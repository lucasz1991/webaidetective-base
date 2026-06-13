#!/usr/bin/env node

// DeepSearch-Flow fuer Instagram-Vorschlaege mit zweiter Ebene:
// 1) Fuehrt den vorhandenen Kandidaten-Check pro Vorschlag (oeffentlich: Listen; privat: Vorschlagsliste)
// 2) Vertieft fuer Treffer eine weitere Ebene: Sammelt deren Vorschlaege und prueft erneut auf die Zielverbindung

const { runProfileSuggestionConnectionScan: runProfileSuggestionConnectionScanCore } = require('./scrape-instagram-suggestions.cjs');

function hasFiniteNumericValue(value) {
  return Number.isFinite(Number(value));
}

function previewItems(items = [], limit = 40) {
  return items.slice(0, Math.max(0, limit));
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
  const originalRuntimeConfig = runtimeState.runtimeConfig || {};
  const runtimeConfig = {
    ...originalRuntimeConfig,
    // DeepSearch kann History nutzen, aber konfigurierbar
    suggestionSkipPreviouslyChecked: originalRuntimeConfig.suggestionSkipPreviouslyChecked !== false,
  };

  runtimeState.runtimeConfig = runtimeConfig;

  // 1) Erste Ebene: vorhandene DeepSearch-Kernlogik verwenden
  const firstLevel = await runProfileSuggestionConnectionScanCore(
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

  // Abbruch, wenn Rate-Limit/Stop
  if (!firstLevel?.ok || firstLevel?.gracefullyStopped) {
    return {
      ...firstLevel,
      scanType: 'suggestion-deepsearch',
    };
  }

  const {
    progressLog,
    captureLivePreviewScreenshot,
    normalizeInstagramUsername,
    collectProfileSuggestionItemsDeep,
    navigateWithSoftTimeout,
    scrollToProfileSuggestions,
    sleep,
  } = deps;

  const matched = Array.isArray(firstLevel.matches) ? firstLevel.matches : [];
  const level2MaxParents = Math.max(0, Number(runtimeConfig.suggestionSecondLevelMaxParents || 8));
  const level2MaxItemsPerParent = Math.max(1, Number(runtimeConfig.suggestionSecondLevelMaxItemsPerParent || 15));
  const level2MaxTotalChecks = Math.max(1, Number(runtimeConfig.suggestionSecondLevelMaxTotalChecks || 80));
  const parents = matched.slice(0, level2MaxParents);
  const branchedConnections = [];
  let totalChecked = 0;

  for (const parent of parents) {
    if (totalChecked >= level2MaxTotalChecks) {
      break;
    }

    try {
      // Zu Eltern-Profil navigieren
      await navigateWithSoftTimeout(page, parent.profileUrl, runtimeConfig);
      await sleep(1000);
      await scrollToProfileSuggestions(page, 5);

      const parentSuggestions = await collectProfileSuggestionItemsDeep(
        page,
        parent.username,
        level2MaxItemsPerParent,
        {
          includeSeeAll: true,
          forceSeeAll: true,
          continueUntilEnd: true,
          runtimeConfig,
          maxInlineRounds: runtimeConfig.suggestionCandidateInlineMaxRounds || 24,
          maxDialogRounds: runtimeConfig.suggestionCandidateDialogMaxRounds || 36,
        },
      );

      const secondLevelItems = Array.isArray(parentSuggestions?.items) ? parentSuggestions.items : [];
      const hits = secondLevelItems
        .filter((item) => normalizeInstagramUsername(item.username) === normalizeInstagramUsername(targetUsername));

      if (hits.length > 0) {
        branchedConnections.push({
          sourceUsername: parent.username,
          sourceProfileUrl: parent.profileUrl,
          targetUsername,
          targetProfileUrl: `https://www.instagram.com/${normalizeInstagramUsername(targetUsername)}/`,
          via: 'profile_suggestions_level2',
          suggestionPreview: previewItems(secondLevelItems, level2MaxItemsPerParent),
        });
      }

      totalChecked += secondLevelItems.length;

      progressLog('suggestions-deepsearch-level2-progress', {
        relationship: 'suggestions',
        parentUsername: parent.username,
        level2Checked: secondLevelItems.length,
        level2TotalChecked: totalChecked,
        level2MaxTotalChecks,
        level2Preview: previewItems(secondLevelItems, 10),
        message: hits.length > 0
          ? `Zweit-Ebene: @${parent.username} enthaelt @${targetUsername} in Vorschlaegen.`
          : `Zweit-Ebene: @${parent.username} ohne direkten Vorschlagstreffer auf @${targetUsername}.`,
        ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
      });

      if (totalChecked >= level2MaxTotalChecks) {
        break;
      }
    } catch (error) {
      // Level-2 Fehler nicht fatal fuer gesamten Lauf
      progressLog('suggestions-deepsearch-level2-error', {
        relationship: 'suggestions',
        parentUsername: parent.username,
        error: String(error?.message || error),
        message: `Zweit-Ebene bei @${parent.username} wegen Fehler uebersprungen.`,
        ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
      });
    }
  }

  const mergedMatches = Array.isArray(firstLevel.matches) ? firstLevel.matches.slice() : [];

  // Zweite Ebene-Treffer als zusaetzliche Matches fuer normale Vorschlagslisten aufnehmen
  for (const branch of branchedConnections) {
    const existing = mergedMatches.find((m) => m.username === branch.sourceUsername);

    if (existing) {
      // Kennzeichnen, dass diese Quelle ebenfalls eine Level-2-Verbindung liefert
      existing.sourceLists = Array.isArray(existing.sourceLists)
        ? Array.from(new Set([...existing.sourceLists, branch.via]))
        : [branch.via];
      continue;
    }

    mergedMatches.push({
      username: branch.sourceUsername,
      displayName: null,
      profileUrl: branch.sourceProfileUrl,
      profileImageUrl: null,
      profileVisibility: null,
      isPrivate: null,
      postsCount: null,
      followersCount: null,
      followingCount: null,
      hoverCard: null,
      sourceSuggestionUsername: branch.sourceUsername,
      sourcePublicUsername: branch.sourceUsername,
      targetFoundAsSuggestion: true,
      targetFoundInPublicLists: false,
      targetFoundInFollowers: false,
      targetFoundInFollowing: false,
      sourceLists: ['profile_suggestions_level2'],
      suggestionPreview: branch.suggestionPreview,
    });
  }

  const result = {
    ...firstLevel,
    scanType: 'suggestion-deepsearch',
    matches: mergedMatches,
    suggestionBranchedConnections: branchedConnections,
  };

  return result;
}

module.exports = {
  runInstagramSuggestionsDeepSearchFlow,
};
