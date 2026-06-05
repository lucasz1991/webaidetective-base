const fs = require('fs');
const path = require('path');

function buildArtifactPaths(username, options = {}) {
  const { baseDirectory = path.join(__dirname, '../../../../storage/app/public/screenshots/instagram') } = options;
  const artifactBasePath = path.join(baseDirectory, username);

  fs.mkdirSync(artifactBasePath, { recursive: true });

  const stamp = Date.now();

  return {
    htmlPath: path.join(artifactBasePath, `profile-page-${stamp}.html`),
    screenshotPath: path.join(artifactBasePath, `instagram-page-${stamp}.png`),
    livePreviewPath: path.join(artifactBasePath, `instagram-live-preview-${stamp}.png`),
  };
}

function buildRelatedScreenshotPath(baseScreenshotPath, suffix, options = {}) {
  const { ensureDirectory, normalizeText } = options;
  const safeSuffix = normalizeText(String(suffix || 'debug'))
    .replace(/[^a-z0-9._-]+/gi, '-')
    .replace(/^-+|-+$/g, '')
    .slice(0, 90) || 'debug';
  const directory = path.dirname(baseScreenshotPath);
  const extension = path.extname(baseScreenshotPath) || '.png';
  const baseName = path.basename(baseScreenshotPath, extension);

  ensureDirectory(directory);

  return path.join(directory, `${baseName}-${safeSuffix}${extension}`);
}

function buildCookiePath(runtimeConfig, options = {}) {
  const { ensureDirectory, normalizeText } = options;
  const configuredPath = normalizeText(String(runtimeConfig?.cookieFilePath || ''));

  if (configuredPath) {
    ensureDirectory(path.dirname(configuredPath));
    return configuredPath;
  }

  const cookieBasePath = path.join(
    __dirname,
    '../../../../storage/app/cookies',
  );
  ensureDirectory(cookieBasePath);
  return path.join(cookieBasePath, 'instagram-cookies.json');
}

function buildScraperProfileBlockPath(runtimeConfig = {}, options = {}) {
  const { ensureDirectory, isUsableDirectory, normalizeText } = options;
  const profileLabel = normalizeText(String(runtimeConfig.profileLabel || runtimeConfig.profile_label || 'instagram-default'))
    .replace(/[^a-z0-9._-]+/gi, '_')
    .slice(0, 80) || 'instagram-default';
  const profileDirectory = isUsableDirectory(runtimeConfig.browserProfilePath)
    ? runtimeConfig.browserProfilePath
    : path.join(__dirname, '../../../../storage/app/browser-profiles/instagram', profileLabel);

  ensureDirectory(profileDirectory);
  return path.join(profileDirectory, 'profile-blocked.json');
}

module.exports = {
  buildArtifactPaths,
  buildCookiePath,
  buildRelatedScreenshotPath,
  buildScraperProfileBlockPath,
};
