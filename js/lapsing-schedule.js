/**
 * lapsing-schedule.js — the lapsing schedule of property and equipment
 *
 * GenericPOS Accounting · ES module · every role
 *
 *   GET /reports/lapsing-schedule?from=&to=          (or ?fiscal_year_id= for a whole year)
 *   …&format=csv                                     the same, as a spreadsheet file
 *
 * One row per asset held during the period, by category: cost and
 * accumulated depreciation at the start, what was added, depreciated and
 * disposed of, and where each stands at the end, with the months of life
 * left. Below it, the tie-out: each category account's balance in the ledger
 * beside the register's figure, with any difference shown — the auditors'
 * first question. Printed on a landscape page (the wide sheet).
 */

import { api } from './api.js';
import { todayYmd, fmtDay } from './store.js';
import { qs, esc, emptyState } from './ui.js';
import { amt, sheetHead, sheetFoot, presets, presetFor, periodText, syncQuery, exportCsv } from './report-kit.js';

const YMD = /^\d{4}-\d{2}-\d{2}$/;
const KEYS = ['cost_start_cents', 'additions_cents', 'disposals_cents', 'cost_end_cents', 'accum_start_cents', 'depreciation_cents',
  'accum_disposals_cents', 'accum_end_cents', 'nbv_end_cents'];
const HEADS = ['Cost, beginning', 'Additions', 'Disposals', 'Cost, end', 'Accum. depreciation, beginning', 'Depreciation', 'Accum. depreciation, disposals',
  'Accum. depreciation, end', 'Book value, end'];

export async function mount(root, ctx) {
  const today = todayYmd();
  const q = ctx.query;
  const state = { from: YMD.test(q.get('from') || '') ? q.get('from') : '', to: YMD.test(q.get('to') || '') ? q.get('to') : '' };
  const form = qs('[data-controls]', root);
  const f = form.elements;
  const sheet = qs('[data-sheet]', root);
  const warn = qs('[data-warn]', root);

  let years = [];
  try { years = (await api.get('/fiscal-years', null, { signal: ctx.signal })).years || []; } catch { /* the presets fall back to the calendar */ }
  if (ctx.signal.aborted) return;
  const fyId = parseInt(q.get('fiscal_year_id') || '0', 10) || 0;
  const fyPick = fyId ? years.find((y) => y.id === fyId) : null;
  if (fyPick) { state.from = fyPick.start_date; state.to = fyPick.end_date; }
  if (!state.to) state.to = today;
  if (!state.from) {
    const fy = years.find((y) => y.start_date <= state.to && state.to <= y.end_date);
    state.from = fy ? fy.start_date : state.to.slice(0, 4) + '-01-01';
  }
  const list = presets(years, today);
  f.preset.innerHTML = list.map((p) => '<option value="' + p.key + '">' + esc(p.label) + '</option>').join('') + '<option value="custom">Custom dates</option>';
  f.from.value = state.from;
  f.to.value = state.to;
  f.preset.value = presetFor(list, state.from, state.to);

  const params = () => ({ from: state.from, to: state.to });

  const render = (d) => {
    const lines = [periodText(d.from, d.to)];
    qs('[data-sub]', root).textContent = lines.join(' · ') + ' · ' + (d.ties ? 'ties to the ledger' : 'differs from the ledger');
    ctx.setTitle('Lapsing schedule');

    const cells = (x) => KEYS.map((k) => '<td class="n">' + amt(x[k]) + '</td>').join('');
    let body = '';
    (d.categories || []).forEach((c) => {
      body += '<tr class="l-heading"><td colspan="15">' + esc(c.name) + '<span class="sub">' + esc(c.asset_account.code + ' ' + c.asset_account.name + ' · ' + c.accum_account.code + ' ' + c.accum_account.name) + '</span></td></tr>';
      body += c.rows.map((r) => '<tr><td class="d"><a href="assets/' + r.id + '">' + esc(r.asset_no) + '</a></td>'
        + '<td>' + esc(r.name) + (r.disposed_on ? '<span class="sub">Disposed of ' + esc(fmtDay(r.disposed_on)) + '</span>' : r.status === 'fully_depreciated' ? '<span class="sub">Fully depreciated</span>' : '') + '</td>'
        + '<td class="d">' + esc(fmtDay(r.acquired_on)) + '</td><td class="n">' + r.life_months + '</td>'
        + cells(r) + '<td class="n">' + (r.months_remaining === null ? '–' : r.months_remaining) + '</td></tr>').join('');
      body += '<tr class="l-subtotal"><td></td><td colspan="3">Total ' + esc(c.name) + '</td>' + cells(c.subtotal) + '<td></td></tr>';
    });
    const any = (d.categories || []).length;

    const tie = (d.tie_out || []).map((t) => '<tr><td class="d"><span class="code">' + esc(t.code) + '</span></td><td>' + esc(t.name) + '<span class="sub">'
      + esc((t.kind === 'cost' ? 'Cost' : 'Accumulated depreciation') + ' · ' + t.categories.join(', ')) + '</span></td>'
      + '<td class="n">' + amt(t.register_cents) + '</td><td class="n">' + amt(t.ledger_cents) + '</td>'
      + '<td class="n' + (t.difference_cents ? ' err-text' : '') + '">' + (t.difference_cents ? amt(t.difference_cents) : 'Ties') + '</td></tr>').join('')
      + (d.others || []).map((o) => '<tr><td class="d"><span class="code">' + esc(o.code) + '</span></td><td>' + esc(o.name) + '<span class="sub">Not linked to any asset category</span></td>'
        + '<td class="n">–</td><td class="n">' + amt(o.balance_cents) + '</td><td class="n err-text">' + amt(-o.balance_cents) + '</td></tr>').join('');

    const notes = [
      'Depreciation counts on the last day of the month it is for, and a disposal on its date, as their entries sit in the ledger.',
      'Accumulated depreciation at the beginning includes what was charged before these books (brought in at go-live).',
      'Months left: the months of useful life after the last full month in the period.',
    ];
    const diffNote = d.ties
      ? 'The register ties to the ledger: each category\'s cost and accumulated depreciation equal the balances of its accounts on ' + fmtDay(d.to, 'long') + '.'
      : 'The register and the ledger differ. Common causes: an asset bought through a bill or entry but never registered; an asset registered but never booked; an entry posted to these accounts by hand. Differences are register less ledger.';

    warn.hidden = !!d.ties;
    if (!d.ties) warn.textContent = diffNote;

    sheet.innerHTML = sheetHead(d.letterhead, d.title, lines)
      + '<div class="table-wrap"><table class="stmt"><thead><tr><th class="l">No.</th><th class="l">Asset</th><th class="l">Acquired</th><th>Life (months)</th>'
      + HEADS.map((h) => '<th>' + esc(h) + '</th>').join('') + '<th>Months left</th></tr></thead><tbody>'
      + (any ? body + '<tr class="l-grand"><td></td><td colspan="3">Total property and equipment</td>' + cells(d.total) + '<td></td></tr>'
        : '<tr><td colspan="15">' + emptyState('package', 'No assets in this period', 'Assets held at any time between these dates appear here.') + '</td></tr>')
      + '</tbody></table></div>'
      + '<h3 class="mt-6 mb-2">Tie-out to the ledger as of ' + esc(fmtDay(d.to, 'long')) + '</h3>'
      + '<div class="table-wrap"><table class="stmt"><thead><tr><th class="l">Account</th><th class="l">Name</th><th>Register</th><th>Ledger</th><th>Difference</th></tr></thead><tbody>'
      + (tie || '<tr><td colspan="5" class="muted">No asset category yet.</td></tr>') + '</tbody></table></div>'
      + '<p class="report-foot">' + esc(diffNote + ' ' + notes.join(' ')) + '</p>'
      + sheetFoot(d.letterhead);
  };

  let seq = 0;
  const load = async () => {
    const my = ++seq;
    syncQuery(params());
    sheet.classList.add('is-busy');
    try {
      const d = await api.get('/reports/lapsing-schedule', params(), { signal: ctx.signal });
      if (my === seq) render(d);
    } catch (err) {
      if (ctx.signal.aborted || my !== seq) return;
      sheet.innerHTML = emptyState('warning', 'Could not load the lapsing schedule', err.errors ? Object.values(err.errors).join(' ') : err.message);
      warn.hidden = true;
    } finally {
      if (my === seq) sheet.classList.remove('is-busy');
    }
  };

  form.addEventListener('submit', (e) => e.preventDefault());
  form.addEventListener('change', (e) => {
    const t = e.target;
    if (t.name === 'preset') {
      const p = list.find((x) => x.key === t.value);
      if (!p) return;
      state.from = p.from;
      state.to = p.to;
      f.from.value = p.from;
      f.to.value = p.to;
    } else if (t.name === 'from' || t.name === 'to') {
      if (!YMD.test(t.value)) return;
      state[t.name] = t.value;
      f.preset.value = presetFor(list, state.from, state.to);
    } else return;
    load();
  });
  qs('[data-print]', root).addEventListener('click', () => window.print());
  qs('[data-csv]', root).addEventListener('click', (e) => exportCsv(e.currentTarget, '/reports/lapsing-schedule', params(),
    'lapsing-schedule-' + state.from + '-to-' + state.to + '.csv'));

  await load();
}
