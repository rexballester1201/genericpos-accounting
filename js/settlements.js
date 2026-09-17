/**
 * settlements.js — receipts from customers, or payments to suppliers: the list
 *
 * GenericPOS Accounting · ES module · every role
 *
 *   /receipts   params.kind = 'receipt'
 *   /payments   params.kind = 'payment'
 *
 * The amount is the cash that moved; withheld is the tax withheld on top of it
 * (CWT on a receipt, EWT on a payment); unapplied is what has not yet been
 * applied to an invoice or bill. The filters live in the address bar.
 */

import { api } from './api.js';
import { money, fmtDay } from './store.js';
import { qs, esc, emptyState, statusBadge, pager, debounce } from './ui.js';
import { hasRole } from './router.js';

const TABS = [['', 'All', 'all'], ['draft', 'Drafts', 'draft'], ['posted', 'Posted', 'posted'], ['cancelled', 'Cancelled', 'cancelled']];
const YMD = /^\d{4}-\d{2}-\d{2}$/;

export async function mount(root, ctx) {
  const rcpt = !(ctx.route.params && ctx.route.params.kind === 'payment');
  const kind = rcpt ? 'receipt' : 'payment';
  const q = ctx.query;
  const state = {
    status: ['draft', 'posted', 'cancelled'].includes(q.get('status')) ? q.get('status') : '',
    state: q.get('state') === 'unapplied' ? 'unapplied' : '',
    contact_id: q.get('contact_id') || '',
    from: YMD.test(q.get('from') || '') ? q.get('from') : '',
    to: YMD.test(q.get('to') || '') ? q.get('to') : '',
    q: q.get('q') || '',
    page: Math.max(1, parseInt(q.get('page') || '1', 10) || 1),
  };

  const form = qs('[data-filters]', root);
  const f = form.elements;
  const tbody = qs('[data-rows]', root);
  const tabs = qs('[data-tabs]', root);

  qs('[data-title]', root).textContent = rcpt ? 'Receipts' : 'Payments';
  ctx.setTitle(rcpt ? 'Receipts' : 'Payments');
  qs('[data-sub]', root).textContent = rcpt
    ? 'Money received from customers, newest first: the cash receipts book, and what each receipt paid.'
    : 'Money paid to suppliers, newest first: the cash disbursements book, and what each payment settled.';
  qs('[data-col-contact]', root).textContent = rcpt ? 'Customer' : 'Supplier';
  qs('[data-col-ref]', root).textContent = rcpt ? 'OR number' : 'Cheque number';
  f.contact_id.innerHTML = '<option value="">Every ' + (rcpt ? 'customer' : 'supplier') + '</option>';
  f.q.value = state.q;
  f.state.value = state.state;
  f.from.value = state.from;
  f.to.value = state.to;

  if (hasRole(ctx.user, 'bookkeeper')) {
    qs('[data-actions]', root).innerHTML = '<a class="btn" href="settlements/new?kind=' + kind + '"><span data-icon="plus" data-icon-size="18"></span>Record ' + kind + '</a>';
  }

  api.get('/settlements/lookups', { kind }, { signal: ctx.signal }).then((lk) => {
    const cur = state.contact_id;
    f.contact_id.innerHTML = '<option value="">Every ' + (rcpt ? 'customer' : 'supplier') + '</option>' + lk.contacts.map((c) => '<option value="' + c.id + '">' + esc(c.name) + '</option>').join('');
    if (cur && !lk.contacts.some((c) => String(c.id) === cur)) f.contact_id.insertAdjacentHTML('beforeend', '<option value="' + esc(cur) + '">#' + esc(cur) + '</option>');
    f.contact_id.value = cur;
  }).catch(() => { /* the filter stays at "every" */ });

  const sync = () => {
    const p = new URLSearchParams();
    ['status', 'state', 'contact_id', 'from', 'to', 'q'].forEach((k) => { if (state[k]) p.set(k, state[k]); });
    if (state.page > 1) p.set('page', String(state.page));
    const s = p.toString();
    history.replaceState(history.state, '', location.pathname + (s ? '?' + s : ''));
  };

  const paintTabs = (counts) => {
    tabs.innerHTML = TABS.map(([value, label, key]) => '<button class="tab" type="button" role="tab" data-status="' + value + '" aria-selected="'
      + String(state.status === value) + '">' + esc(label) + ' <span class="faint num">' + (counts ? counts[key] || 0 : '') + '</span></button>').join('');
  };

  const row = (s) => '<tr class="is-link" data-id="' + s.id + '">'
    + '<td class="code">' + (s.settle_no ? esc(s.settle_no) : '<span class="faint">Draft #' + s.id + '</span>') + '</td>'
    + '<td class="nowrap">' + esc(fmtDay(s.settle_date)) + '</td>'
    + '<td style="min-width:180px"><div class="truncate" style="max-width:300px">' + esc(s.contact_name) + '</div>'
    + (s.description ? '<div class="small muted truncate" style="max-width:300px">' + esc(s.description) + '</div>' : '') + '</td>'
    + '<td class="small nowrap">' + esc(s.reference || '') + '</td>'
    + '<td class="small nowrap"><span class="code">' + esc(s.cash_code) + '</span> ' + esc(s.cash_name) + '</td>'
    + '<td class="num">' + money(s.amount_cents) + '</td>'
    + '<td class="num">' + (s.withholding_cents ? money(s.withholding_cents) : '') + '</td>'
    + '<td class="num">' + (s.unapplied_cents ? '<span class="warn-text">' + money(s.unapplied_cents) + '</span>' : '') + '</td>'
    + '<td class="nowrap">' + statusBadge(s.status) + '</td></tr>';

  let seq = 0;
  const load = async () => {
    const my = ++seq;
    sync();
    try {
      const d = await api.get('/settlements', {
        kind, status: state.status || null, state: state.state || null, contact_id: state.contact_id || null,
        from: state.from || null, to: state.to || null, q: state.q || null, page: state.page, per_page: 25,
      }, { signal: ctx.signal });
      if (my !== seq) return;
      paintTabs(d.counts);
      const rows = d.items || [];
      const filtered = state.q || state.state || state.contact_id || state.from || state.to || state.status;
      tbody.innerHTML = rows.length ? rows.map(row).join('')
        : '<tr><td colspan="9">' + emptyState(rcpt ? 'coins' : 'money', filtered ? 'Nothing matches' : 'No ' + kind + 's yet',
          filtered ? 'Try another filter.' : (rcpt ? 'Receipts appear here as customers pay.' : 'Payments appear here as suppliers are paid.')) + '</td></tr>';
      qs('[data-foot]', root).innerHTML = rows.length
        ? '<tr class="totals"><td colspan="5" class="right muted">Totals of the ' + d.total + ' listed, cancelled ones left out</td><td class="num">' + money(d.totals.amount_cents)
          + '</td><td class="num">' + money(d.totals.withholding_cents) + '</td><td class="num">' + money(d.totals.unapplied_cents) + '</td><td></td></tr>' : '';
      pager(qs('[data-pager]', root), d, (p) => { state.page = p; load(); window.scrollTo(0, 0); });
    } catch (err) {
      if (!ctx.signal.aborted && my === seq) tbody.innerHTML = '<tr><td colspan="9">' + emptyState('warning', 'Could not load the list', err.message) + '</td></tr>';
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
  ['state', 'contact_id'].forEach((k) => f[k].addEventListener('change', () => { state[k] = f[k].value; state.page = 1; load(); }));
  ['from', 'to'].forEach((k) => f[k].addEventListener('change', () => {
    if (f[k].value && !YMD.test(f[k].value)) return;
    state[k] = f[k].value;
    state.page = 1;
    load();
  }));
  tbody.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-id]');
    if (tr) ctx.navigate('/settlements/' + tr.dataset.id);
  });

  paintTabs(null);
  await load();
}
