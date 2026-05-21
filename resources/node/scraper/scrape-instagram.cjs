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

function resolveRuntimeTempDirectory() {
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

  return ensureDirectory(path.resolve(__dirname, '../../../storage/app/tmp'));
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

const runtimeTempDirectory = resolveRuntimeTempDirectory();
const windowsSystemRoot = resolveWindowsSystemRoot();

process.env.PUPPETEER_TMP_DIR = runtimeTempDirectory;
process.env.TEMP = process.env.TEMP || runtimeTempDirectory;
process.env.TMP = process.env.TMP || runtimeTempDirectory;
process.env.TMPDIR = process.env.TMPDIR || runtimeTempDirectory;

if (windowsSystemRoot) {
  process.env.SystemRoot = process.env.SystemRoot || windowsSystemRoot;
  process.env.windir = process.env.windir || windowsSystemRoot;
}

const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');

puppeteer.use(StealthPlugin());

const rawUsername = process.argv[2] || '';
const username = rawUsername.replace(/^@/, '').trim();
const runtimeConfigPath = process.argv[3] || '';
const operationMode = normalizeText(process.argv[4] || 'analyze').toLowerCase();
const isLoginSessionMode = operationMode === 'login-session';
const isMiniScanMode = ['mini', 'mini-scan', 'public', 'public-profile'].includes(operationMode);
const isProfileOnlyMode = isMiniScanMode || ['profile', 'basic', 'grunddaten'].includes(operationMode);
const isFollowersOnlyMode = operationMode === 'followers';
const isFollowingOnlyMode = operationMode === 'following';
const isRelationshipOnlyMode = isFollowersOnlyMode || isFollowingOnlyMode;
const shouldCollectFollowers = operationMode === 'analyze' || isFollowersOnlyMode;
const shouldCollectFollowing = operationMode === 'analyze' || isFollowingOnlyMode;
const DEFAULT_MAX_RELATIONSHIP_LIST_ITEMS = 0;
const DEFAULT_MAX_RELATIONSHIP_LIST_SCROLL_ROUNDS = 100000;
const RELATIONSHIP_NO_PROGRESS_REOPEN_LIMIT = 2;
const RELATIONSHIP_SEARCH_PARTITION_QUERIES = [
  'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm',
  'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
  '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '.', '_',
];

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function resolveNavigationWaitMs(runtimeConfig, fallbackMs = 120000) {
  const configured = Number(runtimeConfig?.navigationTimeoutMs);

  if (Number.isFinite(configured) && configured > 0) {
    return configured;
  }

  return fallbackMs;
}

async function navigateWithSoftTimeout(page, url, runtimeConfig, options = {}) {
  const timeoutMs = Math.max(15000, Number(options.timeoutMs || resolveNavigationWaitMs(runtimeConfig)));
  const waitUntil = options.waitUntil || 'domcontentloaded';

  try {
    const response = await page.goto(url, {
      timeout: timeoutMs,
      waitUntil,
    });

    return {
      ok: true,
      status: response?.status?.() ?? null,
      finalUrl: page.url(),
      timeoutMs,
    };
  } catch (error) {
    const message = normalizeText(error.message || String(error));

    await page.evaluate(() => window.stop()).catch(() => {});
    recordRunDebug('navigation-soft-timeout', {
      url,
      finalUrl: page.url(),
      timeoutMs,
      waitUntil,
      error: message,
    });

    return {
      ok: false,
      error: message,
      finalUrl: page.url(),
      timeoutMs,
    };
  }
}

async function closeBrowserSoftly(browser, timeoutMs = 10000) {
  if (!browser) {
    return;
  }

  try {
    await Promise.race([
      browser.close(),
      sleep(timeoutMs).then(() => {
        throw new Error(`browser.close soft timeout after ${timeoutMs}ms`);
      }),
    ]);
  } catch (error) {
    recordRunDebug('browser-close-soft-failed', {
      error: normalizeText(error.message || String(error)),
    });

    try {
      browser.process()?.kill?.('SIGKILL');
    } catch (killError) {
      recordRunDebug('browser-kill-failed', {
        error: normalizeText(killError.message || String(killError)),
      });
    }
  }
}

function normalizeText(value = '') {
  return value.replace(/\s+/g, ' ').trim();
}

function debugLog(message, data) {
  const enabled = true;
  if (!enabled) {
    return;
  }

  const payload = typeof data !== 'undefined'
    ? `${message} ${JSON.stringify(data, null, 2)}`
    : message;

  process.stderr.write(`[SCRAPER DEBUG] ${payload}\n`);
}

function progressLog(stage, data = {}) {
  process.stderr.write(`[SCRAPER PROGRESS] ${JSON.stringify({
    at: new Date().toISOString(),
    mode: operationMode,
    stage,
    ...data,
  })}\n`);
}

function sanitizeDebugPayload(value, depth = 0) {
  if (depth > 5) {
    return '[depth-limit]';
  }

  if (value === null || typeof value === 'number' || typeof value === 'boolean') {
    return value;
  }

  if (typeof value === 'string') {
    return value.length > 2500 ? `${value.slice(0, 2500)}…` : value;
  }

  if (Array.isArray(value)) {
    return value.slice(0, 60).map((entry) => sanitizeDebugPayload(entry, depth + 1));
  }

  if (typeof value === 'object') {
    const sanitized = {};

    for (const [key, entry] of Object.entries(value)) {
      if (/^(password|loginPassword|login_password.*)$/i.test(key)) {
        sanitized[key] = '[redacted]';
        continue;
      }

      sanitized[key] = sanitizeDebugPayload(entry, depth + 1);
    }

    return sanitized;
  }

  return String(value);
}

function buildRunDebugLogPath(mode, currentUsername) {
  const debugDirectory = ensureDirectory(
    path.resolve(__dirname, '../../../storage/logs/instagram-scraper'),
  );
  const safeUsername = normalizeText(currentUsername || 'session')
    .replace(/[^a-z0-9._-]+/gi, '_')
    .slice(0, 80) || 'session';
  const stamp = new Date().toISOString().replace(/[:.]/g, '-');

  return path.join(debugDirectory, `${stamp}-${mode}-${safeUsername}.json`);
}

let activeRunDebug = null;

function initializeRunDebug(mode, currentUsername, runtimeConfig) {
  activeRunDebug = {
    filePath: buildRunDebugLogPath(mode, currentUsername),
    startedAt: new Date().toISOString(),
    mode,
    username: currentUsername || null,
    profileLabel: runtimeConfig?.profileLabel || null,
    persistentProfileEnabled: Boolean(runtimeConfig?.persistentProfileEnabled),
    browserProfilePath: runtimeConfig?.browserProfilePath || null,
    cookieFilePath: runtimeConfig?.cookieFilePath || null,
    headlessEnabled: Boolean(runtimeConfig?.headlessEnabled),
    autoLoginEnabled: Boolean(runtimeConfig?.autoLoginEnabled),
    loginUsername: runtimeConfig?.loginUsername || null,
    loginPasswordConfigured: Boolean(runtimeConfig?.loginPasswordConfigured),
    loginPasswordDecryptable: runtimeConfig?.loginPasswordDecryptable !== false,
    loginPasswordSource: runtimeConfig?.loginPasswordSource || null,
    events: [],
  };

  return activeRunDebug.filePath;
}

function recordRunDebug(step, data = {}) {
  if (!activeRunDebug) {
    return;
  }

  activeRunDebug.events.push({
    at: new Date().toISOString(),
    step,
    data: sanitizeDebugPayload(data),
  });
}

function flushRunDebug(finalPayload = {}) {
  if (!activeRunDebug?.filePath) {
    return null;
  }

  const logPayload = {
    ...activeRunDebug,
    finishedAt: new Date().toISOString(),
    final: sanitizeDebugPayload(finalPayload),
  };

  try {
    fs.writeFileSync(activeRunDebug.filePath, JSON.stringify(logPayload, null, 2), 'utf8');
  } catch (error) {
    debugLog('Fehler beim Schreiben des Debug-Logs:', error.message);
  }

  return activeRunDebug.filePath;
}

function summarizeCookieCollection(cookies = []) {
  return cookies.slice(0, 30).map((cookie) => ({
    name: cookie.name,
    domain: cookie.domain,
    path: cookie.path,
    expires: cookie.expires ?? null,
    httpOnly: Boolean(cookie.httpOnly),
    secure: Boolean(cookie.secure),
    sameSite: cookie.sameSite ?? null,
  }));
}

function dedupe(values) {
  return [...new Set(values.filter(Boolean))];
}

function normalizeInstagramUsername(value = '') {
  return normalizeText(String(value || ''))
    .replace(/^@/, '')
    .replace(/[^a-z0-9._]/gi, '')
    .toLowerCase();
}

function normalizeOptionalPositiveInteger(value, fallback = 0) {
  const normalizedValue = Number(value ?? fallback);

  if (!Number.isFinite(normalizedValue) || normalizedValue <= 0) {
    return 0;
  }

  return Math.floor(normalizedValue);
}

function normalizeRuntimeConfigShape(config = {}, defaults = {}) {
  const input = config && typeof config === 'object' ? config : {};
  const merged = {
    ...defaults,
    ...input,
  };

  return {
    ...merged,
    profileId: normalizeText(String(input.profileId || input.profile_id || merged.profileId || '')),
    profileLabel: normalizeText(String(input.profileLabel || input.profile_label || merged.profileLabel || 'instagram-default')) || 'instagram-default',
    persistentProfileEnabled: isLoginSessionMode && input?.persistentProfileEnabled !== false,
    headlessEnabled: input?.headlessEnabled !== false,
    autoLoginEnabled: input?.autoLoginEnabled === true,
    navigationTimeoutMs: Math.max(30000, Number(input?.navigationTimeoutMs || merged.navigationTimeoutMs || 120000)),
    postLoginWaitMs: Math.max(500, Number(input?.postLoginWaitMs || merged.postLoginWaitMs || 2500)),
    typingDelayMs: Math.max(0, Number(input?.typingDelayMs || merged.typingDelayMs || 0)),
    followerListMaxItems: normalizeOptionalPositiveInteger(input?.followerListMaxItems, merged.followerListMaxItems),
    followingListMaxItems: normalizeOptionalPositiveInteger(input?.followingListMaxItems, merged.followingListMaxItems),
    relationshipListMaxScrollRounds: Math.max(
      20,
      normalizeOptionalPositiveInteger(
        input?.relationshipListMaxScrollRounds,
        merged.relationshipListMaxScrollRounds,
      ) || merged.relationshipListMaxScrollRounds || DEFAULT_MAX_RELATIONSHIP_LIST_SCROLL_ROUNDS,
    ),
    expectedFollowerCount: normalizeOptionalPositiveInteger(input?.expectedFollowerCount, merged.expectedFollowerCount),
    expectedFollowingCount: normalizeOptionalPositiveInteger(input?.expectedFollowingCount, merged.expectedFollowingCount),
    accountPool: Array.isArray(input.accountPool) ? input.accountPool : [],
  };
}

function normalizeRuntimeAccountConfig(account, baseConfig) {
  const input = account && typeof account === 'object' ? account : {};

  return normalizeRuntimeConfigShape({
    ...baseConfig,
    autoLoginEnabled: false,
    loginUsername: '',
    loginPassword: '',
    loginPasswordConfigured: false,
    loginPasswordDecryptable: true,
    loginPasswordSource: null,
    ...input,
    accountPool: [],
  }, baseConfig);
}

function getScraperAccountKey(runtimeConfig = {}) {
  const candidates = [
    runtimeConfig.profileId,
    runtimeConfig.cookieFilePath,
    runtimeConfig.loginUsername,
    runtimeConfig.profileLabel,
  ];

  for (const candidate of candidates) {
    const normalized = normalizeText(String(candidate || ''));

    if (normalized) {
      return normalized;
    }
  }

  return '';
}

function normalizeRuntimeAccountPool(accountPool, runtimeConfig) {
  const rawAccounts = Array.isArray(accountPool) ? accountPool : [];
  const normalizedAccounts = [
    { ...runtimeConfig, accountPool: [] },
    ...rawAccounts.map((account) => normalizeRuntimeAccountConfig(account, runtimeConfig)),
  ];
  const seenAccountKeys = new Set();
  const pool = [];

  for (const account of normalizedAccounts) {
    const accountKey = getScraperAccountKey(account);

    if (!accountKey || seenAccountKeys.has(accountKey)) {
      continue;
    }

    seenAccountKeys.add(accountKey);
    pool.push({
      ...account,
      accountPool: [],
    });
  }

  return pool;
}

function loadRuntimeConfig(configPath) {
  const defaults = {
    profileId: '',
    profileLabel: 'instagram-default',
    persistentProfileEnabled: isLoginSessionMode,
    browserProfilePath: path.resolve(__dirname, '../../../storage/app/browser-profiles/instagram/default'),
    cookieFilePath: path.resolve(__dirname, '../../../storage/app/cookies/instagram-cookies.json'),
    headlessEnabled: true,
    autoLoginEnabled: false,
    loginUsername: '',
    loginPassword: '',
    loginPasswordConfigured: false,
    loginPasswordDecryptable: true,
    loginPasswordSource: null,
    navigationTimeoutMs: 120000,
    postLoginWaitMs: 2500,
    typingDelayMs: 35,
    followerListMaxItems: DEFAULT_MAX_RELATIONSHIP_LIST_ITEMS,
    followingListMaxItems: DEFAULT_MAX_RELATIONSHIP_LIST_ITEMS,
    relationshipListMaxScrollRounds: DEFAULT_MAX_RELATIONSHIP_LIST_SCROLL_ROUNDS,
    expectedFollowerCount: 0,
    expectedFollowingCount: 0,
    accountPool: [],
  };

  if (!configPath || !fs.existsSync(configPath)) {
    const runtimeConfig = normalizeRuntimeConfigShape({}, defaults);
    runtimeConfig.accountPool = normalizeRuntimeAccountPool([], runtimeConfig);

    return runtimeConfig;
  }

  try {
    const parsed = JSON.parse(fs.readFileSync(configPath, 'utf8'));
    const runtimeConfig = normalizeRuntimeConfigShape(parsed, defaults);
    runtimeConfig.accountPool = normalizeRuntimeAccountPool(parsed?.accountPool, runtimeConfig);

    return runtimeConfig;
  } catch (error) {
    debugLog('Fehler beim Laden der Runtime-Konfiguration:', error.message);
    const runtimeConfig = normalizeRuntimeConfigShape({}, defaults);
    runtimeConfig.accountPool = normalizeRuntimeAccountPool([], runtimeConfig);

    return runtimeConfig;
  }
}

function buildBrowserUserDataDir(runtimeConfig) {
  if (runtimeConfig?.persistentProfileEnabled && isUsableDirectory(runtimeConfig.browserProfilePath)) {
    return ensureDirectory(runtimeConfig.browserProfilePath);
  }

  return fs.mkdtempSync(
    path.join(runtimeTempDirectory, 'puppeteer-profile-'),
  );
}

function hasDisplayServer() {
  if (process.platform === 'win32' || process.platform === 'darwin') {
    return true;
  }

  return Boolean(process.env.DISPLAY || process.env.WAYLAND_DISPLAY);
}

function resolvePuppeteerHeadlessMode(runtimeConfig) {
  const requestedHeadful = isLoginSessionMode || runtimeConfig?.headlessEnabled === false;

  if (requestedHeadful && hasDisplayServer()) {
    return false;
  }

  return 'new';
}

function shouldCleanupBrowserProfile(runtimeConfig, browserUserDataDir) {
  if (!browserUserDataDir) {
    return false;
  }

  if (runtimeConfig?.persistentProfileEnabled && runtimeConfig.browserProfilePath === browserUserDataDir) {
    return false;
  }

  return true;
}

function escapeHtml(value = '') {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function buildArtifactPaths(username) {
  const artifactBasePath = path.join(
    __dirname,
    '../../../storage/app/public/screenshots/instagram',
    username,
  );

  fs.mkdirSync(artifactBasePath, { recursive: true });

  const stamp = Date.now();

  return {
    htmlPath: path.join(artifactBasePath, `profile-page-${stamp}.html`),
    screenshotPath: path.join(artifactBasePath, `instagram-page-${stamp}.png`),
  };
}

function buildCookiePath(runtimeConfig) {
  const configuredPath = normalizeText(String(runtimeConfig?.cookieFilePath || ''));

  if (configuredPath) {
    ensureDirectory(path.dirname(configuredPath));
    return configuredPath;
  }

  const cookieBasePath = path.join(
    __dirname,
    '../../../storage/app/cookies',
  );
  ensureDirectory(cookieBasePath);
  return path.join(cookieBasePath, 'instagram-cookies.json');
}

async function loadCookiesFromFile(page, cookieFilePath) {
  try {
    if (!fs.existsSync(cookieFilePath)) {
      debugLog('Cookie-Datei nicht gefunden:', cookieFilePath);
      return false;
    }

    const cookieData = fs.readFileSync(cookieFilePath, 'utf8');
    const cookies = JSON.parse(cookieData);

    if (!Array.isArray(cookies) || cookies.length === 0) {
      debugLog('Cookie-Datei enthält keine Cookies.');
      return false;
    }

    await page.setCookie(...cookies);
    debugLog('Instagram-Cookies geladen', cookies.map((cookie) => cookie.name));
    return true;
  } catch (error) {
    debugLog('Fehler beim Laden der Cookies:', error.message);
    return false;
  }
}

function extractCookieArray(rawCookiePayload) {
  if (Array.isArray(rawCookiePayload)) {
    return rawCookiePayload;
  }

  if (Array.isArray(rawCookiePayload?.cookies)) {
    return rawCookiePayload.cookies;
  }

  return [];
}

function normalizeSameSiteValue(rawSameSite) {
  const normalizedValue = normalizeText(String(rawSameSite || '')).toLowerCase();

  if (!normalizedValue || normalizedValue === 'unspecified') {
    return undefined;
  }

  if (normalizedValue === 'no_restriction' || normalizedValue === 'none') {
    return 'None';
  }

  if (normalizedValue === 'lax') {
    return 'Lax';
  }

  if (normalizedValue === 'strict') {
    return 'Strict';
  }

  return undefined;
}

function normalizeCookieRecord(rawCookie) {
  if (!rawCookie || typeof rawCookie !== 'object') {
    return null;
  }

  const name = normalizeText(rawCookie.name || '');
  const value = typeof rawCookie.value === 'string' ? rawCookie.value : String(rawCookie.value ?? '');
  const rawDomain = normalizeText(rawCookie.domain || rawCookie.host || '');
  const pathValue = normalizeText(rawCookie.path || '/');
  const secure = Boolean(rawCookie.secure ?? rawCookie.isSecure);
  const httpOnly = Boolean(rawCookie.httpOnly ?? rawCookie.isHttpOnly);
  const sameSite = normalizeSameSiteValue(rawCookie.sameSite);
  const expires = Number(rawCookie.expires ?? rawCookie.expirationDate ?? rawCookie.expiry ?? 0);

  if (!name || value === '' || !rawDomain) {
    return null;
  }

  const normalizedCookie = {
    name,
    value,
    domain: rawDomain.toLowerCase(),
    path: pathValue || '/',
    secure,
    httpOnly,
  };

  if (sameSite) {
    normalizedCookie.sameSite = sameSite;
  }

  if (Number.isFinite(expires) && expires > 0) {
    normalizedCookie.expires = expires;
  }

  return normalizedCookie;
}

function isRelevantCookieDomain(domain) {
  const normalizedDomain = normalizeText(String(domain || ''))
    .replace(/^\./, '')
    .toLowerCase();

  return normalizedDomain.endsWith('instagram.com') || normalizedDomain.endsWith('facebook.com');
}

async function loadCookiesFromFileWithDiagnostics(page, cookieFilePath) {
  const diagnostics = {
    loaded: false,
    providedCount: 0,
    acceptedCount: 0,
    providedNames: [],
    acceptedNames: [],
    sessionCookieProvided: false,
    sessionCookieAccepted: false,
    warnings: [],
  };

  try {
    if (!fs.existsSync(cookieFilePath)) {
      debugLog('Cookie-Datei nicht gefunden:', cookieFilePath);
      diagnostics.warnings.push('Cookie-Datei nicht gefunden.');
      return diagnostics;
    }

    const cookieData = fs.readFileSync(cookieFilePath, 'utf8');
    const rawCookiePayload = JSON.parse(cookieData);
    const cookies = extractCookieArray(rawCookiePayload)
      .map((cookie) => normalizeCookieRecord(cookie))
      .filter((cookie) => cookie && isRelevantCookieDomain(cookie.domain));

    if (!Array.isArray(cookies) || cookies.length === 0) {
      debugLog('Cookie-Datei enthaelt keine verwertbaren Cookies.');
      diagnostics.warnings.push('Cookie-Datei enthaelt keine verwertbaren Instagram-Cookies.');
      return diagnostics;
    }

    diagnostics.providedCount = cookies.length;
    diagnostics.providedNames = cookies.map((cookie) => cookie.name);
    diagnostics.sessionCookieProvided = diagnostics.providedNames.includes('sessionid');

    if (!diagnostics.sessionCookieProvided) {
      diagnostics.warnings.push(
        'Die Cookie-Datei enthaelt keine sessionid; daraus kann kein angemeldeter Instagram-Zustand wiederhergestellt werden.',
      );
    }

    await page.browserContext().setCookie(...cookies);

    const acceptedCookies = await page.cookies('https://www.instagram.com/');
    diagnostics.acceptedNames = acceptedCookies.map((cookie) => cookie.name);
    diagnostics.acceptedCount = acceptedCookies.length;
    diagnostics.sessionCookieAccepted = diagnostics.acceptedNames.includes('sessionid');
    diagnostics.loaded = true;

    debugLog('Instagram-Cookies geladen', diagnostics.providedNames);
    recordRunDebug('cookies-loaded-from-file', {
      cookieFilePath,
      providedNames: diagnostics.providedNames,
      acceptedNames: diagnostics.acceptedNames,
      sessionCookieProvided: diagnostics.sessionCookieProvided,
      sessionCookieAccepted: diagnostics.sessionCookieAccepted,
    });

    if (diagnostics.sessionCookieProvided && !diagnostics.sessionCookieAccepted) {
      diagnostics.warnings.push('Die importierte sessionid wurde vom Browser-Context nicht akzeptiert.');
    }

    return diagnostics;
  } catch (error) {
    debugLog('Fehler beim Laden der Cookies:', error.message);
    diagnostics.warnings.push(`Fehler beim Laden der Cookies: ${normalizeText(error.message)}`);
    return diagnostics;
  }
}

async function primeInstagramSession(page, cookieFilePath, runtimeConfig = {}) {
  const diagnostics = {
    ...await loadCookiesFromFileWithDiagnostics(page, cookieFilePath),
    sessionCookieRetained: false,
    loginViewDetectedAfterReload: false,
    preflightUrl: 'https://www.instagram.com/',
    postReloadUrl: null,
    postReloadTitle: null,
    postReloadBodyPreview: '',
    cookieNamesAfterReload: [],
  };

  if (!diagnostics.loaded) {
    return diagnostics;
  }

  const navigation = await navigateWithSoftTimeout(
    page,
    'https://www.instagram.com/',
    runtimeConfig,
    isLoginSessionMode
      ? { timeoutMs: Math.min(resolveNavigationWaitMs(runtimeConfig, 120000), 20000) }
      : {},
  );

  if (!navigation.ok) {
    diagnostics.warnings.push(`Instagram-Session-Vorabtest konnte nicht stabil geladen werden: ${navigation.error}`);
  }

  await sleep(1000);

  diagnostics.postReloadUrl = page.url();
  diagnostics.postReloadTitle = await page.title().catch(() => null);
  diagnostics.postReloadBodyPreview = normalizeText(
    await page.evaluate(() => document.body?.innerText || '').catch(() => ''),
  ).slice(0, 600);

  const cookiesAfterReload = await page.cookies('https://www.instagram.com/');
  diagnostics.cookieNamesAfterReload = cookiesAfterReload.map((cookie) => cookie.name);
  diagnostics.sessionCookieRetained = diagnostics.cookieNamesAfterReload.includes('sessionid');
  diagnostics.loginViewDetectedAfterReload = /log into instagram|melde dich an|password|passwort|forgot password|log in with facebook/i.test(
    diagnostics.postReloadBodyPreview,
  );
  recordRunDebug('session-preflight', diagnostics);

  if (diagnostics.sessionCookieAccepted && !diagnostics.sessionCookieRetained) {
    diagnostics.warnings.push(
      'Instagram hat die importierte sessionid beim ersten Request wieder entfernt; die Session ist sehr wahrscheinlich serverseitig ungueltig oder an Browser, IP oder Device gebunden.',
    );
  }

  if (diagnostics.sessionCookieRetained && diagnostics.loginViewDetectedAfterReload) {
    diagnostics.warnings.push(
      'Die sessionid blieb zwar gesetzt, Instagram hat aber trotzdem keinen angemeldeten Zustand ausgeliefert.',
    );
  }

  return diagnostics;
}

async function saveCookiesToFile(page, cookieFilePath) {
  try {
    const cookies = await page.cookies();

    // Nur Instagram-relevante Cookies speichern
    const filteredCookies = cookies.filter((cookie) =>
      cookie.domain && (cookie.domain.includes('instagram.com') || cookie.domain.includes('facebook.com')),
    );

    const cookieNames = filteredCookies.map((cookie) => cookie.name);
    const sessionCookieSaved = cookieNames.includes('sessionid');

    if (filteredCookies.length > 0) {
      const tempPath = `${cookieFilePath}.tmp`;
      fs.writeFileSync(tempPath, JSON.stringify(filteredCookies, null, 2), 'utf8');
      fs.renameSync(tempPath, cookieFilePath);
      recordRunDebug('cookies-saved-to-file', {
        cookieFilePath,
        count: filteredCookies.length,
        cookieNames,
        sessionCookieSaved,
      });

      return {
        saved: true,
        count: filteredCookies.length,
        cookieNames,
        sessionCookieSaved,
      };
    }

    recordRunDebug('cookies-not-saved', {
      cookieFilePath,
      reason: 'no-relevant-cookies',
    });

    return {
      saved: false,
      count: 0,
      cookieNames: [],
      sessionCookieSaved: false,
    };
  } catch (error) {
    recordRunDebug('cookies-save-error', {
      cookieFilePath,
      error: normalizeText(error.message),
    });

    return {
      saved: false,
      count: 0,
      cookieNames: [],
      sessionCookieSaved: false,
      error: normalizeText(error.message),
    };
  }
}

function shouldSaveCookies(finalUrl, profile) {
  if ((finalUrl || '').includes('/accounts/login')) {
    return false;
  }

  if (profile?.requiresLogin) {
    return false;
  }

  return true;
}

function cleanupDirectory(directoryPath) {
  try {
    fs.rmSync(directoryPath, { recursive: true, force: true });
  } catch (error) {
    // Temporary browser profiles can stay locked briefly on Windows; ignore cleanup failures.
  }
}

function deriveScrapeOutcome({ title, finalUrl, profile, warnings, cookieDiagnostics, loginDiagnostics }) {
  const normalizedTitle = normalizeText(title || '').toLowerCase();
  const normalizedBodyPreview = normalizeText(profile?.bodyTextPreview || '').toLowerCase();
  const normalizedDescription = normalizeText(profile?.description || '').toLowerCase();
  const normalizedWarnings = warnings.map((warning) => normalizeText(warning).toLowerCase()).join(' ');
  const rejectedImportedSession = Boolean(
    cookieDiagnostics?.sessionCookieProvided &&
    cookieDiagnostics?.sessionCookieAccepted &&
    cookieDiagnostics?.sessionCookieRetained === false
  );

  const hasFailureScreen = /seite konnte nicht geladen werden|something went wrong|couldn'?t load|fehler konnte die seite nicht laden/.test(
    `${normalizedTitle} ${normalizedBodyPreview}`,
  );

  const hasAuthOrRateLimitBlock = /status of 401|status of 403|http 401|http 403|require_login|please wait a few minutes|bitte warte einige minuten|instagram-rate-limit/.test(
    normalizedWarnings,
  );

  const redirectedToLogin = (finalUrl || '').includes('/accounts/login');
  const autoLoginFailed = Boolean(loginDiagnostics?.attempted && !loginDiagnostics?.success);
  const hasUsefulMetadata = Boolean(profile?.ogTitle || profile?.description || profile?.ogImage);
  const hasRenderedProfileContent =
    Boolean(profile?.usernameSeen) &&
    (profile?.imageCount ?? 0) > 0 &&
    !hasFailureScreen &&
    !redirectedToLogin &&
    !profile?.requiresLogin;

  if (rejectedImportedSession && (redirectedToLogin || profile?.requiresLogin)) {
    return {
      ok: false,
      statusLevel: hasUsefulMetadata ? 'partial' : 'error',
      statusMessage: hasUsefulMetadata
        ? 'Instagram verwirft die importierte Login-Session; es wurden nur Metadaten extrahiert.'
        : 'Instagram verwirft die importierte Login-Session; es konnten keine brauchbaren Daten geladen werden.',
    };
  }

  if (autoLoginFailed && (redirectedToLogin || profile?.requiresLogin)) {
    return {
      ok: false,
      statusLevel: hasUsefulMetadata ? 'partial' : 'error',
      statusMessage: hasUsefulMetadata
        ? 'Instagram-Login wurde versucht, aber Instagram hat keinen stabilen angemeldeten Zustand geliefert; es wurden nur Metadaten extrahiert.'
        : 'Instagram-Login wurde versucht, aber Instagram hat keinen stabilen angemeldeten Zustand geliefert.',
    };
  }

  if (redirectedToLogin || profile?.requiresLogin) {
    return {
      ok: false,
      statusLevel: hasUsefulMetadata ? 'partial' : 'error',
      statusMessage: hasUsefulMetadata
        ? 'Instagram verlangt fuer dieses Profil einen Login; es wurden nur Metadaten extrahiert.'
        : 'Instagram verlangt fuer dieses Profil einen Login; es konnten keine brauchbaren Daten geladen werden.',
    };
  }

  if (hasFailureScreen || hasAuthOrRateLimitBlock) {
    return {
      ok: false,
      statusLevel: hasUsefulMetadata ? 'partial' : 'error',
      statusMessage: hasUsefulMetadata
        ? 'Instagram blockiert oder stoert die Profilansicht; es wurden nur Metadaten und ein Snapshot erzeugt.'
        : 'Instagram blockiert den Abruf; es konnten keine brauchbaren Profildaten geladen werden.',
    };
  }

  if (hasRenderedProfileContent) {
    return {
      ok: true,
      statusLevel: 'success',
      statusMessage: 'Instagram-Profil erfolgreich geladen.',
    };
  }

  if (hasUsefulMetadata || normalizedDescription.includes(username.toLowerCase())) {
    return {
      ok: false,
      statusLevel: 'partial',
      statusMessage: 'Instagram-Profil nur teilweise geladen; es sind vor allem Metadaten verfuegbar.',
    };
  }

  return {
    ok: false,
    statusLevel: 'error',
    statusMessage: 'Instagram-Scrape fehlgeschlagen.',
  };
}

async function collectProfileInfo(page, username) {
  const bodyText = normalizeText(
    await page.evaluate(() => document.body?.innerText || '').catch(() => ''),
  );
  const counts = await page.evaluate(() => {
    const normalizeElementText = (value = '') => String(value).replace(/\s+/g, ' ').trim();
    const normalizeCountValue = (rawValue = '') => {
      const value = normalizeElementText(rawValue).toLowerCase();

      if (!value) {
        return null;
      }

      let multiplier = 1;

      if (/\bmio\b|\bm\b/.test(value)) {
        multiplier = 1000000;
      } else if (/\bk\b|\btsd\b/.test(value)) {
        multiplier = 1000;
      }

      const numericPart = value.replace(/[^\d.,]/g, '');

      if (!numericPart) {
        return null;
      }

      if (multiplier === 1) {
        const digits = numericPart.replace(/[^\d]/g, '');

        return digits ? Number.parseInt(digits, 10) : null;
      }

      const decimalValue = Number.parseFloat(numericPart.replace(',', '.'));

      return Number.isFinite(decimalValue) ? Math.round(decimalValue * multiplier) : null;
    };
    const patterns = {
      posts: [
        /([\d., ]+(?:\s*(?:k|m|mio|tsd))?)\s*(?:beitr(?:ag|aege|äge)|posts?)/iu,
      ],
      followers: [
        /([\d., ]+(?:\s*(?:k|m|mio|tsd))?)\s*(?:follower|followers)/iu,
      ],
      following: [
        /([\d., ]+(?:\s*(?:k|m|mio|tsd))?)\s*(?:gefolgt|following)/iu,
      ],
    };
    const values = {
      posts: null,
      followers: null,
      following: null,
    };
    const sources = {
      posts: null,
      followers: null,
      following: null,
    };
    const textSources = [
      ['body_text_preview', document.body?.innerText || ''],
      ...Array.from(document.querySelectorAll('header, main, section, ul, li, a[href*="/followers"], a[href*="/following"]'))
        .flatMap((element) => ([
          ['profile_dom', element.innerText || element.textContent || ''],
          ['profile_dom', element.getAttribute('aria-label') || ''],
          ['profile_dom', element.getAttribute('title') || ''],
        ])),
    ];

    for (const [source, text] of textSources) {
      const normalizedText = normalizeElementText(text);

      if (!normalizedText) {
        continue;
      }

      for (const [metric, metricPatterns] of Object.entries(patterns)) {
        if (values[metric] !== null) {
          continue;
        }

        for (const pattern of metricPatterns) {
          const match = normalizedText.match(pattern);

          if (!match) {
            continue;
          }

          const count = normalizeCountValue(match[1] || '');

          if (count !== null) {
            values[metric] = count;
            sources[metric] = source;
            break;
          }
        }
      }
    }

    return {
      ...values,
      sources,
    };
  }).catch(() => ({
    posts: null,
    followers: null,
    following: null,
    sources: {
      posts: null,
      followers: null,
      following: null,
    },
  }));

  const description = await page
    .$eval('meta[name="description"]', (element) => element.content)
    .catch(() => null);

  const ogImage = await page
    .$eval('meta[property="og:image"]', (element) => element.content)
    .catch(() => null);

  const ogTitle = await page
    .$eval('meta[property="og:title"]', (element) => element.content)
    .catch(() => null);

  const imageCount = await page
    .evaluate(
      () => Array.from(document.images).filter((image) => image.naturalWidth > 50).length,
    )
    .catch(() => 0);

  return {
    bodyTextPreview: bodyText.slice(0, 1200),
    counts,
    description,
    imageCount,
    isPrivate: /dieses profil ist privat|this account is private/i.test(bodyText + ' ' + (description || '')),
    ogImage,
    ogTitle,
    requiresLogin: /melde dich an|anmelden|log in|sign up/i.test(bodyText),
    usernameSeen:
      bodyText.toLowerCase().includes(username.toLowerCase()) ||
      (ogTitle || '').toLowerCase().includes(username.toLowerCase()),
  };
}

async function closeInstagramDialog(page) {
  await page.keyboard.press('Escape').catch(() => {});
  await sleep(400);
}

async function collectFollowerEntriesFromDialog(page) {
  return page.evaluate(() => {
    const normalizeElementText = (value = '') => String(value).replace(/\s+/g, ' ').trim();
    const rateLimitPattern = /(?:versuche es sp(?:a|\u00e4)ter noch einmal|wir schr(?:a|\u00e4)nken die h(?:a|\u00e4)ufigkeit|try again later|we restrict certain activity|we limit how often)/i;
    const rateLimitDialog = Array.from(document.querySelectorAll('div[role="dialog"]'))
      .find((dialogElement) => rateLimitPattern.test(
        normalizeElementText(dialogElement.innerText || dialogElement.textContent || ''),
      )) || null;
    const rateLimitContainer = rateLimitDialog || (
      rateLimitPattern.test(normalizeElementText(document.body?.innerText || document.body?.textContent || ''))
        ? document.body
        : null
    );

    if (rateLimitContainer) {
      return {
        entries: [],
        suggestionsVisible: false,
        suggestionHeadingText: null,
        rateLimited: true,
        rateLimitText: normalizeElementText(rateLimitContainer.innerText || rateLimitContainer.textContent || '').slice(0, 500),
      };
    }

    const reservedPaths = new Set([
      'accounts',
      'direct',
      'explore',
      'p',
      'reel',
      'reels',
      'stories',
      'tv',
    ]);
    const dialog = document.querySelector('div[role="dialog"]') || document.body;
    const suggestionHeadingPattern = /^(f[uü]r dich vorgeschlagen|vorschl[aä]ge f[uü]r dich|suggested for you|suggestions for you|suggested)$/i;
    const getOwnText = (element) => normalizeElementText(
      Array.from(element.childNodes || [])
        .filter((node) => node.nodeType === Node.TEXT_NODE)
        .map((node) => node.textContent || '')
        .join(' '),
    );
    const isSuggestionHeading = (element) => {
      const ownText = getOwnText(element);
      const fullText = normalizeElementText(element.innerText || element.textContent || '');
      const text = ownText || fullText;

      return text !== '' && text.length <= 80 && suggestionHeadingPattern.test(text);
    };
    const suggestionHeading = Array.from(dialog.querySelectorAll('span, div, h1, h2, h3, h4, p'))
      .find(isSuggestionHeading) || null;
    const isAfterSuggestionHeading = (element) => {
      if (!suggestionHeading) {
        return false;
      }

      return Boolean(suggestionHeading.compareDocumentPosition(element) & Node.DOCUMENT_POSITION_FOLLOWING);
    };
    const anchors = Array.from(dialog.querySelectorAll('a[href]'))
      .filter((anchor) => !isAfterSuggestionHeading(anchor));

    const entries = anchors
      .map((anchor) => {
        let pathname = '';

        try {
          pathname = new URL(anchor.getAttribute('href'), window.location.origin).pathname;
        } catch (error) {
          return null;
        }

        const parts = pathname.split('/').filter(Boolean);

        if (parts.length !== 1 || reservedPaths.has(parts[0])) {
          return null;
        }

        const username = parts[0].trim();

        if (!/^[a-z0-9._]+$/i.test(username)) {
          return null;
        }

        const textLines = (anchor.innerText || '')
          .split('\n')
          .map((line) => line.trim())
          .filter(Boolean);

        return {
          username,
          displayName: textLines.find((line) => line.toLowerCase() !== username.toLowerCase()) || null,
          profileUrl: `https://www.instagram.com/${username}/`,
        };
      })
      .filter(Boolean);

    return {
      entries,
      suggestionsVisible: Boolean(suggestionHeading),
      suggestionHeadingText: suggestionHeading
        ? normalizeElementText(suggestionHeading.innerText || suggestionHeading.textContent || '')
        : null,
      rateLimited: false,
      rateLimitText: null,
    };
  }).catch(() => ({
    entries: [],
    suggestionsVisible: false,
    suggestionHeadingText: null,
    rateLimited: false,
    rateLimitText: null,
  }));
}

async function scrollFollowerDialog(page) {
  return page.evaluate(() => {
    const dialog = document.querySelector('div[role="dialog"]');

    if (!dialog) {
      const before = window.scrollY;
      window.scrollBy(0, Math.max(480, window.innerHeight * 0.85));
      const after = window.scrollY;
      const scrollHeight = document.documentElement.scrollHeight;

      return {
        scrolled: after > before,
        scrollTop: after,
        scrollHeight,
        clientHeight: window.innerHeight,
        atBottom: after + window.innerHeight >= scrollHeight - 8,
        profileLinkCount: Array.from(document.querySelectorAll('a[href]')).length,
      };
    }

    const candidates = Array.from(dialog.querySelectorAll('div, ul, section'))
      .filter((element) => element.scrollHeight > element.clientHeight + 40);
    const scrollTarget = candidates
      .sort((left, right) => (right.scrollHeight - right.clientHeight) - (left.scrollHeight - left.clientHeight))[0];

    if (!scrollTarget) {
      return {
        scrolled: false,
        scrollTop: 0,
        scrollHeight: 0,
        clientHeight: 0,
        atBottom: true,
        profileLinkCount: Array.from(dialog.querySelectorAll('a[href]')).length,
      };
    }

    const before = scrollTarget.scrollTop;
    const step = Math.max(420, scrollTarget.clientHeight * 0.85);
    scrollTarget.scrollTop = Math.min(
      scrollTarget.scrollTop + step,
      scrollTarget.scrollHeight,
    );
    const after = scrollTarget.scrollTop;

    return {
      scrolled: after > before,
      scrollTop: after,
      scrollHeight: scrollTarget.scrollHeight,
      clientHeight: scrollTarget.clientHeight,
      atBottom: after + scrollTarget.clientHeight >= scrollTarget.scrollHeight - 8,
      profileLinkCount: Array.from(dialog.querySelectorAll('a[href]')).length,
    };
  }).catch(() => ({
    scrolled: false,
    scrollTop: 0,
    scrollHeight: 0,
    clientHeight: 0,
    atBottom: false,
    profileLinkCount: 0,
  }));
}

async function relationshipDialogSearchInputAvailable(page) {
  return page.evaluate(() => {
    const dialog = document.querySelector('div[role="dialog"]') || document.body;
    const inputs = Array.from(dialog.querySelectorAll('input'));
    const visibleInputs = inputs.filter((input) => {
      const rect = input.getBoundingClientRect();
      const style = window.getComputedStyle(input);

      return rect.width > 0
        && rect.height > 0
        && style.display !== 'none'
        && style.visibility !== 'hidden'
        && !input.disabled
        && !input.readOnly;
    });
    const preferredInput = visibleInputs.find((input) => {
      const haystack = [
        input.getAttribute('placeholder') || '',
        input.getAttribute('aria-label') || '',
        input.getAttribute('name') || '',
        input.getAttribute('type') || '',
      ].join(' ');

      return /suchen|suche|search/i.test(haystack);
    }) || visibleInputs.find((input) => {
      const type = (input.getAttribute('type') || 'text').toLowerCase();

      return type === 'text' || type === 'search';
    });

    return Boolean(preferredInput);
  }).catch(() => false);
}

async function setRelationshipDialogSearchQuery(page, query) {
  const applied = await page.evaluate((value) => {
    const dialog = document.querySelector('div[role="dialog"]') || document.body;
    const inputs = Array.from(dialog.querySelectorAll('input'));
    const visibleInputs = inputs.filter((input) => {
      const rect = input.getBoundingClientRect();
      const style = window.getComputedStyle(input);

      return rect.width > 0
        && rect.height > 0
        && style.display !== 'none'
        && style.visibility !== 'hidden'
        && !input.disabled
        && !input.readOnly;
    });
    const input = visibleInputs.find((candidate) => {
      const haystack = [
        candidate.getAttribute('placeholder') || '',
        candidate.getAttribute('aria-label') || '',
        candidate.getAttribute('name') || '',
        candidate.getAttribute('type') || '',
      ].join(' ');

      return /suchen|suche|search/i.test(haystack);
    }) || visibleInputs.find((candidate) => {
      const type = (candidate.getAttribute('type') || 'text').toLowerCase();

      return type === 'text' || type === 'search';
    });

    if (!input) {
      return false;
    }

    const nativeValueSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value')?.set;

    input.focus();

    if (nativeValueSetter) {
      nativeValueSetter.call(input, '');
    } else {
      input.value = '';
    }

    input.dispatchEvent(new InputEvent('input', {
      bubbles: true,
      inputType: 'deleteContentBackward',
    }));

    if (nativeValueSetter) {
      nativeValueSetter.call(input, value);
    } else {
      input.value = value;
    }

    input.dispatchEvent(new InputEvent('input', {
      bubbles: true,
      data: value,
      inputType: 'insertText',
    }));
    input.dispatchEvent(new Event('change', { bubbles: true }));

    const scrollTargets = Array.from(dialog.querySelectorAll('div, ul, section'))
      .filter((element) => element.scrollHeight > element.clientHeight + 40);

    for (const target of scrollTargets) {
      target.scrollTop = 0;
    }

    return true;
  }, String(query || '')).catch(() => false);

  if (applied) {
    await sleep(query ? 1250 : 600);
  }

  return applied;
}

async function openInstagramRelationshipDialog(page, username, relationship, runtimeConfig = {}) {
  const normalizedUsername = normalizeInstagramUsername(username);
  const normalizedRelationship = relationship === 'following' ? 'following' : 'followers';
  const clicked = await page.evaluate((targetUsername, targetRelationship) => {
    const anchors = Array.from(document.querySelectorAll('a[href]'));
    const targetPath = `/${targetUsername}/${targetRelationship}/`;
    const labelPatternText = targetRelationship === 'following'
      ? 'following|gefolgt|abonniert'
      : 'followers|follower';
    const labelPattern = new RegExp(labelPatternText, 'i');
    const target = anchors.find((anchor) => {
      let pathname = '';

      try {
        pathname = new URL(anchor.getAttribute('href'), window.location.origin).pathname;
      } catch (error) {
        return false;
      }

      return pathname.toLowerCase() === targetPath;
    }) || anchors.find((anchor) => labelPattern.test(anchor.innerText || ''));

    if (!target) {
      return false;
    }

    target.click();
    return true;
  }, normalizedUsername, normalizedRelationship).catch(() => false);

  if (clicked) {
    await sleep(1800);
  }

  const hasDialog = await page.evaluate(() => Boolean(document.querySelector('div[role="dialog"]'))).catch(() => false);

  if (hasDialog) {
    return true;
  }

  await navigateWithSoftTimeout(
    page,
    `https://www.instagram.com/${normalizedUsername}/${normalizedRelationship}/`,
    runtimeConfig,
  );
  await sleep(2200);

  return page.evaluate(() => Boolean(document.querySelector('div[role="dialog"]'))).catch(() => false);
}

async function openFollowersDialog(page, username, runtimeConfig = {}) {
  return openInstagramRelationshipDialog(page, username, 'followers', runtimeConfig);
}

async function openFollowingDialog(page, username, runtimeConfig = {}) {
  return openInstagramRelationshipDialog(page, username, 'following', runtimeConfig);
}

function addRelationshipEntriesToMap(entries, usersByUsername, targetUsername) {
  let added = 0;

  for (const entry of entries) {
    const relatedUsername = normalizeInstagramUsername(entry?.username);

    if (!relatedUsername || relatedUsername === targetUsername || usersByUsername.has(relatedUsername)) {
      continue;
    }

    usersByUsername.set(relatedUsername, {
      username: relatedUsername,
      displayName: entry.displayName,
      profileUrl: entry.profileUrl,
    });
    added++;
  }

  return added;
}

function getRelationshipSearchPartitionQueries(runtimeConfig = {}) {
  const configuredQueries = Array.isArray(runtimeConfig.relationshipSearchPartitionQueries)
    ? runtimeConfig.relationshipSearchPartitionQueries
    : [];
  const normalizedQueries = configuredQueries
    .map((query) => normalizeText(String(query || '')).toLowerCase())
    .filter((query) => query !== '');

  return normalizedQueries.length > 0
    ? [...new Set(normalizedQueries)]
    : RELATIONSHIP_SEARCH_PARTITION_QUERIES;
}

async function collectRelationshipSearchPartitions(page, username, relationship, options, usersByUsername) {
  const normalizedRelationship = relationship === 'following' ? 'following' : 'followers';
  const runtimeConfig = options.runtimeConfig || {};
  const maxItems = normalizeOptionalPositiveInteger(options.maxItems, DEFAULT_MAX_RELATIONSHIP_LIST_ITEMS);
  const expectedCount = normalizeOptionalPositiveInteger(options.expectedCount, 0);
  const maxScrollRounds = Math.max(
    20,
    normalizeOptionalPositiveInteger(options.searchMaxScrollRounds, options.maxScrollRounds)
      || options.maxScrollRounds
      || DEFAULT_MAX_RELATIONSHIP_LIST_SCROLL_ROUNDS,
  );
  const hasItemLimit = maxItems > 0;
  const targetUsername = normalizeInstagramUsername(username);
  const queries = getRelationshipSearchPartitionQueries(runtimeConfig);
  const queriesRun = [];
  let searchRounds = 0;
  let openAttempts = 0;
  let addedCount = 0;
  let stopReason = null;

  const targetReached = () => {
    if (hasItemLimit && usersByUsername.size >= maxItems) {
      return true;
    }

    return expectedCount > 0 && usersByUsername.size >= expectedCount;
  };

  progressLog('relationship-search-opening', {
    relationship: normalizedRelationship,
    loaded: usersByUsername.size,
    expectedCount,
    maxItems,
    maxScrollRounds,
    queryCount: queries.length,
  });

  openAttempts++;
  let opened = normalizedRelationship === 'following'
    ? await openFollowingDialog(page, username, runtimeConfig)
    : await openFollowersDialog(page, username, runtimeConfig);

  if (!opened) {
    return {
      attempted: true,
      inputAvailable: false,
      queries: queriesRun,
      rounds: searchRounds,
      addedCount,
      openAttempts,
      stopReason: 'search-dialog-not-found',
      rateLimited: false,
      rateLimitText: null,
    };
  }

  let inputAvailable = await relationshipDialogSearchInputAvailable(page);

  if (!inputAvailable) {
    await closeInstagramDialog(page);

    return {
      attempted: true,
      inputAvailable: false,
      queries: queriesRun,
      rounds: searchRounds,
      addedCount,
      openAttempts,
      stopReason: 'search-input-not-found',
      rateLimited: false,
      rateLimitText: null,
    };
  }

  for (const query of queries) {
    if (targetReached() || searchRounds >= maxScrollRounds) {
      break;
    }

    let queryApplied = await setRelationshipDialogSearchQuery(page, query);

    if (!queryApplied) {
      await closeInstagramDialog(page);
      openAttempts++;
      opened = normalizedRelationship === 'following'
        ? await openFollowingDialog(page, username, runtimeConfig)
        : await openFollowersDialog(page, username, runtimeConfig);
      inputAvailable = opened ? await relationshipDialogSearchInputAvailable(page) : false;
      queryApplied = opened && inputAvailable
        ? await setRelationshipDialogSearchQuery(page, query)
        : false;
    }

    if (!queryApplied) {
      stopReason = inputAvailable ? 'search-query-not-applied' : 'search-input-lost';
      break;
    }

    queriesRun.push(query);
    const queryStartCount = usersByUsername.size;
    let previousCount = usersByUsername.size;
    let unchangedRounds = 0;
    let queryRounds = 0;
    let lastScrollState = null;
    let queryStopReason = null;

    progressLog('relationship-search-query-start', {
      relationship: normalizedRelationship,
      query,
      loaded: usersByUsername.size,
      expectedCount,
      maxItems,
      maxScrollRounds,
      searchRounds,
    });

    while (!targetReached() && searchRounds < maxScrollRounds) {
      const collection = await collectFollowerEntriesFromDialog(page);
      const entries = Array.isArray(collection) ? collection : (collection.entries || []);
      const suggestionsVisible = !Array.isArray(collection) && Boolean(collection.suggestionsVisible);
      const rateLimited = !Array.isArray(collection) && Boolean(collection.rateLimited);

      if (rateLimited) {
        stopReason = 'instagram-rate-limit';
        await closeInstagramDialog(page);

        return {
          attempted: true,
          inputAvailable,
          queries: queriesRun,
          rounds: searchRounds,
          addedCount,
          openAttempts,
          stopReason,
          rateLimited: true,
          rateLimitText: collection.rateLimitText || null,
        };
      }

      const addedThisRound = addRelationshipEntriesToMap(entries, usersByUsername, targetUsername);
      addedCount += addedThisRound;
      searchRounds++;
      queryRounds++;

      if (usersByUsername.size === previousCount) {
        unchangedRounds++;
      } else {
        unchangedRounds = 0;
        previousCount = usersByUsername.size;
      }

      progressLog('relationship-search-progress', {
        relationship: normalizedRelationship,
        query,
        round: searchRounds,
        queryRound: queryRounds,
        loaded: usersByUsername.size,
        expectedCount,
        maxItems,
        maxScrollRounds,
        unchangedRounds,
        addedThisRound,
        atBottom: Boolean(lastScrollState?.atBottom),
        suggestionsVisible,
      });

      if (targetReached()) {
        queryStopReason = expectedCount > 0 && usersByUsername.size >= expectedCount
          ? 'expected-count-reached'
          : 'max-items-reached';
        break;
      }

      if (suggestionsVisible && unchangedRounds >= 1) {
        queryStopReason = 'search-suggestions-section-reached';
        break;
      }

      const scrollState = await scrollFollowerDialog(page);
      lastScrollState = scrollState;

      if (scrollState.atBottom && unchangedRounds >= 3) {
        queryStopReason = 'search-bottom-stale';
        break;
      }

      if (!scrollState.scrolled && unchangedRounds >= 2) {
        queryStopReason = 'search-scroll-stale';
        break;
      }

      if (unchangedRounds >= 8) {
        queryStopReason = 'search-no-new-items';
        break;
      }

      await sleep(scrollState.atBottom ? 900 : 650);
    }

    progressLog('relationship-search-query-complete', {
      relationship: normalizedRelationship,
      query,
      loaded: usersByUsername.size,
      addedThisQuery: usersByUsername.size - queryStartCount,
      expectedCount,
      maxItems,
      queryRounds,
      searchRounds,
      reason: queryStopReason,
      complete: targetReached(),
    });

    if (targetReached()) {
      stopReason = queryStopReason;
      break;
    }

    if (searchRounds >= maxScrollRounds) {
      stopReason = 'search-max-scroll-rounds-reached';
      break;
    }

    await sleep(450);
  }

  await setRelationshipDialogSearchQuery(page, '');
  await closeInstagramDialog(page);

  return {
    attempted: true,
    inputAvailable,
    queries: queriesRun,
    rounds: searchRounds,
    addedCount,
    openAttempts,
    stopReason: stopReason || (targetReached() ? 'expected-count-reached' : 'search-partitions-exhausted'),
    rateLimited: false,
    rateLimitText: null,
  };
}

async function collectPublicRelationshipList(page, username, profile, relationship, options = {}) {
  const maxItems = normalizeOptionalPositiveInteger(options.maxItems, DEFAULT_MAX_RELATIONSHIP_LIST_ITEMS);
  const expectedCount = normalizeOptionalPositiveInteger(options.expectedCount, 0);
  const runtimeConfig = options.runtimeConfig || {};
  const maxScrollRounds = Math.max(
    20,
    normalizeOptionalPositiveInteger(
      options.maxScrollRounds,
      DEFAULT_MAX_RELATIONSHIP_LIST_SCROLL_ROUNDS,
    ) || DEFAULT_MAX_RELATIONSHIP_LIST_SCROLL_ROUNDS,
  );
  const hasItemLimit = maxItems > 0;
  const normalizedRelationship = relationship === 'following' ? 'following' : 'followers';
  const result = {
    attempted: false,
    available: false,
    complete: false,
    count: 0,
    maxItems,
    expectedCount,
    items: [],
    reason: null,
    rateLimited: false,
    rateLimitText: null,
    openAttempts: 0,
    scrollRounds: 0,
    noProgressReopenLimit: RELATIONSHIP_NO_PROGRESS_REOPEN_LIMIT,
    searchAttempted: false,
    searchInputAvailable: false,
    searchQueries: [],
    searchRounds: 0,
    searchAddedCount: 0,
    searchStopReason: null,
  };

  if (profile?.isPrivate) {
    result.reason = 'private-profile';
    return result;
  }

  if (profile?.requiresLogin) {
    result.reason = 'login-required';
    return result;
  }

  result.attempted = true;
  const usersByUsername = new Map();
  const targetUsername = normalizeInstagramUsername(username);
  let lastScrollState = null;
  let totalScrollRounds = 0;
  let openAttempts = 0;
  let noProgressOpenAttempts = 0;
  let stopReason = null;

  const targetReached = () => {
    if (hasItemLimit && usersByUsername.size >= maxItems) {
      return true;
    }

    return expectedCount > 0 && usersByUsername.size >= expectedCount;
  };

  while (totalScrollRounds < maxScrollRounds && !targetReached()) {
    openAttempts++;

    progressLog(openAttempts === 1 ? 'relationship-opening' : 'relationship-reopening', {
      relationship: normalizedRelationship,
      loaded: usersByUsername.size,
      expectedCount,
      maxItems,
      openAttempt: openAttempts,
      maxScrollRounds,
    });

    const opened = normalizedRelationship === 'following'
      ? await openFollowingDialog(page, username, runtimeConfig)
      : await openFollowersDialog(page, username, runtimeConfig);

    if (!opened) {
      stopReason = `${normalizedRelationship}-dialog-not-found`;

      progressLog('relationship-dialog-missing', {
        relationship: normalizedRelationship,
        loaded: usersByUsername.size,
        expectedCount,
        openAttempt: openAttempts,
        reason: stopReason,
      });

      if (usersByUsername.size === 0) {
        result.reason = stopReason;
        return result;
      }

      break;
    }

    const passStartCount = usersByUsername.size;
    let unchangedRounds = 0;
    let previousCount = usersByUsername.size;
    let passRounds = 0;

    while (totalScrollRounds < maxScrollRounds && !targetReached()) {
      const collection = await collectFollowerEntriesFromDialog(page);
      const entries = Array.isArray(collection) ? collection : (collection.entries || []);
      const suggestionsVisible = !Array.isArray(collection) && Boolean(collection.suggestionsVisible);
      const rateLimited = !Array.isArray(collection) && Boolean(collection.rateLimited);

      if (rateLimited) {
        stopReason = 'instagram-rate-limit';
        result.rateLimited = true;
        result.rateLimitText = collection.rateLimitText || null;
        recordRunDebug('relationship-rate-limit-detected', {
          relationship: normalizedRelationship,
          loaded: usersByUsername.size,
          expectedCount,
          openAttempt: openAttempts,
          round: totalScrollRounds,
          text: collection.rateLimitText || null,
        });
        progressLog('relationship-rate-limited', {
          relationship: normalizedRelationship,
          loaded: usersByUsername.size,
          expectedCount,
          openAttempt: openAttempts,
          reason: stopReason,
        });
        break;
      }

      addRelationshipEntriesToMap(entries, usersByUsername, targetUsername);

      totalScrollRounds++;
      passRounds++;

      if (usersByUsername.size === previousCount) {
        unchangedRounds++;
      } else {
        unchangedRounds = 0;
        previousCount = usersByUsername.size;
      }

      progressLog('relationship-progress', {
        relationship: normalizedRelationship,
        round: totalScrollRounds,
        passRound: passRounds,
        openAttempt: openAttempts,
        loaded: usersByUsername.size,
        expectedCount,
        maxItems,
        maxScrollRounds,
        unchangedRounds,
        atBottom: Boolean(lastScrollState?.atBottom),
        suggestionsVisible,
      });

      if (targetReached()) {
        stopReason = expectedCount > 0 && usersByUsername.size >= expectedCount
          ? 'expected-count-reached'
          : 'max-items-reached';
        break;
      }

      if (suggestionsVisible) {
        stopReason = 'suggestions-section-reached';
        break;
      }

      const scrollState = await scrollFollowerDialog(page);
      lastScrollState = scrollState;

      if (scrollState.atBottom && unchangedRounds >= 5) {
        stopReason = 'pass-bottom-stale';
        break;
      }

      if (!scrollState.scrolled && unchangedRounds >= 3) {
        stopReason = 'pass-scroll-stale';
        break;
      }

      if (unchangedRounds >= 12) {
        stopReason = 'pass-no-new-items';
        break;
      }

      await sleep(scrollState.atBottom ? 1400 : 850);
    }

    const passAdded = usersByUsername.size - passStartCount;

    progressLog('relationship-pass-complete', {
      relationship: normalizedRelationship,
      round: totalScrollRounds,
      passRound: passRounds,
      openAttempt: openAttempts,
      loaded: usersByUsername.size,
      addedThisPass: passAdded,
      expectedCount,
      maxItems,
      maxScrollRounds,
      complete: targetReached(),
      reason: stopReason,
    });

    await closeInstagramDialog(page);

    if (stopReason === 'instagram-rate-limit') {
      break;
    }

    if (targetReached()) {
      break;
    }

    if (totalScrollRounds >= maxScrollRounds) {
      stopReason = 'max-scroll-rounds-reached';
      break;
    }

    if (passAdded <= 0) {
      noProgressOpenAttempts++;
    } else {
      noProgressOpenAttempts = 0;
    }

    if (noProgressOpenAttempts >= RELATIONSHIP_NO_PROGRESS_REOPEN_LIMIT) {
      stopReason = 'no-new-items-after-reopen';
      break;
    }

    await sleep(900);
  }

  if (
    stopReason !== 'instagram-rate-limit'
    && !targetReached()
    && expectedCount > 0
    && usersByUsername.size < expectedCount
  ) {
    const searchResult = await collectRelationshipSearchPartitions(
      page,
      username,
      normalizedRelationship,
      {
        ...options,
        maxScrollRounds,
      },
      usersByUsername,
    );

    result.searchAttempted = Boolean(searchResult.attempted);
    result.searchInputAvailable = Boolean(searchResult.inputAvailable);
    result.searchQueries = searchResult.queries || [];
    result.searchRounds = searchResult.rounds || 0;
    result.searchAddedCount = searchResult.addedCount || 0;
    result.searchStopReason = searchResult.stopReason || null;
    openAttempts += searchResult.openAttempts || 0;
    totalScrollRounds += searchResult.rounds || 0;

    progressLog('relationship-search-complete', {
      relationship: normalizedRelationship,
      loaded: usersByUsername.size,
      expectedCount,
      maxItems,
      searchQueries: result.searchQueries.length,
      searchRounds: result.searchRounds,
      searchAddedCount: result.searchAddedCount,
      reason: result.searchStopReason,
      complete: targetReached(),
    });

    if (searchResult.rateLimited) {
      stopReason = 'instagram-rate-limit';
      result.rateLimited = true;
      result.rateLimitText = searchResult.rateLimitText || null;
    } else {
      stopReason = targetReached()
        ? (expectedCount > 0 && usersByUsername.size >= expectedCount ? 'expected-count-reached-after-search' : 'max-items-reached-after-search')
        : (searchResult.stopReason || 'search-partitions-exhausted');
    }
  }

  result.items = hasItemLimit
    ? Array.from(usersByUsername.values()).slice(0, maxItems)
    : Array.from(usersByUsername.values());
  result.count = result.items.length;
  result.available = result.count > 0;
  result.openAttempts = openAttempts;
  result.scrollRounds = totalScrollRounds;
  result.complete = stopReason !== 'instagram-rate-limit' && (expectedCount > 0
    ? result.count >= expectedCount
    : ((!hasItemLimit || result.count < maxItems) && [
      'suggestions-section-reached',
      'no-new-items-after-reopen',
      'pass-bottom-stale',
      'pass-scroll-stale',
      'pass-no-new-items',
      'search-partitions-exhausted',
    ].includes(stopReason)));
  result.reason = stopReason === 'instagram-rate-limit'
    ? stopReason
    : result.available
    ? (result.complete ? null : (stopReason || `incomplete-${normalizedRelationship}-list`))
    : `no-${normalizedRelationship}-found`;

  progressLog('relationship-complete', {
    relationship: normalizedRelationship,
    loaded: result.count,
    expectedCount,
    maxItems,
    openAttempts,
    scrollRounds: totalScrollRounds,
    complete: result.complete,
    reason: result.reason,
    rateLimited: result.rateLimited,
  });

  return result;
}

async function collectPublicFollowersList(page, username, profile, options = {}) {
  return collectPublicRelationshipList(page, username, profile, 'followers', options);
}

async function collectPublicFollowingList(page, username, profile, options = {}) {
  return collectPublicRelationshipList(page, username, profile, 'following', options);
}

async function clickButtonByText(page, candidateTexts) {
  const normalizedCandidates = candidateTexts.map((text) => normalizeText(text).toLowerCase());

  return page.evaluate((texts) => {
    const buttons = Array.from(document.querySelectorAll('button, a, div[role="button"]'));
    const target = buttons.find((element) => texts.includes((element.innerText || '').replace(/\s+/g, ' ').trim().toLowerCase()));

    if (!target) {
      return false;
    }

    target.click();
    return true;
  }, normalizedCandidates).catch(() => false);
}

async function dismissCookieConsentIfPresent(page) {
  for (const candidateTexts of [
    ['Alle Cookies erlauben', 'Allow all cookies', 'Allow All Cookies'],
    ['Nur erforderliche Cookies erlauben', 'Allow essential cookies', 'Decline optional cookies'],
  ]) {
    const clicked = await clickButtonByText(page, candidateTexts);

    if (clicked) {
      await sleep(900);
    }
  }
}

async function dismissPostLoginPrompts(page) {
  for (const candidateTexts of [
    ['Jetzt nicht', 'Not now', 'Not Now'],
    ['Informationen speichern', 'Save info', 'Save Info'],
    ['Benachrichtigungen jetzt nicht', 'Turn On Notifications'],
  ]) {
    const clicked = await clickButtonByText(page, candidateTexts);

    if (clicked) {
      await sleep(900);
    }
  }
}

async function collectPageDiagnostics(page, options = {}) {
  const includeCookies = options.includeCookies === true;
  const bodyText = normalizeText(
    await page.evaluate(() => document.body?.innerText || '').catch(() => ''),
  );
  const selectors = await page.evaluate(() => ({
    usernameField: Boolean(document.querySelector('input[name="username"]')),
    passwordField: Boolean(document.querySelector('input[name="password"]')),
    submitButton: Boolean(document.querySelector('button[type="submit"]')),
    totalInputs: document.querySelectorAll('input').length,
    textLikeInputs: document.querySelectorAll('input[type="text"], input[type="email"], input[type="tel"], input:not([type])').length,
    passwordInputs: document.querySelectorAll('input[type="password"]').length,
  })).catch(() => ({
    usernameField: false,
    passwordField: false,
    submitButton: false,
    totalInputs: 0,
    textLikeInputs: 0,
    passwordInputs: 0,
  }));
  const diagnostics = {
    url: page.url(),
    title: await page.title().catch(() => null),
    bodyPreview: bodyText.slice(0, 1200),
    selectors,
  };

  if (includeCookies) {
    const cookies = await page.cookies('https://www.instagram.com/').catch(() => []);
    diagnostics.cookies = summarizeCookieCollection(cookies);
  }

  return diagnostics;
}

async function findFirstExistingSelector(page, selectors) {
  for (const selector of selectors) {
    const handle = await page.$(selector).catch(() => null);

    if (handle) {
      await handle.dispose().catch(() => {});
      return selector;
    }
  }

  return null;
}

async function fillLoginInput(page, selector, value, runtimeConfig, fieldName) {
  const timeout = Math.min(resolveNavigationWaitMs(runtimeConfig, 120000), isLoginSessionMode ? 10000 : 20000);

  await page.waitForSelector(selector, {
    timeout,
    visible: true,
  });
  await page.focus(selector);
  await page.click(selector, {
    clickCount: 3,
  }).catch(() => {});
  await page.keyboard.press(process.platform === 'darwin' ? 'Meta+A' : 'Control+A').catch(() => {});
  await page.keyboard.press('Backspace').catch(() => {});
  await page.type(selector, value, {
    delay: runtimeConfig.typingDelayMs,
  });

  let currentValue = await page.$eval(selector, (element) => element.value || '').catch(() => '');

  if (currentValue !== value) {
    await page.$eval(selector, (element, nextValue) => {
      const valueSetter = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value')?.set;

      if (valueSetter) {
        valueSetter.call(element, nextValue);
      } else {
        element.value = nextValue;
      }

      element.dispatchEvent(new Event('input', { bubbles: true }));
      element.dispatchEvent(new Event('change', { bubbles: true }));
      element.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true }));
    }, value).catch(() => {});

    currentValue = await page.$eval(selector, (element) => element.value || '').catch(() => '');
  }

  recordRunDebug('auto-login-input-filled', {
    fieldName,
    selector,
    expectedLength: String(value || '').length,
    actualLength: String(currentValue || '').length,
    matched: currentValue === value,
  });

  return currentValue === value;
}

async function waitForLoginSubmitAttempt(page, runtimeConfig, action, label) {
  const timeout = Math.min(resolveNavigationWaitMs(runtimeConfig, 120000), isLoginSessionMode ? 8000 : 12000);

  await Promise.allSettled([
    page.waitForNavigation({
      timeout,
      waitUntil: 'domcontentloaded',
    }),
    action(),
  ]);
  await sleep(1200);

  recordRunDebug('auto-login-submit-attempt', {
    label,
    url: page.url(),
    cookies: summarizeCookieCollection(
      await page.cookies('https://www.instagram.com/').catch(() => []),
    ),
  });
}

async function submitInstagramLoginForm(page, submitSelector, passwordSelector, runtimeConfig) {
  await waitForLoginSubmitAttempt(
    page,
    runtimeConfig,
    () => page.click(submitSelector),
    'submit-button-click',
  );

  let activeCookies = await page.cookies('https://www.instagram.com/').catch(() => []);

  if (activeCookies.some((cookie) => cookie.name === 'sessionid')) {
    return;
  }

  await page.focus(passwordSelector).catch(() => {});
  await waitForLoginSubmitAttempt(
    page,
    runtimeConfig,
    () => page.keyboard.press('Enter'),
    'password-enter',
  );

  activeCookies = await page.cookies('https://www.instagram.com/').catch(() => []);

  if (activeCookies.some((cookie) => cookie.name === 'sessionid')) {
    return;
  }

  await waitForLoginSubmitAttempt(
    page,
    runtimeConfig,
    () => page.evaluate(() => {
      const buttons = Array.from(document.querySelectorAll('button[type="submit"], form button'));
      const clickableButton = buttons.find((button) =>
        !button.disabled &&
        button.getAttribute('aria-disabled') !== 'true' &&
        button.offsetParent !== null
      ) || buttons.find((button) => !button.disabled) || buttons[0] || null;

      if (clickableButton) {
        clickableButton.click();
      }
    }),
    'dom-submit-button-click',
  );
}

async function performInstagramLogin(page, runtimeConfig) {
  const diagnostics = {
    attempted: false,
    success: false,
    finalUrl: null,
    title: null,
    bodyPreview: '',
    formDetected: false,
    invalidCredentials: false,
    challengeDetected: false,
    sessionCookiePresent: false,
    warnings: [],
  };

  if (!runtimeConfig?.autoLoginEnabled) {
    return diagnostics;
  }

  const loginUsername = normalizeText(runtimeConfig.loginUsername || '');
  const loginPassword = String(runtimeConfig.loginPassword || '');

  if (!loginUsername || !loginPassword) {
    diagnostics.warnings.push(
      !loginUsername
        ? 'Auto-Login ist aktiviert, aber der Instagram-Benutzername fehlt.'
        : (runtimeConfig?.loginPasswordConfigured && runtimeConfig?.loginPasswordDecryptable === false)
          ? 'Auto-Login ist aktiviert, aber das gespeicherte Passwort konnte von der Base-Installation nicht entschluesselt werden.'
          : 'Auto-Login ist aktiviert, aber Benutzername oder Passwort fehlen.',
    );
    recordRunDebug('auto-login-missing-credentials', {
      hasUsername: Boolean(loginUsername),
      hasPassword: Boolean(loginPassword),
      loginPasswordConfigured: Boolean(runtimeConfig?.loginPasswordConfigured),
      loginPasswordDecryptable: runtimeConfig?.loginPasswordDecryptable !== false,
      loginPasswordSource: runtimeConfig?.loginPasswordSource || null,
    });
    return diagnostics;
  }

  diagnostics.attempted = true;
  recordRunDebug('auto-login-start', {
    loginUsername,
    hasPassword: Boolean(loginPassword),
  });

  const usernameSelectors = [
    'input[name="username"]',
    'input[autocomplete="username"]',
    'input[aria-label*="Benutzername"]',
    'input[aria-label*="username" i]',
    'input[placeholder*="Benutzername"]',
    'input[placeholder*="username" i]',
    'input[type="email"]',
    'input[type="text"]',
    'input[type="tel"]',
  ];
  const passwordSelectors = [
    'input[name="password"]',
    'input[autocomplete="current-password"]',
    'input[aria-label*="Passwort"]',
    'input[aria-label*="password" i]',
    'input[type="password"]',
  ];
  const submitSelectors = [
    'button[type="submit"]',
    'form button',
  ];

  const loginNavigation = await navigateWithSoftTimeout(
    page,
    'https://www.instagram.com/accounts/login/',
    runtimeConfig,
    { timeoutMs: Math.min(resolveNavigationWaitMs(runtimeConfig, 120000), isLoginSessionMode ? 15000 : 20000) },
  );

  if (!loginNavigation.ok) {
    diagnostics.warnings.push(`Instagram-Login-Seite konnte nicht stabil geladen werden: ${loginNavigation.error}`);
  }

  await dismissCookieConsentIfPresent(page);

  let usernameSelector = await findFirstExistingSelector(page, usernameSelectors);
  let passwordSelector = await findFirstExistingSelector(page, passwordSelectors);
  let submitSelector = await findFirstExistingSelector(page, submitSelectors);

  const loginFormTimeout = Math.min(runtimeConfig.navigationTimeoutMs, isLoginSessionMode ? 10000 : 20000);

  try {
    if (!usernameSelector || !passwordSelector || !submitSelector) {
      await page.waitForFunction(() => {
        const textLikeInput = document.querySelector('input[name="username"], input[autocomplete="username"], input[type="email"], input[type="text"], input[type="tel"], input[aria-label*="Benutzername"], input[aria-label*="username" i]');
        const passwordInput = document.querySelector('input[name="password"], input[autocomplete="current-password"], input[type="password"], input[aria-label*="Passwort"], input[aria-label*="password" i]');
        const submitButton = document.querySelector('button[type="submit"], form button');

        return Boolean(textLikeInput && passwordInput && submitButton);
      }, {
        timeout: loginFormTimeout,
      });
    }

    usernameSelector = await findFirstExistingSelector(page, usernameSelectors);
    passwordSelector = await findFirstExistingSelector(page, passwordSelectors);
    submitSelector = await findFirstExistingSelector(page, submitSelectors);
    await page.waitForSelector(usernameSelector, {
      timeout: loginFormTimeout,
    });
  } catch (error) {
    const switchedAccount = await clickButtonByText(page, [
      'Zu einem anderen Konto wechseln',
      'Switch accounts',
      'Switch Accounts',
      'Anderes Konto verwenden',
    ]);

    if (switchedAccount) {
      await sleep(1500);
      await dismissCookieConsentIfPresent(page);
      usernameSelector = await findFirstExistingSelector(page, usernameSelectors);
      passwordSelector = await findFirstExistingSelector(page, passwordSelectors);
      submitSelector = await findFirstExistingSelector(page, submitSelectors);
    }
  }

  diagnostics.formDetected = Boolean(usernameSelector && passwordSelector && submitSelector);

  if (!diagnostics.formDetected) {
    const pageDiagnostics = await collectPageDiagnostics(page, { includeCookies: true });
    diagnostics.finalUrl = pageDiagnostics.url;
    diagnostics.title = pageDiagnostics.title;
    diagnostics.bodyPreview = pageDiagnostics.bodyPreview;
    diagnostics.warnings.push('Das Instagram-Login-Formular wurde nicht gefunden.');
    recordRunDebug('auto-login-form-missing', pageDiagnostics);

    return diagnostics;
  }

  recordRunDebug('auto-login-form-ready', await collectPageDiagnostics(page, { includeCookies: true }));

  await sleep(1200);

  const usernameFilled = await fillLoginInput(page, usernameSelector, loginUsername, runtimeConfig, 'username');
  const passwordFilled = await fillLoginInput(page, passwordSelector, loginPassword, runtimeConfig, 'password');

  if (!usernameFilled || !passwordFilled) {
    diagnostics.warnings.push('Die Instagram-Login-Felder konnten nicht stabil befuellt werden.');
  }

  await submitInstagramLoginForm(page, submitSelector, passwordSelector, runtimeConfig);

  await sleep(runtimeConfig.postLoginWaitMs);
  await dismissPostLoginPrompts(page);

  let pageDiagnostics = await collectPageDiagnostics(page, { includeCookies: true });
  diagnostics.finalUrl = pageDiagnostics.url;
  diagnostics.title = pageDiagnostics.title;
  diagnostics.bodyPreview = pageDiagnostics.bodyPreview;
  diagnostics.sessionCookiePresent = (pageDiagnostics.cookies || []).some((cookie) => cookie.name === 'sessionid');
  recordRunDebug('auto-login-after-submit', pageDiagnostics);

  const normalizedBody = diagnostics.bodyPreview.toLowerCase();
  diagnostics.invalidCredentials = /password was incorrect|incorrect password|dein passwort war nicht korrekt|falsches passwort/.test(normalizedBody);
  diagnostics.challengeDetected = /security code|bestaetige, dass du es bist|confirm it'?s you|two-factor|authenticator|suspicious login|challenge/.test(normalizedBody);
  diagnostics.success =
    !diagnostics.invalidCredentials &&
    !diagnostics.challengeDetected &&
    diagnostics.sessionCookiePresent &&
    !diagnostics.finalUrl.includes('/accounts/login') &&
    !/log into instagram|melde dich an|password|passwort/.test(normalizedBody);

  if (diagnostics.success) {
    await navigateWithSoftTimeout(page, 'https://www.instagram.com/', runtimeConfig);
    await sleep(1200);

    pageDiagnostics = await collectPageDiagnostics(page, { includeCookies: true });
    diagnostics.finalUrl = pageDiagnostics.url;
    diagnostics.title = pageDiagnostics.title;
    diagnostics.bodyPreview = pageDiagnostics.bodyPreview;
    diagnostics.sessionCookiePresent = (pageDiagnostics.cookies || []).some((cookie) => cookie.name === 'sessionid');
    diagnostics.success =
      diagnostics.sessionCookiePresent &&
      !diagnostics.finalUrl.includes('/accounts/login') &&
      !/log into instagram|melde dich an|password|passwort/.test(
        diagnostics.bodyPreview.toLowerCase(),
      );
    recordRunDebug('auto-login-post-verify', pageDiagnostics);
  }

  if (diagnostics.invalidCredentials) {
    diagnostics.warnings.push('Instagram hat die Zugangsdaten als ungueltig abgelehnt.');
  }

  if (diagnostics.challengeDetected) {
    diagnostics.warnings.push('Instagram verlangt nach dem Login eine zusaetzliche Verifizierung oder Challenge.');
  }

  if (!diagnostics.success && !diagnostics.invalidCredentials && !diagnostics.challengeDetected) {
    diagnostics.warnings.push('Der Auto-Login wurde ausgefuehrt, aber Instagram hat keinen stabilen angemeldeten Zustand geliefert.');
  }

  recordRunDebug('auto-login-result', diagnostics);

  return diagnostics;
}

async function waitForInteractiveLoginCompletion(page, runtimeConfig) {
  const diagnostics = {
    attempted: true,
    success: false,
    finalUrl: null,
    title: null,
    bodyPreview: '',
    warnings: [],
    waitedForManualLogin: true,
  };

  const timeoutAt = Date.now() + Math.max(runtimeConfig.navigationTimeoutMs, 180000);
  recordRunDebug('manual-login-wait-start', {
    timeoutAt: new Date(timeoutAt).toISOString(),
  });

  while (Date.now() < timeoutAt) {
    await dismissPostLoginPrompts(page);

    const currentBody = normalizeText(
      await page.evaluate(() => document.body?.innerText || '').catch(() => ''),
    ).slice(0, 1000);
    const currentUrl = page.url();
    const activeCookies = await page.cookies('https://www.instagram.com/').catch(() => []);
    const hasSessionCookie = activeCookies.some((cookie) => cookie.name === 'sessionid');
    const stillLoginView = /log into instagram|melde dich an|password|passwort|forgot password/.test(currentBody.toLowerCase());
    const challengeDetected = /security code|bestaetige, dass du es bist|confirm it'?s you|two-factor|authenticator|suspicious login|challenge/.test(currentBody.toLowerCase());

    if (hasSessionCookie && !stillLoginView && !currentUrl.includes('/accounts/login')) {
      diagnostics.success = true;
      diagnostics.finalUrl = currentUrl;
      diagnostics.title = await page.title().catch(() => null);
      diagnostics.bodyPreview = currentBody;
      recordRunDebug('manual-login-success', diagnostics);

      return diagnostics;
    }

    diagnostics.finalUrl = currentUrl;
    diagnostics.title = await page.title().catch(() => null);
    diagnostics.bodyPreview = currentBody;

    if (challengeDetected) {
      diagnostics.warnings.push('Instagram verlangt weiterhin eine Verifizierung oder Challenge.');
      recordRunDebug('manual-login-challenge-visible', {
        currentUrl,
        bodyPreview: currentBody,
      });
    }

    await sleep(1500);
  }

  diagnostics.warnings.push('Die manuelle Instagram-Anmeldung wurde nicht innerhalb des Zeitlimits abgeschlossen.');
  recordRunDebug('manual-login-timeout', diagnostics);

  return diagnostics;
}

async function establishInstagramSession(page, runtimeConfig, notes) {
  const cookieDiagnostics = await primeInstagramSession(page, buildCookiePath(runtimeConfig), runtimeConfig);
  let loginDiagnostics = {
    attempted: false,
    success: false,
    warnings: [],
  };

  if (cookieDiagnostics.loaded) {
    notes.push('Instagram-Cookies aus Speicher geladen.');
  }

  if (cookieDiagnostics.sessionCookieProvided && cookieDiagnostics.sessionCookieAccepted) {
    notes.push('Die importierte sessionid wurde lokal in den Browser-Context uebernommen.');
  }

  if (cookieDiagnostics.sessionCookieProvided && !cookieDiagnostics.sessionCookieAccepted) {
    notes.push('Die importierte sessionid wurde bereits vor dem Profilaufruf nicht akzeptiert.');
  }

  if (cookieDiagnostics.sessionCookieAccepted && !cookieDiagnostics.sessionCookieRetained) {
    notes.push('Instagram hat die importierte sessionid beim Vorabtest wieder verworfen.');
  }

  if (cookieDiagnostics.sessionCookieRetained && !cookieDiagnostics.loginViewDetectedAfterReload) {
    notes.push('Die importierte Instagram-Session wurde im Vorabtest zunaechst akzeptiert.');
  }

  const alreadyAuthenticated =
    cookieDiagnostics.sessionCookieRetained &&
    !cookieDiagnostics.loginViewDetectedAfterReload &&
    !cookieDiagnostics.postReloadUrl?.includes('/accounts/login');

  if (alreadyAuthenticated) {
    recordRunDebug('session-established-from-existing-state', cookieDiagnostics);
    return { cookieDiagnostics, loginDiagnostics, sessionEstablished: true };
  }

  if (runtimeConfig.autoLoginEnabled) {
    loginDiagnostics = await performInstagramLogin(page, runtimeConfig);

    if (loginDiagnostics.attempted) {
      notes.push(
        isLoginSessionMode
          ? 'Auto-Login fuer Instagram wurde gestartet.'
          : 'Auto-Login fuer Instagram wurde im Hintergrund gestartet.',
      );
    }

    if (loginDiagnostics.success) {
      notes.push('Instagram-Login wurde erfolgreich abgeschlossen.');
      recordRunDebug('session-established-via-auto-login', loginDiagnostics);
      return { cookieDiagnostics, loginDiagnostics, sessionEstablished: true };
    }

    notes.push('Auto-Login konnte keinen stabilen angemeldeten Zustand herstellen.');
  }

  if (isLoginSessionMode && resolvePuppeteerHeadlessMode(runtimeConfig) === false) {
    notes.push('Warte auf eine manuelle Anmeldung im sichtbaren Browserfenster.');
    loginDiagnostics = await waitForInteractiveLoginCompletion(page, runtimeConfig);

    if (loginDiagnostics.success) {
      notes.push('Manuelle Instagram-Anmeldung erfolgreich abgeschlossen.');
      recordRunDebug('session-established-via-manual-login', loginDiagnostics);
      return { cookieDiagnostics, loginDiagnostics, sessionEstablished: true };
    }
  } else if (isLoginSessionMode) {
    const headlessWarning = 'Manuelle Instagram-Anmeldung wurde uebersprungen, weil auf dem Server kein sichtbarer Browser/Display-Server verfuegbar ist.';
    notes.push(`${headlessWarning} Der Session-Aufbau nutzt nur gespeicherte Cookies oder Auto-Login.`);
    loginDiagnostics = {
      ...loginDiagnostics,
      success: false,
      manualLoginAttempted: false,
      warnings: [
        ...(Array.isArray(loginDiagnostics.warnings) ? loginDiagnostics.warnings : []),
        headlessWarning,
      ],
    };
    recordRunDebug('manual-login-skipped-headless', loginDiagnostics);
  }

  recordRunDebug('session-establish-failed', {
    cookieDiagnostics,
    loginDiagnostics,
  });

  return {
    cookieDiagnostics,
    loginDiagnostics,
    sessionEstablished: false,
  };
}

function getRuntimeAccountPool(runtimeConfig = {}) {
  const pool = Array.isArray(runtimeConfig.accountPool) ? runtimeConfig.accountPool : [];

  if (pool.length > 0) {
    return pool;
  }

  return [{
    ...runtimeConfig,
    accountPool: [],
  }];
}

function selectNextRuntimeAccount(runtimeConfig, usedAccountKeys) {
  const accountPool = getRuntimeAccountPool(runtimeConfig);

  for (const account of accountPool) {
    const candidate = {
      ...runtimeConfig,
      ...account,
      accountPool,
    };
    const accountKey = getScraperAccountKey(candidate);

    if (!accountKey || usedAccountKeys.has(accountKey)) {
      continue;
    }

    return candidate;
  }

  return null;
}

async function clearInstagramSessionState(page) {
  await closeInstagramDialog(page).catch(() => {});

  const cookies = await page
    .cookies(
      'https://www.instagram.com/',
      'https://instagram.com/',
      'https://www.facebook.com/',
      'https://facebook.com/',
    )
    .catch(() => []);

  if (cookies.length > 0) {
    await page.deleteCookie(...cookies.map((cookie) => ({
      name: cookie.name,
      domain: cookie.domain,
      path: cookie.path || '/',
    }))).catch((error) => {
      recordRunDebug('account-switch-cookie-clear-failed', {
        error: normalizeText(error.message || String(error)),
      });
    });
  }

  await page.evaluate(() => {
    try {
      window.localStorage?.clear();
      window.sessionStorage?.clear();
    } catch (error) {
      // Storage can be inaccessible on transient browser pages.
    }
  }).catch(() => {});

  recordRunDebug('account-switch-state-cleared', {
    clearedCookieCount: cookies.length,
  });
}

async function switchScraperAccountAfterRateLimit(page, runtimeConfig, notes, relationship, rateLimitText, usedAccountKeys) {
  const accountPool = getRuntimeAccountPool(runtimeConfig);
  const currentKey = getScraperAccountKey(runtimeConfig);
  let lastFailure = null;

  if (currentKey) {
    usedAccountKeys.add(currentKey);
  }

  if (accountPool.length <= 1) {
    recordRunDebug('account-switch-skipped', {
      relationship,
      reason: 'no-alternate-account',
      accountPoolSize: accountPool.length,
      rateLimitText,
    });
    return null;
  }

  while (true) {
    const nextRuntimeConfig = selectNextRuntimeAccount(runtimeConfig, usedAccountKeys);

    if (!nextRuntimeConfig) {
      recordRunDebug('account-switch-exhausted', {
        relationship,
        accountPoolSize: accountPool.length,
        usedAccountCount: usedAccountKeys.size,
        rateLimitText,
      });
      return lastFailure
        ? {
          ...lastFailure,
          sessionEstablished: false,
          failed: true,
        }
        : null;
    }

    const nextKey = getScraperAccountKey(nextRuntimeConfig);
    const fromLabel = runtimeConfig.profileLabel || currentKey || 'instagram-default';
    const toLabel = nextRuntimeConfig.profileLabel || nextKey || 'instagram-default';

    if (nextKey) {
      usedAccountKeys.add(nextKey);
    }

    progressLog('account-switching', {
      relationship,
      fromProfileLabel: fromLabel,
      toProfileLabel: toLabel,
      accountPoolSize: accountPool.length,
    });
    notes.push(`Rate-Limit erkannt; Scraper-Account wird gewechselt (${fromLabel} -> ${toLabel}).`);
    recordRunDebug('account-switch-start', {
      relationship,
      fromProfileLabel: fromLabel,
      toProfileLabel: toLabel,
      fromAccountKey: currentKey,
      toAccountKey: nextKey,
      rateLimitText,
    });

    await clearInstagramSessionState(page);

    const sessionResult = await establishInstagramSession(page, nextRuntimeConfig, notes);

    if (sessionResult.sessionEstablished) {
      const switchCookiePath = buildCookiePath(nextRuntimeConfig);
      const cookiesSavedAfterSwitch = await saveCookiesToFile(page, switchCookiePath);

      if (cookiesSavedAfterSwitch.saved) {
        notes.push(`Sessiondaten fuer ${toLabel} wurden gespeichert.`);
      }

      notes.push(`Scraper-Account aktiv: ${toLabel}.`);
      recordRunDebug('account-switch-success', {
        relationship,
        toProfileLabel: toLabel,
        cookieFilePath: switchCookiePath,
        cookiesSaved: cookiesSavedAfterSwitch,
      });

      return {
        runtimeConfig: nextRuntimeConfig,
        cookieDiagnostics: sessionResult.cookieDiagnostics,
        loginDiagnostics: sessionResult.loginDiagnostics,
        sessionEstablished: true,
        failed: false,
      };
    }

    notes.push(`Scraper-Account ${toLabel} konnte keine stabile Instagram-Session herstellen.`);
    lastFailure = {
      runtimeConfig: nextRuntimeConfig,
      cookieDiagnostics: sessionResult.cookieDiagnostics,
      loginDiagnostics: sessionResult.loginDiagnostics,
    };
    recordRunDebug('account-switch-session-failed', {
      relationship,
      toProfileLabel: toLabel,
      cookieDiagnostics: sessionResult.cookieDiagnostics,
      loginDiagnostics: sessionResult.loginDiagnostics,
    });
  }
}

function buildRelationshipCollectionOptions(runtimeConfig, relationship) {
  const normalizedRelationship = relationship === 'following' ? 'following' : 'followers';

  return {
    maxItems: normalizedRelationship === 'following'
      ? runtimeConfig.followingListMaxItems
      : runtimeConfig.followerListMaxItems,
    maxScrollRounds: runtimeConfig.relationshipListMaxScrollRounds,
    expectedCount: normalizedRelationship === 'following'
      ? runtimeConfig.expectedFollowingCount
      : runtimeConfig.expectedFollowerCount,
    runtimeConfig,
  };
}

async function collectRelationshipListForRuntime(page, username, profile, relationship, runtimeConfig) {
  const normalizedRelationship = relationship === 'following' ? 'following' : 'followers';
  const options = buildRelationshipCollectionOptions(runtimeConfig, normalizedRelationship);

  return normalizedRelationship === 'following'
    ? collectPublicFollowingList(page, username, profile, options)
    : collectPublicFollowersList(page, username, profile, options);
}

function mergeRelationshipLists(previousList, nextList) {
  if (!previousList) {
    return nextList;
  }

  if (!nextList) {
    return previousList;
  }

  const itemsByUsername = new Map();

  for (const item of [
    ...(Array.isArray(previousList.items) ? previousList.items : []),
    ...(Array.isArray(nextList.items) ? nextList.items : []),
  ]) {
    const relatedUsername = normalizeInstagramUsername(item?.username || '');

    if (!relatedUsername || itemsByUsername.has(relatedUsername)) {
      continue;
    }

    itemsByUsername.set(relatedUsername, {
      ...item,
      username: relatedUsername,
    });
  }

  const maxItems = normalizeOptionalPositiveInteger(nextList.maxItems, previousList.maxItems);
  const mergedItems = Array.from(itemsByUsername.values());
  const limitedItems = maxItems > 0 ? mergedItems.slice(0, maxItems) : mergedItems;
  const recoveredFromRateLimit = Boolean(
    (previousList.rateLimited || previousList.rateLimitRecovered) && !nextList.rateLimited,
  );

  return {
    ...previousList,
    ...nextList,
    items: limitedItems,
    count: limitedItems.length,
    available: limitedItems.length > 0 || Boolean(previousList.available) || Boolean(nextList.available),
    attempted: Boolean(previousList.attempted || nextList.attempted),
    rateLimited: Boolean(nextList.rateLimited),
    rateLimitText: nextList.rateLimited ? (nextList.rateLimitText || previousList.rateLimitText || null) : null,
    rateLimitRecovered: recoveredFromRateLimit,
    previousRateLimitText: previousList.rateLimitText || previousList.previousRateLimitText || null,
    searchAttempted: Boolean(previousList.searchAttempted || nextList.searchAttempted),
    searchInputAvailable: Boolean(previousList.searchInputAvailable || nextList.searchInputAvailable),
    searchQueries: [
      ...new Set([
        ...(Array.isArray(previousList.searchQueries) ? previousList.searchQueries : []),
        ...(Array.isArray(nextList.searchQueries) ? nextList.searchQueries : []),
      ]),
    ],
    searchRounds: (Number(previousList.searchRounds) || 0) + (Number(nextList.searchRounds) || 0),
    searchAddedCount: (Number(previousList.searchAddedCount) || 0) + (Number(nextList.searchAddedCount) || 0),
    searchStopReason: nextList.searchStopReason || previousList.searchStopReason || null,
  };
}

async function refreshProfileAfterAccountSwitch(page, username, profileUrl, runtimeConfig, notes) {
  progressLog('profile-opening', {
    relationship: null,
    url: profileUrl,
    accountSwitch: true,
    profileLabel: runtimeConfig.profileLabel || null,
  });

  const profileNavigation = await navigateWithSoftTimeout(page, profileUrl, runtimeConfig);

  if (!profileNavigation.ok) {
    notes.push(`Profilseite konnte nach Account-Wechsel nicht stabil geladen werden: ${profileNavigation.error}`);
  }

  await sleep(1800);

  const profile = await collectProfileInfo(page, username);
  const html = await page.content().catch(() => null);
  const title = await page.title().catch(() => null);
  const finalUrl = page.url();

  progressLog('profile-collected', {
    usernameSeen: Boolean(profile.usernameSeen),
    isPrivate: Boolean(profile.isPrivate),
    requiresLogin: Boolean(profile.requiresLogin),
    imageCount: profile.imageCount || 0,
    accountSwitch: true,
    profileLabel: runtimeConfig.profileLabel || null,
  });

  return {
    profile,
    html,
    title,
    finalUrl,
  };
}

async function collectRelationshipListWithAccountSwitches(page, username, profile, relationship, runtimeState, notes, profileUrl) {
  const normalizedRelationship = relationship === 'following' ? 'following' : 'followers';
  const maxPasses = Math.max(1, getRuntimeAccountPool(runtimeState.runtimeConfig).length);
  let currentProfile = profile;
  let latestHtml = null;
  let latestTitle = null;
  let latestFinalUrl = page.url();
  let switched = false;
  let list = null;

  for (let pass = 0; pass < maxPasses; pass++) {
    const collectedList = await collectRelationshipListForRuntime(
      page,
      username,
      currentProfile,
      normalizedRelationship,
      runtimeState.runtimeConfig,
    );
    list = mergeRelationshipLists(list, collectedList);
    recordRunDebug(
      `${normalizedRelationship}-list-collected${switched ? '-after-account-switch' : ''}`,
      list,
    );

    if (!list?.rateLimited) {
      break;
    }

    const switchResult = await switchScraperAccountAfterRateLimit(
      page,
      runtimeState.runtimeConfig,
      notes,
      normalizedRelationship,
      list.rateLimitText || null,
      runtimeState.usedAccountKeys,
    );

    if (!switchResult) {
      break;
    }

    runtimeState.runtimeConfig = switchResult.runtimeConfig;
    runtimeState.cookieFilePath = buildCookiePath(switchResult.runtimeConfig);
    runtimeState.cookieDiagnostics = switchResult.cookieDiagnostics;
    runtimeState.loginDiagnostics = switchResult.loginDiagnostics;

    if (!switchResult.sessionEstablished) {
      runtimeState.cookieSaveDisabled = true;
      latestFinalUrl = page.url();
      break;
    }

    switched = true;

    const refreshed = await refreshProfileAfterAccountSwitch(
      page,
      username,
      profileUrl,
      runtimeState.runtimeConfig,
      notes,
    );

    currentProfile = {
      ...currentProfile,
      ...refreshed.profile,
    };
    latestHtml = refreshed.html;
    latestTitle = refreshed.title;
    latestFinalUrl = refreshed.finalUrl;
  }

  return {
    list,
    profile: currentProfile,
    html: latestHtml,
    title: latestTitle,
    finalUrl: latestFinalUrl,
    switched,
  };
}

async function renderProfileSnapshot(browser, screenshotPath, username, profileUrl, title, profile, notes) {
  const snapshotPage = await browser.newPage();

  try {
    await snapshotPage.setViewport({
      width: 940,
      height: 560,
      deviceScaleFactor: 1,
    });

    const badgeMarkup = [
      profile.isPrivate ? '<span class="badge">Privates Profil</span>' : '',
      profile.requiresLogin ? '<span class="badge muted">Login-Hinweis</span>' : '',
      profile.usernameSeen ? '' : '<span class="badge warning">Profiltext eingeschraenkt</span>',
    ]
      .filter(Boolean)
      .join('');

    const headline = escapeHtml(profile.ogTitle || title || `@${username}`);
    const description = escapeHtml(
      profile.description ||
        profile.bodyTextPreview ||
        'Instagram hat fuer dieses Profil aktuell nur eingeschraenkte Daten geliefert.',
    );

    const avatarMarkup = profile.ogImage
      ? `<img src="${escapeHtml(profile.ogImage)}" alt="Profilbild von ${escapeHtml(username)}">`
      : '<div class="avatar-fallback">@</div>';

    await snapshotPage.setContent(
      `<!DOCTYPE html>
      <html lang="de">
        <head>
          <meta charset="utf-8">
          <title>Instagram Profil-Snapshot</title>
          <style>
            * { box-sizing: border-box; }
            body {
              margin: 0;
              font-family: "Segoe UI", Arial, sans-serif;
              background:
                radial-gradient(circle at top left, #feda75 0%, rgba(254, 218, 117, 0.25) 18%, transparent 40%),
                radial-gradient(circle at top right, #d62976 0%, rgba(214, 41, 118, 0.22) 18%, transparent 45%),
                linear-gradient(135deg, #f6f7fb 0%, #eef2ff 45%, #f8fafc 100%);
              color: #111827;
            }
            .frame {
              width: 940px;
              min-height: 560px;
              padding: 34px;
            }
            .card {
              display: grid;
              grid-template-columns: 220px 1fr;
              gap: 30px;
              min-height: 492px;
              padding: 30px;
              border: 1px solid rgba(15, 23, 42, 0.08);
              border-radius: 28px;
              background: rgba(255, 255, 255, 0.92);
              box-shadow: 0 20px 60px rgba(15, 23, 42, 0.12);
              backdrop-filter: blur(12px);
            }
            .brand {
              display: inline-flex;
              align-items: center;
              gap: 10px;
              margin-bottom: 14px;
              font-size: 13px;
              font-weight: 700;
              letter-spacing: 0.08em;
              text-transform: uppercase;
              color: #be185d;
            }
            .avatar {
              width: 220px;
              height: 220px;
              overflow: hidden;
              border: 4px solid rgba(255, 255, 255, 0.96);
              border-radius: 28px;
              box-shadow: 0 18px 50px rgba(190, 24, 93, 0.18);
              background: linear-gradient(135deg, #f9a8d4 0%, #c084fc 100%);
            }
            .avatar img,
            .avatar-fallback {
              width: 100%;
              height: 100%;
            }
            .avatar img {
              display: block;
              object-fit: cover;
            }
            .avatar-fallback {
              display: grid;
              place-items: center;
              font-size: 86px;
              font-weight: 800;
              color: white;
            }
            .meta {
              display: flex;
              flex-direction: column;
              justify-content: space-between;
              gap: 18px;
            }
            h1 {
              margin: 0;
              font-size: 34px;
              line-height: 1.1;
            }
            .username {
              margin-top: 8px;
              font-size: 15px;
              font-weight: 700;
              letter-spacing: 0.04em;
              text-transform: uppercase;
              color: #475569;
            }
            .badges {
              display: flex;
              flex-wrap: wrap;
              gap: 10px;
              margin-top: 18px;
            }
            .badge {
              padding: 8px 12px;
              border-radius: 999px;
              background: #fce7f3;
              color: #9d174d;
              font-size: 13px;
              font-weight: 700;
            }
            .badge.muted {
              background: #e0e7ff;
              color: #3730a3;
            }
            .badge.warning {
              background: #fef3c7;
              color: #92400e;
            }
            .description {
              margin: 0;
              padding: 18px 20px;
              border-radius: 20px;
              background: #f8fafc;
              font-size: 16px;
              line-height: 1.6;
              color: #1f2937;
            }
            .facts {
              display: grid;
              grid-template-columns: repeat(3, minmax(0, 1fr));
              gap: 12px;
            }
            .fact {
              padding: 16px;
              border-radius: 18px;
              background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
              border: 1px solid rgba(148, 163, 184, 0.18);
            }
            .fact-label {
              font-size: 12px;
              font-weight: 700;
              letter-spacing: 0.08em;
              text-transform: uppercase;
              color: #64748b;
            }
            .fact-value {
              margin-top: 8px;
              font-size: 15px;
              font-weight: 700;
              color: #0f172a;
              word-break: break-word;
            }
            .footer {
              display: flex;
              justify-content: space-between;
              align-items: center;
              gap: 16px;
              font-size: 12px;
              color: #64748b;
            }
            .footer strong {
              color: #0f172a;
            }
          </style>
        </head>
        <body>
          <div class="frame">
            <div class="card">
              <div>
                <div class="brand">Instagram Snapshot</div>
                <div class="avatar">${avatarMarkup}</div>
              </div>
              <div class="meta">
                <div>
                  <h1>${headline}</h1>
                  <div class="username">@${escapeHtml(username)}</div>
                  <div class="badges">${badgeMarkup}</div>
                </div>
                <p class="description">${description}</p>
                <div class="facts">
                  <div class="fact">
                    <div class="fact-label">Quelle</div>
                    <div class="fact-value">Instagram HTML</div>
                  </div>
                  <div class="fact">
                    <div class="fact-label">Bilder erkannt</div>
                    <div class="fact-value">${escapeHtml(String(profile.imageCount || 0))}</div>
                  </div>
                  <div class="fact">
                    <div class="fact-label">Snapshot-Typ</div>
                    <div class="fact-value">Profilkarte</div>
                  </div>
                </div>
                <div class="footer">
                  <span><strong>Profil:</strong> ${escapeHtml(profileUrl)}</span>
                  <span>${escapeHtml(notes.join(' | ') || 'Metadaten erfolgreich extrahiert.')}</span>
                </div>
              </div>
            </div>
          </div>
        </body>
      </html>`,
      {
        waitUntil: 'domcontentloaded',
      },
    );

    await sleep(1200);

    await snapshotPage.screenshot({
      path: screenshotPath,
    });
  } finally {
    await snapshotPage.close();
  }
}

async function captureDebugPageScreenshot(page, screenshotPath, notes) {
  if (!page || !screenshotPath) {
    return null;
  }

  try {
    await page.screenshot({
      path: screenshotPath,
      fullPage: false,
    });

    return screenshotPath;
  } catch (error) {
    const message = normalizeText(error?.message || String(error));

    if (Array.isArray(notes)) {
      notes.push(`Debug-Screenshot konnte nicht erstellt werden: ${message}`);
    }

    recordRunDebug('debug-screenshot-failed', {
      screenshotPath,
      error: message,
    });

    return null;
  }
}

(async () => {
  if (!username && !isLoginSessionMode) {
    console.log(
      JSON.stringify({
        ok: false,
        error: 'Kein Instagram-Username uebergeben.',
      }),
    );
    process.exit(1);
  }

  const startedAt = Date.now();
  const notes = [];
  const consoleMessages = [];
  let runtimeConfig = loadRuntimeConfig(runtimeConfigPath);
  const debugLogPath = initializeRunDebug(operationMode, username, runtimeConfig);
  const profileUrl = isLoginSessionMode
    ? 'https://www.instagram.com/'
    : `https://www.instagram.com/${username}/`;
  const artifacts = buildArtifactPaths(isLoginSessionMode ? '__session__' : username);
  let cookieFilePath = buildCookiePath(runtimeConfig);
  const browserUserDataDir = buildBrowserUserDataDir(runtimeConfig);
  let activeBrowserUserDataDir = browserUserDataDir;
  let cleanupBrowserProfileOnExit = shouldCleanupBrowserProfile(runtimeConfig, browserUserDataDir);

  let browser;
  let page;
  let initialHtml = '';
  let debugScreenshotPath = null;
  let initialProfile = {
    bodyTextPreview: '',
    description: null,
    imageCount: 0,
    isPrivate: false,
    ogImage: null,
    ogTitle: null,
    requiresLogin: false,
    usernameSeen: false,
  };
  let title = null;
  let finalUrl = profileUrl;
  let loginDiagnostics = {
    attempted: false,
    success: false,
    warnings: [],
  };
  let cookieDiagnostics = {
    loaded: false,
    warnings: [],
  };
  recordRunDebug('run-start', {
    runtimeConfigPath,
    profileUrl,
    artifacts,
    cookieFilePath,
    browserUserDataDir,
    isLoginSessionMode,
  });

  try {
    const resolvedHeadlessMode = resolvePuppeteerHeadlessMode(runtimeConfig);

    if (resolvedHeadlessMode !== false && (isLoginSessionMode || runtimeConfig.headlessEnabled === false)) {
      notes.push('Kein Display-Server erkannt; Chrome wird headless gestartet. Fuer einen sichtbaren Browser DISPLAY/xvfb konfigurieren.');
    }

    const launchOptions = {
      headless: resolvedHeadlessMode,
      userDataDir: activeBrowserUserDataDir,
      protocolTimeout: Math.max(300000, resolveNavigationWaitMs(runtimeConfig, 300000)),
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-gpu',
        '--disable-blink-features=AutomationControlled',
        '--lang=de-DE,de;q=0.9',
      ],
    };
    recordRunDebug('browser-launch-options', {
      headless: launchOptions.headless,
      userDataDir: launchOptions.userDataDir,
      args: launchOptions.args,
    });

    try {
      browser = await puppeteer.launch(launchOptions);
    } catch (launchError) {
      const launchErrorMessage = normalizeText(launchError.message || String(launchError));
      const browserProfileLocked = /already running|processsingleton|userdatadir|user data dir|user data directory/i.test(launchErrorMessage);

      if (runtimeConfig.persistentProfileEnabled && browserProfileLocked) {
        notes.push('Das persistente Browser-Profil ist bereits durch Chrome belegt; diese Sitzung nutzt ein temporaeres Profil und speichert die Cookies separat.');
        activeBrowserUserDataDir = fs.mkdtempSync(
          path.join(runtimeTempDirectory, 'puppeteer-profile-fallback-'),
        );
        cleanupBrowserProfileOnExit = true;
        runtimeConfig.persistentProfileEnabled = false;
        recordRunDebug('browser-launch-fallback', {
          previousError: launchErrorMessage,
          fallbackUserDataDir: activeBrowserUserDataDir,
        });
        browser = await puppeteer.launch({
          ...launchOptions,
          userDataDir: activeBrowserUserDataDir,
        });
      } else {
        throw launchError;
      }
    }

    page = await browser.newPage();
    page.setDefaultTimeout(0);
    page.setDefaultNavigationTimeout(0);

    page.on('console', (message) => {
      const text = normalizeText(message.text());

      if (!text) {
        return;
      }

      if (message.type() === 'error' || /rate limit exceeded/i.test(text)) {
        consoleMessages.push(`${message.type()}: ${text}`);
      }
    });

    page.on('pageerror', (error) => {
      consoleMessages.push(`pageerror: ${normalizeText(error.message)}`);
      recordRunDebug('pageerror', {
        message: normalizeText(error.message),
      });
    });

    page.on('requestfailed', (request) => {
      const requestUrl = request.url();

      if (!/instagram|facebook/i.test(requestUrl)) {
        return;
      }

      const failureText = normalizeText(request.failure()?.errorText || 'requestfailed');
      const message = `requestfailed: ${failureText} ${requestUrl}`;
      consoleMessages.push(message);
      recordRunDebug('requestfailed', {
        url: requestUrl,
        resourceType: request.resourceType(),
        failureText,
      });
    });

    page.on('response', (response) => {
      const responseUrl = response.url();

      if (response.status() < 400 || !/instagram|facebook/i.test(responseUrl)) {
        return;
      }

      const message = `response ${response.status()}: ${responseUrl}`;
      consoleMessages.push(message);
      recordRunDebug('http-error-response', {
        status: response.status(),
        url: responseUrl,
        resourceType: response.request().resourceType(),
      });
    });

    await page.setExtraHTTPHeaders({
      'Accept-Language': 'de-DE,de;q=0.9,en;q=0.8',
    });

    await page.setUserAgent(
      'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
    );

    await page.setViewport({
      width: 1280,
      height: 1600,
      deviceScaleFactor: 1,
    });

    await page.emulateMediaFeatures([{ name: 'prefers-color-scheme', value: 'light' }]);

    await page.evaluateOnNewDocument(() => {
      Object.defineProperty(navigator, 'webdriver', {
        get: () => false,
      });
    });

    notes.push(`Scraper-Profil: ${runtimeConfig.profileLabel}`);

    if (runtimeConfig.persistentProfileEnabled) {
      notes.push('Persistentes Browser-Profil aktiviert.');
    }
    recordRunDebug('page-ready', await collectPageDiagnostics(page, { includeCookies: true }));

    let sessionEstablished = false;

    if (isMiniScanMode) {
      notes.push('Mini-Scan aktiv: oeffentliche Profildaten werden ohne Instagram-Anmeldung ausgelesen.');
      cookieDiagnostics = {
        loaded: false,
        skipped: true,
        warnings: [],
      };
      loginDiagnostics = {
        attempted: false,
        success: false,
        skipped: true,
        warnings: [],
      };
      progressLog('profile-session-check', {
        relationship: null,
        skipped: true,
      });
    } else {
      progressLog('profile-session-check', {
        relationship: null,
      });

      const sessionResult = await establishInstagramSession(
        page,
        runtimeConfig,
        notes,
      );
      cookieDiagnostics = sessionResult.cookieDiagnostics;
      loginDiagnostics = sessionResult.loginDiagnostics;
      sessionEstablished = sessionResult.sessionEstablished;

      if (sessionEstablished) {
        const cookiesSavedAfterSession = await saveCookiesToFile(page, cookieFilePath);

        if (cookiesSavedAfterSession.saved) {
          notes.push('Sessiondaten wurden gespeichert.');

          if (!cookiesSavedAfterSession.sessionCookieSaved) {
            notes.push('Es wurden zwar Cookies gespeichert, aber keine sessionid.');
          }
        }
      }

      if (isLoginSessionMode) {
        debugScreenshotPath = await captureDebugPageScreenshot(page, artifacts.screenshotPath, notes);

        const responsePayload = {
          ok: sessionEstablished,
          statusLevel: sessionEstablished ? 'success' : 'error',
          statusMessage: sessionEstablished
            ? 'Instagram-Session wurde erfolgreich aufgebaut und gespeichert.'
            : 'Instagram-Session konnte nicht stabil aufgebaut werden.',
          username: null,
          finalUrl: page.url(),
          htmlBytes: 0,
          htmlPath: null,
          htmlPreview: '',
          notes: dedupe(notes),
          cookieDiagnostics,
          loginDiagnostics,
          profile: null,
          profileUrl,
          screenshotPath: debugScreenshotPath,
          scrapedAt: new Date().toISOString(),
          screenshotMode: debugScreenshotPath ? 'page' : null,
          title: await page.title().catch(() => null),
          warnings: dedupe([
            ...consoleMessages,
            ...(cookieDiagnostics.warnings || []),
            ...(loginDiagnostics.warnings || []),
          ]),
          durationMs: Date.now() - startedAt,
          operationMode,
          debugLogPath,
        };
        flushRunDebug(responsePayload);
        console.log(JSON.stringify(responsePayload));

        return;
      }
    }

    const runtimeState = {
      runtimeConfig,
      cookieFilePath,
      cookieDiagnostics,
      loginDiagnostics,
      usedAccountKeys: new Set([getScraperAccountKey(runtimeConfig)].filter(Boolean)),
      cookieSaveDisabled: false,
    };

    progressLog('profile-opening', {
      relationship: null,
      url: profileUrl,
    });

    const profileNavigation = await navigateWithSoftTimeout(page, profileUrl, runtimeConfig);

    if (!profileNavigation.ok) {
      notes.push(`Profilseite konnte nicht stabil geladen werden: ${profileNavigation.error}`);
    }

    progressLog('profile-page-loaded', {
      relationship: null,
      url: page.url(),
    });

    await sleep(1800);

    initialHtml = await page.content();
    initialProfile = await collectProfileInfo(page, username);
    title = await page.title().catch(() => null);
    finalUrl = page.url();

    progressLog('profile-collected', {
      usernameSeen: Boolean(initialProfile.usernameSeen),
      isPrivate: Boolean(initialProfile.isPrivate),
      requiresLogin: Boolean(initialProfile.requiresLogin),
      imageCount: initialProfile.imageCount || 0,
    });

    if (shouldCollectFollowers) {
      const followersResult = await collectRelationshipListWithAccountSwitches(
        page,
        username,
        initialProfile,
        'followers',
        runtimeState,
        notes,
        profileUrl,
      );

      initialProfile = followersResult.profile;
      initialProfile.followersList = followersResult.list;
      runtimeConfig = runtimeState.runtimeConfig;
      cookieFilePath = runtimeState.cookieFilePath;
      cookieDiagnostics = runtimeState.cookieDiagnostics;
      loginDiagnostics = runtimeState.loginDiagnostics;

      if (followersResult.html !== null) {
        initialHtml = followersResult.html;
      }

      if (followersResult.title !== null) {
        title = followersResult.title;
      }

      finalUrl = followersResult.finalUrl || page.url();

      if (initialProfile.followersList?.rateLimited) {
        notes.push('Instagram hat die Followerliste per Rate-Limit-Meldung blockiert; die Listenphase wurde abgebrochen.');
        consoleMessages.push('rate-limit: instagram-rate-limit followers');
      } else if (initialProfile.followersList?.available) {
        notes.push(`Followerliste ausgelesen: ${initialProfile.followersList.count} Eintraege.`);
      } else if (initialProfile.followersList?.attempted) {
        notes.push('Followerliste konnte nicht ausgelesen werden.');
      }

      if (page.url().includes('/followers')) {
        await navigateWithSoftTimeout(page, profileUrl, runtimeConfig);
        await sleep(900);
      }
    }

    if (shouldCollectFollowing) {
      const followingResult = await collectRelationshipListWithAccountSwitches(
        page,
        username,
        initialProfile,
        'following',
        runtimeState,
        notes,
        profileUrl,
      );

      initialProfile = followingResult.profile;
      initialProfile.followingList = followingResult.list;
      runtimeConfig = runtimeState.runtimeConfig;
      cookieFilePath = runtimeState.cookieFilePath;
      cookieDiagnostics = runtimeState.cookieDiagnostics;
      loginDiagnostics = runtimeState.loginDiagnostics;

      if (followingResult.html !== null) {
        initialHtml = followingResult.html;
      }

      if (followingResult.title !== null) {
        title = followingResult.title;
      }

      finalUrl = followingResult.finalUrl || page.url();

      if (initialProfile.followingList?.rateLimited) {
        notes.push('Instagram hat die Gefolgt-Liste per Rate-Limit-Meldung blockiert; die Listenphase wurde abgebrochen.');
        consoleMessages.push('rate-limit: instagram-rate-limit following');
      } else if (initialProfile.followingList?.available) {
        notes.push(`Gefolgt-Liste ausgelesen: ${initialProfile.followingList.count} Eintraege.`);
      } else if (initialProfile.followingList?.attempted) {
        notes.push('Gefolgt-Liste konnte nicht ausgelesen werden.');
      }

      if (page.url().includes('/followers') || page.url().includes('/following')) {
        await navigateWithSoftTimeout(page, profileUrl, runtimeConfig);
        await sleep(900);
      }
    }

    finalUrl = page.url();

    if (finalUrl.includes('/accounts/login')) {
      notes.push('Instagram hat den Seitenaufruf direkt auf die Login-Seite umgeleitet.');
    }

    if (! initialProfile.usernameSeen) {
      notes.push('Profilname wurde im sichtbaren DOM nur eingeschraenkt erkannt.');
    }

    if (initialProfile.requiresLogin) {
      notes.push('Instagram blendet einen Login-Hinweis fuer dieses Profil ein.');
    }

    if (consoleMessages.some((message) => /rate limit exceeded/i.test(message))) {
      notes.push('Instagram meldet ein Rate-Limit; die Daten koennen unvollstaendig sein.');
    }

    const dedupedWarnings = dedupe([
      ...consoleMessages,
      ...(cookieDiagnostics.warnings || []),
      ...(loginDiagnostics.warnings || []),
    ]);
    const outcome = deriveScrapeOutcome({
      title,
      finalUrl,
      profile: initialProfile,
      warnings: dedupedWarnings,
      cookieDiagnostics,
      loginDiagnostics,
    });

    fs.writeFileSync(artifacts.htmlPath, initialHtml, 'utf8');

    if (isMiniScanMode) {
      notes.push('Cookies wurden im Mini-Scan nicht geladen oder gespeichert.');
    } else if (runtimeState.cookieSaveDisabled) {
      notes.push('Cookies wurden nicht gespeichert, weil der Account-Wechsel keine stabile Session herstellen konnte.');
    } else if (shouldSaveCookies(finalUrl, initialProfile)) {
      const cookiesSaved = await saveCookiesToFile(page, cookieFilePath);
      if (cookiesSaved.saved) {
        notes.push('Aktualisierte Instagram-Cookies gespeichert.');
        if (!cookiesSaved.sessionCookieSaved) {
          notes.push('Die gespeicherten Cookies enthalten weiterhin keine sessionid.');
        }
      } else {
        notes.push('Es wurden keine Cookies gespeichert, weil keine relevanten Instagram-Cookies gefunden wurden.');
      }
    } else {
      notes.push('Cookies wurden nicht gespeichert, weil die Session offenbar nicht gueltig war.');
    }

    debugScreenshotPath = await captureDebugPageScreenshot(page, artifacts.screenshotPath, notes);

    const responsePayload = {
      ok: outcome.ok,
      statusLevel: outcome.statusLevel,
      statusMessage: outcome.statusMessage,
      username,
      finalUrl,
      htmlBytes: Buffer.byteLength(initialHtml, 'utf8'),
      htmlPath: artifacts.htmlPath,
      htmlPreview: initialHtml.slice(0, 4000),
      notes: dedupe(notes),
      cookieDiagnostics,
      loginDiagnostics,
      profile: initialProfile,
      profileUrl,
      screenshotPath: debugScreenshotPath,
      scrapedAt: new Date().toISOString(),
      screenshotMode: debugScreenshotPath ? 'page' : null,
      title,
      warnings: dedupedWarnings,
      durationMs: Date.now() - startedAt,
      operationMode,
      debugLogPath,
    };
    flushRunDebug(responsePayload);
    console.log(JSON.stringify(responsePayload));
  } catch (error) {
    if (initialHtml) {
      fs.writeFileSync(artifacts.htmlPath, initialHtml, 'utf8');
    }

    debugScreenshotPath = await captureDebugPageScreenshot(page, artifacts.screenshotPath, notes);

    const responsePayload = {
      ok: false,
      statusLevel: 'error',
      statusMessage: 'Instagram-Scrape fehlgeschlagen.',
      username,
      error: normalizeText(error.message),
      finalUrl,
      htmlPath: initialHtml ? artifacts.htmlPath : null,
      notes: dedupe(notes),
      screenshotPath: debugScreenshotPath,
      screenshotMode: debugScreenshotPath ? 'page' : null,
      warnings: dedupe(consoleMessages),
      durationMs: Date.now() - startedAt,
      debugLogPath,
    };
    recordRunDebug('run-error', responsePayload);
    flushRunDebug(responsePayload);
    console.log(JSON.stringify(responsePayload));

    process.exitCode = 1;
  } finally {
    if (browser) {
      await closeBrowserSoftly(browser);
    }

    if (cleanupBrowserProfileOnExit) {
      cleanupDirectory(activeBrowserUserDataDir);
    }
  }
})();
