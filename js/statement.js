/**
 * statement.js — the financial statements
 *
 * GenericPOS Accounting · ES module · every role
 *
 *   /reports/balance-sheet       as of a date; beside the end of last year or the same date last year
 *   /reports/income-statement    for a period; beside the previous period or last year, or month by month
 *   /reports/changes-in-equity   for a period
 *   /reports/cash-flows          for a period, by the indirect method
 *
 * The server lays each statement out from the chart of accounts
 * (Statement_lib). This screen draws the lines, adds the percentage and
 * change columns, and keeps every choice in the address bar, so a reload, a
 * bookmark or a shared link shows the same statement. Each account opens its
 * general ledger for the period behind the figure.
 */

import { api } from './api.js';
import { todayYmd, fmtDay, store } from './store.js';
import { qs, qsa, esc, emptyState } from './ui.js';
import { amt, pct, sheetHead, sheetFoot, presets, presetFor, periodText, asOfText, colLabel, glHref, syncQuery, exportCsv } from './report-kit.js';

const YMD = /^\d{4}-\d{2}-\d{2}$/;
const API = {
  'balance-sheet': '/reports/balance-sheet',
  'income-statement': '/reports/income-statement',
  'changes-in-equity': '/reports/changes-in-equity',
  'cash-flows': '/reports/cash-flows',
};

export async function mount(root, ctx) {
  const kind = API[ctx.route.params && ctx.route.params.kind] ? ctx.route.params.kind : 'balance-sheet';
  const bs = kind === 'balance-sheet';
  const is = kind === 'income-statement';
  const coop = store().entity_type === 'cooperative';
  const netWord = coop ? 'Net surplus' : 'Net income';
  const today = todayYmd();
  const q = ctx.query;
  const ymd = (k) => (YMD.test(q.get(k) || '') ? q.get(k) : '');
  const detailDefault = kind === 'changes-in-equity' ? '0' : '2';
  const state = {
    as_of: ymd('as_of') || ymd('to') || today,
    from: ymd('from'),
    to: ymd('to') || ymd('as_of') || today,
    compare: q.get('compare') || '',
    levels: ['0', '1', '2'].includes(q.get('levels')) ? q.get('levels') : detailDefault,
    pct: q.get('pct') === '1',
    zero: q.get('zero') === '1',
    department: q.get('department') || '',
  };

  const sheet = qs('[data-sheet]', root);
  const form = qs('[data-controls]', root);
  const warn = qs('[data-warn]', root);
  const tabs = qs('[data-tabs]', root);

  let years = [];
  try { years = (await api.get('/fiscal-years', null, { signal: ctx.signal })).years || []; } catch { /* the presets fall back to the calendar */ }
  if (ctx.signal.aborted) return;
  if (!state.from) {
    const fy = years.find((y) => y.start_date <= state.to && state.to <= y.end_date);
    state.from = fy ? fy.start_date : state.to.slice(0, 4) + '-01-01';
  }
  const list = presets(years, today);

  // ── the choices ───────────────────────────────────────────────────────
  const detail = kind === 'cash-flows' ? [['2', 'Line items'], ['0', 'Every account']]
    : kind === 'changes-in-equity' ? [['0', 'Every account'], ['2', 'Line items']]
    : [['1', 'Summary'], ['2', 'Line items'], ['0', 'Every account']];
  let html = bs
    ? '<label class="row gap-2 small" for="st-asof">As of <input class="input" type="date" name="as_of" id="st-asof" style="width:auto"></label>'
      + '<select class="select" name="compare" id="st-compare" aria-label="Compare with" style="width:auto"><option value="">No comparison</option>'
      + '<option value="prior_year_end">Beside the end of last fiscal year</option><option value="prior_year">Beside the same date last year</option></select>'
    : '<select class="select" name="preset" id="st-preset" aria-label="Period" style="width:auto">'
      + list.map((p) => '<option value="' + p.key + '">' + esc(p.label) + '</option>').join('') + '<option value="custom">Custom dates</option></select>'
      + '<input class="input" type="date" name="from" id="st-from" aria-label="From" style="width:auto"><span class="faint">to</span>'
      + '<input class="input" type="date" name="to" id="st-to" aria-label="To" style="width:auto">';
  if (is) {
    html += '<select class="select" name="compare" id="st-compare" aria-label="Compare with" style="width:auto"><option value="">No comparison</option>'
      + '<option value="prior_period">Beside the previous period</option><option value="prior_year">Beside the same period last year</option>'
      + '<option value="monthly">Month by month</option></select>'
      + '<select class="select" name="department" id="st-dept" aria-label="Department" style="width:auto" hidden><option value="">All departments</option></select>';
  }
  html += '<div class="segmented" role="group" aria-label="Detail" data-seg>'
    + detail.map(([v, l]) => '<button type="button" data-v="' + v + '">' + esc(l) + '</button>').join('') + '</div>';
  if (bs || is) {
    html += '<label class="check small"><input type="checkbox" name="pct" id="st-pct"> % of ' + (bs ? 'total assets' : (coop ? 'revenues' : 'revenue')) + '</label>'
      + '<label class="check small"><input type="checkbox" name="zero" id="st-zero"> Zero balances</label>';
  }
  form.innerHTML = html;
  const f = form.elements;
  if (f.as_of) f.as_of.value = state.as_of;
  if (f.from) { f.from.value = state.from; f.to.value = state.to; f.preset.value = presetFor(list, state.from, state.to); }
  if (f.compare) { f.compare.value = state.compare; state.compare = f.compare.value; } else state.compare = '';
  if (f.pct) f.pct.checked = state.pct;
  if (f.zero) f.zero.checked = state.zero;
  const paintSeg = () => qsa('[data-seg] button', form).forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.v === state.levels)));
  paintSeg();

  // ── the address bar, the request, the tabs ────────────────────────────
  const query = () => (bs
    ? { as_of: state.as_of === today ? '' : state.as_of, compare: state.compare, levels: state.levels === detailDefault ? '' : state.levels, pct: state.pct, zero: state.zero }
    : { from: state.from, to: state.to, compare: is ? state.compare : '', department: is ? state.department : '',
        levels: state.levels === detailDefault ? '' : state.levels, pct: is && state.pct, zero: is && state.zero });
  const params = () => (bs
    ? { as_of: state.as_of, compare: state.compare, levels: state.levels, zero: state.zero ? 1 : '' }
    : Object.assign({ from: state.from, to: state.to, levels: state.levels },
      is ? { compare: state.compare, department: state.department, zero: state.zero ? 1 : '' } : {}));

  const TABS = [['balance-sheet', coop ? 'Financial condition' : 'Balance sheet'], ['income-statement', coop ? 'Operations' : 'Income statement'],
    ['changes-in-equity', 'Changes in equity'], ['cash-flows', 'Cash flows']];
  const paintTabs = () => {
    const end = bs ? state.as_of : state.to;
    tabs.innerHTML = TABS.map(([k, l]) => {
      const p = new URLSearchParams(k === 'balance-sheet' ? { as_of: end } : (bs ? { to: end } : { from: state.from, to: state.to }));
      return '<a class="tab" role="tab" href="reports/' + k + '?' + esc(p.toString()) + '" aria-selected="' + (k === kind) + '">' + esc(l) + '</a>';
    }).join('');
  };

  // ── drawing the statement ─────────────────────────────────────────────
  let last = null;
  const render = (d) => {
    last = d;
    const cols = d.columns || [];
    const n = cols.length;
    const showPct = state.pct && Array.isArray(d.base);
    const showChange = n === 2 && (bs || (is && state.compare !== 'monthly'));
    const span = 1 + n * (showPct ? 2 : 1) + (showChange ? 2 : 0);
    const dept = is && d.params && d.params.department ? (d.departments || []).find((x) => x.id === d.params.department) : null;
    const lines = [bs ? asOfText(state.as_of) : periodText(state.from, state.to), dept ? 'Department ' + dept.code + ' · ' + dept.name : ''];

    qs('[data-title]', root).textContent = d.title;
    qs('[data-sub]', root).textContent = lines.filter(Boolean).join(' · ');
    ctx.setTitle(d.title);

    const SCE = { beginning: (c) => 'Balance, ' + fmtDay(c.label_date || c.as_of), net_income: () => netWord, other: () => 'Other changes', ending: (c) => 'Balance, ' + fmtDay(c.as_of) };
    const heads = cols.map((c) => '<th>' + esc(kind === 'changes-in-equity' ? SCE[c.key](c) : colLabel(c)) + '</th>' + (showPct ? '<th class="p">%</th>' : '')).join('')
      + (showChange ? '<th>Change</th><th class="p">%</th>' : '');

    const glFrom = bs ? ((cols[0] && cols[0].fy_start) || state.as_of.slice(0, 4) + '-01-01') : state.from;
    const glTo = bs ? state.as_of : state.to;
    const body = (d.lines || []).map((l) => {
      const cls = 'l-' + l.type + (l.strong ? ' is-strong' : '');
      const pad = ' style="padding-left:' + (10 + (l.indent || 0) * 18) + 'px"';
      let label = esc(l.label);
      if ((l.type === 'account' || l.type === 'group') && l.id) {
        label = '<a href="' + esc(glHref(l.id, glFrom, glTo)) + '">' + (state.levels === '0' && l.code ? '<span class="code">' + esc(l.code) + '</span>' : '') + label + '</a>';
      }
      if (!Array.isArray(l.amounts)) return '<tr class="' + cls + '"><td' + pad + ' colspan="' + span + '">' + label + '</td></tr>';
      let cells = '';
      l.amounts.forEach((a, i) => {
        cells += '<td class="n">' + amt(a) + '</td>';
        if (showPct) cells += '<td class="p">' + pct(a, d.base[i]) + '</td>';
      });
      if (showChange) {
        const ch = l.amounts[0] - l.amounts[1];
        cells += '<td class="n">' + amt(ch) + '</td><td class="p">' + pct(ch, Math.abs(l.amounts[1])) + '</td>';
      }
      return '<tr class="' + cls + '"><td' + pad + '>' + label + '</td>' + cells + '</tr>';
    }).join('');

    const notes = [];
    if (bs) {
      notes.push('Every posted entry up to the date counts.');
      if ((d.lines || []).some((l) => l.type === 'computed')) notes.push(netWord + ' stays on its own line in equity until the year-end closing entries transfer it.');
    }
    if (is) {
      notes.push('Closing entries are left out, so a closed year still shows what it earned.');
      if (dept) notes.push('Only lines marked for ' + dept.name + ' count; income and expenses with no department are left out.');
    }
    if (kind === 'changes-in-equity') {
      notes.push('Other changes are contributions, withdrawals, dividends and transfers between funds, and the closing entries that move ' + netWord.toLowerCase() + ' into equity.');
    }
    if (kind === 'cash-flows') {
      notes.push('Indirect method: ' + netWord.toLowerCase() + ', with depreciation added back and the change in every other balance-sheet account. '
        + 'Opening-balance entries count as the starting position; closing entries move no cash and are left out.');
    }
    notes.push('Open an account to see its general ledger.');

    const failed = Object.keys(d.checks || {}).filter((k) => (d.checks[k] || []).some((x) => x === false));
    warn.hidden = !failed.length;
    if (failed.length) warn.textContent = 'This statement does not prove out. Posted entries always balance, so this points to damaged data. Tell your administrator before relying on these figures.';

    sheet.classList.toggle('is-wide', span > 5);
    sheet.innerHTML = sheetHead(d.letterhead, d.title, lines)
      + '<div class="table-wrap"><table class="stmt fs"><thead><tr><th></th>' + heads + '</tr></thead><tbody>' + body + '</tbody></table></div>'
      + '<p class="report-foot">' + esc(notes.join(' ')) + '</p>'
      + sheetFoot(d.letterhead);

    if (f.department && Array.isArray(d.departments)) {
      if (f.department.options.length <= 1) {
        f.department.insertAdjacentHTML('beforeend', d.departments.map((x) => '<option value="' + x.id + '">' + esc(x.code + ' · ' + x.name) + '</option>').join(''));
      }
      f.department.hidden = !d.departments.length;
      f.department.value = state.department;
    }
  };

  let seq = 0;
  const load = async () => {
    const my = ++seq;
    syncQuery(query());
    paintTabs();
    warn.hidden = true;
    sheet.classList.add('is-busy');
    try {
      const d = await api.get(API[kind], params(), { signal: ctx.signal });
      if (my === seq) render(d);
    } catch (err) {
      if (ctx.signal.aborted || my !== seq) return;
      const msg = err.errors ? Object.values(err.errors).join(' ') : err.message;
      if (last && err.errors) { warn.textContent = msg; warn.hidden = false; }
      else sheet.innerHTML = emptyState('warning', 'Could not load the statement', msg);
    } finally {
      if (my === seq) sheet.classList.remove('is-busy');
    }
  };

  form.addEventListener('submit', (e) => e.preventDefault());
  form.addEventListener('change', (e) => {
    const t = e.target;
    switch (t.name) {
      case 'preset': {
        const p = list.find((x) => x.key === t.value);
        if (!p) return;
        state.from = p.from;
        state.to = p.to;
        f.from.value = p.from;
        f.to.value = p.to;
        break;
      }
      case 'from':
      case 'to':
      case 'as_of':
        if (!YMD.test(t.value)) return;
        state[t.name] = t.value;
        if (f.preset) f.preset.value = presetFor(list, state.from, state.to);
        break;
      case 'compare': state.compare = t.value; break;
      case 'department': state.department = t.value; break;
      case 'zero': state.zero = t.checked; break;
      case 'pct':
        state.pct = t.checked;
        syncQuery(query());
        if (last) render(last);
        return;
      default: return;
    }
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
  qs('[data-csv]', root).addEventListener('click', (e) => exportCsv(e.currentTarget, API[kind], params(),
    kind + '-' + (bs ? state.as_of : state.from + '-to-' + state.to) + '.csv'));

  await load();
}
