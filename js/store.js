/**
 * store.js — the company's public configuration, branding, money and dates
 *
 * GenericPOS Accounting · ES module (the name "store" is kept from GenericPOS,
 * like the store_* setting keys it reads)
 *
 *   store()             the current config (synchronous; defaults until loaded)
 *   loadStore(force?)   read it from the server once per session (SW-cached)
 *   applyBranding(cfg)  brand colours, radius, font, theme on <html>
 *   money(cents)        "₱1,234.50" in the company's own currency format
 *   toCents(str)        what someone typed → integer centavos, EXACT, or null
 *   toMajor(cents)      123450 → "1234.50", for an input's value
 *   fmtDate(utc, kind)  a UTC 'Y-m-d H:i:s' from the API in the company's time zone
 *   fmtDay(ymd, kind)   a business date ('2026-09-14') as a calendar date, never shifted
 *   todayYmd()          today in the company's time zone
 *   assetUrl(path)      a stored upload path → a URL
 *
 * NOTE: THE SHELL ALREADY CARRIES THE BRANDING — the server injects the
 * colours into index.html and the config into window.GP_BOOT, so the first
 * paint is in the company's colours without a round trip.
 *
 * NOTE: MONEY NEVER BECOMES A FLOAT. Amounts are integer centavos end to end;
 * money() splits whole and fraction before anything is a string, and
 * toCents() parses digits, not numbers.
 */

import { kvGet, kvSet } from './idb.js';
import { api } from './api.js';

const DEFAULTS = {
  name: 'My Company',
  tagline: '',
  legal_name: '',
  logo: null,
  entity_type: 'business',
  brand: { primary: '#1d4ed8', accent: '#0f766e', theme: 'auto', radius: 8, font: 'system' },
  currency: { code: 'PHP', symbol: '₱', decimals: 2, position: 'before', thousands: ',', decimal: '.', locale: 'en-PH', timezone: 'Asia/Manila' },
  tax: { label: 'VAT', rate: 12, inclusive: true, registered: true, id_label: 'TIN' },
  features: { registration: false },
  software: { name: 'GenericPOS Accounting', version: '' },
};

let _cfg = null;
let _fresh = false;
let _loading = null;
let _cachedTried = false;

function merge(base, over) {
  const out = Object.assign({}, base);
  if (!over || typeof over !== 'object') return out;
  Object.keys(over).forEach((k) => {
    const v = over[k];
    out[k] = (v && typeof v === 'object' && !Array.isArray(v) && base[k] && typeof base[k] === 'object')
      ? Object.assign({}, base[k], v) : v;
  });
  return out;
}

export function store() {
  if (!_cfg) _cfg = merge(DEFAULTS, window.GP_BOOT || null);
  return _cfg;
}

export async function loadStore(force = false) {
  if (!_fresh && !window.GP_BOOT && !_cachedTried) {
    /* No server-injected config (the file opened off disk): paint from the
       last copy this device saw, then refresh from the network below. */
    _cachedTried = true;
    let cached = null;
    try { cached = await kvGet('store_cfg'); } catch { cached = null; }
    if (cached && !_fresh) {
      _cfg = merge(DEFAULTS, cached);
      applyBranding(_cfg);
      window.dispatchEvent(new CustomEvent('gp:store', { detail: _cfg }));
    }
  }
  if (!_cfg) { _cfg = merge(DEFAULTS, window.GP_BOOT || null); applyBranding(_cfg); }
  if (_fresh && !force) return _cfg;
  if (_loading) return _loading;

  _loading = (async () => {
    try {
      const c = await api.get('/store', null, { auth: false, timeout: 10000 });
      _cfg = merge(DEFAULTS, c);
      _fresh = true;
      kvSet('store_cfg', c).catch(() => {});
      applyBranding(_cfg);
      window.dispatchEvent(new CustomEvent('gp:store', { detail: _cfg }));
    } catch { /* offline — keep what we have */ }
    finally { _loading = null; }
    return _cfg;
  })();
  return _loading;
}

// ─── Branding ───────────────────────────────────────────────────────────────

function hexOk(v, d) { return /^#[0-9a-f]{6}$/i.test(String(v || '')) ? String(v).toLowerCase() : d; }

function inkFor(hex) {
  const h = hex.replace('#', '');
  const ch = [0, 2, 4].map((i) => {
    const v = parseInt(h.substr(i, 2), 16) / 255;
    return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
  });
  const lum = 0.2126 * ch[0] + 0.7152 * ch[1] + 0.0722 * ch[2];
  return lum > 0.45 ? '#0b1120' : '#ffffff';
}

const FONTS = {
  inter:   ['Inter', '"Inter", system-ui, sans-serif'],
  poppins: ['Poppins', '"Poppins", system-ui, sans-serif'],
  nunito:  ['Nunito', '"Nunito", system-ui, sans-serif'],
};

export function applyBranding(cfg) {
  const b = (cfg && cfg.brand) || DEFAULTS.brand;
  const root = document.documentElement;
  const primary = hexOk(b.primary, DEFAULTS.brand.primary);
  const accent = hexOk(b.accent, DEFAULTS.brand.accent);

  root.style.setProperty('--brand', primary);
  root.style.setProperty('--brand-ink', inkFor(primary));
  root.style.setProperty('--accent', accent);
  root.style.setProperty('--accent-ink', inkFor(accent));
  root.style.setProperty('--radius', Math.max(0, Math.min(24, parseInt(b.radius, 10) || 0)) + 'px');

  const mode = ['light', 'dark', 'auto'].includes(b.theme) ? b.theme : 'auto';
  const userMode = localStorageGet('acc_theme');
  root.setAttribute('data-theme', userMode || mode);

  const meta = document.querySelector('meta[name="theme-color"]');
  if (meta) meta.setAttribute('content', primary);

  const f = FONTS[b.font];
  if (f) {
    root.style.setProperty('--font', f[1]);
    if (!document.getElementById('gp-webfont')) {
      const l = document.createElement('link');
      l.id = 'gp-webfont';
      l.rel = 'stylesheet';
      l.href = 'https://fonts.googleapis.com/css2?family=' + encodeURIComponent(f[0]) + ':wght@400;500;600;700;800&display=swap';
      document.head.appendChild(l);
    }
  } else {
    root.style.removeProperty('--font');
  }
}

function localStorageGet(k) { try { return localStorage.getItem(k); } catch { return null; } }

/** The person's own light/dark choice, remembered on this device. */
export function setThemeOverride(mode) {
  try {
    if (mode) localStorage.setItem('acc_theme', mode);
    else localStorage.removeItem('acc_theme');
  } catch { /* storage blocked */ }
  applyBranding(store());
}

// ─── Money ──────────────────────────────────────────────────────────────────

/**
 * Integer minor units → display string. Integer arithmetic only: the amount is
 * split into whole and fractional parts BEFORE anything becomes a string, so
 * no float ever touches money.
 */
export function money(cents, o = {}) {
  const c = store().currency || DEFAULTS.currency;
  const dp = Number.isInteger(c.decimals) ? c.decimals : 2;
  const n = Math.round(Number(cents) || 0);
  const neg = n < 0;
  const abs = Math.abs(n);
  const scale = Math.pow(10, dp);
  const whole = Math.floor(abs / scale);
  const frac = abs - whole * scale;

  let s = String(whole).replace(/\B(?=(\d{3})+(?!\d))/g, c.thousands == null ? ',' : c.thousands);
  if (dp > 0) s += (c.decimal || '.') + String(frac).padStart(dp, '0');
  if (o.plain) return (neg ? '-' : '') + s;

  s = c.position === 'after' ? s + ' ' + c.symbol : c.symbol + s;
  if (neg) s = '−' + s;
  if (o.sign && !neg && n > 0) s = '+' + s;
  return s;
}

/** Major units for an <input> value: 123450 → "1234.50" (no grouping). */
export function toMajor(cents) {
  const dp = store().currency.decimals ?? 2;
  const n = Math.round(Number(cents) || 0);
  const neg = n < 0;
  const abs = Math.abs(n);
  const scale = Math.pow(10, dp);
  const whole = Math.floor(abs / scale);
  const frac = abs - whole * scale;
  return (neg ? '-' : '') + String(whole) + (dp > 0 ? '.' + String(frac).padStart(dp, '0') : '');
}

/**
 * "1,234.5" → 123450. EXACT (string manipulation, no float). null when the
 * text is not a plain amount or has more decimals than the currency.
 */
export function toCents(raw, allowNegative = false) {
  const dp = store().currency.decimals ?? 2;
  let s = String(raw == null ? '' : raw).trim().replace(/[\s,_]/g, '');
  const sym = store().currency.symbol;
  if (sym) s = s.split(sym).join('');
  const m = s.match(/^(-?)(\d{1,13})(?:\.(\d*))?$/) || s.match(/^(-?)()\.(\d+)$/);
  if (!m) return null;
  if (m[1] === '-' && !allowNegative) return null;
  let frac = m[3] || '';
  if (frac.length > dp) {
    if (/[^0]/.test(frac.slice(dp))) return null;
    frac = frac.slice(0, dp);
  }
  const digits = ((m[2] || '0') + frac.padEnd(dp, '0')).replace(/^0+(?=\d)/, '');
  const v = parseInt(digits, 10);
  if (!Number.isFinite(v)) return null;
  return m[1] === '-' ? -v : v;
}

// ─── Dates ──────────────────────────────────────────────────────────────────

/** The API stores and returns UTC 'YYYY-MM-DD HH:MM:SS'. */
export function parseUtc(v) {
  if (!v) return null;
  if (v instanceof Date) return v;
  const s = String(v).trim();
  const d = new Date(/[zZ]|[+-]\d\d:?\d\d$/.test(s) ? s : s.replace(' ', 'T') + 'Z');
  return isNaN(d.getTime()) ? null : d;
}

/** An INSTANT (a timestamp from the API) in the company's time zone. */
export function fmtDate(v, kind = 'datetime') {
  const d = parseUtc(v);
  if (!d) return '';
  const c = store().currency;
  const opts = { timeZone: c.timezone || undefined };
  if (kind === 'date') Object.assign(opts, { year: 'numeric', month: 'short', day: 'numeric' });
  else if (kind === 'time') Object.assign(opts, { hour: 'numeric', minute: '2-digit' });
  else if (kind === 'short') Object.assign(opts, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  else Object.assign(opts, { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  try { return d.toLocaleString(c.locale || undefined, opts); }
  catch { return d.toLocaleString(); }
}

/**
 * A BUSINESS DATE ('2026-09-14': an entry date, a period end) as a calendar
 * date. Never converted between time zones — a date an entry was keyed on is
 * the same date everywhere.
 */
export function fmtDay(ymd, kind = 'medium') {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(ymd || ''));
  if (!m) return '';
  const d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], 12));
  const opts = kind === 'short' ? { month: 'short', day: 'numeric' }
    : kind === 'long' ? { year: 'numeric', month: 'long', day: 'numeric' }
    : { year: 'numeric', month: 'short', day: 'numeric' };
  try { return d.toLocaleDateString(store().currency.locale || undefined, Object.assign({ timeZone: 'UTC' }, opts)); }
  catch { return m[0]; }
}

/** Today in the company's time zone, 'YYYY-MM-DD'. */
export function todayYmd() {
  try {
    const parts = new Intl.DateTimeFormat('en-CA', { timeZone: store().currency.timezone || undefined, year: 'numeric', month: '2-digit', day: '2-digit' })
      .formatToParts(new Date());
    const get = (t) => parts.find((p) => p.type === t).value;
    return get('year') + '-' + get('month') + '-' + get('day');
  } catch {
    return new Date().toISOString().slice(0, 10);
  }
}

export function timeAgo(v) {
  const d = parseUtc(v);
  if (!d) return '';
  const s = Math.round((Date.now() - d.getTime()) / 1000);
  if (s < 45) return 'just now';
  if (s < 3600) return Math.round(s / 60) + ' min ago';
  if (s < 86400) return Math.round(s / 3600) + ' h ago';
  if (s < 86400 * 7) return Math.round(s / 86400) + ' d ago';
  return fmtDate(v, 'date');
}

// ─── Assets ─────────────────────────────────────────────────────────────────

/** Stored paths are relative to the app root ('uploads/branding/ab12.png'). */
export function assetUrl(path) {
  if (!path) return '';
  const p = String(path);
  if (/^(https?:|data:|blob:)/i.test(p)) return p;
  const base = (window.GP_CONFIG && window.GP_CONFIG.asset_base) || '';
  return base + p.replace(/^\/+/, '');
}
