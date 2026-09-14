/**
 * trial-balance.js — every account's balance on one date, debits against credits
 *
 * GenericPOS Accounting · ES module · every role
 *
 *   GET /reports/trial-balance?as_of=&kind=&zero=1     the report
 *   …&format=csv                                       the same, as a spreadsheet file
 *
 * The three kinds follow the accounting cycle:
 *   unadjusted     before the year's adjusting entries
 *   adjusted       after them: the figures the statements are drawn from
 *   post-closing   after the closing entries: only balance-sheet accounts remain
 *
 * Income and expense accounts show the fiscal year to date; balance-sheet
 * accounts show every posted entry up to the date. The net income of an
 * earlier year that was never closed shows as a line of its own, so the two
 * columns agree before the year-end close has been run.
 *
 * The choices live in the address bar, so a reload or a shared link shows the
 * same report. Each account opens its general ledger for the year to the date.
 */

import { api } from './api.js';
import { todayYmd } from './store.js';
import { qs, qsa, esc, emptyState } from './ui.js';
import { amt, sheetHead, sheetFoot, asOfText, glHref, syncQuery, exportCsv } from './report-kit.js';

const TYPES = [['asset', 'Assets'], ['liability', 'Liabilities'], ['equity', 'Equity'], ['income', 'Income'], ['expense', 'Expenses']];
const PL = ['income', 'expense'];
const KINDS = {
  unadjusted: 'Before the year\'s adjusting entries',
  adjusted: 'After the adjusting entries: the figures the statements use',
  post_closing: 'After the closing entries: balance-sheet accounts only',
};
const YMD = /^\d{4}-\d{2}-\d{2}$/;

export async function mount(root, ctx) {
  const q = ctx.query;
  const today = todayYmd();
  const state = {
    as_of: YMD.test(q.get('as_of') || '') ? q.get('as_of') : today,
    kind: KINDS[q.get('kind')] ? q.get('kind') : 'adjusted',
    zero: q.get('zero') === '1',
  };

  const form = qs('[data-filters]', root);
  const f = form.elements;
  const sheet = qs('[data-sheet]', root);
  const warn = qs('[data-warn]', root);
  const kinds = qs('[data-kinds]', root);

  f.as_of.value = state.as_of;
  f.zero.checked = state.zero;
  qsa('button[data-kind]', kinds).forEach((b) => { b.title = KINDS[b.dataset.kind] || ''; });

  const params = () => ({ as_of: state.as_of, kind: state.kind, zero: state.zero ? 1 : '' });
  const sync = () => {
    syncQuery({ as_of: state.as_of === today ? '' : state.as_of, kind: state.kind === 'adjusted' ? '' : state.kind, zero: state.zero });
    qsa('button[data-kind]', kinds).forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.kind === state.kind)));
  };

  const render = (d) => {
    const fy = d.fiscal_year;
    const rows = d.rows || [];
    const from = fy ? fy.start_date : d.as_of.slice(0, 4) + '-01-01';
    const title = d.kind_label + ' Trial Balance';
    qs('[data-sub]', root).textContent = d.kind_label + ' · ' + asOfText(d.as_of) + (fy ? ' · ' + fy.name : '');
    ctx.setTitle(title);

    let body = '';
    TYPES.forEach(([type, label]) => {
      const mine = rows.filter((r) => r.type === type);
      if (!mine.length) return;
      body += '<tr class="l-heading"><td colspan="4">' + esc(label) + '</td></tr>'
        + mine.map((r) => '<tr><td class="d"><span class="code">' + esc(r.code) + '</span></td>'
          + '<td><a href="' + esc(glHref(r.account_id, from, d.as_of)) + '">' + esc(r.name) + '</a></td>'
          + '<td class="n">' + (r.debit_cents ? amt(r.debit_cents) : '') + '</td>'
          + '<td class="n">' + (r.credit_cents ? amt(r.credit_cents) : '') + '</td></tr>').join('');
    });
    const prior = d.unclosed_prior_cents || 0;
    if (prior) {
      body += '<tr class="l-heading"><td colspan="4">Earlier years</td></tr>'
        + '<tr><td></td><td>' + (prior < 0 ? 'Net income' : 'Net loss') + ' of earlier years, not yet closed'
        + '<span class="sub">It moves into retained earnings when that year is closed.</span></td>'
        + '<td class="n">' + (prior > 0 ? amt(prior) : '') + '</td><td class="n">' + (prior < 0 ? amt(-prior) : '') + '</td></tr>';
    }

    const notes = [fy
      ? 'Income and expense accounts show ' + fy.name + ' up to the date; balance-sheet accounts show every posted entry up to it.'
      : 'No fiscal year covers this date, so income and expense accounts show every posted entry up to it.'];
    if (d.kind === 'post_closing' && rows.some((r) => PL.includes(r.type))) notes.push('Income and expense accounts still carry balances because the year\'s closing entries have not been posted.');
    if (d.kind === 'unadjusted') notes.push('The general ledger counts every posted entry, so an account\'s ledger includes the adjusting entries this report leaves out.');
    if (rows.length) notes.push('Open an account to see its general ledger.');

    warn.hidden = !!d.balanced;
    if (!d.balanced) {
      warn.textContent = 'The debit and credit columns differ by ' + amt(Math.abs(d.total_debit_cents - d.total_credit_cents))
        + '. Every posted entry balances, so this points to damaged data. Tell your administrator before relying on these figures.';
    }

    sheet.innerHTML = sheetHead(d.letterhead, title, [asOfText(d.as_of)])
      + '<div class="table-wrap"><table class="stmt"><thead><tr><th class="l">Code</th><th class="l">Account</th><th>Debit</th><th>Credit</th></tr></thead><tbody>'
      + (body || '<tr><td colspan="4">' + emptyState('scales', 'Nothing posted by this date', 'Posted entries dated on or before the date appear here.') + '</td></tr>')
      + (body ? '<tr class="l-grand"><td></td><td>Total</td><td class="n">' + amt(d.total_debit_cents) + '</td><td class="n">' + amt(d.total_credit_cents) + '</td></tr>' : '')
      + '</tbody></table></div>'
      + '<p class="report-foot">' + esc(notes.join(' ')) + '</p>'
      + sheetFoot(d.letterhead);
  };

  let seq = 0;
  const load = async () => {
    const my = ++seq;
    sync();
    sheet.classList.add('is-busy');
    try {
      const d = await api.get('/reports/trial-balance', params(), { signal: ctx.signal });
      if (my === seq) render(d);
    } catch (err) {
      if (ctx.signal.aborted || my !== seq) return;
      sheet.innerHTML = emptyState('warning', 'Could not load the trial balance', err.message);
      warn.hidden = true;
    } finally {
      if (my === seq) sheet.classList.remove('is-busy');
    }
  };

  form.addEventListener('submit', (e) => e.preventDefault());
  f.as_of.addEventListener('change', () => {
    if (!YMD.test(f.as_of.value)) return;
    state.as_of = f.as_of.value;
    load();
  });
  f.zero.addEventListener('change', () => { state.zero = f.zero.checked; load(); });
  kinds.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-kind]');
    if (!b || b.dataset.kind === state.kind) return;
    state.kind = b.dataset.kind;
    load();
  });
  qs('[data-print]', root).addEventListener('click', () => window.print());
  qs('[data-csv]', root).addEventListener('click', (e) => exportCsv(e.currentTarget, '/reports/trial-balance', params(), 'trial-balance-' + state.as_of + '.csv'));

  await load();
}
