/* Thin fetch wrapper for the JSON endpoints under public/api/.
 *
 * Three jobs, and nothing else:
 *
 *   1. Send the CSRF header on every mutating request. lib/auth.php's
 *      require_same_origin() refuses a non-GET without it, so a hand-rolled
 *      fetch() that forgets returns 403 'csrf_check_failed' and looks like a
 *      permissions bug rather than a missing header.
 *   2. Throw on anything that isn't 2xx, carrying the server's own error CODE.
 *      Every endpoint answers a failure as {"error": "..."} (json_error() in
 *      lib/bootstrap.php), so the code is a stable string the caller can branch
 *      on — 'unauthorized', 'not_found', 'csrf_check_failed'. Branching on a
 *      human-readable message would break the first time one is reworded.
 *   3. Parse JSON once, in one place.
 *
 * There is deliberately no retry, no queue and no offline cache. This app talks
 * to a server on the same host over a connection you are already using; a
 * failed write should surface immediately as a snackbar, not be silently
 * retried into a list that disagrees with the database.
 */

/* Must equal CSRF_HEADER_VALUE in lib/auth.php. If you change one, change the
   other — there is no test that can catch this from the browser side. */
const CSRF_HEADER = 'X-Requested-With';
const CSRF_VALUE  = 'CRM';

/**
 * A failed request. `code` is the server's error string; `status` is the HTTP
 * status; `detail` is the optional human sentence json_error() may attach.
 *
 * Extending Error rather than returning {ok:false}: a caller that forgets to
 * check gets a visible unhandled rejection instead of quietly carrying on with
 * undefined data.
 */
export class ApiError extends Error {
  constructor(code, status, detail) {
    super(detail || code || ('HTTP ' + status));
    this.name = 'ApiError';
    this.code = code || 'request_failed';
    this.status = status;
    this.detail = detail || '';
  }
}

/** Append query params to a URL, skipping null/undefined. */
function withQuery(url, params) {
  if (!params) { return url; }
  const qs = new URLSearchParams();
  for (const [key, value] of Object.entries(params)) {
    if (value !== null && value !== undefined) { qs.set(key, String(value)); }
  }
  const query = qs.toString();
  if (query === '') { return url; }
  return url + (url.includes('?') ? '&' : '?') + query;
}

/**
 * One request. Everything else here is a wrapper around this.
 *
 * @param {string} method
 * @param {string} url     relative to the current page, e.g. 'api/items.php'
 * @param {object} [opts]  { body, params, signal }
 * @returns {Promise<object>} the parsed JSON body
 */
export async function request(method, url, opts = {}) {
  const { body, params, signal } = opts;

  const headers = { 'Accept': 'application/json' };
  const init = { method, headers, signal, credentials: 'same-origin' };

  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(body);
  }
  if (method !== 'GET' && method !== 'HEAD') {
    headers[CSRF_HEADER] = CSRF_VALUE;
  }

  let response;
  try {
    response = await fetch(withQuery(url, params), init);
  } catch (err) {
    /* An AbortError is the caller's own doing — a superseded search, a
       navigation — and must stay distinguishable from a dead network, or a
       cancelled request pops an error snackbar. */
    if (err && err.name === 'AbortError') { throw err; }
    throw new ApiError('network_unreachable', 0, 'No connection to the server.');
  }

  /* Read the body as text first, always. A 500 from PHP is an HTML error page,
     and response.json() on that throws a SyntaxError that says nothing useful
     about what actually happened. */
  const text = await response.text();
  let payload = null;
  if (text !== '') {
    try {
      payload = JSON.parse(text);
    } catch {
      payload = null;
    }
  }

  if (!response.ok) {
    const code = payload && typeof payload.error === 'string' ? payload.error : null;
    const detail = payload && typeof payload.detail === 'string' ? payload.detail : '';
    throw new ApiError(code, response.status, detail);
  }

  /* A 2xx carrying {"error": ...} should not exist, but if an endpoint ever
     ships that bug it is better surfaced than treated as success. */
  if (payload && typeof payload.error === 'string') {
    throw new ApiError(payload.error, response.status, payload.detail || '');
  }

  return payload === null ? {} : payload;
}

/** GET. `params` become the query string. */
export function apiGet(url, params, signal) {
  return request('GET', url, { params, signal });
}

/** POST a JSON body. The workhorse — every write in this app is one of these. */
export function apiPost(url, body, signal) {
  return request('POST', url, { body, signal });
}

/**
 * POST, but never rejects: resolves to { ok: true, data } or
 * { ok: false, error }.
 *
 * For the fire-and-forget writes where the UI has already moved on — checking
 * an item, saving a reorder — and the only sensible response to a failure is a
 * snackbar. Wrapping each of those in its own try/catch is three lines of noise
 * per call site and one of them will eventually be forgotten.
 */
export async function apiTry(url, body) {
  try {
    return { ok: true, data: await apiPost(url, body) };
  } catch (err) {
    if (err && err.name === 'AbortError') { throw err; }
    return { ok: false, error: err instanceof ApiError ? err : new ApiError(null, 0, String(err)) };
  }
}
