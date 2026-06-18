const fs = require('fs');
const path = require('path');
const { attachScanBilling } = require('./lib/scan-billing.cjs');
const { runInstagramFullScanFlow } = require('./scrape-instagram-full.cjs');
const { runInstagramListScanFlow } = require('./scrape-instagram-list.cjs');
const { runInstagramPostsScanFlow } = require('./scrape-instagram-posts.cjs');
const { runProfileSuggestionConnectionScan: runProfileSuggestionConnectionScanFromModule } = require('./scrape-instagram-suggestions-router.cjs');
const {
  configureRuntimeEnvironment,
  ensureDirectory,
  isUsableDirectory,
} = require('./lib/runtime-environment.cjs');
const {
  debugLog,
  dedupe,
  escapeHtml,
  normalizeInstagramUsername,
  normalizeNumberAtLeast,
  normalizeOptionalPositiveInteger,
  normalizeText,
  summarizeCookieCollection,
} = require('./lib/instagram-scraper-utils.cjs');
const {
  buildBrowserUserDataDir,
  getScraperAccountKey,
  loadRuntimeConfig,
  normalizeSuggestionCandidateHistory,
  resolvePuppeteerHeadlessMode,
  shouldCleanupBrowserProfile,
} = require('./lib/instagram-runtime-config.cjs');
const {
  buildArtifactPaths,
  buildCookiePath,
  buildRelatedScreenshotPath,
  buildScraperProfileBlockPath,
} = require('./lib/instagram-paths.cjs');

const { runtimeTempDirectory } = configureRuntimeEnvironment({
  fallbackTempDirectory: path.resolve(__dirname, '../../../storage/app/tmp'),
});

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
const isFollowersSearchMode = ['followers-search', 'search-followers'].includes(operationMode);
const isFollowingSearchMode = ['following-search', 'search-following'].includes(operationMode);
const isSuggestionsMode = ['suggestions', 'profile-suggestions', 'suggestion-connections'].includes(operationMode);
const isPostsMode = ['posts', 'post-scan'].includes(operationMode);
const isFullScanMode = ['analyze', 'profile', 'basic', 'grunddaten'].includes(operationMode);
const isListScanMode = [
  'followers',
  'following',
  'followers-search',
  'search-followers',
  'following-search',
  'search-following',
  'public-connections-batch',
].includes(operationMode);
const isRelationshipSearchOnlyMode = isFollowersSearchMode || isFollowingSearchMode;
const isRelationshipOnlyMode = isFollowersOnlyMode || isFollowingOnlyMode || isRelationshipSearchOnlyMode;
const shouldCollectFollowers = operationMode === 'analyze' || isFollowersOnlyMode || isFollowersSearchMode;
const shouldCollectFollowing = operationMode === 'analyze' || isFollowingOnlyMode || isFollowingSearchMode;
const DEFAULT_MAX_RELATIONSHIP_LIST_ITEMS = 0;
const DEFAULT_MAX_RELATIONSHIP_LIST_SCROLL_ROUNDS = 100000;
const RELATIONSHIP_NO_PROGRESS_REOPEN_LIMIT = 2;
const LIVE_PREVIEW_MIN_INTERVAL_MS = 2500;
const RELATIONSHIP_SEARCH_PARTITION_CHARACTERS = [
  'a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k', 'l', 'm',
  'n', 'o', 'p', 'q', 'r', 's', 't', 'u', 'v', 'w', 'x', 'y', 'z',
  '0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '.', '_',
];
const RELATIONSHIP_SEARCH_PARTITION_QUERIES = RELATIONSHIP_SEARCH_PARTITION_CHARACTERS;
const sharedScraperHelpers = {
  normalizeText,
  normalizeInstagramUsername,
  normalizeOptionalPositiveInteger,
  normalizeNumberAtLeast,
};
const runtimeConfigDefaults = {
  profileId: '',
  profileLabel: 'instagram-default',
  persistentProfileEnabled: isLoginSessionMode,
  browserProfilePath: path.resolve(__dirname, '../../../storage/app/browser-profiles/instagram/default'),
  cookieFilePath: path.resolve(__dirname, '../../../storage/app/cookies/instagram-cookies.json'),
  headlessEnabled: true,
  autoLoginEnabled: false,
  miniScanUseSession: false,
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
  relationshipPartitionLargeLists: true,
  relationshipPartitionThreshold: 250,
  relationshipSearchQueriesPerDialog: 8,
  relationshipSearchPartitionMaxItems: 250,
  relationshipProgressCheckpointSize: 250,
  expectedFollowerCount: 0,
  expectedFollowingCount: 0,
  relationshipPrioritizedSearchUsernames: {
    followers: [],
    following: [],
  },
  relationshipSearchOnly: false,
  relationshipSearchTargetUsername: '',
  relationshipSearchTargetMaxItems: 0,
  relationshipSearchTargetMaxScrollRounds: 60,
  relationshipSearchInputMaxAttempts: 3,
  relationshipSearchWaitMs: 900,
  suggestionScanMaxItems: 140,
  suggestionCandidateMaxItems: 80,
  suggestionPublicListSearchMaxScrollRounds: 90,
  suggestionInlineMaxRounds: 36,
  suggestionDialogMaxRounds: 48,
  suggestionCandidateInlineMaxRounds: 24,
  suggestionCandidateDialogMaxRounds: 36,
  suggestionCandidateMaxAttempts: 1,
  suggestionCandidateRetryDelayMs: 3000,
  suggestionSkipPreviouslyChecked: true,
  suggestionNoMatchSkipAfter: 2,
  suggestionMaxScraperProfileSwitches: 3,
  postScanMaxItems: 100,
  postScanMaxScrollRounds: 40,
  postScanMaxLikesPerPost: 250,
  postScanMaxCommentsPerPost: 250,
  profileHoverCardsEnabled: true,
  profileHoverCardWaitMs: 850,
  suggestionDismissChecked: false,
  suggestionCandidateHistory: {},
  scriptWatchdogEnabled: true,
  scriptStallTimeoutMs: 900000,
  browserDisconnectAbort: true,
  publicConnectionCandidateMaxAttempts: 3,
  publicConnectionCandidateMaxDurationMs: 1200000,
  publicConnectionDialogMissingMaxAttempts: 2,
  publicConnectionRateLimitAccountSwitchEnabled: true,
  publicConnectionMaxScraperProfileSwitches: 3,
  gracefulStopFilePath: '',
  livePreviewPath: '',
  livePreviewEnabled: true,
  skipDebugArtifacts: false,
  blockHeavyResources: false,
  accountPool: [],
};
const runtimeConfigModuleOptions = {
  defaults: runtimeConfigDefaults,
  debugLog,
  ensureDirectory,
  helpers: sharedScraperHelpers,
  isLoginSessionMode,
  isUsableDirectory,
  runtimeTempDirectory,
  defaultMaxRelationshipListScrollRounds: DEFAULT_MAX_RELATIONSHIP_LIST_SCROLL_ROUNDS,
};
const pathHelperOptions = {
  ensureDirectory,
  isUsableDirectory,
  normalizeText,
};

class ScraperAbortError extends Error {
  constructor(message, code = 'SCRAPER_ABORTED') {
    super(message);
    this.name = 'ScraperAbortError';
    this.code = code;
  }
}

const scriptWatchdog = {
  enabled: false,
  interval: null,
  timeoutMs: 0,
  lastActivityAt: Date.now(),
  lastActivityReason: 'start',
  abortError: null,
  abortListeners: new Set(),
  browser: null,
  intentionalBrowserClose: false,
  browserDisconnectAbort: true,
};
let technicalHeartbeatInterval = null;

function markScriptActivity(reason = 'activity') {
  scriptWatchdog.lastActivityAt = Date.now();
  scriptWatchdog.lastActivityReason = reason;
}

function requestScriptAbort(message, code = 'SCRAPER_ABORTED') {
  if (scriptWatchdog.abortError) {
    return scriptWatchdog.abortError;
  }

  const error = new ScraperAbortError(message, code);
  scriptWatchdog.abortError = error;

  process.stderr.write(`[SCRAPER ABORT] ${JSON.stringify({
    at: new Date().toISOString(),
    code,
    message,
    lastActivityReason: scriptWatchdog.lastActivityReason,
    idleMs: Date.now() - scriptWatchdog.lastActivityAt,
  })}\n`);

  try {
    recordRunDebug('script-abort-requested', {
      code,
      message,
      lastActivityReason: scriptWatchdog.lastActivityReason,
      idleMs: Date.now() - scriptWatchdog.lastActivityAt,
    });
  } catch {
    // Debug logging must never block abort handling.
  }

  for (const listener of Array.from(scriptWatchdog.abortListeners)) {
    try {
      listener(error);
    } catch {
      // Ignore listener cleanup failures.
    }
  }

  try {
    scriptWatchdog.browser?.process?.()?.kill?.('SIGTERM');
  } catch {
    // The browser may already be gone.
  }

  return error;
}

function assertScriptAlive() {
  if (scriptWatchdog.abortError) {
    throw scriptWatchdog.abortError;
  }
}

function configureScriptWatchdog(runtimeConfig = {}) {
  const enabled = runtimeConfig.scriptWatchdogEnabled !== false;
  const configuredTimeout = Number(runtimeConfig.scriptStallTimeoutMs || runtimeConfig.nodeStallTimeoutMs || 900000);
  const timeoutMs = Number.isFinite(configuredTimeout)
    ? Math.max(60000, configuredTimeout)
    : 900000;

  scriptWatchdog.enabled = enabled;
  scriptWatchdog.timeoutMs = timeoutMs;
  scriptWatchdog.browserDisconnectAbort = runtimeConfig.browserDisconnectAbort !== false;
  markScriptActivity('watchdog-configured');

  if (scriptWatchdog.interval) {
    clearInterval(scriptWatchdog.interval);
    scriptWatchdog.interval = null;
  }

  if (!enabled) {
    return;
  }

  scriptWatchdog.interval = setInterval(() => {
    if (scriptWatchdog.abortError) {
      return;
    }

    const idleMs = Date.now() - scriptWatchdog.lastActivityAt;

    if (idleMs >= scriptWatchdog.timeoutMs) {
      requestScriptAbort(
        `Node-Scraper abgebrochen: seit ${Math.round(idleMs / 1000)} Sekunden kein Fortschritt (${scriptWatchdog.lastActivityReason}).`,
        'SCRIPT_STALLED',
      );
    }
  }, Math.min(30000, Math.max(5000, Math.floor(timeoutMs / 6))));

  scriptWatchdog.interval.unref?.();
}

function stopScriptWatchdog() {
  if (scriptWatchdog.interval) {
    clearInterval(scriptWatchdog.interval);
    scriptWatchdog.interval = null;
  }
}

function startTechnicalHeartbeat() {
  if (technicalHeartbeatInterval) {
    clearInterval(technicalHeartbeatInterval);
  }

  technicalHeartbeatInterval = setInterval(() => {
    process.stderr.write(`[SCRAPER PROGRESS] ${JSON.stringify({
      at: new Date().toISOString(),
      mode: operationMode,
      stage: 'technical-heartbeat',
      heartbeat: true,
      lastActivityReason: scriptWatchdog.lastActivityReason,
      idleMs: Date.now() - scriptWatchdog.lastActivityAt,
    })}\n`);
  }, 10000);

  technicalHeartbeatInterval.unref?.();
}

function stopTechnicalHeartbeat() {
  if (technicalHeartbeatInterval) {
    clearInterval(technicalHeartbeatInterval);
    technicalHeartbeatInterval = null;
  }
}

function sleep(ms) {
  assertScriptAlive();

  return new Promise((resolve, reject) => {
    let settled = false;
    const finish = (callback, value) => {
      if (settled) {
        return;
      }

      settled = true;
      clearTimeout(timer);
      scriptWatchdog.abortListeners.delete(onAbort);
      callback(value);
    };
    const onAbort = (error) => finish(reject, error);
    const timer = setTimeout(() => {
      try {
        assertScriptAlive();
        finish(resolve);
      } catch (error) {
        finish(reject, error);
      }
    }, Math.max(0, Number(ms) || 0));

    scriptWatchdog.abortListeners.add(onAbort);
  });
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
    const status = response?.status?.() ?? null;
    const ok = response && status < 400;

    if (!ok) {
      const errorMessage = `HTTP ${status}`;
      recordRunDebug('navigation-http-error', {
        url,
        finalUrl: page.url(),
        timeoutMs,
        waitUntil,
        status,
      });

      return {
        ok: false,
        status,
        error: errorMessage,
        finalUrl: page.url(),
        timeoutMs,
      };
    }

    return {
      ok: true,
      status,
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

let activeScraperProfile = null;
let activeGracefulStopFilePath = null;
let gracefulStopProgressLogged = false;
let lastLivePreviewAt = 0;

function summarizeScraperProfile(runtimeConfig = {}) {
  const profileLabel = normalizeText(String(runtimeConfig.profileLabel || runtimeConfig.profile_label || 'instagram-default')) || 'instagram-default';
  const loginUsername = normalizeText(String(runtimeConfig.loginUsername || runtimeConfig.login_username || ''));
  const profileId = normalizeText(String(runtimeConfig.profileId || runtimeConfig.profile_id || ''));

  return {
    label: profileLabel,
    loginUsername: loginUsername || null,
    id: profileId || null,
  };
}

function setActiveScraperProfile(runtimeConfig = {}) {
  activeScraperProfile = summarizeScraperProfile(runtimeConfig);
}

function activeScraperProfilePayload() {
  return activeScraperProfile ? {
    scraperProfile: activeScraperProfile,
    scraperProfileLabel: activeScraperProfile.label,
    scraperProfileLoginUsername: activeScraperProfile.loginUsername,
    scraperProfileId: activeScraperProfile.id,
  } : {};
}

function setGracefulStopFilePath(runtimeConfig = {}) {
  activeGracefulStopFilePath = normalizeText(String(
    runtimeConfig.gracefulStopFilePath || runtimeConfig.graceful_stop_file_path || '',
  )) || null;
}

function progressLog(stage, data = {}) {
  assertScriptAlive();
  markScriptActivity(`progress:${stage}`);

  process.stderr.write(`[SCRAPER PROGRESS] ${JSON.stringify({
    at: new Date().toISOString(),
    mode: operationMode,
    stage,
    ...activeScraperProfilePayload(),
    ...data,
  })}\n`);
}

function readGracefulStopRequest() {
  if (!activeGracefulStopFilePath) {
    return null;
  }

  try {
    if (!fs.existsSync(activeGracefulStopFilePath)) {
      return null;
    }

    const rawPayload = fs.readFileSync(activeGracefulStopFilePath, 'utf8');
    const payload = rawPayload ? JSON.parse(rawPayload) : {};

    return {
      reason: normalizeText(String(payload?.reason || 'Scan wurde in der Oberflaeche beendet.')),
      requestedAt: normalizeText(String(payload?.requestedAt || '')) || null,
    };
  } catch (error) {
    return {
      reason: 'Scan wurde in der Oberflaeche beendet.',
      requestedAt: null,
    };
  }
}

function markGracefulStopIfRequested(relationship = null, data = {}) {
  const request = readGracefulStopRequest();

  if (!request) {
    return null;
  }

  if (!gracefulStopProgressLogged) {
    gracefulStopProgressLogged = true;
    progressLog('scan-stop-requested', {
      relationship,
      gracefullyStopped: true,
      stopReason: 'ui-stop-requested',
      message: 'Stop angefordert. Der aktuelle Zwischestand wird gespeichert.',
      ...data,
    });
  }

  return request;
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

function persistScraperProfileBlock(runtimeConfig = {}, reason = 'instagram-rate-limit', details = {}) {
  try {
    const blockPath = buildScraperProfileBlockPath(runtimeConfig, pathHelperOptions);
    const blockInfo = {
      profileLabel: normalizeText(String(runtimeConfig.profileLabel || runtimeConfig.profile_label || 'instagram-default')) || null,
      loginUsername: normalizeText(String(runtimeConfig.loginUsername || runtimeConfig.login_username || '')) || null,
      blockedAt: new Date().toISOString(),
      reason: normalizeText(String(reason || 'instagram-rate-limit')) || 'instagram-rate-limit',
      details: {
        relationship: details.relationship || null,
        status: details.status || null,
        source: details.source || null,
        text: normalizeText(String(details.text || '')) || null,
      },
    };

    fs.writeFileSync(blockPath, JSON.stringify(blockInfo, null, 2), 'utf8');
    return blockInfo;
  } catch (error) {
    recordRunDebug('scraper-profile-block-write-failed', {
      error: normalizeText(error.message || String(error)),
      profileLabel: normalizeText(String(runtimeConfig.profileLabel || runtimeConfig.profile_label || 'instagram-default')) || null,
    });
    return null;
  }
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

  const isPrivate = /dieses profil ist privat|this account is private/i.test(bodyText + ' ' + (description || ''));
  const requiresLogin = /melde dich an|anmelden|log in|sign up/i.test(bodyText);
  const usernameSeen =
    bodyText.toLowerCase().includes(username.toLowerCase()) ||
    (ogTitle || '').toLowerCase().includes(username.toLowerCase());
  const hasVisibleCounts = [counts.posts, counts.followers, counts.following].some((value) => Number.isFinite(value));
  const profileVisibility = isPrivate
    ? 'private'
    : ((!requiresLogin && (hasVisibleCounts || usernameSeen)) ? 'public' : 'unknown');

  return {
    bodyTextPreview: bodyText.slice(0, 1200),
    counts,
    description,
    imageCount,
    isPrivate,
    ogImage,
    profileImageUrl: ogImage,
    ogTitle,
    requiresLogin,
    usernameSeen,
    profileVisibility,
    postsCount: Number.isFinite(counts.posts) ? counts.posts : null,
    followersCount: Number.isFinite(counts.followers) ? counts.followers : null,
    followingCount: Number.isFinite(counts.following) ? counts.following : null,
  };
}

function parseInstagramMetricCount(rawValue = '') {
  const value = normalizeText(String(rawValue || '')).toLowerCase();

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
}

function buildRelationshipProgressItems(items, limit = 250) {
  if (!Array.isArray(items)) {
    return [];
  }

  return items
    .slice(-Math.max(1, limit))
    .map((item) => ({
      username: item.username || null,
      displayName: item.displayName || null,
      profileUrl: item.profileUrl || null,
      profileImageUrl: item.profileImageUrl || null,
      profileVisibility: item.profileVisibility || null,
      isPrivate: typeof item.isPrivate === 'boolean' ? item.isPrivate : null,
      postsCount: hasFiniteNumericValue(item.postsCount) ? Number(item.postsCount) : null,
      followersCount: hasFiniteNumericValue(item.followersCount) ? Number(item.followersCount) : null,
      followingCount: hasFiniteNumericValue(item.followingCount) ? Number(item.followingCount) : null,
      hoverCard: item.hoverCard && typeof item.hoverCard === 'object' ? item.hoverCard : null,
      firstSeenAt: item.firstSeenAt || null,
      lastSeenAt: item.lastSeenAt || null,
      sourceLists: Array.isArray(item.sourceLists) ? item.sourceLists : [],
    }))
    .filter((item) => item.username);
}

function buildRelationshipProgressPreview(usersByUsername, limit = 250) {
  if (!(usersByUsername instanceof Map)) {
    return [];
  }

  return buildRelationshipProgressItems(Array.from(usersByUsername.values()), limit);
}

function extractPostMetricFromHtml(html = '', keys = []) {
  for (const key of keys) {
    const directPattern = new RegExp(`"${key}"\\s*:\\s*(\\d+)`, 'i');
    const nestedPattern = new RegExp(`"${key}"\\s*:\\s*\\{[^{}]{0,500}?"count"\\s*:\\s*(\\d+)`, 'i');
    const match = String(html || '').match(directPattern)
      || String(html || '').match(nestedPattern);

    if (match) {
      return Number.parseInt(match[1], 10);
    }
  }

  return null;
}

function extractPostMetricFromText(text = '', patterns = []) {
  for (const pattern of patterns) {
    const match = String(text || '').match(pattern);

    if (!match) {
      continue;
    }

    const count = parseInstagramMetricCount(match[1] || '');

    if (count !== null) {
      return count;
    }
  }

  return null;
}

function normalizeInstagramPostTimestamp(value) {
  if (value === null || value === undefined || value === '') {
    return null;
  }

  const numericValue = Number(value);
  const date = Number.isFinite(numericValue)
    ? new Date(numericValue > 1000000000000 ? numericValue : numericValue * 1000)
    : new Date(String(value));

  return Number.isNaN(date.getTime()) ? null : date.toISOString();
}

function normalizeInstagramPostMediaType(item = {}) {
  const productType = normalizeText(String(item.product_type || item.productType || '')).toLowerCase();
  const mediaType = normalizeText(String(item.media_type || item.mediaType || '')).toLowerCase();

  if (
    ['clips', 'reels', 'reel'].includes(productType)
    || ['reels', 'reel'].includes(mediaType)
    || item.clips_metadata
  ) {
    return 'reel';
  }

  if (['igtv', 'tv'].includes(productType) || ['igtv', 'tv'].includes(mediaType)) {
    return 'tv';
  }

  return 'post';
}

function firstInstagramPostImageUrl(item = {}) {
  const candidates = [
    item.thumbnail_url,
    item.display_url,
    item.image_versions2?.candidates?.[0]?.url,
    item.carousel_media?.[0]?.image_versions2?.candidates?.[0]?.url,
    item.carousel_media?.[0]?.thumbnail_url,
  ];

  return candidates
    .map((candidate) => normalizeText(String(candidate || '')))
    .find(Boolean) || null;
}

function firstInstagramPostVideoUrl(item = {}) {
  const candidates = [
    item.video_url,
    item.video_versions?.[0]?.url,
  ];

  return candidates
    .map((candidate) => normalizeText(String(candidate || '')))
    .find(Boolean) || null;
}

function normalizeInstagramPostMedia(item = {}, position = 0) {
  const previewUrl = firstInstagramPostImageUrl(item);
  const videoUrl = firstInstagramPostVideoUrl(item);
  const mediaType = videoUrl || Number(item.media_type) === 2 ? 'video' : 'image';
  const imageCandidate = item.image_versions2?.candidates?.[0] || {};
  const width = Number(item.original_width || imageCandidate.width || 0);
  const height = Number(item.original_height || imageCandidate.height || 0);
  const durationSeconds = Number(item.video_duration || item.duration || 0);
  const sourceUrl = mediaType === 'video' ? videoUrl : previewUrl;

  if (!sourceUrl && !previewUrl) {
    return null;
  }

  return {
    position,
    mediaType,
    sourceUrl: sourceUrl || previewUrl,
    previewUrl,
    width: Number.isFinite(width) && width > 0 ? width : null,
    height: Number.isFinite(height) && height > 0 ? height : null,
    durationSeconds: Number.isFinite(durationSeconds) && durationSeconds > 0 ? durationSeconds : null,
  };
}

function normalizeInstagramPostMediaCollection(item = {}) {
  const rawMedia = Array.isArray(item.carousel_media) && item.carousel_media.length > 0
    ? item.carousel_media
    : [item];

  return rawMedia
    .map((media, position) => normalizeInstagramPostMedia(media, position))
    .filter(Boolean);
}

function normalizeInstagramTimelinePost(item, username) {
  if (!item || typeof item !== 'object' || Array.isArray(item)) {
    return null;
  }

  const shortcode = normalizeText(String(item.code || item.shortcode || ''));

  if (!/^[A-Za-z0-9_-]{5,}$/.test(shortcode)) {
    return null;
  }

  const targetUsername = normalizeInstagramUsername(username);
  const ownerUsername = normalizeInstagramUsername(
    item.user?.username
      || item.owner?.username
      || item.owner_username
      || '',
  );

  if (ownerUsername && targetUsername && ownerUsername !== targetUsername) {
    return null;
  }

  const mediaType = normalizeInstagramPostMediaType(item);
  const caption = normalizeText(String(
    item.caption?.text
      || item.edge_media_to_caption?.edges?.[0]?.node?.text
      || item.accessibility_caption
      || '',
  )) || null;
  const likesCount = hasFiniteNumericValue(item.like_count)
    ? Number(item.like_count)
    : (hasFiniteNumericValue(item.edge_media_preview_like?.count)
      ? Number(item.edge_media_preview_like.count)
      : null);
  const commentsCount = hasFiniteNumericValue(item.comment_count)
    ? Number(item.comment_count)
    : (hasFiniteNumericValue(item.edge_media_to_comment?.count)
      ? Number(item.edge_media_to_comment.count)
      : null);
  const media = normalizeInstagramPostMediaCollection(item);

  return {
    mediaPk: normalizeText(String(item.pk || item.id || '')) || null,
    shortcode,
    mediaType,
    postUrl: `https://www.instagram.com/${mediaType === 'reel' ? 'reel' : (mediaType === 'tv' ? 'tv' : 'p')}/${shortcode}/`,
    thumbnailUrl: media[0]?.previewUrl || firstInstagramPostImageUrl(item),
    caption,
    publishedAt: normalizeInstagramPostTimestamp(item.taken_at || item.taken_at_timestamp),
    likesCount,
    commentsCount,
    media,
    ownerUsername: ownerUsername || targetUsername || null,
    source: 'timeline-api',
  };
}

function normalizeInstagramEngagementUser(user = {}) {
  const username = normalizeInstagramUsername(user.username || '');
  const instagramUserId = normalizeText(String(user.pk || user.id || '')) || null;

  if (!username && !instagramUserId) {
    return null;
  }

  return {
    instagramUserId,
    username,
    fullName: normalizeText(String(user.full_name || user.fullName || '')) || null,
    profileImageUrl: normalizeText(String(user.profile_pic_url || user.profileImageUrl || '')) || null,
    isVerified: typeof user.is_verified === 'boolean' ? user.is_verified : null,
  };
}

function normalizeInstagramComment(comment = {}, parentCommentId = null) {
  const instagramCommentId = normalizeText(String(comment.pk || comment.id || ''));
  const text = normalizeText(String(comment.text || ''));
  const user = normalizeInstagramEngagementUser(comment.user || {});

  if (!instagramCommentId || !text) {
    return null;
  }

  return {
    instagramCommentId,
    parentInstagramCommentId: parentCommentId,
    ...user,
    text,
    likesCount: hasFiniteNumericValue(comment.comment_like_count)
      ? Number(comment.comment_like_count)
      : (hasFiniteNumericValue(comment.like_count) ? Number(comment.like_count) : null),
    publishedAt: normalizeInstagramPostTimestamp(comment.created_at_utc || comment.created_at),
    rawComment: comment,
  };
}

function flattenInstagramComments(comments = []) {
  const flattened = [];

  for (const comment of Array.isArray(comments) ? comments : []) {
    const normalized = normalizeInstagramComment(comment);

    if (!normalized) {
      continue;
    }

    flattened.push(normalized);

    const replies = comment.preview_child_comments
      || comment.child_comments
      || comment.threading_info?.child_comments
      || [];

    for (const reply of Array.isArray(replies) ? replies : []) {
      const normalizedReply = normalizeInstagramComment(reply, normalized.instagramCommentId);

      if (normalizedReply) {
        flattened.push(normalizedReply);
      }
    }
  }

  return flattened;
}

function uniqueEngagementKey(user = {}) {
  return user?.instagramUserId || user?.username || null;
}

function addLikeToMap(likesByKey, like = {}, maxLikes = 250) {
  const key = uniqueEngagementKey(like);

  if (!key || likesByKey.has(key) || likesByKey.size >= maxLikes) {
    return false;
  }

  likesByKey.set(key, like);

  return true;
}

function normalizeCommentTextForComparison(value = '') {
  return normalizeText(String(value || ''))
    .replace(/\s+/g, ' ')
    .trim()
    .toLowerCase();
}

function looksLikeNonCommentText(value = '') {
  const text = normalizeCommentTextForComparison(value);

  if (!text) {
    return true;
  }

  return /^(antworten|reply|kommentar|comment|translation|uebersetzung|übersetzung|mehr anzeigen|show more|view replies|weitere antworten)(?:\b|$)/i.test(text)
    || /^(gef[aä]llt|likes?)\b/i.test(text)
    || /^(?:\d+\s*)?(?:antworten|replies|comments?|kommentare)$/i.test(text);
}

function addCommentToMap(commentsById, comment = {}, maxComments = 250) {
  const normalizedText = normalizeCommentTextForComparison(comment?.text || '');

  if (!comment?.instagramCommentId || !normalizedText || looksLikeNonCommentText(normalizedText) || commentsById.has(comment.instagramCommentId) || commentsById.size >= maxComments) {
    return false;
  }

  const duplicate = Array.from(commentsById.values()).some((existing) => {
    if (normalizeCommentTextForComparison(existing?.text || '') !== normalizedText) {
      return false;
    }

    const existingUserKey = existing?.instagramUserId || existing?.username || '';
    const commentUserKey = comment?.instagramUserId || comment?.username || '';

    return existingUserKey === commentUserKey
      && (existing?.publishedAt || '') === (comment?.publishedAt || '');
  });

  if (duplicate) {
    return false;
  }

  commentsById.set(comment.instagramCommentId, comment);

  return true;
}

async function fetchInstagramApiJson(page, endpoint) {
  return page.evaluate(async (requestEndpoint) => {
    try {
      const response = await fetch(requestEndpoint, {
        credentials: 'include',
        headers: {
          Accept: '*/*',
          'X-IG-App-ID': '936619743392459',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });
      const rawBody = await response.text();
      const normalizedBody = rawBody.replace(/^for\s*\(\s*;\s*;\s*\)\s*;\s*/, '');
      let payload = null;

      try {
        payload = normalizedBody ? JSON.parse(normalizedBody) : null;
      } catch (error) {
        return {
          ok: false,
          status: response.status,
          error: `invalid-json: ${error.message}`,
        };
      }

      return {
        ok: response.ok,
        status: response.status,
        payload,
        error: response.ok ? null : (payload?.message || `HTTP ${response.status}`),
      };
    } catch (error) {
      return {
        ok: false,
        status: null,
        payload: null,
        error: error.message,
      };
    }
  }, endpoint).catch((error) => ({
    ok: false,
    status: null,
    payload: null,
    error: error.message,
  }));
}

async function resolveInstagramPostMediaPk(page, post = {}) {
  if (normalizeText(String(post.mediaPk || ''))) {
    return normalizeText(String(post.mediaPk));
  }

  if (!post.shortcode) {
    return null;
  }

  const response = await fetchInstagramApiJson(
    page,
    `/api/v1/media/shortcode/${encodeURIComponent(post.shortcode)}/info/`,
  );

  return response.ok
    ? normalizeText(String(response.payload?.items?.[0]?.pk || response.payload?.items?.[0]?.id || '')) || null
    : null;
}

async function expandVisibleInstagramPostReplies(page, maxRounds = 8) {
  let clickedTotal = 0;

  for (let round = 0; round < maxRounds; round += 1) {
    const clicked = await page.evaluate(() => {
      const normalizeElementText = (value = '') => String(value || '').replace(/\s+/g, ' ').trim();
      const isVisible = (element) => {
        const rect = element.getBoundingClientRect();
        const style = window.getComputedStyle(element);

        return rect.width > 2
          && rect.height > 2
          && style.display !== 'none'
          && style.visibility !== 'hidden'
          && style.opacity !== '0';
      };
      const replyPattern = /antwort(?:en)?\s+(?:anzeigen|ansehen)|weitere\s+antwort|view\s+(?:all\s+)?(?:\d+\s+)?repl|show\s+(?:all\s+)?(?:\d+\s+)?repl/i;
      const button = Array.from(document.querySelectorAll('button, [role="button"], span, div'))
        .find((element) => isVisible(element) && replyPattern.test(normalizeElementText(element.innerText || element.textContent || '')));

      if (!button) {
        return false;
      }

      button.click();

      return true;
    }).catch(() => false);

    if (!clicked) {
      break;
    }

    clickedTotal++;
    await sleep(650);
  }

  return clickedTotal;
}

async function collectInstagramPostCommentsFromModal(page, post = {}, runtimeConfig = {}) {
  const maxComments = Math.max(1, Number(runtimeConfig.postScanMaxCommentsPerPost || 250));
  const commentsById = new Map();
  let complete = false;
  let truncated = false;
  let previousCount = -1;
  let staleRounds = 0;
  const maxRounds = Math.max(8, Math.min(80, Number(runtimeConfig.postScanCommentDialogMaxScrollRounds || 40)));

  await expandVisibleInstagramPostReplies(page, 6);

  for (let round = 0; round < maxRounds && commentsById.size < maxComments; round += 1) {
    const collected = await page.evaluate(({ limit, shortcode }) => {
      const normalizeElementText = (value = '') => String(value || '').replace(/\s+/g, ' ').trim();
      const normalizeUsername = (value = '') => normalizeElementText(value).replace(/^@+/, '').toLowerCase();
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
      const simpleHash = (value = '') => {
        let hash = 0;
        const text = String(value || '');

        for (let index = 0; index < text.length; index += 1) {
          hash = ((hash << 5) - hash + text.charCodeAt(index)) | 0;
        }

        return Math.abs(hash).toString(36);
      };
      const isVisible = (element) => {
        const rect = element.getBoundingClientRect();
        const style = window.getComputedStyle(element);

        return rect.width > 4
          && rect.height > 4
          && style.display !== 'none'
          && style.visibility !== 'hidden'
          && style.opacity !== '0';
      };
      const parseCount = (rawValue = '') => {
        const value = normalizeElementText(rawValue).toLowerCase();
        const match = value.match(/([\d.,\s]+(?:k|m|mio|tsd)?)\s*(?:gef[aä]llt|likes?)/i);

        if (!match) {
          return null;
        }

        let multiplier = 1;
        const token = match[1].toLowerCase();

        if (/\bmio\b|\bm\b/.test(token)) {
          multiplier = 1000000;
        } else if (/\bk\b|\btsd\b/.test(token)) {
          multiplier = 1000;
        }

        const numericPart = token.replace(/[^\d.,]/g, '');

        if (!numericPart) {
          return null;
        }

        if (multiplier === 1) {
          const digits = numericPart.replace(/[^\d]/g, '');

          return digits ? Number.parseInt(digits, 10) : null;
        }

        const parsed = Number.parseFloat(numericPart.replace(',', '.'));

        return Number.isFinite(parsed) ? Math.round(parsed * multiplier) : null;
      };
      const isMetaLine = (line = '') => {
        const text = normalizeElementText(line).toLowerCase();

        return !text
          || /^(antworten|reply|mehr|more|translation|uebersetzung|übersetzung|original anzeigen|see translation|view replies|weitere antworten)$/i.test(text)
          || /^(gef[aä]llt|like|likes?)\b/i.test(text)
          || /^\d+\s*(?:min|std|h|d|tag|tage|w|wo|j|y)\b/i.test(text)
          || /^(?:antworten|reply)\s*(?:anzeigen|ansehen)?/i.test(text)
          || /^(?:\d+\s*)?(?:antworten|replies|comments?|kommentare)$/i.test(text);
      };
      const compactDuplicatedText = (value = '') => {
        const text = normalizeElementText(value);
        const evenLength = text.length % 2 === 0;

        if (evenLength) {
          const midpoint = text.length / 2;
          const left = text.slice(0, midpoint).trim();
          const right = text.slice(midpoint).trim();

          if (left && left.toLowerCase() === right.toLowerCase()) {
            return left;
          }
        }

        const words = text.split(' ');

        if (words.length >= 2 && words.length % 2 === 0) {
          const midpoint = words.length / 2;
          const left = words.slice(0, midpoint).join(' ');
          const right = words.slice(midpoint).join(' ');

          if (left.toLowerCase() === right.toLowerCase()) {
            return left;
          }
        }

        return text;
      };
      const profileFromAnchor = (anchor) => {
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

        const username = normalizeUsername(parts[0]);

        return /^[a-z0-9._]+$/i.test(username) ? username : null;
      };
      const dialog = document.querySelector('div[role="dialog"]') || document.body;
      const anchors = Array.from(dialog.querySelectorAll('a[href]')).filter(isVisible);
      const rows = [];
      const seenRows = new Set();

      for (const anchor of anchors) {
        if (rows.length >= limit) {
          break;
        }

        const username = profileFromAnchor(anchor);

        if (!username) {
          continue;
        }

        let row = anchor.closest('li') || anchor.closest('article') || anchor.closest('div[role="button"]') || anchor.closest('div');

        for (let element = anchor; element && element !== dialog; element = element.parentElement) {
          const text = normalizeElementText(element.innerText || element.textContent || '');
          const rect = element.getBoundingClientRect();
          const linkCount = element.querySelectorAll('a[href]').length;
          const timeCount = element.querySelectorAll('time').length;

          if (
            text.toLowerCase().includes(username)
            && text.length >= username.length + 2
            && text.length <= 900
            && rect.width >= 120
            && rect.height >= 24
            && linkCount <= 4
            && timeCount <= 2
          ) {
            row = element;
            break;
          }
        }

        if (!row || seenRows.has(row)) {
          continue;
        }

        seenRows.add(row);
        const rawRowText = row.innerText || row.textContent || '';
        const rowText = normalizeElementText(rawRowText);

        if (!rowText || !rowText.toLowerCase().includes(username)) {
          continue;
        }

        if (/^(gef[aä]llt|likes?)\b/i.test(rowText) || /^@\w/.test(rowText) || rowText.length > 900) {
          continue;
        }

        const textLines = String(rawRowText || '')
          .split(/\n| {2,}/)
          .map((line) => normalizeElementText(line))
          .filter(Boolean);
        const fullName = textLines.find((line) => {
          const normalized = normalizeUsername(line);

          return normalized !== username
            && !isMetaLine(line)
            && line.length <= 120;
        }) || null;
        const lineText = compactDuplicatedText(textLines
          .filter((line) => normalizeUsername(line) !== username && !isMetaLine(line))
          .join(' ')
          .trim());
        const stripped = compactDuplicatedText(rowText
          .replace(new RegExp(`^${username}\\b`, 'i'), '')
          .replace(/\b(?:antworten|reply)\b.*$/i, '')
          .replace(/\b(?:gef[aä]llt|like|likes?)[^.!?\n]{0,80}$/i, '')
          .replace(/\b\d+\s*(?:min|std|h|d|tag|tage|w|wo|j|y)\b/ig, '')
          .trim());
        const text = lineText || stripped;

        if (!text || text.length < 1 || isMetaLine(text)) {
          continue;
        }

        const image = Array.from(row.querySelectorAll('img')).find((candidate) => {
          const rect = candidate.getBoundingClientRect();
          const src = candidate.currentSrc || candidate.src || candidate.getAttribute('src') || '';

          return src !== ''
            && rect.width > 12
            && rect.height > 12
            && !/emoji|sprite|blank|transparent/i.test(src);
        }) || null;
        const timeElement = row.querySelector('time[datetime]');
        const publishedAt = timeElement?.getAttribute('datetime') || null;
        const ariaLabel = anchor.getAttribute('aria-label') || '';
        const parentContainer = row.parentElement?.closest('li, article, div') || null;
        const parentText = parentContainer && parentContainer !== row
          ? normalizeElementText(parentContainer.innerText || parentContainer.textContent || '')
          : '';
        const parentUsernameAnchor = parentContainer && parentContainer !== row
          ? Array.from(parentContainer.querySelectorAll('a[href]')).map(profileFromAnchor).find(Boolean)
          : null;
        const parentInstagramCommentId = parentUsernameAnchor && parentUsernameAnchor !== username && parentText.includes(rowText)
          ? `ui:${shortcode || 'post'}:${parentUsernameAnchor}:${simpleHash(parentText.replace(rowText, '').slice(0, 500))}`
          : null;
        const stableIdentity = `${username}:${publishedAt || ''}:${text}`;
        const instagramCommentId = row.getAttribute('data-comment-id')
          || row.getAttribute('data-id')
          || `ui:${shortcode || 'post'}:${username}:${simpleHash(stableIdentity)}`;

        rows.push({
          instagramCommentId,
          parentInstagramCommentId,
          instagramUserId: null,
          username,
          fullName,
          profileImageUrl: image ? (image.currentSrc || image.src || image.getAttribute('src') || null) : null,
          isVerified: /verified|verifiziert/i.test(ariaLabel) ? true : null,
          text,
          likesCount: parseCount(rowText),
          publishedAt,
          rawComment: {
            source: 'ui-post-modal',
            rowText,
          },
        });
      }

      const scrollTargets = Array.from(dialog.querySelectorAll('div, ul, section, article'))
        .filter((element) => element.scrollHeight > element.clientHeight + 40);
      const scrollTarget = scrollTargets
        .sort((left, right) => (right.scrollHeight - right.clientHeight) - (left.scrollHeight - left.clientHeight))[0] || document.scrollingElement;
      const before = scrollTarget ? scrollTarget.scrollTop : window.scrollY;
      const step = scrollTarget
        ? Math.max(420, scrollTarget.clientHeight * 0.8)
        : Math.max(420, window.innerHeight * 0.8);

      if (scrollTarget) {
        scrollTarget.scrollTop = Math.min(scrollTarget.scrollTop + step, scrollTarget.scrollHeight);
      } else {
        window.scrollBy(0, step);
      }

      const after = scrollTarget ? scrollTarget.scrollTop : window.scrollY;
      const atBottom = scrollTarget
        ? after + scrollTarget.clientHeight >= scrollTarget.scrollHeight - 8
        : after + window.innerHeight >= document.documentElement.scrollHeight - 8;

      return {
        comments: rows,
        scrolled: after > before,
        atBottom,
      };
    }, {
      limit: maxComments,
      shortcode: post.shortcode || null,
    }).catch(() => ({
      comments: [],
      scrolled: false,
      atBottom: false,
    }));

    for (const comment of collected.comments || []) {
      addCommentToMap(commentsById, comment, maxComments);
    }

    await expandVisibleInstagramPostReplies(page, 3);

    if (commentsById.size >= maxComments) {
      truncated = true;
      break;
    }

    if (commentsById.size === previousCount) {
      staleRounds++;
    } else {
      staleRounds = 0;
      previousCount = commentsById.size;
    }

    if (collected.atBottom || (!collected.scrolled && staleRounds >= 2)) {
      complete = true;
      break;
    }

    await sleep(550);
  }

  return {
    comments: Array.from(commentsById.values()),
    complete: complete && !truncated,
    truncated,
  };
}

async function openInstagramPostLikesDialog(page) {
  const clicked = await page.evaluate(() => {
    const normalizeElementText = (value = '') => String(value || '').replace(/\s+/g, ' ').trim();
    const isVisible = (element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);

      return rect.width > 4
        && rect.height > 4
        && style.display !== 'none'
        && style.visibility !== 'hidden'
        && style.opacity !== '0';
    };
    const likePattern = /(?:[\d.,\s]+(?:k|m|mio|tsd)?\s*)?(?:gef[aä]llt|likes?)|anderen gef[aä]llt|liked by/i;
    const candidates = Array.from(document.querySelectorAll('a, button, [role="button"], span, div'))
      .filter((element) => isVisible(element) && likePattern.test(normalizeElementText(element.innerText || element.textContent || '')))
      .map((element) => {
        const clickable = element.closest('a, button, [role="button"]') || element;
        const text = normalizeElementText(element.innerText || element.textContent || '');
        const rect = clickable.getBoundingClientRect();
        let score = 0;

        if (/[\d.,\s]+(?:k|m|mio|tsd)?\s*(?:gef[aä]llt|likes?)/i.test(text)) {
          score += 80;
        }

        if (/anderen gef[aä]llt|liked by/i.test(text)) {
          score += 50;
        }

        if (clickable.tagName.toLowerCase() === 'a' || clickable.tagName.toLowerCase() === 'button' || clickable.getAttribute('role') === 'button') {
          score += 25;
        }

        if (rect.top > window.innerHeight * 0.25) {
          score += 5;
        }

        return { clickable, score };
      })
      .sort((left, right) => right.score - left.score);
    const target = candidates[0]?.clickable || null;

    if (!target) {
      return false;
    }

    target.click();

    return true;
  }).catch(() => false);

  if (!clicked) {
    return false;
  }

  await sleep(1200);

  return page.evaluate(() => {
    const dialog = document.querySelector('div[role="dialog"]');
    const text = String(dialog?.innerText || dialog?.textContent || '').replace(/\s+/g, ' ').trim();

    return Boolean(dialog) && /gef[aä]llt|likes?|abonnieren|follow/i.test(text);
  }).catch(() => false);
}

async function collectInstagramPostLikesFromDialog(page, runtimeConfig = {}) {
  const maxLikes = Math.max(1, Number(runtimeConfig.postScanMaxLikesPerPost || 250));
  const likesByKey = new Map();
  let complete = false;
  let previousCount = -1;
  let staleRounds = 0;
  const maxRounds = Math.max(8, Math.min(80, Number(runtimeConfig.postScanLikeDialogMaxScrollRounds || 40)));

  for (let round = 0; round < maxRounds && likesByKey.size < maxLikes; round += 1) {
    const collected = await page.evaluate(({ limit }) => {
      const normalizeElementText = (value = '') => String(value || '').replace(/\s+/g, ' ').trim();
      const normalizeUsername = (value = '') => normalizeElementText(value).replace(/^@+/, '').toLowerCase();
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
      const isVisible = (element) => {
        const rect = element.getBoundingClientRect();
        const style = window.getComputedStyle(element);

        return rect.width > 4
          && rect.height > 4
          && style.display !== 'none'
          && style.visibility !== 'hidden'
          && style.opacity !== '0';
      };
      const dialogs = Array.from(document.querySelectorAll('div[role="dialog"]'));
      const dialog = dialogs[dialogs.length - 1] || document.body;
      const rows = [];
      const seen = new Set();
      const findEntryContainer = (anchor, username) => {
        for (let element = anchor; element && element !== dialog; element = element.parentElement) {
          const rect = element.getBoundingClientRect();
          const text = normalizeElementText(element.innerText || element.textContent || '');
          const hasProfileImage = Boolean(Array.from(element.querySelectorAll('img')).find((image) => {
            const imageRect = image.getBoundingClientRect();

            return imageRect.width > 12 && imageRect.height > 12;
          }));

          if (rect.width >= 120
            && rect.height >= 28
            && rect.height <= 260
            && text.length <= 700
            && text.toLowerCase().includes(username)
            && hasProfileImage) {
            return element;
          }

          if (rect.height > 420 || text.length > 1200) {
            break;
          }
        }

        return anchor.closest('div[role="button"], li, article, div') || anchor;
      };

      for (const anchor of Array.from(dialog.querySelectorAll('a[href]')).filter(isVisible)) {
        if (rows.length >= limit) {
          break;
        }

        let pathname = '';

        try {
          pathname = new URL(anchor.getAttribute('href'), window.location.origin).pathname;
        } catch (error) {
          continue;
        }

        const parts = pathname.split('/').filter(Boolean);

        if (parts.length !== 1 || reservedPaths.has(parts[0])) {
          continue;
        }

        const username = normalizeUsername(parts[0]);

        if (!/^[a-z0-9._]+$/i.test(username) || seen.has(username)) {
          continue;
        }

        seen.add(username);
        const row = findEntryContainer(anchor, username);
        const rowText = normalizeElementText(row.innerText || row.textContent || '');
        const textLines = rowText
          .split('\n')
          .map((line) => normalizeElementText(line))
          .filter(Boolean);
        const image = Array.from(row.querySelectorAll('img')).find((candidate) => {
          const rect = candidate.getBoundingClientRect();
          const src = candidate.currentSrc || candidate.src || candidate.getAttribute('src') || '';

          return src !== ''
            && rect.width > 12
            && rect.height > 12
            && !/emoji|sprite|blank|transparent/i.test(src);
        }) || null;

        rows.push({
          instagramUserId: null,
          username,
          fullName: textLines.find((line) => normalizeUsername(line) !== username && !/^(folgen|follow|abonniert)$/i.test(line)) || null,
          profileImageUrl: image ? (image.currentSrc || image.src || image.getAttribute('src') || null) : null,
          isVerified: /verified|verifiziert/i.test(anchor.getAttribute('aria-label') || rowText) ? true : null,
          rawLike: {
            source: 'ui-like-modal',
            rowText,
          },
        });
      }

      const scrollTargets = Array.from(dialog.querySelectorAll('div, ul, section'))
        .filter((element) => element.scrollHeight > element.clientHeight + 40);
      const scrollTarget = scrollTargets
        .sort((left, right) => (right.scrollHeight - right.clientHeight) - (left.scrollHeight - left.clientHeight))[0] || document.scrollingElement;
      const before = scrollTarget ? scrollTarget.scrollTop : window.scrollY;
      const step = scrollTarget
        ? Math.max(420, scrollTarget.clientHeight * 0.85)
        : Math.max(420, window.innerHeight * 0.85);

      if (scrollTarget) {
        scrollTarget.scrollTop = Math.min(scrollTarget.scrollTop + step, scrollTarget.scrollHeight);
      } else {
        window.scrollBy(0, step);
      }

      const after = scrollTarget ? scrollTarget.scrollTop : window.scrollY;
      const atBottom = scrollTarget
        ? after + scrollTarget.clientHeight >= scrollTarget.scrollHeight - 8
        : after + window.innerHeight >= document.documentElement.scrollHeight - 8;

      return {
        likes: rows,
        scrolled: after > before,
        atBottom,
      };
    }, { limit: maxLikes }).catch(() => ({
      likes: [],
      scrolled: false,
      atBottom: false,
    }));

    for (const like of collected.likes || []) {
      addLikeToMap(likesByKey, like, maxLikes);
    }

    if (likesByKey.size === previousCount) {
      staleRounds++;
    } else {
      staleRounds = 0;
      previousCount = likesByKey.size;
    }

    if (likesByKey.size >= maxLikes) {
      break;
    }

    if (collected.atBottom || (!collected.scrolled && staleRounds >= 2)) {
      complete = true;
      break;
    }

    await sleep(550);
  }

  return {
    likes: Array.from(likesByKey.values()),
    complete,
  };
}

async function openInstagramPostLikesDialogReliable(page) {
  const clickResult = await page.evaluate(() => {
    const normalizeElementText = (value = '') => String(value || '').replace(/\s+/g, ' ').trim();
    const isVisible = (element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);

      return rect.width > 4
        && rect.height > 4
        && style.display !== 'none'
        && style.visibility !== 'hidden'
        && style.opacity !== '0';
    };
    const dialogs = Array.from(document.querySelectorAll('div[role="dialog"]'));
    const surface = dialogs[dialogs.length - 1] || document.querySelector('article') || document.body;
    const beforeDialogCount = dialogs.length;
    const germanLikeWord = 'gef(?:a|\\u00e4|\\u00c3\\u00a4)llt';
    const numericLikePattern = new RegExp(`(?:^|\\s)[\\d.,\\s]+(?:k|m|mio|tsd)?\\s*(?:${germanLikeWord}|likes?)(?:\\s|$)`, 'i');
    const socialLikePattern = new RegExp(`(?:anderen\\s+${germanLikeWord}|${germanLikeWord}\\s+.+|liked by|and others|likes?)`, 'i');
    const rejectPattern = /antworten|reply|kommentar|comment|translation/i;
    const candidates = Array.from(surface.querySelectorAll('a, button, [role="button"], span, div'))
      .filter((element) => {
        if (!isVisible(element)) {
          return false;
        }

        const text = normalizeElementText(element.innerText || element.textContent || '');

        return text.length > 0
          && text.length <= 220
          && !rejectPattern.test(text)
          && (numericLikePattern.test(text) || socialLikePattern.test(text));
      })
      .map((element) => {
        const clickable = element.closest('a, button, [role="button"]') || element;
        const text = normalizeElementText(element.innerText || element.textContent || '');
        const rect = clickable.getBoundingClientRect();
        let score = 0;

        if (numericLikePattern.test(text)) {
          score += 120;
        }

        if (/liked by|anderen/i.test(text)) {
          score += 70;
        }

        if (clickable.tagName.toLowerCase() === 'a' || clickable.tagName.toLowerCase() === 'button' || clickable.getAttribute('role') === 'button') {
          score += 35;
        }

        if (rect.top > window.innerHeight * 0.35) {
          score += 15;
        }

        if (text.length <= 80) {
          score += 10;
        }

        return { clickable, score };
      })
      .sort((left, right) => right.score - left.score);
    const target = candidates[0]?.clickable || null;

    if (!target) {
      return {
        clicked: false,
        beforeDialogCount,
      };
    }

    target.dispatchEvent(new MouseEvent('mouseover', { bubbles: true, cancelable: true, view: window }));
    target.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true, view: window }));
    target.dispatchEvent(new MouseEvent('mouseup', { bubbles: true, cancelable: true, view: window }));
    target.click();

    return {
      clicked: true,
      beforeDialogCount,
    };
  }).catch(() => false);

  if (!clickResult || !clickResult.clicked) {
    return false;
  }

  const beforeDialogCount = Number(clickResult.beforeDialogCount || 0);

  try {
    await page.waitForFunction((initialDialogCount) => {
      const normalizeElementText = (value = '') => String(value || '').replace(/\s+/g, ' ').trim();
      const dialogs = Array.from(document.querySelectorAll('div[role="dialog"]'));
      const dialog = dialogs[dialogs.length - 1] || null;

      if (!dialog) {
        return false;
      }

      const text = normalizeElementText(dialog.innerText || dialog.textContent || '');
      const profileLinks = Array.from(dialog.querySelectorAll('a[href]')).filter((anchor) => {
        try {
          const parts = new URL(anchor.getAttribute('href'), window.location.origin).pathname.split('/').filter(Boolean);

          return parts.length === 1 && /^[a-z0-9._]+$/i.test(parts[0]);
        } catch (error) {
          return false;
        }
      }).length;
      const likesDialogPattern = new RegExp('gef(?:a|\\u00e4|\\u00c3\\u00a4)llt|likes?|abonnieren|folgen|follow', 'i');

      const looksLikeReusedLikesDialog = profileLinks >= 2
        && likesDialogPattern.test(text)
        && !/antworten|reply|kommentar|comment/i.test(text);

      return dialogs.length > initialDialogCount || looksLikeReusedLikesDialog;
    }, { timeout: 4500 }, beforeDialogCount);

    return true;
  } catch (error) {
    return page.evaluate((initialDialogCount) => {
      const dialogs = Array.from(document.querySelectorAll('div[role="dialog"]'));

      return dialogs.length > initialDialogCount;
    }, beforeDialogCount).catch(() => false);
  }
}

async function collectInstagramPostEngagementFromUi(page, post = {}, runtimeConfig = {}) {
  const result = {
    likes: [],
    comments: [],
    likesComplete: false,
    commentsComplete: false,
    errors: [],
  };

  if (post.postUrl && !page.url().includes(`/${post.shortcode || ''}`)) {
    const navigation = await navigateWithSoftTimeout(page, post.postUrl, runtimeConfig);

    if (!navigation.ok) {
      result.errors.push(`ui-navigation: ${navigation.error || 'unbekannter Fehler'}`);

      return result;
    }
  }

  await sleep(900);
  await expandVisibleInstagramPostReplies(page, 6);

  const comments = await collectInstagramPostCommentsFromModal(page, post, runtimeConfig);
  result.comments = comments.comments;
  result.commentsComplete = comments.complete;

  const likesDialogOpened = await openInstagramPostLikesDialogReliable(page);

  if (likesDialogOpened) {
    const likes = await collectInstagramPostLikesFromDialog(page, runtimeConfig);
    result.likes = likes.likes;
    result.likesComplete = likes.complete;
    await closeInstagramDialog(page);
  } else {
    result.errors.push('ui-likes-dialog-unavailable');
  }

  return result;
}

async function collectInstagramPostEngagement(page, post = {}, runtimeConfig = {}) {
  const maxLikes = Math.max(1, Number(runtimeConfig.postScanMaxLikesPerPost || 250));
  const maxComments = Math.max(1, Number(runtimeConfig.postScanMaxCommentsPerPost || 250));
  const mediaPk = await resolveInstagramPostMediaPk(page, post);
  const likesByKey = new Map();
  const commentsById = new Map();
  let likesComplete = false;
  let commentsComplete = false;
  let rateLimited = false;
  let commentsTruncated = false;
  let childCommentsComplete = true;
  const errors = [];
  const expectedComments = hasFiniteNumericValue(post.commentsCount)
    ? Number(post.commentsCount)
    : null;

  if (!mediaPk) {
    return {
      mediaPk: null,
      likes: [],
      comments: [],
      likesComplete,
      commentsComplete,
      rateLimited,
      errors: ['media-pk-unavailable'],
    };
  }

  const likesResponse = await fetchInstagramApiJson(
    page,
    `/api/v1/media/${encodeURIComponent(mediaPk)}/likers/`,
  );

  if (likesResponse.ok) {
    const rawUsers = likesResponse.payload?.users || likesResponse.payload?.likers || [];

    for (const rawUser of Array.isArray(rawUsers) ? rawUsers : []) {
      const user = normalizeInstagramEngagementUser(rawUser);
      if (user) {
        addLikeToMap(likesByKey, {
          ...user,
          rawLike: rawUser,
        }, maxLikes);
      }
    }

    const expectedLikes = hasFiniteNumericValue(post.likesCount) ? Number(post.likesCount) : null;
    likesComplete = rawUsers.length <= maxLikes
      && (expectedLikes === null || rawUsers.length >= expectedLikes);
  } else {
    rateLimited = likesResponse.status === 429;
    errors.push(`likes: ${likesResponse.error || 'unbekannter Fehler'}`);
  }

  let cursor = null;

  for (let pageIndex = 0; pageIndex < 50 && commentsById.size < maxComments; pageIndex += 1) {
    const query = new URLSearchParams({
      can_support_threading: 'true',
      permalink_enabled: 'false',
    });

    if (cursor) {
      query.set('min_id', cursor);
    }

    const commentsResponse = await fetchInstagramApiJson(
      page,
      `/api/v1/media/${encodeURIComponent(mediaPk)}/comments/?${query.toString()}`,
    );

    if (!commentsResponse.ok) {
      rateLimited = rateLimited || commentsResponse.status === 429;
      errors.push(`comments: ${commentsResponse.error || 'unbekannter Fehler'}`);
      break;
    }

    const rawComments = commentsResponse.payload?.comments || [];

    for (const comment of flattenInstagramComments(rawComments)) {
      if (commentsById.size >= maxComments) {
        commentsTruncated = true;
        break;
      }

      addCommentToMap(commentsById, comment, maxComments);
    }

    for (const rawComment of Array.isArray(rawComments) ? rawComments : []) {
      const parentId = normalizeText(String(rawComment.pk || rawComment.id || ''));
      const expectedChildren = Number(rawComment.child_comment_count || 0);
      const previewChildren = rawComment.preview_child_comments
        || rawComment.child_comments
        || rawComment.threading_info?.child_comments
        || [];

      if (!parentId || expectedChildren <= previewChildren.length) {
        continue;
      }

      let childCursor = null;
      let observedChildren = previewChildren.length;

      for (let childPage = 0; childPage < 20 && commentsById.size < maxComments; childPage += 1) {
        const childQuery = new URLSearchParams();

        if (childCursor) {
          childQuery.set('max_id', childCursor);
        }

        const childResponse = await fetchInstagramApiJson(
          page,
          `/api/v1/media/${encodeURIComponent(mediaPk)}/comments/${encodeURIComponent(parentId)}/child_comments/?${childQuery.toString()}`,
        );

        if (!childResponse.ok) {
          rateLimited = rateLimited || childResponse.status === 429;
          childCommentsComplete = false;
          errors.push(`comment-replies: ${childResponse.error || 'unbekannter Fehler'}`);
          break;
        }

        const rawChildren = childResponse.payload?.child_comments || childResponse.payload?.comments || [];

        for (const rawChild of Array.isArray(rawChildren) ? rawChildren : []) {
          const child = normalizeInstagramComment(rawChild, parentId);

          if (child) {
            addCommentToMap(commentsById, child, maxComments);
          }
        }

        observedChildren += rawChildren.length;
        const nextChildCursor = normalizeText(String(
          childResponse.payload?.next_max_id
            || childResponse.payload?.next_min_id
            || '',
        )) || null;

        if (!nextChildCursor || nextChildCursor === childCursor || observedChildren >= expectedChildren) {
          break;
        }

        childCursor = nextChildCursor;
      }

      if (observedChildren < expectedChildren) {
        childCommentsComplete = false;
      }

      if (commentsById.size >= maxComments) {
        commentsTruncated = true;
        break;
      }
    }

    const nextCursor = normalizeText(String(
      commentsResponse.payload?.next_min_id
        || commentsResponse.payload?.next_max_id
        || '',
    )) || null;

    if (!nextCursor || nextCursor === cursor) {
      commentsComplete = !commentsTruncated
        && childCommentsComplete
        && (expectedComments === null || commentsById.size >= expectedComments);
      break;
    }

    cursor = nextCursor;
    await sleep(150);
  }

  const expectedLikes = hasFiniteNumericValue(post.likesCount) ? Number(post.likesCount) : null;
  const uiFallbackEnabled = runtimeConfig.postScanUiFallbackEnabled !== false
    && runtimeConfig.post_scan_ui_fallback_enabled !== false;
  const shouldUseUiFallback = uiFallbackEnabled
    && post.postUrl
    && (
      (expectedLikes !== null && expectedLikes > 0 && likesByKey.size < Math.min(expectedLikes, maxLikes))
      || (expectedLikes === null && likesByKey.size === 0)
      || (expectedComments !== null && expectedComments > 0 && commentsById.size < Math.min(expectedComments, maxComments))
      || errors.length > 0
    );

  if (shouldUseUiFallback) {
    const uiEngagement = await collectInstagramPostEngagementFromUi(page, post, runtimeConfig);

    for (const like of uiEngagement.likes || []) {
      addLikeToMap(likesByKey, like, maxLikes);
    }

    for (const comment of uiEngagement.comments || []) {
      addCommentToMap(commentsById, comment, maxComments);
    }

    likesComplete = likesComplete || uiEngagement.likesComplete
      || (expectedLikes !== null && likesByKey.size >= Math.min(expectedLikes, maxLikes));
    commentsComplete = commentsComplete || uiEngagement.commentsComplete
      || (expectedComments !== null && commentsById.size >= Math.min(expectedComments, maxComments));

    if (uiEngagement.errors?.length) {
      errors.push(...uiEngagement.errors);
    }
  }

  return {
    mediaPk,
    likes: Array.from(likesByKey.values()),
    comments: Array.from(commentsById.values()),
    likesComplete,
    commentsComplete,
    rateLimited,
    errors,
  };
}

async function fetchInstagramTimelinePage(page, username, cursor = null, count = 12) {
  return page.evaluate(async ({ profileUsername, maxId, pageSize }) => {
    const query = new URLSearchParams({
      count: String(pageSize),
    });

    if (maxId) {
      query.set('max_id', maxId);
    }

    const endpoint = `/api/v1/feed/user/${encodeURIComponent(profileUsername)}/username/?${query.toString()}`;

    try {
      const response = await fetch(endpoint, {
        credentials: 'include',
        headers: {
          Accept: '*/*',
          'X-IG-App-ID': '936619743392459',
          'X-Requested-With': 'XMLHttpRequest',
        },
      });
      const rawBody = await response.text();
      const normalizedBody = rawBody.replace(/^for\s*\(\s*;\s*;\s*\)\s*;\s*/, '');
      let payload = null;

      try {
        payload = normalizedBody ? JSON.parse(normalizedBody) : null;
      } catch (error) {
        return {
          ok: false,
          status: response.status,
          endpoint,
          error: `invalid-json: ${error.message}`,
          bodyPreview: normalizedBody.slice(0, 500),
        };
      }

      return {
        ok: response.ok,
        status: response.status,
        endpoint,
        payload,
        bodyPreview: response.ok ? null : normalizedBody.slice(0, 500),
      };
    } catch (error) {
      return {
        ok: false,
        status: null,
        endpoint,
        error: error.message || String(error),
        bodyPreview: null,
      };
    }
  }, {
    profileUsername: username,
    maxId: cursor,
    pageSize: count,
  });
}

async function collectInstagramTimelinePosts(page, username, maxItems) {
  const itemsByShortcode = new Map();
  const pageSize = Math.min(12, Math.max(1, maxItems));
  const maxPages = Math.max(1, Math.ceil(maxItems / pageSize) + 1);
  const diagnostics = {
    attempted: true,
    successfulPages: 0,
    pages: [],
    rateLimited: false,
    reachedEnd: false,
    error: null,
  };
  let cursor = null;

  for (let pageIndex = 0; pageIndex < maxPages && itemsByShortcode.size < maxItems; pageIndex += 1) {
    const response = await fetchInstagramTimelinePage(page, username, cursor, pageSize);
    const payload = response?.payload && typeof response.payload === 'object'
      ? response.payload
      : {};
    const rawItems = Array.isArray(payload.items)
      ? payload.items
      : (Array.isArray(payload.data?.items) ? payload.data.items : []);
    const nextCursor = normalizeText(String(
      payload.next_max_id
        || payload.nextMaxId
        || payload.data?.next_max_id
        || '',
    )) || null;
    const moreAvailable = payload.more_available === true
      || payload.moreAvailable === true
      || payload.data?.more_available === true;
    const rateLimited = response?.status === 429
      || /rate.?limit|please wait|bitte warte/i.test(String(
        payload.message
          || response?.error
          || response?.bodyPreview
          || '',
      ));

    diagnostics.pages.push({
      page: pageIndex + 1,
      status: response?.status ?? null,
      ok: Boolean(response?.ok),
      itemCount: rawItems.length,
      nextCursor: Boolean(nextCursor),
      moreAvailable,
      endpoint: response?.endpoint || null,
      error: response?.error || payload.message || null,
      bodyPreview: response?.bodyPreview || null,
    });

    recordRunDebug('posts-timeline-api-page', diagnostics.pages.at(-1));

    if (!response?.ok) {
      diagnostics.rateLimited = rateLimited;
      diagnostics.error = response?.error || payload.message || `HTTP ${response?.status ?? 'unbekannt'}`;
      break;
    }

    diagnostics.successfulPages += 1;

    for (const rawItem of rawItems) {
      const item = normalizeInstagramTimelinePost(rawItem, username);

      if (!item || itemsByShortcode.has(item.shortcode)) {
        continue;
      }

      itemsByShortcode.set(item.shortcode, item);

      if (itemsByShortcode.size >= maxItems) {
        break;
      }
    }

    progressLog('posts-timeline-api', {
      relationship: 'posts',
      loaded: itemsByShortcode.size,
      page: pageIndex + 1,
      message: `${itemsByShortcode.size} Beitraege aus der Instagram-Timeline gelesen.`,
    });

    if (!moreAvailable) {
      diagnostics.reachedEnd = true;
      break;
    }

    if (!nextCursor || nextCursor === cursor) {
      diagnostics.error = 'Instagram meldet weitere Beitraege, liefert aber keinen neuen Cursor.';
      break;
    }

    cursor = nextCursor;
    await sleep(350);
  }

  return {
    items: Array.from(itemsByShortcode.values()),
    diagnostics,
  };
}

async function collectProfilePostLinks(page, username, expectedCount, runtimeConfig = {}) {
  const maxItems = Math.max(1, Number(runtimeConfig.postScanMaxItems || 100));
  const maxScrollRounds = Math.max(1, Number(runtimeConfig.postScanMaxScrollRounds || 40));
  const linksByShortcode = new Map();
  const timelineCollection = await collectInstagramTimelinePosts(page, username, maxItems);
  const sourceCounts = {
    timelineApi: 0,
    dom: 0,
  };
  let staleRounds = 0;
  let reachedEnd = Boolean(timelineCollection.diagnostics.reachedEnd);

  for (const item of timelineCollection.items) {
    linksByShortcode.set(item.shortcode, item);
    sourceCounts.timelineApi += 1;
  }

  for (let round = 0; round < maxScrollRounds && linksByShortcode.size < maxItems; round += 1) {
    if (expectedCount > 0 && linksByShortcode.size >= Math.min(maxItems, expectedCount)) {
      break;
    }

    if (timelineCollection.diagnostics.reachedEnd && timelineCollection.diagnostics.successfulPages > 0) {
      break;
    }

    const links = await page.evaluate((profileUsername) => Array.from(document.querySelectorAll('a[href]'))
      .map((anchor) => {
        const href = anchor.getAttribute('href') || '';
        let url;

        try {
          url = new URL(href, window.location.origin);
        } catch (error) {
          return null;
        }

        const match = url.pathname.match(/^\/(p|reel|tv)\/([^/?#]+)\/?/i);

        if (!match) {
          return null;
        }

        const image = anchor.querySelector('img');

        return {
          shortcode: match[2],
          mediaType: match[1].toLowerCase() === 'p' ? 'post' : match[1].toLowerCase(),
          postUrl: url.href,
          thumbnailUrl: image?.currentSrc || image?.src || null,
          thumbnailAlt: image?.getAttribute('alt') || null,
          profileUsername,
          source: 'dom',
        };
      })
      .filter(Boolean), username).catch(() => []);
    const beforeSize = linksByShortcode.size;

    for (const item of links) {
      if (!item?.shortcode || linksByShortcode.has(item.shortcode)) {
        continue;
      }

      linksByShortcode.set(item.shortcode, item);
      sourceCounts.dom += 1;

      if (linksByShortcode.size >= maxItems) {
        break;
      }
    }

    staleRounds = linksByShortcode.size === beforeSize ? staleRounds + 1 : 0;

    progressLog('posts-collecting-links', {
      relationship: 'posts',
      loaded: linksByShortcode.size,
      expectedCount: Math.min(maxItems, Math.max(0, Number(expectedCount || 0))),
      round: round + 1,
      maxScrollRounds,
      message: `${linksByShortcode.size} Beitragslinks gefunden.`,
      ...(await captureLivePreviewScreenshot(page, runtimeConfig)),
    });

    if (markGracefulStopIfRequested('posts', {
      loaded: linksByShortcode.size,
      expectedCount: Math.min(maxItems, Math.max(0, Number(expectedCount || 0))),
    })) {
      break;
    }

    if (expectedCount > 0 && linksByShortcode.size >= Math.min(maxItems, expectedCount)) {
      break;
    }

    const scrollState = await page.evaluate(() => {
      const before = window.scrollY;
      const maxScrollTop = Math.max(0, document.documentElement.scrollHeight - window.innerHeight);

      window.scrollBy(0, Math.max(700, Math.floor(window.innerHeight * 0.85)));

      return {
        before,
        maxScrollTop,
        atEnd: before >= maxScrollTop - 8,
      };
    }).catch(() => ({ atEnd: true }));

    reachedEnd = Boolean(scrollState.atEnd);

    if (reachedEnd && staleRounds >= 2) {
      break;
    }

    await sleep(1100);
  }

  const result = {
    items: Array.from(linksByShortcode.values()),
    reachedEnd,
    complete: expectedCount <= 0
      ? reachedEnd && !timelineCollection.diagnostics.error
      : linksByShortcode.size >= Math.min(maxItems, expectedCount),
    limited: expectedCount > maxItems && linksByShortcode.size >= maxItems,
    sourceCounts,
    timelineApi: timelineCollection.diagnostics,
  };

  recordRunDebug('posts-link-collection', {
    expectedCount,
    observedCount: result.items.length,
    complete: result.complete,
    reachedEnd: result.reachedEnd,
    limited: result.limited,
    sourceCounts,
    timelineApi: timelineCollection.diagnostics,
    shortcodes: result.items.slice(0, 25).map((item) => item.shortcode),
  });

  return result;
}

async function collectInstagramPosts(
  page,
  profile,
  username,
  profileUrl,
  runtimeConfig = {},
  runtimeState = null,
  notes = [],
) {
  const expectedCount = Math.max(0, Number(profile?.counts?.posts || 0));

  if (profile?.isPrivate) {
    return {
      attempted: false,
      available: false,
      complete: false,
      rateLimited: false,
      gracefullyStopped: false,
      expectedCount,
      observedCount: 0,
      items: [],
      statusLevel: 'partial',
      statusMessage: 'Privates Instagram-Profil; Beitraege sind fuer den Beitragsscan nicht sichtbar.',
      reason: 'private-profile',
    };
  }

  progressLog('posts-opening', {
    relationship: 'posts',
    loaded: 0,
    expectedCount,
    message: `Beitraege von @${username} werden gesammelt.`,
    ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
  });

  const linkCollection = await collectProfilePostLinks(page, username, expectedCount, runtimeConfig);
  const posts = [];
  const failedItems = [];
  let rateLimited = Boolean(linkCollection.timelineApi?.rateLimited);
  let gracefullyStopped = false;

  for (let index = 0; index < linkCollection.items.length; index += 1) {
    const link = linkCollection.items[index];

    if (markGracefulStopIfRequested('posts', {
      loaded: posts.length,
      expectedCount: linkCollection.items.length,
    })) {
      gracefullyStopped = true;
      break;
    }

    progressLog('posts-opening-item', {
      relationship: 'posts',
      loaded: posts.length,
      expectedCount: linkCollection.items.length,
      shortcode: link.shortcode,
      message: `Beitrag ${index + 1} von ${linkCollection.items.length} wird geprueft.`,
    });

    const hasTimelineLikes = hasFiniteNumericValue(link.likesCount);
    const hasTimelineComments = hasFiniteNumericValue(link.commentsCount);

    if (link.source === 'timeline-api' && hasTimelineLikes && hasTimelineComments) {
      const engagement = await collectInstagramPostEngagement(page, link, runtimeConfig);

      posts.push({
        ...link,
        mediaPk: engagement.mediaPk || link.mediaPk || null,
        likes: engagement.likes,
        comments: engagement.comments,
        likesComplete: engagement.likesComplete,
        commentsComplete: engagement.commentsComplete,
        engagementErrors: engagement.errors,
        ownerUsername: undefined,
      });
      rateLimited = rateLimited || engagement.rateLimited;

      progressLog('posts-item-collected', {
        relationship: 'posts',
        loaded: posts.length,
        expectedCount: linkCollection.items.length,
        shortcode: link.shortcode,
        likesCount: link.likesCount,
        commentsCount: link.commentsCount,
        storedLikesCount: engagement.likes.length,
        storedCommentsCount: engagement.comments.length,
        source: link.source,
        message: `Beitrag ${index + 1} von ${linkCollection.items.length} aus der Timeline gespeichert.`,
        ...(await captureLivePreviewScreenshot(page, runtimeConfig)),
      });

      if (rateLimited) {
        break;
      }

      continue;
    }

    const navigation = await navigateWithSoftTimeout(page, link.postUrl, runtimeConfig);

    if (!navigation.ok) {
      if (link.source === 'timeline-api') {
        posts.push({
          ...link,
          ownerUsername: undefined,
        });
      }

      failedItems.push({
        ...link,
        error: navigation.error,
      });
      continue;
    }

    await sleep(1100);

    if (runtimeState) {
      const recovery = await recoverFromInstagramDailyTimeLimit(
        page,
        runtimeState,
        notes,
        'posts',
        link.postUrl,
      );

      runtimeConfig = runtimeState.runtimeConfig;

      if (!recovery.recovered) {
        rateLimited = true;
        failedItems.push({
          ...link,
          error: 'instagram-daily-time-limit',
        });
        break;
      }
    }

    const details = await page.evaluate((profileUsername) => ({
      bodyText: document.body?.innerText || '',
      description: document.querySelector('meta[property="og:description"]')?.getAttribute('content') || null,
      thumbnailUrl: document.querySelector('meta[property="og:image"]')?.getAttribute('content') || null,
      publishedAt: document.querySelector('time[datetime]')?.getAttribute('datetime') || null,
      rateLimited: /rate limit|bitte warte einige minuten|please wait a few minutes|t[aä]gliches zeitlimit erreicht|daily time limit reached|reached your daily time limit/i.test(document.body?.innerText || ''),
      ownerSeen: Array.from(document.querySelectorAll('a[href]')).some((anchor) => {
        try {
          const pathParts = new URL(anchor.getAttribute('href'), window.location.origin).pathname
            .split('/')
            .filter(Boolean);

          return pathParts.length === 1 && pathParts[0].toLowerCase() === profileUsername.toLowerCase();
        } catch (error) {
          return false;
        }
      }),
    }), username).catch(() => ({
      bodyText: '',
      description: null,
      thumbnailUrl: null,
      publishedAt: null,
      rateLimited: false,
      ownerSeen: false,
    }));

    const ownerConfirmedByTimeline = link.source === 'timeline-api'
      && normalizeInstagramUsername(link.ownerUsername) === normalizeInstagramUsername(username);

    if (!details.ownerSeen && !ownerConfirmedByTimeline) {
      failedItems.push({
        ...link,
        error: 'post-owner-mismatch',
      });
      continue;
    }

    const html = await page.content().catch(() => '');
    const likesCount = extractPostMetricFromHtml(html, [
      'like_count',
      'edge_media_preview_like',
      'edge_liked_by',
    ]) ?? extractPostMetricFromText(details.bodyText, [
      /([\d.,\s]+(?:k|m|mio|tsd)?)\s+(?:likes?|gef[aä]llt-mir-angaben)/iu,
      /gef[aä]llt\s+([\d.,\s]+(?:k|m|mio|tsd)?)\s+mal/iu,
    ]);
    const commentsCount = extractPostMetricFromHtml(html, [
      'comment_count',
      'edge_media_to_parent_comment',
      'edge_media_to_comment',
    ]) ?? extractPostMetricFromText(details.bodyText, [
      /alle\s+([\d.,\s]+(?:k|m|mio|tsd)?)\s+kommentare/iu,
      /view\s+all\s+([\d.,\s]+(?:k|m|mio|tsd)?)\s+comments?/iu,
      /([\d.,\s]+(?:k|m|mio|tsd)?)\s+comments?/iu,
    ]);
    const engagement = await collectInstagramPostEngagement(page, {
      ...link,
      likesCount: hasTimelineLikes ? Number(link.likesCount) : likesCount,
      commentsCount: hasTimelineComments ? Number(link.commentsCount) : commentsCount,
    }, runtimeConfig);

    posts.push({
      ...link,
      mediaPk: engagement.mediaPk || link.mediaPk || null,
      thumbnailUrl: details.thumbnailUrl || link.thumbnailUrl || null,
      caption: details.description || link.caption || link.thumbnailAlt || null,
      publishedAt: details.publishedAt || link.publishedAt || null,
      likesCount: hasTimelineLikes ? Number(link.likesCount) : likesCount,
      commentsCount: hasTimelineComments ? Number(link.commentsCount) : commentsCount,
      likes: engagement.likes,
      comments: engagement.comments,
      likesComplete: engagement.likesComplete,
      commentsComplete: engagement.commentsComplete,
      engagementErrors: engagement.errors,
      media: Array.isArray(link.media) && link.media.length > 0
        ? link.media
        : [{
          position: 0,
          mediaType: link.mediaType === 'reel' || link.mediaType === 'tv' ? 'video' : 'image',
          sourceUrl: details.thumbnailUrl || link.thumbnailUrl || null,
          previewUrl: details.thumbnailUrl || link.thumbnailUrl || null,
          width: null,
          height: null,
          durationSeconds: null,
        }].filter((media) => Boolean(media.sourceUrl)),
    });
    rateLimited = rateLimited || Boolean(details.rateLimited) || engagement.rateLimited;

    progressLog('posts-item-collected', {
      relationship: 'posts',
      loaded: posts.length,
      expectedCount: linkCollection.items.length,
      shortcode: link.shortcode,
      likesCount,
      commentsCount,
      message: `Beitrag ${index + 1} von ${linkCollection.items.length} gespeichert.`,
      ...(await captureLivePreviewScreenshot(page, runtimeConfig)),
    });

    if (rateLimited) {
      break;
    }
  }

  if (!rateLimited && !gracefullyStopped) {
    await navigateWithSoftTimeout(page, profileUrl, runtimeConfig);
  }

  const complete = !rateLimited
    && !gracefullyStopped
    && linkCollection.complete
    && !linkCollection.limited
    && failedItems.length === 0
    && posts.length === linkCollection.items.length;
  const statusLevel = complete ? 'success' : 'partial';
  const statusMessage = rateLimited
    ? 'Instagram-Beitragsscan wurde wegen eines Rate-Limits vorzeitig beendet.'
    : (gracefullyStopped
      ? 'Instagram-Beitragsscan wurde beendet; bisherige Beitraege werden gespeichert.'
      : (linkCollection.limited
        ? `Instagram-Beitragsscan am konfigurierten Limit beendet; ${posts.length} von ${expectedCount} Beitraegen gespeichert.`
        : (complete
          ? 'Instagram-Beitragsscan abgeschlossen.'
          : 'Instagram-Beitragsscan teilweise abgeschlossen; nicht alle Beitraege waren erreichbar.')));

  progressLog('posts-complete', {
    relationship: 'posts',
    loaded: posts.length,
    expectedCount: linkCollection.items.length,
    message: statusMessage,
    ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
  });

  return {
    attempted: true,
    available: posts.length > 0 || expectedCount === 0,
    complete,
    rateLimited,
    gracefullyStopped,
    expectedCount,
    observedCount: posts.length,
    failedCount: failedItems.length,
    limited: linkCollection.limited,
    items: posts,
    failedItems,
    collectionDiagnostics: {
      sourceCounts: linkCollection.sourceCounts,
      reachedEnd: linkCollection.reachedEnd,
      timelineApi: linkCollection.timelineApi,
    },
    statusLevel,
    statusMessage,
  };
}

async function closeInstagramDialog(page) {
  await page.keyboard.press('Escape').catch(() => {});
  await sleep(400);
}

function hasFiniteNumericValue(value) {
  return value !== null
    && value !== undefined
    && value !== ''
    && Number.isFinite(Number(value));
}

function mergeProfileHoverCardData(item = {}, hoverCard = null) {
  if (!hoverCard || typeof hoverCard !== 'object') {
    return item;
  }

  return {
    ...item,
    displayName: item.displayName || hoverCard.displayName || null,
    profileImageUrl: item.profileImageUrl || hoverCard.profileImageUrl || null,
    profileVisibility: hoverCard.profileVisibility || item.profileVisibility || null,
    isPrivate: typeof hoverCard.isPrivate === 'boolean' ? hoverCard.isPrivate : item.isPrivate,
    postsCount: hasFiniteNumericValue(hoverCard.postsCount) ? Number(hoverCard.postsCount) : item.postsCount,
    followersCount: hasFiniteNumericValue(hoverCard.followersCount) ? Number(hoverCard.followersCount) : item.followersCount,
    followingCount: hasFiniteNumericValue(hoverCard.followingCount) ? Number(hoverCard.followingCount) : item.followingCount,
    hoverCard,
  };
}

async function collectVisibleProfileHoverCard(page, username, runtimeConfig = {}) {
  const normalizedUsername = normalizeInstagramUsername(username);

  if (!normalizedUsername || runtimeConfig.profileHoverCardsEnabled === false || runtimeConfig.profile_hover_cards_enabled === false) {
    return null;
  }

  const hoverTarget = await page.evaluate((targetUsername) => {
    const normalizeUsername = (value = '') => String(value || '')
      .replace(/^@/, '')
      .replace(/[^a-z0-9._]/gi, '')
      .toLowerCase();
    const isVisible = (element) => {
      if (!element) {
        return false;
      }

      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);

      return rect.width > 0
        && rect.height > 0
        && style.display !== 'none'
        && style.visibility !== 'hidden'
        && Number(style.opacity || 1) > 0.05;
    };
    const anchors = Array.from(document.querySelectorAll('a[href]'))
      .filter(isVisible)
      .map((anchor) => {
        let username = '';

        try {
          const parts = new URL(anchor.getAttribute('href'), window.location.origin).pathname
            .split('/')
            .filter(Boolean);

          if (parts.length === 1) {
            username = normalizeUsername(parts[0]);
          }
        } catch (error) {
          username = '';
        }

        if (username !== targetUsername) {
          return null;
        }

        const rect = anchor.getBoundingClientRect();
        const text = String(anchor.innerText || anchor.textContent || '');
        const row = anchor.closest('div[role="button"], li, article, div') || anchor;
        const rowRect = row.getBoundingClientRect();

        return {
          element: anchor,
          rect,
          rowRect,
          score: (text.toLowerCase().includes(targetUsername) ? 100 : 0)
            + (Boolean(anchor.closest('div[role="dialog"]')) ? 20 : 0)
            + (row.querySelector('img') ? 10 : 0)
            - Math.max(0, Math.floor(rowRect.height / 150)),
        };
      })
      .filter(Boolean)
      .sort((left, right) => right.score - left.score);
    const target = anchors[0]?.element || null;

    if (!target) {
      return null;
    }

    target.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    const rect = target.getBoundingClientRect();

    return {
      x: Math.max(1, Math.min(window.innerWidth - 1, rect.left + Math.min(rect.width * 0.45, 80))),
      y: Math.max(1, Math.min(window.innerHeight - 1, rect.top + (rect.height / 2))),
    };
  }, normalizedUsername).catch(() => null);

  if (!hoverTarget) {
    return null;
  }

  await page.mouse.move(hoverTarget.x, hoverTarget.y).catch(() => {});
  await sleep(Math.max(450, Number(runtimeConfig.profileHoverCardWaitMs || runtimeConfig.profile_hover_card_wait_ms || 850)));

  const hoverCard = await page.evaluate((targetUsername) => {
    const normalizeElementText = (value = '') => String(value).replace(/\s+/g, ' ').trim();
    const normalizeUsername = (value = '') => String(value || '')
      .replace(/^@/, '')
      .replace(/[^a-z0-9._]/gi, '')
      .toLowerCase();
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
    const isVisible = (element) => {
      if (!element) {
        return false;
      }

      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);

      return rect.width >= 180
        && rect.height >= 80
        && style.display !== 'none'
        && style.visibility !== 'hidden'
        && Number(style.opacity || 1) > 0.05;
    };
    const metricForLabel = (label = '') => {
      if (/beitr(?:a|ä|Ã¤)ge?|posts?/i.test(label)) {
        return 'posts';
      }

      if (/followers?/i.test(label)) {
        return 'followers';
      }

      if (/gefolgt|following/i.test(label)) {
        return 'following';
      }

      return null;
    };
    const parseCountsFromText = (text) => {
      const counts = {
        posts: null,
        followers: null,
        following: null,
      };
      const lines = String(text || '')
        .split('\n')
        .map((line) => normalizeElementText(line))
        .filter(Boolean);
      const inlinePatterns = {
        posts: /([\d., ]+(?:\s*(?:k|m|mio|tsd))?)\s*(?:beitr(?:a|ä|Ã¤)ge?|posts?)/iu,
        followers: /([\d., ]+(?:\s*(?:k|m|mio|tsd))?)\s*(?:followers?)/iu,
        following: /([\d., ]+(?:\s*(?:k|m|mio|tsd))?)\s*(?:gefolgt|following)/iu,
      };
      const normalizedText = normalizeElementText(text);

      for (const [metric, pattern] of Object.entries(inlinePatterns)) {
        const match = normalizedText.match(pattern);

        if (match) {
          counts[metric] = normalizeCountValue(match[1] || '');
        }
      }

      for (let index = 0; index < lines.length; index += 1) {
        const metric = metricForLabel(lines[index]);

        if (!metric || counts[metric] !== null) {
          continue;
        }

        const before = index > 0 ? normalizeCountValue(lines[index - 1]) : null;
        const after = index + 1 < lines.length ? normalizeCountValue(lines[index + 1]) : null;
        counts[metric] = before ?? after;
      }

      return counts;
    };
    const privatePattern = /(?:dieses profil ist privat|dies ist ein privates konto|this account is private|private account|privates konto|privates profil)/i;
    const cards = Array.from(document.querySelectorAll('div, article, section'))
      .filter(isVisible)
      .map((element) => {
        const text = normalizeElementText(element.innerText || element.textContent || '');
        const rect = element.getBoundingClientRect();
        const usernameSeen = text.toLowerCase().includes(targetUsername);
        const privateSeen = privatePattern.test(text);
        const countLabelSeen = /beitr(?:a|ä|Ã¤)ge?|posts?|followers?|gefolgt|following/i.test(text);
        const linkCount = Array.from(element.querySelectorAll('a[href]')).filter((anchor) => {
          try {
            return new URL(anchor.getAttribute('href'), window.location.origin).pathname.split('/').filter(Boolean).length === 1;
          } catch (error) {
            return false;
          }
        }).length;
        const zIndex = Number.parseInt(window.getComputedStyle(element).zIndex || '0', 10) || 0;
        const score = (usernameSeen ? 100 : 0)
          + (privateSeen ? 60 : 0)
          + (countLabelSeen ? 40 : 0)
          + (rect.width <= 520 ? 20 : 0)
          + (rect.height <= 520 ? 20 : 0)
          + Math.min(30, zIndex)
          - Math.max(0, linkCount - 2) * 20
          - Math.max(0, Math.floor(text.length / 600));

        return {
          element,
          text,
          rect,
          usernameSeen,
          privateSeen,
          countLabelSeen,
          score,
        };
      })
      .filter((entry) => entry.usernameSeen && (entry.privateSeen || entry.countLabelSeen) && entry.score > 90)
      .sort((left, right) => right.score - left.score);
    const card = cards[0] || null;

    if (!card) {
      return null;
    }

    const counts = parseCountsFromText(card.text);
    const image = Array.from(card.element.querySelectorAll('img'))
      .find((candidate) => {
        const rect = candidate.getBoundingClientRect();
        const src = candidate.currentSrc || candidate.src || candidate.getAttribute('src') || '';

        return src !== ''
          && rect.width > 24
          && rect.height > 24
          && !/emoji|sprite|blank|transparent/i.test(src);
      }) || null;
    const privateSeen = privatePattern.test(card.text);
    const hasAnyCount = counts.posts !== null || counts.followers !== null || counts.following !== null;
    const textLines = card.text
      .split('\n')
      .map((line) => normalizeElementText(line))
      .filter(Boolean);
    const displayName = textLines.find((line) => {
      const lower = line.toLowerCase();

      return lower !== targetUsername
        && normalizeUsername(line) !== targetUsername
        && !metricForLabel(line)
        && normalizeCountValue(line) === null
        && !privatePattern.test(line)
        && !/^(folgen|follow|following|nachricht|message)$/i.test(line)
        && line.length <= 120;
    }) || null;

    return {
      username: normalizeUsername(targetUsername),
      displayName,
      profileImageUrl: image ? (image.currentSrc || image.src || image.getAttribute('src') || null) : null,
      profileVisibility: privateSeen ? 'private' : (hasAnyCount ? 'public' : 'unknown'),
      isPrivate: privateSeen ? true : (hasAnyCount ? false : null),
      postsCount: counts.posts,
      followersCount: counts.followers,
      followingCount: counts.following,
      textPreview: card.text.slice(0, 800),
      scannedAt: new Date().toISOString(),
    };
  }, normalizedUsername).catch(() => null);

  await page.mouse.move(Math.max(1, hoverTarget.x - 160), Math.max(1, hoverTarget.y - 160)).catch(() => {});
  await sleep(120);

  return hoverCard;
}

async function enrichProfileEntriesWithHoverCards(page, entries = [], hoveredUsernames = new Set(), runtimeConfig = {}) {
  if (runtimeConfig.profileHoverCardsEnabled === false || runtimeConfig.profile_hover_cards_enabled === false) {
    return entries;
  }

  const enrichedEntries = [];

  for (const entry of entries) {
    const entryUsername = normalizeInstagramUsername(entry?.username || '');

    if (!entryUsername || hoveredUsernames.has(entryUsername)) {
      enrichedEntries.push(entry);
      continue;
    }

    markScriptActivity(`hover-card:${entryUsername}`);
    const hoverCard = await collectVisibleProfileHoverCard(page, entryUsername, runtimeConfig);
    hoveredUsernames.add(entryUsername);
    enrichedEntries.push(mergeProfileHoverCardData(entry, hoverCard));
  }

  return enrichedEntries;
}

async function collectFollowerEntriesFromDialog(page) {
  return page.evaluate(() => {
    const normalizeElementText = (value = '') => String(value).replace(/\s+/g, ' ').trim();
    const rateLimitPattern = /(?:versuche es sp(?:a|\u00e4)ter noch einmal|zu viele anfragen|wir schr(?:a|\u00e4)nken die h(?:a|\u00e4)ufigkeit|t(?:a|\u00e4)gliches zeitlimit erreicht|du hast dein t(?:a|\u00e4)gliches zeitlimit erreicht|try again later|too many requests|429|error 429|we restrict certain activity|we limit how often|this action was blocked|daily time limit reached|reached your daily time limit|rate limit)/i;
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
    const suggestionHeadingPattern = /^(f(?:u|\u00fc)r dich vorgeschlagen|vorschl(?:a|\u00e4)ge f(?:u|\u00fc)r dich|suggested for you|suggestions for you|suggested)$/i;
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
    const findEntryContainer = (anchor, username) => {
      const fallback = anchor.closest('div[role="button"], li, article, div') || anchor;
      const normalizedUsername = String(username || '').toLowerCase();

      for (let element = anchor; element && element !== dialog; element = element.parentElement) {
        const text = normalizeElementText(element.innerText || element.textContent || '');
        const rect = element.getBoundingClientRect();
        const hasProfileImage = Boolean(element.querySelector('img'));

        if (
          hasProfileImage
          && (!normalizedUsername || text.toLowerCase().includes(normalizedUsername))
          && rect.width >= 120
          && rect.height >= 36
          && text.length <= 900
        ) {
          return element;
        }

        if (text.length > 900 || rect.height > 420) {
          break;
        }
      }

      return fallback;
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
        const row = findEntryContainer(anchor, username);
        const image = Array.from(row.querySelectorAll('img'))
          .find((candidate) => {
            const rect = candidate.getBoundingClientRect();
            const src = candidate.currentSrc || candidate.src || candidate.getAttribute('src') || '';

            return src !== ''
              && rect.width > 12
              && rect.height > 12
              && !/emoji|sprite|blank|transparent/i.test(src);
          }) || null;
        const profileImageUrl = image
          ? (image.currentSrc || image.src || image.getAttribute('src') || null)
          : null;

        return {
          username,
          displayName: textLines.find((line) => line.toLowerCase() !== username.toLowerCase()) || null,
          profileUrl: `https://www.instagram.com/${username}/`,
          profileImageUrl,
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
    const normalizeElementText = (value = '') => String(value || '').replace(/\s+/g, ' ').trim();
    const isVisibleEditable = (element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);

      if (
        rect.width <= 2
        || rect.height <= 2
        || style.display === 'none'
        || style.visibility === 'hidden'
        || style.opacity === '0'
      ) {
        return false;
      }

      if ('disabled' in element && element.disabled) {
        return false;
      }

      if ('readOnly' in element && element.readOnly) {
        return false;
      }

      return true;
    };
    const editableElements = Array.from(dialog.querySelectorAll([
      'input',
      'textarea',
      '[contenteditable="true"]',
      '[role="textbox"]',
      '[aria-label]',
    ].join(','))).filter((element) => {
      const tagName = element.tagName.toLowerCase();
      const role = (element.getAttribute('role') || '').toLowerCase();
      const contentEditable = (element.getAttribute('contenteditable') || '').toLowerCase();

      if (!['input', 'textarea'].includes(tagName) && role !== 'textbox' && contentEditable !== 'true') {
        return false;
      }

      if (tagName === 'input') {
        const type = (element.getAttribute('type') || 'text').toLowerCase();

        if (!['text', 'search', 'email', ''].includes(type)) {
          return false;
        }
      }

      return isVisibleEditable(element);
    });
    const preferredInput = editableElements.find((input) => {
      const haystack = [
        input.getAttribute('placeholder') || '',
        input.getAttribute('aria-label') || '',
        input.getAttribute('aria-placeholder') || '',
        input.getAttribute('name') || '',
        input.getAttribute('type') || '',
        input.getAttribute('role') || '',
        normalizeElementText(input.textContent || ''),
      ].join(' ');

      return /suchen|suche|search|durchsuchen|filter/i.test(haystack);
    }) || editableElements.find((input) => {
      const type = (input.getAttribute('type') || 'text').toLowerCase();

      return type === 'text' || type === 'search' || input.getAttribute('role') === 'textbox';
    });

    return Boolean(preferredInput);
  }).catch(() => false);
}

async function setRelationshipDialogSearchQuery(page, query, waitMs = 900) {
  const applySearchQuery = async () => page.evaluate((value) => {
    const dialog = document.querySelector('div[role="dialog"]') || document.body;
    const normalizeElementText = (text = '') => String(text || '').replace(/\s+/g, ' ').trim();
    const isVisibleEditable = (element) => {
      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);

      if (
        rect.width <= 2
        || rect.height <= 2
        || style.display === 'none'
        || style.visibility === 'hidden'
        || style.opacity === '0'
      ) {
        return false;
      }

      if ('disabled' in element && element.disabled) {
        return false;
      }

      if ('readOnly' in element && element.readOnly) {
        return false;
      }

      return true;
    };
    const scoreSearchElement = (candidate) => {
      const tagName = candidate.tagName.toLowerCase();
      const type = (candidate.getAttribute('type') || 'text').toLowerCase();
      const role = (candidate.getAttribute('role') || '').toLowerCase();
      const contentEditable = (candidate.getAttribute('contenteditable') || '').toLowerCase();

      if (!['input', 'textarea'].includes(tagName) && role !== 'textbox' && contentEditable !== 'true') {
        return -1;
      }

      if (tagName === 'input' && !['text', 'search', 'email', ''].includes(type)) {
        return -1;
      }

      if (!isVisibleEditable(candidate)) {
        return -1;
      }

      const haystack = [
        candidate.getAttribute('placeholder') || '',
        candidate.getAttribute('aria-label') || '',
        candidate.getAttribute('aria-placeholder') || '',
        candidate.getAttribute('name') || '',
        candidate.getAttribute('type') || '',
        candidate.getAttribute('role') || '',
        normalizeElementText(candidate.textContent || ''),
      ].join(' ');
      let score = 0;

      if (/suchen|suche|search|durchsuchen|filter/i.test(haystack)) {
        score += 100;
      }

      if (type === 'search') {
        score += 35;
      }

      if (role === 'textbox') {
        score += 20;
      }

      const rect = candidate.getBoundingClientRect();
      const dialogRect = dialog.getBoundingClientRect();

      if (rect.top <= dialogRect.top + Math.max(160, dialogRect.height * 0.28)) {
        score += 15;
      }

      if (tagName === 'input' || tagName === 'textarea') {
        score += 10;
      }

      return score;
    };
    const input = Array.from(dialog.querySelectorAll([
      'input',
      'textarea',
      '[contenteditable="true"]',
      '[role="textbox"]',
      '[aria-label]',
    ].join(',')))
      .map((candidate) => ({ candidate, score: scoreSearchElement(candidate) }))
      .filter((entry) => entry.score >= 0)
      .sort((left, right) => right.score - left.score)[0]?.candidate || null;

    if (!input) {
      return {
        applied: false,
        reason: 'search-input-not-found',
      };
    }

    input.scrollIntoView({ block: 'center', inline: 'nearest' });
    input.click();
    input.focus();

    const isContentEditable = (input.getAttribute('contenteditable') || '').toLowerCase() === 'true'
      || (input.getAttribute('role') || '').toLowerCase() === 'textbox';
    const nativeValueSetter = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value')?.set;
    const nativeTextareaValueSetter = Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype, 'value')?.set;

    if (isContentEditable && !('value' in input)) {
      input.textContent = '';
      input.dispatchEvent(new InputEvent('input', {
        bubbles: true,
        inputType: 'deleteContentBackward',
      }));
      input.textContent = value;
      input.dispatchEvent(new InputEvent('input', {
        bubbles: true,
        data: value,
        inputType: 'insertText',
      }));
    } else {
      const setter = input.tagName.toLowerCase() === 'textarea'
        ? nativeTextareaValueSetter
        : nativeValueSetter;

      if (setter) {
        setter.call(input, '');
      } else {
        input.value = '';
      }

      input.dispatchEvent(new InputEvent('input', {
        bubbles: true,
        inputType: 'deleteContentBackward',
      }));

      if (setter) {
        setter.call(input, value);
      } else {
        input.value = value;
      }

      input.dispatchEvent(new InputEvent('beforeinput', {
        bubbles: true,
        data: value,
        inputType: 'insertText',
      }));
      input.dispatchEvent(new InputEvent('input', {
        bubbles: true,
        data: value,
        inputType: 'insertText',
      }));
    }

    input.dispatchEvent(new Event('change', { bubbles: true }));
    input.dispatchEvent(new KeyboardEvent('keyup', { bubbles: true, key: value.slice(-1) || 'Backspace' }));

    const scrollTargets = Array.from(dialog.querySelectorAll('div, ul, section'))
      .filter((element) => element.scrollHeight > element.clientHeight + 40);

    for (const target of scrollTargets) {
      target.scrollTop = 0;
    }

    const currentValue = 'value' in input ? input.value : normalizeElementText(input.textContent || '');

    return {
      applied: currentValue === value,
      reason: currentValue === value ? null : 'search-query-not-verified',
      currentValue,
    };
  }, String(query || '')).catch(() => false);

  for (let attempt = 0; attempt < 3; attempt++) {
    const result = await applySearchQuery();

    if (result && result.applied) {
      await sleep(query ? waitMs : Math.min(600, waitMs));

      return true;
    }

    await sleep(350);
  }

  return false;
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

function addRelationshipEntriesToMap(entries, usersByUsername, targetUsername, deltaItems = null) {
  let added = 0;

  for (const entry of entries) {
    const relatedUsername = normalizeInstagramUsername(entry?.username);

    if (!relatedUsername || relatedUsername === targetUsername) {
      continue;
    }

    const existing = usersByUsername.get(relatedUsername);
    const nextEntry = {
      username: relatedUsername,
      displayName: entry.displayName || existing?.displayName || null,
      profileUrl: entry.profileUrl || existing?.profileUrl || `https://www.instagram.com/${relatedUsername}/`,
      profileImageUrl: entry.profileImageUrl || entry.profile_image_url || existing?.profileImageUrl || null,
      profileVisibility: entry.profileVisibility || existing?.profileVisibility || null,
      isPrivate: typeof entry.isPrivate === 'boolean' ? entry.isPrivate : existing?.isPrivate,
      postsCount: hasFiniteNumericValue(entry.postsCount) ? Number(entry.postsCount) : existing?.postsCount,
      followersCount: hasFiniteNumericValue(entry.followersCount) ? Number(entry.followersCount) : existing?.followersCount,
      followingCount: hasFiniteNumericValue(entry.followingCount) ? Number(entry.followingCount) : existing?.followingCount,
      hoverCard: entry.hoverCard || existing?.hoverCard || null,
    };

    usersByUsername.set(relatedUsername, nextEntry);

    if (!existing) {
      added++;

      if (Array.isArray(deltaItems)) {
        deltaItems.push(nextEntry);
      }
    }
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

function scoreRelationshipSearchQuery(query, usersByUsername) {
  let score = 0;

  for (const item of usersByUsername.values()) {
    const haystack = `${item.username || ''} ${item.displayName || ''}`.toLowerCase();

    if (haystack.includes(query)) {
      score++;
    }
  }

  return score;
}

function buildSecondLevelRelationshipSearchQueries(queryStats, usersByUsername, alreadyQueuedQueries) {
  const productiveBaseQueries = queryStats
    .filter((stat) => stat.depth === 1 && stat.stopReason === 'search-partition-item-limit')
    .map((stat) => stat.query)
    .filter((query) => query.length === 1);
  const candidates = new Set();

  for (const baseQuery of productiveBaseQueries) {
    for (const character of RELATIONSHIP_SEARCH_PARTITION_CHARACTERS) {
      candidates.add(`${baseQuery}${character}`);
      candidates.add(`${character}${baseQuery}`);
    }
  }

  return Array.from(candidates)
    .filter((query) => !alreadyQueuedQueries.has(query))
    .map((query) => ({
      query,
      score: scoreRelationshipSearchQuery(query, usersByUsername),
    }))
    .sort((left, right) => right.score - left.score || left.query.localeCompare(right.query))
    .map((entry) => entry.query);
}

function getMissingPrioritizedRelationshipSearchQueries(runtimeConfig = {}, relationship, usersByUsername = new Map()) {
  const normalizedRelationship = relationship === 'following' ? 'following' : 'followers';
  const configured = runtimeConfig.relationshipPrioritizedSearchUsernames || {};
  const usernames = Array.isArray(configured[normalizedRelationship]) ? configured[normalizedRelationship] : [];
  const queued = new Set();
  const queries = [];

  for (const rawUsername of usernames) {
    const username = normalizeInstagramUsername(rawUsername);

    if (!username || queued.has(username) || usersByUsername.has(username)) {
      continue;
    }

    queued.add(username);
    queries.push(username);
  }

  return queries;
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
  const searchTargetUsername = normalizeInstagramUsername(
    options.targetUsername || runtimeConfig.relationshipSearchTargetUsername || '',
  );
  const prioritizedQueries = getMissingPrioritizedRelationshipSearchQueries(
    runtimeConfig,
    normalizedRelationship,
    usersByUsername,
  );
  const verifyMissingOnly = Boolean(runtimeConfig.relationshipSearchVerifyMissingOnly);
  const queries = verifyMissingOnly ? [] : getRelationshipSearchPartitionQueries(runtimeConfig);
  const maxSearchDepth = Math.min(
    2,
    Math.max(1, normalizeOptionalPositiveInteger(runtimeConfig.relationshipSearchMaxDepth, 2) || 2),
  );
  const configuredSearchWaitMs = Number(runtimeConfig.relationshipSearchWaitMs || 900);
  const searchWaitMs = Number.isFinite(configuredSearchWaitMs)
    ? Math.max(500, configuredSearchWaitMs)
    : 900;
  const hoverCardUsernames = options.hoverCardUsernames instanceof Set ? options.hoverCardUsernames : new Set();
  const searchInputMaxAttempts = Math.floor(normalizeNumberAtLeast(
    runtimeConfig.relationshipSearchInputMaxAttempts,
    3,
    1,
  ));
  const queriesPerDialog = Math.max(
    1,
    Math.floor(normalizeNumberAtLeast(runtimeConfig.relationshipSearchQueriesPerDialog, 8, 1)),
  );
  const checkpointSize = Math.max(
    25,
    normalizeOptionalPositiveInteger(runtimeConfig.relationshipProgressCheckpointSize, 250) || 250,
  );
  const partitionMaxItems = Math.max(
    25,
    normalizeOptionalPositiveInteger(runtimeConfig.relationshipSearchPartitionMaxItems, 250) || 250,
  );
  const queryQueue = queries.length > 0
    ? queries.map((query) => ({ query, depth: 1, source: 'alphabet-partition' }))
    : prioritizedQueries.map((query) => ({ query, depth: 0, source: 'missing-known-profile' }));
  const queuedQueries = new Set([...prioritizedQueries, ...queries]);
  const queryStats = [];
  const queriesRun = [];
  const verifiedMissingUsernames = new Set();
  const verifiedPresentUsernames = new Set();
  let searchRounds = 0;
  let openAttempts = 0;
  let addedCount = 0;
  let expandedQueryCount = 0;
  let stopReason = null;

  const targetReached = () => {
    if (searchTargetUsername && usersByUsername.has(searchTargetUsername)) {
      return true;
    }

    if (hasItemLimit && usersByUsername.size >= maxItems) {
      return true;
    }

    return false;
  };

  progressLog('relationship-search-opening', {
    relationship: normalizedRelationship,
    loaded: usersByUsername.size,
    expectedCount,
    maxItems,
    maxScrollRounds,
    prioritizedQueryCount: prioritizedQueries.length,
    queryCount: queries.length,
    targetUsername: searchTargetUsername || null,
  });

  const openDialog = async () => (normalizedRelationship === 'following'
    ? openFollowingDialog(page, username, runtimeConfig)
    : openFollowersDialog(page, username, runtimeConfig));
  const openDialogWithSearchInput = async () => {
    while (openAttempts < searchInputMaxAttempts) {
      if (markGracefulStopIfRequested(normalizedRelationship, {
        loaded: usersByUsername.size,
        expectedCount,
      })) {
        return {
          opened: false,
          inputAvailable: false,
          stopReason: 'ui-stop-requested',
        };
      }

      openAttempts++;

      const dialogOpened = await openDialog();

      if (!dialogOpened) {
        return {
          opened: false,
          inputAvailable: false,
          stopReason: 'search-dialog-not-found',
        };
      }

      const searchInputAvailable = await relationshipDialogSearchInputAvailable(page);

      if (searchInputAvailable) {
        return {
          opened: true,
          inputAvailable: true,
          stopReason: null,
        };
      }

      progressLog('relationship-search-input-missing', {
        relationship: normalizedRelationship,
        loaded: usersByUsername.size,
        expectedCount,
        openAttempts,
        maxAttempts: searchInputMaxAttempts,
        targetUsername: searchTargetUsername || null,
        message: `Suchfeld fuer ${normalizedRelationship} nicht gefunden (${openAttempts}/${searchInputMaxAttempts}).`,
      });

      await closeInstagramDialog(page);

      if (openAttempts < searchInputMaxAttempts) {
        await sleep(650);
      }
    }

    return {
      opened: true,
      inputAvailable: false,
      stopReason: 'search-input-not-found',
    };
  };

  let searchDialogState = await openDialogWithSearchInput();
  let opened = searchDialogState.opened;
  let inputAvailable = searchDialogState.inputAvailable;

  if (!opened) {
    const searchStopReason = searchDialogState.stopReason || 'search-dialog-not-found';

    return {
      attempted: true,
      inputAvailable: false,
      queries: queriesRun,
      rounds: searchRounds,
      addedCount,
      openAttempts,
      stopReason: searchStopReason,
      rateLimited: false,
      rateLimitText: null,
      verifiedMissingUsernames: [],
      verifiedPresentUsernames: [],
      gracefullyStopped: searchStopReason === 'ui-stop-requested',
    };
  }

  if (!inputAvailable) {
    const searchStopReason = searchDialogState.stopReason || 'search-input-not-found';

    return {
      attempted: true,
      inputAvailable: false,
      queries: queriesRun,
      rounds: searchRounds,
      addedCount,
      openAttempts,
      stopReason: searchStopReason,
      rateLimited: false,
      rateLimitText: null,
      verifiedMissingUsernames: [],
      verifiedPresentUsernames: [],
      gracefullyStopped: searchStopReason === 'ui-stop-requested',
    };
  }

  for (let queryIndex = 0; queryIndex < queryQueue.length; queryIndex++) {
    const { query, depth, source } = queryQueue[queryIndex];

    if (source === 'missing-known-profile' && usersByUsername.has(query)) {
      verifiedPresentUsernames.add(query);
      continue;
    }

    if (targetReached() || searchRounds >= maxScrollRounds) {
      break;
    }

    if (markGracefulStopIfRequested(normalizedRelationship, {
      loaded: usersByUsername.size,
      expectedCount,
      queryIndex: queryIndex + 1,
      queryCount: queryQueue.length,
    })) {
      stopReason = 'ui-stop-requested';
      break;
    }

    if (queryIndex > 0 && queryIndex % queriesPerDialog === 0) {
      await setRelationshipDialogSearchQuery(page, '', searchWaitMs);
      await closeInstagramDialog(page);

      progressLog('relationship-search-batch-complete', {
        relationship: normalizedRelationship,
        loaded: usersByUsername.size,
        expectedCount,
        queryIndex,
        queryCount: queryQueue.length,
        queriesPerDialog,
        itemsPreview: buildRelationshipProgressPreview(usersByUsername, checkpointSize),
        message: `${queryIndex} alphabetische Suchsegmente abgeschlossen und zwischengespeichert.`,
        ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
      });

      await sleep(1200);
      searchDialogState = await openDialogWithSearchInput();
      opened = searchDialogState.opened;
      inputAvailable = searchDialogState.inputAvailable;

      if (!opened || !inputAvailable) {
        stopReason = searchDialogState.stopReason || 'search-input-not-found-after-batch';
        break;
      }
    }

    let queryApplied = await setRelationshipDialogSearchQuery(page, query, searchWaitMs);

    if (!queryApplied && openAttempts < searchInputMaxAttempts) {
      await closeInstagramDialog(page);
      searchDialogState = await openDialogWithSearchInput();
      opened = searchDialogState.opened;
      inputAvailable = searchDialogState.inputAvailable;
      queryApplied = opened && inputAvailable
        ? await setRelationshipDialogSearchQuery(page, query, searchWaitMs)
        : false;

      if (!queryApplied && searchDialogState.stopReason === 'ui-stop-requested') {
        stopReason = 'ui-stop-requested';
        break;
      }
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
    let queryAddedCount = 0;
    const queryVisibleUsernames = new Set();
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
      depth,
      source,
      queryIndex: queryIndex + 1,
      queryCount: queryQueue.length,
    });

    while (!targetReached() && searchRounds < maxScrollRounds) {
      const collection = await collectFollowerEntriesFromDialog(page);
      const rawEntries = Array.isArray(collection) ? collection : (collection.entries || []);
      const entries = await enrichProfileEntriesWithHoverCards(page, rawEntries, hoverCardUsernames, runtimeConfig);
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
          maxDepth: maxSearchDepth,
          expandedQueryCount,
          verifiedMissingUsernames: Array.from(verifiedMissingUsernames),
          verifiedPresentUsernames: Array.from(verifiedPresentUsernames),
        };
      }

      for (const entry of entries) {
        const relatedUsername = normalizeInstagramUsername(entry?.username);

        if (relatedUsername) {
          queryVisibleUsernames.add(relatedUsername);
        }
      }

      const addedItems = [];
      const addedThisRound = addRelationshipEntriesToMap(entries, usersByUsername, targetUsername, addedItems);
      addedCount += addedThisRound;
      queryAddedCount += addedThisRound;
      searchRounds++;
      queryRounds++;

      if (usersByUsername.size === previousCount) {
        unchangedRounds++;
      } else {
        unchangedRounds = 0;
        previousCount = usersByUsername.size;
      }

      const livePreview = await captureLivePreviewScreenshot(page, runtimeConfig);

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
        depth,
        source,
        queryIndex: queryIndex + 1,
        queryCount: queryQueue.length,
        itemsDelta: buildRelationshipProgressItems(addedItems, checkpointSize),
        itemsPreview: buildRelationshipProgressPreview(usersByUsername, checkpointSize),
        ...livePreview,
      });

      if (markGracefulStopIfRequested(normalizedRelationship, {
        loaded: usersByUsername.size,
        expectedCount,
        query,
        round: searchRounds,
        queryRound: queryRounds,
      })) {
        queryStopReason = 'ui-stop-requested';
        stopReason = queryStopReason;
        break;
      }

      if (targetReached()) {
        queryStopReason = expectedCount > 0 && usersByUsername.size >= expectedCount
          ? 'expected-count-reached'
          : searchTargetUsername && usersByUsername.has(searchTargetUsername)
          ? 'target-found'
          : 'max-items-reached';
        break;
      }

      if (depth >= 1 && queryVisibleUsernames.size >= partitionMaxItems) {
        queryStopReason = 'search-partition-item-limit';
        break;
      }

      if (entries.length === 0) {
        if ((searchTargetUsername || source === 'missing-known-profile') && queryRounds < 3 && searchRounds < maxScrollRounds) {
          await sleep(searchWaitMs);
          continue;
        }

        queryStopReason = 'search-empty';
        break;
      }

      if (suggestionsVisible) {
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
      depth,
      source,
      queryIndex: queryIndex + 1,
      queryCount: queryQueue.length,
      visibleCount: queryVisibleUsernames.size,
      partitionMaxItems,
      itemsPreview: buildRelationshipProgressPreview(usersByUsername, checkpointSize),
    });

    if (source === 'missing-known-profile') {
      if (usersByUsername.has(query) || queryVisibleUsernames.has(query)) {
        verifiedPresentUsernames.add(query);
      } else if (
        queryStopReason !== 'ui-stop-requested'
        && stopReason !== 'instagram-rate-limit'
        && queryApplied
      ) {
        verifiedMissingUsernames.add(query);
      }
    }

    queryStats.push({
      query,
      depth,
      source,
      rounds: queryRounds,
      addedCount: queryAddedCount,
      visibleCount: queryVisibleUsernames.size,
      stopReason: queryStopReason,
    });

    if (targetReached()) {
      stopReason = queryStopReason;
      break;
    }

    if (stopReason === 'ui-stop-requested') {
      break;
    }

    if (searchRounds >= maxScrollRounds) {
      stopReason = 'search-max-scroll-rounds-reached';
      break;
    }

    if (
      depth === 1
      && queryIndex === queries.length - 1
      && maxSearchDepth >= 2
      && !targetReached()
      && searchRounds < maxScrollRounds
    ) {
      const secondLevelQueries = buildSecondLevelRelationshipSearchQueries(
        queryStats,
        usersByUsername,
        queuedQueries,
      );

      for (const secondLevelQuery of secondLevelQueries) {
        queuedQueries.add(secondLevelQuery);
        queryQueue.push({
          query: secondLevelQuery,
          depth: 2,
          source: 'alphabet-subpartition',
        });
      }

      expandedQueryCount = secondLevelQueries.length;

      progressLog('relationship-search-expanded', {
        relationship: normalizedRelationship,
        loaded: usersByUsername.size,
        expectedCount,
        maxItems,
        searchRounds,
        generatedQueries: secondLevelQueries.length,
        totalQueries: queryQueue.length,
        maxDepth: maxSearchDepth,
      });
    }

    if (queries.length > 0 && depth === 1 && queryIndex === queries.length - 1) {
      for (const prioritizedQuery of prioritizedQueries) {
        queryQueue.push({
          query: prioritizedQuery,
          depth: 0,
          source: 'missing-known-profile',
        });
      }
    }

    await sleep(450);
  }

  await setRelationshipDialogSearchQuery(page, '', searchWaitMs);
  await closeInstagramDialog(page);

  return {
    attempted: true,
    inputAvailable,
    queries: queriesRun,
    rounds: searchRounds,
    addedCount,
    openAttempts,
    stopReason: stopReason || (
      searchTargetUsername && usersByUsername.has(searchTargetUsername)
        ? 'target-found'
        : (targetReached() ? 'expected-count-reached' : 'search-partitions-exhausted')
    ),
    rateLimited: false,
    rateLimitText: null,
    maxDepth: maxSearchDepth,
    expandedQueryCount,
    verifiedMissingUsernames: Array.from(verifiedMissingUsernames),
    verifiedPresentUsernames: Array.from(verifiedPresentUsernames),
    gracefullyStopped: stopReason === 'ui-stop-requested',
  };
}

async function collectPublicRelationshipSearchOnlyList(page, username, profile, relationship, options = {}) {
  const runtimeConfig = options.runtimeConfig || {};
  const normalizedRelationship = relationship === 'following' ? 'following' : 'followers';
  const targetUsername = normalizeInstagramUsername(runtimeConfig.relationshipSearchTargetUsername || options.targetUsername || '');
  const maxItems = normalizeOptionalPositiveInteger(runtimeConfig.relationshipSearchTargetMaxItems, 0);
  const maxScrollRounds = Math.max(
    5,
    normalizeOptionalPositiveInteger(runtimeConfig.relationshipSearchTargetMaxScrollRounds, 60) || 60,
  );
  const result = {
    attempted: false,
    available: false,
    complete: false,
    count: 0,
    maxItems,
    expectedCount: targetUsername ? 1 : 0,
    items: [],
    targetUsername: targetUsername || null,
    targetFound: false,
    targetItem: null,
    reason: null,
    rateLimited: false,
    rateLimitText: null,
    openAttempts: 0,
    scrollRounds: 0,
    noProgressReopenLimit: 0,
    searchAttempted: false,
    searchInputAvailable: false,
    searchQueries: [],
    searchRounds: 0,
    searchAddedCount: 0,
    searchStopReason: null,
    searchMaxDepth: 0,
    searchExpandedQueryCount: 0,
    verifiedMissingUsernames: [],
    verifiedPresentUsernames: [],
    listTemporarilyUnavailable: false,
  };

  if (profile?.isPrivate) {
    result.reason = 'private-profile';
    return result;
  }

  if (profile?.requiresLogin) {
    result.reason = 'login-required';
    return result;
  }

  if (!targetUsername) {
    result.reason = 'search-target-missing';
    return result;
  }

  result.attempted = true;
  const usersByUsername = new Map();
  const searchResult = await collectRelationshipSearchPartitions(
    page,
    username,
    normalizedRelationship,
    {
      ...options,
      maxItems,
      expectedCount: 0,
      maxScrollRounds,
      searchMaxScrollRounds: maxScrollRounds,
      targetUsername,
      runtimeConfig: {
        ...runtimeConfig,
        relationshipSearchPartitionQueries: [targetUsername],
        relationshipSearchMaxDepth: 1,
        relationshipSearchTargetUsername: targetUsername,
      },
    },
    usersByUsername,
  );
  const items = Array.from(usersByUsername.values());
  const targetItem = usersByUsername.get(targetUsername) || null;

  result.searchAttempted = Boolean(searchResult.attempted);
  result.searchInputAvailable = Boolean(searchResult.inputAvailable);
  result.searchQueries = searchResult.queries || [];
  result.searchRounds = searchResult.rounds || 0;
  result.searchAddedCount = searchResult.addedCount || 0;
  result.searchStopReason = searchResult.stopReason || null;
  result.searchMaxDepth = searchResult.maxDepth || 0;
  result.searchExpandedQueryCount = searchResult.expandedQueryCount || 0;
  result.verifiedMissingUsernames = Array.isArray(searchResult.verifiedMissingUsernames)
    ? searchResult.verifiedMissingUsernames
    : [];
  result.verifiedPresentUsernames = Array.isArray(searchResult.verifiedPresentUsernames)
    ? searchResult.verifiedPresentUsernames
    : [];
  result.openAttempts = searchResult.openAttempts || 0;
  result.scrollRounds = searchResult.rounds || 0;
  result.rateLimited = Boolean(searchResult.rateLimited);
  result.rateLimitText = searchResult.rateLimitText || null;
  result.gracefullyStopped = Boolean(searchResult.gracefullyStopped);
  result.items = items;
  result.count = items.length;
  result.available = items.length > 0;
  result.targetFound = Boolean(targetItem);
  result.targetItem = targetItem;
  result.complete = Boolean(targetItem) && !result.rateLimited;
  result.reason = result.rateLimited
    ? 'instagram-rate-limit'
    : result.gracefullyStopped
    ? 'ui-stop-requested'
    : result.targetFound
    ? null
    : (searchResult.stopReason || `${normalizedRelationship}-target-not-found`);

  progressLog('relationship-search-complete', {
    relationship: normalizedRelationship,
    loaded: result.count,
    expectedCount: result.expectedCount,
    maxItems,
    searchQueries: result.searchQueries.length,
    searchRounds: result.searchRounds,
    searchAddedCount: result.searchAddedCount,
    reason: result.reason,
    complete: result.complete,
    targetUsername,
    targetFound: result.targetFound,
  });

  return result;
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
  const partitionThreshold = Math.max(
    1,
    normalizeOptionalPositiveInteger(runtimeConfig.relationshipPartitionThreshold, 250) || 250,
  );
  let usePartitionedCollection = runtimeConfig.relationshipPartitionLargeLists !== false
    && expectedCount >= partitionThreshold;
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
    searchMaxDepth: 0,
    searchExpandedQueryCount: 0,
    verifiedMissingUsernames: [],
    verifiedPresentUsernames: [],
    partitioned: usePartitionedCollection,
    partitionThreshold,
    partitionMaxItems: runtimeConfig.relationshipSearchPartitionMaxItems || 250,
    listTemporarilyUnavailable: false,
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
  const hoverCardUsernames = new Set();
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

  if (usePartitionedCollection) {
    stopReason = 'partitioned-large-list';
    progressLog('relationship-partition-mode', {
      relationship: normalizedRelationship,
      loaded: 0,
      expectedCount,
      maxItems,
      partitionThreshold,
      message: `${normalizedRelationship}-Liste hat mehr als ${partitionThreshold} Eintraege und wird direkt alphabetisch segmentiert.`,
    });
  }

  while (!usePartitionedCollection && totalScrollRounds < maxScrollRounds && !targetReached()) {
    if (markGracefulStopIfRequested(normalizedRelationship, {
      loaded: usersByUsername.size,
      expectedCount,
    })) {
      stopReason = 'ui-stop-requested';
      break;
    }

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
    let sawEntriesThisPass = false;
    let emptyAfterEntriesRounds = 0;

    while (totalScrollRounds < maxScrollRounds && !targetReached()) {
      const collection = await collectFollowerEntriesFromDialog(page);
      const rawEntries = Array.isArray(collection) ? collection : (collection.entries || []);
      const entries = await enrichProfileEntriesWithHoverCards(page, rawEntries, hoverCardUsernames, runtimeConfig);
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

      if (entries.length > 0) {
        sawEntriesThisPass = true;
        emptyAfterEntriesRounds = 0;
      } else if (sawEntriesThisPass || usersByUsername.size > 0) {
        emptyAfterEntriesRounds++;

        if (emptyAfterEntriesRounds >= 2) {
          stopReason = 'relationship-list-temporarily-unavailable';
          result.listTemporarilyUnavailable = true;
          progressLog('relationship-list-temporarily-unavailable', {
            relationship: normalizedRelationship,
            loaded: usersByUsername.size,
            expectedCount,
            openAttempt: openAttempts,
            round: totalScrollRounds,
            message: `${normalizedRelationship}-Liste zeigt ploetzlich keine Profile mehr; diese Liste wird fuer diesen Lauf beendet.`,
          });
          break;
        }

        await sleep(1200);
        continue;
      }

      const addedItems = [];
      addRelationshipEntriesToMap(entries, usersByUsername, targetUsername, addedItems);

      totalScrollRounds++;
      passRounds++;

      if (usersByUsername.size === previousCount) {
        unchangedRounds++;
      } else {
        unchangedRounds = 0;
        previousCount = usersByUsername.size;
      }

      const livePreview = await captureLivePreviewScreenshot(page, runtimeConfig);

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
        itemsDelta: buildRelationshipProgressItems(addedItems),
        itemsPreview: buildRelationshipProgressPreview(usersByUsername),
        ...livePreview,
      });

      if (markGracefulStopIfRequested(normalizedRelationship, {
        loaded: usersByUsername.size,
        expectedCount,
        round: totalScrollRounds,
        passRound: passRounds,
        openAttempt: openAttempts,
      })) {
        stopReason = 'ui-stop-requested';
        break;
      }

      if (targetReached()) {
        stopReason = expectedCount > 0 && usersByUsername.size >= expectedCount
          ? 'expected-count-reached'
          : 'max-items-reached';
        break;
      }

      if (
        runtimeConfig.relationshipPartitionLargeLists !== false
        && usersByUsername.size > partitionThreshold
      ) {
        usePartitionedCollection = true;
        result.partitioned = true;
        stopReason = 'partition-threshold-reached';
        progressLog('relationship-partition-mode', {
          relationship: normalizedRelationship,
          loaded: usersByUsername.size,
          expectedCount,
          maxItems,
          partitionThreshold,
          itemsPreview: buildRelationshipProgressPreview(
            usersByUsername,
            runtimeConfig.relationshipProgressCheckpointSize || 250,
          ),
          message: `${normalizedRelationship}-Liste hat ${usersByUsername.size} Eintraege erreicht; der Zwischenstand wird gespeichert und alphabetisch fortgesetzt.`,
        });
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
      itemsPreview: buildRelationshipProgressPreview(usersByUsername),
    });

    await closeInstagramDialog(page);

    if (
      stopReason === 'instagram-rate-limit'
      || stopReason === 'ui-stop-requested'
      || stopReason === 'relationship-list-temporarily-unavailable'
    ) {
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

  const missingKnownRelationshipSearchQueries = getMissingPrioritizedRelationshipSearchQueries(
    runtimeConfig,
    normalizedRelationship,
    usersByUsername,
  );
  const shouldAttemptRelationshipSearch = Boolean(
    usePartitionedCollection
    || missingKnownRelationshipSearchQueries.length > 0
    || (Array.isArray(runtimeConfig.relationshipSearchPartitionQueries)
      && runtimeConfig.relationshipSearchPartitionQueries.length > 0)
    || normalizeInstagramUsername(options.targetUsername || runtimeConfig.relationshipSearchTargetUsername || ''),
  );

  if (
    stopReason !== 'instagram-rate-limit'
    && stopReason !== 'ui-stop-requested'
    && stopReason !== 'relationship-list-temporarily-unavailable'
    && shouldAttemptRelationshipSearch
    && (
      (expectedCount > 0 && usersByUsername.size < expectedCount)
      || missingKnownRelationshipSearchQueries.length > 0
      || usePartitionedCollection
    )
  ) {
    const searchResult = await collectRelationshipSearchPartitions(
      page,
      username,
      normalizedRelationship,
      {
        ...options,
        maxScrollRounds,
        hoverCardUsernames,
        runtimeConfig: {
          ...runtimeConfig,
          relationshipSearchVerifyMissingOnly: !usePartitionedCollection
            && missingKnownRelationshipSearchQueries.length > 0,
        },
      },
      usersByUsername,
    );

    result.searchAttempted = Boolean(searchResult.attempted);
    result.searchInputAvailable = Boolean(searchResult.inputAvailable);
    result.searchQueries = searchResult.queries || [];
    result.searchRounds = searchResult.rounds || 0;
    result.searchAddedCount = searchResult.addedCount || 0;
    result.searchStopReason = searchResult.stopReason || null;
    result.searchMaxDepth = searchResult.maxDepth || 0;
    result.searchExpandedQueryCount = searchResult.expandedQueryCount || 0;
    result.verifiedMissingUsernames = Array.isArray(searchResult.verifiedMissingUsernames)
      ? searchResult.verifiedMissingUsernames
      : [];
    result.verifiedPresentUsernames = Array.isArray(searchResult.verifiedPresentUsernames)
      ? searchResult.verifiedPresentUsernames
      : [];
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
      searchMaxDepth: result.searchMaxDepth,
      searchExpandedQueryCount: result.searchExpandedQueryCount,
      reason: result.searchStopReason,
      complete: !searchResult.rateLimited && !searchResult.gracefullyStopped,
      itemsPreview: buildRelationshipProgressPreview(
        usersByUsername,
        runtimeConfig.relationshipProgressCheckpointSize || 250,
      ),
    });

    if (searchResult.gracefullyStopped) {
      stopReason = 'ui-stop-requested';
    } else if (searchResult.rateLimited) {
      stopReason = 'instagram-rate-limit';
      result.rateLimited = true;
      result.rateLimitText = searchResult.rateLimitText || null;
    } else {
      stopReason = searchResult.stopReason || (
        expectedCount > 0 && usersByUsername.size >= expectedCount
          ? 'expected-count-reached-after-search'
          : 'search-partitions-exhausted'
      );
    }
  }

  result.items = hasItemLimit
    ? Array.from(usersByUsername.values()).slice(0, maxItems)
    : Array.from(usersByUsername.values());
  result.count = result.items.length;
  result.available = result.count > 0;
  result.openAttempts = openAttempts;
  result.scrollRounds = totalScrollRounds;
  result.complete = stopReason !== 'instagram-rate-limit'
    && stopReason !== 'ui-stop-requested'
    && stopReason !== 'relationship-list-temporarily-unavailable'
    && (
      result.searchAttempted
        ? result.searchInputAvailable && ![
          'search-dialog-not-found',
          'search-input-not-found',
          'search-input-lost',
          'search-query-not-applied',
          'search-max-scroll-rounds-reached',
        ].includes(result.searchStopReason)
        : ((!hasItemLimit || result.count < maxItems) && [
          'suggestions-section-reached',
          'no-new-items-after-reopen',
          'pass-bottom-stale',
          'pass-scroll-stale',
          'pass-no-new-items',
        ].includes(stopReason))
    );
  result.reason = stopReason === 'instagram-rate-limit'
    ? stopReason
    : stopReason === 'ui-stop-requested'
    ? stopReason
    : result.available
    ? (result.complete ? null : (stopReason || `incomplete-${normalizedRelationship}-list`))
    : `no-${normalizedRelationship}-found`;
  result.gracefullyStopped = stopReason === 'ui-stop-requested';

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
    partitioned: usePartitionedCollection,
    partitionThreshold,
    verifiedMissingUsernames: result.verifiedMissingUsernames,
    verifiedPresentUsernames: result.verifiedPresentUsernames,
    itemsPreview: buildRelationshipProgressPreview(
      usersByUsername,
      runtimeConfig.relationshipProgressCheckpointSize || 250,
    ),
  });

  return result;
}

async function collectPublicFollowersList(page, username, profile, options = {}) {
  return collectPublicRelationshipList(page, username, profile, 'followers', options);
}

async function collectPublicFollowingList(page, username, profile, options = {}) {
  return collectPublicRelationshipList(page, username, profile, 'following', options);
}

async function runProfileSuggestionConnectionScan(page, runtimeState, notes, targetUsername, profileUrl) {
  return runProfileSuggestionConnectionScanFromModule({
    captureLivePreviewScreenshot,
    clickProfileSuggestionsSeeAll,
    closeInstagramDialog,
    collectProfileInfo,
    collectProfileSuggestionItemsDeep,
    collectSuggestionCandidatePublicListConnection,
    diagnoseProfileSuggestionsSurface,
    detectInstagramHttp429Page,
    dismissVisibleSuggestion,
    hasFiniteNumericValue,
    markGracefulStopIfRequested,
    navigateWithSoftTimeout,
    normalizeInstagramUsername,
    normalizeSuggestionCandidateHistory,
    progressLog,
    scrollToProfileSuggestions,
    switchScraperAccountAfterRateLimit,
    sleep,
  }, page, runtimeState, notes, targetUsername, profileUrl, {
    deepSearch: operationMode === 'suggestion-connections',
  });
}

function summarizeSuggestionPublicListResult(result = {}) {
  const targetItem = result.targetItem && typeof result.targetItem === 'object'
    ? {
      username: normalizeInstagramUsername(result.targetItem.username || '') || null,
      displayName: normalizeText(String(result.targetItem.displayName || result.targetItem.fullName || '')) || null,
      profileUrl: normalizeText(String(result.targetItem.profileUrl || result.targetItem.url || '')) || null,
      profileImageUrl: normalizeText(String(result.targetItem.profileImageUrl || result.targetItem.profile_image_url || '')) || null,
    }
    : null;

  return {
    attempted: Boolean(result.attempted),
    available: Boolean(result.available || result.searchAttempted),
    targetFound: Boolean(result.targetFound),
    targetItem,
    reason: result.reason || null,
    rateLimited: Boolean(result.rateLimited),
    rateLimitText: result.rateLimitText || null,
    searchAttempted: Boolean(result.searchAttempted),
    searchInputAvailable: Boolean(result.searchInputAvailable),
    searchQueries: Array.isArray(result.searchQueries) ? result.searchQueries.slice(0, 12) : [],
    searchRounds: Number(result.searchRounds || 0),
    searchStopReason: result.searchStopReason || null,
    openAttempts: Number(result.openAttempts || 0),
    gracefullyStopped: Boolean(result.gracefullyStopped),
  };
}

function isSuggestionPublicListSearchConclusive(result = {}) {
  return Boolean(result.attempted)
    && Boolean(result.searchAttempted)
    && Boolean(result.searchInputAvailable)
    && !Boolean(result.rateLimited)
    && !Boolean(result.gracefullyStopped);
}

async function detectInstagramHttp429Page(page) {
  const diagnostics = await page.evaluate(() => {
    const text = String(document.body?.innerText || document.body?.textContent || '')
      .replace(/\s+/g, ' ')
      .trim();

    return {
      title: String(document.title || ''),
      text: text.slice(0, 1000),
      url: String(window.location.href || ''),
    };
  }).catch(() => ({
    title: '',
    text: '',
    url: '',
  }));
  const haystack = `${diagnostics.title} ${diagnostics.text}`.toLowerCase();

  return {
    ...diagnostics,
    detected: /http\s*error\s*429|error\s*429|this page isn'?t working|too many requests|status\s*429|t(?:a|\u00e4)gliches zeitlimit erreicht|du hast dein t(?:a|\u00e4)gliches zeitlimit erreicht|daily time limit reached|reached your daily time limit/.test(haystack),
  };
}

async function collectSuggestionCandidatePublicListConnection(
  page,
  candidate,
  candidateProfile,
  targetUsername,
  runtimeConfig,
) {
  const searchRuntimeConfig = {
    ...runtimeConfig,
    relationshipSearchOnly: true,
    relationshipSearchTargetUsername: targetUsername,
    relationshipSearchTargetMaxItems: 1,
    relationshipSearchTargetMaxScrollRounds: Math.max(
      5,
      normalizeOptionalPositiveInteger(
        runtimeConfig.suggestionPublicListSearchMaxScrollRounds,
        runtimeConfig.relationshipSearchTargetMaxScrollRounds || 60,
      ) || 60,
    ),
  };
  const searchOptionsFor = (relationship) => ({
    ...buildRelationshipCollectionOptions(searchRuntimeConfig, relationship),
    runtimeConfig: searchRuntimeConfig,
    targetUsername,
  });
  const followers = await collectPublicRelationshipSearchOnlyList(
    page,
    candidate.username,
    candidateProfile,
    'followers',
    searchOptionsFor('followers'),
  );
  let following = {
    attempted: false,
    targetFound: false,
    reason: followers.rateLimited ? 'skipped-after-rate-limit' : null,
    rateLimited: false,
    gracefullyStopped: false,
  };

  if (!followers.rateLimited && !followers.gracefullyStopped) {
    following = await collectPublicRelationshipSearchOnlyList(
      page,
      candidate.username,
      candidateProfile,
      'following',
      searchOptionsFor('following'),
    );
  }

  const summarizedFollowers = summarizeSuggestionPublicListResult(followers);
  const summarizedFollowing = summarizeSuggestionPublicListResult(following);
  const rateLimited = Boolean(followers.rateLimited || following.rateLimited);
  const gracefullyStopped = Boolean(followers.gracefullyStopped || following.gracefullyStopped);
  const conclusive = isSuggestionPublicListSearchConclusive(followers)
    && isSuggestionPublicListSearchConclusive(following);

  return {
    attempted: true,
    conclusive,
    targetFound: Boolean(followers.targetFound || following.targetFound),
    targetFoundInFollowers: Boolean(followers.targetFound),
    targetFoundInFollowing: Boolean(following.targetFound),
    rateLimited,
    rateLimitText: followers.rateLimitText || following.rateLimitText || null,
    gracefullyStopped,
    followers: summarizedFollowers,
    following: summarizedFollowing,
  };
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

async function scrollToProfileSuggestions(page, maxRounds = 5) {
  let lastScrollY = -1;

  for (let round = 0; round < maxRounds; round += 1) {
    const state = await page.evaluate(() => {
      const normalizeElementText = (value = '') => String(value).replace(/\s+/g, ' ').trim();
      const suggestionHeadingPattern = /^(f(?:u|\u00fc)r dich vorgeschlagen|vorschl(?:a|\u00e4)ge f(?:u|\u00fc)r dich|vorschl(?:a|\u00e4)ge|suggested for you|suggestions for you|suggested|suggestions)$/i;
      const isVisible = (element) => {
        if (!element) {
          return false;
        }

        const rect = element.getBoundingClientRect();
        const style = window.getComputedStyle(element);

        return rect.width > 0
          && rect.height > 0
          && style.visibility !== 'hidden'
          && style.display !== 'none'
          && rect.bottom >= 0
          && rect.top <= window.innerHeight;
      };
      const heading = Array.from(document.querySelectorAll('span, div, h1, h2, h3, h4, p'))
        .find((element) => {
          if (!isVisible(element)) {
            return false;
          }

          const ownText = normalizeElementText(
            Array.from(element.childNodes || [])
              .filter((node) => node.nodeType === Node.TEXT_NODE)
              .map((node) => node.textContent || '')
              .join(' '),
          );
          const fullText = normalizeElementText(element.innerText || element.textContent || '');
          const text = ownText || fullText;

          return text !== '' && text.length <= 80 && suggestionHeadingPattern.test(text);
        }) || null;

      if (heading) {
        return {
          before: window.scrollY,
          after: window.scrollY,
          atBottom: window.scrollY + window.innerHeight >= document.documentElement.scrollHeight - 12,
          suggestionsVisible: true,
          alreadyVisible: true,
        };
      }

      const before = window.scrollY;
      window.scrollBy(0, Math.max(700, window.innerHeight * 0.9));
      const after = window.scrollY;
      const text = String(document.body?.innerText || document.body?.textContent || '');
      const suggestionsVisible = /(?:vorschl(?:a|\u00e4)ge|f(?:u|\u00fc)r dich vorgeschlagen|suggested|suggestions)/i.test(text);

      return {
        before,
        after,
        atBottom: after + window.innerHeight >= document.documentElement.scrollHeight - 12,
        suggestionsVisible,
        alreadyVisible: false,
      };
    }).catch(() => ({
      before: 0,
      after: 0,
      atBottom: true,
      suggestionsVisible: false,
      alreadyVisible: false,
    }));

    await sleep(650);

    if (state.suggestionsVisible || state.atBottom || state.after === lastScrollY) {
      return state;
    }

    lastScrollY = state.after;
  }

  return {
    suggestionsVisible: false,
    atBottom: false,
  };
}

async function diagnoseProfileSuggestionsSurface(page, currentUsername) {
  const normalizedCurrentUsername = normalizeInstagramUsername(currentUsername);

  return page.evaluate(({ currentUsername }) => {
    const normalizeElementText = (value = '') => String(value).replace(/\s+/g, ' ').trim();
    const normalizeUsername = (value = '') => String(value || '')
      .replace(/^@/, '')
      .replace(/[^a-z0-9._]/gi, '')
      .toLowerCase();
    const suggestionPattern = /(?:f(?:u|\u00fc)r dich vorgeschlagen|vorschl(?:a|\u00e4)ge|suggested|suggestions)/i;
    const seeAllPattern = /^(alle ansehen|alle anzeigen|see all|show all)$/i;
    const reservedPaths = new Set(['accounts', 'direct', 'explore', 'p', 'reel', 'reels', 'stories', 'tv']);
    const isVisible = (element) => {
      if (!element) {
        return false;
      }

      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);

      return rect.width > 0
        && rect.height > 0
        && style.visibility !== 'hidden'
        && style.display !== 'none'
        && rect.bottom >= -40
        && rect.top <= window.innerHeight + 240;
    };
    const profileUsernameFromHref = (href) => {
      try {
        const parts = new URL(href, window.location.origin).pathname.split('/').filter(Boolean);

        if (parts.length !== 1 || reservedPaths.has(parts[0])) {
          return '';
        }

        const username = normalizeUsername(parts[0]);

        return username && username !== currentUsername ? username : '';
      } catch (error) {
        return '';
      }
    };
    const bodyText = normalizeElementText(document.body?.innerText || document.body?.textContent || '');
    const visibleTextSamples = [];
    const seenTexts = new Set();

    for (const element of Array.from(document.querySelectorAll('span, div, a, button, h1, h2, h3, p'))) {
      if (!isVisible(element)) {
        continue;
      }

      const text = normalizeElementText(element.innerText || element.textContent || '');

      if (text === '' || text.length > 120 || seenTexts.has(text.toLowerCase())) {
        continue;
      }

      const rect = element.getBoundingClientRect();
      seenTexts.add(text.toLowerCase());
      visibleTextSamples.push({
        text,
        tag: element.tagName?.toLowerCase?.() || '',
        role: element.getAttribute?.('role') || null,
        top: Math.round(rect.top),
        left: Math.round(rect.left),
        width: Math.round(rect.width),
        height: Math.round(rect.height),
        looksLikeUsername: /^[a-z0-9._]{3,30}$/.test(normalizeUsername(text)),
        normalizedUsername: normalizeUsername(text),
      });

      if (visibleTextSamples.length >= 60) {
        break;
      }
    }

    const visibleAnchors = Array.from(document.querySelectorAll('a[href]'))
      .filter(isVisible)
      .slice(0, 80)
      .map((anchor) => {
        const rect = anchor.getBoundingClientRect();
        const href = anchor.getAttribute('href') || '';

        return {
          href,
          text: normalizeElementText(anchor.innerText || anchor.textContent || ''),
          username: profileUsernameFromHref(href),
          top: Math.round(rect.top),
          left: Math.round(rect.left),
          width: Math.round(rect.width),
          height: Math.round(rect.height),
        };
      });

    const seeAllCandidates = Array.from(document.querySelectorAll('button, a, div[role="button"], span'))
      .filter(isVisible)
      .map((element) => {
        const text = normalizeElementText(element.innerText || element.textContent || '');
        const rect = element.getBoundingClientRect();

        return {
          text,
          matches: seeAllPattern.test(text),
          top: Math.round(rect.top),
          left: Math.round(rect.left),
          width: Math.round(rect.width),
          height: Math.round(rect.height),
        };
      })
      .filter((entry) => entry.matches || /alle|see|show|vorschl|suggest/i.test(entry.text))
      .slice(0, 30);

    const scrollableContainers = Array.from(document.querySelectorAll('div, section, ul'))
      .filter(isVisible)
      .map((element) => {
        const rect = element.getBoundingClientRect();
        const text = normalizeElementText(element.innerText || element.textContent || '');
        const anchorCount = Array.from(element.querySelectorAll('a[href]'))
          .filter((anchor) => profileUsernameFromHref(anchor.getAttribute('href') || ''))
          .length;

        return {
          textPreview: text.slice(0, 180),
          anchorCount,
          top: Math.round(rect.top),
          left: Math.round(rect.left),
          width: Math.round(rect.width),
          height: Math.round(rect.height),
          scrollWidth: Math.round(element.scrollWidth || 0),
          clientWidth: Math.round(element.clientWidth || 0),
          scrollHeight: Math.round(element.scrollHeight || 0),
          clientHeight: Math.round(element.clientHeight || 0),
          horizontalOverflow: element.scrollWidth > element.clientWidth + 24,
          verticalOverflow: element.scrollHeight > element.clientHeight + 24,
        };
      })
      .filter((entry) => (
        entry.anchorCount > 0
        || entry.horizontalOverflow
        || suggestionPattern.test(entry.textPreview)
      ))
      .slice(0, 20);

    return {
      url: String(window.location.href || ''),
      title: String(document.title || ''),
      scrollY: Math.round(window.scrollY || 0),
      viewport: {
        width: Math.round(window.innerWidth || 0),
        height: Math.round(window.innerHeight || 0),
      },
      bodyContainsSuggestionText: suggestionPattern.test(bodyText),
      bodyTextPreview: bodyText.slice(0, 600),
      visibleTextSamples,
      visibleAnchors,
      profileAnchorUsernames: visibleAnchors.map((anchor) => anchor.username).filter(Boolean).slice(0, 60),
      seeAllCandidates,
      scrollableContainers,
    };
  }, { currentUsername: normalizedCurrentUsername }).catch((error) => ({
    error: String(error?.message || error || 'surface-diagnostics-error'),
    url: '',
    title: '',
    bodyContainsSuggestionText: false,
    visibleTextSamples: [],
    visibleAnchors: [],
    profileAnchorUsernames: [],
    seeAllCandidates: [],
    scrollableContainers: [],
  }));
}

async function clickProfileSuggestionsSeeAll(page) {
  return page.evaluate(() => {
    const normalizeElementText = (value = '') => String(value).replace(/\s+/g, ' ').trim();
    const visible = (element) => {
      const rect = element.getBoundingClientRect();

      return rect.width > 0 && rect.height > 0;
    };
    const seeAllPattern = /^(alle ansehen|alle anzeigen|see all|show all)$/i;
    const suggestionHeadingPattern = /^(f(?:u|\u00fc)r dich vorgeschlagen|vorschl(?:a|\u00e4)ge f(?:u|\u00fc)r dich|vorschl(?:a|\u00e4)ge|suggested for you|suggestions for you|suggested|suggestions)$/i;
    const discoverMorePattern = /(?:entdecke mehr konten|weitere konten entdecken|discover more accounts|find more accounts)/i;
    const heading = Array.from(document.querySelectorAll('span, div, h1, h2, h3, h4, p'))
      .filter(visible)
      .find((element) => {
        const text = normalizeElementText(element.innerText || element.textContent || '');

        return text !== '' && text.length <= 80 && suggestionHeadingPattern.test(text);
      }) || null;
    const headingRect = heading?.getBoundingClientRect?.() || null;
    const elements = Array.from(document.querySelectorAll('button, a, div[role="button"], span'))
      .filter(visible);
    const candidates = elements
      .map((element) => {
        const text = normalizeElementText(element.innerText || element.textContent || '');
        const rect = element.getBoundingClientRect();
        const afterHeading = heading
          ? Boolean(heading.compareDocumentPosition(element) & Node.DOCUMENT_POSITION_FOLLOWING)
          : true;

        return {
          element,
          text,
          rect,
          afterHeading,
          score: (afterHeading ? 20 : 0)
            + (headingRect && rect.top >= headingRect.top - 40 && rect.top <= headingRect.bottom + 180 ? 12 : 0)
            + (rect.left > window.innerWidth * 0.45 ? 6 : 0)
            - Math.max(0, Math.floor(Math.abs(rect.top - (headingRect?.top || rect.top)) / 120)),
        };
      })
      .filter((entry) => (
        entry.text !== ''
        && entry.text.length <= 60
        && seeAllPattern.test(entry.text)
        && !discoverMorePattern.test(entry.text)
        && entry.afterHeading
      ))
      .sort((left, right) => right.score - left.score);
    const target = candidates[0]?.element || null;

    if (!target) {
      return {
        clicked: false,
        reason: 'see-all-not-found',
        headingFound: Boolean(heading),
        candidatesSeen: candidates.length,
      };
    }

    const clickable = target.closest('button, a, div[role="button"]') || target;

    clickable.scrollIntoView?.({ block: 'center', inline: 'center' });
    clickable.click();

    return {
      clicked: true,
      reason: 'clicked',
      headingFound: Boolean(heading),
      candidatesSeen: candidates.length,
      text: normalizeElementText(clickable.innerText || clickable.textContent || ''),
    };
  }).catch((error) => ({
    clicked: false,
    reason: `error:${error?.message || error}`,
    headingFound: false,
    candidatesSeen: 0,
  }));
}

async function waitForSuggestionsDialog(page, timeoutMs = 3500) {
  const startedAt = Date.now();

  while (Date.now() - startedAt < timeoutMs) {
    const state = await page.evaluate(() => {
      const normalizeElementText = (value = '') => String(value).replace(/\s+/g, ' ').trim();
      const suggestionPattern = /(?:f(?:u|\u00fc)r dich vorgeschlagen|vorschl(?:a|\u00e4)ge|suggested|suggestions)/i;
      const dialog = document.querySelector('div[role="dialog"]');

      if (!dialog) {
        return {
          open: false,
          headingText: null,
          textPreview: '',
          profileLinkCount: 0,
        };
      }

      const text = normalizeElementText(dialog.innerText || dialog.textContent || '');
      const profileLinkCount = Array.from(dialog.querySelectorAll('a[href]'))
        .filter((anchor) => {
          try {
            const parts = new URL(anchor.getAttribute('href'), window.location.origin).pathname.split('/').filter(Boolean);

            return parts.length === 1;
          } catch (error) {
            return false;
          }
        }).length;

      return {
        open: true,
        headingText: suggestionPattern.test(text) ? text.slice(0, 120) : null,
        textPreview: text.slice(0, 300),
        profileLinkCount,
      };
    }).catch(() => ({
      open: false,
      headingText: null,
      textPreview: '',
      profileLinkCount: 0,
    }));

    if (state.open) {
      return state;
    }

    await sleep(250);
  }

  return {
    open: false,
    headingText: null,
    textPreview: '',
    profileLinkCount: 0,
  };
}

async function collectProfileSuggestionItems(page, currentUsername, maxItems = 12) {
  const normalizedCurrentUsername = normalizeInstagramUsername(currentUsername);
  const limit = Math.max(1, Number(maxItems || 12));

  return page.evaluate(({ currentUsername, limit }) => {
    const normalizeElementText = (value = '') => String(value).replace(/\s+/g, ' ').trim();
    const normalizeUsername = (value = '') => String(value || '')
      .replace(/^@/, '')
      .replace(/[^a-z0-9._]/gi, '')
      .toLowerCase();
    const rateLimitPattern = /(?:versuche es sp(?:a|\u00e4)ter noch einmal|zu viele anfragen|wir schr(?:a|\u00e4)nken die h(?:a|\u00e4)ufigkeit|t(?:a|\u00e4)gliches zeitlimit erreicht|du hast dein t(?:a|\u00e4)gliches zeitlimit erreicht|try again later|too many requests|429|error 429|we restrict certain activity|we limit how often|this action was blocked|daily time limit reached|reached your daily time limit|rate limit)/i;
    const discoverMorePattern = /(?:entdecke mehr konten|weitere konten entdecken|discover more accounts|find more accounts)/i;
    const bodyText = normalizeElementText(document.body?.innerText || document.body?.textContent || '');

    if (rateLimitPattern.test(bodyText)) {
      return {
        items: [],
        available: false,
        rateLimited: true,
        rateLimitText: bodyText.slice(0, 500),
        headingText: null,
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
    const suggestionHeadingPattern = /^(f(?:u|\u00fc)r dich vorgeschlagen|vorschl(?:a|\u00e4)ge f(?:u|\u00fc)r dich|vorschl(?:a|\u00e4)ge|suggested for you|suggestions for you|suggested|suggestions)$/i;
    const dialog = document.querySelector('div[role="dialog"]');
    const container = dialog || document.body;
    const isVisible = (element) => {
      if (!element) {
        return false;
      }

      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);

      return rect.width > 0
        && rect.height > 0
        && style.visibility !== 'hidden'
        && style.display !== 'none';
    };
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
    const suggestionHeading = Array.from(container.querySelectorAll('span, div, h1, h2, h3, h4, p'))
      .find(isSuggestionHeading) || null;
    const isAfterSuggestionHeading = (element) => {
      if (!suggestionHeading) {
        return true;
      }

      return Boolean(suggestionHeading.compareDocumentPosition(element) & Node.DOCUMENT_POSITION_FOLLOWING);
    };
    const profileUsernameFromAnchor = (anchor) => {
      try {
        const parts = new URL(anchor.getAttribute('href'), window.location.origin).pathname
          .split('/')
          .filter(Boolean);

        if (parts.length !== 1 || reservedPaths.has(parts[0])) {
          return '';
        }

        const username = normalizeUsername(parts[0]);

        return username && username !== currentUsername && /^[a-z0-9._]+$/i.test(username)
          ? username
          : '';
      } catch (error) {
        return '';
      }
    };
    const profileAnchorsIn = (element) => Array.from(element.querySelectorAll('a[href]'))
      .filter((anchor) => profileUsernameFromAnchor(anchor));
    const isDiscoverMoreElement = (element) => {
      const clickable = element?.closest?.('button, [role="button"], a') || element;
      const text = normalizeElementText(clickable?.innerText || clickable?.textContent || '');

      return discoverMorePattern.test(text);
    };
    const findDialogListScope = () => {
      if (!dialog) {
        return null;
      }

      return Array.from(dialog.querySelectorAll('div, section, ul'))
        .filter(isVisible)
        .map((element) => {
          const profileAnchorCount = profileAnchorsIn(element).length;
          const verticalOverflow = element.scrollHeight > element.clientHeight + 24;
          const rect = element.getBoundingClientRect();
          const text = normalizeElementText(element.innerText || element.textContent || '');
          const score = (profileAnchorCount * 8)
            + (verticalOverflow ? 12 : 0)
            - Math.max(0, Math.floor(text.length / 1600))
            - (rect.height > window.innerHeight * 0.95 ? 8 : 0);

          return {
            element,
            profileAnchorCount,
            score,
          };
        })
        .filter((entry) => entry.profileAnchorCount > 0)
        .sort((left, right) => right.score - left.score)[0]?.element || dialog;
    };
    const findHorizontalSuggestionScope = () => {
      const candidateScopes = Array.from(document.querySelectorAll('div, section, ul'))
        .filter(isVisible)
        .map((element) => {
          const rect = element.getBoundingClientRect();
          const text = normalizeElementText(element.innerText || element.textContent || '');
          const profileAnchors = profileAnchorsIn(element);
          const anchorRects = profileAnchors
            .map((anchor) => anchor.getBoundingClientRect())
            .filter((rectEntry) => rectEntry.width > 0 && rectEntry.height > 0);
          const horizontalOverflow = element.scrollWidth > element.clientWidth + 24;
          const sameRowProfileAnchors = anchorRects.length >= 2
            && (Math.max(...anchorRects.map((rectEntry) => rectEntry.top))
              - Math.min(...anchorRects.map((rectEntry) => rectEntry.top))) <= 120;
          const closeToHeading = suggestionHeading
            ? Boolean(suggestionHeading.compareDocumentPosition(element) & Node.DOCUMENT_POSITION_FOLLOWING)
            : false;
          const suggestionText = suggestionHeadingPattern.test(text)
            || /(?:vorschl(?:a|\u00e4)ge|suggested|suggestions)/i.test(text);
          const horizontalList = horizontalOverflow || sameRowProfileAnchors;
          const score = (profileAnchors.length * 8)
            + (horizontalOverflow ? 14 : 0)
            + (sameRowProfileAnchors ? 8 : 0)
            + (suggestionText ? 10 : 0)
            + (closeToHeading ? 6 : 0)
            - Math.max(0, Math.floor(text.length / 1200))
            - (rect.height > 620 ? 10 : 0);

          return {
            element,
            rect,
            profileAnchorCount: profileAnchors.length,
            horizontalList,
            suggestionText,
            closeToHeading,
            score,
          };
        })
        .filter((entry) => (
          entry.profileAnchorCount > 0
          && entry.horizontalList
          && (entry.suggestionText || entry.closeToHeading || !suggestionHeading)
        ))
        .sort((left, right) => right.score - left.score);

      return candidateScopes[0]?.element || null;
    };
    const visibleElementDiagnostics = () => {
      const headingRect = suggestionHeading?.getBoundingClientRect?.() || null;
      const isRelevantVisibleElement = (element) => {
        if (!isVisible(element)) {
          return false;
        }

        if (suggestionHeading && !isAfterSuggestionHeading(element)) {
          return false;
        }

        const rect = element.getBoundingClientRect();

        return rect.top >= (headingRect ? headingRect.bottom - 28 : 0)
          && rect.top <= window.innerHeight + 220
          && rect.right >= -40
          && rect.left <= window.innerWidth + 220;
      };
      const textSamples = [];
      const seenTexts = new Set();

      for (const element of Array.from(container.querySelectorAll('span, div, a, button'))) {
        if (!isRelevantVisibleElement(element)) {
          continue;
        }

        const text = normalizeElementText(element.innerText || element.textContent || '');

        if (
          text === ''
          || text.length > 90
          || seenTexts.has(text.toLowerCase())
          || discoverMorePattern.test(text)
        ) {
          continue;
        }

        const rect = element.getBoundingClientRect();
        seenTexts.add(text.toLowerCase());
        textSamples.push({
          text,
          tag: element.tagName?.toLowerCase?.() || '',
          role: element.getAttribute?.('role') || null,
          top: Math.round(rect.top),
          left: Math.round(rect.left),
          width: Math.round(rect.width),
          height: Math.round(rect.height),
          normalizedUsername: normalizeUsername(text),
          usernamePatternMatch: /^[a-z0-9._]{3,30}$/.test(normalizeUsername(text)),
        });

        if (textSamples.length >= 40) {
          break;
        }
      }

      const anchorSamples = Array.from(container.querySelectorAll('a[href]'))
        .filter(isRelevantVisibleElement)
        .filter((anchor) => !isDiscoverMoreElement(anchor))
        .slice(0, 40)
        .map((anchor) => {
          const rect = anchor.getBoundingClientRect();
          let href = anchor.getAttribute('href') || '';
          let parsedUsername = '';

          try {
            const parts = new URL(href, window.location.origin).pathname.split('/').filter(Boolean);
            parsedUsername = parts.length === 1 ? normalizeUsername(parts[0]) : '';
          } catch (error) {
            href = '';
          }

          return {
            href,
            text: normalizeElementText(anchor.innerText || anchor.textContent || ''),
            parsedUsername,
            top: Math.round(rect.top),
            left: Math.round(rect.left),
            width: Math.round(rect.width),
            height: Math.round(rect.height),
          };
        });

      const scopeSamples = Array.from(document.querySelectorAll('div, section, ul'))
        .filter(isVisible)
        .map((element) => {
          const rect = element.getBoundingClientRect();
          const text = normalizeElementText(element.innerText || element.textContent || '');
          const profileAnchors = profileAnchorsIn(element);

          return {
            textPreview: text.slice(0, 180),
            profileAnchorCount: profileAnchors.length,
            scrollWidth: Math.round(element.scrollWidth || 0),
            clientWidth: Math.round(element.clientWidth || 0),
            scrollHeight: Math.round(element.scrollHeight || 0),
            clientHeight: Math.round(element.clientHeight || 0),
            horizontalOverflow: element.scrollWidth > element.clientWidth + 24,
            verticalOverflow: element.scrollHeight > element.clientHeight + 24,
            top: Math.round(rect.top),
            left: Math.round(rect.left),
            width: Math.round(rect.width),
            height: Math.round(rect.height),
          };
        })
        .filter((entry) => (
          /(?:vorschl(?:a|\u00e4)ge|suggested|suggestions|folgen|follow)/i.test(entry.textPreview)
          || entry.profileAnchorCount > 0
          || entry.horizontalOverflow
        ))
        .slice(0, 12);

      return {
        bodyContainsSuggestionText: /(?:vorschl(?:a|\u00e4)ge|f(?:u|\u00fc)r dich vorgeschlagen|suggested|suggestions)/i.test(bodyText),
        textSamples,
        anchorSamples,
        scopeSamples,
      };
    };
    const fallbackVisibleSuggestionAnchors = () => {
      const headingRect = suggestionHeading?.getBoundingClientRect?.() || null;

      return Array.from(container.querySelectorAll('a[href]'))
        .filter((anchor) => profileUsernameFromAnchor(anchor))
        .filter(isVisible)
        .filter((anchor) => !isDiscoverMoreElement(anchor))
        .filter((anchor) => {
          const rect = anchor.getBoundingClientRect();

          return isAfterSuggestionHeading(anchor)
            && rect.top >= (headingRect ? headingRect.bottom - 20 : 0)
            && rect.top <= window.innerHeight + 180
            && rect.left >= 0
            && rect.right <= window.innerWidth + 180;
        });
    };
    const fallbackVisibleSuggestionTextItems = () => {
      const headingRect = suggestionHeading?.getBoundingClientRect?.() || null;
      const ignoredTextPattern = /^(folgen|abonniert|entfernen|remove|follow|following|x|alle ansehen|alle anzeigen|see all|show all)$/i;
      const usernamePattern = /^[a-z0-9._]{3,30}$/;
      const textElements = Array.from(container.querySelectorAll('span, div, a, button'))
        .filter(isVisible)
        .filter((element) => isAfterSuggestionHeading(element))
        .filter((element) => {
          const rect = element.getBoundingClientRect();

          return rect.top >= (headingRect ? headingRect.bottom - 20 : 0)
            && rect.top <= window.innerHeight + 180
            && rect.left >= 0
            && rect.right <= window.innerWidth + 180;
        });
      const items = [];
      const seen = new Set();

      for (const element of textElements) {
        const rawText = normalizeElementText(element.innerText || element.textContent || '');
        const compactText = rawText.replace(/^@/, '').trim();

        if (
          rawText === ''
          || rawText.length > 40
          || ignoredTextPattern.test(rawText)
          || /\s/.test(compactText)
        ) {
          continue;
        }

        const username = normalizeUsername(compactText);

        if (
          !username
          || username === currentUsername
          || !usernamePattern.test(username)
          || reservedPaths.has(username)
          || seen.has(username)
        ) {
          continue;
        }

        const rawLooksLikeUsername = compactText === compactText.toLowerCase()
          || /[0-9._]/.test(compactText);

        if (!rawLooksLikeUsername) {
          continue;
        }

        const card = element.closest('div[role="button"], li, article, div') || element;

        if (isDiscoverMoreElement(card)) {
          continue;
        }

        const lines = Array.from(new Set(
          normalizeElementText(card.innerText || card.textContent || '')
            .split(' ')
            .map((line) => normalizeElementText(line))
            .filter(Boolean),
        ));
        const displayName = lines.find((line) => (
          line.toLowerCase() !== username
          && !ignoredTextPattern.test(line)
          && !usernamePattern.test(line.toLowerCase())
          && line.length <= 120
        )) || null;

        seen.add(username);
        items.push({
          username,
          displayName,
          profileUrl: `https://www.instagram.com/${username}/`,
          detectedFromVisibleText: true,
        });

        if (items.length >= limit) {
          break;
        }
      }

      return items;
    };
    const fallbackSuggestionCardItems = () => {
      const headingRect = suggestionHeading?.getBoundingClientRect?.() || null;
      const ignoredTokenPattern = /^(folgen|abonniert|entfernen|remove|follow|following|x|alle|ansehen|anzeigen|see|show|all|vorschlage|vorschlge|suggested|suggestions|fur|dich|for|you)$/i;
      const followActionPattern = /(?:^|\s)(?:folgen|abonniert|follow|following)(?:\s|$)/i;
      const usernamePattern = /^[a-z0-9._]{3,30}$/;
      const cardSelectors = 'li, article, div[role="button"], button, section > div';
      const cards = Array.from(container.querySelectorAll(cardSelectors))
        .filter(isVisible)
        .filter((element) => isAfterSuggestionHeading(element))
        .filter((element) => {
          const rect = element.getBoundingClientRect();

          return rect.top >= (headingRect ? headingRect.bottom - 24 : 0)
            && rect.top <= window.innerHeight + 220
            && rect.right >= -180
            && rect.left <= window.innerWidth + 180;
        });
      const items = [];
      const seen = new Set();

      for (const card of cards) {
        if (isDiscoverMoreElement(card)) {
          continue;
        }

        const rawText = normalizeElementText(card.innerText || card.textContent || '');
        const hasAvatar = Boolean(card.querySelector('img'));
        const hasProfileAnchor = profileAnchorsIn(card).length > 0;

        if (
          rawText === ''
          || rawText.length > 320
          || (!followActionPattern.test(rawText) && !hasAvatar && !hasProfileAnchor)
        ) {
          continue;
        }

        const tokens = rawText
          .split(/\s+/)
          .map((rawToken) => ({
            rawToken,
            username: rawToken.replace(/^@/, '').replace(/[^a-z0-9._]/gi, '').toLowerCase(),
          }))
          .filter(({ username }) => (
            usernamePattern.test(username)
            && username !== currentUsername
            && !reservedPaths.has(username)
            && !ignoredTokenPattern.test(username)
          ));
        const username = tokens.find(({ rawToken, username: tokenUsername }) => (
          rawToken.startsWith('@')
          || tokenUsername.includes('.')
          || tokenUsername.includes('_')
          || /\d/.test(tokenUsername)
          || rawToken === rawToken.toLowerCase()
        ))?.username || '';

        if (!username || seen.has(username)) {
          continue;
        }

        seen.add(username);
        items.push({
          username,
          displayName: null,
          profileUrl: `https://www.instagram.com/${username}/`,
          detectedFromVisibleCardText: true,
        });

        if (items.length >= limit) {
          break;
        }
      }

      return items;
    };
    const anchorScope = dialog ? findDialogListScope() : findHorizontalSuggestionScope();
    const scopedAnchors = anchorScope
      ? Array.from(anchorScope.querySelectorAll('a[href]'))
        .filter((anchor) => profileUsernameFromAnchor(anchor))
        .filter((anchor) => dialog || !suggestionHeading || isAfterSuggestionHeading(anchor) || anchorScope.contains(anchor))
        .filter((anchor) => !isDiscoverMoreElement(anchor))
      : [];
    const fallbackAnchors = fallbackVisibleSuggestionAnchors();
    const anchors = scopedAnchors.length > 0
      ? scopedAnchors
      : fallbackAnchors;
    const itemsByUsername = new Map();
    let profileLinkCandidatesSeen = 0;

    for (const anchor of anchors) {
      let pathname = '';

      try {
        pathname = new URL(anchor.getAttribute('href'), window.location.origin).pathname;
      } catch (error) {
        continue;
      }

      const parts = pathname.split('/').filter(Boolean);

      if (parts.length !== 1 || reservedPaths.has(parts[0])) {
        continue;
      }

      const username = normalizeUsername(parts[0]);

      if (!username || username === currentUsername || !/^[a-z0-9._]+$/i.test(username)) {
        continue;
      }

      profileLinkCandidatesSeen += 1;

      const row = anchor.closest('div[role="button"], li, article, div') || anchor;

      if (isDiscoverMoreElement(row)) {
        continue;
      }

      const rawLines = `${anchor.innerText || ''}\n${row.innerText || ''}`
        .split('\n')
        .map((line) => normalizeElementText(line))
        .filter(Boolean);
      const lines = Array.from(new Set(rawLines));
      const ignoredLinePattern = /^(folgen|abonniert|entfernen|remove|follow|following|x)$/i;
      const displayName = lines.find((line) => (
        line.toLowerCase() !== username
        && !ignoredLinePattern.test(line)
        && line.length <= 120
      )) || null;

      if (!itemsByUsername.has(username)) {
        itemsByUsername.set(username, {
          username,
          displayName,
          profileUrl: `https://www.instagram.com/${username}/`,
        });
      }

      if (itemsByUsername.size >= limit) {
        break;
      }
    }

    const textFallbackItems = fallbackVisibleSuggestionTextItems();
    const cardFallbackItems = fallbackSuggestionCardItems();

    for (const item of [...textFallbackItems, ...cardFallbackItems]) {
      if (!itemsByUsername.has(item.username) && itemsByUsername.size < limit) {
        itemsByUsername.set(item.username, item);
      }
    }

    return {
      items: Array.from(itemsByUsername.values()),
      available: Boolean(anchorScope || suggestionHeading || itemsByUsername.size > 0),
      rateLimited: false,
      rateLimitText: null,
      profileLinkCandidatesSeen,
      debug: {
        dialogOpen: Boolean(dialog),
        headingFound: Boolean(suggestionHeading),
        headingText: suggestionHeading
          ? normalizeElementText(suggestionHeading.innerText || suggestionHeading.textContent || '')
          : null,
        anchorScopeFound: Boolean(anchorScope),
        scopedAnchorsSeen: scopedAnchors.length,
        fallbackAnchorsSeen: fallbackAnchors.length,
        textFallbackItemsSeen: textFallbackItems.length,
        cardFallbackItemsSeen: cardFallbackItems.length,
        anchorsUsed: anchors.length,
        itemsFound: itemsByUsername.size,
        usernames: Array.from(itemsByUsername.keys()).slice(0, 12),
        diagnostics: visibleElementDiagnostics(),
      },
      headingText: suggestionHeading
        ? normalizeElementText(suggestionHeading.innerText || suggestionHeading.textContent || '')
        : null,
    };
  }, { currentUsername: normalizedCurrentUsername, limit }).catch(() => ({
    items: [],
    available: false,
    rateLimited: false,
    rateLimitText: null,
    headingText: null,
  }));
}

async function advanceProfileSuggestionsViewport(page, options = {}) {
  return page.evaluate(({ mode }) => {
    const normalizeElementText = (value = '') => String(value).replace(/\s+/g, ' ').trim();
    const normalizeUsername = (value = '') => String(value || '')
      .replace(/^@/, '')
      .replace(/[^a-z0-9._]/gi, '')
      .toLowerCase();
    const isVisible = (element) => {
      if (!element) {
        return false;
      }

      const rect = element.getBoundingClientRect();
      const style = window.getComputedStyle(element);

      return rect.width > 0
        && rect.height > 0
        && style.visibility !== 'hidden'
        && style.display !== 'none';
    };
    const suggestionPattern = /(?:f(?:u|\u00fc)r dich vorgeschlagen|vorschl(?:a|\u00e4)ge|suggested|suggestions)/i;
    const suggestionHeadingPattern = /^(f(?:u|\u00fc)r dich vorgeschlagen|vorschl(?:a|\u00e4)ge f(?:u|\u00fc)r dich|vorschl(?:a|\u00e4)ge|suggested for you|suggestions for you|suggested|suggestions)$/i;
    const discoverMorePattern = /(?:entdecke mehr konten|weitere konten entdecken|discover more accounts|find more accounts)/i;
    const reservedPaths = new Set(['accounts', 'direct', 'explore', 'p', 'reel', 'reels', 'stories', 'tv']);
    const ignoredTextPattern = /^(folgen|abonniert|entfernen|remove|follow|following|x|alle ansehen|alle anzeigen|see all|show all)$/i;
    const usernamePattern = /^[a-z0-9._]{3,30}$/;
    const isDiscoverMoreElement = (element) => discoverMorePattern.test(
      normalizeElementText(element?.innerText || element?.textContent || ''),
    );
    const getOwnText = (element) => normalizeElementText(
      Array.from(element.childNodes || [])
        .filter((node) => node.nodeType === Node.TEXT_NODE)
        .map((node) => node.textContent || '')
        .join(' '),
    );
    const profileAnchorsIn = (element) => Array.from(element.querySelectorAll('a[href]')).filter((anchor) => {
      if (isDiscoverMoreElement(anchor.closest('button, [role="button"], a, div') || anchor)) {
        return false;
      }

      try {
        const parts = new URL(anchor.getAttribute('href'), window.location.origin).pathname
          .split('/')
          .filter(Boolean);

        return parts.length === 1 && !reservedPaths.has(parts[0]);
      } catch (error) {
        return false;
      }
    });
    const dialog = document.querySelector('div[role="dialog"]');
    const root = dialog || document.body;
    const suggestionHeading = Array.from(root.querySelectorAll('span, div, h1, h2, h3, h4, p'))
      .find((element) => {
        if (!isVisible(element)) {
          return false;
        }

        const ownText = getOwnText(element);
        const fullText = normalizeElementText(element.innerText || element.textContent || '');
        const text = ownText || fullText;

        return text !== '' && text.length <= 80 && suggestionHeadingPattern.test(text);
      }) || null;
    const isAfterSuggestionHeading = (element) => !suggestionHeading
      || Boolean(suggestionHeading.compareDocumentPosition(element) & Node.DOCUMENT_POSITION_FOLLOWING);
    const visibleUsernameTextRectsIn = (element) => {
      if (dialog) {
        return [];
      }

      const headingRect = suggestionHeading?.getBoundingClientRect?.() || null;

      return Array.from(element.querySelectorAll('span, div, a'))
        .filter(isVisible)
        .filter((textElement) => isAfterSuggestionHeading(textElement))
        .map((textElement) => {
          const rawText = normalizeElementText(textElement.innerText || textElement.textContent || '');

          if (
            rawText === ''
            || rawText.length > 40
            || ignoredTextPattern.test(rawText)
            || /[\s@]/.test(rawText)
          ) {
            return null;
          }

          const username = normalizeUsername(rawText);
          const rawLooksLikeUsername = rawText === rawText.toLowerCase() || /[0-9._]/.test(rawText);

          if (
            !username
            || !usernamePattern.test(username)
            || reservedPaths.has(username)
            || !rawLooksLikeUsername
            || isDiscoverMoreElement(textElement.closest('button, [role="button"], a, div') || textElement)
          ) {
            return null;
          }

          const rect = textElement.getBoundingClientRect();

          if (
            headingRect
            && (rect.top < headingRect.bottom - 20 || rect.top > window.innerHeight + 180)
          ) {
            return null;
          }

          return rect;
        })
        .filter(Boolean);
    };
    const scrollables = Array.from(root.querySelectorAll('div, section, ul'))
      .filter(isVisible)
      .map((element) => {
        const rect = element.getBoundingClientRect();
        const text = normalizeElementText(element.innerText || element.textContent || '');
        const profileAnchors = profileAnchorsIn(element);
        const profileAnchorCount = profileAnchors.length;
        const anchorRects = profileAnchors
          .map((anchor) => anchor.getBoundingClientRect())
          .filter((anchorRect) => anchorRect.width > 0 && anchorRect.height > 0);
        const textRects = visibleUsernameTextRectsIn(element)
          .filter((textRect) => textRect.width > 0 && textRect.height > 0);
        const profileCandidateCount = profileAnchorCount + textRects.length;
        const rowRects = [...anchorRects, ...textRects];
        const horizontalOverflow = element.scrollWidth > element.clientWidth + 30;
        const verticalOverflow = element.scrollHeight > element.clientHeight + 30;
        const sameRowProfileAnchors = rowRects.length >= 2
          && (Math.max(...rowRects.map((entryRect) => entryRect.top))
            - Math.min(...rowRects.map((entryRect) => entryRect.top))) <= 130;
        const suggestionText = suggestionPattern.test(text);
        const score = (profileCandidateCount * 4)
          + (horizontalOverflow ? 8 : 0)
          + (sameRowProfileAnchors ? 7 : 0)
          + (verticalOverflow ? 3 : 0)
          + (suggestionText ? 5 : 0)
          + (suggestionHeading && isAfterSuggestionHeading(element) ? 4 : 0)
          - Math.max(0, Math.floor(text.length / 1200));

        return {
          element,
          rect,
          profileAnchorCount,
          textUsernameCount: textRects.length,
          profileCandidateCount,
          horizontalOverflow,
          sameRowProfileAnchors,
          verticalOverflow,
          suggestionText,
          score,
        };
      })
      .filter((entry) => entry.profileCandidateCount > 0 && (entry.horizontalOverflow || entry.sameRowProfileAnchors || entry.verticalOverflow || entry.suggestionText))
      .sort((left, right) => right.score - left.score);

    const inlineOnly = mode === 'inline-carousel';
    const dialogOnly = mode === 'see-all-dialog';
    const horizontalTargetEntry = dialog || dialogOnly ? null : (
      scrollables.find((entry) => entry.horizontalOverflow)
      || scrollables.find((entry) => entry.sameRowProfileAnchors)
      || null
    );
    const horizontalTarget = horizontalTargetEntry?.element || null;
    const horizontalRect = horizontalTarget ? horizontalTarget.getBoundingClientRect() : null;
    const nextPattern = /(?:weiter|n(?:a|\u00e4)chste|next)/i;
    const previousPattern = /(?:zur(?:u|\u00fc)ck|vorherige|previous|back)/i;
    const dismissPattern = /(?:entfernen|remove|dismiss|schlie(?:s|ss)en|close)/i;
    const isDisabledControl = (element) => {
      const ariaDisabled = String(element.getAttribute?.('aria-disabled') || '').toLowerCase();
      const disabled = Boolean(element.disabled);
      const style = window.getComputedStyle(element);

      return disabled
        || ariaDisabled === 'true'
        || Number(style.opacity || 1) < 0.2
        || style.pointerEvents === 'none';
    };
    const nextControl = Array.from(root.querySelectorAll('button, [role="button"], [aria-label]'))
      .filter(isVisible)
      .filter((element) => !isDisabledControl(element))
      .filter((element) => !isDiscoverMoreElement(element))
      .map((element) => {
        const label = normalizeElementText([
          element.getAttribute?.('aria-label') || '',
          element.getAttribute?.('title') || '',
          element.innerText || '',
          element.textContent || '',
        ].join(' '));
        const rect = element.getBoundingClientRect();
        const centerY = rect.top + (rect.height / 2);
        const inHorizontalBand = horizontalRect
          ? centerY >= horizontalRect.top - 24 && centerY <= horizontalRect.bottom + 24
          : false;
        const onRightSide = horizontalRect
          ? rect.left >= horizontalRect.left + (horizontalRect.width * 0.55)
            && rect.right <= horizontalRect.right + 120
          : rect.left >= window.innerWidth * 0.55;
        const nearHorizontalRightEdge = horizontalRect
          ? rect.left >= horizontalRect.right - 110 && rect.right <= horizontalRect.right + 120
          : false;

        return {
          element,
          label,
          rect,
          inHorizontalBand,
          onRightSide,
          nearHorizontalRightEdge,
          verticalMiddle: horizontalRect
            ? centerY >= horizontalRect.top + (horizontalRect.height * 0.25)
              && centerY <= horizontalRect.bottom - (horizontalRect.height * 0.10)
            : true,
        };
      })
      .find((entry) => {
        if (
          nextPattern.test(entry.label)
          && entry.rect.width <= 96
          && entry.rect.height <= 96
          && !previousPattern.test(entry.label)
          && !dismissPattern.test(entry.label)
          && entry.rect.right > 0
          && entry.rect.left < window.innerWidth
        ) {
          return true;
        }

        return horizontalRect
          && entry.inHorizontalBand
          && entry.onRightSide
          && entry.nearHorizontalRightEdge
          && entry.verticalMiddle
          && entry.rect.width >= 28
          && entry.rect.height >= 28
          && entry.rect.width <= 72
          && entry.rect.height <= 72
          && entry.label === ''
          && !previousPattern.test(entry.label);
      }) || null;

    if (!dialog && nextControl) {
      nextControl.element.click();

      return {
        advanced: true,
        mode: 'next-control',
        atEnd: false,
        rightNavigationVisible: true,
      };
    }

    if (!dialog && horizontalTarget) {
      const before = horizontalTarget.scrollLeft;
      const step = Math.max(260, horizontalTarget.clientWidth * 0.82);
      horizontalTarget.scrollLeft = Math.min(horizontalTarget.scrollLeft + step, horizontalTarget.scrollWidth);
      const after = horizontalTarget.scrollLeft;

      if (after > before + 4) {
        return {
          advanced: true,
          mode: 'horizontal-scroll',
          atEnd: after + horizontalTarget.clientWidth >= horizontalTarget.scrollWidth - 8,
          rightNavigationVisible: false,
        };
      }
    }

    if (inlineOnly) {
      return {
        advanced: false,
        mode: 'inline-carousel-end',
        atEnd: true,
        rightNavigationVisible: false,
      };
    }

    const verticalTarget = scrollables.find((entry) => entry.verticalOverflow)?.element || null;

    if (verticalTarget) {
      const before = verticalTarget.scrollTop;
      const step = Math.max(420, verticalTarget.clientHeight * 0.82);
      verticalTarget.scrollTop = Math.min(verticalTarget.scrollTop + step, verticalTarget.scrollHeight);
      const after = verticalTarget.scrollTop;

      if (after > before + 4) {
        return {
          advanced: true,
          mode: 'vertical-scroll',
          atEnd: after + verticalTarget.clientHeight >= verticalTarget.scrollHeight - 8,
        };
      }
    }

    if (dialogOnly) {
      return {
        advanced: false,
        mode: 'dialog-list-end',
        atEnd: true,
      };
    }

    const before = window.scrollY;
    window.scrollBy(0, Math.max(520, window.innerHeight * 0.72));
    const after = window.scrollY;

    return {
      advanced: after > before + 4,
      mode: 'window-scroll',
      atEnd: after + window.innerHeight >= document.documentElement.scrollHeight - 12,
    };
  }, { mode: String(options.mode || '') }).catch(() => ({
    advanced: false,
    mode: 'error',
    atEnd: true,
  }));
}

function mergeSuggestionItemsByUsername(targetMap, items = [], ignoredUsernames = new Set(), limit = 60) {
  for (const item of items) {
    const username = normalizeInstagramUsername(item?.username || '');

    if (!username || ignoredUsernames.has(username) || targetMap.has(username)) {
      continue;
    }

    targetMap.set(username, {
      ...item,
      username,
    });

    if (targetMap.size >= limit) {
      break;
    }
  }
}

async function collectProfileSuggestionItemsDeep(page, currentUsername, maxItems = 60, options = {}) {
  const limit = Math.max(1, Number(maxItems || 60));
  const dismissUsernames = new Set(
    Array.from(options.dismissUsernames || [])
      .map((username) => normalizeInstagramUsername(username))
      .filter(Boolean),
  );
  const itemsByUsername = new Map();
  const dismissedKnownItems = [];
  const dismissedKnownUsernames = new Set();
  const seenKnownUsernames = new Set();
  const maxInlineRounds = Math.max(4, Number(options.maxInlineRounds || 18));
  const maxDialogRounds = Math.max(4, Number(options.maxDialogRounds || 24));
  const collectLimit = Math.max(limit + dismissUsernames.size + 12, limit * 2);
  const continueUntilEnd = options.continueUntilEnd === true;
  const hoverCardUsernames = options.hoverCardUsernames instanceof Set ? options.hoverCardUsernames : new Set();
  const runtimeConfig = options.runtimeConfig || {};
  const collectionDebugEvents = [];
  const scrollDebugEvents = [];
  const rememberDebugEvent = (target, event, limitCount = 80) => {
    target.push(event);

    if (target.length > limitCount) {
      target.splice(0, target.length - limitCount);
    }
  };
  const emitScrollPreview = async (phase, round, advanced) => {
    const livePreview = await captureLivePreviewScreenshot(page, runtimeConfig, true);
    const scrollDebugEvent = {
      phase,
      round,
      loaded: itemsByUsername.size,
      suggestionsObserved: itemsByUsername.size,
      suggestionKnownSeen: seenKnownUsernames.size,
      scrollAdvanced: Boolean(advanced?.advanced),
      scrollAtEnd: Boolean(advanced?.atEnd),
      scrollMode: advanced?.mode || null,
      rightNavigationVisible: Boolean(advanced?.rightNavigationVisible),
      liveScreenshotPath: livePreview.liveScreenshotPath || null,
    };

    rememberDebugEvent(scrollDebugEvents, {
      ...scrollDebugEvent,
    });

    progressLog('suggestions-scroll-preview', {
      relationship: 'suggestions',
      suggestionCollectionPhase: phase,
      round,
      loaded: itemsByUsername.size,
      expectedCount: limit,
      suggestionsObserved: itemsByUsername.size,
      suggestionKnownSeen: seenKnownUsernames.size,
      dismissedSuggestions: dismissedKnownItems.length,
      message: phase === 'see-all-dialog'
        ? `Vorschlags-Modal gescrollt: ${itemsByUsername.size} Vorschlaege erkannt.`
        : `Horizontale Vorschlagsliste gescrollt: ${itemsByUsername.size} Vorschlaege erkannt.`,
      scrollAdvanced: Boolean(advanced?.advanced),
      scrollAtEnd: Boolean(advanced?.atEnd),
      scrollMode: advanced?.mode || null,
      rightNavigationVisible: Boolean(advanced?.rightNavigationVisible),
      suggestionScrollDebug: scrollDebugEvent,
      ...livePreview,
    });
  };
  let available = false;
  let rateLimited = false;
  let rateLimitText = null;
  let headingText = null;
  let seeAllClicked = false;
  let rounds = 0;
  let profileLinkCandidatesSeen = 0;

  const collectRound = async (phase) => {
    const batch = await collectProfileSuggestionItems(page, currentUsername, collectLimit);

    rounds += 1;
    available = available || Boolean(batch.available);
    rateLimited = rateLimited || Boolean(batch.rateLimited);
    rateLimitText = batch.rateLimitText || rateLimitText;
    headingText = batch.headingText || headingText;
    profileLinkCandidatesSeen = Math.max(
      profileLinkCandidatesSeen,
      Number(batch.profileLinkCandidatesSeen || 0),
    );

    const shouldDebugCollection = Boolean(runtimeConfig.suggestionDebug)
      || rounds <= 3
      || Number(batch.items?.length || 0) === 0;
    if (shouldDebugCollection) {
      const debug = batch.debug || {};
      const debugMessage = [
        `Vorschlags-Debug ${phase}: ${Array.isArray(batch.items) ? batch.items.length : 0} Profile erkannt`,
        `Heading ${debug.headingFound ? 'ja' : 'nein'}`,
        `Scope ${debug.anchorScopeFound ? 'ja' : 'nein'}`,
        `sichtbare Links ${Number(debug.fallbackAnchorsSeen || 0)}`,
        `Textfallback ${Number(debug.textFallbackItemsSeen || 0)}`,
        `genutzt ${Number(debug.anchorsUsed || 0)}`,
      ].join(' | ');
      const debugEvent = {
        phase,
        round: rounds,
        message: debugMessage,
        batchItemsFound: Array.isArray(batch.items) ? batch.items.length : 0,
        itemsStored: itemsByUsername.size,
        profileLinkCandidatesSeen: Number(batch.profileLinkCandidatesSeen || 0),
        dialogOpen: Boolean(debug.dialogOpen),
        headingFound: Boolean(debug.headingFound),
        headingText: debug.headingText || null,
        anchorScopeFound: Boolean(debug.anchorScopeFound),
        scopedAnchorsSeen: Number(debug.scopedAnchorsSeen || 0),
        fallbackAnchorsSeen: Number(debug.fallbackAnchorsSeen || 0),
        textFallbackItemsSeen: Number(debug.textFallbackItemsSeen || 0),
        anchorsUsed: Number(debug.anchorsUsed || 0),
        usernames: Array.isArray(debug.usernames) ? debug.usernames.slice(0, 20) : [],
        rateLimited: Boolean(batch.rateLimited),
        rateLimitText: batch.rateLimitText || null,
        diagnostics: debug.diagnostics || null,
      };

      rememberDebugEvent(collectionDebugEvents, debugEvent);

      progressLog('suggestions-collection-debug', {
        relationship: 'suggestions',
        suggestionCollectionPhase: phase,
        loaded: itemsByUsername.size,
        expectedCount: limit,
        round: rounds,
        suggestionsObserved: itemsByUsername.size,
        batchItemsFound: Array.isArray(batch.items) ? batch.items.length : 0,
        profileLinkCandidatesSeen: Number(batch.profileLinkCandidatesSeen || 0),
        suggestionDebug: debug,
        message: debugMessage,
        ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
      });
    }

    if (batch.rateLimited) {
      return {
        stop: true,
        newItems: 0,
      };
    }

    batch.items = await enrichProfileEntriesWithHoverCards(
      page,
      batch.items || [],
      hoverCardUsernames,
      runtimeConfig,
    );

    let newItems = 0;

    for (const item of batch.items || []) {
      const username = normalizeInstagramUsername(item.username || '');

      if (!username) {
        continue;
      }

      if (dismissUsernames.has(username)) {
        seenKnownUsernames.add(username);

        if (!dismissedKnownUsernames.has(username)) {
          const dismissed = await dismissVisibleSuggestion(page, username, { allowDialog: true });

          if (dismissed) {
            dismissedKnownUsernames.add(username);
            dismissedKnownItems.push({
              ...item,
              username,
              dismissed: true,
              dismissedBeforeCheck: true,
              dismissReason: 'already-scanned-suggestion',
              collectionPhase: phase,
            });
            await sleep(500);
          }
        }

        continue;
      }

      if (!itemsByUsername.has(username) && itemsByUsername.size < limit) {
        newItems += 1;
      }
    }

    mergeSuggestionItemsByUsername(itemsByUsername, batch.items || [], dismissUsernames, limit);

    return {
      stop: !continueUntilEnd && itemsByUsername.size >= limit,
      newItems,
    };
  };

  const scanScrollableSuggestions = async (phase, maxRounds) => {
    let staleRounds = 0;

    for (let round = 0; round < maxRounds; round += 1) {
      const beforeSize = itemsByUsername.size;
      const collection = await collectRound(phase);

      if (collection.stop) {
        return;
      }

      staleRounds = collection.newItems === 0 && itemsByUsername.size === beforeSize
        ? staleRounds + 1
        : 0;

      const advanced = await advanceProfileSuggestionsViewport(page, { mode: phase });
      await sleep(advanced.advanced ? 1150 : 750);
      await emitScrollPreview(phase, round + 1, advanced);

      if (!advanced.advanced && staleRounds >= 2) {
        return;
      }

      if (advanced.atEnd && staleRounds >= 2) {
        return;
      }
    }
  };

  const shouldOpenSeeAllFirst = options.openSeeAllFirst === true || options.forceSeeAll === true;

  if (!shouldOpenSeeAllFirst) {
    await scanScrollableSuggestions('inline-carousel', maxInlineRounds);
  }

  if (options.includeSeeAll !== false && (itemsByUsername.size < limit || options.forceSeeAll === true) && !rateLimited) {
    const seeAllResult = await clickProfileSuggestionsSeeAll(page);
    seeAllClicked = Boolean(seeAllResult?.clicked);
    const dialogState = seeAllClicked
      ? await waitForSuggestionsDialog(page, 4500)
      : { open: false, headingText: null, textPreview: '', profileLinkCount: 0 };

    rememberDebugEvent(collectionDebugEvents, {
      phase: 'see-all-open',
      round: rounds + 1,
      message: seeAllClicked
        ? 'Vorschlagsliste per Alle ansehen geoeffnet.'
        : `Alle ansehen konnte nicht geklickt werden (${seeAllResult?.reason || 'unbekannt'}).`,
      batchItemsFound: 0,
      itemsStored: itemsByUsername.size,
      profileLinkCandidatesSeen,
      dialogOpen: Boolean(dialogState.open),
      headingFound: Boolean(seeAllResult?.headingFound || dialogState.headingText),
      headingText: dialogState.headingText || null,
      anchorScopeFound: false,
      scopedAnchorsSeen: Number(dialogState.profileLinkCount || 0),
      fallbackAnchorsSeen: 0,
      textFallbackItemsSeen: 0,
      anchorsUsed: 0,
      usernames: [],
      rateLimited: false,
      rateLimitText: null,
      seeAllResult,
      dialogState,
    });

    progressLog('suggestions-see-all-open', {
      relationship: 'suggestions',
      suggestionCollectionPhase: 'see-all-open',
      loaded: itemsByUsername.size,
      expectedCount: limit,
      suggestionsObserved: itemsByUsername.size,
      seeAllClicked,
      seeAllResult,
      suggestionsDialog: dialogState,
      message: seeAllClicked
        ? `Alle ansehen wurde geoeffnet; Dialog ${dialogState.open ? 'ist sichtbar' : 'wurde nicht erkannt'}.`
        : `Alle ansehen konnte nicht geoeffnet werden (${seeAllResult?.reason || 'unbekannt'}).`,
      ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
    });

    if (seeAllClicked) {
      await sleep(900);
      await scanScrollableSuggestions('see-all-dialog', maxDialogRounds);
    }
  }

  if (shouldOpenSeeAllFirst && !seeAllClicked && !rateLimited) {
    await scanScrollableSuggestions('inline-carousel', maxInlineRounds);
  }

  return {
    items: Array.from(itemsByUsername.values()).slice(0, limit),
    available,
    rateLimited,
    rateLimitText,
    headingText,
    seeAllClicked,
    rounds,
    dismissedKnownItems,
    dismissedKnownCount: dismissedKnownItems.length,
    seenKnownCount: seenKnownUsernames.size,
    profileLinkCandidatesSeen,
    collectionDebug: {
      rounds,
      profileLinkCandidatesSeen,
      events: collectionDebugEvents,
      scrollEvents: scrollDebugEvents,
      finalUsernames: Array.from(itemsByUsername.keys()).slice(0, 120),
      seenKnownUsernames: Array.from(seenKnownUsernames).slice(0, 120),
      dismissedKnownUsernames: Array.from(dismissedKnownUsernames).slice(0, 120),
    },
  };
}

async function dismissVisibleSuggestion(page, username, options = {}) {
  const normalizedUsername = normalizeInstagramUsername(username);
  const allowDialog = options.allowDialog === true;

  if (!normalizedUsername) {
    return false;
  }

  for (let attempt = 0; attempt < 8; attempt += 1) {
    const result = await page.evaluate(({ username, allowDialog }) => {
      const normalizeElementText = (value = '') => String(value).replace(/\s+/g, ' ').trim();
      const normalizeUsername = (value = '') => String(value || '')
        .replace(/^@/, '')
        .replace(/[^a-z0-9._]/gi, '')
        .toLowerCase();
      const isInsideDialog = (element) => Boolean(element?.closest?.('div[role="dialog"]'));
      const isVisible = (element) => {
        if (!element || (!allowDialog && isInsideDialog(element))) {
          return false;
        }

        const rect = element.getBoundingClientRect();
        const style = window.getComputedStyle(element);

        return rect.width > 0
          && rect.height > 0
          && style.visibility !== 'hidden'
          && style.display !== 'none';
      };
      const profileUsernameFromAnchor = (anchor) => {
        try {
          const parts = new URL(anchor.getAttribute('href'), window.location.origin).pathname
            .split('/')
            .filter(Boolean);

          return parts.length === 1 ? normalizeUsername(parts[0]) : '';
        } catch (error) {
          return '';
        }
      };
      const findAnchor = () => Array.from(document.querySelectorAll('a[href]'))
        .filter((anchorElement) => allowDialog || !isInsideDialog(anchorElement))
        .find((anchorElement) => profileUsernameFromAnchor(anchorElement) === username) || null;
      const removePattern = /^(x|entfernen|remove|close|schliessen|schlie(?:ss|\u00df)en|dismiss)$/i;
      const removeTextPattern = /(?:entfernen|remove|close|schliessen|schlie(?:ss|\u00df)en|dismiss)/i;
      const nonDismissPattern = /(?:folgen|abonniert|follow|following|nachricht|message|profil|profile|mehr|more)/i;
      const clickableFor = (element) => element.closest('button, [role="button"]') || element;
      const elementLabel = (element) => normalizeElementText([
        element.getAttribute?.('aria-label') || '',
        element.getAttribute?.('title') || '',
        element.innerText || '',
        element.textContent || '',
      ].join(' '));
      const findCardContainer = (anchor) => {
        let best = anchor;

        for (let element = anchor; element && element !== document.body; element = element.parentElement) {
          if (!allowDialog && isInsideDialog(element)) {
            return null;
          }

          const text = normalizeElementText(element.innerText || element.textContent || '');
          const rect = element.getBoundingClientRect();

          if (
            text.toLowerCase().includes(username)
            && rect.width >= 80
            && rect.height >= 40
            && text.length <= 1200
          ) {
            best = element;
          }

          if (text.length > 1200 || rect.height > 650 || rect.width > Math.max(900, window.innerWidth * 0.95)) {
            break;
          }
        }

        return best;
      };
      const findDismissControl = (card, anchor) => {
        const controls = Array.from(card.querySelectorAll('button, [role="button"], [aria-label], svg[aria-label], svg[role="img"]'))
          .filter((element) => allowDialog || !isInsideDialog(element));

        for (const control of controls) {
          const label = elementLabel(control);

          if (
            label
            && !nonDismissPattern.test(label)
            && (removePattern.test(label) || removeTextPattern.test(label))
          ) {
            const clickable = clickableFor(control);

            if (isVisible(clickable)) {
              return clickable;
            }
          }
        }

        const buttonControls = Array.from(card.querySelectorAll('button, [role="button"]'))
          .filter(isVisible);
        const cardRect = card.getBoundingClientRect();
        const anchorRect = anchor.getBoundingClientRect();

        for (const control of buttonControls) {
          const label = elementLabel(control);

          if (label && nonDismissPattern.test(label)) {
            continue;
          }

          const rect = control.getBoundingClientRect();
          const smallControl = rect.width <= 56 && rect.height <= 56;
          const nearTop = rect.top <= Math.max(anchorRect.top + 70, cardRect.top + 90);
          const nearRight = rect.left >= cardRect.left + (cardRect.width * 0.45);

          if (smallControl && nearTop && nearRight) {
            return control;
          }
        }

        return null;
      };
      const clickCarouselNext = () => {
        const nextPattern = /(?:weiter|n(?:a|\u00e4)chste|next)/i;
        const root = allowDialog ? (document.querySelector('div[role="dialog"]') || document) : document;
        const controls = Array.from(root.querySelectorAll('button, [role="button"], [aria-label]'))
          .filter(isVisible)
          .filter((element) => {
            const label = elementLabel(element);

            if (!nextPattern.test(label)) {
              return false;
            }

            const rect = element.getBoundingClientRect();

            return rect.top > 0
              && rect.top < document.documentElement.scrollHeight
              && rect.width <= 90
              && rect.height <= 90;
          });

        const control = controls[0] || null;

        if (!control) {
          return false;
        }

        control.click();
        return true;
      };
      const anchor = findAnchor();

      if (!anchor) {
        return {
          status: clickCarouselNext() ? 'advanced' : 'not-found',
        };
      }

      anchor.scrollIntoView({
        block: 'center',
        inline: 'center',
      });

      const card = findCardContainer(anchor);

      if (!card) {
        return { status: 'card-missing' };
      }

      const control = findDismissControl(card, anchor);

      if (!control) {
        return { status: 'control-missing' };
      }

      control.click();

      return { status: 'dismissed' };
    }, { username: normalizedUsername, allowDialog }).catch(() => ({ status: 'error' }));

    if (result?.status === 'dismissed') {
      return true;
    }

    await sleep(result?.status === 'advanced' ? 550 : 300);
  }

  return false;
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

  let loginNavigation = null;
  const loginUrl = 'https://www.instagram.com/accounts/login/';
  const loginTimeout = Math.min(resolveNavigationWaitMs(runtimeConfig, 120000), isLoginSessionMode ? 15000 : 20000);

  for (let attempt = 1; attempt <= 3; attempt++) {
    loginNavigation = await navigateWithSoftTimeout(
      page,
      loginUrl,
      runtimeConfig,
      { timeoutMs: loginTimeout },
    );

    if (loginNavigation.ok) {
      break;
    }

    diagnostics.warnings.push(`Instagram-Login-Seite konnte nicht stabil geladen werden: ${loginNavigation.error}`);
    recordRunDebug('auto-login-navigation-retry', {
      attempt,
      status: loginNavigation.status,
      error: loginNavigation.error,
      url: loginUrl,
    });

    if (loginNavigation.status !== 429 || attempt === 3) {
      break;
    }

    await sleep(1500 + attempt * 500);
  }

  if (!loginNavigation.ok) {
    const pageDiagnostics = await collectPageDiagnostics(page, { includeCookies: true });
    diagnostics.finalUrl = pageDiagnostics.url;
    diagnostics.title = pageDiagnostics.title;
    diagnostics.bodyPreview = pageDiagnostics.bodyPreview;
    diagnostics.warnings.push('Instagram-Login-Seite konnte nach mehreren Versuchen nicht geladen werden. Auto-Login wird abgebrochen.');
    recordRunDebug('auto-login-navigation-failed', pageDiagnostics);
    return diagnostics;
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
  const cookieDiagnostics = await primeInstagramSession(page, buildCookiePath(runtimeConfig, pathHelperOptions), runtimeConfig);
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

  if (isLoginSessionMode && resolvePuppeteerHeadlessMode(runtimeConfig, runtimeConfigModuleOptions) === false) {
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

async function detectInstagramDailyTimeLimit(page) {
  return page.evaluate(() => {
    const bodyText = String(document.body?.innerText || '').replace(/\s+/g, ' ').trim();
    const normalized = bodyText.toLowerCase();
    const matched = [
      'du hast dein tägliches zeitlimit erreicht',
      'du hast dein taegliches zeitlimit erreicht',
      'tägliches zeitlimit erreicht',
      'taegliches zeitlimit erreicht',
      'you have reached your daily time limit',
      "you've reached your daily time limit",
      'daily time limit reached',
    ].some((needle) => normalized.includes(needle));

    return {
      matched,
      text: matched ? bodyText.slice(0, 1000) : null,
      url: window.location.href,
    };
  }).catch(() => ({
    matched: false,
    text: null,
    url: page.url(),
  }));
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

  const blockedInfo = persistScraperProfileBlock(runtimeConfig, rateLimitText || 'instagram-rate-limit', {
    relationship,
    text: rateLimitText || null,
  });

  if (blockedInfo) {
    notes.push(`Scraper-Profil gesperrt: ${blockedInfo.reason}.`);
    recordRunDebug('scraper-profile-blocked', {
      relationship,
      profileLabel: runtimeConfig.profileLabel || null,
      blockedInfo,
    });
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
      const switchCookiePath = buildCookiePath(nextRuntimeConfig, pathHelperOptions);
      const cookiesSavedAfterSwitch = await saveCookiesToFile(page, switchCookiePath);

      if (cookiesSavedAfterSwitch.saved) {
        notes.push(`Sessiondaten fuer ${toLabel} wurden gespeichert.`);
      }

      notes.push(`Scraper-Account aktiv: ${toLabel}.`);
      setActiveScraperProfile(nextRuntimeConfig);
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

async function recoverFromInstagramDailyTimeLimit(
  page,
  runtimeState,
  notes,
  relationship,
  retryUrl,
) {
  const maxAttempts = Math.max(1, getRuntimeAccountPool(runtimeState.runtimeConfig).length);
  let switched = false;

  for (let attempt = 0; attempt < maxAttempts; attempt += 1) {
    const detection = await detectInstagramDailyTimeLimit(page);

    if (!detection.matched) {
      return {
        detected: switched,
        switched,
        recovered: true,
        exhausted: false,
      };
    }

    const rateLimitText = detection.text || 'Instagram daily time limit reached';

    progressLog('instagram-daily-time-limit', {
      relationship,
      rateLimited: true,
      reason: 'instagram-daily-time-limit',
      rateLimitText,
      url: detection.url,
      message: 'Instagram-Zeitlimit erkannt; Scraper-Profil wird gewechselt.',
      ...(await captureLivePreviewScreenshot(page, runtimeState.runtimeConfig, true)),
    });
    recordRunDebug('instagram-daily-time-limit-detected', {
      relationship,
      attempt: attempt + 1,
      url: detection.url,
      text: rateLimitText,
      profileLabel: runtimeState.runtimeConfig.profileLabel || null,
    });

    const switchResult = await switchScraperAccountAfterRateLimit(
      page,
      runtimeState.runtimeConfig,
      notes,
      relationship,
      'instagram-daily-time-limit',
      runtimeState.usedAccountKeys,
    );

    if (!switchResult?.sessionEstablished) {
      runtimeState.cookieSaveDisabled = true;

      return {
        detected: true,
        switched,
        recovered: false,
        exhausted: true,
      };
    }

    switched = true;
    runtimeState.runtimeConfig = switchResult.runtimeConfig;
    runtimeState.cookieFilePath = buildCookiePath(switchResult.runtimeConfig, pathHelperOptions);
    runtimeState.cookieDiagnostics = switchResult.cookieDiagnostics;
    runtimeState.loginDiagnostics = switchResult.loginDiagnostics;

    const navigation = await navigateWithSoftTimeout(
      page,
      retryUrl || page.url(),
      runtimeState.runtimeConfig,
    );

    if (!navigation.ok) {
      notes.push(`Zielseite konnte nach dem Scraper-Profilwechsel nicht stabil geladen werden: ${navigation.error}`);
    }

    await sleep(1800);
  }

  const stillLimited = await detectInstagramDailyTimeLimit(page);

  return {
    detected: true,
    switched,
    recovered: !stillLimited.matched,
    exhausted: stillLimited.matched,
  };
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

  if (
    (runtimeConfig.relationshipSearchOnly || isRelationshipSearchOnlyMode)
    && normalizeInstagramUsername(runtimeConfig.relationshipSearchTargetUsername || '')
  ) {
    return collectPublicRelationshipSearchOnlyList(page, username, profile, normalizedRelationship, options);
  }

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
  const verifiedPresentUsernames = new Set([
    ...(Array.isArray(previousList.verifiedPresentUsernames) ? previousList.verifiedPresentUsernames : []),
    ...(Array.isArray(nextList.verifiedPresentUsernames) ? nextList.verifiedPresentUsernames : []),
  ].map(normalizeInstagramUsername).filter(Boolean));
  const verifiedMissingUsernames = new Set([
    ...(Array.isArray(previousList.verifiedMissingUsernames) ? previousList.verifiedMissingUsernames : []),
    ...(Array.isArray(nextList.verifiedMissingUsernames) ? nextList.verifiedMissingUsernames : []),
  ].map(normalizeInstagramUsername).filter(Boolean));

  for (const presentUsername of verifiedPresentUsernames) {
    verifiedMissingUsernames.delete(presentUsername);
  }

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
    listTemporarilyUnavailable: Boolean(previousList.listTemporarilyUnavailable || nextList.listTemporarilyUnavailable),
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
    searchMaxDepth: Math.max(Number(previousList.searchMaxDepth) || 0, Number(nextList.searchMaxDepth) || 0),
    searchExpandedQueryCount: (Number(previousList.searchExpandedQueryCount) || 0) + (Number(nextList.searchExpandedQueryCount) || 0),
    verifiedMissingUsernames: Array.from(verifiedMissingUsernames),
    verifiedPresentUsernames: Array.from(verifiedPresentUsernames),
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
    ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
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

    if (list?.gracefullyStopped) {
      break;
    }

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
    runtimeState.cookieFilePath = buildCookiePath(switchResult.runtimeConfig, pathHelperOptions);
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

function emptyBatchRelationshipList(relationship, profile, reason = null) {
  return {
    relationship: relationship === 'following' ? 'following' : 'followers',
    checked: false,
    available: false,
    complete: false,
    targetFound: false,
    targetItem: null,
    observedCount: 0,
    expectedCount: 0,
    reportedCount: 0,
    rateLimited: false,
    profileIsPrivate: typeof profile?.isPrivate === 'boolean' ? profile.isPrivate : null,
    profileRequiresLogin: typeof profile?.requiresLogin === 'boolean' ? profile.requiresLogin : null,
    reason,
    searchAttempted: false,
    searchInputAvailable: false,
    searchQueries: [],
    searchStopReason: null,
    debugLogPath: activeRunDebug?.filePath || null,
    error: null,
  };
}

function summarizeBatchRelationshipList(list, profile, relationship) {
  const normalizedRelationship = relationship === 'following' ? 'following' : 'followers';
  const items = Array.isArray(list?.items) ? list.items : [];

  return {
    relationship: normalizedRelationship,
    checked: Boolean(list?.attempted || list?.searchAttempted || list?.available || items.length > 0),
    available: Boolean(list?.available),
    complete: Boolean(list?.complete),
    targetFound: Boolean(list?.targetFound || list?.targetItem),
    targetItem: list?.targetItem || null,
    observedCount: items.length,
    expectedCount: Number(list?.expectedCount || 0),
    reportedCount: Number(list?.count || items.length || 0),
    rateLimited: Boolean(list?.rateLimited),
    gracefullyStopped: Boolean(list?.gracefullyStopped),
    profileIsPrivate: typeof profile?.isPrivate === 'boolean' ? profile.isPrivate : null,
    profileRequiresLogin: typeof profile?.requiresLogin === 'boolean' ? profile.requiresLogin : null,
    reason: list?.reason || null,
    searchAttempted: Boolean(list?.searchAttempted),
    searchInputAvailable: Boolean(list?.searchInputAvailable),
    searchQueries: Array.isArray(list?.searchQueries) ? list.searchQueries : [],
    searchStopReason: list?.searchStopReason || null,
    debugLogPath: activeRunDebug?.filePath || null,
    error: null,
  };
}

function isBatchRelationshipSearchDefinitive(list) {
  if (!list || list.rateLimited) {
    return false;
  }

  if (list.targetFound) {
    return true;
  }

  if (!list.searchAttempted || !list.searchInputAvailable) {
    return false;
  }

  return [
    'search-empty',
    'search-suggestions-section-reached',
    'search-bottom-stale',
    'search-scroll-stale',
    'search-no-new-items',
    'search-partitions-exhausted',
    'target-found',
    'followers-target-not-found',
    'following-target-not-found',
  ].includes(list.searchStopReason || list.reason || '');
}

function isBatchCandidateConnectionDefinitive(connection) {
  if (!connection) {
    return false;
  }

  if (connection.skippedReason) {
    return true;
  }

  return isBatchRelationshipSearchDefinitive(connection.followers)
    && isBatchRelationshipSearchDefinitive(connection.following);
}

function isBatchCandidateSearchInputMissing(connection) {
  if (!connection) {
    return false;
  }

  return [connection.followers, connection.following].some((list) => [
    'search-input-not-found',
    'search-input-lost',
  ].includes(list?.searchStopReason || list?.reason || ''));
}

function isBatchCandidateSearchDialogMissing(connection) {
  if (!connection) {
    return false;
  }

  return [connection.followers, connection.following].some((list) => (
    list?.searchStopReason || list?.reason || ''
  ) === 'search-dialog-not-found');
}

function isBatchCandidateRateLimited(connection) {
  return Boolean(connection?.followers?.rateLimited || connection?.following?.rateLimited);
}

function hasUnusedRuntimeAccount(runtimeConfig, usedAccountKeys) {
  return getRuntimeAccountPool(runtimeConfig).some((account) => {
    const candidate = {
      ...runtimeConfig,
      ...account,
      accountPool: getRuntimeAccountPool(runtimeConfig),
    };
    const accountKey = getScraperAccountKey(candidate);

    return accountKey && !usedAccountKeys.has(accountKey);
  });
}

function describeBatchCandidatePendingReason(connection) {
  if (!connection) {
    return 'kein Suchergebnis';
  }

  const reasons = [];

  if (!isBatchRelationshipSearchDefinitive(connection.followers)) {
    reasons.push(`Followerliste: ${connection.followers?.reason || connection.followers?.searchStopReason || 'nicht eindeutig durchsucht'}`);
  }

  if (!isBatchRelationshipSearchDefinitive(connection.following)) {
    reasons.push(`Gefolgt-Liste: ${connection.following?.reason || connection.following?.searchStopReason || 'nicht eindeutig durchsucht'}`);
  }

  return reasons.join('; ') || 'nicht eindeutig durchsucht';
}

async function captureCandidateDebugScreenshot(page, baseScreenshotPath, candidate, attempt, reason, notes, bucket = []) {
  if (!baseScreenshotPath) {
    return null;
  }

  const screenshotPath = buildRelatedScreenshotPath(
    baseScreenshotPath,
    `candidate-${candidate.username}-attempt-${attempt}`,
    pathHelperOptions,
  );
  const capturedPath = await captureDebugPageScreenshot(page, screenshotPath, notes);

  if (!capturedPath) {
    return null;
  }

  const entry = {
    candidateUsername: candidate.username,
    attempt,
    reason,
    screenshotPath: capturedPath,
  };

  bucket.push(entry);
  notes.push(`Debug-Screenshot fuer Kandidat @${candidate.username} gespeichert: ${capturedPath}`);

  return entry;
}

function resolvePublicConnectionRetryDelayMs(runtimeConfig = {}, attempt = 1, connection = null) {
  const rateLimited = Boolean(connection?.followers?.rateLimited || connection?.following?.rateLimited);
  const configuredBase = Number(runtimeConfig.publicConnectionRetryDelayMs || (rateLimited ? 15000 : 6000));
  const configuredMax = Number(runtimeConfig.publicConnectionRetryMaxDelayMs || (rateLimited ? 90000 : 30000));
  const baseDelay = Number.isFinite(configuredBase) ? Math.max(1500, configuredBase) : (rateLimited ? 15000 : 6000);
  const maxDelay = Number.isFinite(configuredMax) ? Math.max(baseDelay, configuredMax) : (rateLimited ? 90000 : 30000);

  return Math.min(maxDelay, baseDelay * Math.min(Math.max(1, attempt), 6));
}

function normalizePublicConnectionCandidate(candidate) {
  if (!candidate || typeof candidate !== 'object') {
    return null;
  }

  const candidateUsername = normalizeInstagramUsername(candidate.username);

  if (!candidateUsername) {
    return null;
  }

  return {
    username: candidateUsername,
    displayName: normalizeText(String(candidate.displayName || '')) || null,
    profileUrl: normalizeText(String(candidate.profileUrl || '')) || `https://www.instagram.com/${candidateUsername}/`,
    profileImageUrl: normalizeText(String(candidate.profileImageUrl || candidate.profile_image_url || '')) || null,
    profileVisibility: ['public', 'private', 'unknown'].includes(candidate.profileVisibility) ? candidate.profileVisibility : null,
    isPrivate: typeof candidate.isPrivate === 'boolean' ? candidate.isPrivate : null,
    postsCount: hasFiniteNumericValue(candidate.postsCount) ? Number(candidate.postsCount) : null,
    followersCount: hasFiniteNumericValue(candidate.followersCount) ? Number(candidate.followersCount) : null,
    followingCount: hasFiniteNumericValue(candidate.followingCount) ? Number(candidate.followingCount) : null,
    hoverCard: candidate.hoverCard && typeof candidate.hoverCard === 'object' ? candidate.hoverCard : null,
    sourceLists: Array.isArray(candidate.sourceLists)
      ? candidate.sourceLists.map((sourceList) => normalizeText(String(sourceList || ''))).filter(Boolean)
      : [],
  };
}

function buildBatchCandidateConnection(candidate, candidateFollowers, candidateFollowing) {
  const candidateFollowsTarget = Boolean(candidateFollowing.targetFound);
  const targetFollowsCandidate = Boolean(candidateFollowers.targetFound);
  const gracefullyStopped = Boolean(candidateFollowers.gracefullyStopped || candidateFollowing.gracefullyStopped);
  const skippedReason = candidateFollowers.profileIsPrivate || candidateFollowing.profileIsPrivate
    ? 'private-profile'
    : candidateFollowers.profileRequiresLogin || candidateFollowing.profileRequiresLogin
    ? 'login-required'
    : gracefullyStopped
    ? 'ui-stop-requested'
    : null;

  return {
    username: candidate.username,
    displayName: candidate.displayName,
    profileUrl: candidate.profileUrl,
    profileImageUrl: candidate.profileImageUrl || null,
    profileVisibility: candidate.profileVisibility || null,
    isPrivate: typeof candidate.isPrivate === 'boolean' ? candidate.isPrivate : null,
    postsCount: hasFiniteNumericValue(candidate.postsCount) ? Number(candidate.postsCount) : null,
    followersCount: hasFiniteNumericValue(candidate.followersCount) ? Number(candidate.followersCount) : null,
    followingCount: hasFiniteNumericValue(candidate.followingCount) ? Number(candidate.followingCount) : null,
    hoverCard: candidate.hoverCard || null,
    sourceLists: candidate.sourceLists,
    candidateFollowsTarget,
    targetFollowsCandidate,
    followerOfPrivateProfile: candidateFollowsTarget,
    followedByPrivateProfile: targetFollowsCandidate,
    skippedReason,
    gracefullyStopped,
    followers: candidateFollowers,
    following: candidateFollowing,
  };
}

function summarizeBatchCandidateConnectionForProgress(connection) {
  return {
    username: connection.username,
    displayName: connection.displayName || null,
    profileUrl: connection.profileUrl || `https://www.instagram.com/${connection.username}/`,
    profileImageUrl: connection.profileImageUrl || null,
    profileVisibility: connection.profileVisibility || null,
    isPrivate: typeof connection.isPrivate === 'boolean' ? connection.isPrivate : null,
    postsCount: hasFiniteNumericValue(connection.postsCount) ? Number(connection.postsCount) : null,
    followersCount: hasFiniteNumericValue(connection.followersCount) ? Number(connection.followersCount) : null,
    followingCount: hasFiniteNumericValue(connection.followingCount) ? Number(connection.followingCount) : null,
    hoverCard: connection.hoverCard || null,
    sourceLists: Array.isArray(connection.sourceLists) ? connection.sourceLists : [],
  };
}

function buildPublicConnectionProgressPreviews(candidateConnections) {
  return {
    inferredFollowersPreview: candidateConnections
      .filter((connection) => connection.followerOfPrivateProfile)
      .slice(-40)
      .map(summarizeBatchCandidateConnectionForProgress),
    inferredFollowingPreview: candidateConnections
      .filter((connection) => connection.followedByPrivateProfile)
      .slice(-40)
      .map(summarizeBatchCandidateConnectionForProgress),
  };
}

function markBatchCandidateConnectionFailed(connection, candidate, reason, attempt, screenshots = []) {
  const failureMessage = normalizeText(String(reason || 'candidate-error')) || 'candidate-error';
  const failedConnection = connection || buildBatchCandidateConnection(
    candidate,
    emptyBatchRelationshipList('followers', null, 'candidate-error'),
    emptyBatchRelationshipList('following', null, 'candidate-error'),
  );

  failedConnection.skippedReason = 'candidate-error';
  failedConnection.candidateError = failureMessage;
  failedConnection.scanAttempts = attempt;
  failedConnection.debugScreenshotPaths = screenshots.map((entry) => entry.screenshotPath).filter(Boolean);

  for (const list of [failedConnection.followers, failedConnection.following]) {
    if (!list) {
      continue;
    }

    list.reason = list.reason || 'candidate-error';
    list.searchStopReason = list.searchStopReason || 'candidate-error';
    list.error = failureMessage;
  }

  return failedConnection;
}

async function collectBatchCandidateConnectionOnce(page, runtimeState, notes, candidate, candidateProfileUrl) {
  const navigation = await navigateWithSoftTimeout(page, candidateProfileUrl, runtimeState.runtimeConfig, {
    timeoutMs: Math.max(15000, Math.min(resolveNavigationWaitMs(runtimeState.runtimeConfig), 45000)),
  });

  if (!navigation.ok) {
    notes.push(`Kandidat @${candidate.username} konnte nicht stabil geladen werden: ${navigation.error}`);
  }

  await sleep(750);

  const candidateProfile = await collectProfileInfo(page, candidate.username);
  let candidateFollowers = emptyBatchRelationshipList('followers', candidateProfile, null);
  let candidateFollowing = emptyBatchRelationshipList('following', candidateProfile, null);

  if (candidateProfile.isPrivate) {
    candidateFollowers = emptyBatchRelationshipList('followers', candidateProfile, 'private-profile');
    candidateFollowing = emptyBatchRelationshipList('following', candidateProfile, 'private-profile');
  } else if (candidateProfile.requiresLogin) {
    candidateFollowers = emptyBatchRelationshipList('followers', candidateProfile, 'login-required');
    candidateFollowing = emptyBatchRelationshipList('following', candidateProfile, 'login-required');
  } else {
    const followersResult = await collectRelationshipListWithAccountSwitches(
      page,
      candidate.username,
      candidateProfile,
      'followers',
      runtimeState,
      notes,
      candidateProfileUrl,
    );
    candidateFollowers = summarizeBatchRelationshipList(followersResult.list, followersResult.profile, 'followers');

    if (page.url().includes('/followers')) {
      await navigateWithSoftTimeout(page, candidateProfileUrl, runtimeState.runtimeConfig, {
        timeoutMs: Math.max(15000, Math.min(resolveNavigationWaitMs(runtimeState.runtimeConfig), 45000)),
      });
      await sleep(350);
    }

    const followingResult = await collectRelationshipListWithAccountSwitches(
      page,
      candidate.username,
      followersResult.profile || candidateProfile,
      'following',
      runtimeState,
      notes,
      candidateProfileUrl,
    );
    candidateFollowing = summarizeBatchRelationshipList(followingResult.list, followingResult.profile, 'following');
  }

  return buildBatchCandidateConnection(candidate, candidateFollowers, candidateFollowing);
}

async function collectBatchCandidateConnectionUntilDefinitive(
  page,
  runtimeState,
  notes,
  candidate,
  candidateProfileUrl,
  progressContext,
) {
  let attempt = 0;
  let lastConnection = null;
  const candidateScreenshots = [];
  const startedAt = Date.now();
  const maxAttempts = Math.min(
    10,
    Math.floor(normalizeNumberAtLeast(runtimeState.runtimeConfig.publicConnectionCandidateMaxAttempts, 3, 1)),
  );
  const maxDurationMs = normalizeNumberAtLeast(
    runtimeState.runtimeConfig.publicConnectionCandidateMaxDurationMs,
    1200000,
    60000,
  );
  const dialogMissingMaxAttempts = Math.floor(normalizeNumberAtLeast(
    runtimeState.runtimeConfig.publicConnectionDialogMissingMaxAttempts,
    2,
    1,
  ));

  while (true) {
    assertScriptAlive();
    attempt++;

    if (attempt > 1) {
      progressLog('candidate-scan-retry', {
        relationship: 'public-connections',
        loaded: progressContext.loaded,
        expectedCount: progressContext.expectedCount,
        candidateUsername: candidate.username,
        attempt,
        foundFollowers: progressContext.foundFollowers,
        foundFollowing: progressContext.foundFollowing,
        inferredFollowersPreview: progressContext.inferredFollowersPreview || [],
        inferredFollowingPreview: progressContext.inferredFollowingPreview || [],
        rateLimitedCandidates: progressContext.rateLimitedCandidates,
        message: `Kandidat @${candidate.username} wird erneut geprueft (Versuch ${attempt}); ${describeBatchCandidatePendingReason(lastConnection)}.`,
      });
    }

    const connection = await collectBatchCandidateConnectionOnce(
      page,
      runtimeState,
      notes,
      candidate,
      candidateProfileUrl,
    );
    connection.scanAttempts = attempt;
    connection.debugScreenshotPaths = candidateScreenshots.map((entry) => entry.screenshotPath);
    lastConnection = connection;

    if (isBatchCandidateConnectionDefinitive(connection)) {
      connection.debugScreenshotPaths = candidateScreenshots.map((entry) => entry.screenshotPath);
      return connection;
    }

    const pendingReason = describeBatchCandidatePendingReason(connection);
    const screenshotEntry = await captureCandidateDebugScreenshot(
      page,
      progressContext.screenshotPath,
      candidate,
      attempt,
      pendingReason,
      notes,
      progressContext.candidateErrorScreenshots,
    );

    if (screenshotEntry) {
      candidateScreenshots.push(screenshotEntry);
      connection.debugScreenshotPaths = candidateScreenshots.map((entry) => entry.screenshotPath);
    }

    const maxScraperProfileSwitches = Math.max(
      0,
      Math.min(
        10,
        Math.floor(normalizeNumberAtLeast(runtimeState.runtimeConfig.publicConnectionMaxScraperProfileSwitches, 3, 0)),
      ),
    );
    const usedScraperProfileSwitches = Math.max(0, runtimeState.usedAccountKeys.size - 1);

    if (
      isBatchCandidateRateLimited(connection)
      && runtimeState.runtimeConfig.publicConnectionRateLimitAccountSwitchEnabled !== false
      && hasUnusedRuntimeAccount(runtimeState.runtimeConfig, runtimeState.usedAccountKeys)
      && usedScraperProfileSwitches < maxScraperProfileSwitches
      && attempt < maxAttempts
    ) {
      progressLog('candidate-rate-limit-account-switch', {
        relationship: 'public-connections',
        loaded: progressContext.loaded,
        expectedCount: progressContext.expectedCount,
        candidateUsername: candidate.username,
        attempt,
        foundFollowers: progressContext.foundFollowers,
        foundFollowing: progressContext.foundFollowing,
        inferredFollowersPreview: progressContext.inferredFollowersPreview || [],
        inferredFollowingPreview: progressContext.inferredFollowingPreview || [],
        accountPoolSize: getRuntimeAccountPool(runtimeState.runtimeConfig).length,
        usedAccountCount: runtimeState.usedAccountKeys.size,
        usedScraperProfileSwitches,
        maxScraperProfileSwitches,
        message: `Rate-Limit bei @${candidate.username}; Scraper-Account wird gewechselt.`,
      });

      const switchResult = await switchScraperAccountAfterRateLimit(
        page,
        runtimeState.runtimeConfig,
        notes,
        'public-connections',
        connection.followers?.rateLimitText || connection.following?.rateLimitText || pendingReason,
        runtimeState.usedAccountKeys,
      );

      if (switchResult?.sessionEstablished) {
        runtimeState.runtimeConfig = switchResult.runtimeConfig;
        runtimeState.cookieFilePath = buildCookiePath(switchResult.runtimeConfig, pathHelperOptions);
        runtimeState.cookieDiagnostics = switchResult.cookieDiagnostics;
        runtimeState.loginDiagnostics = switchResult.loginDiagnostics;
        await closeInstagramDialog(page);
        notes.push(`Kandidat @${candidate.username} wird nach Account-Wechsel erneut geprueft.`);

        continue;
      }

      if (switchResult?.failed) {
        runtimeState.cookieSaveDisabled = true;
        notes.push(`Kein weiterer Scraper-Account konnte fuer @${candidate.username} stabil aktiviert werden.`);
      }
    }

    if (isBatchCandidateSearchDialogMissing(connection) && attempt >= dialogMissingMaxAttempts) {
      const message = `Kandidat @${candidate.username} wird ausgelassen, weil der Listen-Dialog nach ${dialogMissingMaxAttempts} Versuch(en) nicht gefunden wurde (${pendingReason}).`;
      notes.push(message);

      return markBatchCandidateConnectionFailed(
        lastConnection,
        candidate,
        message,
        attempt,
        candidateScreenshots,
      );
    }

    if (isBatchCandidateSearchInputMissing(connection)) {
      const message = `Kandidat @${candidate.username} konnte nicht geprueft werden, weil das Suchfeld nach 3 Versuch(en) nicht stabil gefunden wurde (${pendingReason}).`;
      notes.push(message);

      return markBatchCandidateConnectionFailed(
        lastConnection,
        candidate,
        message,
        attempt,
        candidateScreenshots,
      );
    }

    if (attempt >= maxAttempts || Date.now() - startedAt >= maxDurationMs) {
      const message = `Kandidat @${candidate.username} konnte nach ${attempt} Versuch(en) nicht eindeutig geprueft werden (${pendingReason}).`;
      notes.push(message);

      return markBatchCandidateConnectionFailed(
        lastConnection,
        candidate,
        message,
        attempt,
        candidateScreenshots,
      );
    }

    if (attempt === 1 || attempt % 5 === 0) {
      notes.push(`Kandidat @${candidate.username} wurde noch nicht abgeschlossen; ${pendingReason}. Naechster Versuch folgt in derselben Session.`);
    }

    await closeInstagramDialog(page);
    await sleep(resolvePublicConnectionRetryDelayMs(runtimeState.runtimeConfig, attempt, connection));
  }
}

function resolvePublicConnectionBatchStatus(candidatesTotal, candidatesChecked, candidateConnections) {
  if (candidatesTotal === 0) {
    return 'partial';
  }

  if (candidatesChecked === 0) {
    return 'error';
  }

  return candidateConnections.length > 0 ? 'success' : 'partial';
}

function resolvePublicConnectionBatchMessage(inferredFollowers, inferredFollowing, candidatesTotal, candidatesChecked, privateSkipped, rateLimitedCandidates = 0, failedCandidates = 0) {
  if (inferredFollowers.length > 0 || inferredFollowing.length > 0) {
    return `Teilrekonstruktion abgeschlossen: ${inferredFollowers.length} moegliche Follower und ${inferredFollowing.length} moegliche Gefolgt-Profile gefunden, ${failedCandidates} Kandidaten mit Fehlern.`;
  }

  if (candidatesChecked > 0) {
    return `Teilrekonstruktion abgeschlossen; ${candidatesChecked} von ${candidatesTotal} gespeicherten Kandidaten wurden geprueft, ${privateSkipped} private/gesperrte Profile uebersprungen, ${rateLimitedCandidates} per Rate-Limit blockiert, ${failedCandidates} mit Fehlern.`;
  }

  return 'Keine gespeicherten Kandidatenlisten fuer dieses bekannte Profil gefunden.';
}

async function runPublicConnectionBatch(page, runtimeState, notes, publicUsername, targetUsername, options = {}) {
  const allCandidates = Array.isArray(runtimeState.runtimeConfig.publicConnectionCandidates)
    ? runtimeState.runtimeConfig.publicConnectionCandidates
      .map(normalizePublicConnectionCandidate)
      .filter(Boolean)
      .filter((candidate) => candidate.username !== publicUsername && candidate.username !== targetUsername)
    : [];
  const configuredSkipCandidateUsernames = Array.isArray(runtimeState.runtimeConfig.publicConnectionSkipCandidateUsernames)
    ? runtimeState.runtimeConfig.publicConnectionSkipCandidateUsernames
      .map((candidateUsername) => normalizeInstagramUsername(candidateUsername))
      .filter(Boolean)
    : [];
  const configuredSkipSet = new Set(configuredSkipCandidateUsernames);
  const skippedCandidates = allCandidates.filter((candidate) => configuredSkipSet.has(candidate.username));
  const candidates = allCandidates.filter((candidate) => !configuredSkipSet.has(candidate.username));
  const expectedTotalCount = skippedCandidates.length + candidates.length;
  const candidateConnections = [];
  const checkedConnections = [];
  let foundFollowersCount = 0;
  let foundFollowingCount = 0;
  let rateLimitedCandidates = 0;
  let failedCandidatesCount = 0;
  let stoppedForRateLimit = false;
  let gracefullyStopped = false;
  let rateLimitedCandidateUsername = null;
  const candidateErrorScreenshots = [];

  progressLog('candidate-pool-ready', {
    relationship: 'public-connections',
    loaded: skippedCandidates.length,
    expectedCount: expectedTotalCount,
    resumeSkippedCount: skippedCandidates.length,
    foundFollowers: foundFollowersCount,
    foundFollowing: foundFollowingCount,
    ...buildPublicConnectionProgressPreviews(candidateConnections),
    rateLimitedCandidates,
    failedCandidates: failedCandidatesCount,
    message: `${candidates.length} offene von ${expectedTotalCount} gespeicherten Kandidaten aus @${publicUsername} werden gegen @${targetUsername} geprueft.`,
  });

  for (let index = 0; index < candidates.length; index++) {
    const candidate = candidates[index];
    const candidateProfileUrl = `https://www.instagram.com/${candidate.username}/`;
    const loadedBeforeCandidate = skippedCandidates.length + checkedConnections.length;

    if (markGracefulStopIfRequested('public-connections', {
      loaded: loadedBeforeCandidate,
      expectedCount: expectedTotalCount,
      foundFollowers: foundFollowersCount,
      foundFollowing: foundFollowingCount,
      ...buildPublicConnectionProgressPreviews(candidateConnections),
    })) {
      gracefullyStopped = true;
      break;
    }

    progressLog('candidate-scan-start', {
      relationship: 'public-connections',
      loaded: loadedBeforeCandidate,
      expectedCount: expectedTotalCount,
      resumeSkippedCount: skippedCandidates.length,
      candidateUsername: candidate.username,
      foundFollowers: foundFollowersCount,
      foundFollowing: foundFollowingCount,
      ...buildPublicConnectionProgressPreviews(candidateConnections),
      rateLimitedCandidates,
      failedCandidates: failedCandidatesCount,
      ...(await captureLivePreviewScreenshot(page, runtimeState.runtimeConfig)),
      message: `Kandidat @${candidate.username} wird geprueft (${loadedBeforeCandidate + 1}/${expectedTotalCount}). Gefunden: ${foundFollowersCount} Follower, ${foundFollowingCount} Gefolgt.`,
    });

    const connection = await collectBatchCandidateConnectionUntilDefinitive(
      page,
      runtimeState,
      notes,
      candidate,
      candidateProfileUrl,
      {
        loaded: loadedBeforeCandidate,
        expectedCount: expectedTotalCount,
        foundFollowers: foundFollowersCount,
        foundFollowing: foundFollowingCount,
        ...buildPublicConnectionProgressPreviews(candidateConnections),
        rateLimitedCandidates,
        screenshotPath: options.screenshotPath || null,
        candidateErrorScreenshots,
      },
    );

    if (connection.followerOfPrivateProfile || connection.followedByPrivateProfile) {
      candidateConnections.push(connection);
    }

    if (connection.followerOfPrivateProfile) {
      foundFollowersCount++;
    }

    if (connection.followedByPrivateProfile) {
      foundFollowingCount++;
    }

    if (connection.gracefullyStopped) {
      gracefullyStopped = true;

      progressLog('candidate-scan-stopped', {
        relationship: 'public-connections',
        loaded: loadedBeforeCandidate,
        expectedCount: expectedTotalCount,
        resumeSkippedCount: skippedCandidates.length,
        candidateUsername: candidate.username,
        candidateConnection: connection,
        targetFoundInFollowers: connection.followedByPrivateProfile,
        targetFoundInFollowing: connection.followerOfPrivateProfile,
        skippedReason: connection.skippedReason,
        gracefullyStopped: true,
        foundFollowers: foundFollowersCount,
        foundFollowing: foundFollowingCount,
        ...buildPublicConnectionProgressPreviews(candidateConnections),
        rateLimitedCandidates,
        failedCandidates: failedCandidatesCount,
        message: `Scan bei @${candidate.username} beendet. Bisherige Treffer bleiben gespeichert.`,
      });
      notes.push(`Public-Profile-Verbindungsscan bei @${candidate.username} ueber die Oberflaeche beendet.`);
      break;
    }

    if (isBatchCandidateRateLimited(connection)) {
      rateLimitedCandidates++;
      stoppedForRateLimit = true;
      rateLimitedCandidateUsername = candidate.username;
      connection.skippedReason = 'rate-limited';

      progressLog('candidate-scan-rate-limited', {
        relationship: 'public-connections',
        loaded: loadedBeforeCandidate,
        expectedCount: expectedTotalCount,
        resumeSkippedCount: skippedCandidates.length,
        candidateUsername: candidate.username,
        candidateConnection: connection,
        targetFoundInFollowers: connection.followedByPrivateProfile,
        targetFoundInFollowing: connection.followerOfPrivateProfile,
        skippedReason: connection.skippedReason,
        stoppedForRateLimit: true,
        foundFollowers: foundFollowersCount,
        foundFollowing: foundFollowingCount,
        ...buildPublicConnectionProgressPreviews(candidateConnections),
        rateLimitedCandidates,
        failedCandidates: failedCandidatesCount,
        message: `Instagram-Rate-Limit bei @${candidate.username}. Scan wird pausiert; bisherige Treffer bleiben gespeichert.`,
      });
      notes.push(`Public-Profile-Verbindungsscan bei @${candidate.username} wegen Instagram-Rate-Limit pausiert.`);
      break;
    }

    checkedConnections.push(connection);

    if (connection.skippedReason === 'candidate-error') {
      failedCandidatesCount++;
    }

    progressLog('candidate-scan-complete', {
      relationship: 'public-connections',
      loaded: skippedCandidates.length + checkedConnections.length,
      expectedCount: expectedTotalCount,
      resumeSkippedCount: skippedCandidates.length,
      candidateUsername: candidate.username,
      candidateConnection: connection,
      targetFoundInFollowers: connection.followedByPrivateProfile,
      targetFoundInFollowing: connection.followerOfPrivateProfile,
      skippedReason: connection.skippedReason,
      foundFollowers: foundFollowersCount,
      foundFollowing: foundFollowingCount,
      ...buildPublicConnectionProgressPreviews(candidateConnections),
      rateLimitedCandidates,
      failedCandidates: failedCandidatesCount,
      ...(await captureLivePreviewScreenshot(page, runtimeState.runtimeConfig, true)),
      message: `Kandidat @${candidate.username} abgeschlossen (${index + 1}/${candidates.length}). Gefunden: ${foundFollowersCount} Follower, ${foundFollowingCount} Gefolgt, Fehler: ${failedCandidatesCount}.`,
    });

    notes.push(`Kandidat @${candidate.username} eindeutig geprueft nach ${connection.scanAttempts || 1} Versuch(en).`);
  }

  const inferredFollowers = candidateConnections.filter((connection) => connection.followerOfPrivateProfile);
  const inferredFollowing = candidateConnections.filter((connection) => connection.followedByPrivateProfile);
  const privateSkipped = checkedConnections.filter((connection) => ['private-profile', 'login-required'].includes(connection.skippedReason)).length;
  const checkedCandidateUsernames = [
    ...skippedCandidates.map((candidate) => candidate.username),
    ...checkedConnections.map((connection) => connection.username),
  ];
  const statusLevel = stoppedForRateLimit
    ? 'partial'
    : gracefullyStopped
    ? 'partial'
    : resolvePublicConnectionBatchStatus(expectedTotalCount, checkedCandidateUsernames.length, candidateConnections);

  return {
    ok: statusLevel !== 'error',
    statusLevel,
    statusMessage: stoppedForRateLimit
      ? `Verbindungsscan wegen Instagram-Rate-Limit pausiert: ${checkedCandidateUsernames.length} von ${expectedTotalCount} Kandidaten geprueft.`
      : gracefullyStopped
      ? `Verbindungsscan beendet: ${checkedCandidateUsernames.length} von ${expectedTotalCount} Kandidaten geprueft. Bisherige Treffer wurden gespeichert.`
      : resolvePublicConnectionBatchMessage(
      inferredFollowers,
      inferredFollowing,
      expectedTotalCount,
      checkedCandidateUsernames.length,
      privateSkipped,
      rateLimitedCandidates,
      failedCandidatesCount,
    ),
    publicUsername,
    targetUsername,
    relationType: 'candidate_search',
    targetFollowsPublicProfile: false,
    publicProfileFollowsTarget: false,
    candidatesTotal: expectedTotalCount,
    candidatesChecked: checkedCandidateUsernames.length,
    candidatesSkippedPrivate: privateSkipped,
    candidatesRateLimited: rateLimitedCandidates,
    candidatesFailed: failedCandidatesCount,
    stoppedForRateLimit,
    gracefullyStopped,
    rateLimitedCandidateUsername,
    checkedCandidateUsernames,
    resumeSkippedCount: skippedCandidates.length,
    inferredFollowers,
    inferredFollowing,
    candidateErrorScreenshots,
    checkedPreview: checkedConnections.slice(0, 25),
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

async function captureLivePreviewScreenshot(page, runtimeConfig = {}, force = false) {
  const livePreviewPath = normalizeText(String(runtimeConfig.livePreviewPath || ''));

  if (!page || !livePreviewPath || runtimeConfig.livePreviewEnabled === false) {
    return {};
  }

  const now = Date.now();

  if (!force && now - lastLivePreviewAt < LIVE_PREVIEW_MIN_INTERVAL_MS) {
    return {};
  }

  try {
    ensureDirectory(path.dirname(livePreviewPath));
    await page.screenshot({
      path: livePreviewPath,
      fullPage: false,
      type: 'png',
    });

    lastLivePreviewAt = now;

    return {
      liveScreenshotPath: livePreviewPath,
      liveScreenshotAt: new Date(now).toISOString(),
    };
  } catch (error) {
    recordRunDebug('live-preview-screenshot-failed', {
      livePreviewPath,
      error: normalizeText(error?.message || String(error)),
    });

    return {};
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
  let runtimeConfig = loadRuntimeConfig(runtimeConfigPath, runtimeConfigModuleOptions);
  setActiveScraperProfile(runtimeConfig);
  setGracefulStopFilePath(runtimeConfig);
  configureScriptWatchdog(runtimeConfig);
  startTechnicalHeartbeat();
  const debugLogPath = initializeRunDebug(operationMode, username, runtimeConfig);
  const profileUrl = isLoginSessionMode
    ? 'https://www.instagram.com/'
    : `https://www.instagram.com/${username}/`;
  const artifacts = buildArtifactPaths(isLoginSessionMode ? '__session__' : username);
  runtimeConfig.livePreviewPath = artifacts.livePreviewPath;
  let cookieFilePath = buildCookiePath(runtimeConfig, pathHelperOptions);
  const browserUserDataDir = buildBrowserUserDataDir(runtimeConfig, runtimeConfigModuleOptions);
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
  let suggestionScanResult = null;
  let postsScanResult = null;
  let terminationSignalHandled = false;
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
  const handleTerminationSignal = async (signal) => {
    if (terminationSignalHandled) {
      return;
    }

    terminationSignalHandled = true;
    recordRunDebug('termination-signal', { signal });

    await Promise.race([
      (async () => {
        if (page && typeof page.isClosed === 'function' && !page.isClosed()) {
          await captureLivePreviewScreenshot(page, runtimeConfig || {}, true).catch(() => ({}));
          await captureDebugPageScreenshot(page, artifacts.screenshotPath, notes);
        }

        scriptWatchdog.intentionalBrowserClose = true;

        if (browser) {
          await closeBrowserSoftly(browser);
        }
      })(),
      sleep(5000),
    ]);

    stopScriptWatchdog();
    stopTechnicalHeartbeat();

    if (cleanupBrowserProfileOnExit) {
      cleanupDirectory(activeBrowserUserDataDir);
    }

    process.exit(signal === 'SIGINT' ? 130 : 143);
  };

  process.once('SIGINT', () => {
    void handleTerminationSignal('SIGINT');
  });
  process.once('SIGTERM', () => {
    void handleTerminationSignal('SIGTERM');
  });

  try {
    const resolvedHeadlessMode = resolvePuppeteerHeadlessMode(runtimeConfig, runtimeConfigModuleOptions);

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

    scriptWatchdog.browser = browser;
    browser.on('disconnected', () => {
      if (!scriptWatchdog.intentionalBrowserClose && scriptWatchdog.browserDisconnectAbort) {
        requestScriptAbort(
          'Node-Scraper abgebrochen: Verbindung zu Chrome/Puppeteer wurde getrennt.',
          'BROWSER_DISCONNECTED',
        );
      }
    });

    page = await browser.newPage();
    page.setDefaultTimeout(0);
    page.setDefaultNavigationTimeout(0);
    page.on('close', () => {
      if (!scriptWatchdog.intentionalBrowserClose && scriptWatchdog.browserDisconnectAbort) {
        requestScriptAbort(
          'Node-Scraper abgebrochen: Browser-Seite wurde unerwartet geschlossen.',
          'PAGE_CLOSED',
        );
      }
    });

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
      const failureText = normalizeText(request.failure()?.errorText || 'requestfailed');

      if (
        runtimeConfig.blockHeavyResources
        && failureText === 'net::ERR_FAILED'
        && ['image', 'media', 'font'].includes(request.resourceType())
      ) {
        return;
      }

      if (!/instagram|facebook/i.test(requestUrl)) {
        return;
      }

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

    if (runtimeConfig.blockHeavyResources) {
      await page.setRequestInterception(true);
      page.on('request', (request) => {
        if (['image', 'media', 'font'].includes(request.resourceType())) {
          request.abort().catch(() => {});

          return;
        }

        request.continue().catch(() => {});
      });
    }

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

    const miniScanUsesSession = isMiniScanMode && runtimeConfig.miniScanUseSession === true;

    if (isMiniScanMode && !miniScanUsesSession) {
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

        const responsePayload = attachScanBilling({
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
          ...activeScraperProfilePayload(),
          debugLogPath,
        }, runtimeConfig);
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
    let gracefullyStopped = false;
    const scanState = {
      runtimeConfig,
      cookieFilePath,
      cookieDiagnostics,
      loginDiagnostics,
      initialHtml,
      initialProfile,
      title,
      finalUrl,
      postsScanResult,
      suggestionScanResult,
      gracefullyStopped,
      batchPayload: null,
    };
    const flowContext = {
      page,
      username,
      profileUrl,
      runtimeState,
      notes,
      consoleMessages,
      scanState,
      flags: {
        operationMode,
        artifacts,
        shouldCollectFollowers,
        shouldCollectFollowing,
      },
      helpers: {
        captureLivePreviewScreenshot,
        collectInstagramPosts,
        collectProfileInfo,
        collectRelationshipListWithAccountSwitches,
        markGracefulStopIfRequested,
        navigateWithSoftTimeout,
        normalizeInstagramUsername,
        progressLog,
        recoverFromInstagramDailyTimeLimit,
        runPublicConnectionBatch,
        sleep,
      },
    };

    if (isFullScanMode) {
      await runInstagramFullScanFlow(flowContext);
    } else if (isListScanMode) {
      await runInstagramListScanFlow(flowContext);

      if (scanState.batchPayload) {
        runtimeConfig = scanState.runtimeConfig;
        cookieFilePath = scanState.cookieFilePath;
        cookieDiagnostics = scanState.cookieDiagnostics;
        loginDiagnostics = scanState.loginDiagnostics;
        debugScreenshotPath = await captureDebugPageScreenshot(page, artifacts.screenshotPath, notes);
        const responsePayload = attachScanBilling({
          ...scanState.batchPayload,
          username,
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
          ...activeScraperProfilePayload(),
          debugLogPath,
        }, runtimeConfig);
        flushRunDebug(responsePayload);
        console.log(JSON.stringify(responsePayload));

        return;
      }
    } else if (isPostsMode) {
      await runInstagramPostsScanFlow(flowContext);
    } else {
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
        ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
      });

      await sleep(1800);

      const timeLimitRecovery = await recoverFromInstagramDailyTimeLimit(
        page,
        runtimeState,
        notes,
        isSuggestionsMode ? 'suggestions' : 'profile',
        profileUrl,
      );

      if (!timeLimitRecovery.recovered) {
        throw new Error('Instagram-Zeitlimit auf allen verfuegbaren Scraper-Profilen erreicht.');
      }

      runtimeConfig = runtimeState.runtimeConfig;
      cookieFilePath = runtimeState.cookieFilePath;
      cookieDiagnostics = runtimeState.cookieDiagnostics;
      loginDiagnostics = runtimeState.loginDiagnostics;

      initialHtml = await page.content();
      initialProfile = await collectProfileInfo(page, username);
      title = await page.title().catch(() => null);
      finalUrl = page.url();

      progressLog('profile-collected', {
        usernameSeen: Boolean(initialProfile.usernameSeen),
        isPrivate: Boolean(initialProfile.isPrivate),
        requiresLogin: Boolean(initialProfile.requiresLogin),
        imageCount: initialProfile.imageCount || 0,
        ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
      });

      gracefullyStopped = Boolean(markGracefulStopIfRequested(null, {
        loaded: 0,
        expectedCount: 0,
      }));

      if (gracefullyStopped) {
        notes.push('Instagram-Scan wurde ueber die Oberflaeche nach den Grunddaten beendet.');
      }

      if (isSuggestionsMode && !gracefullyStopped) {
        suggestionScanResult = await runProfileSuggestionConnectionScan(
          page,
          runtimeState,
          notes,
          username,
          profileUrl,
        );
        initialProfile.suggestionScan = suggestionScanResult;
        initialHtml = await page.content().catch(() => initialHtml);
        title = await page.title().catch(() => title);
        finalUrl = page.url();

        if (suggestionScanResult.gracefullyStopped) {
          gracefullyStopped = true;
        }
      }
    }

    if (!isFullScanMode && !isListScanMode && !isPostsMode) {
      scanState.runtimeConfig = runtimeState.runtimeConfig;
      scanState.cookieFilePath = runtimeState.cookieFilePath;
      scanState.cookieDiagnostics = runtimeState.cookieDiagnostics;
      scanState.loginDiagnostics = runtimeState.loginDiagnostics;
      scanState.initialHtml = initialHtml;
      scanState.initialProfile = initialProfile;
      scanState.title = title;
      scanState.finalUrl = finalUrl;
      scanState.postsScanResult = postsScanResult;
      scanState.suggestionScanResult = suggestionScanResult;
      scanState.gracefullyStopped = gracefullyStopped;
    }

    runtimeConfig = scanState.runtimeConfig;
    cookieFilePath = scanState.cookieFilePath;
    cookieDiagnostics = scanState.cookieDiagnostics;
    loginDiagnostics = scanState.loginDiagnostics;
    initialHtml = scanState.initialHtml;
    initialProfile = scanState.initialProfile;
    title = scanState.title;
    finalUrl = scanState.finalUrl || page.url();
    postsScanResult = scanState.postsScanResult;
    suggestionScanResult = scanState.suggestionScanResult;
    gracefullyStopped = Boolean(scanState.gracefullyStopped);

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
    const outcome = isPostsMode && postsScanResult
      ? {
        ok: postsScanResult.statusLevel !== 'error',
        statusLevel: postsScanResult.statusLevel || 'unknown',
        statusMessage: postsScanResult.statusMessage || 'Instagram-Beitragsscan abgeschlossen.',
      }
      : isSuggestionsMode && suggestionScanResult
      ? {
        ok: suggestionScanResult.statusLevel !== 'error',
        statusLevel: suggestionScanResult.statusLevel || 'unknown',
        statusMessage: suggestionScanResult.statusMessage || (
          operationMode === 'suggestion-connections'
            ? 'Vorschlaege DeepSearch abgeschlossen.'
            : 'Vorschlaege-Scan abgeschlossen.'
        ),
      }
      : gracefullyStopped
      ? {
        ok: false,
        statusLevel: 'partial',
        statusMessage: 'Instagram-Scan wurde beendet; bisherige Ergebnisse wurden gespeichert.',
      }
      : deriveScrapeOutcome({
      title,
      finalUrl,
      profile: initialProfile,
      warnings: dedupedWarnings,
      cookieDiagnostics,
      loginDiagnostics,
    });

    if (!runtimeConfig.skipDebugArtifacts) {
      fs.writeFileSync(artifacts.htmlPath, initialHtml, 'utf8');
    }

    if (isMiniScanMode && !miniScanUsesSession) {
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

    const responsePayload = attachScanBilling({
      ok: outcome.ok,
      statusLevel: outcome.statusLevel,
      statusMessage: outcome.statusMessage,
      username,
      finalUrl,
      htmlBytes: Buffer.byteLength(initialHtml, 'utf8'),
      htmlPath: runtimeConfig.skipDebugArtifacts ? null : artifacts.htmlPath,
      htmlPreview: initialHtml.slice(0, 4000),
      notes: dedupe(notes),
      cookieDiagnostics,
      loginDiagnostics,
      profile: initialProfile,
      postsScan: postsScanResult,
      suggestionScan: suggestionScanResult,
      suggestionConnections: Array.isArray(suggestionScanResult?.matches)
        ? suggestionScanResult.matches
        : [],
      gracefullyStopped,
      profileUrl,
      screenshotPath: debugScreenshotPath,
      scrapedAt: new Date().toISOString(),
      screenshotMode: debugScreenshotPath ? 'page' : null,
      title,
      warnings: dedupedWarnings,
      durationMs: Date.now() - startedAt,
      operationMode,
      ...activeScraperProfilePayload(),
      debugLogPath,
    }, runtimeConfig);
    flushRunDebug(responsePayload);
    console.log(JSON.stringify(responsePayload));
  } catch (error) {
    const aborted = error instanceof ScraperAbortError || error?.name === 'ScraperAbortError';

    if (initialHtml && !runtimeConfig.skipDebugArtifacts) {
      fs.writeFileSync(artifacts.htmlPath, initialHtml, 'utf8');
    }

    debugScreenshotPath = await captureDebugPageScreenshot(page, artifacts.screenshotPath, notes);
    progressLog('scraper-error', {
      relationship: isSuggestionsMode ? 'suggestions' : null,
      loaded: 0,
      expectedCount: 0,
      error: normalizeText(error?.message || String(error)),
      message: aborted
        ? error.message
        : 'Instagram-Scrape fehlgeschlagen.',
      ...(await captureLivePreviewScreenshot(page, runtimeConfig, true)),
    });

    const responsePayload = attachScanBilling({
      ok: false,
      statusLevel: 'error',
      statusMessage: aborted
        ? error.message
        : 'Instagram-Scrape fehlgeschlagen.',
      username,
      error: normalizeText(error.message),
      candidateUsername: error.candidateUsername || null,
      candidateErrorScreenshots: Array.isArray(error.candidateDebugScreenshots)
        ? error.candidateDebugScreenshots
        : [],
      finalUrl,
      htmlPath: initialHtml && !runtimeConfig.skipDebugArtifacts ? artifacts.htmlPath : null,
      notes: dedupe(notes),
      screenshotPath: debugScreenshotPath,
      screenshotMode: debugScreenshotPath ? 'page' : null,
      warnings: dedupe(consoleMessages),
      durationMs: Date.now() - startedAt,
      operationMode,
      ...activeScraperProfilePayload(),
      debugLogPath,
    }, runtimeConfig);
    recordRunDebug('run-error', responsePayload);
    flushRunDebug(responsePayload);
    console.log(JSON.stringify(responsePayload));

    process.exitCode = 1;
  } finally {
    if (page && typeof page.isClosed === 'function' && !page.isClosed()) {
      await captureLivePreviewScreenshot(page, runtimeConfig || {}, true).catch(() => ({}));

      if (!debugScreenshotPath && artifacts?.screenshotPath) {
        debugScreenshotPath = await captureDebugPageScreenshot(page, artifacts.screenshotPath, notes);
      }
    }

    scriptWatchdog.intentionalBrowserClose = true;

    if (browser) {
      await closeBrowserSoftly(browser);
    }

    stopScriptWatchdog();
    stopTechnicalHeartbeat();

    if (cleanupBrowserProfileOnExit) {
      cleanupDirectory(activeBrowserUserDataDir);
    }
  }
})();
