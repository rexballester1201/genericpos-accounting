/**
 * voucher.js — a journal entry as the voucher that is signed and filed
 *
 * GenericPOS Accounting · ES module · every role
 *
 *   cash disbursements   Disbursement voucher: pay to, cheque number, the cash
 *                        paid in figures and in words, "received by"
 *   cash receipts        Cash receipt voucher: received from, OR number
 *   every other book     Journal voucher
 *
 * The account distribution, the particulars, and the signature blocks
 * (prepared by the preparer, approved by the approver, checked by whoever
 * Settings › Printed reports names). An entry that has not posted says so on
 * the paper itself.
 */

import { api } from './api.js';
import { money, fmtDay, fmtDate } from './store.js';
import { qs, esc, emptyState } from './ui.js';
import { sheetHead, amt } from './report-kit.js';

const TITLES = { cash_disbursements: 'Disbursement voucher', cash_receipts: 'Cash receipt voucher' };
const STATUS_NOTE = {
  draft: 'DRAFT — not approved and not posted',
  submitted: 'SUBMITTED — waiting for approval, not posted',
  rejected: 'REJECTED — returned to its preparer, not posted',
  cancelled: 'CANCELLED — never posted',
};

export async function mount(root, ctx) {
  const id = parseInt(ctx.params.id, 10) || 0;
  const sheet = qs('[data-sheet]', root);
  qs('[data-print]', root).addEventListener('click', () => window.print());

  let d;
  try {
    d = await api.get('/journals/' + id + '/voucher', null, { signal: ctx.signal });
  } catch (err) {
    if (ctx.signal.aborted) return;
    sheet.innerHTML = emptyState(err.isNotFound ? 'magnifying-glass' : 'warning', err.isNotFound ? 'That entry does not exist' : 'Could not load the voucher', err.isNotFound ? '' : err.message);
    return;
  }

  const j = d.journal;
  const lh = d.letterhead || {};
  const title = TITLES[j.book] || 'Journal voucher';
  const no = j.journal_no || (j.status === 'cancelled' ? 'Cancelled #' + j.id : 'Draft #' + j.id);
  const cashBook = j.book === 'cash_disbursements' || j.book === 'cash_receipts';
  const partyLabel = j.book === 'cash_receipts' ? 'Received from' : (j.book === 'cash_disbursements' ? 'Pay to' : 'Party');
  const refLabel = j.book === 'cash_receipts' ? 'OR number' : (j.book === 'cash_disbursements' ? 'Cheque number' : 'Reference');

  ctx.setTitle(title + ' ' + no);
  qs('[data-title]', root).textContent = title + ' ' + no;
  const back = qs('[data-back]', root);
  back.textContent = no;
  back.setAttribute('href', 'journals/' + j.id);

  let dr = 0;
  let cr = 0;
  const rows = d.lines.map((l) => {
    dr += l.debit_cents;
    cr += l.credit_cents;
    const extra = [l.memo, l.department, l.contact].filter(Boolean).join(' · ');
    return '<tr><td class="code">' + esc(l.account_code) + '</td><td>' + esc(l.account_name) + (extra ? '<div class="xs muted">' + esc(extra) + '</div>' : '') + '</td>'
      + '<td class="num">' + (l.debit_cents ? esc(amt(l.debit_cents)) : '') + '</td><td class="num">' + (l.credit_cents ? esc(amt(l.credit_cents)) : '') + '</td></tr>';
  }).join('');

  const sigs = [
    ['Prepared by', j.created_by_name || '', ''],
    ['Checked by', ((lh.signatories || []).find((s) => s.role === 'Checked by') || {}).name || '', ((lh.signatories || []).find((s) => s.role === 'Checked by') || {}).title || ''],
    ['Approved by', j.approved_by_name || ((lh.signatories || []).find((s) => s.role === 'Approved by') || {}).name || '', ''],
  ];
  if (j.book === 'cash_disbursements') sigs.push(['Received by', '', 'Signature over printed name, and date']);
  if (j.book === 'cash_receipts') sigs.push(['Received by (cashier)', '', '']);

  const amount = d.cash_cents > 0 ? d.cash_cents : j.total_cents;
  sheet.innerHTML = sheetHead(lh, title, ['No. ' + no, fmtDay(j.entry_date, 'long')])
    + (STATUS_NOTE[j.status] ? '<div class="voucher-status">' + esc(STATUS_NOTE[j.status]) + '</div>' : '')
    + (j.reversed_by_id ? '<div class="voucher-status">REVERSED — cancelled out in the ledger by a reversing entry</div>' : '')
    + '<dl class="kv voucher-kv">'
    + '<dt>' + esc(partyLabel) + '</dt><dd>' + esc(j.party_name || '—') + '</dd>'
    + '<dt>' + esc(refLabel) + '</dt><dd>' + esc(j.reference || '—') + '</dd>'
    + '<dt>Book</dt><dd>' + esc(j.book_label) + '</dd>'
    + '<dt>Particulars</dt><dd>' + esc(j.description) + '</dd>'
    + '</dl>'
    + (cashBook ? '<div class="voucher-amount"><div><div class="xs muted">Amount</div><div class="va-figure">' + esc(money(amount)) + '</div></div>'
      + '<div class="grow"><div class="xs muted">In words</div><div class="va-words">' + esc(d.amount_words) + ' only</div></div></div>' : '')
    + '<table class="stmt voucher-lines"><thead><tr><th class="l">Code</th><th class="l">Account title</th><th>Debit</th><th>Credit</th></tr></thead>'
    + '<tbody>' + rows + '</tbody>'
    + '<tfoot><tr class="grand"><td></td><td>Total</td><td class="num">' + esc(amt(dr)) + '</td><td class="num">' + esc(amt(cr)) + '</td></tr></tfoot></table>'
    + '<footer class="report-sign voucher-sign">' + sigs.map(([role, name, t]) => '<div><div class="sg-role">' + esc(role) + ':</div><div class="sg-line"></div>'
      + '<div class="sg-name">' + esc(name) + '</div><div class="sg-title">' + esc(t) + '</div></div>').join('') + '</footer>'
    + (lh.printed_by ? '<div class="report-printed">Printed by ' + esc(lh.printed_by) + ' on ' + esc(fmtDate(lh.printed_at)) + '</div>' : '');
}
