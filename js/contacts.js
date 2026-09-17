/**
 * contacts.js — customers or suppliers: the list, and the form that adds or changes one
 *
 * GenericPOS Accounting · ES module · every role reads; bookkeepers and above add and change
 *
 *   /customers    the list, params.role = 'customer'
 *   /suppliers    the list, params.role = 'supplier'
 *
 * Balances come from the posted ledger (the receivables or payables control
 * account, split by customer or supplier); open and overdue from the posted
 * invoices or bills not yet paid. The filters live in the address bar.
 *
 * openContactForm() is also used by the contact page (js/contact.js).
 */

import { api } from './api.js';
import { money, toCents, toMajor } from './store.js';
import { qs, qsa, esc, emptyState, pager, debounce, openModal, busy, formError, clearErrors, toast } from './ui.js';
import { hasRole } from './router.js';

const TYPE_LABELS = { income: 'Income', expense: 'Expenses', asset: 'Assets' };

export async function mount(root, ctx) {
  const role = ctx.route.params && ctx.route.params.role === 'supplier' ? 'supplier' : 'customer';
  const cust = role === 'customer';
  const q = ctx.query;
  const state = {
    q: q.get('q') || '',
    active: ['1', '0', 'all'].includes(q.get('active')) ? q.get('active') : '1',
    sort: ['name', 'code', 'balance'].includes(q.get('sort')) ? q.get('sort') : 'name',
    page: Math.max(1, parseInt(q.get('page') || '1', 10) || 1),
  };

  const form = qs('[data-filters]', root);
  const f = form.elements;
  const tbody = qs('[data-rows]', root);
  const seg = qs('[data-active]', root);
  f.q.value = state.q;
  f.sort.value = state.sort;

  qs('[data-title]', root).textContent = cust ? 'Customers' : 'Suppliers';
  qs('[data-sub]', root).textContent = cust
    ? 'Everyone you sell to on account. The balance is what each one owes you today, straight from the ledger.'
    : 'Everyone you buy from on account. The balance is what you owe each one today, straight from the ledger.';
  qs('[data-foot-note]', root).textContent = (cust ? 'Open is what posted invoices still have unpaid; overdue is the part past its due date. '
    : 'Open is what posted bills still have unpaid; overdue is the part past its due date. ')
    + 'A balance can differ from open when money was received or paid but not yet applied to a document.';

  const actions = [];
  actions.push('<a class="btn btn-secondary" href="reports/aging?side=' + (cust ? 'ar' : 'ap') + '"><span data-icon="hourglass" data-icon-size="18"></span>Aging</a>');
  if (hasRole(ctx.user, 'bookkeeper')) {
    actions.push('<button class="btn" type="button" data-new><span data-icon="plus" data-icon-size="18"></span>New ' + role + '</button>');
  }
  qs('[data-actions]', root).innerHTML = actions.join('');

  const sync = () => {
    const p = new URLSearchParams();
    if (state.q) p.set('q', state.q);
    if (state.active !== '1') p.set('active', state.active);
    if (state.sort !== 'name') p.set('sort', state.sort);
    if (state.page > 1) p.set('page', String(state.page));
    const s = p.toString();
    history.replaceState(history.state, '', location.pathname + (s ? '?' + s : ''));
    qsa('button', seg).forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.v === state.active)));
  };

  const terms = (n) => (n === null || n === undefined ? '' : n === 0 ? 'Cash' : n + ' days');
  const row = (c) => '<tr class="is-link' + (c.is_active ? '' : ' is-inactive') + '" data-id="' + c.id + '">'
    + '<td class="code">' + esc(c.code) + '</td>'
    + '<td><div>' + esc(c.name) + (c.is_active ? '' : ' <span class="badge">Inactive</span>')
    + (c.is_customer && c.is_supplier ? ' <span class="badge badge-info">' + (cust ? 'Also a supplier' : 'Also a customer') + '</span>' : '') + '</div>'
    + (c.contact_person ? '<div class="small muted">' + esc(c.contact_person) + '</div>' : '') + '</td>'
    + '<td class="small nowrap">' + esc(c.tin || '') + '</td>'
    + '<td class="num small">' + esc(terms(c.terms_days)) + '</td>'
    + '<td class="num">' + (c.balance_cents ? money(c.balance_cents) : '<span class="faint">' + money(0) + '</span>') + '</td>'
    + '<td class="num">' + (c.open_cents ? money(c.open_cents) : '') + (c.open_count ? '<div class="xs faint">' + c.open_count + ' ' + (cust ? 'invoice' : 'bill') + (c.open_count === 1 ? '' : 's') + '</div>' : '') + '</td>'
    + '<td class="num">' + (c.overdue_cents ? '<span class="err-text">' + money(c.overdue_cents) + '</span>' : '') + '</td></tr>';

  let seq = 0;
  const load = async () => {
    const my = ++seq;
    sync();
    try {
      const d = await api.get('/contacts', { role, q: state.q || null, active: state.active, sort: state.sort, page: state.page, per_page: 25 }, { signal: ctx.signal });
      if (my !== seq) return;
      const rows = d.items || [];
      const filtered = state.q || state.active !== '1';
      tbody.innerHTML = rows.length ? rows.map(row).join('')
        : '<tr><td colspan="7">' + emptyState(cust ? 'users' : 'truck', filtered ? 'Nobody matches' : 'No ' + role + 's yet',
          filtered ? 'Try another search, or show inactive ones too.' : (cust ? 'Add the customers you invoice.' : 'Add the suppliers whose bills you record.')) + '</td></tr>';
      const t = d.totals || {};
      qs('[data-foot]', root).innerHTML = rows.length
        ? '<tr class="totals"><td colspan="4" class="right muted">Totals for ' + d.total + ' ' + role + (d.total === 1 ? '' : 's') + '</td><td class="num">' + money(t.balance_cents || 0)
          + '</td><td class="num">' + money(t.open_cents || 0) + '</td><td class="num">' + money(t.overdue_cents || 0) + '</td></tr>' : '';
      const stats = qs('[data-stats]', root);
      stats.innerHTML = [
        [cust ? 'Owed by customers' : 'Owed to suppliers', t.balance_cents || 0, 'From the ledger, today'],
        [cust ? 'Open invoices' : 'Open bills', t.open_cents || 0, 'Posted and not yet paid'],
        ['Overdue', t.overdue_cents || 0, 'Past the due date'],
      ].map(([label, v, sub]) => '<div class="stat"><div class="stat-label">' + esc(label) + '</div><div class="stat-value">' + money(v) + '</div><div class="stat-sub">' + esc(sub) + '</div></div>').join('');
      stats.hidden = false;
      pager(qs('[data-pager]', root), d, (p) => { state.page = p; load(); window.scrollTo(0, 0); });
    } catch (err) {
      if (!ctx.signal.aborted && my === seq) tbody.innerHTML = '<tr><td colspan="7">' + emptyState('warning', 'Could not load the list', err.message) + '</td></tr>';
    }
  };

  form.addEventListener('submit', (e) => e.preventDefault());
  f.q.addEventListener('input', debounce(() => { state.q = f.q.value.trim(); state.page = 1; load(); }, 300));
  f.sort.addEventListener('change', () => { state.sort = f.sort.value; state.page = 1; load(); });
  seg.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-v]');
    if (!b || b.dataset.v === state.active) return;
    state.active = b.dataset.v;
    state.page = 1;
    load();
  });
  tbody.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-id]');
    if (tr) ctx.navigate('/contacts/' + tr.dataset.id);
  });
  root.addEventListener('click', (e) => {
    if (!e.target.closest('[data-new]')) return;
    openContactForm({ role, onSaved: (c) => ctx.navigate('/contacts/' + c.id) });
  });

  await load();
}

/**
 * The form that adds a customer or supplier, or changes one.
 * @param {object} o  contact (to change), role ('customer'|'supplier' for a new one), onSaved(contact)
 */
export async function openContactForm(o = {}) {
  let lk;
  try { lk = await api.get('/contacts/lookups'); } catch (err) { toast(err.message, { kind: 'error' }); return; }
  const existing = o.contact || null;
  const role = existing ? (existing.is_customer ? 'customer' : 'supplier') : (o.role === 'supplier' ? 'supplier' : 'customer');
  const x = existing || {
    code: lk.next_code[role], name: '', is_customer: role === 'customer', is_supplier: role === 'supplier', tin: '', address: '',
    contact_person: '', email: '', phone: '', terms_days: null, credit_limit_cents: null, default_account_id: null, ewt_rate_bp: 0,
    vat_registered: true, notes: '',
  };
  const known = lk.ewt_rates.map((r) => r.bp);
  const other = !known.includes(x.ewt_rate_bp || 0);

  const field = (id, label, control, name, hint, span) => '<div class="field' + (span ? ' span-2' : '') + '" data-field="' + name + '"><label class="label" for="' + id + '">' + label + '</label>'
    + control + (hint ? '<div class="hint">' + hint + '</div>' : '') + '<div class="error" data-error-for="' + name + '"></div></div>';
  const opt = (v, label, sel) => '<option value="' + esc(v) + '"' + (sel ? ' selected' : '') + '>' + esc(label) + '</option>';

  const html = '<form class="stack" data-f novalidate><div class="alert alert-err" data-banner hidden></div>'
    + '<div class="row gap-4"><label class="check"><input type="checkbox" name="is_customer"' + (x.is_customer ? ' checked' : '') + '> <span>Customer — you invoice them</span></label>'
    + '<label class="check"><input type="checkbox" name="is_supplier"' + (x.is_supplier ? ' checked' : '') + '> <span>Supplier — they bill you</span></label></div>'
    + '<div class="error" data-error-for="is_customer"></div><div class="error" data-error-for="is_supplier"></div>'
    + '<div class="form-grid">'
    + field('cf-code', 'Code', '<input class="input input-mono" id="cf-code" name="code" maxlength="20" value="' + esc(x.code) + '" autocomplete="off">', 'code', existing ? '' : 'The next free code is suggested.')
    + field('cf-name', 'Name', '<input class="input" id="cf-name" name="name" maxlength="160" value="' + esc(x.name) + '" autocomplete="off">', 'name', 'As registered, the way it goes on invoices and cheques.')
    + field('cf-tin', 'TIN <span class="opt">(optional)</span>', '<input class="input input-mono" id="cf-tin" name="tin" maxlength="20" value="' + esc(x.tin || '') + '" placeholder="123-456-789-00000" autocomplete="off">', 'tin')
    + field('cf-person', 'Contact person <span class="opt">(optional)</span>', '<input class="input" id="cf-person" name="contact_person" maxlength="120" value="' + esc(x.contact_person || '') + '" autocomplete="off">', 'contact_person')
    + field('cf-email', 'Email <span class="opt">(optional)</span>', '<input class="input" id="cf-email" name="email" type="email" maxlength="190" value="' + esc(x.email || '') + '" autocomplete="off">', 'email')
    + field('cf-phone', 'Phone <span class="opt">(optional)</span>', '<input class="input" id="cf-phone" name="phone" maxlength="40" value="' + esc(x.phone || '') + '" autocomplete="off">', 'phone')
    + field('cf-address', 'Address <span class="opt">(optional)</span>', '<textarea class="textarea" id="cf-address" name="address" maxlength="500" style="min-height:64px">' + esc(x.address || '') + '</textarea>', 'address', '', true)
    + field('cf-terms', 'Terms (days)', '<input class="input" id="cf-terms" name="terms_days" inputmode="numeric" maxlength="3" value="' + (x.terms_days === null ? '' : esc(x.terms_days)) + '" autocomplete="off">', 'terms_days',
      'Days until an invoice or bill falls due; 0 for cash. Empty uses the default.')
    + field('cf-limit', 'Credit limit <span class="opt">(optional)</span>', '<input class="input" id="cf-limit" name="credit_limit" inputmode="decimal" value="' + (x.credit_limit_cents === null ? '' : esc(toMajor(x.credit_limit_cents))) + '" autocomplete="off">', 'credit_limit_cents',
      'You are warned before posting an invoice that goes over it. Empty means no limit.')
    + field('cf-acct', 'Default account <span class="opt">(optional)</span>', '<select class="select" id="cf-acct" name="default_account_id"></select>', 'default_account_id',
      'Suggested on new invoice or bill lines for them.')
    + field('cf-ewt', 'Expanded withholding tax', '<select class="select" id="cf-ewt" name="ewt_choice">'
      + lk.ewt_rates.map((r) => opt(r.bp, r.label, !other && r.bp === (x.ewt_rate_bp || 0))).join('') + opt('other', 'Another rate…', other) + '</select>'
      + '<div class="input-group mt-2" data-ewt-other' + (other ? '' : ' hidden') + '><input class="input" name="ewt_pct" inputmode="decimal" value="' + (other ? esc(String((x.ewt_rate_bp || 0) / 100)) : '') + '" aria-label="Withholding rate in percent"><span class="addon">%</span></div>',
      'ewt_rate_bp', 'Withheld from what you pay them, and suggested on each payment.')
    + '<div class="field span-2"><label class="check"><input type="checkbox" name="vat_registered"' + (x.vat_registered ? ' checked' : '') + '> <span>VAT-registered</span></label>'
    + '<div class="hint">A supplier who is not VAT-registered charges no input VAT: new bill lines start as VAT-exempt.</div></div>'
    + field('cf-notes', 'Notes <span class="opt">(optional)</span>', '<textarea class="textarea" id="cf-notes" name="notes" maxlength="500" style="min-height:64px">' + esc(x.notes || '') + '</textarea>', 'notes', '', true)
    + '</div>'
    + '<div class="row gap-2" style="justify-content:flex-end"><button class="btn btn-secondary" type="button" data-x>Cancel</button>'
    + '<button class="btn" type="submit">' + (existing ? 'Save' : 'Add ' + role) + '</button></div></form>';

  const m = openModal({ title: existing ? x.code + ' · ' + x.name : 'New ' + role, size: 'lg', body: html });
  const f = qs('[data-f]', m.el);
  const el = f.elements;

  const paint = () => {
    const c = el.is_customer.checked;
    const s = el.is_supplier.checked;
    const types = c && s ? ['income', 'expense', 'asset'] : c ? ['income'] : ['expense', 'asset'];
    const cur = el.default_account_id.value || (x.default_account_id ? String(x.default_account_id) : '');
    el.default_account_id.innerHTML = opt('', 'None — use the company default', !cur) + types.map((t) => {
      const list = lk.accounts.filter((a) => a.type === t && (a.active || String(a.id) === cur));
      return list.length ? '<optgroup label="' + esc(TYPE_LABELS[t]) + '">' + list.map((a) => opt(a.id, a.code + ' · ' + a.name, String(a.id) === cur)).join('') + '</optgroup>' : '';
    }).join('');
    if (el.default_account_id.value !== cur) el.default_account_id.value = '';
    qs('[data-field="credit_limit_cents"]', f).hidden = !c;
    qs('[data-field="ewt_rate_bp"]', f).hidden = !s;
    el.terms_days.placeholder = String(c ? lk.terms.customer : lk.terms.supplier);
  };
  paint();
  el.is_customer.addEventListener('change', paint);
  el.is_supplier.addEventListener('change', paint);
  el.ewt_choice.addEventListener('change', () => { qs('[data-ewt-other]', f).hidden = el.ewt_choice.value !== 'other'; });
  qs('[data-x]', f).addEventListener('click', () => m.close());

  f.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearErrors(f);
    const errors = {};
    let limit = null;
    if (el.is_customer.checked && el.credit_limit.value.trim() !== '') {
      limit = toCents(el.credit_limit.value);
      if (limit === null) errors.credit_limit_cents = 'Enter the credit limit as an amount, for example 50,000.00.';
    }
    let ewt = 0;
    if (el.is_supplier.checked) {
      if (el.ewt_choice.value === 'other') {
        const v = el.ewt_pct.value.trim();
        const bp = /^\d{1,2}(\.\d{1,2})?$/.test(v) ? Math.round(parseFloat(v) * 100) : -1;
        if (bp < 0 || bp > 3200) errors.ewt_rate_bp = 'Enter a rate from 0 to 32 %, with up to two decimals.';
        else ewt = bp;
      } else {
        ewt = parseInt(el.ewt_choice.value, 10) || 0;
      }
    }
    if (Object.keys(errors).length) { formError(f, { errors }); return; }

    const body = {
      code: el.code.value.trim(), name: el.name.value.trim(), is_customer: el.is_customer.checked, is_supplier: el.is_supplier.checked,
      tin: el.tin.value.trim(), contact_person: el.contact_person.value.trim(), email: el.email.value.trim(), phone: el.phone.value.trim(),
      address: el.address.value.trim(), terms_days: el.terms_days.value.trim(), credit_limit_cents: limit,
      default_account_id: el.default_account_id.value ? Number(el.default_account_id.value) : null, ewt_rate_bp: ewt,
      vat_registered: el.vat_registered.checked, notes: el.notes.value.trim(),
    };
    const b = qs('button[type="submit"]', f);
    busy(b, true);
    try {
      const r = existing ? await api.put('/contacts/' + existing.id, body) : await api.post('/contacts', body);
      m.close();
      toast(existing ? body.name + ' saved.' : (body.is_customer ? 'Customer ' : 'Supplier ') + body.name + ' added.');
      if (o.onSaved) o.onSaved(r.contact);
    } catch (err) { formError(f, err); } finally { busy(b, false); }
  });
}
