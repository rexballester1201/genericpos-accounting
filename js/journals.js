/**
 * journals.js — journal entries: the list, and the approval queue
 *
 * GenericPOS Accounting · ES module · every role (the queue: accountants)
 *
 * The filters live in the address bar, so a filtered list survives a reload
 * and the back button, and other screens can link to one (a trial-balance
 * row opens the entries behind that account). /approvals is the same list
 * fixed to entries waiting for approval.
 */

import { api } from './api.js';
import { money, fmtDay } from './store.js';
import { qs, esc, emptyState, statusBadge, pager, debounce } from './ui.js';
import { hasRole } from './router.js';

const BOOKS = [['general', 'General journal'], ['cash_receipts', 'Cash receipts'], ['cash_disbursements', 'Cash disbursements'],
  ['sales', 'Sales journal'], ['purchases', 'Purchase journal'], ['adjusting', 'Adjusting entries'],
  ['closing', 'Closing entries'], ['opening', 'Opening balances']];

const TABS = [['', 'All', 'all'], ['waiting', 'Not posted', 'waiting'], ['draft', 'Drafts', 'draft'],
  ['submitted', 'Waiting for approval', 'submitted'], ['rejected', 'Rejected', 'rejected'],
  ['posted', 'Posted', 'posted'], ['cancelled', 'Cancelled', 'cancelled']];

export async function mount(root, ctx) {
  const queue = !!(ctx.route.params && ctx.route.params.view === 'approvals');
  const q = ctx.query;
  const state = {
    status: queue ? 'submitted' : (q.get('status') || ''),
    book: q.get('book') || '', from: q.get('from') || '', to: q.get('to') || '', q: q.get('q') || '',
    mine: q.get('mine') === '1', account: q.get('account') || '', period: q.get('period') || '',
    page: Math.max(1, parseInt(q.get('page') || '1', 10) || 1),
  };

  const form = qs('[data-filters]', root);
  const f = form.elements;
  const tbody = qs('[data-rows]', root);
  const tabs = qs('[data-tabs]', root);

  f.book.innerHTML = '<option value="">All books</option>' + BOOKS.map(([k, l]) => '<option value="' + k + '">' + esc(l) + '</option>').join('');
  f.q.value = state.q;
  f.book.value = state.book;
  f.from.value = state.from;
  f.to.value = state.to;
  f.mine.checked = state.mine;

  if (queue) {
    qs('[data-title]', root).textContent = 'Approvals';
    qs('[data-sub]', root).textContent = 'Entries submitted for approval. Open one to approve and post it, or to reject it with a reason. You cannot approve your own.';
    tabs.hidden = true;
  }
  if (hasRole(ctx.user, 'bookkeeper')) {
    qs('[data-actions]', root).innerHTML = '<a class="btn" href="journals/new"><span data-icon="plus" data-icon-size="18"></span>New entry</a>';
  }

  const sync = () => {
    if (queue) return;
    const p = new URLSearchParams();
    ['status', 'book', 'from', 'to', 'q', 'account', 'period'].forEach((k) => { if (state[k]) p.set(k, state[k]); });
    if (state.mine) p.set('mine', '1');
    if (state.page > 1) p.set('page', String(state.page));
    const s = p.toString();
    history.replaceState(history.state, '', location.pathname + (s ? '?' + s : ''));
  };

  const paintTabs = (counts) => {
    if (queue) return;
    tabs.innerHTML = TABS.map(([value, label, key]) => '<button class="tab" type="button" role="tab" data-status="' + value + '" aria-selected="'
      + String(state.status === value) + '">' + esc(label) + ' <span class="faint num">' + (counts ? counts[key] || 0 : '') + '</span></button>').join('');
  };

  const paintScope = (d) => {
    const box = qs('[data-scope]', root);
    const bits = [];
    if (d.account) bits.push('touching <b>' + esc(d.account.code + ' · ' + d.account.name) + '</b>');
    if (state.period) bits.push('in the selected period');
    if (!bits.length) { box.hidden = true; return; }
    box.innerHTML = '<span data-icon="funnel"></span><div class="grow">Showing entries ' + bits.join(' and ') + '.</div><a class="btn btn-secondary btn-sm" href="journals">Show every entry</a>';
    box.hidden = false;
  };

  const row = (j) => '<tr class="is-link" data-id="' + j.id + '">'
    + '<td class="code">' + (j.journal_no ? esc(j.journal_no) : '<span class="faint">#' + j.id + '</span>') + '</td>'
    + '<td class="nowrap">' + esc(fmtDay(j.entry_date)) + '</td>'
    + '<td style="min-width:240px"><div class="truncate" style="max-width:440px">' + esc(j.description) + '</div>'
    + ((j.reference || j.party_name) ? '<div class="small muted truncate" style="max-width:440px">' + esc([j.reference, j.party_name].filter(Boolean).join(' · ')) + '</div>' : '')
    + (j.status === 'rejected' && j.reject_reason ? '<div class="small err-text truncate" style="max-width:440px">' + esc(j.reject_reason) + '</div>' : '')
    + '</td>'
    + '<td class="small nowrap">' + esc(j.book_label) + '</td>'
    + '<td class="small nowrap">' + esc(j.created_by_name || '') + '</td>'
    + '<td class="num">' + money(j.total_cents) + '</td>'
    + '<td class="nowrap">' + statusBadge(j.status) + (j.reversed_by_id ? ' <span class="badge">Reversed</span>' : '') + '</td></tr>';

  let seq = 0;
  const load = async () => {
    const my = ++seq;
    sync();
    try {
      const d = await api.get('/journals', {
        status: state.status || null, book: state.book || null, from: state.from || null, to: state.to || null,
        q: state.q || null, mine: state.mine ? 1 : null, account: state.account || null, period: state.period || null,
        page: state.page, per_page: 25,
      }, { signal: ctx.signal });
      if (my !== seq) return;
      paintTabs(d.counts);
      paintScope(d);
      const rows = d.items || [];
      const filtered = state.q || state.book || state.from || state.to || state.mine || state.account || state.period;
      tbody.innerHTML = rows.length ? rows.map(row).join('')
        : '<tr><td colspan="7">' + (queue
          ? emptyState('check-square', 'Nothing is waiting for approval', 'Submitted entries appear here.')
          : emptyState('book-open', filtered || state.status ? 'No entries match' : 'No entries yet', filtered || state.status ? 'Try another filter.' : 'Entries appear here as they are prepared.')) + '</td></tr>';
      pager(qs('[data-pager]', root), d, (p) => { state.page = p; load(); window.scrollTo(0, 0); });
    } catch (err) {
      if (!ctx.signal.aborted && my === seq) tbody.innerHTML = '<tr><td colspan="7">' + emptyState('warning', 'Could not load the entries', err.message) + '</td></tr>';
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
  f.book.addEventListener('change', () => { state.book = f.book.value; state.page = 1; load(); });
  f.from.addEventListener('change', () => { state.from = f.from.value; state.page = 1; load(); });
  f.to.addEventListener('change', () => { state.to = f.to.value; state.page = 1; load(); });
  f.mine.addEventListener('change', () => { state.mine = f.mine.checked; state.page = 1; load(); });
  tbody.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-id]');
    if (tr) ctx.navigate('/journals/' + tr.dataset.id);
  });

  paintTabs(null);
  await load();
}
