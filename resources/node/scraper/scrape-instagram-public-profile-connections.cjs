#!/usr/bin/env node

const fs = require('fs');
const { spawn } = require('child_process');
const path = require('path');

const normalizeUsername = (value) => String(value || '')
  .trim()
  .replace(/^@+/, '')
  .toLowerCase();

const publicUsername = normalizeUsername(process.argv[2]);
const targetUsername = normalizeUsername(process.argv[3]);
const runtimeConfigPath = process.argv[4] || null;
const scraperScriptPath = path.join(__dirname, 'scrape-instagram-list.cjs');
const nodeBinary = process.execPath || 'node';

function progressLog(stage, payload = {}) {
  process.stderr.write(`[SCRAPER PROGRESS] ${JSON.stringify({
    stage,
    relationship: 'public-connections',
    ...payload,
  })}\n`);
}

function loadRuntimeConfig() {
  if (!runtimeConfigPath || !fs.existsSync(runtimeConfigPath)) {
    return {};
  }

  try {
    return JSON.parse(fs.readFileSync(runtimeConfigPath, 'utf8'));
  } catch {
    return {};
  }
}

const runtimeConfig = loadRuntimeConfig();

function normalizeNumberAtLeast(value, fallback, minimum) {
  const normalizedValue = Number(value ?? fallback);

  if (!Number.isFinite(normalizedValue)) {
    return Math.max(minimum, fallback);
  }

  return Math.max(minimum, normalizedValue);
}

function normalizeIntegerInRange(value, fallback, minimum, maximum) {
  const normalizedValue = Math.floor(normalizeNumberAtLeast(value, fallback, minimum));

  return Math.min(maximum, Math.max(minimum, normalizedValue));
}

function hasFiniteNumericValue(value) {
  return value !== null
    && value !== undefined
    && value !== ''
    && Number.isFinite(Number(value));
}

function normalizeItem(item) {
  if (!item || typeof item !== 'object') {
    return null;
  }

  const status = String(item.status || '').trim().toLowerCase();

  if (
    item.active === false
    || item.isActive === false
    || item.removed === true
    || item.deleted === true
    || item.removedAt
    || ['removed', 'deleted', 'inactive'].includes(status)
  ) {
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
    profileImageUrl: item.profileImageUrl || item.profile_image_url ? String(item.profileImageUrl || item.profile_image_url).trim() : null,
    profileVisibility: ['public', 'private', 'unknown'].includes(item.profileVisibility) ? item.profileVisibility : null,
    isPrivate: typeof item.isPrivate === 'boolean' ? item.isPrivate : null,
    postsCount: hasFiniteNumericValue(item.postsCount) ? Number(item.postsCount) : null,
    followersCount: hasFiniteNumericValue(item.followersCount) ? Number(item.followersCount) : null,
    followingCount: hasFiniteNumericValue(item.followingCount) ? Number(item.followingCount) : null,
    hoverCard: item.hoverCard && typeof item.hoverCard === 'object' ? item.hoverCard : null,
    sourceLists: Array.isArray(item.sourceLists) ? item.sourceLists.filter(Boolean) : [],
  };
}

function buildCandidatePool() {
  const candidates = new Map();
  const configuredCandidates = Array.isArray(runtimeConfig.publicConnectionCandidates)
    ? runtimeConfig.publicConnectionCandidates
    : [];

  for (const rawCandidate of configuredCandidates) {
    const candidate = normalizeItem(rawCandidate);

    if (!candidate || candidate.username === targetUsername || candidate.username === publicUsername) {
      continue;
    }

    const existing = candidates.get(candidate.username) || {
      ...candidate,
      sourceLists: [],
    };

    for (const sourceList of candidate.sourceLists) {
      if (!existing.sourceLists.includes(sourceList)) {
        existing.sourceLists.push(sourceList);
      }
    }

    candidates.set(candidate.username, existing);
  }

  const allCandidates = Array.from(candidates.values())
    .sort((left, right) => left.username.localeCompare(right.username));

  return allCandidates;
}

function writeChildRuntimeConfig() {
  const childConfig = {
    ...runtimeConfig,
    publicConnectionCandidates: buildCandidatePool(),
    relationshipSearchOnly: true,
    relationshipSearchTargetUsername: targetUsername,
    relationshipSearchPartitionQueries: [targetUsername],
    relationshipSearchMaxDepth: 1,
    relationshipSearchTargetMaxItems: Math.max(0, Number(runtimeConfig.relationshipSearchTargetMaxItems || 0)),
    relationshipSearchTargetMaxScrollRounds: Math.max(10, Number(runtimeConfig.relationshipSearchTargetMaxScrollRounds || 60)),
    relationshipSearchWaitMs: Math.max(500, Number(runtimeConfig.relationshipSearchWaitMs || 500)),
    publicConnectionRetryDelayMs: normalizeNumberAtLeast(runtimeConfig.publicConnectionRetryDelayMs, 6000, 1500),
    publicConnectionRetryMaxDelayMs: normalizeNumberAtLeast(runtimeConfig.publicConnectionRetryMaxDelayMs, 90000, 10000),
    publicConnectionCandidateMaxAttempts: normalizeIntegerInRange(runtimeConfig.publicConnectionCandidateMaxAttempts, 3, 1, 3),
    publicConnectionCandidateMaxDurationMs: normalizeNumberAtLeast(runtimeConfig.publicConnectionCandidateMaxDurationMs, 1200000, 60000),
    publicConnectionDialogMissingMaxAttempts: normalizeIntegerInRange(runtimeConfig.publicConnectionDialogMissingMaxAttempts, 2, 1, 2),
    publicConnectionRateLimitAccountSwitchEnabled: runtimeConfig.publicConnectionRateLimitAccountSwitchEnabled !== false,
    relationshipSearchInputMaxAttempts: normalizeIntegerInRange(runtimeConfig.relationshipSearchInputMaxAttempts, 3, 1, 3),
    scriptWatchdogEnabled: runtimeConfig.scriptWatchdogEnabled !== false,
    scriptStallTimeoutMs: normalizeNumberAtLeast(runtimeConfig.scriptStallTimeoutMs || runtimeConfig.nodeStallTimeoutMs, 900000, 60000),
    browserDisconnectAbort: runtimeConfig.browserDisconnectAbort !== false,
    navigationTimeoutMs: Math.max(30000, Math.min(Number(runtimeConfig.navigationTimeoutMs || 30000), 45000)),
    postLoginWaitMs: Math.max(500, Math.min(Number(runtimeConfig.postLoginWaitMs || 500), 1000)),
    skipDebugArtifacts: true,
    blockHeavyResources: true,
    expectedFollowerCount: 0,
    expectedFollowingCount: 0,
    followerListMaxItems: 0,
    followingListMaxItems: 0,
  };
  const directory = runtimeConfigPath && fs.existsSync(path.dirname(runtimeConfigPath))
    ? path.dirname(runtimeConfigPath)
    : path.dirname(runtimeConfigPath || process.cwd());
  const filePath = path.join(directory, `instagram-public-connection-batch-${Date.now()}-${process.pid}.json`);

  fs.writeFileSync(filePath, JSON.stringify(childConfig, null, 2), 'utf8');

  return filePath;
}

function runScraper(username, operationMode, childRuntimeConfigPath) {
  return new Promise((resolve) => {
    const args = [scraperScriptPath, username, childRuntimeConfigPath, operationMode];
    const child = spawn(nodeBinary, args, {
      cwd: path.resolve(__dirname, '../../..'),
      windowsHide: true,
    });
    const stdoutChunks = [];
    const stderrChunks = [];
    const stallTimeoutMs = normalizeNumberAtLeast(runtimeConfig.scriptStallTimeoutMs || runtimeConfig.nodeStallTimeoutMs, 900000, 60000);
    let lastOutputAt = Date.now();
    let killedByWatchdog = false;
    let settled = false;
    const markOutput = () => {
      lastOutputAt = Date.now();
    };
    const clearWatchdog = () => {
      if (watchdogInterval) {
        clearInterval(watchdogInterval);
      }
    };
    const watchdogInterval = runtimeConfig.scriptWatchdogEnabled === false
      ? null
      : setInterval(() => {
        if (settled || killedByWatchdog) {
          return;
        }

        const idleMs = Date.now() - lastOutputAt;

        if (idleMs < stallTimeoutMs) {
          return;
        }

        killedByWatchdog = true;
        const message = `Public-Profile-Verbindungsscan abgebrochen: Child-Node-Skript hat seit ${Math.round(idleMs / 1000)} Sekunden keinen Output geliefert.`;
        process.stderr.write(`[SCRAPER ABORT] ${JSON.stringify({
          at: new Date().toISOString(),
          code: 'CHILD_SCRIPT_STALLED',
          message,
          idleMs,
        })}\n`);
        child.kill('SIGTERM');
        setTimeout(() => {
          if (!settled) {
            child.kill('SIGKILL');
          }
        }, 5000).unref?.();
      }, Math.min(30000, Math.max(5000, Math.floor(stallTimeoutMs / 6))));

    watchdogInterval?.unref?.();

    child.stdout.on('data', (chunk) => {
      markOutput();
      stdoutChunks.push(chunk);
    });

    child.stderr.on('data', (chunk) => {
      markOutput();
      stderrChunks.push(chunk);
      process.stderr.write(chunk);
    });

    child.on('error', (error) => {
      settled = true;
      clearWatchdog();
      resolve({
        ok: false,
        code: null,
        payload: null,
        stderr: stderrChunks.map((chunk) => chunk.toString('utf8')).join(''),
        error: killedByWatchdog
          ? 'Child-Node-Skript wurde wegen Stillstand beendet.'
          : error.message,
      });
    });

    child.on('close', (code) => {
      settled = true;
      clearWatchdog();
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
        ok: !killedByWatchdog && code === 0 && payload !== null && !parseError,
        code,
        payload,
        stderr,
        error: killedByWatchdog
          ? 'Child-Node-Skript wurde wegen Stillstand beendet.'
          : parseError,
      });
    });
  });
}

async function main() {
  const startedAt = Date.now();

  if (!publicUsername || !targetUsername || !runtimeConfigPath) {
    console.log(JSON.stringify({
      ok: false,
      statusLevel: 'error',
      statusMessage: 'Public-Profile-Verbindungsscan fehlgeschlagen: Username oder Runtime-Config fehlt.',
      publicUsername,
      targetUsername,
      relationType: 'unknown',
      targetFollowsPublicProfile: false,
      publicProfileFollowsTarget: false,
      candidatesTotal: 0,
      candidatesChecked: 0,
      candidatesSkippedPrivate: 0,
      candidatesFailed: 0,
      candidateErrorScreenshots: [],
      inferredFollowers: [],
      inferredFollowing: [],
      durationMs: Date.now() - startedAt,
      scannedAt: new Date().toISOString(),
    }));
    process.exitCode = 1;

    return;
  }

  const childRuntimeConfigPath = writeChildRuntimeConfig();

  try {
    const batchRun = await runScraper(publicUsername, 'public-connections-batch', childRuntimeConfigPath);

    if (batchRun.payload) {
      console.log(JSON.stringify({
        ...batchRun.payload,
        publicUsername: batchRun.payload.publicUsername || publicUsername,
        targetUsername: batchRun.payload.targetUsername || targetUsername,
      }));

      return;
    }

    console.log(JSON.stringify({
      ok: false,
      statusLevel: 'error',
      statusMessage: 'Public-Profile-Verbindungsscan fehlgeschlagen: Batch-Scraper lieferte kein Ergebnis.',
      publicUsername,
      targetUsername,
      relationType: 'candidate_search',
      candidatesTotal: 0,
      candidatesChecked: 0,
      candidatesSkippedPrivate: 0,
      candidatesFailed: 0,
      candidateErrorScreenshots: [],
      inferredFollowers: [],
      inferredFollowing: [],
      error: batchRun.error || batchRun.stderr || null,
      durationMs: Date.now() - startedAt,
      scannedAt: new Date().toISOString(),
    }));
    process.exitCode = 1;
  } finally {
    try {
      fs.unlinkSync(childRuntimeConfigPath);
    } catch {
      // Ignore temp cleanup errors.
    }
  }

}

main().catch((error) => {
  console.log(JSON.stringify({
    ok: false,
    statusLevel: 'error',
    statusMessage: 'Public-Profile-Verbindungsscan fehlgeschlagen.',
    publicUsername,
    targetUsername,
    relationType: 'unknown',
    targetFollowsPublicProfile: false,
    publicProfileFollowsTarget: false,
    candidatesTotal: 0,
    candidatesChecked: 0,
    candidatesSkippedPrivate: 0,
    candidatesFailed: 0,
    candidateErrorScreenshots: [],
    inferredFollowers: [],
    inferredFollowing: [],
    error: error.message,
    scannedAt: new Date().toISOString(),
  }));
  process.exitCode = 1;
});
