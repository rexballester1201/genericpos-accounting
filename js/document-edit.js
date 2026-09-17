/**
 * document-edit.js — prepare or change an invoice, credit note, bill or debit note
 *
 * GenericPOS Accounting · ES module · bookkeepers and above
 *
 *   /documents/new?type=invoice|credit_note|bill|debit_note&contact_id=&related_id=
 *   /documents/{id}/edit        a draft — its preparer only
 *
 * Each line is an account, a description, a quantity and a unit price (or a
 * typed amount) and how VAT applies to it. The totals are worked out live the
 * way the server works them out — per line, in whole centavos, rounded half
 * away from zero — but the server does it again and its figures are the ones
 * that count. A credit or debit note may name the posted invoice or bill it
 * corrects; posting it then applies it there.
 */

import { api } from './api.js';
import { money, toCents, toMajor, fmtDay } from './store.js';
import { qs, qsa, esc, emptyState, busy, toast, formError, clearErrors, setErrors } from './ui.js';
import { hasRole } from './router.js';

const TITLE = { invoice: 'Invoice', credit_note: 'Credit note', bill: 'Bill', debit_note: 'Debit note' };
const SIDE = { invoice: 'sales', credit_note: 'sales', bill: 'purchases', debit_note: 'purchases' };
const TYPE_LABELS = { income: 'Income', expense: 'Expenses', asset: 'Assets', liability: 'Liabilities', equity: 'Equity' };
const VAT_LABELS = { vatable: 'VATable', exempt: 'VAT-exempt', zero_rated: 'Zero-rated' };

// ─── The server's arithmetic, exactly (BigInt: no float ever touches money) ──

function roundDiv(num, den) {
  if (den === 0n) return 0n;
  const neg = (num < 0n) !== (den < 0n);
  const n = num < 0n ? -num : num;
  const d = den < 0n ? -den : den;
  const q = (2n * n + d) / (2n * d);
  return neg ? -q : q;
}

/** A quantity × 10000 as a BigInt; blank is 1; null when it is not a quantity above zero with up to 4 decimals. */
function qtyScaled(raw) {
  const s = String(raw == null ? '' : raw).trim().replace(/[,\s]/g, '');
  if (s === '') return 10000n;
  const m = s.match(/^(\d{1,14})(?:\.(\d{1,4}))?$/);
  if (!m) return null;
  const v = BigInt(m[1] + (m[2] || '').padEnd(4, '0'));
  return v > 0n ? v : null;
}

function vatSplit(amount, mode, inclusive, rateBp, registered) {
  if (!registered || mode !== 'vatable' || rateBp <= 0) return [amount, 0];
  const a = BigInt(amount);
  const r = BigInt(rateBp);
  if (inclusive) {
    const vat = roundDiv(a * r, 10000n + r);
    return [Number(a - vat), Number(vat)];
  }
  return [amount, Number(roundDiv(a * r, 10000n))];
}

const addDays = (ymd, n) => { const t = new Date(ymd + 'T00:00:00Z'); t.setUTCDate(t.getUTCDate() + n); return t.toISOString().slice(0, 10); };
const YMD = /^\d{4}-\d{2}-\d{2}$/;

export async function mount(root, ctx) {
  const id = ctx.params.id ? parseInt(ctx.params.id, 10) || 0 : 0;
  const stateEl = qs('[data-state]', root);
  const form = qs('[data-form]', root);
  const tbody = qs('[data-lines]', root);
  const f = form.elements;

  let type = TITLE[ctx.query.get('type')] ? ctx.query.get('type') : 'invoice';
  let doc = null;
  let lk = null;
  let rel = null;
  try {
    if (id) {
      doc = await api.get('/documents/' + id, null, { signal: ctx.signal });
      type = doc.document.doc_type;
    }
    const relId = id ? 0 : parseInt(ctx.query.get('related_id') || '0', 10) || 0;
    const tasks = [api.get('/documents/lookups', { side: SIDE[type] }, { signal: ctx.signal })];
    if (relId) tasks.push(api.get('/documents/' + relId, null, { signal: ctx.signal }));
    [lk, rel] = await Promise.all(tasks);
  } catch (err) {
    if (ctx.signal.aborted) return;
    stateEl.innerHTML = emptyState('warning', 'Could not open the form', err.message, '<a class="btn btn-secondary" href="invoices">Back to the invoices</a>');
    return;
  }

  const sales = SIDE[type] === 'sales';
  const note = type === 'credit_note' || type === 'debit_note';
  const word = TITLE[type].toLowerCase();
  const who = sales ? 'customer' : 'supplier';
  const target = sales ? 'invoice' : 'bill';
  const listHref = sales ? 'invoices' : 'bills';

  if (id && !doc.can.edit) {
    stateEl.innerHTML = emptyState('lock-key', 'This ' + word + ' cannot be changed',
      doc.document.status === 'draft' ? 'Only the person who prepared a draft can change it.' : 'It has posted. Cancel it and prepare a new one to correct it.',
      '<a class="btn btn-secondary" href="documents/' + id + '">Back to the ' + word + '</a>');
    return;
  }

  // ── the header ────────────────────────────────────────────────────────
  const src = doc ? doc.document : null;
  const title = id ? 'Edit draft ' + word + ' #' + id : 'New ' + word;
  qs('[data-title]', root).textContent = title;
  qs('[data-crumb]', root).textContent = title;
  qs('[data-crumb-list]', root).textContent = sales ? 'Invoices' : 'Bills';
  qs('[data-crumb-list]', root).setAttribute('href', listHref);
  qs('a[data-cancel]', root).setAttribute('href', id ? 'documents/' + id : listHref);
  ctx.setTitle(title);
  qs('[data-sub]', root).textContent = {
    invoice: 'What the customer owes you. It reaches the ledger when an accountant posts it: the customer\'s account, the sales accounts and output VAT.',
    credit_note: 'Reduces what the customer owes: goods returned, a price adjustment or a rebate. Name the invoice it corrects to apply it there when it posts.',
    bill: 'What you owe a supplier, from their invoice. It reaches the ledger when an accountant posts it: the expense or asset accounts, input VAT and the supplier\'s account.',
    debit_note: 'Reduces what you owe a supplier: goods sent back or a price adjustment. Name the bill it corrects to apply it there when it posts.',
  }[type];
  qs('[data-contact-label]', root).textContent = sales ? 'Customer' : 'Supplier';
  qs('[data-date-label]', root).textContent = TITLE[type] + ' date';
  qs('[data-ref-label]', root).innerHTML = type === 'bill' ? 'Supplier\'s invoice number' : esc(type === 'invoice' ? 'PO number or reference' : 'Reference') + ' <span class="opt">(optional)</span>';
  f.reference.placeholder = type === 'bill' ? 'SI 12345' : type === 'invoice' ? 'PO 5501' : '';
  qs('[data-due-field]', root).hidden = note;
  qs('[data-related-field]', root).hidden = !note;
  qs('[data-related-label]', root).textContent = 'Corrects ' + target + ' (optional)';

  const reg = !!lk.tax.registered;
  const rateBp = lk.tax.rate_bp || 0;
  qs('[data-inclusive-field]', root).hidden = !reg;
  qs('[data-inclusive-hint]', root).textContent = 'Ticked, a line\'s amount already has ' + lk.tax.rate_pct + ' % VAT in it and the VAT is taken out of it; untick it when you type amounts before VAT and the VAT is added on top.';
  if (!reg) {
    qs('[data-col-vat]', root).hidden = true;
    const n = qs('[data-note]', root);
    n.textContent = 'The company is not VAT-registered, so no VAT is charged or claimed: every line is VAT-exempt.';
    n.className = 'alert alert-neutral';
    n.hidden = false;
  }
  const depts = lk.departments || [];
  if (!depts.length) qs('[data-col-dept]', root).hidden = true;

  const periods = lk.open_periods || [];
  qs('[data-date-hint]', root).textContent = periods.length
    ? 'Open for entries: ' + (periods.length === 1 ? periods[0].name : periods[0].name + ' to ' + periods[periods.length - 1].name) + '.'
    : 'No month is open for entries. An accountant can reopen one.';

  // ── customers or suppliers ────────────────────────────────────────────
  const contacts = lk.contacts;
  const cById = new Map(contacts.map((c) => [c.id, c]));
  let contactId = src ? src.contact_id : parseInt(ctx.query.get('contact_id') || (rel ? String(rel.document.contact_id) : '0'), 10) || 0;
  f.contact_id.innerHTML = '<option value="">Choose the ' + who + '</option>' + contacts.map((c) => '<option value="' + c.id + '">' + esc(c.name + ' (' + c.code + ')') + '</option>').join('')
    + (contactId && !cById.has(contactId) && doc ? '<option value="' + contactId + '" disabled>' + esc(doc.contact.name + ' (inactive)') + '</option>' : '');
  f.contact_id.value = contactId ? String(contactId) : '';

  // ── accounts ──────────────────────────────────────────────────────────
  const accounts = lk.accounts;
  const aById = new Map(accounts.map((a) => [a.id, a]));
  const order = sales ? ['income', 'liability', 'equity', 'asset', 'expense'] : ['expense', 'asset', 'liability', 'equity', 'income'];
  const groups = {};
  accounts.filter((a) => a.active).forEach((a) => { (groups[a.type] = groups[a.type] || []).push(a); });
  const ACCOUNT_OPTS = '<option value="">Choose an account</option>' + order.filter((t) => groups[t]).map((t) => '<optgroup label="' + TYPE_LABELS[t] + '">'
    + groups[t].map((a) => '<option value="' + a.id + '">' + esc(a.code + ' · ' + a.name) + '</option>').join('') + '</optgroup>').join('');

  /** The account a new line starts on: the contact's default when it fits this side, else the company's. */
  const defaultAccount = () => {
    const c = cById.get(contactId);
    const a = c && c.default_account_id ? aById.get(c.default_account_id) : null;
    const fits = a && a.active && (sales ? a.type === 'income' : ['expense', 'asset'].includes(a.type));
    return fits ? a.id : (lk.defaults.account_id || 0);
  };
  const defaultVat = () => {
    if (!reg) return 'exempt';
    const c = cById.get(contactId);
    return !sales && c && !c.vat_registered ? 'exempt' : 'vatable';
  };
  const blank = () => ({ account_id: defaultAccount(), description: '', qty: '1', price: '', amount: '', vat_mode: defaultVat(), department_id: 0 });

  // ── the header's values ───────────────────────────────────────────────
  let dueTouched = false;
  if (src) {
    f.doc_date.value = src.doc_date;
    f.due_date.value = src.due_date || '';
    f.reference.value = src.reference || '';
    f.description.value = src.description || '';
    f.prices_include_tax.checked = !!src.prices_include_tax;
    dueTouched = true;
  } else {
    f.doc_date.value = lk.today;
    f.prices_include_tax.checked = !!lk.tax.inclusive;
    if (rel) f.description.value = (sales ? 'Adjustment to ' : 'Adjustment to ') + rel.document.doc_no;
  }

  const terms = () => {
    const c = cById.get(contactId);
    return c && c.terms_days !== null && c.terms_days !== undefined ? c.terms_days : lk.defaults.terms_days;
  };
  const paintDue = () => {
    if (note) return;
    const t = terms();
    if (!dueTouched && YMD.test(f.doc_date.value)) f.due_date.value = addDays(f.doc_date.value, t);
    qs('[data-due-hint]', root).textContent = contactId ? (t === 0 ? 'Cash terms: due on the day.' : t + '-day terms.') + (dueTouched ? '' : ' Worked out from the date.') : '';
  };
  const paintContactHint = () => {
    const c = cById.get(contactId);
    const bits = [];
    if (c) {
      if (c.tin) bits.push('TIN ' + c.tin);
      if (sales && c.credit_limit_cents !== null) bits.push('credit limit ' + money(c.credit_limit_cents));
      if (!sales && !c.vat_registered) bits.push('not VAT-registered: no input VAT');
      if (!sales && c.ewt_rate_bp) bits.push((c.ewt_rate_bp / 100) + ' % EWT is withheld when you pay');
    }
    qs('[data-contact-hint]', root).textContent = bits.join(' · ');
  };

  // ── the invoice or bill a note corrects ───────────────────────────────
  const relSel = f.related_document_id;
  let relWanted = src ? (src.related_document_id || 0) : (rel ? rel.document.id : 0);
  const loadRelated = async () => {
    if (!note) return;
    if (!contactId) { relSel.innerHTML = '<option value="">Choose the ' + who + ' first</option>'; return; }
    relSel.innerHTML = '<option value="">Loading…</option>';
    try {
      const r = await api.get('/documents', { side: SIDE[type], type: target, status: 'posted', contact_id: contactId, per_page: 100 }, { signal: ctx.signal });
      const items = r.items || [];
      relSel.innerHTML = '<option value="">None — it stays unapplied until you apply it</option>' + items.map((d) => '<option value="' + d.id + '">'
        + esc(d.doc_no + ' · ' + fmtDay(d.doc_date) + ' · ' + money(d.total_cents) + (d.open_cents ? ' (open ' + money(d.open_cents) + ')' : ' (paid)')) + '</option>').join('');
      if (relWanted && !items.some((d) => d.id === relWanted) && doc && doc.related) {
        relSel.insertAdjacentHTML('beforeend', '<option value="' + relWanted + '">' + esc(doc.related.doc_no) + '</option>');
      }
      relSel.value = relWanted ? String(relWanted) : '';
    } catch { relSel.innerHTML = '<option value="">Could not load the ' + target + 's</option>'; }
    qs('[data-related-hint]', root).textContent = 'Posting the ' + word + ' applies it to that ' + target + ', up to what is still open on it.';
  };

  // ── the lines ─────────────────────────────────────────────────────────
  let lines;
  if (src) {
    lines = doc.lines.map((l) => ({ account_id: l.account_id, description: l.description || '', qty: String(parseFloat(l.quantity)), price: l.unit_price_cents ? toMajor(l.unit_price_cents) : '',
      amount: toMajor(l.amount_cents), vat_mode: l.vat_mode, department_id: l.department_id || 0 }));
  } else if (rel && rel.lines) {
    lines = rel.lines.map((l) => ({ account_id: l.account_id, description: l.description || '', qty: String(parseFloat(l.quantity)), price: l.unit_price_cents ? toMajor(l.unit_price_cents) : '',
      amount: toMajor(l.amount_cents), vat_mode: l.vat_mode, department_id: l.department_id || 0 }));
    f.prices_include_tax.checked = !!rel.document.prices_include_tax;
  } else {
    lines = [blank()];
  }
  if (!lines.length) lines.push(blank());

  const deptOptions = (a) => '<option value="">' + (a && a.needs_department ? 'Choose a department' : '—') + '</option>'
    + depts.map((x) => '<option value="' + x.id + '">' + esc(x.code + ' · ' + x.name) + '</option>').join('');
  const vatOptions = () => Object.keys(VAT_LABELS).map((k) => '<option value="' + k + '">' + VAT_LABELS[k] + '</option>').join('');

  const rowHtml = (l, i) => {
    const a = aById.get(l.account_id);
    const extra = a && !a.active ? '<option value="' + a.id + '" disabled>' + esc(a.code + ' · ' + a.name + ' (inactive)') + '</option>' : '';
    return '<tr data-line="' + i + '">'
      + '<td><select class="select" data-f="account_id" aria-label="Account, line ' + (i + 1) + '">' + extra + ACCOUNT_OPTS + '</select></td>'
      + '<td><input class="input" data-f="description" maxlength="255" value="' + esc(l.description) + '" aria-label="Description, line ' + (i + 1) + '" autocomplete="off"></td>'
      + '<td class="amt"><input class="input" data-f="qty" inputmode="decimal" value="' + esc(l.qty) + '" aria-label="Quantity, line ' + (i + 1) + '" autocomplete="off"></td>'
      + '<td class="amt"><input class="input" data-f="price" inputmode="decimal" value="' + esc(l.price) + '" aria-label="Unit price, line ' + (i + 1) + '" autocomplete="off"></td>'
      + '<td class="amt"><input class="input" data-f="amount" inputmode="decimal" value="' + esc(l.amount) + '" aria-label="Amount, line ' + (i + 1) + '" autocomplete="off"></td>'
      + (reg ? '<td><select class="select" data-f="vat_mode" aria-label="VAT, line ' + (i + 1) + '">' + vatOptions() + '</select></td>' : '')
      + (depts.length ? '<td><select class="select" data-f="department_id" aria-label="Department, line ' + (i + 1) + '">' + deptOptions(a) + '</select></td>' : '')
      + '<td class="row-x"><button class="icon-btn" type="button" data-remove aria-label="Remove line ' + (i + 1) + '"' + (lines.length <= 1 ? ' disabled' : '') + '>'
      + '<span data-icon="x" data-icon-size="16"></span></button></td></tr>';
  };

  const val = (tr, k) => { const el = tr.querySelector('[data-f="' + k + '"]'); return el ? el.value : ''; };
  const read = () => {
    lines = qsa('tr[data-line]', tbody).map((tr) => ({
      account_id: parseInt(val(tr, 'account_id'), 10) || 0,
      description: val(tr, 'description'),
      qty: val(tr, 'qty'),
      price: val(tr, 'price'),
      amount: val(tr, 'amount'),
      vat_mode: reg ? (val(tr, 'vat_mode') || 'vatable') : 'exempt',
      department_id: parseInt(val(tr, 'department_id'), 10) || 0,
    }));
  };

  /** One line's amount in centavos: typed, or quantity × unit price; null when something is not a number. */
  const lineAmount = (l) => {
    const typed = String(l.amount || '').trim();
    if (typed !== '') return toCents(typed);
    const q = qtyScaled(l.qty);
    const p = String(l.price || '').trim() === '' ? 0 : toCents(l.price);
    if (q === null || p === null) return null;
    return Number(roundDiv(q * BigInt(p), 10000n));
  };

  const totals = () => {
    read();
    const inclusive = reg && f.prices_include_tax.checked;
    const t = { vatable: 0, exempt: 0, zero_rated: 0, vat: 0, total: 0 };
    let bad = false;
    lines.forEach((l) => {
      const amt = lineAmount(l);
      if (amt === null) { bad = true; return; }
      if (!amt) return;
      const [net, vat] = vatSplit(amt, l.vat_mode, inclusive, rateBp, reg);
      t[l.vat_mode] += net;
      t.vat += vat;
      t.total += net + vat;
    });
    const rowT = (label, v, cls) => '<div class="t-row' + (cls ? ' ' + cls : '') + '"><span>' + esc(label) + '</span><span>' + money(v) + '</span></div>';
    const s = sales ? 'sales' : 'purchases';
    qs('[data-totals]', root).innerHTML = (bad ? '<div class="err-text small">An amount, a quantity or a price is not a number.</div>' : '')
      + (reg ? rowT('VATable ' + s, t.vatable, 't-muted') + (t.exempt ? rowT('VAT-exempt ' + s, t.exempt, 't-muted') : '')
        + (t.zero_rated ? rowT('Zero-rated ' + s, t.zero_rated, 't-muted') : '') + rowT('VAT (' + lk.tax.rate_pct + ' %)', t.vat, 't-muted') : '')
      + rowT(note ? 'Total of the ' + word : type === 'invoice' ? 'Total amount due' : 'Total of the bill', t.total, 't-total');
  };

  const paint = () => {
    tbody.innerHTML = lines.map(rowHtml).join('');
    qsa('tr[data-line]', tbody).forEach((tr, i) => {
      const l = lines[i];
      tr.querySelector('[data-f="account_id"]').value = l.account_id ? String(l.account_id) : '';
      const v = tr.querySelector('[data-f="vat_mode"]');
      if (v) v.value = l.vat_mode || 'vatable';
      const d = tr.querySelector('[data-f="department_id"]');
      if (d) d.value = l.department_id ? String(l.department_id) : '';
    });
    totals();
  };

  // ── wiring ────────────────────────────────────────────────────────────
  tbody.addEventListener('change', (e) => {
    const t = e.target;
    const tr = t.closest('tr[data-line]');
    if (!tr) return;
    if (t.dataset.f === 'account_id') {
      const ds = tr.querySelector('[data-f="department_id"]');
      if (ds) { const dv = ds.value; ds.innerHTML = deptOptions(aById.get(parseInt(t.value, 10))); ds.value = dv; }
    }
    totals();
  });
  tbody.addEventListener('input', (e) => {
    const t = e.target;
    const tr = t.closest('tr[data-line]');
    if (!tr) return;
    /* Quantity or price typed: the amount follows them. An amount typed by hand stays as typed. */
    if (t.dataset.f === 'qty' || t.dataset.f === 'price') {
      const price = val(tr, 'price').trim();
      const q = qtyScaled(val(tr, 'qty'));
      const p = price === '' ? null : toCents(price);
      if (q !== null && p !== null && price !== '') tr.querySelector('[data-f="amount"]').value = toMajor(Number(roundDiv(q * BigInt(p), 10000n)));
    }
    totals();
  });
  tbody.addEventListener('focusout', (e) => {
    const t = e.target;
    if (t.dataset.f !== 'price' && t.dataset.f !== 'amount') return;
    const raw = t.value.trim();
    const c = raw === '' ? 0 : toCents(raw);
    if (raw !== '' && c !== null) t.value = toMajor(c);
    t.classList.toggle('is-invalid', raw !== '' && c === null);
  });
  tbody.addEventListener('click', (e) => {
    const b = e.target.closest('[data-remove]');
    if (!b) return;
    read();
    lines.splice(parseInt(b.closest('tr').dataset.line, 10), 1);
    paint();
  });
  qs('[data-add-line]', root).addEventListener('click', () => {
    read();
    lines.push(blank());
    paint();
    const last = tbody.lastElementChild;
    if (last) last.querySelector('[data-f="description"]').focus();
  });
  f.prices_include_tax.addEventListener('change', totals);
  f.contact_id.addEventListener('change', () => {
    contactId = parseInt(f.contact_id.value, 10) || 0;
    read();
    /* Lines nobody has filled in yet follow the new contact's defaults. */
    lines = lines.map((l) => (!l.description && !l.amount && !l.price ? Object.assign(blank(), { qty: l.qty }) : l));
    paint();
    paintDue();
    paintContactHint();
    relWanted = 0;
    loadRelated();
  });
  f.doc_date.addEventListener('change', paintDue);
  f.due_date.addEventListener('input', () => { dueTouched = f.due_date.value !== ''; paintDue(); });

  // ── saving ────────────────────────────────────────────────────────────
  const postBtn = qs('button[data-then="post"]', form);
  postBtn.hidden = !(hasRole(ctx.user, 'accountant') && lk.policy && lk.policy.self_approve);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const then = (e.submitter && e.submitter.dataset.then) || 'draft';
    clearErrors(form);
    read();
    const out = [];
    const bad = [];
    lines.forEach((l, i) => {
      const empty = !l.description.trim() && !String(l.amount).trim() && !String(l.price).trim();
      if (empty && (!l.account_id || l.account_id === defaultAccount())) return;             // a blank row
      const amt = lineAmount(l);
      const a = aById.get(l.account_id);
      if (!l.account_id) bad.push('Line ' + (i + 1) + ': choose an account.');
      else if (qtyScaled(l.qty) === null) bad.push('Line ' + (i + 1) + ': enter a quantity above zero, with up to 4 decimals.');
      else if (amt === null) bad.push('Line ' + (i + 1) + ': that amount is not a number.');
      else if (!amt) bad.push('Line ' + (i + 1) + ': enter a unit price or an amount.');
      else if (a && a.needs_department && !l.department_id) bad.push('Line ' + (i + 1) + ': ' + a.code + ' needs a department.');
      out.push({
        account_id: l.account_id, description: l.description.trim(), quantity: String(l.qty || '').trim() || '1',
        unit_price_cents: String(l.price).trim() === '' ? 0 : (toCents(l.price) || 0), amount_cents: amt || 0,
        vat_mode: l.vat_mode, department_id: l.department_id || 0,
      });
    });
    const errors = {};
    if (!contactId) errors.contact_id = 'Choose the ' + who + '.';
    if (!f.doc_date.value) errors.doc_date = 'Enter the ' + word + ' date.';
    if (bad.length) errors.lines = bad.slice(0, 4).join(' ') + (bad.length > 4 ? ' (and ' + (bad.length - 4) + ' more)' : '');
    else if (!out.length) errors.lines = 'Add at least one line.';
    if (Object.keys(errors).length) { setErrors(form, errors); return; }

    const body = {
      doc_type: type, contact_id: contactId, doc_date: f.doc_date.value, due_date: note ? '' : f.due_date.value,
      reference: f.reference.value.trim(), description: f.description.value.trim(),
      prices_include_tax: reg ? f.prices_include_tax.checked : false,
      related_document_id: note ? (parseInt(relSel.value, 10) || 0) : 0, lines: out, then,
    };
    const btn = e.submitter || qs('button[data-then="draft"]', form);
    busy(btn, true);
    try {
      const r = id ? await api.put('/documents/' + id, body) : await api.post('/documents', body);
      const n = r.notice || {};
      toast(n.text || 'Saved.', { kind: n.warn ? 'error' : 'ok' });
      ctx.navigate('/documents/' + r.document.id, { replace: !!id });
    } catch (err) {
      formError(form, err);
    } finally {
      busy(btn, false);
    }
  });

  stateEl.innerHTML = '';
  form.hidden = false;
  paint();
  paintDue();
  paintContactHint();
  loadRelated();
  if (!id) setTimeout(() => (contactId ? f.doc_date : f.contact_id).focus(), 40);
}
