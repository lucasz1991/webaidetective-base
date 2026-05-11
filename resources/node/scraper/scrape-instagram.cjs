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

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
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

function dedupe(values) {
  return [...new Set(values.filter(Boolean))];
}

function loadRuntimeConfig(configPath) {
  const defaults = {
    profileLabel: 'instagram-default',
    persistentProfileEnabled: true,
    browserProfilePath: path.resolve(__dirname, '../../../storage/app/browser-profiles/instagram/default'),
    cookieFilePath: path.resolve(__dirname, '../../../storage/app/cookies/instagram-cookies.json'),
    headlessEnabled: true,
    autoLoginEnabled: false,
    loginUsername: '',
    loginPassword: '',
    navigationTimeoutMs: 120000,
    postLoginWaitMs: 2500,
    typingDelayMs: 35,
  };

  if (!configPath || !fs.existsSync(configPath)) {
    return defaults;
  }

  try {
    const parsed = JSON.parse(fs.readFileSync(configPath, 'utf8'));

    return {
      ...defaults,
      ...parsed,
      persistentProfileEnabled: parsed?.persistentProfileEnabled !== false,
      headlessEnabled: parsed?.headlessEnabled !== false,
      autoLoginEnabled: parsed?.autoLoginEnabled === true,
      navigationTimeoutMs: Math.max(30000, Number(parsed?.navigationTimeoutMs || defaults.navigationTimeoutMs)),
      postLoginWaitMs: Math.max(500, Number(parsed?.postLoginWaitMs || defaults.postLoginWaitMs)),
      typingDelayMs: Math.max(0, Number(parsed?.typingDelayMs || defaults.typingDelayMs)),
    };
  } catch (error) {
    debugLog('Fehler beim Laden der Runtime-Konfiguration:', error.message);
    return defaults;
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
    screenshotPath: path.join(artifactBasePath, `profile-snapshot-${stamp}.png`),
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

    await page.browserContext().setCookie(...cookies);

    const acceptedCookies = await page.cookies('https://www.instagram.com/');
    diagnostics.acceptedNames = acceptedCookies.map((cookie) => cookie.name);
    diagnostics.acceptedCount = acceptedCookies.length;
    diagnostics.sessionCookieAccepted = diagnostics.acceptedNames.includes('sessionid');
    diagnostics.loaded = true;

    debugLog('Instagram-Cookies geladen', diagnostics.providedNames);

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

async function primeInstagramSession(page, cookieFilePath) {
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

  await page.goto('https://www.instagram.com/', {
    timeout: 120000,
    waitUntil: 'domcontentloaded',
  });

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

    if (filteredCookies.length > 0) {
      const tempPath = `${cookieFilePath}.tmp`;
      fs.writeFileSync(tempPath, JSON.stringify(filteredCookies, null, 2), 'utf8');
      fs.renameSync(tempPath, cookieFilePath);
      return true;
    }

    return false;
  } catch (error) {
    return false;
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

function deriveScrapeOutcome({ title, finalUrl, profile, warnings, cookieDiagnostics }) {
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

  const hasAuthOrRateLimitBlock = /status of 401|status of 403|http 401|http 403|require_login|please wait a few minutes|bitte warte einige minuten/.test(
    normalizedWarnings,
  );

  const redirectedToLogin = (finalUrl || '').includes('/accounts/login');
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

async function performInstagramLogin(page, runtimeConfig) {
  const diagnostics = {
    attempted: false,
    success: false,
    finalUrl: null,
    title: null,
    bodyPreview: '',
    invalidCredentials: false,
    challengeDetected: false,
    warnings: [],
  };

  if (!runtimeConfig?.autoLoginEnabled) {
    return diagnostics;
  }

  const loginUsername = normalizeText(runtimeConfig.loginUsername || '');
  const loginPassword = String(runtimeConfig.loginPassword || '');

  if (!loginUsername || !loginPassword) {
    diagnostics.warnings.push('Auto-Login ist aktiviert, aber Benutzername oder Passwort fehlen.');
    return diagnostics;
  }

  diagnostics.attempted = true;

  await page.goto('https://www.instagram.com/accounts/login/', {
    timeout: runtimeConfig.navigationTimeoutMs,
    waitUntil: 'domcontentloaded',
  });

  await page.waitForSelector('input[name="username"]', {
    timeout: runtimeConfig.navigationTimeoutMs,
  });

  await sleep(1200);

  await page.$eval('input[name="username"]', (element) => {
    element.focus();
    element.value = '';
  });
  await page.type('input[name="username"]', loginUsername, {
    delay: runtimeConfig.typingDelayMs,
  });

  await page.$eval('input[name="password"]', (element) => {
    element.focus();
    element.value = '';
  });
  await page.type('input[name="password"]', loginPassword, {
    delay: runtimeConfig.typingDelayMs,
  });

  await Promise.allSettled([
    page.waitForNavigation({
      timeout: runtimeConfig.navigationTimeoutMs,
      waitUntil: 'domcontentloaded',
    }),
    page.click('button[type="submit"]'),
  ]);

  await sleep(runtimeConfig.postLoginWaitMs);
  await dismissPostLoginPrompts(page);

  diagnostics.finalUrl = page.url();
  diagnostics.title = await page.title().catch(() => null);
  diagnostics.bodyPreview = normalizeText(
    await page.evaluate(() => document.body?.innerText || '').catch(() => ''),
  ).slice(0, 1000);

  const normalizedBody = diagnostics.bodyPreview.toLowerCase();
  diagnostics.invalidCredentials = /password was incorrect|incorrect password|dein passwort war nicht korrekt|falsches passwort/.test(normalizedBody);
  diagnostics.challengeDetected = /security code|bestaetige, dass du es bist|confirm it'?s you|two-factor|authenticator|suspicious login|challenge/.test(normalizedBody);
  diagnostics.success =
    !diagnostics.invalidCredentials &&
    !diagnostics.challengeDetected &&
    !diagnostics.finalUrl.includes('/accounts/login') &&
    !/log into instagram|melde dich an|password|passwort/.test(normalizedBody);

  if (diagnostics.invalidCredentials) {
    diagnostics.warnings.push('Instagram hat die Zugangsdaten als ungueltig abgelehnt.');
  }

  if (diagnostics.challengeDetected) {
    diagnostics.warnings.push('Instagram verlangt nach dem Login eine zusaetzliche Verifizierung oder Challenge.');
  }

  if (!diagnostics.success && !diagnostics.invalidCredentials && !diagnostics.challengeDetected) {
    diagnostics.warnings.push('Der Auto-Login wurde ausgefuehrt, aber Instagram hat keinen stabilen angemeldeten Zustand geliefert.');
  }

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

      return diagnostics;
    }

    diagnostics.finalUrl = currentUrl;
    diagnostics.title = await page.title().catch(() => null);
    diagnostics.bodyPreview = currentBody;

    if (challengeDetected) {
      diagnostics.warnings.push('Instagram verlangt weiterhin eine Verifizierung oder Challenge.');
    }

    await sleep(1500);
  }

  diagnostics.warnings.push('Die manuelle Instagram-Anmeldung wurde nicht innerhalb des Zeitlimits abgeschlossen.');

  return diagnostics;
}

async function establishInstagramSession(page, runtimeConfig, notes) {
  const cookieDiagnostics = await primeInstagramSession(page, buildCookiePath(runtimeConfig));
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
    return { cookieDiagnostics, loginDiagnostics, sessionEstablished: true };
  }

  if (isLoginSessionMode && runtimeConfig.autoLoginEnabled) {
    loginDiagnostics = await performInstagramLogin(page, runtimeConfig);

    if (loginDiagnostics.attempted) {
      notes.push('Auto-Login fuer Instagram wurde gestartet.');
    }

    if (loginDiagnostics.success) {
      notes.push('Instagram-Login wurde erfolgreich abgeschlossen.');
      return { cookieDiagnostics, loginDiagnostics, sessionEstablished: true };
    }

    notes.push('Auto-Login konnte keinen stabilen angemeldeten Zustand herstellen.');
  }

  if (isLoginSessionMode) {
    notes.push('Warte auf eine manuelle Anmeldung im sichtbaren Browserfenster.');
    loginDiagnostics = await waitForInteractiveLoginCompletion(page, runtimeConfig);

    if (loginDiagnostics.success) {
      notes.push('Manuelle Instagram-Anmeldung erfolgreich abgeschlossen.');
      return { cookieDiagnostics, loginDiagnostics, sessionEstablished: true };
    }
  }

  return {
    cookieDiagnostics,
    loginDiagnostics,
    sessionEstablished: false,
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

    await snapshotPage
      .waitForFunction(() => Array.from(document.images).every((image) => image.complete), {
        timeout: 10000,
      })
      .catch(() => {});

    await sleep(1200);

    await snapshotPage.screenshot({
      path: screenshotPath,
    });
  } finally {
    await snapshotPage.close();
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
  const runtimeConfig = loadRuntimeConfig(runtimeConfigPath);
  const profileUrl = isLoginSessionMode
    ? 'https://www.instagram.com/'
    : `https://www.instagram.com/${username}/`;
  const artifacts = buildArtifactPaths(isLoginSessionMode ? '__session__' : username);
  const cookieFilePath = buildCookiePath(runtimeConfig);
  const browserUserDataDir = buildBrowserUserDataDir(runtimeConfig);
  let activeBrowserUserDataDir = browserUserDataDir;
  let cleanupBrowserProfileOnExit = shouldCleanupBrowserProfile(runtimeConfig, browserUserDataDir);

  let browser;
  let page;
  let initialHtml = '';
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

  try {
    const launchOptions = {
      headless: isLoginSessionMode ? false : runtimeConfig.headlessEnabled,
      userDataDir: activeBrowserUserDataDir,
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-dev-shm-usage',
        '--disable-blink-features=AutomationControlled',
        '--lang=de-DE,de;q=0.9',
      ],
    };

    try {
      browser = await puppeteer.launch(launchOptions);
    } catch (launchError) {
      if (!isLoginSessionMode && runtimeConfig.persistentProfileEnabled) {
        notes.push('Das persistente Browser-Profil konnte nicht gestartet werden; die Analyse nutzt ein temporaeres Hintergrundprofil.');
        activeBrowserUserDataDir = fs.mkdtempSync(
          path.join(runtimeTempDirectory, 'puppeteer-profile-fallback-'),
        );
        cleanupBrowserProfileOnExit = true;
        browser = await puppeteer.launch({
          ...launchOptions,
          userDataDir: activeBrowserUserDataDir,
        });
      } else {
        throw launchError;
      }
    }

    page = await browser.newPage();

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

    const { cookieDiagnostics, loginDiagnostics: sessionLoginDiagnostics, sessionEstablished } = await establishInstagramSession(
      page,
      runtimeConfig,
      notes,
    );
    loginDiagnostics = sessionLoginDiagnostics;

    if (sessionEstablished) {
      const cookiesSavedAfterSession = await saveCookiesToFile(page, cookieFilePath);

      if (cookiesSavedAfterSession) {
        notes.push('Sessiondaten wurden gespeichert.');
      }
    }

    if (isLoginSessionMode) {
      console.log(
        JSON.stringify({
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
          screenshotPath: null,
          scrapedAt: new Date().toISOString(),
          screenshotMode: null,
          title: await page.title().catch(() => null),
          warnings: dedupe([
            ...consoleMessages,
            ...(cookieDiagnostics.warnings || []),
            ...(loginDiagnostics.warnings || []),
          ]),
          durationMs: Date.now() - startedAt,
          operationMode,
        }),
      );

      return;
    }

    await page.goto(profileUrl, {
      timeout: runtimeConfig.navigationTimeoutMs,
      waitUntil: 'domcontentloaded',
    });

    await sleep(1800);

    initialHtml = await page.content();
    initialProfile = await collectProfileInfo(page, username);
    title = await page.title().catch(() => null);
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
    });

    fs.writeFileSync(artifacts.htmlPath, initialHtml, 'utf8');

    if (shouldSaveCookies(finalUrl, initialProfile)) {
      const cookiesSaved = await saveCookiesToFile(page, cookieFilePath);
      if (cookiesSaved) {
        notes.push('Aktualisierte Instagram-Cookies gespeichert.');
      } else {
        notes.push('Es wurden keine Cookies gespeichert, weil keine relevanten Instagram-Cookies gefunden wurden.');
      }
    } else {
      notes.push('Cookies wurden nicht gespeichert, weil die Session offenbar nicht gueltig war.');
    }

    await renderProfileSnapshot(
      browser,
      artifacts.screenshotPath,
      username,
      profileUrl,
      title,
      initialProfile,
      dedupe(notes),
    );

    console.log(
      JSON.stringify({
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
        screenshotPath: artifacts.screenshotPath,
        scrapedAt: new Date().toISOString(),
        screenshotMode: 'generated-card',
        title,
        warnings: dedupedWarnings,
        durationMs: Date.now() - startedAt,
        operationMode,
      }),
    );
  } catch (error) {
    if (initialHtml) {
      fs.writeFileSync(artifacts.htmlPath, initialHtml, 'utf8');
    }

    console.log(
      JSON.stringify({
        ok: false,
        statusLevel: 'error',
        statusMessage: 'Instagram-Scrape fehlgeschlagen.',
        username,
        error: normalizeText(error.message),
        finalUrl,
        htmlPath: initialHtml ? artifacts.htmlPath : null,
        notes: dedupe(notes),
        screenshotPath: null,
        warnings: dedupe(consoleMessages),
        durationMs: Date.now() - startedAt,
      }),
    );

    process.exitCode = 1;
  } finally {
    if (browser) {
      await browser.close();
    }

    if (cleanupBrowserProfileOnExit) {
      cleanupDirectory(activeBrowserUserDataDir);
    }
  }
})();
