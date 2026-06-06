#!/usr/bin/env node

const { runInstagramScraperEntrypoint } = require('./lib/instagram-scraper-entrypoint.cjs');

runInstagramScraperEntrypoint({
  defaultMode: 'posts',
  allowedModes: ['posts', 'post-scan'],
});
