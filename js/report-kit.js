/**
 * report-kit.js — what every report screen shares
 *
 * GenericPOS Accounting · ES module
 *
 *   amt(cents)                  1,234.50 · (1,234.50) when negative · – for nothing
 *   pct(part, whole)            "12.5%", "(3.0%)", or '' when there is no whole
 *   sheetHead(lh, title, lines) the letterhead at the top of a report sheet
 *   sheetFoot(lh)               the signature blocks and "printed by" (on paper only)
 *   presets(years, today)       period choices: this month, last month, this quarter,
 *                               year to date, and each fiscal year whole
 *   periodText(from, to)        "For the year ended …", "For the nine months ended …"
 *   asOfText(ymd)               "As of September 14, 2026"
 *   colLabel(col)               a column heading from its dates
 *   glHref(id, from, to)        the general ledger of an account over a period
 *   syncQuery(params)           keep a report's choices in the address bar
 *   exportCsv(btn, path, params, filename)
 *
 * Amounts follow the accounting convention of the printed statements: no
 * currency sign on every line (the heading names the currency), negatives in
 * parentheses, a dash for nothing.
 */

import { download } from './api.js';
import { money, fmtDay, fmtDate, store, assetUrl } from './store.js';
import { esc, toast, busy } from './ui.js';

// ─── Numbers ────────────────────────────────────────────────────────────────

export function amt(cents) {
  const n = Math.round(Number(cents) || 0);
  if (n === 0) return '–';
  const s = money(Math.abs(n), { plain: true });
  return n < 0 ? '(' + s + ')' : s;
}

export function pct(part, whole) {
  if (!whole) return '';
  const v = (Number(part) / Number(whole)) * 100;
  if (!Number.isFinite(v)) return '';
  const r = Math.abs(v) >= 100 ? Math.abs(v).toFixed(0) : Math.abs(v).toFixed(1);
  return v < 0 ? '(' + r + '%)' : r + '%';
}

export function currencyNote(code) {
  const c = code || store().currency.code || 'PHP';
  let name = c;
  try { name = new Intl.DisplayNames(['en'], { type: 'currency' }).of(c) || c; } catch { /* an older browser */ }
  return 'Amounts in ' + name;
}

// ─── Dates ──────────────────────────────────────────────────────────────────

const num = (s, a, b) => Number(String(s).slice(a, b));

/** The last day of the month that holds $ymd. */
export function monthEnd(ymd) {
  const d = new Date(Date.UTC(num(ymd, 0, 4), num(ymd, 5, 7), 0));
  return d.toISOString().slice(0, 10);
}

/** The first day of the month $n months after the one that holds $ymd. */
export function addMonths(ymd, n) {
  return new Date(Date.UTC(num(ymd, 0, 4), num(ymd, 5, 7) - 1 + n, 1)).toISOString().slice(0, 10);
}

export function addDays(ymd, n) {
  return new Date(Date.UTC(num(ymd, 0, 4), num(ymd, 5, 7) - 1, num(ymd, 8, 10) + n)).toISOString().slice(0, 10);
}

function monthsBetween(from, to) {
  return (num(to, 0, 4) - num(from, 0, 4)) * 12 + (num(to, 5, 7) - num(from, 5, 7)) + 1;
}

function monthName(ymd) {
  try {
    return new Intl.DateTimeFormat(store().currency.locale || undefined, { month: 'short', year: 'numeric', timeZone: 'UTC' })
      .format(new Date(Date.UTC(num(ymd, 0, 4), num(ymd, 5, 7) - 1, 1)));
  } catch { return ymd.slice(0, 7); }
}

const COUNT = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve'];

/** How a statement names its period, the way an accountant writes it. */
export function periodText(from, to) {
  if (!from || !to) return '';
  if (from.slice(8) === '01' && to === monthEnd(to)) {
    const n = monthsBetween(from, to);
    if (n === 1) return 'For the month ended ' + fmtDay(to, 'long');
    if (n === 12) return 'For the year ended ' + fmtDay(to, 'long');
    if (n === 3) return 'For the quarter ended ' + fmtDay(to, 'long');
    if (n > 1 && n < 12) return 'For the ' + COUNT[n] + ' months ended ' + fmtDay(to, 'long');
  }
  return 'For the period ' + fmtDay(from, 'long') + ' to ' + fmtDay(to, 'long');
}

export const asOfText = (ymd) => 'As of ' + fmtDay(ymd, 'long');

/** A column heading from its dates. */
export function colLabel(c) {
  if (!c) return '';
  if (c.total) return 'Total';
  if (c.as_of) return fmtDay(c.as_of);
  if (c.from && c.to) {
    if (c.from.slice(8) === '01' && c.to === monthEnd(c.to) && c.from.slice(0, 7) === c.to.slice(0, 7)) return monthName(c.from);
    if (c.from.slice(0, 4) === c.to.slice(0, 4)) return fmtDay(c.from, 'short') + ' – ' + fmtDay(c.to);
    return fmtDay(c.from) + ' – ' + fmtDay(c.to);
  }
  return '';
}

/**
 * The period choices for a report that covers a range.
 * @param {Array} years  the fiscal years (GET /fiscal-years), newest first
 */
export function presets(years, today) {
  const cur = (years || []).find((y) => y.start_date <= today && today <= y.end_date);
  const m0 = today.slice(0, 8) + '01';
  const lm = addMonths(m0, -1);
  const out = [
    { key: 'this_month', label: 'This month', from: m0, to: today },
    { key: 'last_month', label: 'Last month', from: lm, to: monthEnd(lm) },
  ];
  if (cur) {
    const q = addMonths(cur.start_date, Math.floor((monthsBetween(cur.start_date, today) - 1) / 3) * 3);
    out.push({ key: 'this_quarter', label: 'This quarter', from: q, to: today });
    out.push({ key: 'ytd', label: 'Year to date', from: cur.start_date, to: today });
  } else {
    out.push({ key: 'ytd', label: 'Year to date', from: today.slice(0, 4) + '-01-01', to: today });
  }
  (years || []).forEach((y) => out.push({ key: 'fy' + y.id, label: y.name + ', whole year', from: y.start_date, to: y.end_date }));
  return out;
}

export function presetFor(list, from, to) {
  const p = list.find((x) => x.from === from && x.to === to);
  return p ? p.key : 'custom';
}

// ─── The sheet ──────────────────────────────────────────────────────────────

/** The letterhead: company, registration details, the report's title and period. */
export function sheetHead(lh, title, lines = []) {
  const l = lh || {};
  const ids = [l.tin ? (l.tin_label || 'TIN') + ' ' + l.tin : '', l.cda_reg_no ? 'CDA Reg. No. ' + l.cda_reg_no : ''].filter(Boolean).join(' · ');
  const meta = [
    l.legal_name && l.legal_name !== l.company ? l.legal_name : '',
    l.tagline || '',
    l.address ? String(l.address).replace(/\s*\n\s*/g, ', ') : '',
    ids,
  ].filter(Boolean);
  return '<header class="report-head">'
    + (l.logo ? '<img class="rh-logo" src="' + esc(assetUrl(l.logo)) + '" alt="">' : '')
    + '<div class="rh-company">' + esc(l.company || store().name) + '</div>'
    + meta.map((m) => '<div class="rh-meta">' + esc(m) + '</div>').join('')
    + '<div class="rh-title">' + esc(title) + '</div>'
    + lines.filter(Boolean).map((t) => '<div class="rh-period">' + esc(t) + '</div>').join('')
    + '<div class="rh-note">' + esc([currencyNote(l.currency), l.note].filter(Boolean).join(' · ')) + '</div>'
    + '</header>';
}

/** The signature blocks and "printed by": they appear on paper, not on screen. */
export function sheetFoot(lh) {
  const l = lh || {};
  const sig = (l.signatories || []).map((s) => '<div><div class="sg-role">' + esc(s.role) + ':</div><div class="sg-line"></div>'
    + '<div class="sg-name">' + esc(s.name || '') + '</div><div class="sg-title">' + esc(s.title || '') + '</div></div>').join('');
  return (sig ? '<footer class="report-sign">' + sig + '</footer>' : '')
    + (l.printed_by ? '<div class="report-printed">Printed by ' + esc(l.printed_by) + ' on ' + esc(fmtDate(l.printed_at)) + '</div>' : '');
}

// ─── Links, the address bar, files ──────────────────────────────────────────

export const glHref = (id, from, to) => 'reports/general-ledger?' + new URLSearchParams({ account: String(id), from, to }).toString();

function toParams(params) {
  const p = new URLSearchParams();
  Object.keys(params).forEach((k) => {
    const v = params[k];
    if (v === '' || v === null || v === undefined || v === false) return;
    p.set(k, v === true ? '1' : String(v));
  });
  return p;
}

export function syncQuery(params) {
  const s = toParams(params).toString();
  history.replaceState(history.state, '', location.pathname + (s ? '?' + s : ''));
}

export async function exportCsv(btn, path, params, filename) {
  const p = toParams(params);
  p.set('format', 'csv');
  busy(btn, true);
  try {
    await download(path + '?' + p.toString(), filename);
  } catch (err) {
    toast(err.message, { kind: 'error' });
  } finally { busy(btn, false); }
}
