/**
 * settlement-edit.js — record a receipt or a payment, or change a draft one
 *
 * GenericPOS Accounting · ES module · bookkeepers and above
 *
 *   /settlements/new?kind=receipt|payment&contact_id=
 *   /settlements/{id}/edit      a draft — its preparer only
 *
 * The amount is the cash that moved. Tax withheld comes on top of it: on a
 * receipt, only when the customer withheld (the rate is typed, 1 % unless
 * changed, on the net of what is paid); on a payment, the supplier's EWT rate
 * on the net of each bill paid. Typing the amount fills the open invoices or
 * bills oldest first; every figure stays editable. What is not applied stays
 * on the contact's account, to be applied later. The server checks it all
 * again, and again when it posts.
 */

import { api } from './api.js';
import { money, toCents, toMajor, fmtDay } from './store.js';
import { qs, qsa, esc, emptyState, busy, toast, formError, clearErrors, setErrors, debounce } from './ui.js';
import { hasRole } from './router.js';

function roundDiv(num, den) {
  if (den === 0n) return 0n;
  const q = (2n * num + den) / (2n * den);
  return q;
}

/** The tax withheld when $a of document $t is paid at $bp: round(net × a / total × bp / 10000). */
function withheld(t, a, bp) {
  if (!bp || !a || !t.total_cents) return 0;
  return Number(roundDiv(BigInt(t.net_cents) * BigInt(a) * BigInt(bp), BigInt(t.total_cents) * 10000n));
}

export async function mount(root, ctx) {
  const id = ctx.params.id ? parseInt(ctx.params.id, 10) || 0 : 0;
  const stateEl = qs('[data-state]', root);
  const form = qs('[data-form]', root);
  const f = form.elements;

  let kind = ctx.query.get('kind') === 'payment' ? 'payment' : 'receipt';
  let cur = null;
  let lk = null;
  try {
    if (id) {
      cur = await api.get('/settlements/' + id, null, { signal: ctx.signal });
      kind = cur.settlement.kind;
    }
    lk = await api.get('/settlements/lookups', { kind }, { signal: ctx.signal });
  } catch (err) {
    if (ctx.signal.aborted) return;
    stateEl.innerHTML = emptyState('warning', 'Could not open the form', err.message, '<a class="btn btn-secondary" href="receipts">Back to the receipts</a>');
    return;
  }

  const rcpt = kind === 'receipt';
  const who = rcpt ? 'customer' : 'supplier';
  const docs = rcpt ? 'invoices' : 'bills';
  const listHref = rcpt ? 'receipts' : 'payments';
  if (id && !cur.can.edit) {
    stateEl.innerHTML = emptyState('lock-key', 'This ' + kind + ' cannot be changed',
      cur.settlement.status === 'draft' ? 'Only the person who prepared a draft can change it.' : 'It has posted. Cancel it and record it again to correct it.',
      '<a class="btn btn-secondary" href="settlements/' + id + '">Back to the ' + kind + '</a>');
    return;
  }

  // ── the header ────────────────────────────────────────────────────────
  const title = id ? 'Edit draft ' + kind + ' #' + id : (rcpt ? 'Record a receipt' : 'Record a payment');
  qs('[data-title]', root).textContent = title;
  qs('[data-crumb]', root).textContent = title;
  qs('[data-crumb-list]', root).textContent = rcpt ? 'Receipts' : 'Payments';
  qs('[data-crumb-list]', root).setAttribute('href', listHref);
  qs('a[data-cancel]', root).setAttribute('href', id ? 'settlements/' + id : listHref);
  ctx.setTitle(title);
  qs('[data-sub]', root).textContent = rcpt
    ? 'Money a customer paid you, and the invoices it pays. It reaches the ledger when an accountant posts it: cash in, the customer\'s balance down.'
    : 'Money you paid a supplier, and the bills it pays. It reaches the ledger when an accountant posts it: cash out, the supplier\'s balance down.';
  qs('[data-contact-label]', root).textContent = rcpt ? 'Customer' : 'Supplier';
  qs('[data-date-label]', root).textContent = rcpt ? 'Date received' : 'Date paid';
  qs('[data-cash-label]', root).textContent = rcpt ? 'Deposited to' : 'Paid from';
  qs('[data-ref-label]', root).innerHTML = rcpt ? 'OR number <span class="opt">(optional)</span>' : 'Cheque number <span class="opt">(optional)</span>';
  f.reference.placeholder = rcpt ? 'OR 2041' : 'CHK 500123';
  qs('[data-ref-hint]', root).textContent = rcpt ? 'From the official receipt you issued.' : 'Leave it empty for a cash payment or a bank transfer.';
  qs('[data-amount-label]', root).textContent = rcpt ? 'Amount received' : 'Amount paid';
  qs('[data-amount-hint]', root).textContent = 'The cash that moved. Typing it fills the ' + docs + ' below, oldest first.';
  qs('[data-wh-label]', root).textContent = rcpt ? 'Creditable withholding tax (CWT)' : 'Expanded withholding tax (EWT)';
  qs('[data-cwt-row]', root).hidden = !rcpt;
  qs('[data-alloc-title]', root).textContent = rcpt ? 'Invoices it pays' : 'Bills it pays';
  qs('[data-target-col]', root).textContent = rcpt ? 'Invoice' : 'Bill';
  qs('[data-note-col]', root).textContent = rcpt ? 'Credit note' : 'Debit note';
  if (!lk.withholding.account_set) {
    qs('[data-wh-hint]', root).textContent = 'The ' + (rcpt ? 'creditable withholding tax' : 'withholding tax payable') + ' account is not set in Settings, so tax withheld cannot post yet.';
  }

  const periods = lk.open_periods || [];
  qs('[data-date-hint]', root).textContent = periods.length
    ? 'Open for entries: ' + (periods.length === 1 ? periods[0].name : periods[0].name + ' to ' + periods[periods.length - 1].name) + '.'
    : 'No month is open for entries. An accountant can reopen one.';

  const contacts = lk.contacts;
  const cById = new Map(contacts.map((c) => [c.id, c]));
  const s0 = cur ? cur.settlement : null;
  let contactId = s0 ? s0.contact_id : parseInt(ctx.query.get('contact_id') || '0', 10) || 0;
  f.contact_id.innerHTML = '<option value="">Choose the ' + who + '</option>' + contacts.map((c) => '<option value="' + c.id + '">' + esc(c.name + ' (' + c.code + ')') + '</option>').join('')
    + (contactId && !cById.has(contactId) && cur ? '<option value="' + contactId + '" disabled>' + esc(cur.contact.name + ' (inactive)') + '</option>' : '');
  f.contact_id.value = contactId ? String(contactId) : '';
  f.cash_account_id.innerHTML = '<option value="">Choose the cash or bank account</option>' + lk.cash_accounts.map((a) => '<option value="' + a.id + '">'
    + esc(a.code + ' · ' + a.name + (a.bank ? ' — ' + a.bank : '')) + '</option>').join('');

  if (s0) {
    f.settle_date.value = s0.settle_date;
    f.cash_account_id.value = String(s0.cash_account_id);
    f.reference.value = s0.reference || '';
    f.amount.value = toMajor(s0.amount_cents);
    f.withholding.value = s0.withholding_cents ? toMajor(s0.withholding_cents) : '';
    f.description.value = s0.description || '';
    f.wh_on.checked = rcpt && s0.withholding_cents > 0;
  } else {
    f.settle_date.value = lk.today;
    const bank = lk.cash_accounts.find((a) => a.bank) || lk.cash_accounts[0];
    if (bank) f.cash_account_id.value = String(bank.id);
  }

  // ── what it pays ──────────────────────────────────────────────────────
  let targets = [];
  let notes = [];
  const alloc = new Map();   // document id → cents typed
  let whAuto = !(s0 && s0.withholding_cents > 0);
  if (cur && cur.plan) cur.plan.forEach((p) => alloc.set(p.document_id, p.amount_cents));

  const rateBp = () => {
    if (rcpt) {
      if (!f.wh_on.checked) return 0;
      const v = String(f.wh_rate.value || '').trim();
      return /^\d{1,2}(\.\d{1,2})?$/.test(v) ? Math.round(parseFloat(v) * 100) : 0;
    }
    const c = cById.get(contactId);
    return c ? c.ewt_rate_bp : 0;
  };
  const cents = (el) => { const v = String(el.value || '').trim(); return v === '' ? 0 : toCents(v); };

  const suggestion = () => targets.reduce((n, t) => n + withheld(t, alloc.get(t.id) || 0, rateBp()), 0);

  /** Fill the documents oldest first with the cash (plus any notes used), leaving room for the tax withheld on each. */
  const fillOldest = () => {
    const cash = cents(f.amount);
    if (cash === null) return;
    const bp = rateBp();
    let left = cash + notes.reduce((n, x) => n + (alloc.get(x.id) || 0), 0);
    targets.forEach((t) => {
      if (left <= 0) { alloc.delete(t.id); return; }
      const need = t.open_cents - withheld(t, t.open_cents, bp);
      if (left >= need) { alloc.set(t.id, t.open_cents); left -= need; return; }
      let a = bp ? Number((BigInt(left) * BigInt(t.total_cents) * 10000n) / (BigInt(t.total_cents) * 10000n - BigInt(t.net_cents) * BigInt(bp))) : left;
      a = Math.min(a, t.open_cents);
      while (a < t.open_cents && a - withheld(t, a, bp) < left) a++;
      while (a > 0 && a - withheld(t, a, bp) > left) a--;
      if (a > 0) alloc.set(t.id, a); else alloc.delete(t.id);
      left = 0;
    });
    paintRows();
  };

  const paintSummary = () => {
    const cash = cents(f.amount);
    const wh = cents(f.withholding);
    const st = targets.reduce((n, t) => n + (alloc.get(t.id) || 0), 0);
    const sn = notes.reduce((n, x) => n + (alloc.get(x.id) || 0), 0);
    const row = (label, v, cls) => '<div class="t-row' + (cls ? ' ' + cls : '') + '"><span>' + esc(label) + '</span><span>' + money(v) + '</span></div>';
    let html = row('Applied to ' + docs, st, 't-muted');
    if (sn) html += row('Less ' + (rcpt ? 'credit' : 'debit') + ' notes used', -sn, 't-muted');
    html += row('Paid by this ' + kind, st - sn, 't-muted');
    if (cash !== null && wh !== null) {
      const left = cash + wh - (st - sn);
      html += row(rcpt ? 'Cash received' : 'Cash paid', cash, 't-muted') + row('Tax withheld', wh, 't-muted');
      html += left < 0 ? '<div class="t-row err-text"><span>More is applied than was ' + (rcpt ? 'received' : 'paid') + ' and withheld</span><span>' + money(-left) + '</span></div>'
        : row(left ? 'Left unapplied on the account' : 'Nothing left unapplied', left, 't-total');
    } else {
      html += '<div class="err-text small">An amount is not a number.</div>';
    }
    qs('[data-summary]', root).innerHTML = html;
    const s = suggestion();
    const bp = rateBp();
    if (lk.withholding.account_set) {
      qs('[data-wh-hint]', root).textContent = bp
        ? 'Suggested ' + money(s) + ': ' + (bp / 100) + ' % of the net of what is paid' + (whAuto ? '.' : '. You typed your own figure.')
        : rcpt ? 'Tick the box when the customer withheld tax (they give you BIR Form 2307).' : 'This supplier has no withholding rate.';
    }
  };

  const syncWithholding = () => {
    if (!whAuto) return;
    const s = suggestion();
    f.withholding.value = s ? toMajor(s) : '';
  };

  const paintRows = () => {
    const tr = (x, isNote) => '<tr data-doc="' + x.id + '"><td><a class="code" href="documents/' + x.id + '" target="_blank" rel="noopener">' + esc(x.doc_no) + '</a>'
      + (x.reference ? '<div class="xs muted">' + esc(x.reference) + '</div>' : '') + '</td>'
      + '<td class="nowrap">' + esc(fmtDay(x.doc_date)) + '</td>'
      + '<td class="nowrap">' + (isNote ? '' : esc(x.due_date ? fmtDay(x.due_date) : '') + (x.days_overdue ? '<div class="xs err-text">' + x.days_overdue + ' days overdue</div>' : '')) + '</td>'
      + '<td class="money">' + money(x.open_cents) + '</td>'
      + '<td class="amt"><input class="input" inputmode="decimal" data-alloc="' + x.id + '" value="' + (alloc.get(x.id) ? esc(toMajor(alloc.get(x.id))) : '') + '" aria-label="Apply to ' + esc(x.doc_no) + '"></td></tr>';
    qs('[data-targets]', root).innerHTML = targets.map((t) => tr(t, false)).join('');
    qs('[data-notes]', root).innerHTML = notes.map((n) => tr(n, true)).join('');
    qs('[data-targets-wrap]', root).hidden = !targets.length;
    qs('[data-notes-wrap]', root).hidden = !notes.length;
    syncWithholding();
    paintSummary();
  };

  const loadItems = async (keep) => {
    const box = qs('[data-alloc-state]', root);
    targets = [];
    notes = [];
    if (!keep) alloc.clear();
    if (!contactId) {
      box.textContent = 'Choose the ' + who + ' to see their open ' + docs + '.';
      box.hidden = false;
      paintRows();
      return;
    }
    box.textContent = 'Loading their open ' + docs + '…';
    box.hidden = false;
    try {
      const r = await api.get('/documents/open-items', { side: rcpt ? 'ar' : 'ap', contact_id: contactId }, { signal: ctx.signal });
      targets = r.targets || [];
      notes = r.notes || [];
      /* A draft's plan may name a document that has since been paid: keep it on screen so it can be taken off. */
      if (keep && cur) {
        cur.plan.forEach((p) => {
          const list = p.is_note ? notes : targets;
          if (!list.some((x) => x.id === p.document_id)) {
            list.push({ id: p.document_id, doc_no: p.doc_no, doc_date: p.doc_date, due_date: p.due_date, reference: null, net_cents: p.net_cents,
              total_cents: p.total_cents, open_cents: p.open_cents, days_overdue: 0 });
          }
        });
      }
      box.textContent = targets.length || notes.length ? '' : 'Nothing open: whatever you record stays unapplied on their account until you apply it.';
      box.hidden = !!(targets.length || notes.length);
    } catch (err) {
      box.textContent = 'Could not load the open ' + docs + ': ' + err.message;
    }
    paintRows();
  };

  const paintContactHint = () => {
    const c = cById.get(contactId);
    qs('[data-contact-hint]', root).textContent = c ? [c.tin ? 'TIN ' + c.tin : '', !rcpt && c.ewt_rate_bp ? (c.ewt_rate_bp / 100) + ' % EWT is withheld from what you pay them' : ''].filter(Boolean).join(' · ') : '';
  };

  // ── wiring ────────────────────────────────────────────────────────────
  f.contact_id.addEventListener('change', () => {
    contactId = parseInt(f.contact_id.value, 10) || 0;
    whAuto = true;
    paintContactHint();
    loadItems(false).then(() => { if (cents(f.amount)) fillOldest(); });
  });
  f.amount.addEventListener('input', debounce(() => { fillOldest(); }, 250));
  f.amount.addEventListener('focusout', () => { const c = cents(f.amount); if (c !== null && f.amount.value.trim() !== '') f.amount.value = toMajor(c); });
  f.withholding.addEventListener('input', () => { whAuto = f.withholding.value.trim() === ''; paintSummary(); });
  f.withholding.addEventListener('focusout', () => { const c = cents(f.withholding); if (c !== null && f.withholding.value.trim() !== '') f.withholding.value = toMajor(c); });
  f.wh_on.addEventListener('change', () => { whAuto = true; syncWithholding(); paintSummary(); });
  f.wh_rate.addEventListener('input', () => { whAuto = true; syncWithholding(); paintSummary(); });
  form.addEventListener('input', (e) => {
    const el = e.target.closest('[data-alloc]');
    if (!el) return;
    const c = cents(el);
    if (c === null) { el.classList.add('is-invalid'); return; }
    el.classList.remove('is-invalid');
    if (c) alloc.set(Number(el.dataset.alloc), c); else alloc.delete(Number(el.dataset.alloc));
    syncWithholding();
    paintSummary();
  });
  form.addEventListener('focusout', (e) => {
    const el = e.target.closest('[data-alloc]');
    if (!el) return;
    const c = cents(el);
    if (c) el.value = toMajor(c);
  });
  qs('[data-fill]', root).addEventListener('click', fillOldest);
  qs('[data-clear]', root).addEventListener('click', () => { alloc.clear(); paintRows(); });

  // ── saving ────────────────────────────────────────────────────────────
  qs('button[data-then="post"]', form).hidden = !(hasRole(ctx.user, 'accountant') && lk.policy && lk.policy.self_approve);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    const then = (e.submitter && e.submitter.dataset.then) || 'draft';
    clearErrors(form);
    const amount = cents(f.amount);
    const wh = cents(f.withholding);
    const errors = {};
    if (!contactId) errors.contact_id = 'Choose the ' + who + '.';
    if (!f.settle_date.value) errors.settle_date = 'Enter the date.';
    if (!f.cash_account_id.value) errors.cash_account_id = 'Choose the cash or bank account.';
    if (amount === null || amount <= 0) errors.amount_cents = 'Enter the amount ' + (rcpt ? 'received' : 'paid') + '.';
    if (wh === null) errors.withholding_cents = 'Enter the tax withheld as an amount, or leave it empty.';
    if (qsa('[data-alloc].is-invalid', form).length) errors.allocations = 'An amount applied is not a number.';
    if (Object.keys(errors).length) { setErrors(form, errors); return; }

    const allocations = [];
    targets.concat(notes).forEach((x) => { const a = alloc.get(x.id); if (a) allocations.push({ document_id: x.id, amount_cents: a }); });
    const body = {
      kind, contact_id: contactId, settle_date: f.settle_date.value, cash_account_id: Number(f.cash_account_id.value),
      reference: f.reference.value.trim(), amount_cents: amount, withholding_cents: wh || 0, description: f.description.value.trim(), allocations, then,
    };
    const btn = e.submitter || qs('button[data-then="draft"]', form);
    busy(btn, true);
    try {
      const r = id ? await api.put('/settlements/' + id, body) : await api.post('/settlements', body);
      const n = r.notice || {};
      toast(n.text || 'Saved.', { kind: n.warn ? 'error' : 'ok' });
      ctx.navigate('/settlements/' + r.settlement.id, { replace: !!id });
    } catch (err) {
      formError(form, err);
    } finally {
      busy(btn, false);
    }
  });

  stateEl.innerHTML = '';
  form.hidden = false;
  paintContactHint();
  await loadItems(true);
  if (!id) setTimeout(() => (contactId ? f.amount : f.contact_id).focus(), 40);
}
