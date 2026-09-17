/**
 * api.js — the one HTTP client for /api/v1
 *
 * GenericPOS Accounting · ES module
 *
 *   apiFetch(method, path, body?, opts?) → the envelope's `data`, or throws ApiError
 *   api.get / post / put / del / upload   thin wrappers
 *   getSession / setSession / clearSession / currentUser
 *   login / logout / refreshMe / download
 *
 * ─── THE ENVELOPE ─────────────────────────────────────────────────────────
 * Every response is { status, data, message }. Success returns `data`; a
 * failure throws ApiError carrying the server's message, HTTP status, a machine
 * `code` when there is one, and — for a 422 — the per-field `errors` map the
 * forms render inline.
 *
 * ─── SESSIONS AND 401 ─────────────────────────────────────────────────────
 * Access tokens are short-lived; refresh tokens last 30 days and ROTATE on use.
 * A 401 on an authenticated call triggers ONE refresh (single-flight: ten
 * requests failing together share one refresh, because the second refresh of a
 * rotated token would itself fail and sign the user out) and the request is
 * retried. Only when the refresh is refused is the session cleared and a
 * `gp:logout` event raised.
 */

import { kvGet, kvSet, kvDel } from './idb.js';

const TIMEOUT_MS = 20000;

let _session = null;
let _loaded = false;
let _refreshing = null;

export class ApiError extends Error {
  constructor({ message, status = 0, code = '', data = null, retryAfter = null } = {}) {
    super(message || 'Request failed');
    this.name = 'ApiError';
    this.status = status;
    this.code = code || '';
    this.data = data;
    this.errors = data && typeof data === 'object' && data.errors ? data.errors : null;
    this.retryAfter = retryAfter;
  }
  get isNetwork()   { return this.status === 0; }
  get isAuth()      { return this.status === 401; }
  get isForbidden() { return this.status === 403; }
  get isNotFound()  { return this.status === 404; }
  get isInvalid()   { return this.status === 422; }
  get isRateLimited() { return this.status === 429; }
}

export function apiBase() {
  const b = window.GP_CONFIG && window.GP_CONFIG.api_base;
  return String(b || 'api/v1').replace(/\/+$/, '');
}

// ─── Session ────────────────────────────────────────────────────────────────

async function loadSession() {
  if (_loaded) return _session;
  _loaded = true;
  try { _session = (await kvGet('session')) || null; } catch { _session = null; }
  return _session;
}

export async function getSession() { return loadSession(); }

export async function currentUser() {
  const s = await loadSession();
  return s ? s.user || null : null;
}

export async function setSession(s) {
  _loaded = true;
  _session = s && s.access_token
    ? { access_token: s.access_token, refresh_token: s.refresh_token || (_session && _session.refresh_token) || null, user: s.user || (_session && _session.user) || null }
    : null;
  try {
    if (_session) await kvSet('session', _session);
    else await kvDel('session');
  } catch { /* storage unavailable — the in-memory session still works for this tab */ }
  window.dispatchEvent(new CustomEvent('gp:session', { detail: { user: _session ? _session.user : null } }));
  return _session;
}

export async function updateUser(user) {
  const s = await loadSession();
  if (!s) return;
  s.user = Object.assign({}, s.user || {}, user || {});
  await setSession(s);
}

export async function clearSession() { return setSession(null); }

function emitLogout(reason) {
  window.dispatchEvent(new CustomEvent('gp:logout', { detail: { reason } }));
}

// ─── Transport ──────────────────────────────────────────────────────────────

async function rawFetch(method, path, body, opts) {
  const url = /^https?:\/\//i.test(path) ? path : apiBase() + path;
  const isForm = typeof FormData !== 'undefined' && body instanceof FormData;

  const headers = { Accept: 'application/json' };
  if (body != null && !isForm) headers['Content-Type'] = 'application/json';
  Object.assign(headers, opts.headers || {});

  let token = opts.token || null;
  if (!token && opts.auth !== false) {
    const s = await loadSession();
    if (s) token = s.access_token;
  }
  if (token) headers.Authorization = 'Bearer ' + token;

  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), opts.timeout || TIMEOUT_MS);
  if (opts.signal) opts.signal.addEventListener('abort', () => ctrl.abort(), { once: true });

  let res;
  try {
    res = await fetch(url, {
      method,
      headers,
      body: body == null ? undefined : (isForm ? body : JSON.stringify(body)),
      signal: ctrl.signal,
      credentials: 'omit',
    });
  } catch (err) {
    clearTimeout(timer);
    const aborted = err && err.name === 'AbortError';
    throw new ApiError({
      message: aborted ? 'The request timed out. Check your connection and try again.' : 'You appear to be offline. Check your connection and try again.',
      status: 0,
      code: aborted ? 'TIMEOUT' : 'NETWORK',
    });
  }
  clearTimeout(timer);

  if (opts.blob && res.ok) {
    const blob = await res.blob();
    return { ok: true, status: res.status, body: null, blob, headers: res.headers, usedToken: !!token };
  }

  let json = null;
  const ct = res.headers.get('Content-Type') || '';
  if (ct.includes('json')) {
    try { json = await res.json(); } catch { json = null; }
  }
  return { ok: res.ok, status: res.status, body: json, headers: res.headers, usedToken: !!token };
}

/**
 * Refresh the session once, however many callers ask at the same moment.
 * @returns {Promise<boolean>} TRUE when a new access token is in place.
 */
async function refreshSession() {
  if (_refreshing) return _refreshing;

  _refreshing = (async () => {
    const s = await loadSession();
    if (!s || !s.refresh_token) return false;
    try {
      const r = await rawFetch('POST', '/auth/refresh', { refresh_token: s.refresh_token }, { auth: false });
      if (r.ok && r.body && r.body.status && r.body.data && r.body.data.access_token) {
        await setSession(r.body.data);
        return true;
      }
      if (r.status === 401 || r.status === 400) {
        /* The server spends a refresh token once. Another tab may have used
           this one a moment ago and stored the new session: if the stored
           session has moved on, take it instead of signing everyone out. */
        let stored = null;
        try { stored = await kvGet('session'); } catch { stored = null; }
        if (stored && stored.access_token && stored.refresh_token && stored.refresh_token !== s.refresh_token) {
          _session = stored;
          _loaded = true;
          return true;
        }
        await clearSession();
        emitLogout('expired');
      }
      return false;
    } catch {
      return false;       // offline: keep the session, the next attempt may succeed
    }
  })();

  try { return await _refreshing; }
  finally { _refreshing = null; }
}

/**
 * @param {string} method
 * @param {string} path     '/journals'
 * @param {*}      [body]   object (JSON) or FormData
 * @param {object} [opts]   auth (default true), token, headers, timeout, signal, noRefresh, raw, blob
 */
export async function apiFetch(method, path, body = null, opts = {}) {
  let r = await rawFetch(method, path, body, opts);

  if (r.status === 401 && r.usedToken && !opts.token && !opts.noRefresh) {
    if (await refreshSession()) {
      r = await rawFetch(method, path, body, opts);
    }
    if (r.status === 401) {
      await clearSession();
      emitLogout('expired');
    }
  }

  if (opts.raw) return r;
  if (opts.blob && r.ok && r.blob) return r.blob;

  const env = r.body;
  if (!r.ok || !env || env.status === false) {
    let retryAfter = null;
    if (r.status === 429) retryAfter = parseInt(r.headers.get('Retry-After') || '0', 10) || null;
    throw new ApiError({
      message: (env && env.message) || (r.status ? 'Request failed (' + r.status + ')' : 'Request failed'),
      status: r.status,
      code: (env && env.code) || '',
      data: env ? env.data : null,
      retryAfter,
    });
  }
  return env.data;
}

function qs(params) {
  if (!params) return '';
  const u = new URLSearchParams();
  Object.keys(params).forEach((k) => {
    const v = params[k];
    if (v === undefined || v === null || v === '') return;
    u.set(k, String(v));
  });
  const s = u.toString();
  return s ? '?' + s : '';
}

export const api = {
  get:    (path, params, opts) => apiFetch('GET', path + qs(params), null, opts),
  post:   (path, body, opts)   => apiFetch('POST', path, body == null ? {} : body, opts),
  put:    (path, body, opts)   => apiFetch('PUT', path, body == null ? {} : body, opts),
  del:    (path, body, opts)   => apiFetch('DELETE', path, body, opts),
  upload: (path, formData, opts) => apiFetch('POST', path, formData, opts),
};

// ─── Auth helpers ───────────────────────────────────────────────────────────

export async function login(identifier, password) {
  const d = await apiFetch('POST', '/auth/login', { identifier, password }, { auth: false });
  await setSession(d);
  return d.user;
}

export async function refreshMe() {
  try {
    const d = await apiFetch('GET', '/auth/me');
    if (d && d.user) await updateUser(d.user);
    return d ? d.user : null;
  } catch (e) {
    if (e.isAuth) return null;
    throw e;
  }
}

/** Revoke the refresh token on the server (best effort), then forget the session. */
export async function logout() {
  const s = await loadSession();
  try {
    if (s && s.refresh_token) {
      await apiFetch('POST', '/auth/logout', { refresh_token: s.refresh_token }, { auth: false, timeout: 6000 });
    }
  } catch { /* best effort — the local clear below is what ends the session here */ }
  await clearSession();
}

/**
 * Save an authenticated file (a CSV export) to the device. A plain link cannot
 * carry the bearer token, so the file is fetched and handed over as a blob.
 */
export async function download(path, filename) {
  const blob = await apiFetch('GET', path, null, { blob: true, timeout: 60000 });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 10000);
}
