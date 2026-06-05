const fs = require('fs');
const path = require('path');

function normalizeSuggestionCandidateHistory(value = {}, helpers = {}) {
  const { normalizeInstagramUsername } = helpers;
  const input = value && typeof value === 'object' && !Array.isArray(value) ? value : {};
  const history = {};

  for (const [rawUsername, rawState] of Object.entries(input)) {
    const username = normalizeInstagramUsername(rawUsername);

    if (!username) {
      continue;
    }

    const state = rawState && typeof rawState === 'object' && !Array.isArray(rawState) ? rawState : {};
    const noMatchChecks = Math.max(0, Math.floor(Number(state.noMatchChecks || state.no_match_checks || 0)));

    history[username] = {
      hasMatch: state.hasMatch === true || state.has_match === true,
      noMatchChecks,
      permanentlyDismissed:
        state.permanentlyDismissed === true
        || state.permanently_dismissed === true
        || noMatchChecks >= 2,
    };
  }

  return history;
}

function normalizeRelationshipSearchUsernameMap(value = {}, helpers = {}) {
  const { normalizeInstagramUsername } = helpers;
  const input = value && typeof value === 'object' && !Array.isArray(value) ? value : {};
  const normalized = {
    followers: [],
    following: [],
  };

  for (const relationship of ['followers', 'following']) {
    const rawItems = Array.isArray(input[relationship]) ? input[relationship] : [];
    const seen = new Set();

    for (const rawItem of rawItems) {
      const username = normalizeInstagramUsername(
        typeof rawItem === 'string' ? rawItem : (rawItem?.username || rawItem?.handle || ''),
      );

      if (!username || seen.has(username)) {
        continue;
      }

      seen.add(username);
      normalized[relationship].push(username);
    }
  }

  return normalized;
}

function normalizeRuntimeConfigShape(config = {}, defaults = {}, helpers = {}, options = {}) {
  const {
    normalizeText,
    normalizeInstagramUsername,
    normalizeOptionalPositiveInteger,
    normalizeNumberAtLeast,
  } = helpers;
  const {
    isLoginSessionMode = false,
    defaultMaxRelationshipListScrollRounds = 100000,
  } = options;
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
    miniScanUseSession: input?.miniScanUseSession === true || input?.mini_scan_use_session === true,
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
      ) || merged.relationshipListMaxScrollRounds || defaultMaxRelationshipListScrollRounds,
    ),
    expectedFollowerCount: normalizeOptionalPositiveInteger(input?.expectedFollowerCount, merged.expectedFollowerCount),
    expectedFollowingCount: normalizeOptionalPositiveInteger(input?.expectedFollowingCount, merged.expectedFollowingCount),
    relationshipPrioritizedSearchUsernames: normalizeRelationshipSearchUsernameMap(
      input?.relationshipPrioritizedSearchUsernames
        || input?.relationship_prioritized_search_usernames
        || merged.relationshipPrioritizedSearchUsernames,
      helpers,
    ),
    relationshipSearchOnly: input?.relationshipSearchOnly === true || input?.relationship_search_only === true,
    relationshipSearchTargetUsername: normalizeInstagramUsername(input?.relationshipSearchTargetUsername || merged.relationshipSearchTargetUsername || ''),
    relationshipSearchTargetMaxItems: normalizeOptionalPositiveInteger(input?.relationshipSearchTargetMaxItems, merged.relationshipSearchTargetMaxItems),
    relationshipSearchTargetMaxScrollRounds: normalizeOptionalPositiveInteger(input?.relationshipSearchTargetMaxScrollRounds, merged.relationshipSearchTargetMaxScrollRounds),
    relationshipSearchInputMaxAttempts: Math.floor(normalizeNumberAtLeast(input?.relationshipSearchInputMaxAttempts, merged.relationshipSearchInputMaxAttempts || 3, 1)),
    suggestionScanMaxItems: normalizeOptionalPositiveInteger(input?.suggestionScanMaxItems, merged.suggestionScanMaxItems || 140) || 140,
    suggestionCandidateMaxItems: normalizeOptionalPositiveInteger(input?.suggestionCandidateMaxItems, merged.suggestionCandidateMaxItems || 80) || 80,
    suggestionPublicListSearchMaxScrollRounds: normalizeOptionalPositiveInteger(
      input?.suggestionPublicListSearchMaxScrollRounds,
      merged.suggestionPublicListSearchMaxScrollRounds || 90,
    ) || 90,
    suggestionInlineMaxRounds: normalizeOptionalPositiveInteger(input?.suggestionInlineMaxRounds, merged.suggestionInlineMaxRounds || 36) || 36,
    suggestionDialogMaxRounds: normalizeOptionalPositiveInteger(input?.suggestionDialogMaxRounds, merged.suggestionDialogMaxRounds || 48) || 48,
    suggestionCandidateInlineMaxRounds: normalizeOptionalPositiveInteger(input?.suggestionCandidateInlineMaxRounds, merged.suggestionCandidateInlineMaxRounds || 24) || 24,
    suggestionCandidateDialogMaxRounds: normalizeOptionalPositiveInteger(input?.suggestionCandidateDialogMaxRounds, merged.suggestionCandidateDialogMaxRounds || 36) || 36,
    profileHoverCardsEnabled: input?.profileHoverCardsEnabled !== false
      && input?.profile_hover_cards_enabled !== false
      && merged.profileHoverCardsEnabled !== false,
    profileHoverCardWaitMs: normalizeNumberAtLeast(input?.profileHoverCardWaitMs || input?.profile_hover_card_wait_ms, merged.profileHoverCardWaitMs || 850, 250),
    suggestionDismissChecked: input?.suggestionDismissChecked === true || input?.suggestion_dismiss_checked === true,
    suggestionCandidateHistory: normalizeSuggestionCandidateHistory(
      input?.suggestionCandidateHistory || input?.suggestion_candidate_history || merged.suggestionCandidateHistory,
      helpers,
    ),
    scriptWatchdogEnabled: input?.scriptWatchdogEnabled !== false && input?.script_watchdog_enabled !== false,
    scriptStallTimeoutMs: normalizeNumberAtLeast(input?.scriptStallTimeoutMs || input?.nodeStallTimeoutMs, merged.scriptStallTimeoutMs || 900000, 60000),
    browserDisconnectAbort: input?.browserDisconnectAbort !== false && input?.browser_disconnect_abort !== false,
    publicConnectionCandidateMaxAttempts: Math.min(
      3,
      Math.floor(normalizeNumberAtLeast(input?.publicConnectionCandidateMaxAttempts, merged.publicConnectionCandidateMaxAttempts || 3, 1)),
    ),
    publicConnectionCandidateMaxDurationMs: normalizeNumberAtLeast(input?.publicConnectionCandidateMaxDurationMs, merged.publicConnectionCandidateMaxDurationMs || 1200000, 60000),
    publicConnectionDialogMissingMaxAttempts: Math.floor(normalizeNumberAtLeast(input?.publicConnectionDialogMissingMaxAttempts, merged.publicConnectionDialogMissingMaxAttempts || 2, 1)),
    publicConnectionRateLimitAccountSwitchEnabled: input?.publicConnectionRateLimitAccountSwitchEnabled !== false
      && input?.public_connection_rate_limit_account_switch_enabled !== false
      && merged.publicConnectionRateLimitAccountSwitchEnabled !== false,
    gracefulStopFilePath: normalizeText(String(input?.gracefulStopFilePath || input?.graceful_stop_file_path || merged.gracefulStopFilePath || '')),
    livePreviewPath: normalizeText(String(input?.livePreviewPath || input?.live_preview_path || merged.livePreviewPath || '')),
    livePreviewEnabled: input?.livePreviewEnabled !== false && input?.live_preview_enabled !== false && merged.livePreviewEnabled !== false,
    skipDebugArtifacts: input?.skipDebugArtifacts === true || input?.skip_debug_artifacts === true,
    blockHeavyResources: input?.blockHeavyResources === true || input?.block_heavy_resources === true,
    accountPool: Array.isArray(input.accountPool) ? input.accountPool : [],
  };
}

function normalizeRuntimeAccountConfig(account, baseConfig, helpers = {}, options = {}) {
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
  }, baseConfig, helpers, options);
}

function getScraperAccountKey(runtimeConfig = {}, helpers = {}) {
  const normalizeText = typeof helpers.normalizeText === 'function'
    ? helpers.normalizeText
    : (value = '') => String(value || '').replace(/\s+/g, ' ').trim();
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

function normalizeRuntimeAccountPool(accountPool, runtimeConfig, helpers = {}, options = {}) {
  const rawAccounts = Array.isArray(accountPool) ? accountPool : [];
  const normalizedAccounts = [
    { ...runtimeConfig, accountPool: [] },
    ...rawAccounts.map((account) => normalizeRuntimeAccountConfig(account, runtimeConfig, helpers, options)),
  ];
  const seenAccountKeys = new Set();
  const pool = [];

  for (const account of normalizedAccounts) {
    const accountKey = getScraperAccountKey(account, helpers);

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

function loadRuntimeConfig(configPath, options = {}) {
  const {
    defaults = {},
    debugLog,
    helpers = {},
  } = options;

  if (!configPath || !fs.existsSync(configPath)) {
    const runtimeConfig = normalizeRuntimeConfigShape({}, defaults, helpers, options);
    runtimeConfig.accountPool = normalizeRuntimeAccountPool([], runtimeConfig, helpers, options);

    return runtimeConfig;
  }

  try {
    const parsed = JSON.parse(fs.readFileSync(configPath, 'utf8'));
    const runtimeConfig = normalizeRuntimeConfigShape(parsed, defaults, helpers, options);
    runtimeConfig.accountPool = normalizeRuntimeAccountPool(parsed?.accountPool, runtimeConfig, helpers, options);

    return runtimeConfig;
  } catch (error) {
    debugLog?.('Fehler beim Laden der Runtime-Konfiguration:', error.message);
    const runtimeConfig = normalizeRuntimeConfigShape({}, defaults, helpers, options);
    runtimeConfig.accountPool = normalizeRuntimeAccountPool([], runtimeConfig, helpers, options);

    return runtimeConfig;
  }
}

function buildBrowserUserDataDir(runtimeConfig, options = {}) {
  const { runtimeTempDirectory, ensureDirectory, isUsableDirectory } = options;

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

function resolvePuppeteerHeadlessMode(runtimeConfig, options = {}) {
  const { isLoginSessionMode = false } = options;
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

module.exports = {
  buildBrowserUserDataDir,
  getScraperAccountKey,
  loadRuntimeConfig,
  normalizeRelationshipSearchUsernameMap,
  normalizeRuntimeAccountConfig,
  normalizeRuntimeAccountPool,
  normalizeRuntimeConfigShape,
  normalizeSuggestionCandidateHistory,
  resolvePuppeteerHeadlessMode,
  shouldCleanupBrowserProfile,
};
