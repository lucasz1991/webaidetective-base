#!/usr/bin/env node

const { runInstagramScraperModeWrapper } = require('./lib/instagram-scraper-mode-wrapper.cjs');

runInstagramScraperModeWrapper({
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
