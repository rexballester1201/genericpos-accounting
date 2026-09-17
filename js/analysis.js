/**
 * analysis.js — financial ratios, read in plain words
 *
 * GenericPOS Accounting · ES module · every role
 *
 *   /reports/analysis?as_of=
 *
 * Each ratio shows its value, the same date a year earlier and whether it
 * moved the right way, a sentence saying what it means, its formula, and the
 * figures it was worked out from (Analysis_lib).
 */

import { api } from './api.js';
import { todayYmd, fmtDay, money, store } from './store.js';
import { qs, esc, emptyState } from './ui.js';
import { amt, sheetHead, sheetFoot, asOfText, syncQuery, exportCsv } from './report-kit.js';

const YMD = /^\d{4}-\d{2}-\d{2}$/;

const fmt = (v, unit) => {
  if (v === null || v === undefined) return 'Not available';
  if (unit === 'money') return money(v);
  if (unit === 'pct') return (v * 100).toFixed(1) + '%';
  if (unit === 'days') return Math.round(v) + ' days';
  return v.toFixed(2) + '×';
};

export async function mount(root, ctx) {
  const coop = store().entity_type === 'cooperative';
  const P = (v) => (v * 100).toFixed(1) + '%';
  const X = (v) => v.toFixed(2);
  const D = (v) => String(Math.round(Math.abs(v)));
  const READ = {
    current_ratio: (v) => 'Current assets cover current liabilities ' + X(v) + ' times.',
    quick_ratio: (v) => 'Cash and receivables alone cover current liabilities ' + X(v) + ' times.',
    cash_ratio: (v) => 'Cash covers ' + P(v) + ' of current liabilities.',
    working_capital: (v) => (v >= 0 ? 'Current assets exceed current liabilities by ' + money(v) + '.' : 'Current liabilities exceed current assets by ' + money(-v) + '.'),
    debt_ratio: (v) => P(v) + ' of the assets are financed by liabilities.',
    debt_to_equity: (v) => X(v) + ' in liabilities for every 1.00 of equity.',
    equity_ratio: (v) => P(v) + ' of the assets are financed by equity.',
    times_interest_earned: (v) => 'Earnings before interest and tax cover the interest ' + X(v) + ' times.',
    gross_margin: (v) => P(v) + ' of revenue is left after the cost of sales.',
    operating_margin: (v) => P(v) + ' of revenue is left after the costs of operating.',
    net_margin: (v) => P(v) + ' of revenue ends as ' + (coop ? 'net surplus' : 'net income') + '.',
    return_on_assets: (v) => 'The assets earn ' + P(v) + ' a year.',
    return_on_equity: (v) => 'Equity earns ' + P(v) + ' a year.',
    receivable_turnover: (v) => 'Trade receivables are collected ' + X(v) + ' times a year.',
    days_sales_outstanding: (v) => 'Customers take about ' + D(v) + ' days to pay.',
    inventory_turnover: (v) => 'Inventory is sold through ' + X(v) + ' times a year.',
    days_inventory_outstanding: (v) => 'Stock stays about ' + D(v) + ' days before it is sold.',
    payable_turnover: (v) => 'Trade payables are settled ' + X(v) + ' times a year.',
    days_payables_outstanding: (v) => 'Suppliers are paid in about ' + D(v) + ' days.',
    cash_conversion_cycle: (v) => (v >= 0 ? 'About ' + D(v) + ' days pass between paying suppliers and collecting from customers.'
      : 'Customers pay about ' + D(v) + ' days before suppliers are paid.'),
    asset_turnover: (v) => 'Each 1.00 of assets brings in ' + X(v) + ' of revenue a year.',
    portfolio_at_risk: (v) => P(v) + ' of the loan portfolio is past due.',
    allowance_cover: (v) => 'The allowance covers ' + P(v) + ' of the loans past due.',
    share_capital_ratio: (v) => 'Share capital finances ' + P(v) + ' of the assets.',
    statutory_fund_ratio: (v) => 'The statutory funds equal ' + P(v) + ' of the assets.',
    cost_ratio: (v) => 'Operating and financing costs take ' + P(v) + ' of revenue.',
  };

  const today = todayYmd();
  const state = { as_of: YMD.test(ctx.query.get('as_of') || '') ? ctx.query.get('as_of') : today };
  const form = qs('[data-controls]', root);
  const sheet = qs('[data-sheet]', root);
  form.elements.as_of.value = state.as_of;

  const card = (r) => {
    const has = r.value !== null && r.value !== undefined;
    let prior = '';
    if (has && r.prior !== null && r.prior !== undefined) {
      const diff = r.value - r.prior;
      const good = r.better && Math.abs(diff) > 1e-12 ? (r.better === 'higher') === (diff > 0) : null;
      prior = '<div class="r-prior">' + (Math.abs(diff) <= 1e-12 ? 'Unchanged from ' : '<span class="' + (good === null ? '' : good ? 'r-good' : 'r-bad') + '">'
        + (diff > 0 ? '▲' : '▼') + '</span> from ') + esc(fmt(r.prior, r.unit)) + ' a year earlier</div>';
    }
    const read = has ? (READ[r.key] ? READ[r.key](r.value) : '') : 'The figure it divides by is zero.';
    const b = r.benchmark;
    const bench = b
      ? '<div class="r-bench">' + (b.met === null ? '<span class="badge">' + esc(b.label) + '</span>'
        : '<span class="badge ' + (b.met ? 'badge-ok' : 'badge-warn') + '">' + (b.met ? 'Meets ' : 'Below ') + esc(b.label.toLowerCase()) + '</span>') + '</div>'
      : '';
    return '<div class="ratio"><div class="r-name">' + esc(r.label) + '</div>'
      + '<div class="r-value' + (has ? '' : ' is-none') + '">' + esc(fmt(r.value, r.unit)) + '</div>'
      + bench
      + prior
      + '<div class="r-read">' + esc(read) + '</div>'
      + '<div class="r-formula">' + esc(r.formula) + '</div>'
      + '<details><summary>Figures used</summary><dl>' + r.inputs.map((i) => '<dt>' + esc(i.label) + '</dt><dd>' + amt(i.cents) + '</dd>').join('') + '</dl></details>'
      + '</div>';
  };

  const render = (d) => {
    const lines = [
      asOfText(d.as_of),
      'Income and turnover from ' + fmtDay(d.from, 'long') + ' (' + d.days + ' day' + (d.days === 1 ? '' : 's') + ')',
      d.prior_as_of ? 'Beside ' + fmtDay(d.prior_as_of, 'long') : '',
    ];
    qs('[data-sub]', root).textContent = lines.filter(Boolean).join(' · ');
    ctx.setTitle('Financial analysis');
    const figures = '<div class="figure-row">' + d.figures.map((x) => '<div class="figure"><div class="f-label">' + esc(x.label) + '</div>'
      + '<div class="f-value">' + esc(money(x.cents)) + '</div>'
      + (x.prior_cents !== null ? '<div class="f-prior">' + esc(money(x.prior_cents)) + ' a year earlier</div>' : '') + '</div>').join('') + '</div>';
    const groups = d.groups.map((g) => '<section class="ratio-group"><h3>' + esc(g.label) + '</h3><p>' + esc(g.about) + '</p><div class="ratio-grid">'
      + d.ratios.filter((r) => r.group === g.key).map(card).join('') + '</div></section>').join('');

    const notes = ['Income figures are for the fiscal year to date. Turnover and return ratios are annualised, and use the average of the balances at the start of the year and at the date.'];
    if (d.days < 60) notes.push('With only ' + d.days + ' days of the year so far, the annualised ratios can swing widely.');
    if (!d.prior_as_of) notes.push('There are no figures from a year earlier to compare with.');

    sheet.innerHTML = sheetHead(d.letterhead, 'Financial Analysis', lines)
      + figures + groups
      + '<p class="report-foot">' + esc(notes.join(' ')) + '</p>'
      + sheetFoot(d.letterhead);
  };

  let seq = 0;
  const load = async () => {
    const my = ++seq;
    syncQuery({ as_of: state.as_of === today ? '' : state.as_of });
    sheet.classList.add('is-busy');
    try {
      const d = await api.get('/reports/analysis', { as_of: state.as_of }, { signal: ctx.signal });
      if (my === seq) render(d);
    } catch (err) {
      if (!ctx.signal.aborted && my === seq) sheet.innerHTML = emptyState('warning', 'Could not work out the ratios', err.message);
    } finally {
      if (my === seq) sheet.classList.remove('is-busy');
    }
  };

  form.addEventListener('submit', (e) => e.preventDefault());
  form.elements.as_of.addEventListener('change', (e) => {
    if (!YMD.test(e.target.value)) return;
    state.as_of = e.target.value;
    load();
  });
  qs('[data-print]', root).addEventListener('click', () => window.print());
  qs('[data-csv]', root).addEventListener('click', (e) => exportCsv(e.currentTarget, '/reports/analysis', { as_of: state.as_of }, 'financial-analysis-' + state.as_of + '.csv'));

  await load();
}
