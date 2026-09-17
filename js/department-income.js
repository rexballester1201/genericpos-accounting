/**
 * department-income.js — the income statement, one column per department
 *
 * GenericPOS Accounting · ES module · every role
 *
 *   GET /reports/department-income?from=&to=&levels=&zero=1
 *
 * One column for each active department (and for an inactive one that still
 * has income or expenses in the period), one for lines no department was put
 * on, and the total — which is the company's income statement for the same
 * period, so the columns always add up to it. Many columns print on a
 * landscape page, the way the month-by-month income statement does.
 */

import { api } from './api.js';
import { todayYmd, store } from './store.js';
import { qs, qsa, esc, emptyState } from './ui.js';
import { amt, sheetHead, sheetFoot, presets, presetFor, periodText, glHref, syncQuery, exportCsv } from './report-kit.js';

const YMD = /^\d{4}-\d{2}-\d{2}$/;
const LEVELS = [['1', 'Summary'], ['2', 'Line items'], ['0', 'Every account']];

export async function mount(root, ctx) {
  const coop = store().entity_type === 'cooperative';
  const today = todayYmd();
  const q = ctx.query;
  const state = {
    from: YMD.test(q.get('from') || '') ? q.get('from') : '',
    to: YMD.test(q.get('to') || '') ? q.get('to') : today,
    levels: ['0', '1', '2'].includes(q.get('levels')) ? q.get('levels') : '2',
    zero: q.get('zero') === '1',
  };
  const sheet = qs('[data-sheet]', root);
  const form = qs('[data-controls]', root);
  const warn = qs('[data-warn]', root);

  let years = [];
  try { years = (await api.get('/fiscal-years', null, { signal: ctx.signal })).years || []; } catch { /* the presets fall back to the calendar */ }
  if (ctx.signal.aborted) return;
  if (!state.from) {
    const fy = years.find((y) => y.start_date <= state.to && state.to <= y.end_date);
    state.from = fy ? fy.start_date : state.to.slice(0, 4) + '-01-01';
  }
  const list = presets(years, today);

  form.innerHTML = '<select class="select" name="preset" aria-label="Period" style="width:auto">'
    + list.map((p) => '<option value="' + p.key + '">' + esc(p.label) + '</option>').join('') + '<option value="custom">Custom dates</option></select>'
    + '<input class="input" type="date" name="from" aria-label="From" style="width:auto"><span class="faint">to</span>'
    + '<input class="input" type="date" name="to" aria-label="To" style="width:auto">'
    + '<div class="segmented" role="group" aria-label="Detail" data-seg>' + LEVELS.map(([v, l]) => '<button type="button" data-v="' + v + '">' + l + '</button>').join('') + '</div>'
    + '<label class="check small"><input type="checkbox" name="zero"> Zero balances</label>';
  const f = form.elements;
  f.from.value = state.from;
  f.to.value = state.to;
  f.preset.value = presetFor(list, state.from, state.to);
  f.zero.checked = state.zero;
  const paintSeg = () => qsa('[data-seg] button', form).forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.v === state.levels)));
  paintSeg();

  const params = () => ({ from: state.from, to: state.to, levels: state.levels, zero: state.zero ? 1 : '' });

  const render = (d) => {
    const cols = d.columns || [];
    const depts = d.departments || [];
    const title = (coop ? 'Statement of Operations' : d.title) + ' by Department';
    const lines = [periodText(state.from, state.to)];
    qs('[data-title]', root).textContent = 'Income by department';
    qs('[data-sub]', root).textContent = lines[0] + ' · ' + depts.length + ' department' + (depts.length === 1 ? '' : 's');
    ctx.setTitle('Income by department');

    const heads = cols.map((c, i) => {
      const x = depts[i];
      if (x) return '<th title="' + esc(x.name) + '">' + esc(x.code) + '<span class="sub" style="font-weight:400">' + esc(x.name + (x.is_active ? '' : ' (inactive)')) + '</span></th>';
      return '<th>' + esc(c.total ? 'Total' : 'No department') + '</th>';
    }).join('');

    const body = (d.lines || []).map((l) => {
      const cls = 'l-' + l.type + (l.strong ? ' is-strong' : '');
      const pad = ' style="padding-left:' + (10 + (l.indent || 0) * 18) + 'px"';
      let label = esc(l.label);
      if ((l.type === 'account' || l.type === 'group') && l.id) {
        label = '<a href="' + esc(glHref(l.id, state.from, state.to)) + '">' + (state.levels === '0' && l.code ? '<span class="code">' + esc(l.code) + '</span>' : '') + label + '</a>';
      }
      if (!Array.isArray(l.amounts)) return '<tr class="' + cls + '"><td' + pad + ' colspan="' + (cols.length + 1) + '">' + label + '</td></tr>';
      return '<tr class="' + cls + '"><td' + pad + '>' + label + '</td>' + l.amounts.map((a) => '<td class="n">' + amt(a) + '</td>').join('') + '</tr>';
    }).join('');

    const notes = ['Each department\'s column counts only the lines marked for it; No department holds the lines without one; Total is the '
      + (coop ? 'statement of operations' : 'income statement') + ' for the whole ' + (coop ? 'co-operative' : 'company') + ', so the columns add up to it.',
      'Closing entries are left out, so a closed year still shows what each department earned.'];
    if (depts.some((x) => !x.is_active)) notes.push('An inactive department appears only while it has income or expenses in the period.');
    notes.push('Open an account to see its general ledger.');

    const failed = Object.keys(d.checks || {}).filter((k) => (d.checks[k] || []).some((x) => x === false));
    warn.hidden = !failed.length;
    if (failed.length) warn.textContent = 'This report does not prove out: the columns do not add up to the total, or a column does not tie to its accounts. Tell your administrator before relying on these figures.';

    sheet.classList.toggle('is-wide', cols.length > 3);
    sheet.innerHTML = sheetHead(d.letterhead, title, lines)
      + '<div class="table-wrap"><table class="stmt fs"><thead><tr><th></th>' + heads + '</tr></thead><tbody>' + body + '</tbody></table></div>'
      + '<p class="report-foot">' + esc(notes.join(' ')) + '</p>' + sheetFoot(d.letterhead);
  };

  let seq = 0;
  let last = null;
  const load = async () => {
    const my = ++seq;
    syncQuery({ from: state.from, to: state.to, levels: state.levels === '2' ? '' : state.levels, zero: state.zero });
    warn.hidden = true;
    sheet.classList.add('is-busy');
    try {
      const d = await api.get('/reports/department-income', params(), { signal: ctx.signal });
      if (my === seq) { last = d; render(d); }
    } catch (err) {
      if (ctx.signal.aborted || my !== seq) return;
      const msg = err.errors ? Object.values(err.errors).join(' ') : err.message;
      if (last && err.errors) { warn.textContent = msg; warn.hidden = false; }
      else sheet.innerHTML = emptyState('warning', 'Could not load the report', msg);
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
    } else if (t.name === 'zero') {
      state.zero = t.checked;
    } else return;
    load();
  });
  form.addEventListener('click', (e) => {
    const b = e.target.closest('[data-seg] button');
    if (!b || b.dataset.v === state.levels) return;
    state.levels = b.dataset.v;
    paintSeg();
    load();
  });
  qs('[data-print]', root).addEventListener('click', () => window.print());
  qs('[data-csv]', root).addEventListener('click', (e) => exportCsv(e.currentTarget, '/reports/department-income', params(),
    'income-by-department-' + state.from + '-to-' + state.to + '.csv'));

  await load();
}
