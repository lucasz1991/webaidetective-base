#!/usr/bin/env node

const { runInstagramScraperModeWrapper } = require('./lib/instagram-scraper-mode-wrapper.cjs');

runInstagramScraperModeWrapper({
  defaultMode: 'analyze',
  allowedModes: ['analyze', 'profile', 'basic', 'grunddaten'],
});
