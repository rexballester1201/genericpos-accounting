/**
 * documents.js — invoices and credit notes, or bills and debit notes: the list
 *
 * GenericPOS Accounting · ES module · every role
 *
 *   /invoices   params.side = 'sales'      invoices and credit notes
 *   /bills      params.side = 'purchases'  bills and debit notes
 *
 * Filters — status, kind, paid or not, customer or supplier, dates and a
 * search — live in the address bar, so a filtered list survives a reload and
 * other screens link to one (/invoices?state=overdue&contact_id=3). The totals
 * row counts notes against the documents they reduce.
 */

import { api } from './api.js';
import { money, fmtDay } from './store.js';
import { qs, esc, emptyState, statusBadge, pager, debounce } from './ui.js';
import { hasRole } from './router.js';

const TABS = [['', 'All', 'all'], ['draft', 'Drafts', 'draft'], ['posted', 'Posted', 'posted'], ['cancelled', 'Cancelled', 'cancelled']];
const YMD = /^\d{4}-\d{2}-\d{2}$/;

/** The badge for where a document stands. */
function badge(d) {
  switch (d.state) {
    case 'overdue':   return statusBadge('overdue', d.days_overdue + ' day' + (d.days_overdue === 1 ? '' : 's') + ' overdue');
    case 'due':       return statusBadge('due', 'Due today');
    case 'not_due':   return statusBadge('not_due');
    case 'partial':   return statusBadge('partial');
    case 'paid':      return statusBadge('paid');
    case 'unapplied': return statusBadge('partial', 'Unapplied');
    case 'applied':   return statusBadge('applied');
    default:          return statusBadge(d.state);
  }
}

export async function mount(root, ctx) {
  const sales = !(ctx.route.params && ctx.route.params.side === 'purchases');
  const side = sales ? 'sales' : 'purchases';
  const main = sales ? 'invoice' : 'bill';
  const note = sales ? 'credit_note' : 'debit_note';
  const who = sales ? 'customer' : 'supplier';
  const q = ctx.query;
  const state = {
    status: ['draft', 'posted', 'cancelled'].includes(q.get('status')) ? q.get('status') : '',
    type: [main, note].includes(q.get('type')) ? q.get('type') : '',
    state: ['open', 'overdue', 'paid'].includes(q.get('state')) ? q.get('state') : '',
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

  qs('[data-title]', root).textContent = sales ? 'Invoices' : 'Bills';
  ctx.setTitle(sales ? 'Invoices' : 'Bills');
  qs('[data-sub]', root).textContent = sales
    ? 'Customer invoices and credit notes, newest first. A posted invoice is in the sales journal and on the customer\'s account.'
    : 'Supplier bills and debit notes, newest first. A posted bill is in the purchase journal and on the supplier\'s account.';
  qs('[data-col-contact]', root).textContent = sales ? 'Customer' : 'Supplier';
  f.type.innerHTML = '<option value="">' + (sales ? 'Invoices and credit notes' : 'Bills and debit notes') + '</option>'
    + '<option value="' + main + '">' + (sales ? 'Invoices' : 'Bills') + ' only</option><option value="' + note + '">' + (sales ? 'Credit notes' : 'Debit notes') + ' only</option>';
  f.contact_id.innerHTML = '<option value="">Every ' + who + '</option>';
  f.q.value = state.q;
  f.type.value = state.type;
  f.state.value = state.state;
  f.from.value = state.from;
  f.to.value = state.to;

  if (hasRole(ctx.user, 'bookkeeper')) {
    qs('[data-actions]', root).innerHTML = '<a class="btn btn-secondary" href="documents/new?type=' + note + '"><span data-icon="plus" data-icon-size="18"></span>New '
      + (sales ? 'credit' : 'debit') + ' note</a><a class="btn" href="documents/new?type=' + main + '"><span data-icon="plus" data-icon-size="18"></span>New ' + main + '</a>';
  }

  /* The customers or suppliers for the filter; the list works without them. */
  api.get('/documents/lookups', { side }, { signal: ctx.signal }).then((lk) => {
    const cur = state.contact_id;
    f.contact_id.innerHTML = '<option value="">Every ' + who + '</option>' + lk.contacts.map((c) => '<option value="' + c.id + '">' + esc(c.name) + '</option>').join('');
    if (cur && !lk.contacts.some((c) => String(c.id) === cur)) f.contact_id.insertAdjacentHTML('beforeend', '<option value="' + esc(cur) + '">#' + esc(cur) + '</option>');
    f.contact_id.value = cur;
  }).catch(() => { /* the filter stays at "every" */ });

  const sync = () => {
    const p = new URLSearchParams();
    ['status', 'type', 'state', 'contact_id', 'from', 'to', 'q'].forEach((k) => { if (state[k]) p.set(k, state[k]); });
    if (state.page > 1) p.set('page', String(state.page));
    const s = p.toString();
    history.replaceState(history.state, '', location.pathname + (s ? '?' + s : ''));
  };

  const paintTabs = (counts) => {
    tabs.innerHTML = TABS.map(([value, label, key]) => '<button class="tab" type="button" role="tab" data-status="' + value + '" aria-selected="'
      + String(state.status === value) + '">' + esc(label) + ' <span class="faint num">' + (counts ? counts[key] || 0 : '') + '</span></button>').join('');
  };

  const row = (d) => '<tr class="is-link" data-id="' + d.id + '">'
    + '<td class="code">' + (d.doc_no ? esc(d.doc_no) : '<span class="faint">Draft #' + d.id + '</span>')
    + (d.is_note ? '<div class="xs muted">' + esc(d.type_label) + '</div>' : '') + '</td>'
    + '<td class="nowrap">' + esc(fmtDay(d.doc_date)) + '</td>'
    + '<td style="min-width:200px"><div class="truncate" style="max-width:340px">' + esc(d.contact_name) + '</div>'
    + ((d.reference || d.description) ? '<div class="small muted truncate" style="max-width:340px">' + esc([d.reference, d.description].filter(Boolean).join(' · ')) + '</div>' : '') + '</td>'
    + '<td class="nowrap small">' + (d.due_date ? esc(fmtDay(d.due_date)) : '') + '</td>'
    + '<td class="nowrap">' + badge(d) + '</td>'
    + '<td class="num">' + (d.is_note ? '−' : '') + money(d.total_cents) + '</td>'
    + '<td class="num">' + (d.status === 'posted' && d.open_cents ? (d.is_note ? '−' : '') + money(d.open_cents) : '') + '</td></tr>';

  let seq = 0;
  const load = async () => {
    const my = ++seq;
    sync();
    try {
      const d = await api.get('/documents', {
        side, status: state.status || null, type: state.type || null, state: state.state || null, contact_id: state.contact_id || null,
        from: state.from || null, to: state.to || null, q: state.q || null, page: state.page, per_page: 25,
      }, { signal: ctx.signal });
      if (my !== seq) return;
      paintTabs(d.counts);
      const rows = d.items || [];
      const filtered = state.q || state.type || state.state || state.contact_id || state.from || state.to || state.status;
      tbody.innerHTML = rows.length ? rows.map(row).join('')
        : '<tr><td colspan="7">' + emptyState(sales ? 'receipt' : 'article', filtered ? 'Nothing matches' : 'No ' + main + 's yet',
          filtered ? 'Try another filter.' : (sales ? 'Invoices appear here as they are prepared.' : 'Bills appear here as they are recorded.')) + '</td></tr>';
      qs('[data-foot]', root).innerHTML = rows.length
        ? '<tr class="totals"><td colspan="5" class="right muted">Totals of the ' + d.total + ' listed' + (state.type === main ? '' : ', notes taken off') + '</td>'
          + '<td class="num">' + money(d.totals.total_cents) + '</td><td class="num">' + money(d.totals.open_cents) + '</td></tr>' : '';
      pager(qs('[data-pager]', root), d, (p) => { state.page = p; load(); window.scrollTo(0, 0); });
    } catch (err) {
      if (!ctx.signal.aborted && my === seq) tbody.innerHTML = '<tr><td colspan="7">' + emptyState('warning', 'Could not load the list', err.message) + '</td></tr>';
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
  ['type', 'state', 'contact_id'].forEach((k) => f[k].addEventListener('change', () => { state[k] = f[k].value; state.page = 1; load(); }));
  ['from', 'to'].forEach((k) => f[k].addEventListener('change', () => {
    if (f[k].value && !YMD.test(f[k].value)) return;
    state[k] = f[k].value;
    state.page = 1;
    load();
  }));
  tbody.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-id]');
    if (tr) ctx.navigate('/documents/' + tr.dataset.id);
  });

  paintTabs(null);
  await load();
}
