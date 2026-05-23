#!/usr/bin/env node

const { spawn } = require('child_process');
const path = require('path');

const normalizeUsername = (value) => String(value || '')
  .trim()
  .replace(/^@+/, '')
  .toLowerCase();

const publicUsername = normalizeUsername(process.argv[2]);
const targetUsername = normalizeUsername(process.argv[3]);
const runtimeConfigPath = process.argv[4] || null;
const scraperScriptPath = path.join(__dirname, 'scrape-instagram.cjs');
const nodeBinary = process.execPath || 'node';

function emptyPhase(relationship, error = null) {
  return {
    relationship,
    checked: false,
    available: false,
    complete: false,
    targetFound: false,
    targetItem: null,
    observedCount: 0,
    expectedCount: 0,
    reportedCount: 0,
    rateLimited: false,
    statusLevel: 'error',
    statusMessage: error || 'Listen-Scan wurde nicht ausgefuehrt.',
    reason: null,
    notes: [],
    warnings: [],
    screenshotPath: null,
    debugLogPath: null,
    error,
  };
}

function normalizeItem(item) {
  if (!item || typeof item !== 'object') {
    return null;
  }

  const username = normalizeUsername(item.username);

  if (!username) {
    return null;
  }

  return {
    username,
    displayName: item.displayName ? String(item.displayName).trim() : null,
    profileUrl: item.profileUrl ? String(item.profileUrl) : `https://www.instagram.com/${username}/`,
  };
}

function extractPhase(payload, relationship) {
  if (!payload || typeof payload !== 'object') {
    return emptyPhase(relationship, 'Node-Scraper lieferte kein gueltiges JSON.');
  }

  const payloadKey = relationship === 'followers' ? 'followersList' : 'followingList';
  const list = payload.profile && typeof payload.profile === 'object'
    ? payload.profile[payloadKey] || {}
    : {};
  const items = Array.isArray(list.items)
    ? list.items.map(normalizeItem).filter(Boolean)
    : [];
  const targetItem = items.find((item) => item.username === targetUsername) || null;

  return {
    relationship,
    checked: Boolean(list.attempted || list.available || items.length > 0 || payload.ok),
    available: Boolean(list.available),
    complete: Boolean(list.complete),
    targetFound: Boolean(targetItem),
    targetItem,
    observedCount: items.length,
    expectedCount: Number(list.expectedCount || 0),
    reportedCount: Number(list.count || items.length || 0),
    rateLimited: Boolean(list.rateLimited),
    statusLevel: payload.statusLevel || (payload.ok ? 'success' : 'error'),
    statusMessage: payload.statusMessage || payload.error || null,
    reason: list.reason || null,
    notes: Array.isArray(payload.notes) ? payload.notes : [],
    warnings: Array.isArray(payload.warnings) ? payload.warnings : [],
    screenshotPath: payload.screenshotPath || null,
    debugLogPath: payload.debugLogPath || null,
    error: payload.error || null,
  };
}

function runScraper(relationship) {
  return new Promise((resolve) => {
    const args = [scraperScriptPath, publicUsername, runtimeConfigPath, relationship];
    const child = spawn(nodeBinary, args, {
      cwd: path.resolve(__dirname, '../../..'),
      windowsHide: true,
    });
    const stdoutChunks = [];
    const stderrChunks = [];

    child.stdout.on('data', (chunk) => {
      stdoutChunks.push(chunk);
    });

    child.stderr.on('data', (chunk) => {
      stderrChunks.push(chunk);
      process.stderr.write(chunk);
    });

    child.on('error', (error) => {
      resolve({
        ok: false,
        code: null,
        payload: null,
        stdout: '',
        stderr: stderrChunks.map((chunk) => chunk.toString('utf8')).join(''),
        error: error.message,
      });
    });

    child.on('close', (code) => {
      const stdout = stdoutChunks.map((chunk) => chunk.toString('utf8')).join('').trim();
      const stderr = stderrChunks.map((chunk) => chunk.toString('utf8')).join('').trim();
      let payload = null;
      let parseError = null;

      if (stdout !== '') {
        try {
          payload = JSON.parse(stdout);
        } catch (error) {
          parseError = error.message;
        }
      }

      resolve({
        ok: code === 0 && payload !== null && !parseError,
        code,
        payload,
        stdout,
        stderr,
        error: parseError,
      });
    });
  });
}

function resolveRelationType(followers, following) {
  const targetFollowsPublic = Boolean(followers.targetFound);
  const publicFollowsTarget = Boolean(following.targetFound);

  if (targetFollowsPublic && publicFollowsTarget) {
    return 'mutual';
  }

  if (publicFollowsTarget) {
    return 'public_follows_target';
  }

  if (targetFollowsPublic) {
    return 'target_follows_public';
  }

  if (followers.checked && following.checked) {
    return 'none';
  }

  return 'unknown';
}

function resolveStatusLevel(followers, following) {
  if (followers.rateLimited || following.rateLimited) {
    return followers.checked || following.checked ? 'partial' : 'error';
  }

  if (followers.checked && following.checked) {
    return 'success';
  }

  if (followers.checked || following.checked) {
    return 'partial';
  }

  return 'error';
}

function resolveStatusMessage(relationType, followers, following) {
  if (followers.rateLimited || following.rateLimited) {
    return 'Instagram hat mindestens eine Verbindungsliste per Rate-Limit blockiert.';
  }

  return matchRelationMessage(relationType);
}

function matchRelationMessage(relationType) {
  switch (relationType) {
    case 'mutual':
      return 'Gegenseitige Instagram-Verbindung wurde in den oeffentlichen Listen gefunden.';
    case 'public_follows_target':
      return 'Das oeffentliche Profil folgt der beobachteten Person.';
    case 'target_follows_public':
      return 'Die beobachtete Person folgt dem oeffentlichen Profil.';
    case 'none':
      return 'In den oeffentlichen Listen wurde keine direkte Verbindung gefunden.';
    default:
      return 'Die Verbindung konnte nicht sicher geprueft werden.';
  }
}

async function main() {
  const startedAt = Date.now();

  if (!publicUsername || !targetUsername || !runtimeConfigPath) {
    const responsePayload = {
      ok: false,
      statusLevel: 'error',
      statusMessage: 'Public-Profile-Verbindungsscan fehlgeschlagen: Username oder Runtime-Config fehlt.',
      publicUsername,
      targetUsername,
      relationType: 'unknown',
      targetFollowsPublicProfile: false,
      publicProfileFollowsTarget: false,
      followers: emptyPhase('followers', 'Username oder Runtime-Config fehlt.'),
      following: emptyPhase('following', 'Username oder Runtime-Config fehlt.'),
      durationMs: Date.now() - startedAt,
      scannedAt: new Date().toISOString(),
    };
    console.log(JSON.stringify(responsePayload));
    process.exitCode = 1;

    return;
  }

  const followersRun = await runScraper('followers');
  const followers = followersRun.payload
    ? extractPhase(followersRun.payload, 'followers')
    : emptyPhase('followers', followersRun.error || 'Followerliste lieferte kein Ergebnis.');

  const followingRun = await runScraper('following');
  const following = followingRun.payload
    ? extractPhase(followingRun.payload, 'following')
    : emptyPhase('following', followingRun.error || 'Gefolgt-Liste lieferte kein Ergebnis.');

  const relationType = resolveRelationType(followers, following);
  const statusLevel = resolveStatusLevel(followers, following);
  const responsePayload = {
    ok: statusLevel !== 'error',
    statusLevel,
    statusMessage: resolveStatusMessage(relationType, followers, following),
    publicUsername,
    targetUsername,
    relationType,
    targetFollowsPublicProfile: Boolean(followers.targetFound),
    publicProfileFollowsTarget: Boolean(following.targetFound),
    followers,
    following,
    notes: [
      ...followers.notes.map((note) => `followers: ${note}`),
      ...following.notes.map((note) => `following: ${note}`),
    ],
    warnings: [
      ...followers.warnings.map((warning) => `followers: ${warning}`),
      ...following.warnings.map((warning) => `following: ${warning}`),
    ],
    childProcesses: {
      followers: {
        code: followersRun.code,
        ok: followersRun.ok,
        error: followersRun.error || null,
      },
      following: {
        code: followingRun.code,
        ok: followingRun.ok,
        error: followingRun.error || null,
      },
    },
    durationMs: Date.now() - startedAt,
    scannedAt: new Date().toISOString(),
  };

  console.log(JSON.stringify(responsePayload));
}

main().catch((error) => {
  const responsePayload = {
    ok: false,
    statusLevel: 'error',
    statusMessage: 'Public-Profile-Verbindungsscan fehlgeschlagen.',
    publicUsername,
    targetUsername,
    relationType: 'unknown',
    targetFollowsPublicProfile: false,
    publicProfileFollowsTarget: false,
    followers: emptyPhase('followers', error.message),
    following: emptyPhase('following', error.message),
    error: error.message,
    scannedAt: new Date().toISOString(),
  };

  console.log(JSON.stringify(responsePayload));
  process.exitCode = 1;
});
