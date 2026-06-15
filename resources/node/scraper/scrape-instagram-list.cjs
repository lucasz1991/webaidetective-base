#!/usr/bin/env node

const { runInstagramScraperModeWrapper } = require('./lib/instagram-scraper-mode-wrapper.cjs');

async function runInstagramListScanFlow(context) {
  const {
    page,
    username,
    profileUrl,
    runtimeState,
    notes,
    consoleMessages,
    scanState,
    flags,
    helpers,
  } = context;
  const {
    captureLivePreviewScreenshot,
    collectProfileInfo,
    collectRelationshipListWithAccountSwitches,
    markGracefulStopIfRequested,
    navigateWithSoftTimeout,
    normalizeInstagramUsername,
    progressLog,
    recoverFromInstagramDailyTimeLimit,
    runPublicConnectionBatch,
    sleep,
  } = helpers;

  if (flags.operationMode === 'public-connections-batch') {
    const targetUsername = normalizeInstagramUsername(scanState.runtimeConfig.relationshipSearchTargetUsername || '');

    scanState.batchPayload = await runPublicConnectionBatch(
      page,
      runtimeState,
      notes,
      username,
      targetUsername,
      {
        screenshotPath: flags.artifacts.screenshotPath,
      },
    );
    scanState.runtimeConfig = runtimeState.runtimeConfig;
    scanState.cookieFilePath = runtimeState.cookieFilePath;
    scanState.cookieDiagnostics = runtimeState.cookieDiagnostics;
    scanState.loginDiagnostics = runtimeState.loginDiagnostics;

    return;
  }

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
    runtimeState,
    notes,
    'profile',
    profileUrl,
  );
  scanState.runtimeConfig = runtimeState.runtimeConfig;
  scanState.cookieFilePath = runtimeState.cookieFilePath;
  scanState.cookieDiagnostics = runtimeState.cookieDiagnostics;
  scanState.loginDiagnostics = runtimeState.loginDiagnostics;

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
  }

  if (flags.shouldCollectFollowers && !scanState.gracefullyStopped) {
    const followersResult = await collectRelationshipListWithAccountSwitches(
      page,
      username,
      scanState.initialProfile,
      'followers',
      runtimeState,
      notes,
      profileUrl,
    );

    scanState.initialProfile = followersResult.profile;
    scanState.initialProfile.followersList = followersResult.list;
    scanState.runtimeConfig = runtimeState.runtimeConfig;
    scanState.cookieFilePath = runtimeState.cookieFilePath;
    scanState.cookieDiagnostics = runtimeState.cookieDiagnostics;
    scanState.loginDiagnostics = runtimeState.loginDiagnostics;

    if (followersResult.html !== null) {
      scanState.initialHtml = followersResult.html;
    }

    if (followersResult.title !== null) {
      scanState.title = followersResult.title;
    }

    scanState.finalUrl = followersResult.finalUrl || page.url();

    if (scanState.initialProfile.followersList?.rateLimited) {
      notes.push('Instagram hat die Followerliste per Rate-Limit-Meldung blockiert; die Listenphase wurde abgebrochen.');
      consoleMessages.push('rate-limit: instagram-rate-limit followers');
    } else if (scanState.initialProfile.followersList?.listTemporarilyUnavailable) {
      notes.push('Followerliste zeigte waehrend des Scans ploetzlich keine Profile mehr; mit der naechsten Listenphase wird fortgesetzt.');
    } else if (scanState.initialProfile.followersList?.gracefullyStopped) {
      scanState.gracefullyStopped = true;
      notes.push('Followerliste wurde ueber die Oberflaeche beendet; bisherige Eintraege werden gespeichert.');
    } else if (scanState.initialProfile.followersList?.available) {
      notes.push(`Followerliste ausgelesen: ${scanState.initialProfile.followersList.count} Eintraege.`);
    } else if (scanState.initialProfile.followersList?.attempted) {
      notes.push('Followerliste konnte nicht ausgelesen werden.');
    }

    if (page.url().includes('/followers')) {
      await navigateWithSoftTimeout(page, profileUrl, scanState.runtimeConfig);
      await sleep(900);
    }
  }

  if (flags.shouldCollectFollowing && !scanState.gracefullyStopped) {
    const followingResult = await collectRelationshipListWithAccountSwitches(
      page,
      username,
      scanState.initialProfile,
      'following',
      runtimeState,
      notes,
      profileUrl,
    );

    scanState.initialProfile = followingResult.profile;
    scanState.initialProfile.followingList = followingResult.list;
    scanState.runtimeConfig = runtimeState.runtimeConfig;
    scanState.cookieFilePath = runtimeState.cookieFilePath;
    scanState.cookieDiagnostics = runtimeState.cookieDiagnostics;
    scanState.loginDiagnostics = runtimeState.loginDiagnostics;

    if (followingResult.html !== null) {
      scanState.initialHtml = followingResult.html;
    }

    if (followingResult.title !== null) {
      scanState.title = followingResult.title;
    }

    scanState.finalUrl = followingResult.finalUrl || page.url();

    if (scanState.initialProfile.followingList?.rateLimited) {
      notes.push('Instagram hat die Gefolgt-Liste per Rate-Limit-Meldung blockiert; die Listenphase wurde abgebrochen.');
      consoleMessages.push('rate-limit: instagram-rate-limit following');
    } else if (scanState.initialProfile.followingList?.listTemporarilyUnavailable) {
      notes.push('Gefolgt-Liste zeigte waehrend des Scans ploetzlich keine Profile mehr; die Listenphase wurde beendet.');
    } else if (scanState.initialProfile.followingList?.gracefullyStopped) {
      scanState.gracefullyStopped = true;
      notes.push('Gefolgt-Liste wurde ueber die Oberflaeche beendet; bisherige Eintraege werden gespeichert.');
    } else if (scanState.initialProfile.followingList?.available) {
      notes.push(`Gefolgt-Liste ausgelesen: ${scanState.initialProfile.followingList.count} Eintraege.`);
    } else if (scanState.initialProfile.followingList?.attempted) {
      notes.push('Gefolgt-Liste konnte nicht ausgelesen werden.');
    }

    if (page.url().includes('/followers') || page.url().includes('/following')) {
      await navigateWithSoftTimeout(page, profileUrl, scanState.runtimeConfig);
      await sleep(900);
    }
  }

  scanState.finalUrl = page.url();
}

if (require.main === module) {
  runInstagramScraperModeWrapper({
    defaultMode: 'followers',
    allowedModes: [
      'followers',
      'following',
      'followers-search',
      'search-followers',
      'following-search',
      'search-following',
      'public-connections-batch',
    ],
  });
}

module.exports = {
  runInstagramListScanFlow,
};
