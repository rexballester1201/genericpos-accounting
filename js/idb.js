/**
 * idb.js — client-side storage
 *
 * GenericPOS Accounting · ES module
 *
 *   kv    small values: the session and the cached company config
 *
 * ─── WHY INDEXEDDB ────────────────────────────────────────────────────────
 * localStorage is synchronous and capped around 5 MB. When IndexedDB is
 * unavailable (some private modes) every call falls back to localStorage, and
 * finally to memory — storage failing must never take a page down with it.
 *
 * NOTE: EVERYTHING IS NAMESPACED (PLAN.md §10). GenericPOS runs on the same
 * localhost origin and keeps its own "gp:" keys and database: this app's
 * database is "accounting" and its fallback keys start with "acc_". With a
 * shared key, signing into one app would sign you out of the other.
 *
 * NOTE: open() rejects on `blocked` instead of hanging forever when another
 * tab holds an older version open, and closes this connection on
 * `versionchange` so the other tab's upgrade can proceed.
 */

const DB_NAME = 'accounting';
const DB_VERSION = 1;

let _dbp = null;
let _broken = false;
const _mem = new Map();

function open() {
  if (_broken) return Promise.reject(new Error('IndexedDB unavailable'));
  if (_dbp) return _dbp;

  _dbp = new Promise((resolve, reject) => {
    let req;
    try { req = indexedDB.open(DB_NAME, DB_VERSION); }
    catch (e) { _broken = true; _dbp = null; reject(e); return; }

    req.onupgradeneeded = () => {
      const db = req.result;
      if (!db.objectStoreNames.contains('kv')) db.createObjectStore('kv', { keyPath: 'k' });
    };
    req.onsuccess = () => {
      const db = req.result;
      db.onversionchange = () => { db.close(); _dbp = null; };
      resolve(db);
    };
    req.onerror = () => { _dbp = null; _broken = true; reject(req.error); };
    req.onblocked = () => { _dbp = null; reject(new Error('IndexedDB upgrade blocked by another tab')); };
  });
  return _dbp;
}

function req(r) {
  return new Promise((resolve, reject) => {
    r.onsuccess = () => resolve(r.result);
    r.onerror = () => reject(r.error);
  });
}

// ─── kv ─────────────────────────────────────────────────────────────────────

const LS_PREFIX = 'acc_';

function lsGet(k) {
  try { const v = localStorage.getItem(LS_PREFIX + k); return v == null ? undefined : JSON.parse(v); }
  catch { return _mem.get(k); }
}
function lsSet(k, v) {
  try { localStorage.setItem(LS_PREFIX + k, JSON.stringify(v)); }
  catch { _mem.set(k, v); }
}
function lsDel(k) {
  try { localStorage.removeItem(LS_PREFIX + k); } catch { /* ignore */ }
  _mem.delete(k);
}

export async function kvGet(k) {
  try {
    const db = await open();
    const row = await req(db.transaction('kv', 'readonly').objectStore('kv').get(k));
    return row ? row.v : undefined;
  } catch {
    return lsGet(k);
  }
}

export async function kvSet(k, v) {
  try {
    const db = await open();
    await req(db.transaction('kv', 'readwrite').objectStore('kv').put({ k, v }));
  } catch {
    lsSet(k, v);
  }
}

export async function kvDel(k) {
  try {
    const db = await open();
    await req(db.transaction('kv', 'readwrite').objectStore('kv').delete(k));
  } catch {
    lsDel(k);
  }
}

/** Forget everything user-specific (sign-out on a shared device). */
export async function clearUserData() {
  await Promise.allSettled([kvDel('session')]);
}
