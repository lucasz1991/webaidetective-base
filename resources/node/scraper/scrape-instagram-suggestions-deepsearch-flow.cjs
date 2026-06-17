#!/usr/bin/env node

// DeepSearch ist Stufe 2 des Vorschlags-Scans:
// Er scannt nicht erneut die Zielperson, sondern die Vorschlagslisten der zuvor
// im normalen Vorschlags-Scan gefundenen Profile.

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
    progressLog,
    scrollToProfileSuggestions,
    sleep,
  } = deps;
  const runtimeConfig = {
    ...(runtimeState.runtimeConfig || {}),
    suggestionDismissChecked: false,
  };
  runtimeState.runtimeConfig = runtimeConfig;

  const seedProfiles = normalizeSeedProfiles(runtimeConfig, normalizeInstagramUsername);
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

  const statusLevel = gracefullyStopped || rateLimited ? 'partial' : 'success';
  const statusMessage = gracefullyStopped
    ? 'Vorschlaege DeepSearch wurde beendet; bisherige Stufe-2-Vorschlaege wurden gespeichert.'
    : (rateLimited
      ? 'Vorschlaege DeepSearch wurde wegen Instagram-Rate-Limit pausiert.'
      : 'Vorschlaege DeepSearch Stufe 2 abgeschlossen.');

  progressLog('suggestions-deepsearch-complete', {
    relationship: 'suggestions',
    loaded: checkedCandidates.length,
    expectedCount: parentsToScan.length,
    observedSuggestionCount: totalObserved,
    suggestionBranchedConnectionsPreview: branchedConnections.slice(-40),
    gracefullyStopped,
    rateLimited,
    message: statusMessage,
    ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
  });

  notes.push(`Vorschlaege DeepSearch Stufe 2: ${checkedCandidates.length} von ${parentsToScan.length} Profilen gescannt, ${totalObserved} Vorschlaege gesehen.`);

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
    matchCount: 0,
    rateLimited,
    rateLimitText,
    gracefullyStopped,
    suggestions: parentsToScan,
    candidatesToCheck: parentsToScan,
    checkedCandidates,
    skippedCandidates: checkedCandidates.filter((candidate) => candidate.skipped),
    dismissedCandidates: [],
    observedSuggestions: [],
    suggestionBranchedConnections: branchedConnections,
    matches: [],
  };
}

module.exports = {
  runInstagramSuggestionsDeepSearchFlow,
};
