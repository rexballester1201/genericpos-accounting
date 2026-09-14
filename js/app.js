/**
 * app.js — boot
 *
 * GenericPOS Accounting · ES module · the only script the shell loads
 *
 * Order matters, and every step is allowed to fail without taking the app
 * down: icons paint, the saved session and company config are read from this
 * device, the router renders the first page, and only then does anything
 * touch the network for freshness (company config, the signed-in account,
 * the service worker).
 */

import { startAutoPaint } from './icons.js';
import { currentUser, getSession, refreshMe } from './api.js';
import { loadStore } from './store.js';
import { toast } from './ui.js';
import { startRouter, navigate, currentRoute } from './router.js';
import { applyChrome, refreshChrome, afterRender, refreshBadges } from './chrome.js';

async function boot() {
  startAutoPaint(document.body);

  loadStore();          // paints from the injected/cached config, refreshes in the background
  await getSession();

  window.addEventListener('gp:store', () => refreshChrome());
  window.addEventListener('gp:session', async () => refreshChrome(await currentUser()));
  window.addEventListener('gp:logout', onSessionEnded);
  window.addEventListener('gp:badges', () => refreshBadges(true));

  await startRouter({
    resolveUser: currentUser,
    applyChrome,
    refreshChrome,
    afterRender: (route, user) => {
      afterRender(route, user);
      window.dispatchEvent(new CustomEvent('gp:routed', { detail: { route } }));
    },
  });
  window.__gpBooted = true;

  /* Confirm the saved session is still good and pick up role changes made
     since it was issued. api.js clears it (and raises gp:logout) if not. */
  getSession().then((s) => { if (s) refreshMe().catch(() => {}); });

  offlineBar();
  serviceWorker();
}

function onSessionEnded() {
  const cur = currentRoute();
  if (cur && cur.route.auth) {
    toast('Your session has ended. Please sign in again.', { kind: 'info' });
    navigate('/login?next=' + encodeURIComponent(cur.path + location.search), { replace: true });
  } else {
    refreshChrome(null);
  }
}

// ─── Offline indicator ──────────────────────────────────────────────────────

function offlineBar() {
  const el = document.getElementById('gp-offline');
  if (!el) return;
  const update = () => { el.hidden = navigator.onLine !== false; };
  window.addEventListener('online', update);
  window.addEventListener('offline', update);
  update();
}

// ─── Service worker & updates ───────────────────────────────────────────────

/** Safe to reload under the user's feet? Not mid-form or mid-dialog. */
function isIdle() {
  if (window.GP_BUSY) return false;
  if (document.querySelector('dialog[open]')) return false;
  const a = document.activeElement;
  if (a && /^(INPUT|TEXTAREA|SELECT)$/.test(a.tagName)) return false;
  if (document.querySelector('form[data-dirty]')) return false;
  return true;
}

function showUpdateBar() {
  if (document.getElementById('gp-update')) return;
  const bar = document.createElement('div');
  bar.id = 'gp-update';
  bar.className = 'update-bar';
  bar.setAttribute('role', 'status');
  bar.innerHTML = 'A new version is ready. <button type="button">Reload</button>';
  bar.querySelector('button').addEventListener('click', () => location.reload());
  document.body.appendChild(bar);
}

function serviceWorker() {
  if (!('serviceWorker' in navigator)) return;
  const local = ['localhost', '127.0.0.1'].includes(location.hostname);
  if (location.protocol !== 'https:' && !local) return;

  let hadController = !!navigator.serviceWorker.controller;

  navigator.serviceWorker.register('sw.js').then((reg) => {
    setInterval(() => reg.update().catch(() => {}), 60 * 60 * 1000);
    document.addEventListener('visibilitychange', () => {
      if (document.visibilityState === 'visible') reg.update().catch(() => {});
    });
  }).catch((err) => console.warn('[sw] registration failed:', err));

  /* A new version took over. The first install claiming this tab is not an
     update — the page already runs the same code — so it never reloads. */
  let reloading = false;
  navigator.serviceWorker.addEventListener('controllerchange', () => {
    if (!hadController) { hadController = true; return; }
    if (reloading) return;
    if (isIdle()) { reloading = true; location.reload(); }
    else showUpdateBar();
  });
}

boot().catch((err) => {
  console.error('[boot] failed', err);
  if (window.__gpBootFailed) window.__gpBootFailed('It stopped while starting (' + ((err && err.message) || 'unknown error') + ').');
});
