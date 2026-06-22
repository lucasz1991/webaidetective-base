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
    const lastCheckedAt = String(state.lastCheckedAt || state.last_checked_at || '').trim() || null;
    const recheckAfter = String(state.recheckAfter || state.recheck_after || '').trim() || null;
    const lastSuggestionProfileScanAt = String(
      state.lastSuggestionProfileScanAt
      || state.last_suggestion_profile_scan_at
      || state.lastProfileSuggestionScanAt
      || state.last_profile_suggestion_scan_at
      || '',
    ).trim() || null;
    const suggestionProfileRecheckAfter = String(
      state.suggestionProfileRecheckAfter
      || state.suggestion_profile_recheck_after
      || state.profileSuggestionRecheckAfter
      || state.profile_suggestion_recheck_after
      || '',
    ).trim() || null;

    history[username] = {
      hasMatch: state.hasMatch === true || state.has_match === true,
      knownSuggestion: state.knownSuggestion === true || state.known_suggestion === true,
      knownProfile: state.knownProfile === true || state.known_profile === true,
      noMatchChecks,
      permanentlyDismissed:
        state.permanentlyDismissed === true
        || state.permanently_dismissed === true,
      recentlyChecked: state.recentlyChecked === true || state.recently_checked === true,
      lastCheckedAt,
      recheckAfter,
      recentlySuggestionProfileScanned:
        state.recentlySuggestionProfileScanned === true
        || state.recently_suggestion_profile_scanned === true
        || state.recentlyProfileSuggestionScanned === true
        || state.recently_profile_suggestion_scanned === true,
      lastSuggestionProfileScanAt,
      suggestionProfileRecheckAfter,
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
    browserEngine: String(
      input.browserEngine
      || input.browser_engine
      || merged.browserEngine
      || 'chrome',
    ).trim().toLowerCase() || 'chrome',
    cloakHumanizeEnabled:
      input.cloakHumanizeEnabled === true
      || input.cloak_humanize_enabled === true,
    cloakHumanPreset: String(
      input.cloakHumanPreset
      || input.cloak_human_preset
      || merged.cloakHumanPreset
      || '',
    ).trim(),
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
    relationshipPartitionLargeLists: input?.relationshipPartitionLargeLists !== false
      && input?.relationship_partition_large_lists !== false
      && merged.relationshipPartitionLargeLists !== false,
    relationshipPartitionThreshold: Math.max(
      1,
      normalizeOptionalPositiveInteger(
        input?.relationshipPartitionThreshold || input?.relationship_partition_threshold,
        merged.relationshipPartitionThreshold || 250,
      ) || 250,
    ),
    relationshipSearchQueriesPerDialog: Math.max(
      1,
      Math.floor(normalizeNumberAtLeast(
        input?.relationshipSearchQueriesPerDialog || input?.relationship_search_queries_per_dialog,
        merged.relationshipSearchQueriesPerDialog || 8,
        1,
      )),
    ),
    relationshipSearchPartitionMaxItems: Math.max(
      25,
      normalizeOptionalPositiveInteger(
        input?.relationshipSearchPartitionMaxItems || input?.relationship_search_partition_max_items,
        merged.relationshipSearchPartitionMaxItems || 250,
      ) || 250,
    ),
    relationshipSearchMaxDepth: Math.min(
      4,
      Math.max(
        1,
        normalizeOptionalPositiveInteger(
          input?.relationshipSearchMaxDepth || input?.relationship_search_max_depth,
          merged.relationshipSearchMaxDepth || 3,
        ) || 3,
      ),
    ),
    relationshipProgressCheckpointSize: Math.max(
      25,
      normalizeOptionalPositiveInteger(
        input?.relationshipProgressCheckpointSize || input?.relationship_progress_checkpoint_size,
        merged.relationshipProgressCheckpointSize || 250,
      ) || 250,
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
    relationshipSearchWaitMs: normalizeNumberAtLeast(
      input?.relationshipSearchWaitMs || input?.relationship_search_wait_ms,
      merged.relationshipSearchWaitMs || 900,
      250,
    ),
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
    suggestionCandidateMaxAttempts: Math.min(
      10,
      Math.floor(normalizeNumberAtLeast(input?.suggestionCandidateMaxAttempts, merged.suggestionCandidateMaxAttempts || 1, 1)),
    ),
    suggestionCandidateRetryDelayMs: normalizeNumberAtLeast(
      input?.suggestionCandidateRetryDelayMs,
      merged.suggestionCandidateRetryDelayMs || 3000,
      0,
    ),
    suggestionSkipPreviouslyChecked: input?.suggestionSkipPreviouslyChecked !== false
      && input?.suggestion_skip_previously_checked !== false
      && merged.suggestionSkipPreviouslyChecked !== false,
    suggestionNoMatchSkipAfter: Math.min(
      100,
      Math.floor(normalizeNumberAtLeast(input?.suggestionNoMatchSkipAfter, merged.suggestionNoMatchSkipAfter || 2, 1)),
    ),
    suggestionCandidateRecheckHours: Math.min(
      168,
      Math.floor(normalizeNumberAtLeast(
        input?.suggestionCandidateRecheckHours || input?.suggestion_candidate_recheck_hours,
        merged.suggestionCandidateRecheckHours || 48,
        1,
      )),
    ),
    suggestionMaxScraperProfileSwitches: Math.min(
      10,
      Math.floor(normalizeNumberAtLeast(input?.suggestionMaxScraperProfileSwitches, merged.suggestionMaxScraperProfileSwitches || 3, 0)),
    ),
    postScanMaxItems: normalizeOptionalPositiveInteger(input?.postScanMaxItems, merged.postScanMaxItems || 100) || 100,
    postScanMaxScrollRounds: normalizeOptionalPositiveInteger(input?.postScanMaxScrollRounds, merged.postScanMaxScrollRounds || 40) || 40,
    postScanMaxLikesPerPost: normalizeOptionalPositiveInteger(
      input?.postScanMaxLikesPerPost || input?.post_scan_max_likes_per_post,
      merged.postScanMaxLikesPerPost || 250,
    ) || 250,
    postScanMaxCommentsPerPost: normalizeOptionalPositiveInteger(
      input?.postScanMaxCommentsPerPost || input?.post_scan_max_comments_per_post,
      merged.postScanMaxCommentsPerPost || 250,
    ) || 250,
    postScanOpenLikesDialogEnabled: input?.postScanOpenLikesDialogEnabled !== false
      && input?.post_scan_open_likes_dialog_enabled !== false
      && merged.postScanOpenLikesDialogEnabled !== false,
    postScanLikeDialogMaxScrollRounds: normalizeOptionalPositiveInteger(
      input?.postScanLikeDialogMaxScrollRounds || input?.post_scan_like_dialog_max_scroll_rounds,
      merged.postScanLikeDialogMaxScrollRounds || 40,
    ) || 40,
    postScanCommentDialogMaxScrollRounds: normalizeOptionalPositiveInteger(
      input?.postScanCommentDialogMaxScrollRounds || input?.post_scan_comment_dialog_max_scroll_rounds,
      merged.postScanCommentDialogMaxScrollRounds || 40,
    ) || 40,
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
      10,
      Math.floor(normalizeNumberAtLeast(input?.publicConnectionCandidateMaxAttempts, merged.publicConnectionCandidateMaxAttempts || 3, 1)),
    ),
    publicConnectionCandidateMaxDurationMs: normalizeNumberAtLeast(input?.publicConnectionCandidateMaxDurationMs, merged.publicConnectionCandidateMaxDurationMs || 1200000, 60000),
    publicConnectionDialogMissingMaxAttempts: Math.floor(normalizeNumberAtLeast(input?.publicConnectionDialogMissingMaxAttempts, merged.publicConnectionDialogMissingMaxAttempts || 2, 1)),
    publicConnectionRateLimitAccountSwitchEnabled: input?.publicConnectionRateLimitAccountSwitchEnabled !== false
      && input?.public_connection_rate_limit_account_switch_enabled !== false
      && merged.publicConnectionRateLimitAccountSwitchEnabled !== false,
    publicConnectionMaxScraperProfileSwitches: Math.min(
      10,
      Math.floor(normalizeNumberAtLeast(input?.publicConnectionMaxScraperProfileSwitches, merged.publicConnectionMaxScraperProfileSwitches || 3, 0)),
    ),
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
