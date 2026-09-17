/**
 * bank-reconciliation.js — the bank reconciliation report
 *
 * GenericPOS Accounting · ES module · every role
 *
 *   GET /reports/bank-reconciliation?statement_id=     the report (without an id: the statements to choose from)
 *   …&format=csv                                       the same, as a spreadsheet file
 *
 * The standard layout: the balance per bank statement, plus deposits in
 * transit, less outstanding cheques, gives the adjusted bank balance; the
 * balance per books, plus credit memos and less debit memos the books do not
 * have yet, gives the adjusted book balance. The two must agree. The chosen
 * statement lives in the address bar, so a reload or a shared link shows it.
 */

import { api } from './api.js';
import { fmtDay, fmtDate } from './store.js';
import { qs, esc, emptyState } from './ui.js';
import { amt, sheetHead, sheetFoot, asOfText, syncQuery, exportCsv } from './report-kit.js';

export async function mount(root, ctx) {
  const f = qs('[data-filters]', root).elements;
  const sheet = qs('[data-sheet]', root);
  const warn = qs('[data-warn]', root);
  const open = qs('[data-open]', root);
  let choices = [];
  let sid = parseInt(ctx.query.get('statement_id') || '0', 10) || 0;
  let last = null;

  const fillBanks = () => {
    const withSt = choices.filter((c) => c.statements.length);
    f.bank.innerHTML = withSt.length ? withSt.map((c) => '<option value="' + c.id + '">' + esc(c.label) + '</option>').join('') : '<option value="">No statements yet</option>';
  };
  const fillStatements = (bankId) => {
    const c = choices.find((x) => x.id === Number(bankId));
    f.statement.innerHTML = c ? c.statements.map((s) => '<option value="' + s.id + '">' + esc(fmtDay(s.statement_date)) + (s.status === 'reconciled' ? ' · reconciled' : ' · open') + '</option>').join('') : '';
  };
  const bankOf = (id) => choices.find((c) => c.statements.some((s) => s.id === id));

  // ── the sheet ─────────────────────────────────────────────────────────
  const line = (cls, label, a1, a2, pad) => '<tr class="' + cls + '"><td' + (pad ? ' style="padding-left:' + (10 + pad * 18) + 'px"' : '') + '>' + label + '</td>'
    + '<td class="n">' + (a1 === null ? '' : amt(a1)) + '</td><td class="n">' + (a2 === null ? '' : amt(a2)) + '</td></tr>';

  const itemLabel = (it, book) => {
    const bits = [esc(fmtDay(it.date))];
    if (it.reference) bits.push(esc(it.reference));
    const what = book
      ? (it.journal_no ? '<a href="journals/' + it.journal_id + '" class="mono">' + esc(it.journal_no) + '</a> ' : '') + esc(it.description || '')
      : esc(it.description || '') + (it.reason ? ' <span class="sub">' + esc(it.reason) + '</span>' : '');
    return bits.join(' · ') + ' · ' + what;
  };

  /** A group: its caption, one row per item (the first amount column), its total (the second). */
  const group = (caption, items, total, sign, book, empty) => {
    if (!items.length) return empty ? line('l-header', esc(caption) + ' <span class="faint small">— none</span>', null, 0, 1) : '';
    return line('l-header', esc(caption), null, null, 1)
      + items.map((it) => line('', itemLabel(it, book), sign * it.amount_cents, null, 2)).join('')
      + line('l-subtotal', 'Total ' + esc(caption.replace(/^(Add|Less|Add \(deduct\)): /, '').toLowerCase()), null, sign * total, 1);
  };

  const render = (d) => {
    last = d;
    const r = d.report;
    const s = d.statement;
    const b = d.bank;
    const I = r.items;
    const title = 'Bank Reconciliation';
    const sub = b.bank_name + (b.account_last4 ? ' · account ending ' + b.account_last4 : '') + ' · ' + b.ledger_code + ' ' + b.ledger_name;
    qs('[data-sub]', root).textContent = sub + ' · ' + asOfText(s.statement_date);
    ctx.setTitle(title + ' · ' + fmtDay(s.statement_date));
    open.hidden = false;
    open.setAttribute('href', 'banking/statements/' + s.id);

    const bs = r.bank_side;
    const ks = r.book_side;
    const body = line('l-heading', 'Balance per bank statement, ' + esc(fmtDay(s.statement_date, 'long')), null, bs.closing_cents)
      + group('Add: Deposits in transit', I.deposits_in_transit, bs.deposits_in_transit_cents, 1, true, true)
      + group('Less: Outstanding cheques', I.outstanding_cheques, bs.outstanding_cheques_cents, -1, true, true)
      + group('Add (deduct): Bank errors set aside', I.bank_errors, bs.bank_errors_cents, 1, false, false)
      + line('l-grand', 'Adjusted bank balance', null, bs.adjusted_cents)
      + line('l-heading', 'Balance per books, ' + esc(fmtDay(s.statement_date, 'long')), null, ks.balance_cents)
      + group('Add: Credit memos not yet in the books', I.credit_memos, ks.credit_memos_cents, 1, false, true)
      + group('Less: Debit memos not yet in the books', I.debit_memos, ks.debit_memos_cents, -1, false, true)
      + group('Add (deduct): Entered in the books after ' + fmtDay(s.statement_date), I.booked_later, ks.booked_later_cents, 1, true, false)
      + line('l-grand', 'Adjusted book balance', null, ks.adjusted_cents)
      + line('l-computed is-strong', 'Difference' + (r.difference_cents === 0 ? '' : ' <span class="sub">It must be zero before the statement can be reconciled.</span>'), null, r.difference_cents);

    const c = r.counts;
    const status = s.status === 'reconciled'
      ? 'Reconciled by ' + (s.reconciled_by_name || '—') + ' on ' + fmtDate(s.reconciled_at, 'date') + '.'
      : 'Not reconciled yet: ' + c.unmatched + ' of ' + c.lines + ' statement lines still to match.';
    const notes = [status,
      'Deposits in transit and outstanding cheques are posted entries on ' + b.ledger_code + ' dated ' + fmtDay(r.recon_start) + ' to ' + fmtDay(s.statement_date)
        + ' that no bank line of this or an earlier statement has cleared. Entries before ' + fmtDay(r.recon_start) + ' are taken as part of the first statement\'s opening balance.',
      'Credit and debit memos are this statement\'s lines the books do not have yet.'];
    if (I.bank_errors.length) notes.push('Bank errors are lines set aside as the bank\'s mistakes; they are added back until the bank corrects them.');

    const w = [];
    if (!r.checks.adds_up) w.push('The opening balance and the lines add up to ' + amt(r.checks.expected_closing_cents) + ', but the statement says ' + amt(bs.closing_cents) + '.');
    if (r.difference_cents !== 0) w.push('The two adjusted balances differ by ' + amt(Math.abs(r.difference_cents)) + '.');
    if (r.checks.previous && r.checks.opening_agrees === false) w.push('The opening balance differs from the closing balance of the statement of ' + fmtDay(r.checks.previous.statement_date) + '.');
    warn.textContent = w.join(' ');
    warn.hidden = !w.length;

    sheet.innerHTML = sheetHead(d.letterhead, title, [sub, asOfText(s.statement_date)])
      + '<div class="table-wrap"><table class="stmt"><thead><tr><th></th><th>Amount</th><th>Total</th></tr></thead><tbody>' + body + '</tbody></table></div>'
      + '<p class="report-foot">' + esc(notes.join(' ')) + '</p>'
      + sheetFoot(d.letterhead);
  };

  // ── loading ───────────────────────────────────────────────────────────
  let seq = 0;
  const load = async () => {
    const my = ++seq;
    syncQuery({ statement_id: sid || '' });
    sheet.classList.add('is-busy');
    try {
      const d = await api.get('/reports/bank-reconciliation', { statement_id: sid || null }, { signal: ctx.signal });
      if (my !== seq) return;
      choices = d.choices || [];
      fillBanks();
      if (!d.statement) {
        const first = choices.find((c) => c.statements.length);
        if (first) { sid = first.statements[0].id; load(); return; }
        sheet.innerHTML = emptyState('bank', 'No bank statements yet', 'Add a statement under Banking, then its reconciliation shows here.', '<a class="btn" href="banking">Go to Banking</a>');
        return;
      }
      const bk = bankOf(d.statement.id);
      if (bk) { f.bank.value = String(bk.id); fillStatements(bk.id); f.statement.value = String(d.statement.id); }
      render(d);
    } catch (err) {
      if (ctx.signal.aborted || my !== seq) return;
      sheet.innerHTML = emptyState(err.isNotFound ? 'magnifying-glass' : 'warning', err.isNotFound ? 'That statement does not exist' : 'Could not load the reconciliation', err.isNotFound ? '' : err.message);
    } finally {
      if (my === seq) sheet.classList.remove('is-busy');
    }
  };

  f.bank.addEventListener('change', () => {
    fillStatements(f.bank.value);
    sid = Number(f.statement.value) || 0;
    load();
  });
  f.statement.addEventListener('change', () => { sid = Number(f.statement.value) || 0; load(); });
  qs('[data-print]', root).addEventListener('click', () => window.print());
  qs('[data-csv]', root).addEventListener('click', (e) => {
    if (!last) return;
    exportCsv(e.currentTarget, '/reports/bank-reconciliation', { statement_id: last.statement.id },
      'bank-reconciliation-' + last.statement.statement_date + '.csv');
  });

  await load();
}
