const fs = require('fs');
const os = require('os');
const path = require('path');

function ensureDirectory(directoryPath) {
  fs.mkdirSync(directoryPath, { recursive: true });
  return directoryPath;
}

function looksBrokenWindowsPath(directoryPath) {
  return /^undefined([\\/]|$)/i.test(directoryPath);
}

function isUsableDirectory(directoryPath) {
  if (!directoryPath || typeof directoryPath !== 'string') {
    return false;
  }

  if (looksBrokenWindowsPath(directoryPath)) {
    return false;
  }

  try {
    ensureDirectory(directoryPath);
    fs.accessSync(directoryPath, fs.constants.W_OK);
    return true;
  } catch (error) {
    return false;
  }
}

function resolveRuntimeTempDirectory(fallbackDirectory = path.resolve(__dirname, '../../../../storage/app/tmp')) {
  const explicitCandidates = [
    process.env.PUPPETEER_TMP_DIR,
    process.env.TEMP,
    process.env.TMP,
    process.env.TMPDIR,
  ].filter(Boolean);

  for (const candidate of explicitCandidates) {
    if (isUsableDirectory(candidate)) {
      return candidate;
    }
  }

  try {
    const systemTmp = os.tmpdir();

    if (isUsableDirectory(systemTmp)) {
      return systemTmp;
    }
  } catch (error) {
    // Ignore invalid system temp resolution and use the project-local fallback below.
  }

  return ensureDirectory(fallbackDirectory);
}

function resolveWindowsSystemRoot() {
  if (process.platform !== 'win32') {
    return null;
  }

  const candidates = [
    process.env.SystemRoot,
    process.env.windir,
    'C:\\Windows',
  ].filter(Boolean);

  for (const candidate of candidates) {
    if (looksBrokenWindowsPath(candidate)) {
      continue;
    }

    try {
      if (fs.existsSync(candidate)) {
        return candidate;
      }
    } catch (error) {
      // Ignore broken candidates and continue with the next one.
    }
  }

  return null;
}

function configureRuntimeEnvironment(options = {}) {
  const runtimeTempDirectory = resolveRuntimeTempDirectory(options.fallbackTempDirectory);
  const windowsSystemRoot = resolveWindowsSystemRoot();

  process.env.PUPPETEER_TMP_DIR = runtimeTempDirectory;
  process.env.TEMP = process.env.TEMP || runtimeTempDirectory;
  process.env.TMP = process.env.TMP || runtimeTempDirectory;
  process.env.TMPDIR = process.env.TMPDIR || runtimeTempDirectory;

  if (windowsSystemRoot) {
    process.env.SystemRoot = process.env.SystemRoot || windowsSystemRoot;
    process.env.windir = process.env.windir || windowsSystemRoot;
  }

  return {
    runtimeTempDirectory,
    windowsSystemRoot,
  };
}

module.exports = {
  configureRuntimeEnvironment,
  ensureDirectory,
  isUsableDirectory,
  looksBrokenWindowsPath,
  resolveRuntimeTempDirectory,
  resolveWindowsSystemRoot,
};
