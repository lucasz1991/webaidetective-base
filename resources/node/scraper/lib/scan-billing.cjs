const DEFAULT_CREDIT_COSTS = {
  scan_base_credit: 1,
  scan_credit_per_minute: 2,
  scan_minimum_credits: 1,
  scan_max_billable_minutes: 30,
  profile_scan: 1,
  profile_image_scan: 1,
  post_scan: 3,
  new_posts_archive: 5,
  media_download_per_file: 5,
  ai_analysis_multiplier: 1000,
};

function normalizePositiveInteger(value, fallback, minimum = 0) {
  const numeric = Number(value);

  if (!Number.isFinite(numeric)) {
    return fallback;
  }

  return Math.max(minimum, Math.floor(numeric));
}

function normalizeCreditCosts(input = {}) {
  const source = input && typeof input === 'object' ? input : {};

  return {
    scanBaseCredit: normalizePositiveInteger(
      source.scan_base_credit ?? source.scanBaseCredit ?? source.profile_scan,
      DEFAULT_CREDIT_COSTS.scan_base_credit,
    ),
    scanCreditPerMinute: normalizePositiveInteger(
      source.scan_credit_per_minute ?? source.scanCreditPerMinute,
      DEFAULT_CREDIT_COSTS.scan_credit_per_minute,
    ),
    scanMinimumCredits: normalizePositiveInteger(
      source.scan_minimum_credits ?? source.scanMinimumCredits,
      DEFAULT_CREDIT_COSTS.scan_minimum_credits,
    ),
    scanMaxBillableMinutes: normalizePositiveInteger(
      source.scan_max_billable_minutes ?? source.scanMaxBillableMinutes,
      DEFAULT_CREDIT_COSTS.scan_max_billable_minutes,
      1,
    ),
    profileScan: normalizePositiveInteger(source.profile_scan ?? source.profileScan, DEFAULT_CREDIT_COSTS.profile_scan),
    profileImageScan: normalizePositiveInteger(source.profile_image_scan ?? source.profileImageScan, DEFAULT_CREDIT_COSTS.profile_image_scan),
    postScan: normalizePositiveInteger(source.post_scan ?? source.postScan, DEFAULT_CREDIT_COSTS.post_scan),
    newPostsArchive: normalizePositiveInteger(source.new_posts_archive ?? source.newPostsArchive, DEFAULT_CREDIT_COSTS.new_posts_archive),
    mediaDownloadPerFile: normalizePositiveInteger(
      source.media_download_per_file ?? source.mediaDownloadPerFile,
      DEFAULT_CREDIT_COSTS.media_download_per_file,
    ),
    aiAnalysisMultiplier: normalizePositiveInteger(
      source.ai_analysis_multiplier ?? source.aiAnalysisMultiplier,
      DEFAULT_CREDIT_COSTS.ai_analysis_multiplier,
      1,
    ),
  };
}

function countDownloadedMedia(profile) {
  if (!profile || typeof profile !== 'object') {
    return 0;
  }

  const imageUrls = Array.isArray(profile.imageUrls)
    ? profile.imageUrls
    : (Array.isArray(profile.image_urls) ? profile.image_urls : []);

  return imageUrls.length;
}

function resolveBillingStatus(payload) {
  if (payload?.statusLevel === 'error') {
    return 'failed';
  }

  if (payload?.gracefullyStopped) {
    return 'stopped';
  }

  if (payload?.statusLevel === 'partial') {
    return 'partial';
  }

  return 'success';
}

function calculateScanBilling(payload = {}, runtimeConfig = {}) {
  const durationMs = normalizePositiveInteger(payload.durationMs, 0);
  const durationSeconds = Math.max(0, Math.ceil(durationMs / 1000));
  const durationMinutes = Math.max(0, Math.ceil(durationSeconds / 60));
  const costs = normalizeCreditCosts(runtimeConfig.creditCosts || runtimeConfig.credit_costs || {});
  const billableMinutes = Math.min(durationMinutes, costs.scanMaxBillableMinutes);
  const status = resolveBillingStatus(payload);
  const featureCredits = {
    profileScan: costs.profileScan,
    profileImageScan: payload?.profile?.ogImage || payload?.profile?.profileImageUrl ? costs.profileImageScan : 0,
    postScan: payload?.operationMode === 'posts' ? costs.postScan : 0,
    mediaDownload: countDownloadedMedia(payload.profile) * costs.mediaDownloadPerFile,
    aiAnalysis: 0,
  };
  const runtimeCredits = billableMinutes * costs.scanCreditPerMinute;
  const rawCredits = costs.scanBaseCredit
    + runtimeCredits
    + Object.values(featureCredits).reduce((sum, value) => sum + value, 0);
  const totalCredits = Math.max(costs.scanMinimumCredits, rawCredits);

  return {
    status,
    strategy: 'base_plus_duration_plus_features',
    durationMs,
    durationSeconds,
    durationMinutes,
    billableSeconds: billableMinutes * 60,
    billableMinutes,
    maxBillableMinutes: costs.scanMaxBillableMinutes,
    baseCredits: costs.scanBaseCredit,
    runtimeCredits,
    featureCredits,
    totalCredits,
    currency: 'credits',
    reason: status === 'failed'
      ? 'Scan fehlgeschlagen; technische Refund-/Kulanzlogik erfolgt spaeter in PHP.'
      : 'Berechnet aus Basispreis, angefangenen Laufzeitminuten und Feature-Kosten.',
  };
}

function attachScanBilling(payload = {}, runtimeConfig = {}) {
  return {
    ...payload,
    billing: calculateScanBilling(payload, runtimeConfig),
  };
}

module.exports = {
  DEFAULT_CREDIT_COSTS,
  attachScanBilling,
  calculateScanBilling,
  normalizeCreditCosts,
};
