/**
 * settlement.js — one receipt or payment: the voucher that is printed and
 * signed, the invoices or bills it settled, and where it sits in the books
 *
 * GenericPOS Accounting · ES module · every role (the actions depend on the role)
 *
 *   /settlements/{id}
 *
 * The server says what this person may do now (`can`). Posting takes the
 * number, writes the cash-receipts or cash-disbursements entry and applies the
 * money to the documents chosen; a posted receipt or payment is never edited —
 * it is cancelled, which reverses its entry and opens again what it settled.
 */

import { api } from './api.js';
import { money, fmtDay, fmtDate, todayYmd } from './store.js';
import { qs, esc, emptyState, statusBadge, confirmDialog, promptDialog, openModal, toast, busy, formError, clearErrors } from './ui.js';
import { setAdminTitle } from './chrome.js';
import { amt, sheetHead } from './report-kit.js';
import { attachmentsPanel } from './attachments.js';

const STEP = { create: 'Prepared', update: 'Changed', post: 'Posted', cancel: 'Cancelled', delete: 'Deleted', apply: 'Applied' };

export async function mount(root, ctx) {
  const id = parseInt(ctx.params.id, 10) || 0;
  const stateEl = qs('[data-state]', root);
  const bodyEl = qs('[data-body]', root);
  let d = null;

  const alertBox = (html, kind) => {
    const b = qs('[data-banner]', root);
    if (!html) { b.hidden = true; return; }
    b.className = 'alert no-print alert-' + kind;
    b.innerHTML = html;
    b.hidden = false;
  };

  const paint = () => {
    const s = d.settlement;
    const c = d.contact;
    const receipt = s.kind === 'receipt';
    const word = s.kind_label.toLowerCase();
    const no = s.settle_no || 'Draft ' + word + ' #' + s.id;

    qs('[data-crumb-list]', root).textContent = receipt ? 'Receipts' : 'Payments';
    qs('[data-crumb-list]', root).setAttribute('href', receipt ? 'receipts' : 'payments');
    qs('[data-crumb]', root).textContent = no;
    qs('[data-title]', root).innerHTML = '<span>' + esc(no) + '</span>' + statusBadge(s.status);
    ctx.setTitle(no);
    setAdminTitle(no);
    qs('[data-sub]', root).innerHTML = esc(s.kind_label) + ' · <a href="contacts/' + c.id + '">' + esc(c.name) + '</a> · ' + esc(fmtDay(s.settle_date, 'long'))
      + ' · ' + esc(money(s.amount_cents)) + (d.cash_account ? ' through ' + esc(d.cash_account.code + ' ' + d.cash_account.name) : '');

    if (s.status === 'draft') {
      alertBox('<span data-icon="pencil-simple"></span><div>A draft: it has no number and no entry yet. It takes its number, moves the cash and settles what it is applied to when an accountant posts it.</div>', 'neutral');
    } else if (s.status === 'cancelled') {
      alertBox('<span data-icon="prohibit"></span><div>Cancelled' + (d.reversal ? ': its entry was reversed by <a href="journals/' + d.reversal.id + '"><b class="mono">' + esc(d.reversal.journal_no) + '</b></a>' : '')
        + '. What it had settled is open again.</div>', 'neutral');
    } else if (s.unapplied_cents) {
      alertBox('<span data-icon="info"></span><div>' + money(s.unapplied_cents) + ' of this ' + word + ' is not applied to any '
        + (receipt ? 'invoice' : 'bill') + ' yet. It sits on ' + esc(c.name) + '’s account until it is applied.</div>', 'info');
    } else if (d.bank_matched) {
      alertBox('<span data-icon="bank"></span><div>Matched to a line on a bank statement. Unmatch it in Banking before cancelling it.</div>', 'info');
    } else {
      alertBox('', '');
    }

    const can = d.can;
    const btn = (act, label, icon, cls) => '<button class="btn ' + cls + '" type="button" data-do="' + act + '"><span data-icon="' + icon + '" data-icon-size="18"></span>' + label + '</button>';
    const link = (href, label, icon, cls) => '<a class="btn ' + cls + '" href="' + href + '"><span data-icon="' + icon + '" data-icon-size="18"></span>' + label + '</a>';
    qs('[data-actions]', root).innerHTML = [
      can.delete ? btn('delete', 'Delete draft', 'trash', 'btn-ghost') : '',
      can.cancel ? btn('cancel', 'Cancel ' + word, 'prohibit', 'btn-ghost') : '',
      can.edit ? link('settlements/' + s.id + '/edit', 'Edit', 'pencil-simple', 'btn-secondary') : '',
      btn('print', 'Print', 'printer', 'btn-secondary'),
      can.apply ? btn('apply', 'Apply to ' + (receipt ? 'invoices' : 'bills'), 'arrows-left-right', '') : '',
      can.post ? btn('post', 'Post ' + word, 'check-circle', '') : '',
    ].join('');

    // ── the voucher itself ────────────────────────────────────────────
    const kv = (k, v) => (v ? '<tr><td class="small muted" style="padding-left:0">' + esc(k) + '</td><td class="n" style="text-align:left;min-width:0;width:auto">' + v + '</td></tr>' : '');
    const party = '<div><div class="small muted">' + (receipt ? 'Received from' : 'Pay to') + '</div>'
      + '<div style="font-weight:700;font-size:15px">' + esc(c.name) + '</div>'
      + (c.address ? '<div class="small">' + esc(c.address).replace(/\n/g, '<br>') + '</div>' : '')
      + (c.tin ? '<div class="small">TIN ' + esc(c.tin) + '</div>' : '') + '</div>';
    const facts = '<table class="stmt" style="width:auto"><tbody>'
      + kv((receipt ? 'Receipt' : 'Voucher') + ' number', '<b class="mono">' + esc(s.settle_no || 'Draft') + '</b>')
      + kv('Date', esc(fmtDay(s.settle_date, 'long')))
      + kv(receipt ? 'OR number' : 'Cheque number', esc(s.reference || ''))
      + kv(receipt ? 'Deposited to' : 'Paid from', d.cash_account
        ? esc(d.cash_account.code + ' ' + d.cash_account.name) + (d.cash_account.bank ? '<div class="small muted">' + esc(d.cash_account.bank) + '</div>' : '') : '')
      + '</tbody></table>';

    const allocs = d.allocations || [];
    const rows = allocs.map((a) => '<tr class="l-line"><td>' + esc(a.type_label) + ' <span class="code">' + esc(a.doc_no) + '</span>'
      + '<span class="sub">' + esc(fmtDay(a.doc_date) + (a.due_date ? ' · due ' + fmtDay(a.due_date) : '')) + '</span></td>'
      + '<td class="n">' + amt(a.total_cents) + '</td><td class="n">' + amt(a.amount_cents) + '</td></tr>').join('');
    const sum = (label, v, cls) => '<tr class="' + (cls || 'l-computed') + '"><td colspan="2" style="text-align:right">' + esc(label) + '</td><td class="n">' + amt(v) + '</td></tr>';
    let summary = '';
    if (s.withholding_cents) {
      summary += sum(receipt ? 'Tax withheld by the customer (creditable)' : 'Tax withheld from the supplier', s.withholding_cents);
      summary += sum(receipt ? 'Cash received' : 'Cash paid', s.amount_cents, 'l-grand');
      summary += sum('Settled in all', s.total_cents, 'l-total');
    } else {
      summary += sum(receipt ? 'Cash received' : 'Cash paid', s.amount_cents, 'l-grand');
    }
    if (s.unapplied_cents) summary += sum('Not applied to any ' + (receipt ? 'invoice' : 'bill') + ' yet', s.unapplied_cents, 'l-total');

    const people = [['Prepared by', s.created_by_name], ['Approved by', s.posted_by_name], [receipt ? 'Received by' : 'Received the payment', '']];
    qs('[data-sheet]', root).innerHTML = sheetHead(d.letterhead, receipt ? 'Official Receipt' : 'Payment Voucher', [s.settle_no || 'Draft — not yet posted'])
      + '<div class="grid-2" style="margin-bottom:var(--s5);align-items:start">' + party + '<div style="justify-self:end">' + facts + '</div></div>'
      + '<div class="voucher-amount"><div><div class="xs muted">' + (receipt ? 'Amount received' : 'Amount paid') + '</div>'
      + '<div class="va-figure">' + esc(money(s.amount_cents)) + '</div></div>'
      + '<div class="grow"><div class="xs muted">In words</div><div class="va-words">' + esc(d.amount_in_words || '') + ' only</div></div></div>'
      + (s.description ? '<p>' + esc(s.description) + '</p>' : '')
      + '<div class="table-wrap"><table class="stmt">'
      + (rows ? '<thead><tr><th class="l">' + (receipt ? 'Invoice settled' : 'Bill settled') + '</th><th>Its total</th><th>Applied</th></tr></thead>' : '')
      + '<tbody>' + rows + summary + '</tbody></table></div>'
      + '<p class="report-foot">' + esc('Amounts in ' + ((d.letterhead && d.letterhead.currency) || 'PHP') + '.') + '</p>'
      + '<footer class="report-sign">' + people.map(([r, n]) => '<div><div class="sg-role">' + esc(r) + ':</div><div class="sg-line"></div><div class="sg-name">' + esc(n || '') + '</div></div>').join('') + '</footer>'
      + (d.letterhead && d.letterhead.printed_by ? '<div class="report-printed">Printed by ' + esc(d.letterhead.printed_by) + ' on ' + esc(fmtDate(d.letterhead.printed_at)) + '</div>' : '');

    // ── what it settled ───────────────────────────────────────────────
    qs('[data-alloc-card]', root).hidden = !allocs.length;
    qs('[data-alloc-col]', root).textContent = receipt ? 'Invoice' : 'Bill';
    qs('[data-alloc-sub]', root).textContent = allocs.length ? money(allocs.reduce((n, x) => n + x.amount_cents, 0)) + ' applied' : '';
    qs('[data-allocs]', root).innerHTML = allocs.map((a) => '<tr><td><a class="code" href="documents/' + a.document_id + '">' + esc(a.doc_no) + '</a> <span class="small muted">'
      + esc(a.type_label) + '</span></td><td class="nowrap">' + esc(fmtDay(a.doc_date)) + '</td><td class="nowrap small">' + esc(a.due_date ? fmtDay(a.due_date) : '') + '</td>'
      + '<td class="num">' + money(a.total_cents) + '</td><td class="num">' + money(a.amount_cents) + '</td>'
      + '<td class="num">' + (can.remove_allocation && s.status === 'posted'
        ? '<button class="btn btn-ghost btn-sm" type="button" data-remove="' + a.id + '" data-amount="' + a.amount_cents + '">Remove</button>' : '') + '</td></tr>').join('');

    // ── in the books ──────────────────────────────────────────────────
    const fact = (k, v) => '<div><div class="k">' + esc(k) + '</div><div class="v">' + (v || '<span class="faint">—</span>') + '</div></div>';
    qs('[data-books]', root).innerHTML =
        fact('Journal', d.journal ? '<a href="journals/' + d.journal.id + '"><span class="mono">' + esc(d.journal.journal_no) + '</span></a> <span class="small muted">'
          + esc(fmtDay(d.journal.entry_date)) + '</span>' : '<span class="faint">Written when it posts</span>')
      + fact('Book', d.journal ? (receipt ? 'Cash receipts' : 'Cash disbursements') : '')
      + fact(receipt ? 'Cash in' : 'Cash out', d.cash_account ? esc(d.cash_account.code + ' ' + d.cash_account.name) : '')
      + (s.withholding_cents ? fact(receipt ? 'Creditable withholding tax' : 'Withholding tax payable', money(s.withholding_cents)) : '')
      + (d.reversal ? fact('Reversed by', '<a href="journals/' + d.reversal.id + '"><span class="mono">' + esc(d.reversal.journal_no) + '</span></a>') : '')
      + fact('Bank statement', d.bank_matched ? 'Matched' : '<span class="faint">Not matched yet</span>')
      + fact('Prepared by', esc(s.created_by_name || '') + ' <span class="small muted">' + esc(fmtDate(s.created_at, 'short')) + '</span>')
      + fact('Posted by', s.posted_by_name ? esc(s.posted_by_name) + ' <span class="small muted">' + esc(fmtDate(s.posted_at, 'short')) + '</span>' : '');

    qs('[data-trail]', root).innerHTML = (d.trail || []).length ? d.trail.map((t) => {
      const x = t.detail || {};
      const step = t.action === 'allocation.remove' ? 'Allocation removed' : (STEP[t.action.split('.').pop()] || t.action);
      return '<li class="is-done"><div class="tl-title">' + esc(step) + (x.settle_no && t.action.endsWith('.post') ? ' as ' + esc(x.settle_no) : '') + '</div>'
        + '<div class="tl-time">' + esc(t.who) + ' · ' + esc(fmtDate(t.at)) + '</div>'
        + (x.reason ? '<div class="small">“' + esc(x.reason) + '”</div>' : '')
        + (x.reversal_no ? '<div class="small">Reversed by ' + esc(x.reversal_no) + '</div>' : '') + '</li>';
    }).join('') : '<li class="muted">Nothing recorded.</li>';
  };

  const reload = async () => { d = await api.get('/settlements/' + id, null, { signal: ctx.signal }); paint(); };

  // ── actions ─────────────────────────────────────────────────────────
  const cancel = () => {
    const s = d.settlement;
    const today = todayYmd();
    const m = openModal({
      title: 'Cancel ' + (s.settle_no || 'this ' + s.kind_label.toLowerCase()), size: 'sm',
      body: '<form class="stack" data-f novalidate><div class="alert alert-err" data-banner hidden></div>'
        + '<p class="muted mb-0">Its entry is reversed by a new one dated below, what it settled opens again, and the ' + esc(s.kind_label.toLowerCase())
        + ' is marked cancelled. Both entries stay on record.</p>'
        + '<div class="field"><label class="label" for="sc-date">Date of the reversal</label><input class="input" type="date" id="sc-date" name="date" value="'
        + (today < s.settle_date ? s.settle_date : today) + '" min="' + s.settle_date + '">'
        + '<div class="hint">On or after ' + esc(fmtDay(s.settle_date)) + ', in an open month.</div><div class="error" data-error-for="date"></div></div>'
        + '<div class="field"><label class="label" for="sc-reason">Why</label><textarea class="textarea" id="sc-reason" name="reason" maxlength="300" placeholder="Cheque bounced, keyed twice…"></textarea>'
        + '<div class="error" data-error-for="reason"></div></div>'
        + '<div class="row gap-2" style="justify-content:flex-end"><button class="btn btn-secondary" type="button" data-x>Keep it</button><button class="btn btn-danger" type="submit">Cancel it</button></div></form>',
    });
    const f = qs('[data-f]', m.el);
    qs('[data-x]', f).addEventListener('click', () => m.close());
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErrors(f);
      const b = qs('button[type="submit"]', f);
      busy(b, true);
      try {
        d = await api.post('/settlements/' + s.id + '/cancel', { date: f.elements.date.value, reason: f.elements.reason.value.trim() });
        m.close();
        toast(s.kind_label + ' ' + (s.settle_no || '') + ' cancelled.');
        paint();
      } catch (err) { formError(f, err); } finally { busy(b, false); }
    });
  };

  const apply = async () => {
    const s = d.settlement;
    const receipt = s.kind === 'receipt';
    let items;
    try { items = await api.get('/documents/open-items', { side: s.ledger_side, contact_id: s.contact_id }); } catch (err) { toast(err.message, { kind: 'error' }); return; }
    const targets = items.targets || [];
    if (!targets.length) { toast(d.contact.name + ' has no open ' + (receipt ? 'invoices' : 'bills') + ' to apply it to.', { kind: 'info' }); return; }
    let left = s.unapplied_cents;
    const pre = targets.map((t) => { const a = Math.min(left, t.open_cents); left -= a; return a; });
    const m = openModal({
      title: 'Apply ' + (s.settle_no || 'this ' + s.kind_label.toLowerCase()), size: 'lg',
      body: '<form class="stack" data-f novalidate><div class="alert alert-err" data-banner hidden></div>'
        + '<p class="muted mb-0">' + money(s.unapplied_cents) + ' is not applied yet. Say how much goes to each open ' + (receipt ? 'invoice' : 'bill') + '; the oldest are filled first.</p>'
        + '<div class="table-wrap"><table class="je-lines"><thead><tr><th>Number</th><th>Date</th><th>Due</th><th class="amt">Open</th><th class="amt">Apply</th></tr></thead><tbody>'
        + targets.map((t, i) => '<tr><td class="code">' + esc(t.doc_no) + '</td><td class="nowrap">' + esc(fmtDay(t.doc_date)) + '</td><td class="nowrap">' + esc(t.due_date ? fmtDay(t.due_date) : '') + '</td>'
          + '<td class="money">' + money(t.open_cents) + '</td><td class="amt"><input class="input" inputmode="decimal" data-doc="' + t.id + '" value="'
          + (pre[i] ? (pre[i] / 100).toFixed(2) : '') + '" aria-label="Apply to ' + esc(t.doc_no) + '"></td></tr>').join('')
        + '</tbody></table></div><div class="error" data-error-for="allocations"></div>'
        + '<div class="row gap-2" style="justify-content:flex-end"><button class="btn btn-secondary" type="button" data-x>Cancel</button><button class="btn" type="submit">Apply</button></div></form>',
    });
    const f = qs('[data-f]', m.el);
    qs('[data-x]', f).addEventListener('click', () => m.close());
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErrors(f);
      const allocations = [];
      let bad = false;
      f.querySelectorAll('input[data-doc]').forEach((el) => {
        const v = el.value.trim();
        if (!v) return;
        const cents = Math.round(Number(v.replace(/,/g, '')) * 100);
        if (!/^\d[\d,]*(\.\d{1,2})?$/.test(v) || !Number.isFinite(cents)) { bad = true; return; }
        if (cents > 0) allocations.push({ document_id: Number(el.dataset.doc), amount_cents: cents });
      });
      if (bad) { formError(f, { errors: { allocations: 'An amount is not a number.' } }); return; }
      const b = qs('button[type="submit"]', f);
      busy(b, true);
      try {
        d = await api.post('/settlements/' + s.id + '/apply', { allocations });
        m.close();
        toast('Applied.');
        paint();
      } catch (err) { formError(f, err); } finally { busy(b, false); }
    });
  };

  qs('[data-actions]', root).addEventListener('click', async (e) => {
    const b = e.target.closest('[data-do]');
    if (!b || !d) return;
    const s = d.settlement;
    const receipt = s.kind === 'receipt';
    const word = s.kind_label.toLowerCase();
    try {
      switch (b.dataset.do) {
        case 'print':
          window.print();
          return;
        case 'post': {
          const where = receipt ? 'cash receipts' : 'cash disbursements';
          const move = receipt
            ? money(s.amount_cents) + ' into ' + (d.cash_account ? d.cash_account.name : 'cash') + ', against ' + d.contact.name + '’s receivable'
            : money(s.amount_cents) + ' out of ' + (d.cash_account ? d.cash_account.name : 'cash') + ', against ' + d.contact.name + '’s payable';
          const ok = await confirmDialog({ title: 'Post this ' + word + '?', confirmLabel: 'Post ' + word,
            body: 'It takes the next number and goes into the ' + where + ' book: ' + move
              + (s.withholding_cents ? ', with ' + money(s.withholding_cents) + ' of withholding tax' : '') + '. After that it can only be undone by cancelling it.' });
          if (!ok) return;
          busy(b, true);
          d = await api.post('/settlements/' + s.id + '/post');
          toast(d.settlement.kind_label + ' ' + d.settlement.settle_no + ' posted.');
          break;
        }
        case 'delete':
          if (!(await confirmDialog({ title: 'Delete this draft?', body: 'It was never posted and has no number, so nothing is lost from the books. The audit log keeps a record.', confirmLabel: 'Delete draft', danger: true }))) return;
          busy(b, true);
          await api.del('/settlements/' + s.id);
          toast('Draft ' + word + ' deleted.');
          ctx.navigate(receipt ? '/receipts' : '/payments', { replace: true });
          return;
        case 'cancel':
          cancel();
          return;
        case 'apply':
          await apply();
          return;
        default:
          return;
      }
      paint();
    } catch (err) {
      toast(err.message, { kind: 'error' });
    } finally {
      busy(b, false);
    }
  });

  root.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-remove]');
    if (!b || !d) return;
    const reason = await promptDialog({ title: 'Remove this allocation', label: 'Why is ' + money(Number(b.dataset.amount)) + ' taken off?', multiline: true, maxlength: 300,
      required: true, confirmLabel: 'Remove', danger: true,
      hint: 'The document opens again by that much and this ' + d.settlement.kind_label.toLowerCase() + ' gets it back as unapplied. The ledger does not change.' });
    if (reason === null) return;
    try {
      await api.post('/allocations/' + b.dataset.remove + '/remove', { reason });
      toast('Allocation removed.');
      await reload();
    } catch (err) { toast(err.message, { kind: 'error' }); }
  });

  try {
    await reload();
  } catch (err) {
    if (ctx.signal.aborted) return;
    stateEl.innerHTML = emptyState(err.isNotFound ? 'magnifying-glass' : 'warning', err.isNotFound ? 'That receipt or payment does not exist' : 'Could not load it',
      err.isNotFound ? 'It may have been a draft that was deleted.' : err.message, '<a class="btn btn-secondary" href="receipts">Back to the receipts</a>');
    return;
  }
  stateEl.innerHTML = '';
  bodyEl.hidden = false;
  attachmentsPanel(qs('[data-attachments]', root), { owner: 'settlement', id, user: ctx.user });
}
