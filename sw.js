/**
 * sw.js — offline shell and asset caching
 *
 * GenericPOS Accounting · service worker (scope: the app's mount point)
 *
 * ─── THE ONE RULE ─────────────────────────────────────────────────────────
 * BUMP CACHE_VERSION ON EVERY DEPLOY THAT CHANGES index.html, css/, js/ OR
 * pages/. The new worker installs, takes over, deletes the old caches, and
 * the page reloads itself when idle (js/app.js). Forget the bump and people
 * keep yesterday's code until their cached copy happens to be revalidated.
 *
 * ─── WHAT IS CACHED, AND WHAT NEVER IS ────────────────────────────────────
 *   navigations      network first, the last good shell when offline
 *   css/js/pages     stale-while-revalidate (fast, and fresh on the next load)
 *   uploads/         cache first, capped (the logo, profile pictures)
 *   /api/v1/store    network first, the cached copy offline (branding only)
 *   ANY request with an Authorization header — never. The books stay out of
 *   the cache, so a shared device holds nothing of the last person's after
 *   sign-out.
 *
 * NOTE: EVERY FILE IN REQUIRED MUST EXIST. One missing file fails the install,
 * and a worker that cannot install can never replace the one before it — the
 * browser keeps serving that worker's old copies. v1.0.6 listed the deleted
 * js/cart.js and stranded every browser on the shop's code. OPTIONAL entries
 * are best-effort.
 *
 * NOTE: every unknown path answers 200 with the SPA shell (marked by the
 * X-GP-Shell header). Such a response is never stored under a non-navigation
 * URL, or pages/missing.html would be cached as a copy of the whole app.
 */

const CACHE_VERSION = 'v2.2.0';

/* acc- prefix: cache names are per ORIGIN, not per scope, so GenericPOS (gp-)
   on the same host shares them. Our own prefix keeps our caches, and our
   clean-up in 'activate', away from theirs. */
const SHELL   = 'acc-shell-' + CACHE_VERSION;
const DATA    = 'acc-data-' + CACHE_VERSION;
const IMAGES  = 'acc-images-v1';      // uploads do not change with code; kept across versions
const RUNTIME = 'acc-runtime-v1';     // web fonts
const OWNED   = [SHELL, DATA, IMAGES, RUNTIME];
const IMAGE_MAX = 200;

const SCOPE = new URL(self.registration.scope);
const BASE  = SCOPE.pathname;                     // "/dashboard/accounting/" or "/"

const REQUIRED = ['./', 'css/app.css', 'js/app.js', 'js/api.js', 'js/idb.js', 'js/store.js', 'js/ui.js',
  'js/icons.js', 'js/router.js', 'js/chrome.js'];
const OPTIONAL = [
  'js/account.js', 'js/accounts.js', 'js/admin-audit.js', 'js/admin-settings.js', 'js/admin-staff.js', 'js/aging.js',
  'js/analysis.js', 'js/asset-categories.js', 'js/asset-edit.js', 'js/asset.js', 'js/assets.js', 'js/attachments.js',
  'js/auth-pages.js', 'js/bank-reconciliation.js', 'js/bank-statement.js', 'js/banking.js', 'js/books.js',
  'js/budget-vs-actual.js', 'js/budget.js', 'js/budgets.js', 'js/contact.js', 'js/contacts.js', 'js/countries.js',
  'js/dashboard.js', 'js/department-income.js', 'js/departments.js', 'js/depreciation.js', 'js/document-edit.js',
  'js/document.js', 'js/documents.js', 'js/general-ledger.js', 'js/help.js', 'js/imports.js', 'js/integrity.js',
  'js/journal-edit.js', 'js/journal.js', 'js/journals.js', 'js/lapsing-schedule.js', 'js/notifications.js',
  'js/opening-balances.js', 'js/periods.js', 'js/phone-field.js', 'js/report-kit.js', 'js/reports.js',
  'js/settlement-edit.js', 'js/settlement.js', 'js/settlements.js', 'js/setup.js', 'js/statement-of-account.js',
  'js/statement.js', 'js/subsidiary-ledger.js', 'js/templates.js', 'js/trial-balance.js', 'js/voucher.js',
  'js/worksheet.js', 'js/year-end.js', 'pages/account.html', 'pages/accounts.html', 'pages/admin-audit.html',
  'pages/admin-settings.html', 'pages/admin-staff.html', 'pages/aging.html', 'pages/analysis.html',
  'pages/asset-categories.html', 'pages/asset-edit.html', 'pages/asset.html', 'pages/assets.html',
  'pages/bank-reconciliation.html', 'pages/bank-statement.html', 'pages/banking.html', 'pages/books.html',
  'pages/budget-vs-actual.html', 'pages/budget.html', 'pages/budgets.html', 'pages/contact.html',
  'pages/contacts.html', 'pages/dashboard.html', 'pages/department-income.html', 'pages/departments.html',
  'pages/depreciation.html', 'pages/document-edit.html', 'pages/document.html', 'pages/documents.html',
  'pages/forgot-password.html', 'pages/general-ledger.html', 'pages/help.html', 'pages/imports.html', 'pages/integrity.html',
  'pages/journal-edit.html', 'pages/journal.html', 'pages/journals.html', 'pages/lapsing-schedule.html',
  'pages/login.html', 'pages/notifications.html', 'pages/opening-balances.html', 'pages/periods.html',
  'pages/reports.html', 'pages/reset-password.html', 'pages/settlement-edit.html', 'pages/settlement.html',
  'pages/settlements.html', 'pages/setup.html', 'pages/statement-of-account.html', 'pages/statement.html',
  'pages/subsidiary-ledger.html', 'pages/templates.html', 'pages/trial-balance.html', 'pages/verify-email.html',
  'pages/voucher.html', 'pages/worksheet.html', 'pages/year-end.html', 'icons/icon-192.png', 'icons/favicon.png', 'help/guide.json'];

function isShell(res) { return !!(res && res.headers && res.headers.get('X-GP-Shell')); }

self.addEventListener('install', (event) => {
  event.waitUntil((async () => {
    const cache = await caches.open(SHELL);
    const get = async (u, required) => {
      try {
        const res = await fetch(new Request(u, { cache: 'reload', credentials: 'same-origin' }));
        if (!res.ok) throw new Error(u + ' → HTTP ' + res.status);
        if (u !== './' && isShell(res)) throw new Error(u + ' → missing');
        await cache.put(u, res);
      } catch (err) {
        if (required) throw err;
      }
    };
    await Promise.all(REQUIRED.map((u) => get(u, true)));
    await Promise.all(OPTIONAL.map((u) => get(u, false)));
    await self.skipWaiting();
  })());
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const names = await caches.keys();
    await Promise.all(names
      .filter((n) => n.startsWith('acc-') && !OWNED.includes(n))
      .map((n) => caches.delete(n)));
    await self.clients.claim();
  })());
});

// ─── Fetch ──────────────────────────────────────────────────────────────────

self.addEventListener('fetch', (event) => {
  const req = event.request;
  if (req.method !== 'GET') return;

  const url = new URL(req.url);

  if (url.origin !== self.location.origin) {
    if (url.hostname === 'fonts.gstatic.com' || url.hostname === 'fonts.googleapis.com') {
      event.respondWith(staleWhileRevalidate(event, RUNTIME));
    }
    return;
  }
  if (!url.pathname.startsWith(BASE)) return;
  const path = url.pathname.slice(BASE.length);

  if (req.mode === 'navigate') { event.respondWith(navigation(event)); return; }

  if (path.startsWith('api/')) {
    if (req.headers.has('Authorization')) return;
    if (path === 'api/v1/store') event.respondWith(networkFirst(req, DATA, 5000));
    return;
  }
  if (path === 'sw.js') return;
  if (path.startsWith('uploads/')) { event.respondWith(imageCacheFirst(event)); return; }
  if (/^(css|js|pages|icons)\//.test(path) || path === 'manifest.webmanifest') {
    event.respondWith(staleWhileRevalidate(event, SHELL));
  }
});

function timeout(promise, ms) {
  return Promise.race([promise, new Promise((_, reject) => setTimeout(() => reject(new Error('timeout')), ms))]);
}

async function navigation(event) {
  try {
    const res = await timeout(fetch(event.request), 6000);
    /* Every app route answers with the same shell, so the freshest good one
       becomes the offline copy — it also carries the latest branding. */
    if (res.ok && isShell(res)) {
      const copy = res.clone();
      event.waitUntil(caches.open(SHELL).then((c) => c.put('./', copy)).catch(() => {}));
    }
    return res;
  } catch (err) {
    const cache = await caches.open(SHELL);
    return (await cache.match('./')) || offlinePage();
  }
}

async function networkFirst(req, cacheName, ms) {
  const cache = await caches.open(cacheName);
  try {
    const res = await timeout(fetch(req), ms);
    if (res.ok) cache.put(req, res.clone()).catch(() => {});
    return res;
  } catch (err) {
    const hit = await cache.match(req);
    if (hit) return hit;
    return new Response(JSON.stringify({ status: false, data: null, message: 'You are offline. Check your connection and try again.' }),
      { status: 503, headers: { 'Content-Type': 'application/json' } });
  }
}

async function staleWhileRevalidate(event, cacheName) {
  const req = event.request;
  const cache = await caches.open(cacheName);
  const hit = await cache.match(req);
  const fresh = fetch(req).then((res) => {
    if (res.ok && !isShell(res)) cache.put(req, res.clone()).catch(() => {});
    return res;
  }).catch(() => null);

  if (hit) { event.waitUntil(fresh); return hit; }
  return (await fresh) || new Response('', { status: 504, statusText: 'Offline' });
}

async function imageCacheFirst(event) {
  const req = event.request;
  const cache = await caches.open(IMAGES);
  const hit = await cache.match(req);
  if (hit) return hit;
  try {
    const res = await fetch(req);
    if (res.ok && !isShell(res)) event.waitUntil(cache.put(req, res.clone()).then(() => trim(cache, IMAGE_MAX)).catch(() => {}));
    return res;
  } catch (err) {
    return new Response('', { status: 504, statusText: 'Offline' });
  }
}

async function trim(cache, max) {
  const keys = await cache.keys();
  for (let i = 0; i < keys.length - max; i++) await cache.delete(keys[i]);
}

function offlinePage() {
  return new Response(
    '<!DOCTYPE html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
    + '<title>Offline</title><body style="font-family:system-ui,sans-serif;display:flex;min-height:90vh;align-items:center;justify-content:center;text-align:center;padding:24px">'
    + '<div><h1 style="font-size:22px">You are offline</h1><p style="color:#667085">Check your connection, then try again.</p>'
    + '<button onclick="location.reload()" style="padding:10px 20px;border-radius:8px;border:0;background:#1d4ed8;color:#fff;font-weight:600">Try again</button></div></body>',
    { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}

// ─── Messages from the page ─────────────────────────────────────────────────

self.addEventListener('message', (event) => {
  const msg = event.data || {};
  if (msg.type === 'gp:skip-waiting') { self.skipWaiting(); return; }
  if (msg.type === 'gp:version' && event.ports[0]) event.ports[0].postMessage({ version: CACHE_VERSION });
});
