#!/usr/bin/env node

const { runInstagramScraperEntrypoint } = require('./lib/instagram-scraper-entrypoint.cjs');

runInstagramScraperEntrypoint({
  defaultMode: 'mini',
  allowedModes: ['mini', 'mini-scan', 'public', 'public-profile'],
});
