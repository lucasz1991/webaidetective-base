#!/usr/bin/env node

const path = require('path');
const { spawn } = require('child_process');

function normalizeMode(value) {
  return String(value || '').trim().toLowerCase();
}

function buildMiniProfile(profile = {}) {
  const counts = profile && typeof profile.counts === 'object' && profile.counts !== null
    ? profile.counts
    : {};

  return {
    bodyTextPreview: typeof profile.bodyTextPreview === 'string' ? profile.bodyTextPreview : '',
    counts: {
      posts: Number.isFinite(Number(counts.posts)) ? Number(counts.posts) : null,
      followers: Number.isFinite(Number(counts.followers)) ? Number(counts.followers) : null,
      following: Number.isFinite(Number(counts.following)) ? Number(counts.following) : null,
      sources: counts && typeof counts.sources === 'object' && counts.sources !== null
        ? counts.sources
        : {
          posts: null,
          followers: null,
          following: null,
        },
    },
    description: profile.description ?? null,
    biography: profile.description ?? null,
    ogImage: profile.ogImage ?? null,
    profileImageUrl: profile.ogImage ?? null,
    ogTitle: profile.ogTitle ?? null,
    fullName: profile.ogTitle ?? null,
    isPrivate: typeof profile.isPrivate === 'boolean' ? profile.isPrivate : null,
    requiresLogin: typeof profile.requiresLogin === 'boolean' ? profile.requiresLogin : false,
    usernameSeen: typeof profile.usernameSeen === 'boolean' ? profile.usernameSeen : false,
  };
}

function buildMiniPayload(payload = {}) {
  return {
    ok: Boolean(payload.ok),
    statusLevel: payload.statusLevel || 'unknown',
    statusMessage: payload.statusMessage || 'Instagram-Mini-Scan abgeschlossen.',
    username: payload.username || null,
    finalUrl: payload.finalUrl || null,
    htmlBytes: Number.isFinite(Number(payload.htmlBytes)) ? Number(payload.htmlBytes) : 0,
    htmlPath: payload.htmlPath || null,
    htmlPreview: typeof payload.htmlPreview === 'string' ? payload.htmlPreview : '',
    notes: Array.isArray(payload.notes) ? payload.notes : [],
    cookieDiagnostics: payload.cookieDiagnostics && typeof payload.cookieDiagnostics === 'object'
      ? payload.cookieDiagnostics
      : {},
    loginDiagnostics: payload.loginDiagnostics && typeof payload.loginDiagnostics === 'object'
      ? payload.loginDiagnostics
      : {},
    profile: buildMiniProfile(payload.profile || {}),
    gracefullyStopped: Boolean(payload.gracefullyStopped),
    profileUrl: payload.profileUrl || null,
    screenshotPath: payload.screenshotPath || null,
    scrapedAt: payload.scrapedAt || new Date().toISOString(),
    screenshotMode: payload.screenshotMode || null,
    title: payload.title || null,
    warnings: Array.isArray(payload.warnings) ? payload.warnings : [],
    durationMs: Number.isFinite(Number(payload.durationMs)) ? Number(payload.durationMs) : 0,
    operationMode: 'mini',
    debugLogPath: payload.debugLogPath || null,
    billing: payload.billing && typeof payload.billing === 'object' ? payload.billing : null,
  };
}

function emitAndExit(payload, code = 0) {
  process.stdout.write(`${JSON.stringify(payload)}\n`, () => {
    process.exit(code);
  });
}

function run() {
  const defaultMode = 'mini';
  const allowedModes = new Set(['mini', 'mini-scan', 'public', 'public-profile']);
  const requestedMode = normalizeMode(process.argv[4] || defaultMode);
  const operationMode = allowedModes.has(requestedMode) ? requestedMode : defaultMode;
  const scraperScriptPath = path.resolve(__dirname, './scrape-instagram.cjs');
  const childArgs = [
    scraperScriptPath,
    process.argv[2] || '',
    process.argv[3] || '',
    operationMode,
  ];

  const child = spawn(process.execPath, childArgs, {
    env: process.env,
    cwd: path.resolve(__dirname, '../../..'),
    stdio: ['inherit', 'pipe', 'pipe'],
  });

  let stdout = '';

  child.stdout.on('data', (chunk) => {
    stdout += chunk.toString();
  });

  child.stderr.on('data', (chunk) => {
    process.stderr.write(chunk);
  });

  child.on('error', (error) => {
    emitAndExit({
      ok: false,
      statusLevel: 'error',
      statusMessage: `Instagram-Mini-Scan fehlgeschlagen: ${error.message}`,
      error: error.message,
      operationMode: 'mini',
    }, 1);
  });

  child.on('close', (code) => {
    const rawOutput = stdout.trim();
    const outputLine = rawOutput.split(/\r?\n/).filter(Boolean).pop() || '';

    if (outputLine === '') {
      emitAndExit({
        ok: false,
        statusLevel: 'error',
        statusMessage: 'Instagram-Mini-Scan fehlgeschlagen: Kein Output vom Basisskript.',
        operationMode: 'mini',
      }, code || 1);
      return;
    }

    try {
      const payload = JSON.parse(outputLine);
      emitAndExit(buildMiniPayload(payload), code || 0);
    } catch (error) {
      emitAndExit({
        ok: false,
        statusLevel: 'error',
        statusMessage: `Instagram-Mini-Scan fehlgeschlagen: Output konnte nicht verarbeitet werden (${error.message}).`,
        operationMode: 'mini',
      }, code || 1);
    }
  });
}

run();
