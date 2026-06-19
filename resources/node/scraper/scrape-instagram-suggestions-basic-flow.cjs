#!/usr/bin/env node

// Basic-Flow fuer Instagram-Vorschlaege: Erkennt Vorschlaege und prueft sie direkt
// (oeffentliche Profile: Followers/Following-Listen; private Profile: Kandidaten-Vorschlaege).
// Implementierung: nutzt die bestehende Kernfunktion aus scrape-instagram-suggestions.cjs mit deepSearch=true
// und respektiert dabei die konfigurierte History-/Recheck-Grenze.

const { runProfileSuggestionConnectionScan: runProfileSuggestionConnectionScanCore } = require('./scrape-instagram-suggestions.cjs');

async function runInstagramSuggestionsBasicFlow(
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
    // Der Basic-Lauf prueft nur Kandidaten, deren Recheck-Fenster abgelaufen ist.
    suggestionSkipPreviouslyChecked: originalRuntimeConfig.suggestionSkipPreviouslyChecked !== false,
    // Basic-Lauf soll nichts aggressiv aus der UI entfernen
    suggestionDismissChecked: false,
  };

  runtimeState.runtimeConfig = runtimeConfig;

  const coreResult = await runProfileSuggestionConnectionScanCore(
    deps,
    page,
    runtimeState,
    notes,
    targetUsername,
    profileUrl,
    {
      ...options,
      deepSearch: true, // erzwingt die Kandidaten-Pruefung in der Kernfunktion
    },
  );

  // Rueckgabe so anpassen, dass der Scan als "suggestions"-Lauf erscheint
  return {
    ...coreResult,
    scanType: 'suggestions',
    statusMessage: String(coreResult?.statusMessage || '')
      .replace(/DeepSearch/gi, 'Scan')
      .replace(/Vorschlaege\s*DeepSearch/gi, 'Vorschlaege-Scan'),
  };
}

module.exports = {
  runInstagramSuggestionsBasicFlow,
};
