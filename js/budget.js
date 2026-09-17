/**
 * budget.js — one budget: the grid of amounts, its row tools, and its workflow
 *
 * GenericPOS Accounting · ES module · every role reads it; accountants change, approve and import
 *
 * Rows are the income and expense accounts grouped under their headers, in
 * the income statement's order; columns are the twelve months of the fiscal
 * year and the total. The department selector picks whose amounts are shown:
 * company-wide (lines with no department), one department, or all of them
 * added up (read-only).
 *
 * Typed amounts wait on the screen — tinted, counted beside Save changes —
 * until they are saved together in one request; a zero clears a line. Row
 * tools spread a yearly amount evenly (the leftover centavos go to the first
 * months, so the twelve add up exactly), copy the first month across, change
 * the row by a percentage, or clear it. An approved budget is read-only.
 */

import { api, download } from './api.js';
import { money, toCents, toMajor, fmtDate } from './store.js';
import { qs, qsa, esc, emptyState, statusBadge, openModal, confirmDialog, promptDialog, toast, busy, formError, clearErrors } from './ui.js';
import { appPath } from './router.js';
import { amt, syncQuery } from './report-kit.js';

// ─── Exact arithmetic for the row tools (also used by the module's tests) ────

/** Split `total` centavos into `n` whole-centavo parts that add up exactly: the leftover centavos go to the first months. */
export function spread(total, n = 12) {
  const t = Math.round(Number(total) || 0);
  const base = Math.trunc(t / n);
  let rest = t - base * n;
  const out = [];
  for (let i = 0; i < n; i++) {
    let v = base;
    if (rest > 0) { v++; rest--; } else if (rest < 0) { v--; rest++; }
    out.push(v);
  }
  return out;
}

/** "5", "-2.5", "+10%" → basis points (500, -250, 1000); null when it is not a sensible change. */
export function percentBp(text) {
  const s = String(text == null ? '' : text).replace(/[%\s]/g, '');
  const m = /^([+-]?)(\d{1,4})(?:\.(\d{1,2}))?$/.exec(s);
  if (!m) return null;
  const bp = (Number(m[2]) * 100 + Number((m[3] || '').padEnd(2, '0'))) * (m[1] === '-' ? -1 : 1);
  return bp <= -10000 || bp > 100000 ? null : bp;
}

/** `cents` changed by `bp` basis points, rounded to the centavo, half away from zero (BigInt, so large amounts stay exact). */
export function applyPercent(cents, bp) {
  const num = BigInt(Math.round(cents)) * BigInt(10000 + bp);
  const q = num >= 0n ? (num * 2n + 10000n) / 20000n : -((-num * 2n + 10000n) / 20000n);
  return Number(q);
}

const ACTIONS = {
  'budget.create': 'Created', 'budget.update': 'Changed', 'budget.import': 'Imported from a CSV file', 'budget.approve': 'Approved',
  'budget.return': 'Returned to draft', 'budget.primary': 'Made the primary budget', 'budget.delete': 'Deleted',
};

export async function mount(root, ctx) {
  const id = parseInt(ctx.params.id, 10) || 0;
  const stateEl = qs('[data-state]', root);
  const grid = qs('[data-grid]', root);
  const ctrls = qs('[data-controls]', root);
  const sel = ctrls.elements.dept;
  const gridErr = qs('[data-grid-error]', root);
  const saveBtn = qs('[data-save]', root);
  const discardBtn = qs('[data-discard]', root);
  const actions = qs('[data-actions]', root);

  let d = null;
  let view = ctx.query.get('department') || '';
  const base = new Map();     // 'department|account|period' → saved centavos
  const edits = new Map();    // the same keys → typed, not yet saved
  const bad = new Set();      // keys whose text is not an amount
  const K = (dep, a, p) => dep + '|' + a + '|' + p;
  const lc = (s) => s.charAt(0).toLowerCase() + s.slice(1);

  const take = (data) => {
    d = data;
    base.clear();
    edits.clear();
    bad.clear();
    d.lines.forEach(([a, dep, p, c]) => base.set(K(dep, a, p), c));
  };
  const val = (dep, a, p) => { const k = K(dep, a, p); return edits.has(k) ? edits.get(k) : (base.get(k) || 0); };
  const deptKeys = () => {
    const s = new Set([0]);
    base.forEach((_, k) => s.add(Number(k.split('|')[0])));
    edits.forEach((_, k) => s.add(Number(k.split('|')[0])));
    return [...s];
  };
  const shown = (a, p, depts) => (view === 'all' ? depts.reduce((s, x) => s + val(x, a, p), 0) : val(Number(view), a, p));
  const editable = () => !!(d && d.can.edit && view !== 'all');
  const accountRow = (aid) => { for (const s of d.sections) for (const r of s.rows) if (r.kind === 'account' && r.id === aid) return r; return null; };
  const setCell = (dep, a, p, v) => { const k = K(dep, a, p); bad.delete(k); if (v === (base.get(k) || 0)) edits.delete(k); else edits.set(k, v); };
  const deptLabel = () => {
    if (view === 'all') return 'all departments';
    const x = d.departments.find((y) => String(y.id) === view);
    return !x || x.id === 0 ? 'company-wide' : x.code + ' · ' + x.name;
  };

  // ── the grid ──────────────────────────────────────────────────────────
  const stick = 'position:sticky;left:0;background:var(--surface);z-index:1;';
  const renderGrid = () => {
    const ed = editable();
    const P = d.periods;
    const depts = deptKeys();
    const cols = P.length + 2 + (ed ? 1 : 0);
    let h = '<thead><tr><th style="' + stick + 'min-width:16em">Account</th>' + P.map((p) => '<th class="num">' + esc(p.name) + '</th>').join('')
      + '<th class="num">Total</th>' + (ed ? '<th><span class="sr-only">Row tools</span></th>' : '') + '</tr></thead><tbody>';
    const computed = (key, label, strong) => '<tr class="totals"><td style="' + stick + (strong ? 'font-weight:800' : 'font-weight:700') + '">' + esc(label) + '</td>'
      + P.map((p) => '<td class="num" data-ct="' + key + '|' + p.no + '"></td>').join('') + '<td class="num" data-ct="' + key + '|0"></td>' + (ed ? '<td></td>' : '') + '</tr>';

    if (!d.sections.length) h += '<tr><td colspan="' + cols + '">' + emptyState('list-numbers', 'No income or expense accounts', 'Add them to the chart of accounts first.') + '</td></tr>';
    d.sections.forEach((s) => {
      h += '<tr><td colspan="' + cols + '" style="font-weight:700;padding-top:14px">' + esc(s.title) + '</td></tr>';
      s.rows.forEach((r) => {
        const pad = 'padding-left:' + (14 + r.depth * 16) + 'px;';
        if (r.kind === 'header') { h += '<tr><td colspan="' + cols + '" style="' + pad + 'font-weight:600">' + esc(r.name) + '</td></tr>'; return; }
        h += '<tr data-row="' + r.id + '"><td style="' + stick + pad + '"><span class="code">' + esc(r.code) + '</span> ' + esc(r.name)
          + (r.sign < 0 ? ' <span class="small muted" title="A contra account: its budget counts against its section">(deducted)</span>' : '')
          + (r.active ? '' : ' <span class="badge">Inactive</span>') + '</td>';
        P.forEach((p) => {
          const v = shown(r.id, p.no, depts);
          const k = K(view, r.id, p.no);
          h += ed
            ? '<td class="num" style="padding:3px 4px"><input class="input input-sm" style="width:7.6em;text-align:right' + (edits.has(k) ? ';background:var(--brand-softer)' : '') + '"'
              + ' inputmode="decimal" autocomplete="off" spellcheck="false" aria-label="' + esc(r.code + ' ' + r.name + ', ' + p.name) + '" data-a="' + r.id + '" data-p="' + p.no + '"'
              + ' value="' + (v ? toMajor(v) : '') + '"></td>'
            : '<td class="num">' + amt(v) + '</td>';
        });
        h += '<td class="num" data-rt="' + r.id + '"></td>' + (ed ? '<td style="padding:3px 4px"><button class="btn btn-ghost btn-sm btn-icon" type="button" data-tools="' + r.id + '"'
          + ' aria-label="Row tools for ' + esc(r.code) + '" title="Spread, copy across, change by %, clear"><span data-icon="dots-three" data-icon-size="18"></span></button></td>' : '') + '</tr>';
      });
      h += '<tr class="totals"><td style="' + stick + 'font-weight:700">Total ' + esc(lc(s.title)) + '</td>' + P.map((p) => '<td class="num" data-st="' + s.key + '|' + p.no + '"></td>').join('')
        + '<td class="num" data-st="' + s.key + '|0"></td>' + (ed ? '<td></td>' : '') + '</tr>';
      if (s.key === 'cost_of_sales' && d.sections.some((x) => x.key === 'revenue')) h += computed('gross', d.words.gross, false);
    });
    if (d.sections.length) h += computed('income', 'Total income', false) + computed('expense', 'Total expenses', false) + computed('net', d.words.income, true);
    grid.innerHTML = h + '</tbody>';
    paintTotals();
  };

  const paintTotals = () => {
    const P = d.periods;
    const depts = deptKeys();
    const zero = () => new Array(P.length + 1).fill(0);
    const agg = { income: zero(), expense: zero() };
    const sec = {};
    const put = (sel, v) => { const c = qs(sel, grid); if (c) c.textContent = amt(v); };
    d.sections.forEach((s) => {
      const t = zero();
      s.rows.forEach((r) => {
        if (r.kind !== 'account') return;
        let row = 0;
        P.forEach((p, i) => { const v = shown(r.id, p.no, depts); row += v; t[i + 1] += r.sign * v; });
        put('[data-rt="' + r.id + '"]', row);
      });
      t[0] = t.slice(1).reduce((a, b) => a + b, 0);
      sec[s.key] = t;
      t.forEach((v, i) => { put('[data-st="' + s.key + '|' + (i ? P[i - 1].no : 0) + '"]', v); agg[s.type][i] += v; });
    });
    const gross = zero().map((_, i) => (sec.revenue ? sec.revenue[i] : 0) - (sec.cost_of_sales ? sec.cost_of_sales[i] : 0));
    const net = zero().map((_, i) => agg.income[i] - agg.expense[i]);
    [['gross', gross], ['income', agg.income], ['expense', agg.expense], ['net', net]].forEach(([k, arr]) => arr.forEach((v, i) => put('[data-ct="' + k + '|' + (i ? P[i - 1].no : 0) + '"]', v)));
  };

  const paintDirty = () => {
    const n = edits.size;
    qs('[data-dirty]', root).textContent = n ? n + ' unsaved change' + (n === 1 ? '' : 's')
      : (view === 'all' && d.can.edit ? 'Choose a department to change its amounts.' : '');
    saveBtn.hidden = !(n || bad.size);
    discardBtn.hidden = !(n || bad.size);
    saveBtn.disabled = bad.size > 0;
    gridErr.hidden = !bad.size;
    if (bad.size) gridErr.textContent = bad.size + ' amount' + (bad.size === 1 ? ' is' : 's are') + ' not valid (marked in red). Enter a plain amount such as 12500 or 12,500.50; a budget cannot be below zero.';
  };

  // ── the page around it ────────────────────────────────────────────────
  const paint = () => {
    const b = d.budget;
    qs('[data-title]', root).innerHTML = '<span>' + esc(b.name) + '</span>' + statusBadge(b.status) + (b.is_primary ? '<span class="badge badge-brand">Primary</span>' : '');
    qs('[data-crumb]', root).textContent = b.name;
    ctx.setTitle(b.name + ' · ' + b.fiscal_year.name);
    qs('[data-sub]', root).textContent = b.fiscal_year.name + ' · ' + b.totals.line_count + ' amount' + (b.totals.line_count === 1 ? '' : 's') + ' · last changed ' + fmtDate(b.updated_at);

    const banner = qs('[data-banner]', root);
    const yr = b.fiscal_year.name;
    let kind = 'neutral';
    let text;
    if (b.status === 'approved') {
      kind = 'ok';
      text = 'Approved and read-only. ' + (b.is_primary ? 'It is the primary budget for ' + yr + ', so budget vs actual and the dashboard use it. ' : '')
        + (d.can.return ? 'To change it, return it to draft.' : '');
    } else {
      text = 'A draft. Type the amounts by month, then select Save changes. ' + (b.is_primary ? 'It is still the primary budget for ' + yr + ', so reports compare against it as it stands. ' : '')
        + (d.can.edit ? 'Approve it when it is final.' : 'An accountant finishes and approves it.');
    }
    banner.className = 'alert alert-' + kind;
    banner.innerHTML = '<span data-icon="' + (b.status === 'approved' ? 'lock-key' : 'pencil-simple') + '"></span><div>' + esc(text) + '</div>';
    banner.hidden = false;

    const c = d.can;
    const btn = (act, label, icon, cls) => '<button class="btn ' + cls + '" type="button" data-do="' + act + '"><span data-icon="' + icon + '" data-icon-size="18"></span>' + esc(label) + '</button>';
    actions.innerHTML = '<a class="btn btn-ghost" href="' + esc('reports/budget-vs-actual?budget_id=' + b.id + (view !== 'all' ? '&department_id=' + view : '')) + '"><span data-icon="scales" data-icon-size="18"></span>Budget vs actual</a>'
      + btn('export', 'Download CSV', 'download-simple', 'btn-secondary')
      + (c.import ? btn('import', 'Import CSV', 'upload-simple', 'btn-secondary') : '')
      + (c.edit ? btn('details', 'Name and notes', 'pencil-simple', 'btn-secondary') : '')
      + (c.delete ? btn('delete', 'Delete', 'trash', 'btn-ghost') : '')
      + (c.return ? btn('return', 'Return to draft', 'arrow-counter-clockwise', 'btn-secondary') : '')
      + (c.primary ? btn('primary', 'Set as primary', 'flag', 'btn-secondary') : '')
      + (c.approve ? btn('approve', 'Approve', 'check-circle', '') : '');

    const opts = d.departments.filter((x) => x.id === 0 || x.is_active || x.has_lines);
    sel.innerHTML = '<option value="all">All departments (added up, read-only)</option>' + opts.map((x) => '<option value="' + x.id + '">'
      + esc(x.id === 0 ? 'Company-wide (no department)' : ' '.repeat(x.depth) + x.code + ' · ' + x.name + (x.is_active ? '' : ' (inactive)')) + '</option>').join('');
    if (view !== 'all' && !opts.some((x) => String(x.id) === view)) view = '';
    if (!view) view = d.lines.some((l) => l[1] !== 0) ? 'all' : '0';
    sel.value = view;

    const t = b.totals;
    const fact = (k, v, wide) => '<div' + (wide ? ' class="wide"' : '') + '><div class="k">' + esc(k) + '</div><div class="v">' + (v || '<span class="faint">—</span>') + '</div></div>';
    qs('[data-about]', root).innerHTML = '<div class="je-head">'
      + fact('Fiscal year', esc(b.fiscal_year.name + ' (' + b.fiscal_year.start_date + ' to ' + b.fiscal_year.end_date + ')'))
      + fact('Status', statusBadge(b.status) + (b.is_primary ? ' <span class="badge badge-brand">Primary</span>' : ''))
      + fact('Prepared by', esc(b.created_by_name || '') + ' <span class="small muted">' + esc(fmtDate(b.created_at, 'date')) + '</span>')
      + fact('Income, all departments', '<span class="num">' + money(t.income_cents) + '</span>')
      + fact('Expenses, all departments', '<span class="num">' + money(t.expense_cents) + '</span>')
      + fact(d.words.income + ', all departments', '<span class="num">' + money(t.net_cents) + '</span>')
      + fact('Notes', esc(b.notes || ''), true) + '</div>';

    qs('[data-trail]', root).innerHTML = d.trail.length ? d.trail.map((x) => {
      const y = x.detail || {};
      const more = [];
      if (x.action === 'budget.create' && y.started_from) more.push('Started from ' + y.started_from + (y.lines != null ? ': ' + y.lines + ' amounts' : ''));
      if ((x.action === 'budget.update' || x.action === 'budget.import') && (y.set || y.deleted)) more.push((y.set || 0) + ' amounts saved, ' + (y.deleted || 0) + ' cleared');
      if (x.action === 'budget.import' && y.department) more.push('Into ' + y.department + (y.skipped ? '; ' + y.skipped + ' rows skipped' : ''));
      if (y.name && y.name.from !== undefined) more.push('Renamed from “' + y.name.from + '”');
      if (x.action === 'budget.primary' && y.previous_name) more.push('In place of ' + y.previous_name);
      return '<li class="is-done"><div class="tl-title">' + esc(ACTIONS[x.action] || x.action) + '</div><div class="tl-time">' + esc(x.who) + ' · ' + esc(fmtDate(x.at)) + '</div>'
        + more.map((t2) => '<div class="small">' + esc(t2) + '</div>').join('') + (y.reason ? '<div class="small">“' + esc(y.reason) + '”</div>' : '') + '</li>';
    }).join('') : '<li class="muted">Nothing recorded yet.</li>';

    renderGrid();
    paintDirty();
  };

  // ── typing into the grid ──────────────────────────────────────────────
  const onCell = (inp, tidy) => {
    const a = Number(inp.dataset.a);
    const p = Number(inp.dataset.p);
    const k = K(view, a, p);
    const txt = inp.value.trim();
    const v = txt === '' || txt === '-' ? 0 : toCents(txt);
    if (v === null) {
      bad.add(k);
      inp.setAttribute('aria-invalid', 'true');
      inp.style.borderColor = 'var(--err)';
    } else {
      setCell(Number(view), a, p, v);
      inp.removeAttribute('aria-invalid');
      inp.style.borderColor = '';
      if (tidy) inp.value = v ? toMajor(v) : '';
    }
    inp.style.background = edits.has(k) ? 'var(--brand-softer)' : '';
    paintTotals();
    paintDirty();
  };
  grid.addEventListener('input', (e) => { if (e.target.matches('input[data-a]')) onCell(e.target, false); });
  grid.addEventListener('change', (e) => { if (e.target.matches('input[data-a]')) onCell(e.target, true); });
  grid.addEventListener('focusin', (e) => { if (e.target.matches('input[data-a]')) e.target.select(); });
  grid.addEventListener('keydown', (e) => {
    const inp = e.target.closest('input[data-a]');
    if (!inp) return;
    let dir = 0;
    if (e.key === 'Enter') dir = e.shiftKey ? -1 : 1;
    else if (e.key === 'ArrowDown') dir = 1;
    else if (e.key === 'ArrowUp') dir = -1;
    else return;
    e.preventDefault();
    const col = qsa('input[data-p="' + inp.dataset.p + '"]', grid);
    const next = col[col.indexOf(inp) + dir];
    if (next) next.focus();
  });

  // ── row tools ─────────────────────────────────────────────────────────
  const tools = (aid) => {
    const r = accountRow(aid);
    if (!r) return;
    const P = d.periods;
    const dep = Number(view);
    const html = '<form class="stack" data-f novalidate><div class="alert alert-err" data-banner hidden></div>'
      + '<p class="small muted mb-0">For ' + esc(deptLabel()) + '. Changes wait on the screen until you select Save changes.</p>'
      + '<div class="field"><label class="label" for="rt-year">Spread a yearly amount evenly</label><div class="row gap-2">'
      + '<input class="input" id="rt-year" name="year" inputmode="decimal" placeholder="120,000.00" autocomplete="off" style="max-width:170px">'
      + '<button class="btn btn-secondary" type="button" data-t="spread">Spread over ' + P.length + ' months</button></div>'
      + '<div class="hint">Leftover centavos go to the first months, so the months add up to the amount exactly.</div></div>'
      + '<div class="field"><span class="label">Copy the first month across</span><div class="row gap-2"><button class="btn btn-secondary" type="button" data-t="copy">Copy '
      + esc(P[0].name) + ' (' + esc(amt(val(dep, aid, P[0].no))) + ') to every month</button></div></div>'
      + '<div class="field"><label class="label" for="rt-pct">Change every month by a percentage</label><div class="row gap-2" style="align-items:center">'
      + '<input class="input" id="rt-pct" name="pct" inputmode="decimal" placeholder="5 or -2.5" autocomplete="off" style="max-width:120px"><span>%</span>'
      + '<button class="btn btn-secondary" type="button" data-t="pct">Apply</button></div><div class="hint">Each month is rounded to the centavo.</div></div>'
      + '<div class="field"><span class="label">Clear the row</span><div class="row gap-2"><button class="btn btn-danger-soft" type="button" data-t="clear">Set every month to zero</button></div></div>'
      + '</form>';
    const m = openModal({ title: r.code + ' · ' + r.name, body: html });
    const f = qs('[data-f]', m.el);
    const say = (msg) => { const b = qs('[data-banner]', f); b.textContent = msg; b.hidden = false; };
    f.addEventListener('submit', (e) => e.preventDefault());
    f.addEventListener('click', (e) => {
      const b = e.target.closest('[data-t]');
      if (!b) return;
      let next;
      if (b.dataset.t === 'spread') {
        const v = toCents(f.elements.year.value);
        if (v === null || !f.elements.year.value.trim()) { say('Enter the yearly amount, such as 120000 or 120,000.50.'); f.elements.year.focus(); return; }
        next = spread(v, P.length);
      } else if (b.dataset.t === 'copy') {
        const v = val(dep, aid, P[0].no);
        next = P.map(() => v);
      } else if (b.dataset.t === 'pct') {
        const bp = percentBp(f.elements.pct.value);
        if (bp === null) { say('Enter the change as a percentage between -99.99 and 1000, such as 5 or -2.5.'); f.elements.pct.focus(); return; }
        next = P.map((p) => Math.max(0, applyPercent(val(dep, aid, p.no), bp)));
      } else {
        next = P.map(() => 0);
      }
      P.forEach((p, i) => setCell(dep, aid, p.no, next[i]));
      m.close();
      renderGrid();
      paintDirty();
    });
  };
  grid.addEventListener('click', (e) => { const b = e.target.closest('[data-tools]'); if (b && editable()) tools(Number(b.dataset.tools)); });

  // ── saving ────────────────────────────────────────────────────────────
  const savedText = (r) => {
    if (!r || (!r.set && !r.deleted && !(r.header && Object.keys(r.header).length))) return 'Nothing changed.';
    const bits = [];
    if (r.set) bits.push(r.set + ' amount' + (r.set === 1 ? '' : 's') + ' saved');
    if (r.deleted) bits.push(r.deleted + ' cleared');
    if (r.header && Object.keys(r.header).length) bits.push('the ' + Object.keys(r.header).join(' and ') + ' changed');
    return bits.join(', ').replace(/^./, (x) => x.toUpperCase()) + '.';
  };
  saveBtn.addEventListener('click', async () => {
    if (bad.size || !edits.size) return;
    const lines = [...edits].map(([k, v]) => { const [dep, a, p] = k.split('|').map(Number); return { account_id: a, department_id: dep, period_no: p, amount_cents: v }; });
    busy(saveBtn, true);
    gridErr.hidden = true;
    try {
      const r = await api.put('/budgets/' + id, { lines }, { timeout: 60000 });
      take(r);
      paint();
      toast(savedText(r.result));
    } catch (err) {
      gridErr.textContent = err.message;
      gridErr.hidden = false;
    } finally { busy(saveBtn, false); }
  });
  discardBtn.addEventListener('click', async () => {
    if (!(await confirmDialog({ title: 'Discard your changes?', body: 'The ' + (edits.size || bad.size) + ' unsaved change' + ((edits.size || bad.size) === 1 ? '' : 's') + ' will be lost.', confirmLabel: 'Discard', cancelLabel: 'Keep them', danger: true }))) return;
    edits.clear();
    bad.clear();
    renderGrid();
    paintDirty();
  });
  sel.addEventListener('change', () => {
    view = sel.value;
    syncQuery({ department: view });
    paint();
  });
  ctrls.addEventListener('submit', (e) => e.preventDefault());

  // ── the workflow ──────────────────────────────────────────────────────
  const needClean = () => {
    if (!edits.size && !bad.size) return false;
    toast('Save or discard your changes first.', { kind: 'error' });
    return true;
  };

  const details = () => {
    const b = d.budget;
    const m = openModal({ title: 'Name and notes', body: '<form class="stack" data-f novalidate><div class="alert alert-err" data-banner hidden></div>'
      + '<div class="field"><label class="label" for="bd-name">Name</label><input class="input" id="bd-name" name="name" maxlength="80" value="' + esc(b.name) + '" autocomplete="off"><div class="error" data-error-for="name"></div></div>'
      + '<div class="field"><label class="label" for="bd-notes">Notes <span class="opt">(optional)</span></label><textarea class="textarea" id="bd-notes" name="notes" maxlength="500">' + esc(b.notes || '') + '</textarea><div class="error" data-error-for="notes"></div></div>'
      + '<div class="row gap-2" style="justify-content:flex-end"><button class="btn btn-secondary" type="button" data-x>Cancel</button><button class="btn" type="submit">Save</button></div></form>' });
    const f = qs('[data-f]', m.el);
    qs('[data-x]', f).addEventListener('click', () => m.close());
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErrors(f);
      const btn = qs('button[type="submit"]', f);
      busy(btn, true);
      try {
        const r = await api.put('/budgets/' + id, { name: f.elements.name.value.trim(), notes: f.elements.notes.value.trim() });
        m.close();
        const kept = new Map(edits);
        take(r);
        kept.forEach((v, k) => edits.set(k, v));
        paint();
        toast(savedText(r.result));
      } catch (err) { formError(f, err); } finally { busy(btn, false); }
    });
  };

  const importCsv = () => {
    if (needClean()) return;
    const target = view === 'all' ? '0' : view;
    const opts = d.departments.filter((x) => x.id === 0 || x.is_active);
    const m = openModal({ title: 'Import amounts from a CSV file', body: '<form class="stack" data-f novalidate><div class="alert alert-err" data-banner hidden></div>'
      + '<p class="muted mb-0">Use a file downloaded from this screen (Download CSV) and filled in, for example in a spreadsheet.</p>'
      + '<div class="field"><label class="label" for="im-dept">Import into</label><select class="select" id="im-dept" name="department_id">'
      + opts.map((x) => '<option value="' + x.id + '"' + (String(x.id) === target ? ' selected' : '') + '>' + esc(x.id === 0 ? 'Company-wide (no department)' : x.code + ' · ' + x.name) + '</option>').join('')
      + '</select><div class="error" data-error-for="department_id"></div></div>'
      + '<div class="field"><label class="label" for="im-file">CSV file</label><input class="input" type="file" id="im-file" name="file" accept=".csv,text/csv"><div class="error" data-error-for="file"></div></div>'
      + '<div class="hint">Accounts are matched by code and months by position; the Total column and the total rows are ignored. Every account in the file gets all twelve months (a blank is zero); accounts not in the file keep their amounts. Rows that cannot be used are listed afterwards with the reason.</div>'
      + '<div class="row gap-2" style="justify-content:flex-end"><button class="btn btn-secondary" type="button" data-x>Cancel</button><button class="btn" type="submit">Import</button></div></form>' });
    const f = qs('[data-f]', m.el);
    qs('[data-x]', f).addEventListener('click', () => m.close());
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErrors(f);
      const file = f.elements.file.files && f.elements.file.files[0];
      if (!file) { formError(f, { errors: { file: 'Choose the CSV file to import.' } }); return; }
      const fd = new FormData();
      fd.append('department_id', f.elements.department_id.value);
      fd.append('file', file);
      const btn = qs('button[type="submit"]', f);
      busy(btn, true);
      try {
        const r = await api.upload('/budgets/' + id + '/import', fd, { timeout: 60000 });
        m.close();
        view = f.elements.department_id.value;
        syncQuery({ department: view });
        take(r);
        paint();
        showImport(r.import);
      } catch (err) { formError(f, err); } finally { busy(btn, false); }
    });
  };

  const showImport = (x) => {
    const card = qs('[data-import-report]', root);
    const res = x.result || {};
    toast('Imported ' + x.used + ' account' + (x.used === 1 ? '' : 's') + ' into ' + x.department + ': ' + (res.set || 0) + ' amounts saved, ' + (res.deleted || 0) + ' cleared.'
      + (x.skipped.length ? ' ' + x.skipped.length + ' row' + (x.skipped.length === 1 ? '' : 's') + ' skipped.' : ''), { kind: x.skipped.length ? 'info' : 'ok' });
    card.hidden = !x.skipped.length;
    if (!x.skipped.length) return;
    qs('[data-import-body]', root).innerHTML = '<p class="small muted">' + x.rows + ' account rows read; ' + x.used + ' imported into ' + esc(x.department) + '. These rows were not used:</p>'
      + '<div class="table-wrap"><table class="table table-compact"><thead><tr><th>Row</th><th>Code</th><th>Why</th></tr></thead><tbody>'
      + x.skipped.map(([line, code, why]) => '<tr><td class="num">' + line + '</td><td class="code">' + esc(code) + '</td><td>' + esc(why) + '</td></tr>').join('') + '</tbody></table></div>';
  };
  qs('[data-close-report]', root).addEventListener('click', () => { qs('[data-import-report]', root).hidden = true; });

  actions.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-do]');
    if (!b || !d) return;
    const bud = d.budget;
    try {
      switch (b.dataset.do) {
        case 'export': {
          const slug = (s) => String(s).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
          const dl = view === 'all' ? 'all-departments' : (view === '0' ? 'company-wide' : slug((d.departments.find((x) => String(x.id) === view) || {}).code || view));
          busy(b, true);
          await download('/budgets/' + id + '/export?department_id=' + encodeURIComponent(view), 'budget-' + slug(bud.fiscal_year.name) + '-' + slug(bud.name) + '-' + dl + '.csv');
          return;
        }
        case 'import': importCsv(); return;
        case 'details': details(); return;
        case 'approve':
          if (needClean()) return;
          if (!(await confirmDialog({ title: 'Approve "' + bud.name + '"?', body: 'It becomes read-only. An accountant can return it to draft later, with a reason.', confirmLabel: 'Approve' }))) return;
          busy(b, true);
          take(await api.post('/budgets/' + id + '/approve'));
          toast('"' + bud.name + '" approved. It is read-only now.');
          break;
        case 'return': {
          const reason = await promptDialog({ title: 'Return to draft', label: 'Why does it need changing?', multiline: true, maxlength: 300, required: true, confirmLabel: 'Return to draft' });
          if (reason === null) return;
          busy(b, true);
          take(await api.post('/budgets/' + id + '/return', { reason }));
          toast('"' + bud.name + '" is a draft again.');
          break;
        }
        case 'primary':
          if (!(await confirmDialog({ title: 'Make "' + bud.name + '" the primary budget?', body: 'Budget vs actual and the dashboard use the primary budget of ' + bud.fiscal_year.name + ' unless someone chooses another. Any other primary budget of that year stops being primary.', confirmLabel: 'Set as primary' }))) return;
          busy(b, true);
          take(await api.post('/budgets/' + id + '/primary'));
          toast('"' + bud.name + '" is now the primary budget for ' + bud.fiscal_year.name + '.');
          break;
        case 'delete':
          if (!(await confirmDialog({ title: 'Delete "' + bud.name + '"?', body: 'The draft and all ' + bud.totals.line_count + ' of its amounts are removed. This cannot be undone.', confirmLabel: 'Delete', danger: true }))) return;
          busy(b, true);
          await api.del('/budgets/' + id);
          edits.clear();
          bad.clear();
          toast('Budget "' + bud.name + '" deleted.');
          ctx.navigate('/budgets');
          return;
        default: return;
      }
      paint();
    } catch (err) {
      toast(err.message, { kind: 'error' });
    } finally {
      busy(b, false);
    }
  });

  // ── load, and do not lose typed amounts by leaving ─────────────────────
  try {
    take(await api.get('/budgets/' + id, null, { signal: ctx.signal }));
  } catch (err) {
    if (ctx.signal.aborted) return undefined;
    stateEl.innerHTML = emptyState(err.isNotFound ? 'magnifying-glass' : 'warning', err.isNotFound ? 'That budget does not exist' : 'Could not load the budget', err.isNotFound ? '' : err.message,
      '<a class="btn btn-secondary" href="budgets">Back to the budgets</a>');
    return undefined;
  }
  stateEl.innerHTML = '';
  qs('[data-body]', root).hidden = false;
  paint();

  const guard = (e) => {
    if (!edits.size && !bad.size) return;
    const a = e.target.closest && e.target.closest('a[href]');
    if (!a || (a.target && a.target !== '_self') || a.hasAttribute('download') || a.closest('dialog') || e.button !== 0 || e.metaKey || e.ctrlKey || e.shiftKey) return;
    e.preventDefault();
    e.stopPropagation();
    confirmDialog({ title: 'Leave without saving?', body: 'You have ' + (edits.size || bad.size) + ' unsaved change' + ((edits.size || bad.size) === 1 ? '' : 's') + ' in this budget. They will be lost.',
      confirmLabel: 'Leave without saving', cancelLabel: 'Stay', danger: true }).then((ok) => {
      if (!ok) return;
      edits.clear();
      bad.clear();
      const to = appPath(a.href);
      if (to === null) location.href = a.href; else ctx.navigate(to + (new URL(a.href).search || ''));
    });
  };
  const beforeUnload = (e) => { if (edits.size || bad.size) { e.preventDefault(); e.returnValue = ''; } };
  document.addEventListener('click', guard, true);
  window.addEventListener('beforeunload', beforeUnload);
  return () => {
    document.removeEventListener('click', guard, true);
    window.removeEventListener('beforeunload', beforeUnload);
  };
}
