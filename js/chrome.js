/**
 * chrome.js — everything on screen around the page itself
 *
 * GenericPOS Accounting · ES module
 *
 *   applyChrome(route, user)   called by the router before each page mounts
 *   refreshChrome(user?)       re-render after sign-in/out or a settings change
 *   afterRender(route)         mark the current nav item, refresh the counts
 *   refreshBadges(force?)      the counts beside Journal entries, Approvals and the bell
 *   setAdminTitle(text)        a page can retitle the top bar ("GJ-2026-00051")
 *
 * Two chromes, chosen per route and written to body[data-chrome]:
 *   auth   a slim header with the company's name (sign-in, password reset, setup)
 *   admin  the sidebar and top bar; the page renders inside them
 *
 * The sidebar shows only what the signed-in role can open (PLAN.md §8); the
 * server checks every call again, so a hidden link is a courtesy, not a guard.
 *
 * NOTE: the page element (#gp-view) is MOVED into the frame for admin routes
 * and back out for everything else. One element, never two: a second view
 * container is how a page ends up mounted twice.
 */

import { esc, toast } from './ui.js';
import { api, logout } from './api.js';
import { store, assetUrl, setThemeOverride } from './store.js';
import { navigate, hasRole, currentRoute } from './router.js';

let _user = null;
let _badges = { unread: 0, awaiting_approval: 0, my_rejected: 0 };
let _badgesAt = 0;
let _badgesBusy = null;

const $ = (id) => document.getElementById(id);

export const ROLE_LABEL = { viewer: 'Viewer', bookkeeper: 'Bookkeeper', accountant: 'Accountant', admin: 'Administrator' };

function initials(name) {
  const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
  return ((parts[0] || '?')[0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
}

function brandHtml(href = './') {
  const s = store();
  const mark = s.logo
    ? '<img src="' + esc(assetUrl(s.logo)) + '" alt="' + esc(s.name) + '">'
    : '<span class="brand-mark">' + esc(initials(s.name)) + '</span><span class="brand-name">' + esc(s.name) + '</span>';
  return '<a class="brand" href="' + href + '">' + mark + '</a>';
}

function avatarHtml(u) {
  return u && u.avatar_url
    ? '<span class="avatar"><img src="' + esc(assetUrl(u.avatar_url)) + '" alt=""></span>'
    : '<span class="avatar">' + esc(initials(u && (u.full_name || u.username))) + '</span>';
}

function themeLabel() {
  let o = null;
  try { o = localStorage.getItem('acc_theme'); } catch { /* blocked */ }
  return o === 'dark' ? ['moon', 'Dark theme'] : o === 'light' ? ['sun', 'Light theme'] : ['monitor', 'Theme: automatic'];
}

function userMenuHtml(u) {
  const t = themeLabel();
  return '<div class="rel" data-user-menu>'
    + '<button class="icon-btn" type="button" data-act="user-menu" aria-haspopup="menu" aria-expanded="false" aria-label="Account menu">' + avatarHtml(u) + '</button>'
    + '<div class="menu" role="menu" hidden>'
    + '<div class="menu-head"><div class="truncate" style="font-weight:650">' + esc(u.full_name || u.username) + '</div>'
    + '<div class="small muted truncate">' + esc(ROLE_LABEL[u.role] || u.role || '') + (u.email ? ' · ' + esc(u.email) : '') + '</div></div>'
    + '<a href="account" role="menuitem"><span data-icon="user-circle" data-icon-size="18"></span>My account</a>'
    + '<a href="notifications" role="menuitem"><span data-icon="bell" data-icon-size="18"></span>Notifications</a>'
    + '<hr><button type="button" role="menuitem" data-act="theme"><span data-icon="' + t[0] + '" data-icon-size="18"></span>' + t[1] + '</button>'
    + '<button type="button" role="menuitem" data-act="logout"><span data-icon="sign-out" data-icon-size="18"></span>Sign out</button>'
    + '</div></div>';
}

// ─── Sign-in pages ──────────────────────────────────────────────────────────

function renderAuthHeader() {
  $('gp-header').innerHTML = '<div class="container hdr-in">' + brandHtml('./') + '</div>';
}

// ─── The app frame ──────────────────────────────────────────────────────────

/* [group label, who sees the group, items: [nav key, href, icon, label, who sees it, badge]] */
const NAV = [
  ['', 'viewer', [['dashboard', './', 'squares-four', 'Dashboard']]],
  ['Ledger', 'viewer', [
    ['journals', 'journals', 'book-open', 'Journal entries', 'viewer', 'my_rejected'],
    ['approvals', 'approvals', 'check-square', 'Approvals', 'accountant', 'awaiting_approval'],
    ['templates', 'saved-entries', 'copy', 'Saved entries', 'bookkeeper'],
    ['accounts', 'accounts', 'list-numbers', 'Chart of accounts'],
    ['periods', 'periods', 'calendar', 'Fiscal years and periods'],
    ['opening', 'opening-balances', 'stack', 'Opening balances', 'admin'],
    ['year-end', 'year-end', 'lock-key', 'Year-end closing', 'admin'],
  ]],
  ['Sales', 'viewer', [
    ['customers', 'customers', 'users', 'Customers'],
    ['invoices', 'invoices', 'receipt', 'Invoices'],
    ['receipts', 'receipts', 'coins', 'Receipts'],
  ]],
  ['Purchases', 'viewer', [
    ['suppliers', 'suppliers', 'truck', 'Suppliers'],
    ['bills', 'bills', 'article', 'Bills'],
    ['payments', 'payments', 'money', 'Payments'],
  ]],
  ['Cash and assets', 'viewer', [
    ['banking', 'banking', 'bank', 'Banking'],
    ['assets', 'assets', 'package', 'Fixed assets'],
    ['depreciation', 'depreciation', 'clock-counter-clockwise', 'Depreciation'],
  ]],
  ['Planning', 'viewer', [
    ['budgets', 'budgets', 'sliders', 'Budgets'],
    ['departments', 'departments', 'buildings', 'Departments'],
  ]],
  ['Reports', 'viewer', [
    ['bs', 'reports/balance-sheet', 'scales', 'Balance sheet'],
    ['is', 'reports/income-statement', 'chart-line', 'Income statement'],
    ['tb', 'reports/trial-balance', 'calculator', 'Trial balance'],
    ['gl', 'reports/general-ledger', 'rows', 'General ledger'],
    ['aging', 'reports/aging', 'hourglass', 'Aging'],
    ['analysis', 'reports/analysis', 'chart-bar', 'Financial analysis'],
    ['reports', 'reports', 'folder', 'All reports'],
  ]],
  ['Administration', 'admin', [
    ['users', 'users', 'users-three', 'Users'],
    ['settings', 'settings', 'gear', 'Settings'],
    ['imports', 'imports', 'upload-simple', 'Imports'],
    ['integrity', 'reports/integrity', 'shield-check', 'Integrity check'],
    ['audit', 'audit', 'clipboard-text', 'Audit log'],
  ]],
  ['', 'user', [['help', 'help', 'question', 'Help', 'user']]],
];

const BADGE_CLASS = { my_rejected: 'badge-err', awaiting_approval: 'badge-warn' };

function badgeHtml(key) {
  const n = _badges[key] || 0;
  return '<span class="badge ' + (BADGE_CLASS[key] || 'badge-brand') + '" data-badge="' + key + '"'
    + (n ? '' : ' hidden') + '>' + (n > 99 ? '99+' : n) + '</span>';
}

function renderAdmin(route) {
  const side = NAV.filter((g) => hasRole(_user, g[1])).map(([label, , items]) => {
    const links = items.filter((it) => hasRole(_user, it[4] || 'viewer')).map(([key, hrefv, icon, text, , badge]) =>
      '<a class="side-link" href="' + hrefv + '" data-nav="' + key + '"><span data-icon="' + icon + '" data-icon-size="19"></span>'
      + '<span class="grow truncate">' + esc(text) + '</span>' + (badge ? badgeHtml(badge) : '') + '</a>').join('');
    return links ? (label ? '<div class="side-group">' + esc(label) + '</div>' : '') + links : '';
  }).join('');

  const sw = store().software || {};
  $('gp-admin-side').innerHTML = brandHtml('./') + '<nav class="side-nav" aria-label="Main">' + side + '</nav>'
    + '<div class="side-foot xs faint">' + esc((sw.name || '') + (sw.version ? ' ' + sw.version : '')) + '</div>';

  const n = _badges.unread || 0;
  $('gp-admin-top').innerHTML =
      '<button class="icon-btn side-toggle" type="button" data-act="side" aria-label="Menu"><span data-icon="list" data-icon-size="22"></span></button>'
    + '<div class="title truncate" data-admin-title>' + esc(route.title || '') + '</div><div class="grow"></div>'
    + (_user
      ? '<a class="icon-btn" href="notifications" aria-label="Notifications"><span data-icon="bell" data-icon-size="20"></span>'
        + '<span class="count-dot" data-badge="unread"' + (n ? '' : ' hidden') + '>' + (n > 99 ? '99+' : n) + '</span></a>' + userMenuHtml(_user)
      : '');
}

// ─── Placement ──────────────────────────────────────────────────────────────

function placeView(chrome) {
  const v = $('gp-view');
  if (chrome === 'admin') {
    if (v.parentElement !== $('gp-admin-slot')) $('gp-admin-slot').appendChild(v);
  } else if (v.parentElement !== $('gp-app')) {
    $('gp-app').appendChild(v);
  }
}

export function applyChrome(route, user) {
  _user = user || null;
  const chrome = route.chrome || 'admin';
  document.body.setAttribute('data-chrome', chrome);
  document.body.classList.remove('side-open');
  placeView(chrome);

  $('gp-header').hidden = chrome !== 'auth';
  $('gp-admin').hidden = chrome !== 'admin';

  if (chrome === 'auth') renderAuthHeader();
  else if (chrome === 'admin') renderAdmin(route);
}

export function refreshChrome(user) {
  if (user !== undefined) _user = user;
  const cur = currentRoute();
  if (cur) applyChrome(cur.route, _user);
  afterRender(cur ? cur.route : null);
}

export function afterRender(route) {
  const nav = route && route.nav;
  document.querySelectorAll('[data-nav]').forEach((a) => {
    if (a.getAttribute('data-nav') === nav) a.setAttribute('aria-current', 'page'); else a.removeAttribute('aria-current');
  });
  refreshBadges(false);
}

// ─── Counts ─────────────────────────────────────────────────────────────────

function paintBadges() {
  document.querySelectorAll('[data-badge]').forEach((el) => {
    const n = _badges[el.getAttribute('data-badge')] || 0;
    el.textContent = n > 99 ? '99+' : String(n);
    el.hidden = !n;
  });
}

/** Fresh counts at most every 30 seconds, or at once after an action (force). */
export function refreshBadges(force = false) {
  if (!_user || !hasRole(_user, 'viewer')) return Promise.resolve();
  if (!force && Date.now() - _badgesAt < 30000) { paintBadges(); return Promise.resolve(); }
  if (_badgesBusy) return _badgesBusy;
  _badgesBusy = api.get('/dashboard/badges', null, { timeout: 8000 })
    .then((b) => { _badges = Object.assign({}, _badges, b || {}); _badgesAt = Date.now(); paintBadges(); })
    .catch(() => { /* counts are a nicety; the pages themselves are the record */ })
    .finally(() => { _badgesBusy = null; });
  return _badgesBusy;
}

/** Pages can retitle the top bar. */
export function setAdminTitle(text) {
  const el = document.querySelector('[data-admin-title]');
  if (el) el.textContent = text || '';
}

// ─── Delegated behaviour (bound once) ───────────────────────────────────────

function closeMenus(except) {
  document.querySelectorAll('[data-user-menu] .menu').forEach((m) => {
    if (m === except) return;
    m.hidden = true;
    const b = m.parentElement.querySelector('[data-act="user-menu"]');
    if (b) b.setAttribute('aria-expanded', 'false');
  });
}

document.addEventListener('click', async (e) => {
  const act = e.target.closest('[data-act]');
  const inMenu = e.target.closest('[data-user-menu]');
  if (!inMenu) closeMenus();

  if (document.body.classList.contains('side-open') && !e.target.closest('.admin-side') && !(act && act.dataset.act === 'side')) {
    document.body.classList.remove('side-open');
  }
  if (e.target.closest('.admin-side a')) document.body.classList.remove('side-open');
  if (!act) {
    if (inMenu && e.target.closest('a')) closeMenus();
    return;
  }

  switch (act.dataset.act) {
    case 'user-menu': {
      const menu = act.parentElement.querySelector('.menu');
      const open = menu.hidden;
      closeMenus(menu);
      menu.hidden = !open;
      act.setAttribute('aria-expanded', open ? 'true' : 'false');
      break;
    }
    case 'side': document.body.classList.toggle('side-open'); break;
    case 'theme': {
      let o = null;
      try { o = localStorage.getItem('acc_theme'); } catch { /* blocked */ }
      const next = o === null ? 'light' : o === 'light' ? 'dark' : null;
      setThemeOverride(next);
      closeMenus();
      refreshChrome();
      toast(next === 'dark' ? 'Dark theme on' : next === 'light' ? 'Light theme on' : 'Theme follows your device', { kind: 'info' });
      break;
    }
    case 'logout': {
      closeMenus();
      await logout();
      _badges = { unread: 0, awaiting_approval: 0, my_rejected: 0 };
      _badgesAt = 0;
      toast('You have signed out.');
      navigate('/login', { replace: true });
      break;
    }
    default: break;
  }
});

document.addEventListener('keydown', (e) => {
  if (e.key !== 'Escape') return;
  closeMenus();
  document.body.classList.remove('side-open');
});
