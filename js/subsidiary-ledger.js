/**
 * subsidiary-ledger.js — the control account, customer by customer or supplier by supplier
 *
 * GenericPOS Accounting · ES module · every role
 *
 *   /reports/subsidiary-ledger?side=ar|ap&as_of=&zero=1
 *
 * Every line on a receivables or payables control account names its customer
 * or supplier (the posting engine refuses one that does not), so this report
 * and the control account's balance are equal by construction. The foot shows
 * both figures, and anything that could not be attributed, so the tie is
 * visible rather than assumed.
 */

import { api } from './api.js';
import { todayYmd, money, fmtDay } from './store.js';
import { qs, esc, emptyState } from './ui.js';
import { amt, sheetHead, sheetFoot, asOfText, syncQuery, exportCsv } from './report-kit.js';

const YMD = /^\d{4}-\d{2}-\d{2}$/;

export async function mount(root, ctx) {
  const today = todayYmd();
  const q = ctx.query;
  const state = {
    side: q.get('side') === 'ap' ? 'ap' : 'ar',
    as_of: YMD.test(q.get('as_of') || '') ? q.get('as_of') : today,
    zero: q.get('zero') === '1',
  };
  const sheet = qs('[data-sheet]', root);
  const form = qs('[data-filters]', root);
  form.elements.as_of.value = state.as_of;
  form.elements.zero.checked = state.zero;

  const paintSides = () => qs('[data-sides]', root).querySelectorAll('[data-side]').forEach((b) => {
    b.classList.toggle('is-on', b.dataset.side === state.side);
    b.setAttribute('aria-pressed', b.dataset.side === state.side ? 'true' : 'false');
  });

  const render = (d) => {
    const who = d.side === 'ar' ? 'Customer' : 'Supplier';
    ctx.setTitle(d.title);
    qs('[data-head]', root).textContent = d.title;
    qs('[data-sub]', root).textContent = asOfText(d.as_of) + ' · ' + d.rows.length + ' with a balance · ' + money(d.total_cents)
      + (d.ties ? ' · it ties to the control account' : ' · IT DOES NOT TIE TO THE CONTROL ACCOUNT');

    const rows = d.rows.map((r) => '<tr class="l-line"><td class="d"><span class="code">' + esc(r.code) + '</span></td>'
      + '<td><a href="reports/customer-statement?side=' + d.side + '&contact_id=' + r.contact_id + '">' + esc(r.name) + '</a>'
      + (r.is_active ? '' : ' <span class="badge">inactive</span>') + '</td>'
      + '<td class="n">' + amt(r.debit_cents) + '</td><td class="n">' + amt(r.credit_cents) + '</td>'
      + '<td class="n">' + amt(r.balance_cents) + '</td><td class="d">' + esc(r.last_date ? fmtDay(r.last_date) : '') + '</td></tr>').join('');

    const accounts = (d.accounts || []).map((a) => a.code + ' ' + a.name).join(', ');
    const tie = '<table class="stmt" style="margin-top:var(--s5);width:auto"><tbody>'
      + '<tr><td class="l">Total, ' + esc(who.toLowerCase()) + ' by ' + esc(who.toLowerCase()) + '</td><td class="n">' + amt(d.total_cents) + '</td></tr>'
      + (d.unnamed_cents ? '<tr><td class="l">Posted to the control account with no ' + esc(who.toLowerCase()) + ' named</td><td class="n">' + amt(d.unnamed_cents) + '</td></tr>' : '')
      + '<tr><td class="l">' + esc(accounts || 'The control account') + ' in the ledger</td><td class="n">' + amt(d.control_cents) + '</td></tr>'
      + '<tr class="' + (d.ties ? 'l-total' : 'l-grand') + '"><td class="l">' + (d.ties ? 'They agree' : 'Difference — run the integrity check') + '</td>'
      + '<td class="n">' + amt(d.difference_cents) + '</td></tr></tbody></table>';

    sheet.innerHTML = sheetHead(d.letterhead, d.title, [asOfText(d.as_of)])
      + '<div class="table-wrap"><table class="stmt"><thead><tr><th class="l">Code</th><th class="l">' + esc(who) + '</th>'
      + '<th>Debits</th><th>Credits</th><th>Balance</th><th class="l">Last entry</th></tr></thead><tbody>'
      + (rows || '<tr><td colspan="6">' + emptyState('users', 'No balances at this date', 'Nothing is open, or nothing has been posted yet.') + '</td></tr>')
      + '<tr class="l-grand"><td></td><td>Total</td><td class="n"></td><td class="n"></td><td class="n">' + amt(d.total_cents) + '</td><td></td></tr>'
      + '</tbody></table></div>' + tie + sheetFoot(d.letterhead);
  };

  let seq = 0;
  const load = async () => {
    const my = ++seq;
    syncQuery({ side: state.side === 'ar' ? '' : 'ap', as_of: state.as_of === today ? '' : state.as_of, zero: state.zero ? '1' : '' });
    sheet.classList.add('is-busy');
    try {
      const d = await api.get('/reports/subsidiary-ledger', { side: state.side, as_of: state.as_of, zero: state.zero ? 1 : '' }, { signal: ctx.signal });
      if (my === seq) render(d);
    } catch (err) {
      if (!ctx.signal.aborted && my === seq) sheet.innerHTML = emptyState('warning', 'Could not load the subsidiary ledger', err.message);
    } finally {
      if (my === seq) sheet.classList.remove('is-busy');
    }
  };

  form.addEventListener('submit', (e) => e.preventDefault());
  form.addEventListener('change', (e) => {
    if (e.target.name === 'as_of' && YMD.test(e.target.value)) state.as_of = e.target.value;
    else if (e.target.name === 'zero') state.zero = e.target.checked;
    else return;
    load();
  });
  qs('[data-sides]', root).addEventListener('click', (e) => {
    const b = e.target.closest('[data-side]');
    if (!b || b.dataset.side === state.side) return;
    state.side = b.dataset.side;
    paintSides();
    load();
  });
  qs('[data-print]', root).addEventListener('click', () => window.print());
  qs('[data-csv]', root).addEventListener('click', (e) => exportCsv(e.currentTarget, '/reports/subsidiary-ledger',
    { side: state.side, as_of: state.as_of, zero: state.zero ? 1 : '' }, (state.side === 'ar' ? 'receivables' : 'payables') + '-subsidiary-ledger-' + state.as_of + '.csv'));

  paintSides();
  await load();
}
