#!/usr/bin/env node

// Wrapper-Skript: Fuehrt den zentralen Instagram-Scraper im Modus "suggestion-connections" aus.
// Dies ist der DeepSearch-Einstiegspunkt, getrennt vom Basis-Vorschlagslauf.

const { runInstagramScraperEntrypoint } = require('./lib/instagram-scraper-entrypoint.cjs');

runInstagramScraperEntrypoint({
  defaultMode: 'suggestion-connections',
  allowedModes: ['suggestion-connections'],
});
