/**
 * budget-vs-actual.js — the budget beside what actually happened
 *
 * GenericPOS Accounting · ES module · every role
 *
 *   GET /reports/budget-vs-actual?budget_id=&from_period=&to_period=&department_id=&levels=&zero=1
 *
 * Rows are the income statement's (the server lays both columns out with the
 * income statement's own code, so Actual is exactly the income statement for
 * the months chosen). Variance is actual minus budget; F marks a favourable
 * one (income above budget, or an expense below it), U an unfavourable one.
 * The choices live in the address bar, so a reload or a shared link shows
 * the same report.
 */

import { api } from './api.js';
import { store } from './store.js';
import { qs, qsa, esc, emptyState } from './ui.js';
import { amt, pct, sheetHead, sheetFoot, periodText, glHref, syncQuery, exportCsv } from './report-kit.js';

const LEVELS = [['1', 'Summary'], ['2', 'Line items'], ['0', 'Every account']];

export async function mount(root, ctx) {
  const coop = store().entity_type === 'cooperative';
  const q = ctx.query;
  const state = {
    budget_id: q.get('budget_id') || '',
    from_period: q.get('from_period') || '',
    to_period: q.get('to_period') || '',
    department_id: q.get('department_id') || '',
    levels: ['0', '1', '2'].includes(q.get('levels')) ? q.get('levels') : '2',
    zero: q.get('zero') === '1',
  };
  const sheet = qs('[data-sheet]', root);
  const form = qs('[data-controls]', root);
  const warn = qs('[data-warn]', root);
  let d = null;

  const params = () => ({ budget_id: state.budget_id, from_period: state.from_period, to_period: state.to_period, department_id: state.department_id,
    levels: state.levels, zero: state.zero ? 1 : '' });

  // ── the choices, drawn once the first answer says what there is ───────
  const paintControls = () => {
    const years = [];
    d.budgets.forEach((b) => { if (!years.includes(b.fiscal_year)) years.push(b.fiscal_year); });
    const cur = d.current_period;
    form.innerHTML = '<select class="select" name="budget_id" aria-label="Budget" style="width:auto">'
      + years.map((y) => '<optgroup label="' + esc(y) + '">' + d.budgets.filter((b) => b.fiscal_year === y).map((b) => '<option value="' + b.id + '">'
        + esc(b.name + (b.is_primary ? ' (primary)' : '') + (b.status === 'draft' ? ' (draft)' : '')) + '</option>').join('') + '</optgroup>').join('') + '</select>'
      + '<div class="segmented" role="group" aria-label="Months" data-range>'
      + [['month', 'Month'], ['quarter', 'Quarter'], ['ytd', 'Year to date'], ['year', 'Full year']].map(([k, l]) => '<button type="button" data-r="' + k + '">' + l + '</button>').join('') + '</div>'
      + '<select class="select" name="from_period" aria-label="From month" style="width:auto">' + d.periods.map((p) => '<option value="' + p.no + '">' + esc(p.name) + '</option>').join('') + '</select>'
      + '<span class="faint">to</span>'
      + '<select class="select" name="to_period" aria-label="To month" style="width:auto">' + d.periods.map((p) => '<option value="' + p.no + '">' + esc(p.name) + '</option>').join('') + '</select>'
      + '<select class="select" name="department_id" aria-label="Department" style="width:auto"><option value="">All departments</option><option value="0">Company-wide lines only</option>'
      + d.departments.map((x) => '<option value="' + x.id + '">' + esc(' '.repeat(x.depth) + x.code + ' · ' + x.name + (x.is_active ? '' : ' (inactive)')) + '</option>').join('') + '</select>'
      + '<div class="segmented" role="group" aria-label="Detail" data-seg>' + LEVELS.map(([v, l]) => '<button type="button" data-v="' + v + '">' + l + '</button>').join('') + '</div>'
      + '<label class="check small"><input type="checkbox" name="zero"> Zero lines</label>';
    const f = form.elements;
    f.budget_id.value = String(d.params.budget_id);
    f.from_period.value = String(d.params.from_period);
    f.to_period.value = String(d.params.to_period);
    f.department_id.value = d.params.department_id;
    f.zero.checked = !!d.params.zero;
    qsa('[data-seg] button', form).forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.v === String(d.params.levels))));
    const fp = d.params.from_period;
    const tp = d.params.to_period;
    const last = d.periods.length;
    const now = cur || last;
    const qs0 = Math.floor((tp - 1) / 3) * 3 + 1;
    const range = fp === tp ? 'month' : (fp === qs0 && tp === Math.min(qs0 + 2, last) ? 'quarter' : (fp === 1 && tp === now && now !== last ? 'ytd' : (fp === 1 && tp === last ? 'year' : '')));
    qsa('[data-range] button', form).forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.r === range)));
  };

  // ── the sheet ─────────────────────────────────────────────────────────
  const mark = (fav) => (fav === null || fav === undefined ? '' : fav
    ? '<span class="badge badge-ok" title="Favourable">F</span>' : '<span class="badge badge-err" title="Unfavourable">U</span>');

  const render = () => {
    const p = d.params;
    const b = d.budget;
    const lines = [periodText(p.from, p.to), 'Budget: ' + b.name + ' · ' + b.fiscal_year.name + (b.status === 'draft' ? ' (draft, not yet approved)' : ''), d.department_text];
    qs('[data-sub]', root).textContent = lines.join(' · ');
    ctx.setTitle('Budget vs actual');

    const body = d.lines.map((l) => {
      const cls = 'l-' + l.type + (l.strong ? ' is-strong' : '');
      const pad = ' style="padding-left:' + (10 + (l.indent || 0) * 18) + 'px"';
      let label = esc(l.label);
      if ((l.type === 'account' || l.type === 'group') && l.id) {
        label = '<a href="' + esc(glHref(l.id, p.from, p.to)) + '">' + (String(p.levels) === '0' && l.code ? '<span class="code">' + esc(l.code) + '</span>' : '') + label + '</a>';
      }
      if (!Array.isArray(l.amounts)) return '<tr class="' + cls + '"><td' + pad + ' colspan="6">' + label + '</td></tr>';
      return '<tr class="' + cls + '"><td' + pad + '>' + label + '</td><td class="n">' + amt(l.amounts[0]) + '</td><td class="n">' + amt(l.amounts[1]) + '</td>'
        + '<td class="n">' + amt(l.variance) + '</td><td class="p">' + pct(l.variance, Math.abs(l.amounts[1])) + '</td><td class="p">' + mark(l.favourable) + '</td></tr>';
    }).join('');

    const s = d.summary;
    const short = ['income', 'expenses', 'net'].map((k) => '<tr class="' + (k === 'net' ? 'l-grand' : 'l-total') + '"><td>' + esc(s[k].label) + '</td>'
      + '<td class="n">' + amt(s[k].actual_cents) + '</td><td class="n">' + amt(s[k].budget_cents) + '</td><td class="n">' + amt(s[k].variance_cents) + '</td>'
      + '<td class="p">' + pct(s[k].variance_cents, Math.abs(s[k].budget_cents)) + '</td><td class="p">' + mark(s[k].favourable) + '</td></tr>').join('');

    const notes = ['Actual is posted entries with closing entries left out — the income statement\'s own figures for the same months.',
      'The budget counts the months chosen in full; a month still running has only what has been posted so far.'];
    if (p.department_id === '0') notes.push('Company-wide lines only: budget amounts with no department against posted lines with no department.');
    else if (p.department_id) notes.push('Only the department\'s own budget amounts and the posted lines marked for it count.');
    else notes.push('Every budget amount against every posted line, whatever the department.');
    notes.push('Variance is actual minus budget. F marks a favourable variance (income above budget, or an expense below it); U an unfavourable one.');

    const failed = (d.checks.ties || []).some((x) => x === false);
    warn.hidden = !failed;
    if (failed) warn.textContent = 'This report does not prove out against its accounts. Posted entries always balance, so this points to damaged data. Tell your administrator before relying on these figures.';

    const head = '<thead><tr><th></th><th>Actual</th><th>Budget</th><th>Variance</th><th class="p">%</th><th class="p"><span class="sr-only">Favourable or unfavourable</span></th></tr></thead>';
    sheet.innerHTML = sheetHead(d.letterhead, 'Budget vs Actual', lines)
      + '<div class="table-wrap"><table class="stmt fs">' + head + '<tbody>' + body + '</tbody></table></div>'
      + '<h3 style="margin:24px 0 6px;font-size:14px">In short</h3>'
      + '<div class="table-wrap"><table class="stmt fs">' + head + '<tbody>' + short + '</tbody></table></div>'
      + '<p class="report-foot">' + esc(notes.join(' ')) + '</p>' + sheetFoot(d.letterhead);
  };

  let seq = 0;
  const load = async () => {
    const my = ++seq;
    sheet.classList.add('is-busy');
    warn.hidden = true;
    try {
      const r = await api.get('/reports/budget-vs-actual', params(), { signal: ctx.signal });
      if (my !== seq) return;
      d = r;
      if (!d.budget) {
        form.innerHTML = '';
        qs('[data-sub]', root).textContent = 'There is no budget yet.';
        sheet.innerHTML = emptyState('sliders', 'No budget to compare with', 'An accountant prepares budgets under Budgets. The primary budget of this fiscal year shows here by default.',
          '<a class="btn btn-secondary" href="budgets">Budgets</a>');
        return;
      }
      Object.assign(state, { budget_id: String(d.params.budget_id), from_period: String(d.params.from_period), to_period: String(d.params.to_period),
        department_id: d.params.department_id, levels: String(d.params.levels), zero: !!d.params.zero });
      syncQuery({ budget_id: state.budget_id, from_period: state.from_period, to_period: state.to_period, department_id: state.department_id,
        levels: state.levels === '2' ? '' : state.levels, zero: state.zero });
      paintControls();
      render();
    } catch (err) {
      if (ctx.signal.aborted || my !== seq) return;
      const msg = err.errors ? Object.values(err.errors).join(' ') : err.message;
      if (d && d.budget && err.errors) { warn.textContent = msg; warn.hidden = false; }
      else sheet.innerHTML = emptyState('warning', 'Could not load the report', msg);
    } finally {
      if (my === seq) sheet.classList.remove('is-busy');
    }
  };

  form.addEventListener('submit', (e) => e.preventDefault());
  form.addEventListener('change', (e) => {
    const t = e.target;
    if (t.name === 'budget_id') { state.budget_id = t.value; state.from_period = ''; state.to_period = ''; }
    else if (t.name === 'zero') state.zero = t.checked;
    else if (['from_period', 'to_period', 'department_id'].includes(t.name)) state[t.name] = t.value;
    else return;
    load();
  });
  form.addEventListener('click', (e) => {
    const seg = e.target.closest('[data-seg] button');
    if (seg && seg.dataset.v !== state.levels) { state.levels = seg.dataset.v; load(); return; }
    const r = e.target.closest('[data-range] button');
    if (!r || !d) return;
    const last = d.periods.length;
    const now = d.current_period && d.budget.fiscal_year.start_date <= d.params.to ? d.current_period : 0;
    const anchor = now || Number(state.to_period) || last;
    if (r.dataset.r === 'month') { state.from_period = String(anchor); state.to_period = String(anchor); }
    if (r.dataset.r === 'quarter') { const s = Math.floor((anchor - 1) / 3) * 3 + 1; state.from_period = String(s); state.to_period = String(Math.min(s + 2, last)); }
    if (r.dataset.r === 'ytd') { state.from_period = '1'; state.to_period = String(now || last); }
    if (r.dataset.r === 'year') { state.from_period = '1'; state.to_period = String(last); }
    load();
  });
  qs('[data-print]', root).addEventListener('click', () => window.print());
  qs('[data-csv]', root).addEventListener('click', (e) => {
    if (!d || !d.budget) return;
    exportCsv(e.currentTarget, '/reports/budget-vs-actual', params(), 'budget-vs-actual-' + d.budget.fiscal_year.name + '-' + state.from_period + '-to-' + state.to_period + '.csv');
  });

  if (coop) qs('[data-sub]', root).textContent = 'Revenues and costs against the budget, and the net surplus.';
  await load();
}
