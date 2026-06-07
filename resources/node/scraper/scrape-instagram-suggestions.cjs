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

async function runProfileSuggestionConnectionScan(deps, page, runtimeState, notes, targetUsername, profileUrl) {
  const {
    captureLivePreviewScreenshot,
    clickProfileSuggestionsSeeAll,
    closeInstagramDialog,
    collectProfileInfo,
    collectProfileSuggestionItemsDeep,
    collectSuggestionCandidatePublicListConnection,
    dismissVisibleSuggestion,
    hasFiniteNumericValue,
    markGracefulStopIfRequested,
    navigateWithSoftTimeout,
    normalizeInstagramUsername,
    normalizeSuggestionCandidateHistory,
    progressLog,
    scrollToProfileSuggestions,
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

  progressLog('suggestions-opening', {
    relationship: 'suggestions',
    loaded: 0,
    expectedCount: maxTargetSuggestions,
    message: `Profilvorschlaege fuer @${targetUsername} werden gesucht.`,
    ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
  });

  await scrollToProfileSuggestions(page, 6);

  const targetSuggestions = await collectProfileSuggestionItemsDeep(
    page,
    targetUsername,
    maxTargetSuggestions,
    {
      dismissUsernames: alreadyScannedSuggestionUsernames,
      includeSeeAll: true,
      forceSeeAll: true,
      continueUntilEnd: true,
      runtimeConfig,
      maxInlineRounds: runtimeConfig.suggestionInlineMaxRounds || 36,
      maxDialogRounds: runtimeConfig.suggestionDialogMaxRounds || 48,
    },
  );
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
    const shouldDismissBeforeRetry = !shouldDismissAsKnownMatch
      && !shouldDismissAsFinalMiss
      && Number(history.noMatchChecks || 0) >= 1;

    if (shouldDismissAsKnownMatch || shouldDismissAsFinalMiss || shouldDismissBeforeRetry) {
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
            : (shouldDismissAsFinalMiss ? 'already-dismissed-no-match' : 'retry-no-match-candidate'),
          previousNoMatchChecks: Number(history.noMatchChecks || 0),
          previousTargetFoundAsSuggestion: shouldDismissAsKnownMatch,
        });
        await sleep(350);
      }
    }

    if (shouldDismissAsKnownMatch || shouldDismissAsFinalMiss) {
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
      dismissedBeforeCheck: shouldDismissBeforeRetry && dismissedUsernames.has(candidateUsername),
    });
  }

  progressLog(rateLimited ? 'suggestions-rate-limited' : 'suggestions-target-list', {
    relationship: 'suggestions',
    loaded: 0,
    expectedCount: candidatesToCheck.length,
    suggestionsObserved: candidates.length,
    profileLinkCandidatesSeen: targetSuggestions.profileLinkCandidatesSeen || 0,
    suggestionCollectionRounds: targetSuggestions.rounds || 0,
    suggestionKnownSeen: targetSuggestions.seenKnownCount || 0,
    dismissedSuggestions: dismissedCount,
    skippedSuggestions: skippedCandidates.length,
    message: rateLimited
      ? 'Instagram hat die Profilvorschlaege per Rate-Limit blockiert.'
      : `${candidates.length} neue Profilvorschlaege gefunden. ${candidatesToCheck.length} Kandidaten werden geprueft.`,
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
        message: Number(candidate.previousNoMatchChecks || 0) > 0
          ? `@${candidate.username} wird nach frueherem Nichttreffer erneut geprueft.`
          : `@${candidate.username} wird auf Verbindung zu @${targetUsername} geprueft.`,
        ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
      });

      try {
      const candidateNavigation = await navigateWithSoftTimeout(page, candidate.profileUrl, runtimeConfig);

      if (!candidateNavigation.ok) {
        checkedCandidates.push({
          ...candidate,
          checked: false,
          error: candidateNavigation.error,
          skipped: true,
          skippedReason: 'candidate-navigation-error',
        });

        progressLog('suggestions-candidate-error', {
          relationship: 'suggestions',
          loaded: checkedCandidates.length,
          expectedCount: candidatesToCheck.length,
          candidateUsername: candidate.username,
          foundSuggestions: matchedCandidates.length,
          suggestionConnectionsPreview: matchedCandidates.slice(-40),
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
          message: `@${candidate.username} ist oeffentlich; Follower und Gefolgt werden nach @${targetUsername} durchsucht.`,
          ...(await captureLivePreviewScreenshot(page, runtimeConfig)),
        });

        const publicListSearch = await collectSuggestionCandidatePublicListConnection(
          page,
          candidate,
          candidateProfile,
          targetUsername,
          runtimeConfig,
        );

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
          profileVisibility: candidateHoverCard?.profileVisibility || candidate.profileVisibility || (candidateProfile.isPrivate === false ? 'public' : null),
          isPrivate: typeof candidateHoverCard?.isPrivate === 'boolean' ? candidateHoverCard.isPrivate : candidateProfile.isPrivate,
          postsCount: hasFiniteNumericValue(candidateHoverCard?.postsCount) ? Number(candidateHoverCard.postsCount) : candidate.postsCount,
          followersCount: hasFiniteNumericValue(candidateHoverCard?.followersCount) ? Number(candidateHoverCard.followersCount) : candidate.followersCount,
          followingCount: hasFiniteNumericValue(candidateHoverCard?.followingCount) ? Number(candidateHoverCard.followingCount) : candidate.followingCount,
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
            profileImageUrl: candidate.profileImageUrl || null,
            profileVisibility: candidateHoverCard?.profileVisibility || candidate.profileVisibility || 'public',
            isPrivate: typeof candidateHoverCard?.isPrivate === 'boolean' ? candidateHoverCard.isPrivate : false,
            postsCount: hasFiniteNumericValue(candidateHoverCard?.postsCount) ? Number(candidateHoverCard.postsCount) : candidate.postsCount,
            followersCount: hasFiniteNumericValue(candidateHoverCard?.followersCount) ? Number(candidateHoverCard.followersCount) : candidate.followersCount,
            followingCount: hasFiniteNumericValue(candidateHoverCard?.followingCount) ? Number(candidateHoverCard.followingCount) : candidate.followingCount,
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
        const candidateSeeAllClicked = await clickProfileSuggestionsSeeAll(page);

        if (candidateSeeAllClicked) {
          await sleep(1300);
        }

        const candidateSuggestions = await collectProfileSuggestionItemsDeep(
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
          profileVisibility: candidateHoverCard?.profileVisibility || candidate.profileVisibility || (candidateProfile.isPrivate === true ? 'private' : null),
          isPrivate: typeof candidateHoverCard?.isPrivate === 'boolean' ? candidateHoverCard.isPrivate : candidateProfile.isPrivate,
          postsCount: hasFiniteNumericValue(candidateHoverCard?.postsCount) ? Number(candidateHoverCard.postsCount) : candidate.postsCount,
          followersCount: hasFiniteNumericValue(candidateHoverCard?.followersCount) ? Number(candidateHoverCard.followersCount) : candidate.followersCount,
          followingCount: hasFiniteNumericValue(candidateHoverCard?.followingCount) ? Number(candidateHoverCard.followingCount) : candidate.followingCount,
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
            profileImageUrl: candidate.profileImageUrl || null,
            profileVisibility: candidateHoverCard?.profileVisibility || candidate.profileVisibility || null,
            isPrivate: typeof candidateHoverCard?.isPrivate === 'boolean' ? candidateHoverCard.isPrivate : candidateProfile.isPrivate,
            postsCount: hasFiniteNumericValue(candidateHoverCard?.postsCount) ? Number(candidateHoverCard.postsCount) : candidate.postsCount,
            followersCount: hasFiniteNumericValue(candidateHoverCard?.followersCount) ? Number(candidateHoverCard.followersCount) : candidate.followersCount,
            followingCount: hasFiniteNumericValue(candidateHoverCard?.followingCount) ? Number(candidateHoverCard.followingCount) : candidate.followingCount,
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

      progressLog(rateLimited ? 'suggestions-rate-limited' : 'suggestions-candidate-checked', {
        relationship: 'suggestions',
        loaded: checkedCandidates.length,
        expectedCount: candidatesToCheck.length,
        candidateUsername: candidate.username,
        ...progressPayload,
        dismissedSuggestions: dismissedCount,
        foundSuggestions: matchedCandidates.length,
        suggestionConnectionsPreview: matchedCandidates.slice(-40),
        message: progressMessage,
        ...(await captureLivePreviewScreenshot(page, runtimeConfig)),
      });

      if (rateLimited || gracefullyStopped) {
        break;
      }
      } catch (error) {
        if (isFatalSuggestionCandidateError(error)) {
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

        notes.push(`Vorschlags-Kandidat @${candidate.username} uebersprungen: ${errorMessage}`);

        progressLog('suggestions-candidate-error', {
          relationship: 'suggestions',
          loaded: checkedCandidates.length,
          expectedCount: candidatesToCheck.length,
          candidateUsername: candidate.username,
          foundSuggestions: matchedCandidates.length,
          suggestionConnectionsPreview: matchedCandidates.slice(-40),
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
  const statusLevel = gracefullyStopped || rateLimited || candidateErrorCount > 0 ? 'partial' : 'success';
  const statusMessage = gracefullyStopped
    ? 'Profilvorschlag-Verbindungsscan wurde beendet; bisherige Treffer wurden gespeichert.'
    : (rateLimited
      ? 'Profilvorschlag-Verbindungsscan wurde wegen Instagram-Rate-Limit pausiert.'
      : (candidateErrorCount > 0
        ? `Profilvorschlag-Verbindungsscan abgeschlossen; ${candidateErrorCount} Kandidaten wurden wegen Fehlern uebersprungen.`
        : 'Profilvorschlag-Verbindungsscan abgeschlossen.'));

  progressLog('suggestions-complete', {
    relationship: 'suggestions',
    loaded: checkedCandidates.length,
    expectedCount: candidatesToCheck.length,
    foundSuggestions: matchedCandidates.length,
    dismissedSuggestions: dismissedCount,
    skippedSuggestions: skippedCandidates.length,
    failedSuggestions: candidateErrorCount,
    suggestionConnectionsPreview: matchedCandidates.slice(-40),
    gracefullyStopped,
    rateLimited,
    message: statusMessage,
    ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
  });

  notes.push(
    `Profilvorschlag-Verbindungsscan: ${checkedCandidates.length} von ${candidatesToCheck.length} Kandidaten geprueft, ${matchedCandidates.length} Treffer.`,
  );

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
    observedCount: candidates.length,
    collectionRounds: targetSuggestions.rounds || 0,
    profileLinkCandidatesSeen: targetSuggestions.profileLinkCandidatesSeen || 0,
    dismissedKnownCount: targetSuggestions.dismissedKnownCount || 0,
    seenKnownCount: targetSuggestions.seenKnownCount || 0,
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
