#!/usr/bin/env node

const { runInstagramScraperEntrypoint } = require('./lib/instagram-scraper-entrypoint.cjs');

runInstagramScraperEntrypoint({
  defaultMode: 'suggestions',
  allowedModes: ['suggestions', 'profile-suggestions', 'suggestion-connections'],
});
