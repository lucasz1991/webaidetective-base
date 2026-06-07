#!/usr/bin/env node

const { runInstagramScraperEntrypoint } = require('./lib/instagram-scraper-entrypoint.cjs');

runInstagramScraperEntrypoint({
  defaultMode: 'followers',
  allowedModes: [
    'followers',
    'following',
    'followers-search',
    'search-followers',
    'following-search',
    'search-following',
    'public-connections-batch',
  ],
});
