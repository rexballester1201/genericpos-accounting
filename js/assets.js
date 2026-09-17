/**
 * assets.js — the fixed-asset register
 *
 * GenericPOS Accounting · ES module · every role (bookkeepers and above register assets)
 *
 *   GET /assets?status=&category=&department=&q=&page=     the list, its totals and the counts per status
 *   …&format=csv                                            the same, as a spreadsheet file
 *
 * "In use" (active and fully depreciated) is the default: its totals are what
 * the asset accounts in the ledger should hold. The filters live in the
 * address bar, so a filtered list survives a reload and can be linked to.
 */

import { api } from './api.js';
import { money, fmtDay, todayYmd } from './store.js';
import { qs, esc, emptyState, statusBadge, pager, debounce } from './ui.js';
import { hasRole } from './router.js';
import { exportCsv } from './report-kit.js';

const TABS = [['in_use', 'In use'], ['active', 'Active'], ['fully_depreciated', 'Fully depreciated'], ['disposed', 'Disposed'], ['all', 'All']];

export async function mount(root, ctx) {
  const q = ctx.query;
  const state = {
    status: TABS.some(([k]) => k === q.get('status')) ? q.get('status') : 'in_use',
    category: q.get('category') || '', department: q.get('department') || '', q: q.get('q') || '',
    page: Math.max(1, parseInt(q.get('page') || '1', 10) || 1),
  };

  const form = qs('[data-filters]', root);
  const f = form.elements;
  const tbody = qs('[data-rows]', root);
  const tfoot = qs('[data-totals]', root);
  const tabs = qs('[data-tabs]', root);
  f.q.value = state.q;

  if (hasRole(ctx.user, 'bookkeeper')) {
    qs('[data-actions]', root).insertAdjacentHTML('beforeend', '<a class="btn" href="assets/new"><span data-icon="plus" data-icon-size="18"></span>New asset</a>');
  }

  const params = () => ({ status: state.status, category: state.category || null, department: state.department || null, q: state.q || null });
  const sync = () => {
    const p = new URLSearchParams();
    if (state.status !== 'in_use') p.set('status', state.status);
    ['category', 'department', 'q'].forEach((k) => { if (state[k]) p.set(k, state[k]); });
    if (state.page > 1) p.set('page', String(state.page));
    const s = p.toString();
    history.replaceState(history.state, '', location.pathname + (s ? '?' + s : ''));
  };

  const paintTabs = (counts) => {
    tabs.innerHTML = TABS.map(([k, label]) => '<button class="tab" type="button" role="tab" data-status="' + k + '" aria-selected="' + String(state.status === k) + '">'
      + esc(label) + ' <span class="faint num">' + (counts ? (counts[k] || 0) : '') + '</span></button>').join('');
  };

  let filled = false;
  const fillFilters = (d) => {
    if (filled) return;
    filled = true;
    f.category.insertAdjacentHTML('beforeend', (d.categories || []).map((c) => '<option value="' + c.id + '">' + esc(c.name) + (c.is_active ? '' : ' (inactive)') + '</option>').join(''));
    f.department.insertAdjacentHTML('beforeend', (d.departments || []).map((x) => '<option value="' + x.id + '">' + esc(x.code + ' · ' + x.name) + '</option>').join(''));
    f.category.value = state.category;
    f.department.value = state.department;
    f.department.hidden = !(d.departments || []).length;
  };

  const row = (a) => '<tr class="is-link" data-id="' + a.id + '">'
    + '<td class="code">' + esc(a.asset_no) + '</td>'
    + '<td style="min-width:220px"><div class="truncate" style="max-width:380px">' + esc(a.name) + '</div>'
    + (a.serial_no ? '<div class="small muted truncate" style="max-width:380px">Serial no. ' + esc(a.serial_no) + '</div>' : '') + '</td>'
    + '<td class="small">' + esc(a.category_name || '') + '</td>'
    + '<td class="nowrap">' + esc(fmtDay(a.acquired_on)) + '</td>'
    + '<td class="num">' + money(a.cost_cents) + '</td>'
    + '<td class="num">' + money(a.accumulated_cents) + '</td>'
    + '<td class="num">' + money(a.nbv_cents) + '</td>'
    + '<td class="nowrap">' + statusBadge(a.status) + (a.status === 'disposed' && a.disposed_on ? '<div class="xs faint">' + esc(fmtDay(a.disposed_on)) + '</div>' : '') + '</td>'
    + '<td class="small nowrap">' + esc(a.department_code || '') + '</td>'
    + '<td class="small">' + esc(a.location || '') + '</td></tr>';

  let seq = 0;
  const load = async () => {
    const my = ++seq;
    sync();
    try {
      const d = await api.get('/assets', Object.assign(params(), { page: state.page, per_page: 50 }), { signal: ctx.signal });
      if (my !== seq) return;
      fillFilters(d);
      paintTabs(d.counts);
      const rows = d.items || [];
      const filtered = state.q || state.category || state.department;
      tbody.innerHTML = rows.length ? rows.map(row).join('')
        : '<tr><td colspan="10">' + emptyState('package', filtered || state.status !== 'in_use' ? 'No assets match' : 'No assets yet',
          filtered || state.status !== 'in_use' ? 'Try another filter or tab.' : 'Register the property and equipment the company owns.',
          !filtered && hasRole(ctx.user, 'bookkeeper') ? '<a class="btn" href="assets/new">Register an asset</a>' : '') + '</td></tr>';
      const t = d.totals || {};
      tfoot.innerHTML = rows.length
        ? '<tr class="totals"><td colspan="4" class="right muted">Total · ' + t.count + ' asset' + (t.count === 1 ? '' : 's') + '</td>'
          + '<td class="num">' + money(t.cost_cents) + '</td><td class="num">' + money(t.accumulated_cents) + '</td><td class="num">' + money(t.nbv_cents) + '</td>'
          + '<td colspan="3"></td></tr>'
        : '';
      pager(qs('[data-pager]', root), d, (p) => { state.page = p; load(); window.scrollTo(0, 0); });
    } catch (err) {
      if (!ctx.signal.aborted && my === seq) tbody.innerHTML = '<tr><td colspan="10">' + emptyState('warning', 'Could not load the register', err.message) + '</td></tr>';
    }
  };

  tabs.addEventListener('click', (e) => {
    const b = e.target.closest('[data-status]');
    if (!b) return;
    state.status = b.dataset.status;
    state.page = 1;
    load();
  });
  form.addEventListener('submit', (e) => e.preventDefault());
  f.q.addEventListener('input', debounce(() => { state.q = f.q.value.trim(); state.page = 1; load(); }, 300));
  f.category.addEventListener('change', () => { state.category = f.category.value; state.page = 1; load(); });
  f.department.addEventListener('change', () => { state.department = f.department.value; state.page = 1; load(); });
  tbody.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-id]');
    if (tr) ctx.navigate('/assets/' + tr.dataset.id);
  });
  qs('[data-csv]', root).addEventListener('click', (e) => exportCsv(e.currentTarget, '/assets', params(), 'fixed-asset-register-' + todayYmd() + '.csv'));

  paintTabs(null);
  await load();
}
