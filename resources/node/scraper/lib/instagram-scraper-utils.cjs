function normalizeText(value = '') {
  return String(value || '').replace(/\s+/g, ' ').trim();
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

function normalizeNumberAtLeast(value, fallback, minimum) {
  const normalizedValue = Number(value ?? fallback);

  if (!Number.isFinite(normalizedValue)) {
    return Math.max(minimum, fallback);
  }

  return Math.max(minimum, normalizedValue);
}

function escapeHtml(value = '') {
  return String(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

module.exports = {
  debugLog,
  dedupe,
  escapeHtml,
  normalizeInstagramUsername,
  normalizeNumberAtLeast,
  normalizeOptionalPositiveInteger,
  normalizeText,
  summarizeCookieCollection,
};
