#!/usr/bin/env node

// Wrapper-Skript: Fuehrt den zentralen Instagram-Scraper im Modus "suggestions" aus.
// So koennen der Basis-Vorschlagslauf und der DeepSearch-Lauf strikt ueber getrennte
// Node-Skripte gestartet und spaeter getrennt optimiert werden.

const { runInstagramScraperEntrypoint } = require('./lib/instagram-scraper-entrypoint.cjs');

runInstagramScraperEntrypoint({
  defaultMode: 'suggestions',
  allowedModes: ['suggestions'],
});
