/**
 * router.js — client-side routes, navigation and page loading
 *
 * GenericPOS Accounting · ES module
 *
 *   ROUTES                     the whole map of the app, in one place
 *   startRouter(hooks)         intercept links, handle back/forward, render the first page
 *   navigate(path, opts?)      '/journals/412' — app paths always start with '/'
 *   href(path)                 an app path → an absolute URL under the mount point
 *   appPath(url)               the reverse; null for a URL outside the app
 *   hasRole(user, need)        'user' (anyone signed in) | viewer | bookkeeper | accountant | admin
 *
 * ─── HOW A PAGE IS BUILT ──────────────────────────────────────────────────
 * A route names a fragment, pages/<page>.html. Its root element carries
 * data-module="<name>", and js/<name>.js exports mount(root, ctx). mount may
 * return a cleanup function — timers, observers, listeners on window — and
 * the router calls it before the next page mounts, so a screen can never keep
 * running after the user has left it.
 *
 * ─── PATHS ARE RELATIVE TO THE MOUNT POINT ────────────────────────────────
 * The shell carries <base href="/dashboard/accounting/"> (or "/" at a domain
 * root). Markup therefore writes links WITHOUT a leading slash —
 * href="journals/412" — and the same markup works in both places. Code
 * navigates with app paths ('/journals/412'), which href() resolves against
 * that base.
 *
 * ─── ROLES ────────────────────────────────────────────────────────────────
 * The same ladder as the server (api_helper.php role_rank): viewer 1,
 * bookkeeper 2, accountant 3, admin 4. Hiding a route here is a convenience;
 * every endpoint checks the role again.
 *
 * NOTE: a missing fragment does not 404 — Apache hands every unknown path to
 * the SPA catch-all, which answers 200 with the shell. The loader therefore
 * treats a response containing <html as "no such page" rather than injecting
 * a second copy of the application into the first.
 */

import { emptyState, setTitle } from './ui.js';
import { store } from './store.js';

const RANK = { viewer: 1, bookkeeper: 2, accountant: 3, admin: 4 };

const r = (path, page, chrome, extra) => Object.assign({ path, page, chrome }, extra || {});

const TABLE = [
  // ── sign-in and first run ────────────────────────────────────────────────
  r('/login', 'login', 'auth', { guest: true, title: 'Sign in' }),
  r('/forgot-password', 'forgot-password', 'auth', { title: 'Reset your password' }),
  r('/reset-password', 'reset-password', 'auth', { title: 'Choose a new password' }),
  r('/verify-email', 'verify-email', 'auth', { title: 'Confirm your email' }),
  r('/setup', 'setup', 'auth', { title: 'Set up' }),

  // ── the ledger ───────────────────────────────────────────────────────────
  r('/', 'dashboard', 'admin', { auth: 'viewer', nav: 'dashboard', title: 'Dashboard' }),
  r('/journals', 'journals', 'admin', { auth: 'viewer', nav: 'journals', title: 'Journal entries' }),
  r('/journals/new', 'journal-edit', 'admin', { auth: 'bookkeeper', nav: 'journals', title: 'New entry' }),
  r('/journals/:id/edit', 'journal-edit', 'admin', { auth: 'bookkeeper', nav: 'journals', title: 'Edit entry' }),
  r('/journals/:id', 'journal', 'admin', { auth: 'viewer', nav: 'journals', title: 'Journal entry' }),
  r('/approvals', 'journals', 'admin', { auth: 'accountant', nav: 'approvals', title: 'Approvals', params: { view: 'approvals' } }),
  r('/accounts', 'accounts', 'admin', { auth: 'viewer', nav: 'accounts', title: 'Chart of accounts' }),
  r('/periods', 'periods', 'admin', { auth: 'viewer', nav: 'periods', title: 'Fiscal years and periods' }),
  r('/reports', 'reports', 'admin', { auth: 'viewer', nav: 'reports', title: 'Reports' }),
  r('/reports/balance-sheet', 'statement', 'admin', { auth: 'viewer', nav: 'bs', title: 'Balance sheet', params: { kind: 'balance-sheet' } }),
  r('/reports/income-statement', 'statement', 'admin', { auth: 'viewer', nav: 'is', title: 'Income statement', params: { kind: 'income-statement' } }),
  r('/reports/changes-in-equity', 'statement', 'admin', { auth: 'viewer', nav: 'reports', title: 'Changes in equity', params: { kind: 'changes-in-equity' } }),
  r('/reports/cash-flows', 'statement', 'admin', { auth: 'viewer', nav: 'reports', title: 'Cash flows', params: { kind: 'cash-flows' } }),
  r('/reports/trial-balance', 'trial-balance', 'admin', { auth: 'viewer', nav: 'tb', title: 'Trial balance' }),
  r('/reports/general-ledger', 'general-ledger', 'admin', { auth: 'viewer', nav: 'gl', title: 'General ledger' }),
  r('/reports/books', 'books', 'admin', { auth: 'viewer', nav: 'reports', title: 'Books of accounts' }),
  r('/reports/analysis', 'analysis', 'admin', { auth: 'viewer', nav: 'analysis', title: 'Financial analysis' }),
  r('/reports/aging', 'aging', 'admin', { auth: 'viewer', nav: 'aging', title: 'Aging' }),
  r('/reports/customer-statement', 'statement-of-account', 'admin', { auth: 'viewer', nav: 'customers', title: 'Statement of account' }),
  r('/reports/subsidiary-ledger', 'subsidiary-ledger', 'admin', { auth: 'viewer', nav: 'reports', title: 'Subsidiary ledger' }),
  r('/reports/bank-reconciliation', 'bank-reconciliation', 'admin', { auth: 'viewer', nav: 'banking', title: 'Bank reconciliation' }),
  r('/reports/lapsing-schedule', 'lapsing-schedule', 'admin', { auth: 'viewer', nav: 'assets', title: 'Lapsing schedule' }),
  r('/reports/budget-vs-actual', 'budget-vs-actual', 'admin', { auth: 'viewer', nav: 'budgets', title: 'Budget vs actual' }),
  r('/reports/department-income', 'department-income', 'admin', { auth: 'viewer', nav: 'departments', title: 'Income by department' }),
  r('/reports/worksheet', 'worksheet', 'admin', { auth: 'viewer', nav: 'reports', title: 'Worksheet' }),
  r('/reports/integrity', 'integrity', 'admin', { auth: 'accountant', nav: 'integrity', title: 'Integrity check' }),

  // ── sales and purchases ──────────────────────────────────────────────────
  r('/customers', 'contacts', 'admin', { auth: 'viewer', nav: 'customers', title: 'Customers', params: { role: 'customer' } }),
  r('/suppliers', 'contacts', 'admin', { auth: 'viewer', nav: 'suppliers', title: 'Suppliers', params: { role: 'supplier' } }),
  r('/contacts/:id', 'contact', 'admin', { auth: 'viewer', nav: 'customers', title: 'Customer or supplier' }),
  r('/invoices', 'documents', 'admin', { auth: 'viewer', nav: 'invoices', title: 'Invoices', params: { side: 'sales' } }),
  r('/bills', 'documents', 'admin', { auth: 'viewer', nav: 'bills', title: 'Bills', params: { side: 'purchases' } }),
  r('/documents/new', 'document-edit', 'admin', { auth: 'bookkeeper', nav: 'invoices', title: 'New document' }),
  r('/documents/:id/edit', 'document-edit', 'admin', { auth: 'bookkeeper', nav: 'invoices', title: 'Edit document' }),
  r('/documents/:id', 'document', 'admin', { auth: 'viewer', nav: 'invoices', title: 'Document' }),
  r('/receipts', 'settlements', 'admin', { auth: 'viewer', nav: 'receipts', title: 'Receipts', params: { kind: 'receipt' } }),
  r('/payments', 'settlements', 'admin', { auth: 'viewer', nav: 'payments', title: 'Payments', params: { kind: 'payment' } }),
  r('/settlements/new', 'settlement-edit', 'admin', { auth: 'bookkeeper', nav: 'receipts', title: 'New receipt or payment' }),
  r('/settlements/:id/edit', 'settlement-edit', 'admin', { auth: 'bookkeeper', nav: 'receipts', title: 'Edit receipt or payment' }),
  r('/settlements/:id', 'settlement', 'admin', { auth: 'viewer', nav: 'receipts', title: 'Receipt or payment' }),

  // ── cash and assets ──────────────────────────────────────────────────────
  r('/banking', 'banking', 'admin', { auth: 'viewer', nav: 'banking', title: 'Banking' }),
  r('/banking/statements/:id', 'bank-statement', 'admin', { auth: 'viewer', nav: 'banking', title: 'Bank statement' }),
  r('/assets', 'assets', 'admin', { auth: 'viewer', nav: 'assets', title: 'Fixed assets' }),
  r('/assets/new', 'asset-edit', 'admin', { auth: 'bookkeeper', nav: 'assets', title: 'New asset' }),
  r('/assets/:id/edit', 'asset-edit', 'admin', { auth: 'bookkeeper', nav: 'assets', title: 'Edit asset' }),
  r('/assets/:id', 'asset', 'admin', { auth: 'viewer', nav: 'assets', title: 'Asset' }),
  r('/asset-categories', 'asset-categories', 'admin', { auth: 'viewer', nav: 'assets', title: 'Asset categories' }),
  r('/depreciation', 'depreciation', 'admin', { auth: 'viewer', nav: 'depreciation', title: 'Depreciation' }),

  // ── planning ─────────────────────────────────────────────────────────────
  r('/budgets', 'budgets', 'admin', { auth: 'viewer', nav: 'budgets', title: 'Budgets' }),
  r('/budgets/:id', 'budget', 'admin', { auth: 'viewer', nav: 'budgets', title: 'Budget' }),
  r('/departments', 'departments', 'admin', { auth: 'viewer', nav: 'departments', title: 'Departments' }),

  // ── the ledger's extras ──────────────────────────────────────────────────
  r('/saved-entries', 'templates', 'admin', { auth: 'bookkeeper', nav: 'templates', title: 'Saved entries' }),
  r('/opening-balances', 'opening-balances', 'admin', { auth: 'admin', nav: 'opening', title: 'Opening balances' }),
  r('/year-end', 'year-end', 'admin', { auth: 'admin', nav: 'year-end', title: 'Year-end closing' }),
  r('/vouchers/:id', 'voucher', 'admin', { auth: 'viewer', nav: 'journals', title: 'Voucher' }),
  r('/imports', 'imports', 'admin', { auth: 'admin', nav: 'imports', title: 'Imports' }),
  r('/help', 'help', 'admin', { auth: 'user', nav: 'help', title: 'Help' }),
  r('/help/:chapter', 'help', 'admin', { auth: 'user', nav: 'help', title: 'Help' }),

  // ── you ──────────────────────────────────────────────────────────────────
  r('/notifications', 'notifications', 'admin', { auth: 'user', nav: 'notifications', title: 'Notifications' }),
  r('/account', 'account', 'admin', { auth: 'user', nav: 'account', title: 'My account' }),

  // ── administration ───────────────────────────────────────────────────────
  r('/users', 'admin-staff', 'admin', { auth: 'admin', nav: 'users', title: 'Users' }),
  r('/settings', 'admin-settings', 'admin', { auth: 'admin', nav: 'settings', title: 'Settings' }),
  r('/audit', 'admin-audit', 'admin', { auth: 'admin', nav: 'audit', title: 'Audit log' }),

  // ── earlier addresses, still working ─────────────────────────────────────
  r('/admin', null, null, { redirect: '/' }),
  r('/admin/staff', null, null, { redirect: '/users' }),
  r('/admin/settings', null, null, { redirect: '/settings' }),
  r('/admin/audit', null, null, { redirect: '/audit' }),
];

function compile(route) {
  const names = [];
  const src = route.path.replace(/\/:([a-z_]+)/gi, (_, n) => { names.push(n); return '/([^/]+)'; });
  return Object.assign({}, route, { re: new RegExp('^' + (src === '/' ? '/' : src) + '/?$'), names });
}

export const ROUTES = TABLE.map(compile);

export function matchRoute(path) {
  for (const route of ROUTES) {
    const m = path.match(route.re);
    if (!m) continue;
    const params = Object.assign({}, route.params || {});
    route.names.forEach((n, i) => {
      try { params[n] = decodeURIComponent(m[i + 1]); } catch { params[n] = m[i + 1]; }
    });
    return { route, params };
  }
  return null;
}

// ─── URLs ───────────────────────────────────────────────────────────────────

let _base = null;

export function basePath() {
  if (_base) return _base;
  try { _base = new URL(document.baseURI).pathname; } catch { _base = '/'; }
  if (!_base.endsWith('/')) _base = _base.replace(/[^/]*$/, '');
  return _base;
}

export function appPath(url) {
  let u;
  try { u = typeof url === 'string' ? new URL(url, document.baseURI) : url; } catch { return null; }
  const b = basePath();
  let p = u.pathname;
  if (p + '/' === b) return '/';
  if (!p.startsWith(b)) return null;
  p = '/' + p.slice(b.length);
  return p.length > 1 ? p.replace(/\/+$/, '') : p;
}

export function href(path) {
  const s = String(path == null ? '/' : path);
  if (/^https?:\/\//i.test(s)) return s;
  return new URL(s.replace(/^\/+/, ''), document.baseURI).href;
}

/** A `next=` value is followed only if it is an app path — never a URL. */
export function safeNext(v) {
  const s = String(v || '');
  return /^\/(?![\/\\])[^\s]*$/.test(s) ? s : null;
}

/** 1–4 for the four roles, 0 for anything else signed in, -1 signed out. */
export function roleRank(user) {
  if (!user) return -1;
  return RANK[user.role] || 0;
}

export function hasRole(user, need) {
  if (!need) return true;
  if (!user) return false;
  if (need === 'user') return true;
  return roleRank(user) >= (RANK[need] || 99);
}

/** Where someone lands after signing in: the dashboard, whatever their role. */
export function landingFor(user) {
  if (!user) return '/login';
  return roleRank(user) >= 1 ? '/' : '/account';
}

// ─── Navigation ─────────────────────────────────────────────────────────────

let _hooks = {};
let _seq = 0;
let _cleanup = null;
let _abort = null;
let _current = null;
const _fragments = new Map();

export function currentRoute() { return _current; }

function saveScroll() {
  try { history.replaceState(Object.assign({}, history.state || {}, { gp: 1, y: window.scrollY }), ''); } catch { /* ignore */ }
}

export function navigate(to, opts = {}) {
  const url = href(to);
  if (new URL(url).origin !== location.origin) { location.href = url; return Promise.resolve(); }
  saveScroll();
  if (opts.replace) history.replaceState({ gp: 1 }, '', url);
  else history.pushState({ gp: 1 }, '', url);
  return render({ scroll: opts.keepScroll ? 'keep' : 'top' });
}

function teardown() {
  if (_abort) { try { _abort.abort(); } catch { /* ignore */ } _abort = null; }
  if (_cleanup) { try { _cleanup(); } catch (e) { console.error('[router] cleanup failed', e); } _cleanup = null; }
}

async function fragment(page) {
  if (_fragments.has(page)) return _fragments.get(page);
  const res = await fetch('pages/' + page + '.html', { credentials: 'same-origin' });
  if (res.status === 404) throw Object.assign(new Error('not found'), { notFound: true });
  if (!res.ok) throw new Error('HTTP ' + res.status);
  if (res.headers.get('X-GP-Shell')) throw Object.assign(new Error('not found'), { notFound: true });
  const html = await res.text();
  if (/<html[\s>]/i.test(html.slice(0, 600))) throw Object.assign(new Error('not found'), { notFound: true });
  _fragments.set(page, html);
  return html;
}

function view() { return document.getElementById('gp-view'); }

function notice(icon, title, text, actionHtml) {
  return '<div class="container narrow" style="padding-top:32px">' + emptyState(icon, title, text, actionHtml) + '</div>';
}

/** Render whatever the address bar says now. */
export async function render(opts = {}) {
  const seq = ++_seq;
  const url = new URL(location.href);
  const path = appPath(url);
  if (path === null) { location.reload(); return; }

  const query = url.searchParams;
  const user = _hooks.resolveUser ? await _hooks.resolveUser() : null;
  if (seq !== _seq) return;

  const m = matchRoute(path);
  let special = m ? null : 'notfound';

  if (m) {
    const rt = m.route;
    if (rt.redirect) return navigate(rt.redirect + url.search, { replace: true });
    if (rt.auth && !user) {
      return navigate('/login' + (path === '/' ? '' : '?next=' + encodeURIComponent(path + url.search)), { replace: true });
    }
    if (rt.guest && user) {
      return navigate(safeNext(query.get('next')) || landingFor(user), { replace: true });
    }
    if (rt.auth && !hasRole(user, rt.auth)) special = 'forbidden';
  }

  const route = m ? m.route : { path, page: null, chrome: user ? 'admin' : 'auth' };
  const params = m ? m.params : {};

  teardown();
  _abort = new AbortController();
  _current = { route, params, path, query, url };

  if (_hooks.applyChrome) _hooks.applyChrome(route, user);
  const v = view();

  const t = setTimeout(() => {
    if (seq === _seq) v.innerHTML = '<div class="loading-block"><div class="spinner spinner-lg"></div></div>';
  }, 180);

  const done = () => {
    clearTimeout(t);
    if (opts.scroll === 'top') window.scrollTo(0, 0);
    else if (opts.scroll === 'restore') {
      const y = (history.state && history.state.y) || 0;
      window.scrollTo(0, y);
      setTimeout(() => { if (seq === _seq) window.scrollTo(0, y); }, 120);
    }
    if (!opts.initial) { try { v.focus({ preventScroll: true }); } catch { /* ignore */ } }
    if (_hooks.afterRender) _hooks.afterRender(route, user);
  };

  const home = user ? (roleRank(user) >= 1 ? './' : 'account') : 'login';
  if (special === 'notfound') {
    setTitle('Page not found', store().name);
    v.innerHTML = notice('magnifying-glass', 'Page not found', 'The page you are looking for does not exist or has moved.',
      '<a class="btn" href="' + home + '">' + (user ? 'Go to the dashboard' : 'Sign in') + '</a>');
    return done();
  }
  if (special === 'forbidden') {
    setTitle('Not available', store().name);
    v.innerHTML = notice('lock-key', 'Not available to your role', 'Your account does not have access to this page. An administrator can change your role.',
      '<a class="btn" href="' + home + '">Go to my start page</a>');
    return done();
  }

  setTitle(route.title || '', store().name);

  try {
    const html = await fragment(route.page);
    if (seq !== _seq) return;
    /* The spinner timer must die the moment the page arrives. Left running
       until mount() finished, it fired DURING a slow mount and replaced the
       half-built page with a spinner — the page's own code went on filling
       elements that were no longer in the document, and the screen stayed
       blank. */
    clearTimeout(t);
    v.innerHTML = html;

    const host = v.querySelector('[data-module]');
    if (host) {
      const name = host.getAttribute('data-module');
      if (!/^[a-z0-9-]+$/.test(name)) throw new Error('bad module name');
      const mod = await import('./' + name + '.js');
      if (seq !== _seq) return;
      const ctx = {
        route, params, query, path, user,
        store: store(),
        signal: _abort.signal,
        navigate,
        href,
        setTitle: (title) => setTitle(title, store().name),
        reload: () => render({ scroll: 'keep' }),
        refreshChrome: () => _hooks.refreshChrome && _hooks.refreshChrome(),
      };
      const ret = await mod.mount(host, ctx);
      if (seq !== _seq) { if (typeof ret === 'function') { try { ret(); } catch { /* ignore */ } } return; }
      if (typeof ret === 'function') _cleanup = ret;
    }
  } catch (err) {
    if (seq !== _seq) return;
    if (err && err.notFound) {
      setTitle('Not built yet', store().name);
      v.innerHTML = notice('clock', 'Not built yet', 'This screen is not part of the app yet.', '<a class="btn" href="' + home + '">Go back</a>');
    } else {
      console.error('[router] page failed', err);
      v.innerHTML = notice('warning', 'Something went wrong', 'This page could not be loaded. Check your connection and try again.', '<button class="btn" type="button" data-retry>Try again</button>');
      const b = v.querySelector('[data-retry]');
      if (b) b.addEventListener('click', () => render({ scroll: 'keep' }));
    }
  }
  done();
}

// ─── Link interception ──────────────────────────────────────────────────────

const FILE_PATH = /^\/(api|uploads|icons|css|js|pages)(\/|$)|\.[a-z0-9]{2,5}$/i;

function onClick(e) {
  if (e.defaultPrevented || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey || e.altKey) return;
  const a = e.target.closest && e.target.closest('a[href]');
  if (!a) return;
  if ((a.target && a.target !== '_self') || a.hasAttribute('download') || a.hasAttribute('data-native')) return;

  const raw = a.getAttribute('href') || '';
  /* With <base href> in place, "#x" resolves to the HOME page plus #x — a full
     reload somewhere else. In-page anchors are scrolled to by hand instead. */
  if (raw.startsWith('#')) {
    e.preventDefault();
    const el = raw.length > 1 ? document.getElementById(decodeURIComponent(raw.slice(1))) : null;
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
    return;
  }
  if (/^(mailto|tel|sms|javascript):/i.test(raw)) return;

  let url;
  try { url = new URL(a.href); } catch { return; }
  if (url.origin !== location.origin) return;
  const p = appPath(url);
  if (p === null || FILE_PATH.test(p)) return;

  e.preventDefault();
  if (url.href === location.href) return render({ scroll: 'keep' });
  navigate(p + url.search + url.hash, { replace: a.hasAttribute('data-replace') });
}

export function startRouter(hooks) {
  _hooks = hooks || {};
  if ('scrollRestoration' in history) history.scrollRestoration = 'manual';
  document.addEventListener('click', onClick);
  window.addEventListener('popstate', () => render({ scroll: 'restore' }));
  return render({ scroll: 'none', initial: true });
}
