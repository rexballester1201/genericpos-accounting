/**
 * admin-audit.js — the audit log
 *
 * GenericPOS Accounting · ES module · administrators
 *
 *   GET /admin/audit?q=&action=&page=&per_page=
 *
 * Append-only: nothing here or anywhere else edits or deletes a row. Each line
 * says in plain words who did what, keeps the raw action code beside it for
 * anyone matching the log against the server, and links to the entry, month
 * or account it concerns.
 */

import { api } from './api.js';
import { fmtDate } from './store.js';
import { qs, esc, emptyState, pager, debounce } from './ui.js';
import { ROLE_LABEL } from './chrome.js';

const ACTION = {
  'journal.create': 'Prepared an entry', 'journal.update': 'Edited a draft entry', 'journal.submit': 'Submitted an entry for approval',
  'journal.reject': 'Rejected an entry', 'journal.cancel': 'Cancelled an entry', 'journal.post': 'Posted an entry',
  'journal.reverse': 'Reversed an entry',
  'period.close': 'Closed a month', 'period.reopen': 'Reopened a month', 'period.lock': 'Locked a month',
  'fiscal_year.create': 'Opened a fiscal year',
  'account.create': 'Added an account', 'account.update': 'Changed an account', 'account.delete': 'Deleted an account',
  'account.email_verified': 'Confirmed their email address',
  'staff.create': 'Added a user', 'staff.update': 'Changed a user',
  'settings.update': 'Changed settings', 'settings.reset': 'Put a setting back to its default', 'settings.upload': 'Uploaded an image',
  'mail.test': 'Sent a test email', 'setup.complete': 'Set up the ledger', 'cli.create_user': 'Created a user on the server',
};

/* Where a row's subject lives in the app. */
const TARGET = { journal: (id) => 'journals/' + id, period: () => 'periods', fiscal_year: () => 'periods', account: () => 'accounts', user: () => 'users' };

const val = (v) => {
  if (v === null || v === undefined || v === '') return '(empty)';
  if (typeof v === 'boolean') return v ? 'yes' : 'no';
  if (Array.isArray(v)) return v.map(val).join(', ');
  if (typeof v === 'object') return ('from' in v && 'to' in v) ? val(v.from) + ' → ' + val(v.to) : JSON.stringify(v);
  return String(v);
};
const detailText = (d) => {
  if (d == null || d === '') return '';
  if (typeof d !== 'object') return String(d);
  if (Array.isArray(d)) return d.map(val).join(', ');
  return Object.keys(d).filter((k) => d[k] !== null && d[k] !== '').map((k) => k.replace(/_/g, ' ') + ': ' + val(d[k])).join(' · ');
};

export async function mount(root, ctx) {
  const form = qs('[data-filters]', root);
  const f = form.elements;
  const tbody = qs('[data-rows]', root);
  const state = { q: ctx.query.get('q') || '', action: ctx.query.get('action') || '', page: 1 };
  f.q.value = state.q;
  f.action.value = state.action;
  let seq = 0;

  const sync = () => {
    const p = new URLSearchParams();
    if (state.q) p.set('q', state.q);
    if (state.action) p.set('action', state.action);
    const s = p.toString();
    history.replaceState(history.state, '', location.pathname + (s ? '?' + s : ''));
  };

  const row = (r) => {
    const target = r.target_type ? r.target_type.replace(/_/g, ' ') + (r.target_id ? ' #' + r.target_id : '') : '';
    const link = r.target_type && r.target_id && TARGET[r.target_type] ? TARGET[r.target_type](r.target_id) : '';
    return '<tr><td class="small nowrap">' + esc(fmtDate(r.at)) + '</td>'
      + '<td>' + esc(r.who) + (r.role ? '<div class="small muted">' + esc(ROLE_LABEL[r.role] || r.role) + '</div>' : '') + '</td>'
      + '<td>' + esc(ACTION[r.action] || r.action) + '<div class="small muted"><code>' + esc(r.action) + '</code>'
      + (target ? ' · ' + (link ? '<a href="' + esc(link) + '">' + esc(target) + '</a>' : esc(target)) : '') + '</div></td>'
      + '<td class="small" style="max-width:420px;overflow-wrap:anywhere">' + esc(detailText(r.detail)) + '</td>'
      + '<td class="small muted">' + esc(r.ip || '') + '</td></tr>';
  };

  const load = async () => {
    const my = ++seq;
    sync();
    try {
      const d = await api.get('/admin/audit', { q: state.q || null, action: state.action || null, page: state.page, per_page: 50 }, { signal: ctx.signal });
      if (my !== seq) return;
      const rows = d.items || [];
      qs('[data-sub]', root).textContent = d.total + ' entr' + (d.total === 1 ? 'y' : 'ies') + (state.q || state.action ? ' match' + (d.total === 1 ? 'es' : '') : '')
        + '. Every change made in the ledger, newest first. Nobody can edit or delete it.';
      tbody.innerHTML = rows.length ? rows.map(row).join('')
        : '<tr><td colspan="5">' + emptyState('clipboard-text', 'Nothing logged', state.q || state.action ? 'Try another search.' : 'Changes made in the ledger appear here.') + '</td></tr>';
      pager(qs('[data-pager]', root), d, (p) => { state.page = p; load(); window.scrollTo(0, 0); });
    } catch (err) {
      if (!ctx.signal.aborted && my === seq) tbody.innerHTML = '<tr><td colspan="5">' + emptyState('warning', 'Could not load the log', err.message) + '</td></tr>';
    }
  };

  form.addEventListener('submit', (e) => e.preventDefault());
  f.q.addEventListener('input', debounce(() => { state.q = f.q.value.trim(); state.page = 1; load(); }, 300));
  f.action.addEventListener('change', () => { state.action = f.action.value; state.page = 1; load(); });
  await load();
}
