/**
 * Browser binaries are provisioned explicitly:
 *
 * - CloakBrowser: `npx cloakbrowser install`
 * - Standard Chrome fallback: system Chrome or an explicitly installed
 *   Puppeteer Chrome configured through PUPPETEER_EXECUTABLE_PATH.
 *
 * Keeping downloads out of `npm install` avoids incomplete browser caches on
 * restricted hosting environments such as Plesk.
 */
module.exports = {
  skipDownload: true,
};
