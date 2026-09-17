/**
 * contact.js — one customer or supplier
 *
 * GenericPOS Accounting · ES module · every role (the buttons depend on the role)
 *
 *   /contacts/{id}
 *
 * What they owe or are owed (from the ledger), their open documents with
 * days overdue, money received or paid but not yet applied, drafts waiting,
 * and the latest posted lines on the receivables or payables accounts. From
 * here: a new invoice or bill, a receipt or payment, the statement of account.
 */

import { api } from './api.js';
import { money, fmtDay } from './store.js';
import { qs, esc, emptyState, statusBadge, confirmDialog, toast } from './ui.js';
import { setAdminTitle } from './chrome.js';
import { openContactForm } from './contacts.js';

const DOC_HREF = { invoice: 'documents/', credit_note: 'documents/', bill: 'documents/', debit_note: 'documents/', receipt: 'settlements/', payment: 'settlements/' };

export async function mount(root, ctx) {
  const id = parseInt(ctx.params.id, 10) || 0;
  const stateEl = qs('[data-state]', root);
  const body = qs('[data-body]', root);
  let d = null;

  const paint = () => {
    const c = d.contact;
    const both = c.is_customer && c.is_supplier;
    const cust = c.is_customer;
    qs('[data-crumb-list]', root).textContent = cust ? 'Customers' : 'Suppliers';
    qs('[data-crumb-list]', root).setAttribute('href', cust ? 'customers' : 'suppliers');
    qs('[data-crumb]', root).textContent = c.name;
    qs('[data-title]', root).innerHTML = '<span>' + esc(c.name) + '</span>' + (c.is_active ? '' : statusBadge('inactive'))
      + (both ? '<span class="badge badge-info">Customer and supplier</span>' : '');
    ctx.setTitle(c.name);
    setAdminTitle(c.name);
    qs('[data-sub]', root).textContent = [c.code, c.tin ? 'TIN ' + c.tin : '', c.terms_days === null ? '' : c.terms_days === 0 ? 'Cash terms' : c.terms_days + '-day terms']
      .filter(Boolean).join(' · ');

    const b = qs('[data-banner]', root);
    b.hidden = c.is_active;
    if (!c.is_active) b.innerHTML = '<span data-icon="prohibit"></span><div>Inactive: no new invoices, bills, receipts or payments can be posted for them. Their history and balance stay on the books.</div>';

    // ── what can be done ─────────────────────────────────────────────
    const can = d.can;
    const a = (href, icon, label, cls) => '<a class="btn ' + (cls || 'btn-secondary') + '" href="' + href + '"><span data-icon="' + icon + '" data-icon-size="18"></span>' + label + '</a>';
    const btn = (act, icon, label, cls) => '<button class="btn ' + (cls || 'btn-ghost') + '" type="button" data-do="' + act + '"><span data-icon="' + icon + '" data-icon-size="18"></span>' + label + '</button>';
    const acts = [];
    if (can.delete) acts.push(btn('delete', 'trash', 'Delete'));
    if (can.activate) acts.push(btn(c.is_active ? 'deactivate' : 'activate', c.is_active ? 'prohibit' : 'check-circle', c.is_active ? 'Deactivate' : 'Reactivate'));
    if (can.edit) acts.push(btn('edit', 'pencil-simple', 'Edit', 'btn-secondary'));
    if (c.is_customer || d.sides.ar) acts.push(a('reports/customer-statement?contact_id=' + c.id + (both ? '&side=ar' : ''), 'file-text', 'Statement of account'));
    if (c.is_supplier && !c.is_customer) acts.push(a('reports/customer-statement?contact_id=' + c.id + '&side=ap', 'file-text', 'Statement of account'));
    if (can.payment) acts.push(a('settlements/new?kind=payment&contact_id=' + c.id, 'money', 'Record payment'));
    if (can.bill) acts.push(a('documents/new?type=bill&contact_id=' + c.id, 'article', 'New bill'));
    if (can.receipt) acts.push(a('settlements/new?kind=receipt&contact_id=' + c.id, 'coins', 'Record receipt'));
    if (can.invoice) acts.push(a('documents/new?type=invoice&contact_id=' + c.id, 'receipt', 'New invoice', ''));
    qs('[data-actions]', root).innerHTML = acts.join('');

    // ── the figures ──────────────────────────────────────────────────
    const stat = (label, v, sub, cls) => '<div class="stat"><div class="stat-label">' + esc(label) + '</div><div class="stat-value' + (cls ? ' ' + cls : '') + '">' + money(v)
      + '</div><div class="stat-sub">' + esc(sub) + '</div></div>';
    const s = [];
    if (d.sides.ar) {
      const x = d.sides.ar;
      s.push(stat(both ? 'They owe you' : 'Balance owed to you', x.balance_cents, 'Receivable per the ledger, today'));
      s.push(stat('Open invoices', x.open_cents, x.open_count + ' invoice' + (x.open_count === 1 ? '' : 's') + ' not fully paid'));
      s.push(stat('Overdue', x.overdue_cents, 'Past the due date', x.overdue_cents ? 'err-text' : ''));
      if (c.credit_limit_cents !== null) {
        const left = c.credit_limit_cents - x.balance_cents;
        s.push(stat('Credit left', left, 'Limit ' + money(c.credit_limit_cents), left < 0 ? 'err-text' : ''));
      }
    }
    if (d.sides.ap) {
      const x = d.sides.ap;
      s.push(stat(both ? 'You owe them' : 'Balance you owe', x.balance_cents, 'Payable per the ledger, today'));
      s.push(stat('Open bills', x.open_cents, x.open_count + ' bill' + (x.open_count === 1 ? '' : 's') + ' not fully paid'));
      s.push(stat('Overdue', x.overdue_cents, 'Past the due date', x.overdue_cents ? 'err-text' : ''));
    }
    qs('[data-stats]', root).innerHTML = s.join('');

    // ── the details ──────────────────────────────────────────────────
    const fact = (k, v, wide) => '<div' + (wide ? ' class="wide"' : '') + '><div class="k">' + esc(k) + '</div><div class="v">' + (v || '<span class="faint">—</span>') + '</div></div>';
    const acct = d.default_account;
    qs('[data-head]', root).innerHTML =
        fact('Code', '<span class="mono">' + esc(c.code) + '</span>')
      + fact('TIN', esc(c.tin || ''))
      + fact('Contact person', esc(c.contact_person || ''))
      + fact('Email', c.email ? '<a href="mailto:' + esc(c.email) + '">' + esc(c.email) + '</a>' : '')
      + fact('Phone', esc(c.phone || ''))
      + fact('Terms', c.terms_days === null ? '' : esc(c.terms_days === 0 ? 'Cash' : c.terms_days + ' days'))
      + (c.is_customer ? fact('Credit limit', c.credit_limit_cents === null ? 'No limit' : money(c.credit_limit_cents)) : '')
      + (c.is_supplier ? fact('Expanded withholding tax', c.ewt_rate_bp ? esc(String(c.ewt_rate_bp / 100)) + ' %' : 'None') : '')
      + fact('Default account', acct ? '<span class="code">' + esc(acct.code) + '</span> ' + esc(acct.name) : '')
      + fact('VAT-registered', c.vat_registered ? 'Yes' : 'No')
      + fact('Address', esc(c.address || '').replace(/\n/g, '<br>'), true)
      + (c.notes ? fact('Notes', esc(c.notes), true) : '');

    // ── open documents and unapplied money ───────────────────────────
    const docs = d.open_documents || [];
    const un = d.unapplied || [];
    const rows = docs.map((x) => {
      const badge = x.is_note ? statusBadge('partial', 'Unapplied') : x.days_overdue > 0 ? statusBadge('overdue', x.days_overdue + ' day' + (x.days_overdue === 1 ? '' : 's') + ' overdue')
        : x.due_date === d.today ? statusBadge('due', 'Due today') : statusBadge('not_due');
      return '<tr class="is-link" data-href="/documents/' + x.id + '"><td><span class="code">' + esc(x.doc_no) + '</span><div class="small muted">' + esc(x.type_label)
        + (x.reference ? ' · ' + esc(x.reference) : '') + '</div></td>'
        + '<td class="nowrap">' + esc(fmtDay(x.doc_date)) + '</td><td class="nowrap">' + (x.due_date ? esc(fmtDay(x.due_date)) : '') + '</td>'
        + '<td>' + badge + '</td><td class="num">' + money(x.total_cents) + '</td><td class="num">' + (x.is_note ? '−' : '') + money(x.open_cents) + '</td></tr>';
    }).concat(un.map((x) => '<tr class="is-link" data-href="/settlements/' + x.id + '"><td><span class="code">' + esc(x.settle_no) + '</span><div class="small muted">'
      + (x.kind === 'receipt' ? 'Receipt' : 'Payment') + (x.reference ? ' · ' + esc(x.reference) : '') + '</div></td>'
      + '<td class="nowrap">' + esc(fmtDay(x.settle_date)) + '</td><td></td><td>' + statusBadge('partial', 'Unapplied') + '</td>'
      + '<td class="num">' + money(x.total_cents) + '</td><td class="num">−' + money(x.unapplied_cents) + '</td></tr>'));
    qs('[data-open]', root).innerHTML = rows.length ? rows.join('')
      : '<tr><td colspan="6">' + emptyState('check-circle', 'Nothing open', 'Every posted document is fully paid or applied.') + '</td></tr>';
    qs('[data-open-count]', root).textContent = docs.length ? docs.length + ' open' + (un.length ? ' · ' + un.length + ' unapplied' : '') : '';

    // ── drafts ───────────────────────────────────────────────────────
    const drafts = d.drafts || [];
    qs('[data-drafts-card]', root).hidden = !drafts.length;
    qs('[data-drafts]', root).innerHTML = drafts.map((x) => '<tr class="is-link" data-href="/' + (x.what === 'document' ? 'documents/' : 'settlements/') + x.id + '">'
      + '<td>' + esc(x.type_label) + ' <span class="faint">#' + x.id + '</span> ' + statusBadge('draft') + '</td><td class="nowrap">' + esc(fmtDay(x.date)) + '</td>'
      + '<td class="num">' + money(x.total_cents) + '</td></tr>').join('');

    // ── activity ─────────────────────────────────────────────────────
    const act = d.activity || [];
    qs('[data-activity]', root).innerHTML = act.length ? act.map((l) => {
      const href = DOC_HREF[l.source] && l.source_id ? DOC_HREF[l.source] + l.source_id : 'journals/' + l.journal_id;
      return '<tr><td class="nowrap">' + esc(fmtDay(l.date)) + '</td><td><a class="code" href="journals/' + l.journal_id + '">' + esc(l.journal_no || '#' + l.journal_id) + '</a></td>'
        + '<td><a href="' + esc(href) + '">' + esc(l.description) + '</a>' + (both ? ' <span class="badge">' + (l.side === 'ar' ? 'Receivable' : 'Payable') + '</span>' : '')
        + (l.reference ? '<div class="small muted">' + esc(l.reference) + '</div>' : '') + '</td>'
        + '<td class="num">' + (l.debit_cents ? money(l.debit_cents) : '') + '</td><td class="num">' + (l.credit_cents ? money(l.credit_cents) : '') + '</td></tr>';
    }).join('') : '<tr><td colspan="5">' + emptyState('clock-counter-clockwise', 'No posted activity yet', 'Posted invoices, bills, receipts and payments appear here.') + '</td></tr>';
  };

  const load = async () => {
    d = await api.get('/contacts/' + id, null, { signal: ctx.signal });
    paint();
  };

  root.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-href]');
    if (tr && !e.target.closest('a')) ctx.navigate(tr.dataset.href);
  });

  qs('[data-actions]', root).addEventListener('click', async (e) => {
    const b = e.target.closest('[data-do]');
    if (!b || !d) return;
    const c = d.contact;
    try {
      switch (b.dataset.do) {
        case 'edit':
          openContactForm({ contact: c, onSaved: () => load().catch((err) => toast(err.message, { kind: 'error' })) });
          return;
        case 'deactivate': {
          const bal = (d.sides.ar ? d.sides.ar.balance_cents : 0) + (d.sides.ap ? d.sides.ap.balance_cents : 0);
          const ok = await confirmDialog({ title: 'Deactivate ' + c.name + '?', confirmLabel: 'Deactivate', danger: true,
            body: 'Nothing new can be posted for them until they are reactivated.' + (bal ? ' They still have a balance of ' + money(bal) + ', which stays on the books and in the reports.' : '')
              + (d.drafts.length ? ' Their ' + d.drafts.length + ' draft' + (d.drafts.length === 1 ? '' : 's') + ' cannot post while they are inactive.' : '') });
          if (!ok) return;
          d = await api.post('/contacts/' + c.id + '/active', { active: false });
          toast(c.name + ' is now inactive.');
          break;
        }
        case 'activate':
          d = await api.post('/contacts/' + c.id + '/active', { active: true });
          toast(c.name + ' is active again.');
          break;
        case 'delete':
          if (!(await confirmDialog({ title: 'Delete ' + c.name + '?', body: 'Nothing has ever used them, so they can go. This cannot be undone.', confirmLabel: 'Delete', danger: true }))) return;
          await api.del('/contacts/' + c.id);
          toast(c.name + ' deleted.');
          ctx.navigate(c.is_customer ? '/customers' : '/suppliers', { replace: true });
          return;
        default:
          return;
      }
      paint();
    } catch (err) {
      toast(err.message, { kind: 'error' });
    }
  });

  try {
    await load();
  } catch (err) {
    if (ctx.signal.aborted) return;
    stateEl.innerHTML = emptyState(err.isNotFound ? 'magnifying-glass' : 'warning', err.isNotFound ? 'That customer or supplier does not exist' : 'Could not load them',
      err.isNotFound ? '' : err.message, '<a class="btn btn-secondary" href="customers">Back to the customers</a>');
    return;
  }
  stateEl.innerHTML = '';
  body.hidden = false;
}
