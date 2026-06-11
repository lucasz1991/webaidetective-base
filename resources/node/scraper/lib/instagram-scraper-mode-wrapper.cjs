const path = require('path');
const { spawn } = require('child_process');

function normalizeMode(value) {
  return String(value || '').trim().toLowerCase();
}

function emitAndExit(payload, code = 0) {
  process.stdout.write(`${JSON.stringify(payload)}\n`, () => {
    process.exit(code);
  });
}

function runInstagramScraperModeWrapper(options = {}) {
  const defaultMode = normalizeMode(options.defaultMode || 'analyze') || 'analyze';
  const allowedModes = new Set(
    Array.isArray(options.allowedModes)
      ? options.allowedModes.map((mode) => normalizeMode(mode)).filter(Boolean)
      : [defaultMode],
  );
  const requestedMode = normalizeMode(process.argv[4] || defaultMode);
  const operationMode = allowedModes.has(requestedMode) ? requestedMode : defaultMode;
  const scraperScriptPath = path.resolve(__dirname, '../scrape-instagram.cjs');
  const childArgs = [
    scraperScriptPath,
    process.argv[2] || '',
    process.argv[3] || '',
    operationMode,
  ];

  const child = spawn(process.execPath, childArgs, {
    env: process.env,
    cwd: path.resolve(__dirname, '../../..'),
    stdio: ['inherit', 'pipe', 'pipe'],
  });

  let stdout = '';

  child.stdout.on('data', (chunk) => {
    stdout += chunk.toString();
  });

  child.stderr.on('data', (chunk) => {
    process.stderr.write(chunk);
  });

  child.on('error', (error) => {
    emitAndExit({
      ok: false,
      statusLevel: 'error',
      statusMessage: `Instagram-Scan fehlgeschlagen: ${error.message}`,
      error: error.message,
      operationMode,
    }, 1);
  });

  child.on('close', (code) => {
    const rawOutput = stdout.trim();
    const outputLine = rawOutput.split(/\r?\n/).filter(Boolean).pop() || '';

    if (outputLine === '') {
      emitAndExit({
        ok: false,
        statusLevel: 'error',
        statusMessage: 'Instagram-Scan fehlgeschlagen: Kein Output vom Basisskript.',
        operationMode,
      }, code || 1);
      return;
    }

    try {
      const payload = JSON.parse(outputLine);
      const transformedPayload = typeof options.transformPayload === 'function'
        ? options.transformPayload(payload, { operationMode })
        : payload;

      emitAndExit(transformedPayload, code || 0);
    } catch (error) {
      emitAndExit({
        ok: false,
        statusLevel: 'error',
        statusMessage: `Instagram-Scan fehlgeschlagen: Output konnte nicht verarbeitet werden (${error.message}).`,
        operationMode,
      }, code || 1);
    }
  });
}

module.exports = {
  runInstagramScraperModeWrapper,
};
