/**
 * document.js — one invoice, credit note, bill or debit note: the printable
 * document, what settled it, where it sits in the books, and what can be done
 *
 * GenericPOS Accounting · ES module · every role (the actions depend on the role)
 *
 *   /documents/{id}
 *
 * The server says what this person may do now (`can`); every action asks
 * first and is checked again on the server. Posting takes the number and
 * writes the journal; a posted document is never edited — it is cancelled
 * (its journal reversed) once nothing is applied to it.
 */

import { api } from './api.js';
import { money, fmtDay, fmtDate, todayYmd } from './store.js';
import { qs, esc, emptyState, statusBadge, confirmDialog, promptDialog, openModal, toast, busy, formError, clearErrors } from './ui.js';
import { setAdminTitle } from './chrome.js';
import { amt, sheetHead } from './report-kit.js';
import { attachmentsPanel } from './attachments.js';

const TITLES = { invoice: 'Invoice', credit_note: 'Credit Note', bill: 'Bill', debit_note: 'Debit Note' };
const STEP = { create: 'Prepared', update: 'Changed', post: 'Posted', cancel: 'Cancelled', delete: 'Deleted', apply: 'Applied' };

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

const qty = (q) => { const n = parseFloat(q); return Number.isFinite(n) ? String(n) : String(q); };

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
    const doc = d.document;
    const c = d.contact;
    const sales = doc.side === 'sales';
    const note = doc.is_note;
    const word = doc.type_label.toLowerCase();
    const no = doc.doc_no || 'Draft ' + word + ' #' + doc.id;

    qs('[data-crumb-list]', root).textContent = sales ? 'Invoices' : 'Bills';
    qs('[data-crumb-list]', root).setAttribute('href', sales ? 'invoices' : 'bills');
    qs('[data-crumb]', root).textContent = no;
    qs('[data-title]', root).innerHTML = '<span>' + esc(no) + '</span>' + badge(doc);
    ctx.setTitle(no);
    setAdminTitle(no);
    qs('[data-sub]', root).innerHTML = esc(doc.type_label) + ' · <a href="contacts/' + c.id + '">' + esc(c.name) + '</a> · ' + esc(fmtDay(doc.doc_date, 'long'))
      + (doc.due_date ? ' · due ' + esc(fmtDay(doc.due_date, 'long')) : '');

    // ── where it stands ─────────────────────────────────────────────
    if (doc.status === 'draft') {
      alertBox('<span data-icon="pencil-simple"></span><div>A draft: it has no number and is not in the books yet. It takes its number and reaches the ledger when an accountant posts it.'
        + (!d.can.post && ctx.user && ['accountant', 'admin'].includes(ctx.user.role) && doc.created_by === ctx.user.id ? ' You prepared it, so another accountant posts it.' : '') + '</div>', 'neutral');
    } else if (doc.status === 'cancelled') {
      alertBox('<span data-icon="prohibit"></span><div>Cancelled' + (d.reversal ? ' on ' + esc(fmtDay(d.reversal.entry_date, 'long')) + ': its journal was reversed by <a href="journals/' + d.reversal.id + '"><b class="mono">'
        + esc(d.reversal.journal_no) + '</b></a>' : '') + '. It stays on record with its number.</div>', 'neutral');
    } else if (note) {
      alertBox(doc.open_cents ? '<span data-icon="info"></span><div>' + money(doc.open_cents) + ' of this ' + word + ' is not applied yet. Apply it to an open ' + (sales ? 'invoice' : 'bill')
        + ', or use it in the next ' + (sales ? 'receipt' : 'payment') + '.</div>' : '', 'info');
    } else if (doc.state === 'paid') {
      alertBox('<span data-icon="check-circle"></span><div>Paid in full.</div>', 'ok');
    } else if (doc.state === 'overdue') {
      alertBox('<span data-icon="warning"></span><div>' + money(doc.open_cents) + ' is ' + doc.days_overdue + ' day' + (doc.days_overdue === 1 ? '' : 's') + ' past its due date.</div>', 'err');
    } else {
      alertBox('', '');
    }
    const warns = (d.warnings || []).slice();
    if (d.credit && d.credit.over && doc.status === 'draft') {
      warns.unshift('Posting this invoice takes ' + c.name + ' to ' + money(d.credit.after_cents) + ', over their credit limit of ' + money(d.credit.limit_cents) + '.');
    }
    const w = qs('[data-warnings]', root);
    w.hidden = !warns.length;
    w.innerHTML = warns.map((x) => '<span data-icon="warning"></span><div>' + esc(x) + '</div>').join('');

    // ── what this person may do ─────────────────────────────────────
    const can = d.can;
    const btn = (act, label, icon, cls) => '<button class="btn ' + cls + '" type="button" data-do="' + act + '"><span data-icon="' + icon + '" data-icon-size="18"></span>' + label + '</button>';
    const link = (href, label, icon, cls) => '<a class="btn ' + cls + '" href="' + href + '"><span data-icon="' + icon + '" data-icon-size="18"></span>' + label + '</a>';
    qs('[data-actions]', root).innerHTML = [
      can.delete ? btn('delete', 'Delete draft', 'trash', 'btn-ghost') : '',
      can.cancel ? btn('cancel', 'Cancel ' + word, 'prohibit', 'btn-ghost') : '',
      can.edit ? link('documents/' + doc.id + '/edit', 'Edit', 'pencil-simple', 'btn-secondary') : '',
      btn('print', 'Print', 'printer', 'btn-secondary'),
      can.note ? link('documents/new?type=' + (sales ? 'credit_note' : 'debit_note') + '&contact_id=' + c.id + '&related_id=' + doc.id, 'New ' + (sales ? 'credit' : 'debit') + ' note', 'arrow-counter-clockwise', 'btn-secondary') : '',
      can.settle ? link('settlements/new?kind=' + (sales ? 'receipt' : 'payment') + '&contact_id=' + c.id, sales ? 'Record receipt' : 'Record payment', sales ? 'coins' : 'money', 'btn-secondary') : '',
      can.apply ? btn('apply', 'Apply to ' + (sales ? 'invoices' : 'bills'), 'arrows-left-right', '') : '',
      can.post ? btn('post', 'Post ' + word, 'check-circle', '') : '',
    ].join('');

    // ── the document itself ─────────────────────────────────────────
    const bd = d.breakdown;
    const kv = (k, v) => v ? '<tr><td class="small muted" style="padding-left:0">' + esc(k) + '</td><td class="n" style="text-align:left;min-width:0;width:auto">' + v + '</td></tr>' : '';
    const party = '<div><div class="small muted">' + (sales ? (note ? 'Credited to' : 'Bill to') : (note ? 'Debited to' : 'Supplier')) + '</div>'
      + '<div style="font-weight:700;font-size:15px">' + esc(c.name) + '</div>'
      + (c.address ? '<div class="small">' + esc(c.address).replace(/\n/g, '<br>') + '</div>' : '')
      + (c.tin ? '<div class="small">TIN ' + esc(c.tin) + '</div>' : '')
      + (c.contact_person ? '<div class="small">Attention: ' + esc(c.contact_person) + '</div>' : '') + '</div>';
    const facts = '<table class="stmt" style="width:auto"><tbody>'
      + kv(TITLES[doc.doc_type] + ' number', '<b class="mono">' + esc(doc.doc_no || 'Draft') + '</b>')
      + kv('Date', esc(fmtDay(doc.doc_date, 'long')))
      + kv('Due date', doc.due_date ? esc(fmtDay(doc.due_date, 'long')) : '')
      + kv('Terms', !note && c.terms_days !== null ? esc(c.terms_days === 0 ? 'Cash' : c.terms_days + ' days') : '')
      + kv(doc.doc_type === 'bill' ? 'Supplier\'s invoice' : 'Reference', esc(doc.reference || ''))
      + kv('Corrects', d.related ? esc(d.related.doc_no) + ' of ' + esc(fmtDay(d.related.doc_date)) : '')
      + '</tbody></table>';

    const lines = d.lines.map((l) => {
      const sub = [l.account_code + ' ' + l.account_name, l.vat_mode === 'exempt' && bd.vat_cents ? 'VAT-exempt' : '', l.vat_mode === 'zero_rated' ? 'Zero-rated' : '', l.department ? 'Dept ' + l.department : '']
        .filter(Boolean).join(' · ');
      return '<tr class="l-line"><td>' + esc(l.description || l.account_name) + '<span class="sub">' + esc(sub) + '</span></td>'
        + '<td class="n">' + esc(qty(l.quantity)) + '</td><td class="n">' + amt(l.unit_price_cents) + '</td><td class="n">' + amt(l.amount_cents) + '</td></tr>';
    }).join('');
    const sum = (label, v, cls) => '<tr class="' + (cls || 'l-computed') + '"><td colspan="3" style="text-align:right">' + esc(label) + '</td><td class="n">' + amt(v) + '</td></tr>';
    const s = sales ? 'sales' : 'purchases';
    let summary = '';
    if (bd.vat_cents || bd.vatable_cents) {
      summary += sum('VATable ' + s, bd.vatable_cents);
      if (bd.exempt_cents) summary += sum('VAT-exempt ' + s, bd.exempt_cents);
      if (bd.zero_rated_cents) summary += sum('Zero-rated ' + s, bd.zero_rated_cents);
      summary += sum('VAT (' + bd.rate_pct + ' %)', bd.vat_cents);
    } else if (bd.exempt_cents || bd.zero_rated_cents) {
      if (bd.exempt_cents) summary += sum('VAT-exempt ' + s, bd.exempt_cents);
      if (bd.zero_rated_cents) summary += sum('Zero-rated ' + s, bd.zero_rated_cents);
    }
    summary += sum(note ? 'Total' : doc.doc_type === 'invoice' ? 'Total amount due' : 'Total', doc.total_cents, 'l-grand');
    if (doc.status === 'posted' && doc.applied_cents) {
      summary += note ? sum('Applied', doc.applied_cents) + sum('Not yet applied', doc.open_cents, 'l-total')
        : sum('Less payments and credits', -doc.applied_cents) + sum('Balance due', doc.open_cents, 'l-total');
    }

    const people = [['Prepared by', doc.created_by_name], ['Approved by', doc.posted_by_name], [sales ? 'Received by' : 'Checked by', '']];
    qs('[data-sheet]', root).innerHTML = sheetHead(d.letterhead, TITLES[doc.doc_type], [doc.doc_no ? doc.doc_no : 'Draft — not yet posted'])
      + '<div class="grid-2" style="margin-bottom:var(--s5);align-items:start">' + party + '<div style="justify-self:end">' + facts + '</div></div>'
      + (doc.description ? '<p>' + esc(doc.description) + '</p>' : '')
      + '<div class="table-wrap"><table class="stmt"><thead><tr><th class="l">Description</th><th>Quantity</th><th>Unit price</th><th>Amount</th></tr></thead><tbody>'
      + lines + summary + '</tbody></table></div>'
      + '<p class="report-foot">' + esc((bd.vat_cents ? (doc.prices_include_tax ? 'Line amounts include VAT.' : 'Line amounts are before VAT.') + ' ' : '')
        + 'Amounts in ' + ((d.letterhead && d.letterhead.currency) || 'PHP') + '.') + '</p>'
      + '<footer class="report-sign">' + people.map(([r, n]) => '<div><div class="sg-role">' + esc(r) + ':</div><div class="sg-line"></div><div class="sg-name">' + esc(n || '') + '</div></div>').join('') + '</footer>'
      + (d.letterhead && d.letterhead.printed_by ? '<div class="report-printed">Printed by ' + esc(d.letterhead.printed_by) + ' on ' + esc(fmtDate(d.letterhead.printed_at)) + '</div>' : '');

    // ── what settled it, or what it settled ─────────────────────────
    const rows = note ? d.applied_to : d.allocations;
    qs('[data-alloc-card]', root).hidden = !rows.length;
    qs('[data-alloc-title]', root).textContent = note ? 'Applied to' : 'Payments and credits';
    qs('[data-alloc-col]', root).textContent = note ? (sales ? 'Invoice' : 'Bill') : 'From';
    qs('[data-alloc-sub]', root).textContent = rows.length ? money(rows.reduce((n, x) => n + x.amount_cents, 0)) + ' in all' : '';
    const rm = (a) => (can.remove_allocation && doc.status === 'posted'
      ? '<button class="btn btn-ghost btn-sm" type="button" data-remove="' + a.id + '" data-amount="' + a.amount_cents + '">Remove</button>' : '');
    qs('[data-allocs]', root).innerHTML = rows.map((a) => note
      ? '<tr><td><a class="code" href="documents/' + a.document_id + '">' + esc(a.doc_no) + '</a></td><td class="nowrap">' + esc(fmtDay(a.doc_date)) + '</td><td class="small muted">'
        + esc(fmtDate(a.created_at, 'date')) + '</td><td class="num">' + money(a.amount_cents) + '</td><td class="num">' + rm(a) + '</td></tr>'
      : '<tr><td>' + esc(a.source_label) + ' <a class="code" href="' + (a.source === 'note' ? 'documents/' : 'settlements/') + a.source_id + '">' + esc(a.source_no || '#' + a.source_id) + '</a></td>'
        + '<td class="nowrap">' + esc(fmtDay(a.date)) + '</td><td class="small">' + esc(a.reference || '') + '</td><td class="num">' + money(a.amount_cents) + '</td><td class="num">' + rm(a) + '</td></tr>').join('');

    // ── in the books ────────────────────────────────────────────────
    const fact = (k, v) => '<div><div class="k">' + esc(k) + '</div><div class="v">' + (v || '<span class="faint">—</span>') + '</div></div>';
    qs('[data-books]', root).innerHTML =
        fact('Journal', d.journal ? '<a href="journals/' + d.journal.id + '"><span class="mono">' + esc(d.journal.journal_no) + '</span></a> <span class="small muted">'
          + esc(fmtDay(d.journal.entry_date)) + '</span>' : '<span class="faint">Written when it posts</span>')
      + fact('Book', d.journal ? (sales ? 'Sales journal' : 'Purchase journal') : '')
      + (d.reversal ? fact('Reversed by', '<a href="journals/' + d.reversal.id + '"><span class="mono">' + esc(d.reversal.journal_no) + '</span></a>') : '')
      + (d.related ? fact('Corrects', '<a href="documents/' + d.related.id + '"><span class="mono">' + esc(d.related.doc_no) + '</span></a>') : '')
      + (d.notes.length ? fact((sales ? 'Credit' : 'Debit') + ' notes against it', d.notes.map((n) => '<a class="mono" href="documents/' + n.id + '">' + esc(n.doc_no || 'Draft #' + n.id) + '</a>').join(', ')) : '')
      + fact('Prepared by', esc(doc.created_by_name || '') + ' <span class="small muted">' + esc(fmtDate(doc.created_at, 'short')) + '</span>')
      + fact('Posted by', doc.posted_by_name ? esc(doc.posted_by_name) + ' <span class="small muted">' + esc(fmtDate(doc.posted_at, 'short')) + '</span>' : '');

    qs('[data-trail]', root).innerHTML = d.trail.length ? d.trail.map((t) => {
      const x = t.detail || {};
      const step = t.action === 'allocation.remove' ? 'Allocation removed' : (STEP[t.action.split('.').pop()] || t.action);
      return '<li class="is-done"><div class="tl-title">' + esc(step) + (x.doc_no && t.action.endsWith('.post') ? ' as ' + esc(x.doc_no) : '') + '</div>'
        + '<div class="tl-time">' + esc(t.who) + ' · ' + esc(fmtDate(t.at)) + '</div>'
        + (x.applied_to ? '<div class="small">Applied ' + money(x.applied_cents) + ' to ' + esc(x.applied_to) + '</div>' : '')
        + (x.to ? '<div class="small">To ' + esc(x.to) + '</div>' : '')
        + (x.amount_cents && t.action === 'allocation.remove' ? '<div class="small">' + money(x.amount_cents) + (x.from ? ' from ' + esc(x.from) : '') + '</div>' : '')
        + (x.reason ? '<div class="small">“' + esc(x.reason) + '”</div>' : '')
        + (x.reversal_no ? '<div class="small">Reversed by ' + esc(x.reversal_no) + '</div>' : '') + '</li>';
    }).join('') : '<li class="muted">Nothing recorded.</li>';
  };

  const reload = async () => { d = await api.get('/documents/' + id, null, { signal: ctx.signal }); paint(); };

  // ── actions ─────────────────────────────────────────────────────────
  const cancel = () => {
    const doc = d.document;
    const today = todayYmd();
    const m = openModal({
      title: 'Cancel ' + doc.doc_no, size: 'sm',
      body: '<form class="stack" data-f novalidate><div class="alert alert-err" data-banner hidden></div>'
        + '<p class="muted mb-0">Its journal is reversed by a new entry dated below, and the ' + esc(doc.type_label.toLowerCase()) + ' is marked cancelled. Both stay on record.</p>'
        + '<div class="field"><label class="label" for="dc-date">Date of the reversal</label><input class="input" type="date" id="dc-date" name="date" value="' + (today < doc.doc_date ? doc.doc_date : today) + '" min="' + doc.doc_date + '">'
        + '<div class="hint">On or after ' + esc(fmtDay(doc.doc_date)) + ', in an open month.</div><div class="error" data-error-for="date"></div></div>'
        + '<div class="field"><label class="label" for="dc-reason">Why</label><textarea class="textarea" id="dc-reason" name="reason" maxlength="300" placeholder="Keyed twice, wrong customer…"></textarea><div class="error" data-error-for="reason"></div></div>'
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
        d = await api.post('/documents/' + doc.id + '/cancel', { date: f.elements.date.value, reason: f.elements.reason.value.trim() });
        m.close();
        toast(doc.type_label + ' ' + doc.doc_no + ' cancelled.');
        paint();
      } catch (err) { formError(f, err); } finally { busy(b, false); }
    });
  };

  const apply = async () => {
    const doc = d.document;
    const sales = doc.side === 'sales';
    let items;
    try { items = await api.get('/documents/open-items', { side: doc.ledger_side, contact_id: doc.contact_id }); } catch (err) { toast(err.message, { kind: 'error' }); return; }
    const targets = items.targets || [];
    if (!targets.length) { toast(d.contact.name + ' has no open ' + (sales ? 'invoices' : 'bills') + ' to apply it to.', { kind: 'info' }); return; }
    let left = doc.open_cents;
    const pre = targets.map((t) => { const a = Math.min(left, t.open_cents); left -= a; return a; });
    const m = openModal({
      title: 'Apply ' + doc.doc_no, size: 'lg',
      body: '<form class="stack" data-f novalidate><div class="alert alert-err" data-banner hidden></div>'
        + '<p class="muted mb-0">' + money(doc.open_cents) + ' is not applied yet. Say how much goes to each open ' + (sales ? 'invoice' : 'bill') + '; the oldest are filled first.</p>'
        + '<div class="table-wrap"><table class="je-lines"><thead><tr><th>Number</th><th>Date</th><th>Due</th><th class="amt">Open</th><th class="amt">Apply</th></tr></thead><tbody>'
        + targets.map((t, i) => '<tr><td class="code">' + esc(t.doc_no) + '</td><td class="nowrap">' + esc(fmtDay(t.doc_date)) + '</td><td class="nowrap">' + esc(t.due_date ? fmtDay(t.due_date) : '') + '</td>'
          + '<td class="money">' + money(t.open_cents) + '</td><td class="amt"><input class="input" inputmode="decimal" data-doc="' + t.id + '" value="' + (pre[i] ? (pre[i] / 100).toFixed(2) : '') + '" aria-label="Apply to ' + esc(t.doc_no) + '"></td></tr>').join('')
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
        d = await api.post('/documents/' + doc.id + '/apply', { allocations });
        m.close();
        toast(doc.doc_no + ' applied.');
        paint();
      } catch (err) { formError(f, err); } finally { busy(b, false); }
    });
  };

  qs('[data-actions]', root).addEventListener('click', async (e) => {
    const b = e.target.closest('[data-do]');
    if (!b || !d) return;
    const doc = d.document;
    const word = doc.type_label.toLowerCase();
    try {
      switch (b.dataset.do) {
        case 'print':
          window.print();
          return;
        case 'post': {
          const sales = doc.side === 'sales';
          const acct = sales ? 'accounts receivable' : 'accounts payable';
          const where = sales ? 'the sales journal' : 'the purchase journal';
          const over = d.credit && d.credit.over
            ? '<div class="alert alert-warn mt-3"><span data-icon="warning"></span><div>This takes ' + esc(d.contact.name) + ' to ' + money(d.credit.after_cents) + ', over their credit limit of '
              + money(d.credit.limit_cents) + '. You can still post it.</div></div>' : '';
          const ok = await confirmDialog({ title: 'Post this ' + word + '?', confirmLabel: 'Post ' + word,
            bodyHtml: '<p>It takes the next number and goes into ' + where + ': ' + esc(acct) + ' ' + (doc.is_note === sales ? 'is credited ' : 'is debited ') + money(doc.total_cents)
              + ' for ' + esc(d.contact.name) + (doc.vat_cents ? ', with ' + money(doc.vat_cents) + ' of VAT' : '') + '. After that it can only be undone by cancelling it.</p>' + over });
          if (!ok) return;
          busy(b, true);
          d = await api.post('/documents/' + doc.id + '/post');
          toast(d.document.type_label + ' ' + d.document.doc_no + ' posted.');
          break;
        }
        case 'delete':
          if (!(await confirmDialog({ title: 'Delete this draft?', body: 'It was never posted and has no number, so nothing is lost from the books. The audit log keeps a record.', confirmLabel: 'Delete draft', danger: true }))) return;
          busy(b, true);
          await api.del('/documents/' + doc.id);
          toast('Draft ' + word + ' deleted.');
          ctx.navigate(doc.side === 'sales' ? '/invoices' : '/bills', { replace: true });
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
      required: true, confirmLabel: 'Remove', danger: true, hint: 'The document opens again by that much, and the receipt, payment or note gets it back as unapplied. The ledger does not change.' });
    if (reason === null) return;
    try {
      const r = await api.post('/allocations/' + b.dataset.remove + '/remove', { reason });
      toast('Allocation removed.');
      void r;
      await reload();
    } catch (err) { toast(err.message, { kind: 'error' }); }
  });

  try {
    await reload();
  } catch (err) {
    if (ctx.signal.aborted) return;
    stateEl.innerHTML = emptyState(err.isNotFound ? 'magnifying-glass' : 'warning', err.isNotFound ? 'That document does not exist' : 'Could not load it', err.isNotFound ? 'It may have been a draft that was deleted.' : err.message,
      '<a class="btn btn-secondary" href="invoices">Back to the invoices</a>');
    return;
  }
  stateEl.innerHTML = '';
  bodyEl.hidden = false;
  attachmentsPanel(qs('[data-attachments]', root), { owner: 'document', id, user: ctx.user });
}
