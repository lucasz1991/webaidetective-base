#!/usr/bin/env node

const { runInstagramScraperEntrypoint } = require('./lib/instagram-scraper-entrypoint.cjs');

function normalizeCandidateErrorMessage(error) {
  return String(error?.message || error || 'candidate-error').trim() || 'candidate-error';
}

function isFatalSuggestionCandidateError(error) {
  const code = String(error?.code || '').trim().toUpperCase();
  const name = String(error?.name || '').trim();
  const message = normalizeCandidateErrorMessage(error).toLowerCase();

  return name === 'ScraperAbortError'
    || [
      'SCRAPER_ABORTED',
      'SCRIPT_STALLED',
      'BROWSER_DISCONNECTED',
      'PAGE_CLOSED',
    ].includes(code)
    || message.includes('node-scraper abgebrochen')
    || message.includes('browser-seite wurde unerwartet geschlossen')
    || message.includes('verbindung zu chrome/puppeteer wurde getrennt');
}

function suggestionCandidateErrorRequiresAbort(error, page) {
  if (!isFatalSuggestionCandidateError(error)) {
    return false;
  }

  if (typeof page?.isClosed === 'function' && page.isClosed()) {
    return true;
  }

  const code = String(error?.code || '').trim().toUpperCase();
  const name = String(error?.name || '').trim();
  const message = normalizeCandidateErrorMessage(error).toLowerCase();

  return name === 'ScraperAbortError'
    || code === 'SCRAPER_ABORTED'
    || code === 'SCRIPT_STALLED'
    || message.includes('node-scraper abgebrochen')
    || message.includes('verbindung zu chrome/puppeteer wurde getrennt');
}

function resolveCandidateProfileImageUrl(candidate = {}, candidateProfile = {}, candidateHoverCard = null) {
  return candidate.profileImageUrl
    || candidate.profile_image_url
    || candidateHoverCard?.profileImageUrl
    || candidateProfile.profileImageUrl
    || candidateProfile.ogImage
    || null;
}

function resolveCandidateProfileVisibility(candidate = {}, candidateProfile = {}, candidateHoverCard = null) {
  if (candidateHoverCard?.profileVisibility === 'public' || candidateHoverCard?.profileVisibility === 'private') {
    return candidateHoverCard.profileVisibility;
  }

  if (candidate.profileVisibility === 'public' || candidate.profileVisibility === 'private') {
    return candidate.profileVisibility;
  }

  if (candidateProfile.profileVisibility === 'public' || candidateProfile.profileVisibility === 'private') {
    return candidateProfile.profileVisibility;
  }

  if (candidateProfile.isPrivate === true) {
    return 'private';
  }

  if (candidateProfile.isPrivate === false) {
    return 'public';
  }

  return null;
}

async function runProfileSuggestionConnectionScan(deps, page, runtimeState, notes, targetUsername, profileUrl) {
  const {
    captureLivePreviewScreenshot,
    clickProfileSuggestionsSeeAll,
    closeInstagramDialog,
    collectProfileInfo,
    collectProfileSuggestionItemsDeep,
    collectSuggestionCandidatePublicListConnection,
    diagnoseProfileSuggestionsSurface,
    detectInstagramHttp429Page,
    dismissVisibleSuggestion,
    hasFiniteNumericValue,
    markGracefulStopIfRequested,
    navigateWithSoftTimeout,
    normalizeInstagramUsername,
    normalizeSuggestionCandidateHistory,
    progressLog,
    scrollToProfileSuggestions,
    switchScraperAccountAfterRateLimit,
    sleep,
  } = deps;

  let runtimeConfig = runtimeState.runtimeConfig;
  const maxTargetSuggestions = Math.max(1, Number(runtimeConfig.suggestionScanMaxItems || 12));
  const maxCandidateSuggestions = Math.max(1, Number(runtimeConfig.suggestionCandidateMaxItems || 8));
  const checkedCandidates = [];
  const matchedCandidates = [];
  const skippedCandidates = [];
  const dismissedCandidates = [];
  const dismissedUsernames = new Set();
  const observedSuggestionsByUsername = new Map();
  const candidateHistory = normalizeSuggestionCandidateHistory(runtimeConfig.suggestionCandidateHistory || {});
  const alreadyScannedSuggestionUsernames = new Set(Object.entries(candidateHistory)
    .filter(([, history]) => (
      Boolean(history?.hasMatch)
      || Boolean(history?.permanentlyDismissed)
      || Number(history?.noMatchChecks || 0) >= 2
    ))
    .map(([username]) => normalizeInstagramUsername(username))
    .filter(Boolean));
  let gracefullyStopped = false;
  let rateLimited = false;
  let rateLimitText = null;
  let dismissedCount = 0;
  let scraperProfileSwitchCount = 0;
  const maxScraperProfileSwitches = Math.max(0, Math.min(3, Number(runtimeConfig.suggestionMaxScraperProfileSwitches || 3)));

  const rememberObservedSuggestion = (candidate, extra = {}) => {
    const username = normalizeInstagramUsername(candidate?.username || '');

    if (!username) {
      return;
    }

    const previous = observedSuggestionsByUsername.get(username) || {};
    observedSuggestionsByUsername.set(username, {
      ...previous,
      ...candidate,
      ...extra,
      username,
      profileUrl: candidate?.profileUrl || previous.profileUrl || `https://www.instagram.com/${username}/`,
    });
  };
  const observedSuggestionsPreview = () => Array.from(observedSuggestionsByUsername.values()).slice(-60);
  const switchScraperProfileFor429 = async (context, retryUrl) => {
    if (!switchScraperAccountAfterRateLimit || scraperProfileSwitchCount >= maxScraperProfileSwitches) {
      return false;
    }

    scraperProfileSwitchCount += 1;

    progressLog('suggestions-rate-limit-account-switch', {
      relationship: 'suggestions',
      loaded: checkedCandidates.length,
      expectedCount: 0,
      scraperProfileSwitchCount,
      maxScraperProfileSwitches,
      message: `HTTP 429 erkannt (${context}); Scraper-Profil wird gewechselt (${scraperProfileSwitchCount}/${maxScraperProfileSwitches}).`,
      ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
    });

    const switchResult = await switchScraperAccountAfterRateLimit(
      page,
      runtimeConfig,
      notes,
      'suggestions',
      `HTTP 429 bei ${context}`,
      runtimeState.usedAccountKeys,
    );

    if (!switchResult?.sessionEstablished) {
      scraperProfileSwitchCount = maxScraperProfileSwitches;
      return false;
    }

    runtimeConfig = switchResult.runtimeConfig;
    runtimeState.runtimeConfig = switchResult.runtimeConfig;
    runtimeState.cookieDiagnostics = switchResult.cookieDiagnostics;
    runtimeState.loginDiagnostics = switchResult.loginDiagnostics;

    const navigation = await navigateWithSoftTimeout(page, retryUrl, runtimeConfig);
    const navigationHit429 = Number(navigation?.status || 0) === 429
      || /http\s*error\s*429|error\s*429|http\s*429|\b429\b|too many requests/i.test(String(navigation?.error || ''));

    if (!navigation.ok && !navigationHit429) {
      notes.push(`Nach Scraper-Profilwechsel konnte ${retryUrl} nicht stabil geladen werden: ${navigation.error}`);
      return false;
    }

    await sleep(1600);
    return true;
  };
  const pageHas429 = async () => {
    if (!detectInstagramHttp429Page) {
      return { detected: false };
    }

    return detectInstagramHttp429Page(page);
  };
  const looksLikeHttp429 = (value) => (
    /http\s*error\s*429|error\s*429|http\s*429|\b429\b|too many requests/i.test(String(value || ''))
  );
  const resultLooksLikeHttp429 = (result = {}) => (
    Number(result?.status || 0) === 429
    || looksLikeHttp429(result?.error)
    || looksLikeHttp429(result?.rateLimitText)
    || looksLikeHttp429(result?.text)
  );

  progressLog('suggestions-opening', {
    relationship: 'suggestions', 
    loaded: 0,
    expectedCount: maxTargetSuggestions,
    message: `Profilvorschlaege fuer @${targetUsername} werden gesucht.`,
    ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
  });

  let targetSuggestions = null;
  let targetSurfaceDebug = null;

  while (!targetSuggestions) {
    await scrollToProfileSuggestions(page, 6);
    targetSurfaceDebug = diagnoseProfileSuggestionsSurface
      ? await diagnoseProfileSuggestionsSurface(page, targetUsername)
      : null;

    progressLog('suggestions-surface-debug', {
      relationship: 'suggestions',
      suggestionCollectionPhase: 'surface-before-collection',
      loaded: 0,
      expectedCount: maxTargetSuggestions,
      suggestionDebug: {
        headingFound: Boolean(targetSurfaceDebug?.bodyContainsSuggestionText),
        headingText: targetSurfaceDebug?.bodyContainsSuggestionText ? 'surface-text-match' : null,
        diagnostics: {
          bodyContainsSuggestionText: Boolean(targetSurfaceDebug?.bodyContainsSuggestionText),
          textSamples: Array.isArray(targetSurfaceDebug?.visibleTextSamples)
            ? targetSurfaceDebug.visibleTextSamples.slice(0, 40)
            : [],
          anchorSamples: Array.isArray(targetSurfaceDebug?.visibleAnchors)
            ? targetSurfaceDebug.visibleAnchors.slice(0, 40)
            : [],
          scopeSamples: Array.isArray(targetSurfaceDebug?.scrollableContainers)
            ? targetSurfaceDebug.scrollableContainers.slice(0, 12)
            : [],
        },
      },
      suggestionsSurfaceDebug: targetSurfaceDebug,
      message: targetSurfaceDebug?.bodyContainsSuggestionText
        ? 'Oberflaeche vor Vorschlags-Scan: Vorschlagstext sichtbar.'
        : 'Oberflaeche vor Vorschlags-Scan: kein Vorschlagstext im DOM erkannt.',
      ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
    });

    targetSuggestions = await collectProfileSuggestionItemsDeep(
      page,
      targetUsername,
      maxTargetSuggestions,
      {
        dismissUsernames: alreadyScannedSuggestionUsernames,
        includeSeeAll: true,
        forceSeeAll: true,
        openSeeAllFirst: true,
        continueUntilEnd: true,
        runtimeConfig,
        maxInlineRounds: runtimeConfig.suggestionInlineMaxRounds || 36,
        maxDialogRounds: runtimeConfig.suggestionDialogMaxRounds || 48,
      },
    );

    const http429 = await pageHas429();
    const collectionHit429 = Boolean(targetSuggestions.rateLimited)
      && resultLooksLikeHttp429(targetSuggestions);

    if (!http429.detected && !collectionHit429) {
      break;
    }

    targetSuggestions = null;

    const switched = await switchScraperProfileFor429('Zielprofil-Vorschlaege', profileUrl);

    if (!switched) {
      rateLimited = true;
      rateLimitText = http429.text || 'HTTP ERROR 429';
      targetSuggestions = {
        items: [],
        available: false,
        rateLimited: true,
        rateLimitText,
        dismissedKnownItems: [],
      };
      break;
    }
  }
  const targetSeeAllClicked = Boolean(targetSuggestions.seeAllClicked);

  if (targetSeeAllClicked) {
    await closeInstagramDialog(page);
    await scrollToProfileSuggestions(page, 2);
  }

  const candidates = targetSuggestions.items.slice(0, maxTargetSuggestions);
  const candidatesToCheck = [];

  rateLimited = Boolean(targetSuggestions.rateLimited);
  rateLimitText = targetSuggestions.rateLimitText || null;

  for (const dismissedKnown of targetSuggestions.dismissedKnownItems || []) {
    const candidateUsername = normalizeInstagramUsername(dismissedKnown.username || '');

    if (!candidateUsername || dismissedUsernames.has(candidateUsername)) {
      continue;
    }

    const history = candidateHistory[candidateUsername] || {
      hasMatch: false,
      noMatchChecks: 0,
      permanentlyDismissed: false,
    };

    dismissedCount += 1;
    dismissedUsernames.add(candidateUsername);
    rememberObservedSuggestion(dismissedKnown, {
      checked: false,
      skipped: true,
      skippedReason: Boolean(history.hasMatch)
        ? 'already-saved-match'
        : (Boolean(history.permanentlyDismissed) ? 'already-dismissed-no-match' : 'already-scanned-suggestion'),
      alreadyKnown: Boolean(history.hasMatch),
      dismissedFromSuggestions: true,
      previousNoMatchChecks: Number(history.noMatchChecks || 0),
      previousTargetFoundAsSuggestion: Boolean(history.hasMatch),
    });
    dismissedCandidates.push({
      ...dismissedKnown,
      previousNoMatchChecks: Number(history.noMatchChecks || 0),
      previousTargetFoundAsSuggestion: Boolean(history.hasMatch),
    });
    skippedCandidates.push({
      ...dismissedKnown,
      checked: false,
      skipped: true,
      skippedReason: Boolean(history.hasMatch)
        ? 'already-saved-match'
        : (Boolean(history.permanentlyDismissed) ? 'already-dismissed-no-match' : 'already-scanned-suggestion'),
      dismissedFromSuggestions: true,
      previousNoMatchChecks: Number(history.noMatchChecks || 0),
      previousTargetFoundAsSuggestion: Boolean(history.hasMatch),
    });
  }

  for (const candidate of candidates) {
    const candidateUsername = normalizeInstagramUsername(candidate.username);
    const history = candidateHistory[candidateUsername] || {
      hasMatch: false,
      noMatchChecks: 0,
      permanentlyDismissed: false,
    };
    const shouldDismissAsKnownMatch = Boolean(history.hasMatch);
    const shouldDismissAsFinalMiss = Boolean(history.permanentlyDismissed) || Number(history.noMatchChecks || 0) >= 2;

    rememberObservedSuggestion(candidate, {
      checked: false,
      skipped: false,
      alreadyKnown: shouldDismissAsKnownMatch,
      previousNoMatchChecks: Number(history.noMatchChecks || 0),
      previousTargetFoundAsSuggestion: shouldDismissAsKnownMatch,
    });

    if (shouldDismissAsKnownMatch || shouldDismissAsFinalMiss) {
      const dismissed = await dismissVisibleSuggestion(page, candidate.username);

      if (dismissed) {
        dismissedCount += 1;
        dismissedUsernames.add(candidateUsername);
        dismissedCandidates.push({
          ...candidate,
          dismissed: true,
          dismissedBeforeCheck: true,
          dismissReason: shouldDismissAsKnownMatch
            ? 'already-saved-match'
            : 'already-dismissed-no-match',
          previousNoMatchChecks: Number(history.noMatchChecks || 0),
          previousTargetFoundAsSuggestion: shouldDismissAsKnownMatch,
        });
        await sleep(350);
      }
    }

    if (shouldDismissAsKnownMatch || shouldDismissAsFinalMiss) {
      rememberObservedSuggestion(candidate, {
        checked: false,
        skipped: true,
        skippedReason: shouldDismissAsKnownMatch
          ? 'already-saved-match'
          : 'already-dismissed-no-match',
        alreadyKnown: shouldDismissAsKnownMatch,
        dismissedFromSuggestions: dismissedUsernames.has(candidateUsername),
        previousNoMatchChecks: Number(history.noMatchChecks || 0),
        previousTargetFoundAsSuggestion: shouldDismissAsKnownMatch,
      });
      skippedCandidates.push({
        ...candidate,
        checked: false,
        skipped: true,
        skippedReason: shouldDismissAsKnownMatch
          ? 'already-saved-match'
          : 'already-dismissed-no-match',
        dismissedFromSuggestions: dismissedUsernames.has(candidateUsername),
        previousNoMatchChecks: Number(history.noMatchChecks || 0),
        previousTargetFoundAsSuggestion: shouldDismissAsKnownMatch,
      });

      continue;
    }

    candidatesToCheck.push({
      ...candidate,
      previousNoMatchChecks: Number(history.noMatchChecks || 0),
      dismissedBeforeCheck: false,
    });
  }

  progressLog(rateLimited ? 'suggestions-rate-limited' : 'suggestions-target-list', {
    relationship: 'suggestions',
    loaded: 0,
    expectedCount: candidatesToCheck.length,
    suggestionsObserved: candidates.length,
    observedSuggestionCount: observedSuggestionsByUsername.size,
    observedSuggestionsPreview: observedSuggestionsPreview(),
    profileLinkCandidatesSeen: targetSuggestions.profileLinkCandidatesSeen || 0,
    suggestionCollectionRounds: targetSuggestions.rounds || 0,
    suggestionKnownSeen: targetSuggestions.seenKnownCount || 0,
    dismissedSuggestions: dismissedCount,
    skippedSuggestions: skippedCandidates.length,
    message: rateLimited
      ? 'Instagram hat die Profilvorschlaege per Rate-Limit blockiert.'
      : (candidates.length > 0 && candidatesToCheck.length === 0 && skippedCandidates.length > 0
        ? `${candidates.length} Profilvorschlaege gefunden; alle waren bereits bekannt oder endgueltig uebersprungen.`
        : `${candidates.length} Profilvorschlaege gefunden. ${candidatesToCheck.length} Kandidaten werden geprueft.`),
    ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
  });

  if (runtimeConfig.suggestionDismissChecked && candidates.length > 0) {
    for (const candidate of candidates) {
      if (dismissedUsernames.has(normalizeInstagramUsername(candidate.username))) {
        continue;
      }

      const dismissed = await dismissVisibleSuggestion(page, candidate.username);

      if (dismissed) {
        dismissedCount += 1;
        dismissedUsernames.add(normalizeInstagramUsername(candidate.username));
        dismissedCandidates.push({
          ...candidate,
          dismissed: true,
          dismissedBeforeCheck: true,
          dismissReason: 'configured-dismiss-checked',
        });
        await sleep(350);
      }
    }
  }

  if (!rateLimited) {
    for (let index = 0; index < candidatesToCheck.length; index += 1) {
      const candidate = candidatesToCheck[index];
      const stopRequest = markGracefulStopIfRequested('suggestions', {
        loaded: checkedCandidates.length,
        expectedCount: candidatesToCheck.length,
        foundSuggestions: matchedCandidates.length,
        suggestionConnectionsPreview: matchedCandidates.slice(-40),
      });

      if (stopRequest) {
        gracefullyStopped = true;
        break;
      }

      progressLog('suggestions-candidate-opening', {
        relationship: 'suggestions',
        loaded: checkedCandidates.length,
        expectedCount: candidatesToCheck.length,
        candidateUsername: candidate.username,
        foundSuggestions: matchedCandidates.length,
        suggestionConnectionsPreview: matchedCandidates.slice(-40),
        observedSuggestionCount: observedSuggestionsByUsername.size,
        observedSuggestionsPreview: observedSuggestionsPreview(),
        message: Number(candidate.previousNoMatchChecks || 0) > 0
          ? `@${candidate.username} wird nach frueherem Nichttreffer erneut geprueft.`
          : `@${candidate.username} wird auf Verbindung zu @${targetUsername} geprueft.`,
        ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
      });

      try {
        let candidateNavigation = null;
        let candidateHttp429 = { detected: false };

        while (true) {
          candidateNavigation = await navigateWithSoftTimeout(page, candidate.profileUrl, runtimeConfig);
          candidateHttp429 = await pageHas429();

          if (!resultLooksLikeHttp429(candidateNavigation) && !candidateHttp429.detected) {
            break;
          }

          const switched = await switchScraperProfileFor429(`Kandidat @${candidate.username}`, candidate.profileUrl);

          if (!switched) {
            rateLimited = true;
            rateLimitText = candidateHttp429.text || candidateNavigation.error || 'HTTP ERROR 429';
            break;
          }
        }

        if (rateLimited) {
          checkedCandidates.push({
            ...candidate,
            checked: false,
            error: rateLimitText,
            skipped: true,
            skippedReason: 'candidate-http-429',
          });
          rememberObservedSuggestion(candidate, {
            checked: false,
            skipped: true,
            skippedReason: 'candidate-http-429',
            error: rateLimitText,
          });

          progressLog('suggestions-rate-limited', {
            relationship: 'suggestions',
            loaded: checkedCandidates.length,
            expectedCount: candidatesToCheck.length,
            candidateUsername: candidate.username,
            foundSuggestions: matchedCandidates.length,
            suggestionConnectionsPreview: matchedCandidates.slice(-40),
            observedSuggestionCount: observedSuggestionsByUsername.size,
            observedSuggestionsPreview: observedSuggestionsPreview(),
            scraperProfileSwitchCount,
            maxScraperProfileSwitches,
            message: `HTTP 429 blieb nach ${scraperProfileSwitchCount} Scraper-Profilwechseln bestehen; Vorschlags-Scan wird pausiert.`,
            ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
          });

          break;
        }

        if (!candidateNavigation.ok) {
          checkedCandidates.push({
          ...candidate,
          checked: false,
          error: candidateNavigation.error,
          skipped: true,
          skippedReason: 'candidate-navigation-error',
        });
        rememberObservedSuggestion(candidate, {
          checked: false,
          skipped: true,
          skippedReason: 'candidate-navigation-error',
          error: candidateNavigation.error,
        });

        progressLog('suggestions-candidate-error', {
          relationship: 'suggestions',
          loaded: checkedCandidates.length,
          expectedCount: candidatesToCheck.length,
          candidateUsername: candidate.username,
          foundSuggestions: matchedCandidates.length,
          suggestionConnectionsPreview: matchedCandidates.slice(-40),
          observedSuggestionCount: observedSuggestionsByUsername.size,
          observedSuggestionsPreview: observedSuggestionsPreview(),
          message: `@${candidate.username} konnte nicht stabil geladen werden.`,
          ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
        });

        continue;
      }

      await sleep(1300);
      const candidateProfile = await collectProfileInfo(page, candidate.username);
      const candidateHoverCard = candidate.hoverCard && typeof candidate.hoverCard === 'object'
        ? candidate.hoverCard
        : null;
      const candidateProfileImageUrl = resolveCandidateProfileImageUrl(candidate, candidateProfile, candidateHoverCard);
      const candidateProfileVisibility = resolveCandidateProfileVisibility(candidate, candidateProfile, candidateHoverCard);
      const candidateIsPublic = candidateProfile.isPrivate === false
        || (candidateProfile.isPrivate !== true && candidateHoverCard?.isPrivate === false)
        || (!candidateProfile.isPrivate && !candidateProfile.requiresLogin);
      let targetFound = false;
      let checkedCandidate = null;
      let progressMessage = null;
      let progressPayload = {};

      if (candidateIsPublic) {
        progressLog('suggestions-public-list-search', {
          relationship: 'suggestions',
          loaded: checkedCandidates.length,
          expectedCount: candidatesToCheck.length,
          candidateUsername: candidate.username,
          foundSuggestions: matchedCandidates.length,
          suggestionConnectionsPreview: matchedCandidates.slice(-40),
          observedSuggestionCount: observedSuggestionsByUsername.size,
          message: `@${candidate.username} ist oeffentlich; Follower und Gefolgt werden nach @${targetUsername} durchsucht.`,
          ...(await captureLivePreviewScreenshot(page, runtimeConfig)),
        });

        let publicListSearch = null;

        while (!publicListSearch) {
          publicListSearch = await collectSuggestionCandidatePublicListConnection(
            page,
            candidate,
            candidateProfile,
            targetUsername,
            runtimeConfig,
          );

          const listHttp429 = await pageHas429();
          const listHit429 = listHttp429.detected
            || (Boolean(publicListSearch.rateLimited) && resultLooksLikeHttp429(publicListSearch));

          if (!listHit429) {
            break;
          }

          const switched = await switchScraperProfileFor429(`Listenpruefung @${candidate.username}`, candidate.profileUrl);

          if (!switched) {
            rateLimited = true;
            rateLimitText = listHttp429.text || publicListSearch.rateLimitText || 'HTTP ERROR 429';
            break;
          }

          publicListSearch = null;
        }

        if (publicListSearch.rateLimited) {
          rateLimited = true;
          rateLimitText = publicListSearch.rateLimitText || rateLimitText;
        }

        if (publicListSearch.gracefullyStopped) {
          gracefullyStopped = true;
        }

        targetFound = Boolean(publicListSearch.targetFound);
        const definitiveNoMatch = !targetFound
          && Boolean(publicListSearch.conclusive)
          && !Boolean(publicListSearch.rateLimited)
          && !Boolean(publicListSearch.gracefullyStopped);

        checkedCandidate = {
          ...candidate,
          checked: true,
          checkMode: 'public-lists',
          profileImageUrl: candidateProfileImageUrl,
          profileVisibility: candidateProfileVisibility || (candidateProfile.isPrivate === false ? 'public' : null),
          isPrivate: typeof candidateHoverCard?.isPrivate === 'boolean' ? candidateHoverCard.isPrivate : candidateProfile.isPrivate,
          postsCount: hasFiniteNumericValue(candidateHoverCard?.postsCount)
            ? Number(candidateHoverCard.postsCount)
            : (hasFiniteNumericValue(candidate.postsCount) ? Number(candidate.postsCount) : candidateProfile.postsCount),
          followersCount: hasFiniteNumericValue(candidateHoverCard?.followersCount)
            ? Number(candidateHoverCard.followersCount)
            : (hasFiniteNumericValue(candidate.followersCount) ? Number(candidate.followersCount) : candidateProfile.followersCount),
          followingCount: hasFiniteNumericValue(candidateHoverCard?.followingCount)
            ? Number(candidateHoverCard.followingCount)
            : (hasFiniteNumericValue(candidate.followingCount) ? Number(candidate.followingCount) : candidateProfile.followingCount),
          hoverCard: candidateHoverCard,
          targetFoundAsSuggestion: false,
          targetFoundInPublicLists: targetFound,
          targetFoundInFollowers: Boolean(publicListSearch.targetFoundInFollowers),
          targetFoundInFollowing: Boolean(publicListSearch.targetFoundInFollowing),
          finalDismissedAfterSecondMiss: definitiveNoMatch && Number(candidate.previousNoMatchChecks || 0) >= 1,
          dismissedFromSuggestions: Boolean(candidate.dismissedBeforeCheck),
          suggestionsObserved: 0,
          suggestionsAvailable: false,
          suggestionsRateLimited: false,
          suggestionPreview: [],
          publicListSearchAttempted: Boolean(publicListSearch.attempted),
          publicListSearchConclusive: Boolean(publicListSearch.conclusive),
          publicListRateLimited: Boolean(publicListSearch.rateLimited),
          publicListSearch,
        };

        const foundLists = [
          publicListSearch.targetFoundInFollowers ? 'Followern' : null,
          publicListSearch.targetFoundInFollowing ? 'Gefolgt' : null,
        ].filter(Boolean).join(' und ');

        progressMessage = targetFound
          ? `Listen-Verbindung gefunden: @${targetUsername} steht bei @${candidate.username} in ${foundLists}.`
          : (checkedCandidate.finalDismissedAfterSecondMiss
            ? `@${candidate.username} erneut ohne Listentreffer geprueft und endgueltig aus den sichtbaren Vorschlaegen entfernt.`
            : `@${candidate.username} oeffentlich geprueft; @${targetUsername} wurde in den normalen Listen nicht gefunden.`);
        progressPayload = {
          targetFoundInPublicLists: targetFound,
          targetFoundInFollowers: Boolean(publicListSearch.targetFoundInFollowers),
          targetFoundInFollowing: Boolean(publicListSearch.targetFoundInFollowing),
          publicListSearchConclusive: Boolean(publicListSearch.conclusive),
        };

        if (targetFound) {
          matchedCandidates.push({
            username: candidate.username,
            displayName: candidate.displayName || null,
            profileUrl: candidate.profileUrl,
            profileImageUrl: candidateProfileImageUrl,
            profileVisibility: candidateProfileVisibility || 'public',
            isPrivate: typeof candidateHoverCard?.isPrivate === 'boolean' ? candidateHoverCard.isPrivate : false,
            postsCount: hasFiniteNumericValue(candidateHoverCard?.postsCount)
              ? Number(candidateHoverCard.postsCount)
              : (hasFiniteNumericValue(candidate.postsCount) ? Number(candidate.postsCount) : candidateProfile.postsCount),
            followersCount: hasFiniteNumericValue(candidateHoverCard?.followersCount)
              ? Number(candidateHoverCard.followersCount)
              : (hasFiniteNumericValue(candidate.followersCount) ? Number(candidate.followersCount) : candidateProfile.followersCount),
            followingCount: hasFiniteNumericValue(candidateHoverCard?.followingCount)
              ? Number(candidateHoverCard.followingCount)
              : (hasFiniteNumericValue(candidate.followingCount) ? Number(candidate.followingCount) : candidateProfile.followingCount),
            hoverCard: candidateHoverCard,
            sourceSuggestionUsername: candidate.username,
            sourcePublicUsername: candidate.username,
            targetFoundAsSuggestion: false,
            targetFoundInPublicLists: true,
            targetFoundInFollowers: Boolean(publicListSearch.targetFoundInFollowers),
            targetFoundInFollowing: Boolean(publicListSearch.targetFoundInFollowing),
            sourceLists: [
              ...(publicListSearch.targetFoundInFollowers ? ['public_profile_followers'] : []),
              ...(publicListSearch.targetFoundInFollowing ? ['public_profile_following'] : []),
            ],
            publicListSearch,
            suggestionPreview: [],
          });
        }
      } else {
        await scrollToProfileSuggestions(page, 5);
        const candidateSeeAllResult = await clickProfileSuggestionsSeeAll(page);
        const candidateSeeAllClicked = Boolean(candidateSeeAllResult?.clicked);

        if (candidateSeeAllClicked) {
          await sleep(1300);
        }

        let candidateSuggestions = null;

        while (!candidateSuggestions) {
          candidateSuggestions = await collectProfileSuggestionItemsDeep(
            page,
            candidate.username,
            maxCandidateSuggestions,
            {
              includeSeeAll: true,
              forceSeeAll: true,
              continueUntilEnd: true,
              runtimeConfig,
              maxInlineRounds: runtimeConfig.suggestionCandidateInlineMaxRounds || 24,
              maxDialogRounds: runtimeConfig.suggestionCandidateDialogMaxRounds || 36,
            },
          );

          const suggestionsHttp429 = await pageHas429();
          const suggestionsHit429 = suggestionsHttp429.detected
            || (Boolean(candidateSuggestions.rateLimited) && resultLooksLikeHttp429(candidateSuggestions));

          if (!suggestionsHit429) {
            break;
          }

          const switched = await switchScraperProfileFor429(`Kandidaten-Vorschlaege @${candidate.username}`, candidate.profileUrl);

          if (!switched) {
            rateLimited = true;
            rateLimitText = suggestionsHttp429.text || candidateSuggestions.rateLimitText || 'HTTP ERROR 429';
            break;
          }

          candidateSuggestions = null;
          await scrollToProfileSuggestions(page, 5);
        }

        if (candidateSuggestions.rateLimited) {
          rateLimited = true;
          rateLimitText = candidateSuggestions.rateLimitText || rateLimitText;
        }

        targetFound = candidateSuggestions.items.some((item) => (
          normalizeInstagramUsername(item.username) === targetUsername
        ));
        const definitiveNoMatch = !targetFound
          && Boolean(candidateSuggestions.available)
          && !Boolean(candidateSuggestions.rateLimited);

        checkedCandidate = {
          ...candidate,
          checked: true,
          checkMode: 'profile-suggestions',
          profileImageUrl: candidateProfileImageUrl,
          profileVisibility: candidateProfileVisibility || (candidateProfile.isPrivate === true ? 'private' : null),
          isPrivate: typeof candidateHoverCard?.isPrivate === 'boolean' ? candidateHoverCard.isPrivate : candidateProfile.isPrivate,
          postsCount: hasFiniteNumericValue(candidateHoverCard?.postsCount)
            ? Number(candidateHoverCard.postsCount)
            : (hasFiniteNumericValue(candidate.postsCount) ? Number(candidate.postsCount) : candidateProfile.postsCount),
          followersCount: hasFiniteNumericValue(candidateHoverCard?.followersCount)
            ? Number(candidateHoverCard.followersCount)
            : (hasFiniteNumericValue(candidate.followersCount) ? Number(candidate.followersCount) : candidateProfile.followersCount),
          followingCount: hasFiniteNumericValue(candidateHoverCard?.followingCount)
            ? Number(candidateHoverCard.followingCount)
            : (hasFiniteNumericValue(candidate.followingCount) ? Number(candidate.followingCount) : candidateProfile.followingCount),
          hoverCard: candidateHoverCard,
          targetFoundAsSuggestion: targetFound,
          finalDismissedAfterSecondMiss: definitiveNoMatch && Number(candidate.previousNoMatchChecks || 0) >= 1,
          dismissedFromSuggestions: Boolean(candidate.dismissedBeforeCheck),
          suggestionsObserved: candidateSuggestions.items.length,
          suggestionsAvailable: Boolean(candidateSuggestions.available),
          suggestionsRateLimited: Boolean(candidateSuggestions.rateLimited),
          suggestionPreview: candidateSuggestions.items.slice(0, maxCandidateSuggestions),
        };

        progressMessage = targetFound
          ? `Vorschlag-Verbindung gefunden: @${candidate.username} zeigt @${targetUsername}.`
          : (checkedCandidate.finalDismissedAfterSecondMiss
            ? `@${candidate.username} erneut ohne Treffer geprueft und endgueltig aus den sichtbaren Vorschlaegen entfernt.`
            : `@${candidate.username} geprueft; @${targetUsername} war nicht in den ersten Vorschlaegen.`);
        progressPayload = {
          targetFoundAsSuggestion: targetFound,
          suggestionsObserved: candidateSuggestions.items.length,
        };

        if (targetFound) {
          matchedCandidates.push({
            username: candidate.username,
            displayName: candidate.displayName || null,
            profileUrl: candidate.profileUrl,
            profileImageUrl: candidateProfileImageUrl,
            profileVisibility: candidateProfileVisibility,
            isPrivate: typeof candidateHoverCard?.isPrivate === 'boolean' ? candidateHoverCard.isPrivate : candidateProfile.isPrivate,
            postsCount: hasFiniteNumericValue(candidateHoverCard?.postsCount)
              ? Number(candidateHoverCard.postsCount)
              : (hasFiniteNumericValue(candidate.postsCount) ? Number(candidate.postsCount) : candidateProfile.postsCount),
            followersCount: hasFiniteNumericValue(candidateHoverCard?.followersCount)
              ? Number(candidateHoverCard.followersCount)
              : (hasFiniteNumericValue(candidate.followersCount) ? Number(candidate.followersCount) : candidateProfile.followersCount),
            followingCount: hasFiniteNumericValue(candidateHoverCard?.followingCount)
              ? Number(candidateHoverCard.followingCount)
              : (hasFiniteNumericValue(candidate.followingCount) ? Number(candidate.followingCount) : candidateProfile.followingCount),
            hoverCard: candidateHoverCard,
            sourceSuggestionUsername: candidate.username,
            sourcePublicUsername: candidate.username,
            targetFoundAsSuggestion: true,
            sourceLists: ['profile_suggestions'],
            suggestionPreview: candidateSuggestions.items.slice(0, maxCandidateSuggestions),
          });
        }
      }

      checkedCandidates.push(checkedCandidate);
      rememberObservedSuggestion(checkedCandidate, {
        checked: true,
        skipped: false,
        matched: targetFound,
        targetFoundAsSuggestion: Boolean(checkedCandidate.targetFoundAsSuggestion),
        targetFoundInPublicLists: Boolean(checkedCandidate.targetFoundInPublicLists),
        targetFoundInFollowers: Boolean(checkedCandidate.targetFoundInFollowers),
        targetFoundInFollowing: Boolean(checkedCandidate.targetFoundInFollowing),
      });

      progressLog(rateLimited ? 'suggestions-rate-limited' : 'suggestions-candidate-checked', {
        relationship: 'suggestions',
        loaded: checkedCandidates.length,
        expectedCount: candidatesToCheck.length,
        candidateUsername: candidate.username,
        ...progressPayload,
        dismissedSuggestions: dismissedCount,
        foundSuggestions: matchedCandidates.length,
        suggestionConnectionsPreview: matchedCandidates.slice(-40),
        observedSuggestionCount: observedSuggestionsByUsername.size,
        observedSuggestionsPreview: observedSuggestionsPreview(),
        message: progressMessage,
        ...(await captureLivePreviewScreenshot(page, runtimeConfig)),
      });

      if (rateLimited || gracefullyStopped) {
        break;
      }
      } catch (error) {
        if (suggestionCandidateErrorRequiresAbort(error, page)) {
          throw error;
        }

        const errorMessage = normalizeCandidateErrorMessage(error);

        checkedCandidates.push({
          ...candidate,
          checked: false,
          skipped: true,
          skippedReason: 'candidate-error',
          error: errorMessage,
        });
        rememberObservedSuggestion(candidate, {
          checked: false,
          skipped: true,
          skippedReason: 'candidate-error',
          error: errorMessage,
        });

        notes.push(`Vorschlags-Kandidat @${candidate.username} uebersprungen: ${errorMessage}`);

        progressLog('suggestions-candidate-error', {
          relationship: 'suggestions',
          loaded: checkedCandidates.length,
          expectedCount: candidatesToCheck.length,
          candidateUsername: candidate.username,
          foundSuggestions: matchedCandidates.length,
          suggestionConnectionsPreview: matchedCandidates.slice(-40),
          observedSuggestionCount: observedSuggestionsByUsername.size,
          observedSuggestionsPreview: observedSuggestionsPreview(),
          candidateError: errorMessage,
          skippedReason: 'candidate-error',
          message: `@${candidate.username} wurde wegen eines Fehlers uebersprungen; naechster Vorschlag wird geprueft.`,
          ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
        });
      }
    }
  }

  const candidatesNeedingPostDismissal = [
    ...matchedCandidates.map((candidate) => ({
      ...candidate,
      dismissReason: 'newly-saved-match',
    })),
    ...checkedCandidates
      .filter((candidate) => candidate.finalDismissedAfterSecondMiss)
      .map((candidate) => ({
        ...candidate,
        dismissReason: 'second-no-match',
      })),
  ].filter((candidate) => (
    !dismissedUsernames.has(normalizeInstagramUsername(candidate.username))
  ));

  if (!rateLimited && !gracefullyStopped && candidatesNeedingPostDismissal.length > 0) {
    try {
      await navigateWithSoftTimeout(page, profileUrl, runtimeConfig);
      await sleep(900);
      await scrollToProfileSuggestions(page, 4);

      for (const candidate of candidatesNeedingPostDismissal) {
        const dismissed = await dismissVisibleSuggestion(page, candidate.username);

        if (!dismissed) {
          continue;
        }

        dismissedCount += 1;
        dismissedUsernames.add(normalizeInstagramUsername(candidate.username));
        dismissedCandidates.push({
          ...candidate,
          dismissed: true,
          dismissedBeforeCheck: false,
          dismissReason: candidate.dismissReason,
        });
        await sleep(350);
      }
    } catch (error) {
      if (isFatalSuggestionCandidateError(error)) {
        throw error;
      }

      notes.push(`Vorschlags-Aufraeumen uebersprungen: ${normalizeCandidateErrorMessage(error)}`);
    }
  }

  const candidateErrorCount = checkedCandidates.filter((candidate) => (
    ['candidate-error', 'candidate-navigation-error'].includes(candidate?.skippedReason)
  )).length;
  const observedSuggestions = Array.from(observedSuggestionsByUsername.values());
  const alreadyKnownObservedCount = observedSuggestions.filter((candidate) => (
    Boolean(candidate.alreadyKnown) || candidate.skippedReason === 'already-saved-match'
  )).length;
  const knownOrSkippedObservedCount = observedSuggestions.filter((candidate) => (
    Boolean(candidate.alreadyKnown)
    || Boolean(candidate.skipped)
    || [
      'already-saved-match',
      'already-dismissed-no-match',
      'already-scanned-suggestion',
    ].includes(candidate.skippedReason)
    || Number(candidate.previousNoMatchChecks || 0) > 0
  )).length;
  const statusLevel = gracefullyStopped || rateLimited || candidateErrorCount > 0 ? 'partial' : 'success';
  const statusMessage = gracefullyStopped
    ? 'Profilvorschlag-Verbindungsscan wurde beendet; bisherige Treffer wurden gespeichert.'
    : (rateLimited
      ? 'Profilvorschlag-Verbindungsscan wurde wegen Instagram-Rate-Limit pausiert.'
      : (candidateErrorCount > 0
        ? `Profilvorschlag-Verbindungsscan abgeschlossen; ${candidateErrorCount} Kandidaten wurden wegen Fehlern uebersprungen.`
        : (matchedCandidates.length === 0 && observedSuggestions.length > 0 && checkedCandidates.length === 0 && knownOrSkippedObservedCount === observedSuggestions.length
          ? `Profilvorschlag-Verbindungsscan abgeschlossen; keine neuen Verbindungen, weil alle ${observedSuggestions.length} gefundenen Vorschlaege bereits bekannt waren.`
          : 'Profilvorschlag-Verbindungsscan abgeschlossen.')));

  progressLog('suggestions-complete', {
    relationship: 'suggestions',
    loaded: checkedCandidates.length,
    expectedCount: candidatesToCheck.length,
    foundSuggestions: matchedCandidates.length,
    dismissedSuggestions: dismissedCount,
    skippedSuggestions: skippedCandidates.length,
    failedSuggestions: candidateErrorCount,
    suggestionConnectionsPreview: matchedCandidates.slice(-40),
    observedSuggestionCount: observedSuggestions.length,
    observedSuggestionsPreview: observedSuggestionsPreview(),
    knownSuggestionCount: alreadyKnownObservedCount,
    knownObservedSuggestions: alreadyKnownObservedCount,
    gracefullyStopped,
    rateLimited,
    message: statusMessage,
    ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
  });

  notes.push(
    `Profilvorschlag-Verbindungsscan: ${checkedCandidates.length} von ${candidatesToCheck.length} Kandidaten geprueft, ${matchedCandidates.length} Treffer.`,
  );

  if (observedSuggestions.length > 0) {
    notes.push(`${observedSuggestions.length} Vorschlaege wurden in der sichtbaren Vorschlagsliste erkannt.`);
  }

  if (dismissedCount > 0) {
    notes.push(`${dismissedCount} Vorschlaege wurden aus der sichtbaren Vorschlagsliste entfernt.`);
  }

  if (rateLimited) {
    notes.push('Instagram hat die Profilvorschlaege per Rate-Limit blockiert; der Scan wurde pausiert.');
  }

  return {
    ok: !rateLimited,
    statusLevel,
    statusMessage,
    targetUsername,
    attempted: true,
    available: Boolean(targetSuggestions.available),
    seeAllClicked: targetSeeAllClicked,
    observedCount: observedSuggestions.length,
    collectionRounds: targetSuggestions.rounds || 0,
    profileLinkCandidatesSeen: targetSuggestions.profileLinkCandidatesSeen || 0,
    targetCollectionDebug: {
      surfaceBeforeCollection: targetSurfaceDebug,
      ...(targetSuggestions.collectionDebug || {}),
    },
    dismissedKnownCount: targetSuggestions.dismissedKnownCount || 0,
    seenKnownCount: targetSuggestions.seenKnownCount || 0,
    observedSuggestions,
    knownObservedCount: alreadyKnownObservedCount,
    knownOrSkippedObservedCount,
    checkedCount: checkedCandidates.length,
    matchCount: matchedCandidates.length,
    dismissedCount,
    skippedCount: skippedCandidates.length,
    candidateErrorCount,
    rateLimited,
    rateLimitText,
    gracefullyStopped,
    suggestions: candidates,
    candidatesToCheck,
    skippedCandidates,
    dismissedCandidates,
    checkedCandidates,
    matches: matchedCandidates,
  };
}

module.exports = {
  runProfileSuggestionConnectionScan,
};

if (require.main === module) {
  runInstagramScraperEntrypoint({
    defaultMode: 'suggestions',
    allowedModes: ['suggestions', 'profile-suggestions', 'suggestion-connections'],
  });
}
