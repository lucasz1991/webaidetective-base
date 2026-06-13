#!/usr/bin/env node

// Basic-Flow fuer Instagram-Vorschlaege: Erkennt Vorschlaege und prueft sie direkt
// (oeffentliche Profile: Followers/Following-Listen; private Profile: Kandidaten-Vorschlaege).
// Implementierung: nutzt die bestehende Kernfunktion aus scrape-instagram-suggestions.cjs mit deepSearch=true,
// erzwingt dabei aber ohne History-Skip eine vollstaendige erneute Pruefung und setzt passende Scan-Metadaten.

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
    // Fuer den Basic-Lauf standardmaessig alles neu pruefen (kein History-Skip)
    suggestionSkipPreviouslyChecked: false,
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
