#!/usr/bin/env node

const { runInstagramScraperModeWrapper } = require('./lib/instagram-scraper-mode-wrapper.cjs');

function buildMiniProfile(profile = {}) {
  const counts = profile && typeof profile.counts === 'object' && profile.counts !== null
    ? profile.counts
    : {};
  const profileImageUrl = profile.profileImageUrl
    || profile.profile_image_url
    || profile.imageUrl
    || profile.avatarUrl
    || profile.ogImage
    || null;

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
    profileImageUrl,
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

runInstagramScraperModeWrapper({
  defaultMode: 'mini',
  allowedModes: ['mini', 'mini-scan', 'public', 'public-profile'],
  transformPayload: (payload) => buildMiniPayload(payload),
});
