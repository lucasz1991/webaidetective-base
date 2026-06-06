const path = require('path');

function normalizeMode(value) {
  return String(value || '').trim().toLowerCase();
}

function runInstagramScraperEntrypoint(options = {}) {
  const defaultMode = normalizeMode(options.defaultMode || 'analyze');
  const allowedModes = Array.isArray(options.allowedModes)
    ? options.allowedModes.map(normalizeMode).filter(Boolean)
    : [];
  const requestedMode = normalizeMode(process.argv[4] || defaultMode);
  const operationMode = allowedModes.length > 0 && !allowedModes.includes(requestedMode)
    ? defaultMode
    : requestedMode;
  const scraperScriptPath = path.resolve(__dirname, '../scrape-instagram.cjs');

  process.argv = [
    process.argv[0],
    scraperScriptPath,
    process.argv[2] || '',
    process.argv[3] || '',
    operationMode,
  ];

  require(scraperScriptPath);
}

module.exports = {
  runInstagramScraperEntrypoint,
};
