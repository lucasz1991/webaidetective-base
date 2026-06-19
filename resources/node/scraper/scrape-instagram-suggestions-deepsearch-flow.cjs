#!/usr/bin/env node

// DeepSearch ist Stufe 2 des Vorschlags-Scans:
// Er scannt nicht erneut die Zielperson, sondern die Vorschlagslisten der zuvor
// im normalen Vorschlags-Scan gefundenen Profile.

const { runProfileSuggestionConnectionScan: runProfileSuggestionConnectionScanCore } = require('./scrape-instagram-suggestions.cjs');

function normalizeCandidateProfile(candidate, normalizeInstagramUsername) {
  const username = normalizeInstagramUsername(candidate?.username || '');

  if (!username) {
    return null;
  }

  return {
    ...candidate,
    username,
    profileUrl: candidate?.profileUrl || `https://www.instagram.com/${username}/`,
  };
}

function normalizeDeepSearchHistoryState(history = {}) {
  const state = history && typeof history === 'object' && !Array.isArray(history) ? history : {};

  return {
    hasMatch: Boolean(state.hasMatch),
    knownSuggestion: Boolean(state.knownSuggestion),
    knownProfile: Boolean(state.knownProfile),
    noMatchChecks: Math.max(0, Math.floor(Number(state.noMatchChecks || 0))),
    permanentlyDismissed: Boolean(state.permanentlyDismissed),
    recentlyChecked: Boolean(state.recentlyChecked),
    lastCheckedAt: state.lastCheckedAt || null,
    recheckAfter: state.recheckAfter || null,
    recentlySuggestionProfileScanned: Boolean(state.recentlySuggestionProfileScanned),
    lastSuggestionProfileScanAt: state.lastSuggestionProfileScanAt || null,
    suggestionProfileRecheckAfter: state.suggestionProfileRecheckAfter || null,
  };
}

function isRecentDeepSearchFinalMiss(history, noMatchSkipAfter) {
  return Boolean(history.recentlyChecked)
    && (
      Boolean(history.permanentlyDismissed)
      || Number(history.noMatchChecks || 0) >= noMatchSkipAfter
    );
}

function shouldSkipDeepSearchCandidate(history, noMatchSkipAfter) {
  return Boolean(history.hasMatch)
    || Boolean(history.recentlyChecked)
    || isRecentDeepSearchFinalMiss(history, noMatchSkipAfter);
}

function deepSearchSkipReason(history, noMatchSkipAfter) {
  if (Boolean(history.hasMatch)) {
    return 'already-saved-match';
  }

  if (isRecentDeepSearchFinalMiss(history, noMatchSkipAfter)) {
    return 'already-dismissed-no-match';
  }

  if (Boolean(history.recentlyChecked)) {
    return 'recently-checked-suggestion';
  }

  return 'already-known-suggestion';
}

function deepSearchHistoryMeta(history = {}) {
  return {
    alreadyKnown: Boolean(history.hasMatch || history.knownSuggestion || history.knownProfile),
    recentlyChecked: Boolean(history.recentlyChecked),
    lastCheckedAt: history.lastCheckedAt || null,
    recheckAfter: history.recheckAfter || null,
    previousNoMatchChecks: Number(history.noMatchChecks || 0),
    previousTargetFoundAsSuggestion: Boolean(history.hasMatch),
  };
}

function normalizeSeedProfiles(runtimeConfig = {}, normalizeInstagramUsername) {
  const pendingCandidates = Array.isArray(runtimeConfig.suggestionPendingCandidates)
    ? runtimeConfig.suggestionPendingCandidates
    : [];
  const configuredSeeds = Array.isArray(runtimeConfig.suggestionDeepSearchSeedProfiles)
    ? runtimeConfig.suggestionDeepSearchSeedProfiles
    : [];
  const sourceProfiles = pendingCandidates.length > 0 && runtimeConfig.suggestionResumePendingOnly
    ? pendingCandidates
    : configuredSeeds;
  const seen = new Set();
  const normalized = [];

  for (const sourceProfile of sourceProfiles) {
    const candidate = normalizeCandidateProfile(sourceProfile, normalizeInstagramUsername);

    if (!candidate || seen.has(candidate.username)) {
      continue;
    }

    seen.add(candidate.username);
    normalized.push(candidate);
  }

  return normalized;
}

function branchItems(sourceUsername, items = [], maxItems = 0) {
  const limit = maxItems > 0 ? maxItems : items.length;

  return items.slice(0, limit).map((item) => ({
    ...item,
    sourceSuggestionUsername: sourceUsername,
    sourcePublicUsername: sourceUsername,
    sourceLists: Array.isArray(item.sourceLists)
      ? Array.from(new Set([...item.sourceLists, 'profile_suggestions_level2']))
      : ['profile_suggestions_level2'],
    suggestionLevel: 2,
  }));
}

async function runInstagramSuggestionsDeepSearchFlow(
  deps,
  page,
  runtimeState,
  notes,
  targetUsername,
  profileUrl,
  options = {},
) {
  const {
    captureLivePreviewScreenshot,
    closeInstagramDialog,
    collectProfileSuggestionItemsDeep,
    detectInstagramHttp429Page,
    markGracefulStopIfRequested,
    navigateWithSoftTimeout,
    normalizeInstagramUsername,
    normalizeSuggestionCandidateHistory,
    progressLog,
    scrollToProfileSuggestions,
    sleep,
  } = deps;
  let runtimeConfig = {
    ...(runtimeState.runtimeConfig || {}),
    suggestionDismissChecked: false,
  };
  runtimeState.runtimeConfig = runtimeConfig;

  const seedProfiles = normalizeSeedProfiles(runtimeConfig, normalizeInstagramUsername);
  const candidateHistory = normalizeSuggestionCandidateHistory
    ? normalizeSuggestionCandidateHistory(runtimeConfig.suggestionCandidateHistory || {})
    : {};
  const noMatchSkipAfter = Math.max(1, Math.min(100, Number(runtimeConfig.suggestionNoMatchSkipAfter || 2)));
  const maxParents = Math.max(1, Number(runtimeConfig.suggestionDeepSearchMaxProfiles || runtimeConfig.suggestionSecondLevelMaxParents || 250));
  const maxItemsPerParent = Math.max(1, Number(
    runtimeConfig.suggestionDeepSearchMaxItemsPerProfile
      || runtimeConfig.suggestionSecondLevelMaxItemsPerParent
      || runtimeConfig.suggestionCandidateMaxItems
      || 300,
  ));
  const maxTotalItems = Math.max(1, Number(runtimeConfig.suggestionDeepSearchMaxTotalItems || runtimeConfig.suggestionSecondLevelMaxTotalChecks || 5000));
  const parentsToScan = seedProfiles.slice(0, maxParents);
  const checkedCandidates = [];
  const skippedCandidates = [];
  const branchCandidateMap = new Map();
  const branchedConnections = [];
  let totalObserved = 0;
  let gracefullyStopped = false;
  let rateLimited = false;
  let rateLimitText = null;

  if (parentsToScan.length === 0) {
    const statusMessage = 'Vorschlaege DeepSearch konnte nicht starten: keine Profile aus einem vorherigen Vorschlaege-Scan gefunden.';

    progressLog('suggestions-deepsearch-no-seeds', {
      relationship: 'suggestions',
      loaded: 0,
      expectedCount: 0,
      observedSuggestionCount: 0,
      suggestionBranchedConnections: [],
      message: statusMessage,
      ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
    });

    notes.push(statusMessage);

    return {
      ok: true,
      statusLevel: 'partial',
      statusMessage,
      scanType: 'suggestion-deepsearch',
      targetUsername,
      attempted: true,
      available: false,
      observedCount: 0,
      checkedCount: 0,
      matchCount: 0,
      rateLimited: false,
      gracefullyStopped: false,
      suggestions: [],
      candidatesToCheck: [],
      checkedCandidates: [],
      skippedCandidates: [],
      dismissedCandidates: [],
      observedSuggestions: [],
      suggestionBranchedConnections: [],
      matches: [],
    };
  }

  progressLog('suggestions-deepsearch-opening', {
    relationship: 'suggestions',
    loaded: 0,
    expectedCount: parentsToScan.length,
    observedSuggestionCount: 0,
    suggestionBranchedConnections: [],
    message: runtimeConfig.suggestionResumePendingOnly
      ? `Vorschlaege DeepSearch wird fortgesetzt: ${parentsToScan.length} Profile in der Warteschlange.`
      : `Vorschlaege DeepSearch startet Stufe 2 fuer ${parentsToScan.length} gefundene Vorschlagsprofile.`,
    ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
  });

  const pageHas429 = async () => {
    if (!detectInstagramHttp429Page) {
      return { detected: false };
    }

    return detectInstagramHttp429Page(page);
  };
  const looksLikeHttp429 = (value) => (
    /http\s*error\s*429|error\s*429|http\s*429|\b429\b|too many requests|t[aä]gliches zeitlimit erreicht|daily time limit reached|reached your daily time limit/i.test(String(value || ''))
  );
  const resultLooksLikeHttp429 = (result = {}) => (
    Number(result?.status || 0) === 429
    || looksLikeHttp429(result?.error)
    || looksLikeHttp429(result?.rateLimitText)
  );

  for (const parent of parentsToScan) {
    if (totalObserved >= maxTotalItems) {
      break;
    }

    const stopRequest = markGracefulStopIfRequested('suggestions', {
      loaded: checkedCandidates.length,
      expectedCount: parentsToScan.length,
      observedSuggestionCount: totalObserved,
    });

    if (stopRequest) {
      gracefullyStopped = true;
      break;
    }

    const parentHistory = normalizeDeepSearchHistoryState(candidateHistory[parent.username]);

    if (!runtimeConfig.suggestionResumePendingOnly && parentHistory.recentlySuggestionProfileScanned) {
      const skippedParent = {
        ...parent,
        checked: false,
        skipped: true,
        skippedReason: 'recently-scanned-suggestion-profile',
        checkMode: 'profile-suggestions-level2',
        recentlySuggestionProfileScanned: true,
        lastSuggestionProfileScanAt: parentHistory.lastSuggestionProfileScanAt || null,
        suggestionProfileRecheckAfter: parentHistory.suggestionProfileRecheckAfter || null,
      };

      checkedCandidates.push(skippedParent);
      skippedCandidates.push(skippedParent);

      progressLog('suggestions-deepsearch-profile-skipped-recent', {
        relationship: 'suggestions',
        loaded: checkedCandidates.length,
        expectedCount: parentsToScan.length,
        candidateUsername: parent.username,
        sourceUsername: parent.username,
        observedSuggestionCount: totalObserved,
        skippedReason: skippedParent.skippedReason,
        lastSuggestionProfileScanAt: skippedParent.lastSuggestionProfileScanAt,
        suggestionProfileRecheckAfter: skippedParent.suggestionProfileRecheckAfter,
        message: `Stufe 2: @${parent.username} wurde in den letzten ${runtimeConfig.suggestionCandidateRecheckHours || 48} Stunden bereits als Vorschlagsprofil gescannt und wird uebersprungen.`,
        ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
      });

      continue;
    }

    progressLog('suggestions-deepsearch-profile-opening', {
      relationship: 'suggestions',
      loaded: checkedCandidates.length,
      expectedCount: parentsToScan.length,
      candidateUsername: parent.username,
      sourceUsername: parent.username,
      observedSuggestionCount: totalObserved,
      message: `Stufe 2: Vorschlaege von @${parent.username} werden gescannt.`,
      ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
    });

    try {
      const navigation = await navigateWithSoftTimeout(page, parent.profileUrl, runtimeConfig);
      const navigation429 = await pageHas429();

      if (resultLooksLikeHttp429(navigation) || navigation429.detected) {
        rateLimited = true;
        rateLimitText = navigation429.text || navigation.error || 'HTTP ERROR 429';
        checkedCandidates.push({
          ...parent,
          checked: false,
          skipped: true,
          skippedReason: 'candidate-http-429',
          error: rateLimitText,
        });
        break;
      }

      if (!navigation.ok) {
        checkedCandidates.push({
          ...parent,
          checked: false,
          skipped: true,
          skippedReason: 'candidate-navigation-error',
          error: navigation.error,
        });

        progressLog('suggestions-deepsearch-profile-error', {
          relationship: 'suggestions',
          loaded: checkedCandidates.length,
          expectedCount: parentsToScan.length,
          candidateUsername: parent.username,
          sourceUsername: parent.username,
          observedSuggestionCount: totalObserved,
          skippedReason: 'candidate-navigation-error',
          message: `Stufe 2: @${parent.username} konnte nicht stabil geladen werden.`,
          ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
        });

        continue;
      }

      await sleep(1100);
      await scrollToProfileSuggestions(page, 5);

      const parentSuggestions = await collectProfileSuggestionItemsDeep(
        page,
        parent.username,
        Math.min(maxItemsPerParent, maxTotalItems - totalObserved),
        {
          includeSeeAll: true,
          forceSeeAll: true,
          openSeeAllFirst: true,
          continueUntilEnd: true,
          runtimeConfig,
          maxInlineRounds: runtimeConfig.suggestionCandidateInlineMaxRounds || runtimeConfig.suggestionInlineMaxRounds || 40,
          maxDialogRounds: runtimeConfig.suggestionCandidateDialogMaxRounds || runtimeConfig.suggestionDialogMaxRounds || 70,
        },
      );
      const collection429 = await pageHas429();

      if (collection429.detected || Boolean(parentSuggestions?.rateLimited)) {
        rateLimited = true;
        rateLimitText = collection429.text || parentSuggestions?.rateLimitText || 'HTTP ERROR 429';
      }

      await closeInstagramDialog(page).catch(() => {});

      const items = Array.isArray(parentSuggestions?.items) ? parentSuggestions.items : [];
      const suggestionPreview = branchItems(parent.username, items, maxItemsPerParent);
      for (const branchCandidate of suggestionPreview) {
        const branchUsername = normalizeInstagramUsername(branchCandidate?.username || '');

        if (!branchUsername || branchUsername === targetUsername || branchUsername === parent.username) {
          continue;
        }

        const previous = branchCandidateMap.get(branchUsername) || {};
        branchCandidateMap.set(branchUsername, {
          ...previous,
          ...branchCandidate,
          username: branchUsername,
          profileUrl: branchCandidate.profileUrl || previous.profileUrl || `https://www.instagram.com/${branchUsername}/`,
          sourceSuggestionUsername: branchCandidate.sourceSuggestionUsername || previous.sourceSuggestionUsername || parent.username,
          sourcePublicUsername: branchCandidate.sourcePublicUsername || previous.sourcePublicUsername || parent.username,
          sourceSeedUsername: parent.username,
          deepSearchBranch: true,
          sourceLists: Array.from(new Set([
            ...(Array.isArray(previous.sourceLists) ? previous.sourceLists : []),
            ...(Array.isArray(branchCandidate.sourceLists) ? branchCandidate.sourceLists : []),
            'profile_suggestions_level2',
          ])),
          suggestionLevel: 2,
        });
      }
      const branch = {
        sourceUsername: parent.username,
        sourceProfileUrl: parent.profileUrl,
        sourceDisplayName: parent.displayName || null,
        targetUsername,
        via: 'profile_suggestions_level2',
        suggestionPreview,
        suggestionsObserved: suggestionPreview.length,
        suggestionsAvailable: Boolean(parentSuggestions?.available),
        suggestionsRateLimited: Boolean(parentSuggestions?.rateLimited),
      };

      branchedConnections.push(branch);
      totalObserved += suggestionPreview.length;
      checkedCandidates.push({
        ...parent,
        checked: !rateLimited,
        skipped: rateLimited,
        skippedReason: rateLimited ? 'candidate-http-429' : null,
        error: rateLimited ? rateLimitText : null,
        matched: false,
        checkMode: 'profile-suggestions-level2',
        suggestionsObserved: suggestionPreview.length,
        suggestionsAvailable: Boolean(parentSuggestions?.available),
        suggestionsRateLimited: Boolean(parentSuggestions?.rateLimited),
        suggestionPreview,
      });

      progressLog(rateLimited ? 'suggestions-deepsearch-rate-limited' : 'suggestions-deepsearch-profile-checked', {
        relationship: 'suggestions',
        loaded: checkedCandidates.length,
        expectedCount: parentsToScan.length,
        candidateUsername: parent.username,
        sourceUsername: parent.username,
        observedSuggestionCount: totalObserved,
        suggestionBranchedConnections: [branch],
        suggestionBranchedConnectionsPreview: branchedConnections.slice(-20),
        message: rateLimited
          ? `Stufe 2: Instagram-Rate-Limit bei @${parent.username}; DeepSearch wird pausiert.`
          : `Stufe 2: ${suggestionPreview.length} Vorschlaege von @${parent.username} gespeichert.`,
        ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
      });

      if (rateLimited) {
        break;
      }
    } catch (error) {
      checkedCandidates.push({
        ...parent,
        checked: false,
        skipped: true,
        skippedReason: 'candidate-error',
        error: String(error?.message || error),
      });

      progressLog('suggestions-deepsearch-profile-error', {
        relationship: 'suggestions',
        loaded: checkedCandidates.length,
        expectedCount: parentsToScan.length,
        candidateUsername: parent.username,
        sourceUsername: parent.username,
        observedSuggestionCount: totalObserved,
        skippedReason: 'candidate-error',
        error: String(error?.message || error),
        message: `Stufe 2: @${parent.username} wurde wegen eines Fehlers uebersprungen.`,
        ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
      });
    }
  }

  const branchCandidates = Array.from(branchCandidateMap.values());
  const branchCandidatesToVerify = [];
  const branchCandidatesSkippedByHistory = [];
  let connectionCheckResult = null;

  if (!rateLimited && !gracefullyStopped && branchCandidates.length > 0) {
    for (const candidate of branchCandidates) {
      const history = normalizeDeepSearchHistoryState(candidateHistory[candidate.username]);

      if (!runtimeConfig.suggestionResumePendingOnly && shouldSkipDeepSearchCandidate(history, noMatchSkipAfter)) {
        const skippedCandidate = {
          ...candidate,
          checked: false,
          skipped: true,
          skippedReason: deepSearchSkipReason(history, noMatchSkipAfter),
          checkMode: 'deepsearch-branch-history',
          ...deepSearchHistoryMeta(history),
        };

        branchCandidatesSkippedByHistory.push(skippedCandidate);
        skippedCandidates.push(skippedCandidate);
        continue;
      }

      branchCandidatesToVerify.push({
        ...candidate,
        ...deepSearchHistoryMeta(history),
        checked: false,
        skipped: false,
      });
    }

    if (branchCandidatesSkippedByHistory.length > 0) {
      progressLog('suggestions-deepsearch-branch-history-skip', {
        relationship: 'suggestions',
        loaded: checkedCandidates.length,
        expectedCount: parentsToScan.length,
        observedSuggestionCount: totalObserved,
        skippedSuggestions: branchCandidatesSkippedByHistory.length,
        branchCandidatesToVerify: branchCandidatesToVerify.length,
        message: `${branchCandidatesSkippedByHistory.length} Stufe-2-Kandidaten wurden uebersprungen, weil sie bereits bekannt oder innerhalb von ${runtimeConfig.suggestionCandidateRecheckHours || 48} Stunden geprueft wurden.`,
        ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
      });
    }

    if (branchCandidatesToVerify.length > 0) {
      progressLog('suggestions-deepsearch-branch-check-start', {
        relationship: 'suggestions',
        loaded: 0,
        expectedCount: branchCandidatesToVerify.length,
        observedSuggestionCount: totalObserved,
        suggestionBranchedConnectionsPreview: branchedConnections.slice(-40),
        message: `${branchCandidatesToVerify.length} Stufe-2-Vorschlaege werden jetzt gegen @${targetUsername} geprueft.`,
        ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
      });

      runtimeState.runtimeConfig = {
        ...runtimeConfig,
        suggestionPendingCandidates: branchCandidatesToVerify,
        suggestionResumePendingOnly: true,
        suggestionSkipPreviouslyChecked: false,
        suggestionDismissChecked: false,
      };

      connectionCheckResult = await runProfileSuggestionConnectionScanCore(
        deps,
        page,
        runtimeState,
        notes,
        targetUsername,
        profileUrl,
        {
          ...options,
          deepSearch: true,
        },
      );

      runtimeConfig = {
        ...(runtimeState.runtimeConfig || runtimeConfig),
        suggestionDismissChecked: false,
      };
      runtimeState.runtimeConfig = runtimeConfig;

      if (connectionCheckResult?.rateLimited) {
        rateLimited = true;
        rateLimitText = connectionCheckResult.rateLimitText || rateLimitText;
      }

      if (connectionCheckResult?.gracefullyStopped) {
        gracefullyStopped = true;
      }

      checkedCandidates.push(...(Array.isArray(connectionCheckResult?.checkedCandidates)
        ? connectionCheckResult.checkedCandidates
        : []));
      skippedCandidates.push(...(Array.isArray(connectionCheckResult?.skippedCandidates)
        ? connectionCheckResult.skippedCandidates
        : []));
    }
  }

  const matchedCandidates = Array.isArray(connectionCheckResult?.matches)
    ? connectionCheckResult.matches
    : [];
  const observedBranchChecks = Array.isArray(connectionCheckResult?.observedSuggestions)
    ? connectionCheckResult.observedSuggestions
    : [];
  const combinedSkippedCandidateMap = new Map();

  for (const candidate of [
    ...skippedCandidates,
    ...checkedCandidates.filter((checkedCandidate) => checkedCandidate?.skipped),
  ]) {
    if (!candidate || typeof candidate !== 'object') {
      continue;
    }

    const key = `${candidate.username || 'unknown'}:${candidate.skippedReason || 'skipped'}`;
    combinedSkippedCandidateMap.set(key, candidate);
  }

  const combinedSkippedCandidates = Array.from(combinedSkippedCandidateMap.values());
  const statusLevel = gracefullyStopped || rateLimited ? 'partial' : 'success';
  const statusMessage = gracefullyStopped
    ? 'Vorschlaege DeepSearch wurde beendet; bisherige Stufe-2-Vorschlaege und Treffer wurden gespeichert.'
    : (rateLimited
      ? 'Vorschlaege DeepSearch wurde wegen Instagram-Rate-Limit pausiert.'
      : `Vorschlaege DeepSearch Stufe 2 abgeschlossen: ${matchedCandidates.length} neue Verbindungen gefunden.`);

  progressLog('suggestions-deepsearch-complete', {
    relationship: 'suggestions',
    loaded: checkedCandidates.length,
    expectedCount: parentsToScan.length + branchCandidatesToVerify.length,
    observedSuggestionCount: totalObserved,
    suggestionBranchedConnectionsPreview: branchedConnections.slice(-40),
    suggestionConnectionsPreview: matchedCandidates.slice(-40),
    foundSuggestions: matchedCandidates.length,
    skippedSuggestions: combinedSkippedCandidates.length,
    gracefullyStopped,
    rateLimited,
    message: statusMessage,
    ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
  });

  notes.push(`Vorschlaege DeepSearch Stufe 2: ${parentsToScan.length} bekannte Profile eingeplant, ${totalObserved} Vorschlaege gesehen, ${branchCandidatesToVerify.length} Stufe-2-Kandidaten gegen @${targetUsername} geprueft.`);

  return {
    ok: !rateLimited,
    statusLevel,
    statusMessage,
    scanType: 'suggestion-deepsearch',
    targetUsername,
    attempted: true,
    available: branchedConnections.length > 0,
    observedCount: totalObserved,
    checkedCount: checkedCandidates.filter((candidate) => candidate.checked).length,
    matchCount: matchedCandidates.length,
    rateLimited,
    rateLimitText,
    gracefullyStopped,
    suggestions: branchCandidates.length > 0 ? branchCandidates : parentsToScan,
    candidatesToCheck: [
      ...parentsToScan,
      ...branchCandidatesToVerify,
    ],
    checkedCandidates,
    skippedCandidates: combinedSkippedCandidates,
    dismissedCandidates: [],
    observedSuggestions: observedBranchChecks,
    suggestionBranchedConnections: branchedConnections,
    matches: matchedCandidates,
  };
}

module.exports = {
  runInstagramSuggestionsDeepSearchFlow,
};
