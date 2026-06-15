#!/usr/bin/env node

const { runInstagramScraperModeWrapper } = require('./lib/instagram-scraper-mode-wrapper.cjs');

async function runInstagramPostsScanFlow(context) {
  const {
    page,
    username,
    profileUrl,
    notes,
    scanState,
    helpers,
  } = context;
  const {
    captureLivePreviewScreenshot,
    collectInstagramPosts,
    collectProfileInfo,
    markGracefulStopIfRequested,
    navigateWithSoftTimeout,
    progressLog,
    recoverFromInstagramDailyTimeLimit,
    sleep,
  } = helpers;

  progressLog('profile-opening', {
    relationship: null,
    url: profileUrl,
  });

  const profileNavigation = await navigateWithSoftTimeout(page, profileUrl, scanState.runtimeConfig);

  if (!profileNavigation.ok) {
    notes.push(`Profilseite konnte nicht stabil geladen werden: ${profileNavigation.error}`);
  }

  progressLog('profile-page-loaded', {
    relationship: null,
    url: page.url(),
    ...(await captureLivePreviewScreenshot(page, scanState.runtimeConfig, true)),
  });

  await sleep(1800);

  const timeLimitRecovery = await recoverFromInstagramDailyTimeLimit(
    page,
    context.runtimeState,
    notes,
    'posts',
    profileUrl,
  );
  scanState.runtimeConfig = context.runtimeState.runtimeConfig;
  scanState.cookieFilePath = context.runtimeState.cookieFilePath;
  scanState.cookieDiagnostics = context.runtimeState.cookieDiagnostics;
  scanState.loginDiagnostics = context.runtimeState.loginDiagnostics;

  if (!timeLimitRecovery.recovered) {
    throw new Error('Instagram-Zeitlimit auf allen verfuegbaren Scraper-Profilen erreicht.');
  }

  scanState.initialHtml = await page.content();
  scanState.initialProfile = await collectProfileInfo(page, username);
  scanState.title = await page.title().catch(() => null);
  scanState.finalUrl = page.url();

  progressLog('profile-collected', {
    usernameSeen: Boolean(scanState.initialProfile.usernameSeen),
    isPrivate: Boolean(scanState.initialProfile.isPrivate),
    requiresLogin: Boolean(scanState.initialProfile.requiresLogin),
    imageCount: scanState.initialProfile.imageCount || 0,
    ...(await captureLivePreviewScreenshot(page, scanState.runtimeConfig, true)),
  });

  scanState.gracefullyStopped = Boolean(markGracefulStopIfRequested(null, {
    loaded: 0,
    expectedCount: 0,
  }));

  if (scanState.gracefullyStopped) {
    notes.push('Instagram-Scan wurde ueber die Oberflaeche nach den Grunddaten beendet.');

    return;
  }

  scanState.postsScanResult = await collectInstagramPosts(
    page,
    scanState.initialProfile,
    username,
    profileUrl,
    scanState.runtimeConfig,
    context.runtimeState,
    notes,
  );
  scanState.runtimeConfig = context.runtimeState.runtimeConfig;
  scanState.cookieFilePath = context.runtimeState.cookieFilePath;
  scanState.cookieDiagnostics = context.runtimeState.cookieDiagnostics;
  scanState.loginDiagnostics = context.runtimeState.loginDiagnostics;
  scanState.initialProfile.postsScan = scanState.postsScanResult;
  scanState.initialHtml = await page.content().catch(() => scanState.initialHtml);
  scanState.title = await page.title().catch(() => scanState.title);
  scanState.finalUrl = page.url();

  if (scanState.postsScanResult.gracefullyStopped) {
    scanState.gracefullyStopped = true;
  }
}

if (require.main === module) {
  runInstagramScraperModeWrapper({
    defaultMode: 'posts',
    allowedModes: ['posts', 'post-scan'],
  });
}

module.exports = {
  runInstagramPostsScanFlow,
};
