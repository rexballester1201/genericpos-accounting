/**
 * journal-edit.js — prepare or change a journal entry
 *
 * GenericPOS Accounting · ES module · bookkeepers and above
 *
 *   /journals/new             a new entry (?copy=ID starts from another entry's lines)
 *   /journals/{id}/edit       a draft or a rejected entry — its preparer only
 *
 * Amounts are typed in pesos and sent as integer centavos (store.toCents —
 * exact, no floats). The totals row keeps a running difference, a new line
 * starts with whatever would balance the entry, and a line on a receivables
 * or payables control account offers only customers or suppliers. The server
 * checks everything again; this screen only helps.
 */

import { api } from './api.js';
import { money, toCents, toMajor, fmtDay } from './store.js';
import { qs, qsa, esc, emptyState, busy, toast, formError, clearErrors, setErrors } from './ui.js';
import { hasRole } from './router.js';

const TYPE_LABELS = { asset: 'Assets', liability: 'Liabilities', equity: 'Equity', income: 'Income', expense: 'Expenses' };

const blank = () => ({ account_id: 0, memo: '', department_id: 0, contact_id: 0, debit: '', credit: '' });

export async function mount(root, ctx) {
  const id = ctx.params.id ? parseInt(ctx.params.id, 10) || 0 : 0;
  const copyId = id ? 0 : parseInt(ctx.query.get('copy') || '0', 10) || 0;
  const stateEl = qs('[data-state]', root);
  const form = qs('[data-form]', root);
  const tbody = qs('[data-lines]', root);
  const f = form.elements;

  let lk;
  let entry = null;
  try {
    const tasks = [api.get('/journals/lookups', null, { signal: ctx.signal })];
    if (id || copyId) tasks.push(api.get('/journals/' + (id || copyId), null, { signal: ctx.signal }));
    [lk, entry] = await Promise.all(tasks);
  } catch (err) {
    if (ctx.signal.aborted) return;
    stateEl.innerHTML = emptyState('warning', 'Could not open the entry form', err.message, '<a class="btn btn-secondary" href="journals">Back to the entries</a>');
    return;
  }
  if (id && !entry.can.edit) {
    stateEl.innerHTML = emptyState('lock-key', 'This entry cannot be changed',
      entry.journal.status === 'posted' ? 'It has posted. Reverse it to correct it.' : 'Only its preparer can change a draft or a rejected entry.',
      '<a class="btn btn-secondary" href="journals/' + id + '">Back to the entry</a>');
    return;
  }

  const accounts = lk.accounts;
  const byId = new Map(accounts.map((a) => [a.id, a]));
  const depts = lk.departments;
  const contacts = lk.contacts;
  const hasDepts = depts.length > 0;
  if (!hasDepts) {
    qs('[data-col-dept]', root).hidden = true;
    qs('[data-foot-span]', root).setAttribute('colspan', '3');
  }

  // ── the header ────────────────────────────────────────────────────────
  f.book.innerHTML = lk.books.filter((b) => b.manual).map((b) => '<option value="' + b.key + '">' + esc(b.label) + '</option>').join('');
  const manual = new Set(lk.books.filter((b) => b.manual).map((b) => b.key));
  const src = entry ? entry.journal : null;

  if (id) {
    const t = 'Edit ' + (src.status === 'rejected' ? 'rejected entry' : 'draft') + ' #' + src.id;
    qs('[data-title]', root).textContent = t;
    qs('[data-crumb]', root).textContent = t;
    ctx.setTitle(t);
    f.book.value = src.book;
    f.entry_date.value = src.entry_date;
    f.reference.value = src.reference || '';
    f.party_name.value = src.party_name || '';
    f.description.value = src.description || '';
    qs('a[data-cancel]', root).setAttribute('href', 'journals/' + id);
    if (src.status === 'rejected' && src.reject_reason) {
      const n = qs('[data-note]', root);
      n.textContent = 'Returned by ' + (src.rejected_by_name || 'the approver') + ': ' + src.reject_reason + ' Saving puts it back to a draft; submit it again when it is fixed.';
      n.hidden = false;
    }
  } else {
    f.book.value = src && manual.has(src.book) ? src.book : 'general';
    f.entry_date.value = lk.today;
    if (src) {
      f.description.value = src.description || '';
      f.party_name.value = src.party_name || '';
      qs('[data-sub]', root).textContent = 'A copy of ' + (src.journal_no || '#' + src.id) + '. Check the date, the reference and the amounts before saving.';
    }
  }

  const periods = lk.open_periods || [];
  qs('[data-date-hint]', root).textContent = periods.length
    ? 'Open for entries: ' + (periods.length === 1 ? periods[0].name : periods[0].name + ' to ' + periods[periods.length - 1].name) + '.'
    : 'No month is open for entries. An accountant can reopen one.';

  // ── the lines ─────────────────────────────────────────────────────────
  let lines = entry
    ? entry.lines.map((l) => ({ account_id: l.account_id, memo: l.memo || '', department_id: l.department_id || 0, contact_id: l.contact_id || 0,
        debit: l.debit_cents ? toMajor(l.debit_cents) : '', credit: l.credit_cents ? toMajor(l.credit_cents) : '' }))
    : [blank(), blank()];
  if (lines.length < 2) lines.push(blank());

  const groups = {};
  accounts.filter((a) => a.active).forEach((a) => { (groups[a.type] = groups[a.type] || []).push(a); });
  const ACCOUNT_OPTS = '<option value="">Choose an account</option>' + Object.keys(TYPE_LABELS).filter((t) => groups[t]).map((t) =>
    '<optgroup label="' + TYPE_LABELS[t] + '">' + groups[t].map((a) => '<option value="' + a.id + '">' + esc(a.code + ' · ' + a.name) + '</option>').join('') + '</optgroup>').join('');

  const accountSelect = (l, i) => {
    const a = byId.get(l.account_id);
    const extra = a && !a.active ? '<option value="' + a.id + '" disabled>' + esc(a.code + ' · ' + a.name + ' (inactive)') + '</option>' : '';
    return '<select class="select" data-f="account_id" aria-label="Account, line ' + (i + 1) + '">' + extra + ACCOUNT_OPTS + '</select>';
  };
  const contactOptions = (a) => {
    const ctl = a && a.control;
    const list = contacts.filter((c) => (ctl === 'ar' ? c.customer : ctl === 'ap' ? c.supplier : true));
    return '<option value="">' + (ctl === 'ar' ? 'Choose the customer' : ctl === 'ap' ? 'Choose the supplier' : '—') + '</option>'
      + list.map((c) => '<option value="' + c.id + '">' + esc(c.name) + '</option>').join('');
  };
  const deptOptions = (a) => '<option value="">' + (a && a.needs_department ? 'Choose a department' : '—') + '</option>'
    + depts.map((x) => '<option value="' + x.id + '">' + esc(x.code + ' · ' + x.name) + '</option>').join('');

  const rowHtml = (l, i) => {
    const a = byId.get(l.account_id);
    return '<tr data-line="' + i + '">'
      + '<td>' + accountSelect(l, i) + '</td>'
      + '<td><input class="input" data-f="memo" maxlength="255" value="' + esc(l.memo) + '" aria-label="Memo, line ' + (i + 1) + '" autocomplete="off"></td>'
      + (hasDepts ? '<td><select class="select" data-f="department_id" aria-label="Department, line ' + (i + 1) + '">' + deptOptions(a) + '</select></td>' : '')
      + '<td><select class="select" data-f="contact_id" aria-label="Customer or supplier, line ' + (i + 1) + '">' + contactOptions(a) + '</select></td>'
      + '<td class="amt"><input class="input" data-f="debit" inputmode="decimal" autocomplete="off" value="' + esc(l.debit) + '" aria-label="Debit, line ' + (i + 1) + '"></td>'
      + '<td class="amt"><input class="input" data-f="credit" inputmode="decimal" autocomplete="off" value="' + esc(l.credit) + '" aria-label="Credit, line ' + (i + 1) + '"></td>'
      + '<td class="row-x"><button class="icon-btn" type="button" data-remove aria-label="Remove line ' + (i + 1) + '"' + (lines.length <= 2 ? ' disabled' : '') + '>'
      + '<span data-icon="x" data-icon-size="16"></span></button></td></tr>';
  };

  const val = (tr, k) => { const el = tr.querySelector('[data-f="' + k + '"]'); return el ? el.value : ''; };

  /** The lines as they are on screen now. */
  const read = () => {
    lines = qsa('tr[data-line]', tbody).map((tr) => ({
      account_id: parseInt(val(tr, 'account_id'), 10) || 0,
      memo: val(tr, 'memo'),
      department_id: parseInt(val(tr, 'department_id'), 10) || 0,
      contact_id: parseInt(val(tr, 'contact_id'), 10) || 0,
      debit: val(tr, 'debit'),
      credit: val(tr, 'credit'),
    }));
  };

  const sums = () => {
    let dr = 0;
    let cr = 0;
    let bad = false;
    qsa('tr[data-line]', tbody).forEach((tr) => {
      const d = val(tr, 'debit').trim();
      const c = val(tr, 'credit').trim();
      if (d) { const v = toCents(d); if (v === null) bad = true; else dr += v; }
      if (c) { const v = toCents(c); if (v === null) bad = true; else cr += v; }
    });
    return { dr, cr, bad };
  };

  const totals = () => {
    const s = sums();
    qs('[data-total-dr]', root).textContent = money(s.dr);
    qs('[data-total-cr]', root).textContent = money(s.cr);
    const diff = s.dr - s.cr;
    qs('[data-diff]', root).innerHTML = s.bad ? '<span class="err-text">An amount is not a number.</span>'
      : diff === 0
        ? (s.dr > 0 ? '<span class="ok-text row gap-1"><span data-icon="check-circle" data-icon-size="16"></span>Balanced</span>' : '<span class="muted">Enter the amounts.</span>')
        : '<span class="err-text">' + (diff > 0 ? 'Debits exceed credits by ' : 'Credits exceed debits by ') + money(Math.abs(diff)) + '</span>';
  };

  const paint = () => {
    tbody.innerHTML = lines.map(rowHtml).join('');
    qsa('tr[data-line]', tbody).forEach((tr, i) => {
      const l = lines[i];
      tr.querySelector('[data-f="account_id"]').value = l.account_id ? String(l.account_id) : '';
      const d = tr.querySelector('[data-f="department_id"]');
      if (d) d.value = l.department_id ? String(l.department_id) : '';
      const c = tr.querySelector('[data-f="contact_id"]');
      c.value = l.contact_id ? String(l.contact_id) : '';
      if (l.contact_id && c.value !== String(l.contact_id)) c.value = '';
    });
    totals();
  };

  const dirty = () => { form.setAttribute('data-dirty', '1'); };

  tbody.addEventListener('change', (e) => {
    const t = e.target;
    const tr = t.closest('tr[data-line]');
    if (!tr) return;
    if (t.dataset.f === 'account_id') {
      const a = byId.get(parseInt(t.value, 10));
      const cs = tr.querySelector('[data-f="contact_id"]');
      const cv = cs.value;
      cs.innerHTML = contactOptions(a);
      cs.value = cv;
      if (cs.value !== cv) cs.value = '';
      const ds = tr.querySelector('[data-f="department_id"]');
      if (ds) { const dv = ds.value; ds.innerHTML = deptOptions(a); ds.value = dv; }
    }
    dirty();
  });

  tbody.addEventListener('input', (e) => {
    const t = e.target;
    const tr = t.closest('tr[data-line]');
    if (!tr) return;
    /* One side per line: typing a debit clears the credit, and the other way round. */
    if (t.dataset.f === 'debit' && t.value.trim() !== '') tr.querySelector('[data-f="credit"]').value = '';
    if (t.dataset.f === 'credit' && t.value.trim() !== '') tr.querySelector('[data-f="debit"]').value = '';
    totals();
    dirty();
  });

  tbody.addEventListener('focusout', (e) => {
    const t = e.target;
    if (t.dataset.f !== 'debit' && t.dataset.f !== 'credit') return;
    const raw = t.value.trim();
    const c = raw === '' ? 0 : toCents(raw);
    if (raw !== '' && c !== null) t.value = c === 0 ? '' : toMajor(c);
    t.classList.toggle('is-invalid', raw !== '' && c === null);
    totals();
  });

  tbody.addEventListener('click', (e) => {
    const b = e.target.closest('[data-remove]');
    if (!b) return;
    read();
    lines.splice(parseInt(b.closest('tr').dataset.line, 10), 1);
    paint();
    dirty();
  });

  qs('[data-add-line]', root).addEventListener('click', () => {
    read();
    const s = sums();
    const diff = s.dr - s.cr;
    const l = blank();
    if (!s.bad && diff > 0) l.credit = toMajor(diff);
    else if (!s.bad && diff < 0) l.debit = toMajor(-diff);
    lines.push(l);
    paint();
    dirty();
    const last = tbody.lastElementChild;
    if (last) last.querySelector('[data-f="account_id"]').focus();
  });

  form.addEventListener('input', (e) => { if (!e.target.closest('[data-lines]')) dirty(); });

  // ── saving ────────────────────────────────────────────────────────────
  const postBtn = qs('button[data-then="post"]', form);
  postBtn.hidden = !(hasRole(ctx.user, 'accountant') && lk.policy && lk.policy.self_approve);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const then = (e.submitter && e.submitter.dataset.then) || 'draft';
    clearErrors(form);
    read();

    const out = [];
    const lineErr = [];
    lines.forEach((l, i) => {
      const d = l.debit.trim();
      const c = l.credit.trim();
      if (!l.account_id && !d && !c && !l.memo.trim()) return;            // a blank row
      const dc = d ? toCents(d) : 0;
      const cc = c ? toCents(c) : 0;
      const a = byId.get(l.account_id);
      if (!l.account_id) lineErr.push('Line ' + (i + 1) + ': choose an account.');
      else if (dc === null || cc === null) lineErr.push('Line ' + (i + 1) + ': that amount is not a number.');
      else if ((dc > 0) === (cc > 0)) lineErr.push('Line ' + (i + 1) + ': enter either a debit or a credit.');
      else if (a && a.control === 'ar' && !l.contact_id) lineErr.push('Line ' + (i + 1) + ': ' + a.code + ' needs a customer.');
      else if (a && a.control === 'ap' && !l.contact_id) lineErr.push('Line ' + (i + 1) + ': ' + a.code + ' needs a supplier.');
      else if (a && a.needs_department && !l.department_id) lineErr.push('Line ' + (i + 1) + ': ' + a.code + ' needs a department.');
      out.push({ account_id: l.account_id, debit_cents: dc || 0, credit_cents: cc || 0, memo: l.memo.trim(),
        department_id: l.department_id || 0, contact_id: l.contact_id || 0 });
    });

    const errors = {};
    if (!f.description.value.trim()) errors.description = 'Describe the entry.';
    if (!f.entry_date.value) errors.entry_date = 'Enter the date.';
    if (lineErr.length) errors.lines = lineErr.slice(0, 4).join(' ') + (lineErr.length > 4 ? ' (and ' + (lineErr.length - 4) + ' more)' : '');
    else if (out.length < 2) errors.lines = 'An entry needs at least two lines.';
    else {
      const dr = out.reduce((n, l) => n + l.debit_cents, 0);
      const cr = out.reduce((n, l) => n + l.credit_cents, 0);
      if (dr !== cr) errors.lines = 'Debits (' + money(dr) + ') and credits (' + money(cr) + ') do not balance.';
    }
    if (Object.keys(errors).length) { setErrors(form, errors); return; }

    const body = {
      book: f.book.value, entry_date: f.entry_date.value, reference: f.reference.value.trim(),
      party_name: f.party_name.value.trim(), description: f.description.value.trim(), lines: out, then,
    };
    const btn = e.submitter || qs('button[data-then="draft"]', form);
    busy(btn, true);
    try {
      const r = id ? await api.put('/journals/' + id, body) : await api.post('/journals', body);
      form.removeAttribute('data-dirty');
      const n = r.notice || {};
      toast(n.text || 'Saved.', { kind: n.warn ? 'error' : 'ok' });
      window.dispatchEvent(new CustomEvent('gp:badges'));
      ctx.navigate('/journals/' + r.journal.id, { replace: !!id });
    } catch (err) {
      formError(form, err);
    } finally {
      busy(btn, false);
    }
  });

  stateEl.innerHTML = '';
  form.hidden = false;
  paint();
  if (!id) setTimeout(() => f.description.focus(), 40);

  return () => { form.removeAttribute('data-dirty'); };
}
