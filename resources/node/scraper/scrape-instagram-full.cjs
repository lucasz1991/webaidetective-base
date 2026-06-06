#!/usr/bin/env node

const { runInstagramScraperEntrypoint } = require('./lib/instagram-scraper-entrypoint.cjs');

runInstagramScraperEntrypoint({
  defaultMode: 'analyze',
  allowedModes: ['analyze', 'profile', 'basic', 'grunddaten'],
});
