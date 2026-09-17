/**
 * worksheet.js — the ten-column worksheet
 *
 * GenericPOS Accounting · ES module · every role
 *
 *   /reports/worksheet?as_of=
 *
 * Unadjusted trial balance · adjustments (the adjusting book) · adjusted trial
 * balance · income statement · balance sheet, a debit and a credit column
 * each. Net income balances the last two pairs, as on paper. Income and
 * expenses count from the start of the fiscal year; balance-sheet accounts
 * from the first entry. Each account opens its general ledger.
 */

import { api } from './api.js';
import { todayYmd } from './store.js';
import { qs, esc, emptyState } from './ui.js';
import { amt, sheetHead, sheetFoot, asOfText, glHref, syncQuery, exportCsv } from './report-kit.js';

const YMD = /^\d{4}-\d{2}-\d{2}$/;
const PAIRS = [['ub', 'Unadjusted trial balance'], ['adj', 'Adjustments'], ['ab', 'Adjusted trial balance'], ['is', 'Income statement'], ['bs', 'Balance sheet']];

export async function mount(root, ctx) {
  const today = todayYmd();
  const state = { as_of: YMD.test(ctx.query.get('as_of') || '') ? ctx.query.get('as_of') : today };
  const sheet = qs('[data-sheet]', root);
  const f = qs('[data-filters]', root).elements;
  f.as_of.value = state.as_of;

  const cell = (v) => '<td class="n">' + (v ? amt(v) : '') + '</td>';

  const render = (d) => {
    const coop = d.letterhead && d.letterhead.entity_type === 'cooperative';
    const ni = d.net_income_cents;
    const niLabel = coop ? (ni >= 0 ? 'Net surplus' : 'Net loss') : (ni >= 0 ? 'Net income' : 'Net loss');
    const from = d.fiscal_year ? d.fiscal_year.start_date : d.as_of.slice(0, 4) + '-01-01';
    const t = d.totals;

    const rows = d.rows.map((r) => '<tr><td><a href="' + glHref(r.account_id, from, d.as_of) + '"><span class="code">' + esc(r.code) + '</span>' + esc(r.name) + '</a></td>'
      + PAIRS.map(([k]) => cell(r[k + '_dr']) + cell(r[k + '_cr'])).join('') + '</tr>').join('');
    const pd = d.prior_cents > 0 ? d.prior_cents : 0;
    const pc = d.prior_cents < 0 ? -d.prior_cents : 0;
    const prior = d.prior_cents ? '<tr><td>' + esc((coop ? 'Net surplus' : 'Net income') + ' of earlier years, not yet closed') + '</td>'
      + cell(pd) + cell(pc) + cell(0) + cell(0) + cell(pd) + cell(pc) + cell(0) + cell(0) + cell(pd) + cell(pc) + '</tr>' : '';
    const totals = '<tr class="l-total"><td>Totals</td>' + PAIRS.map(([k]) => '<td class="n">' + amt(t[k + '_dr']) + '</td><td class="n">' + amt(t[k + '_cr']) + '</td>').join('') + '</tr>';
    const netRow = '<tr><td>' + esc(niLabel) + '</td><td></td><td></td><td></td><td></td><td></td><td></td>'
      + cell(ni > 0 ? ni : 0) + cell(ni < 0 ? -ni : 0) + cell(ni < 0 ? -ni : 0) + cell(ni > 0 ? ni : 0) + '</tr>';
    const final = '<tr class="l-grand"><td></td><td></td><td></td><td></td><td></td><td></td><td></td>'
      + '<td class="n">' + amt(t.is_dr + Math.max(ni, 0)) + '</td><td class="n">' + amt(t.is_cr + Math.max(-ni, 0)) + '</td>'
      + '<td class="n">' + amt(t.bs_dr + Math.max(-ni, 0)) + '</td><td class="n">' + amt(t.bs_cr + Math.max(ni, 0)) + '</td></tr>';

    qs('[data-sub]', root).textContent = asOfText(d.as_of) + (d.fiscal_year ? ' · ' + d.fiscal_year.name : '') + (d.balanced ? ' · every pair of columns balances' : ' · THE COLUMNS DO NOT BALANCE');
    sheet.innerHTML = sheetHead(d.letterhead, 'Worksheet', [asOfText(d.as_of), d.fiscal_year ? 'Income and expenses from ' + d.fiscal_year.start_date : ''])
      + (d.balanced ? '' : '<div class="alert alert-err no-print">The columns do not balance. Run the integrity check.</div>')
      + '<div class="table-wrap"><table class="stmt worksheet"><thead>'
      + '<tr><th class="l" rowspan="2">Account</th>' + PAIRS.map(([, l]) => '<th colspan="2" class="c">' + esc(l) + '</th>').join('') + '</tr>'
      + '<tr>' + PAIRS.map(() => '<th>Debit</th><th>Credit</th>').join('') + '</tr></thead><tbody>'
      + (rows || '<tr><td colspan="11">' + emptyState('calculator', 'Nothing posted yet', 'The worksheet fills in as entries post.') + '</td></tr>')
      + prior + totals + netRow + final + '</tbody></table></div>'
      + sheetFoot(d.letterhead);
  };

  let seq = 0;
  const load = async () => {
    const my = ++seq;
    syncQuery({ as_of: state.as_of === today ? '' : state.as_of });
    sheet.classList.add('is-busy');
    try {
      const d = await api.get('/reports/worksheet', { as_of: state.as_of }, { signal: ctx.signal });
      if (my === seq) render(d);
    } catch (err) {
      if (!ctx.signal.aborted && my === seq) sheet.innerHTML = emptyState('warning', 'Could not load the worksheet', err.message);
    } finally {
      if (my === seq) sheet.classList.remove('is-busy');
    }
  };

  qs('[data-filters]', root).addEventListener('submit', (e) => e.preventDefault());
  qs('[data-filters]', root).addEventListener('change', (e) => {
    if (e.target.name === 'as_of' && YMD.test(e.target.value)) { state.as_of = e.target.value; load(); }
  });
  qs('[data-print]', root).addEventListener('click', () => window.print());
  qs('[data-csv]', root).addEventListener('click', (e) => exportCsv(e.currentTarget, '/reports/worksheet', { as_of: state.as_of }, 'worksheet-' + state.as_of + '.csv'));

  await load();
}
