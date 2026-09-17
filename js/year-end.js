/**
 * year-end.js — closing a fiscal year, a co-op's net-surplus allocation, reopening
 *
 * GenericPOS Accounting · ES module · administrators (PLAN.md §8)
 *
 *   the checklist        what must be true before closing, and what is worth knowing
 *   the closing entry    every income and expense balance, reversed into the closing account
 *   close / reopen       the year's name typed back; reopening also needs a reason
 *   co-ops               the net-surplus allocation: with the closing, or later once
 *                        the general assembly approves it
 *
 * The server does the work and checks everything again (Year_end_model); the
 * allocation preview here uses the same rounding so the figures agree.
 */

import { api } from './api.js';
import { money, fmtDay, fmtDate } from './store.js';
import { qs, esc, emptyState, statusBadge, toast, busy, promptDialog } from './ui.js';
import { syncQuery } from './report-kit.js';

const PCT_KEYS = ['reserve', 'cetf', 'cdf', 'optional', 'isc'];

/** The server's split: each fund rounded to the centavo, ISC from the remainder, patronage takes the rest. */
function split(ns, pct) {
  const part = (base, p) => Number((BigInt(base) * BigInt(Math.round((Number(p) || 0) * 100)) + 5000n) / 10000n);
  const out = {};
  let used = 0;
  ['reserve', 'cetf', 'cdf', 'optional'].forEach((k) => { out[k] = part(ns, pct[k]); used += out[k]; });
  const rest = ns - used;
  out.isc = part(rest, pct.isc);
  out.patronage = rest - out.isc;
  return out;
}

export async function mount(root, ctx) {
  const box = qs('[data-body]', root);
  const pick = qs('[data-year]', root);
  let st = null;
  let fyId = Number(ctx.query.get('fiscal_year_id')) || 0;

  const coop = () => st.entity_type === 'cooperative';
  const W = () => (coop() ? 'net surplus' : 'net income');

  // ── pieces ────────────────────────────────────────────────────────────────
  const checkRow = (c) => {
    const kind = c.ok ? 'ok' : (c.level === 'block' ? 'err' : 'warn');
    const icon = c.ok ? 'check-circle' : (c.level === 'block' ? 'x-circle' : 'warning');
    return '<div class="alert alert-' + kind + '"><span data-icon="' + icon + '" data-icon-size="18"></span>'
      + '<div class="grow">' + esc(c.text) + (c.link && !c.ok ? ' <a href="' + esc(c.link) + '">Open</a>' : '') + '</div></div>';
  };

  const headCard = (y) => {
    const done = y.status === 'closed';
    return '<section class="card"><div class="card-head"><div><h3>' + esc(y.name) + '</h3>'
      + '<div class="small muted">' + esc(fmtDay(y.start_date, 'long') + ' – ' + fmtDay(y.end_date, 'long')) + '</div></div>' + statusBadge(y.status) + '</div>'
      + '<div class="card-body stack">'
      + (done
        ? '<p>Closed' + (y.closed_by ? ' by ' + esc(y.closed_by) : '') + (y.closed_at ? ' on ' + esc(fmtDate(y.closed_at, 'date')) : '') + '. Nothing more can be dated in ' + esc(y.name) + '.</p>'
          + '<div class="row gap-2"><a class="btn btn-secondary btn-sm" href="reports/trial-balance?as_of=' + esc(y.end_date) + '&kind=post_closing"><span data-icon="calculator" data-icon-size="16"></span>Post-closing trial balance</a>'
          + '<a class="btn btn-secondary btn-sm" href="reports/balance-sheet?as_of=' + esc(y.end_date) + '"><span data-icon="scales" data-icon-size="16"></span>Balance sheet at ' + esc(fmtDay(y.end_date, 'short')) + '</a>'
          + (st.can.reopen ? '<button class="btn btn-danger-soft btn-sm" type="button" data-reopen><span data-icon="lock-open" data-icon-size="16"></span>Reopen ' + esc(y.name) + '</button>' : '')
          + '</div>' + (st.reopen_note ? '<p class="small muted">' + esc(st.reopen_note) + '</p>' : '')
        : '<p>Months: ' + y.periods.map((p) => esc(p.name.split(' ')[0]) + ' ' + statusBadge(p.status)).join(' ') + '</p>')
      + '</div></section>';
  };

  const planCard = (p, y) => {
    const ni = p.net_income_cents;
    const where = p.closing_account ? p.closing_account.code + ' ' + p.closing_account.name : 'the closing account';
    const lead = !p.lines.length
      ? '<p class="muted">Nothing to close: no income or expense was posted in ' + esc(y.name) + '.</p>'
      : '<p>' + (ni >= 0 ? 'The ' + W() + ' of <strong>' + esc(money(ni)) + '</strong> goes to ' : 'The net loss of <strong>' + esc(money(-ni)) + '</strong> is charged to ')
        + esc(where) + '.' + (p.drawings_cents > 0 ? ' The owner\'s drawings of ' + esc(money(p.drawings_cents)) + ' are closed into capital in the same entry.' : '') + '</p>';
    const rows = p.lines.map((l) => '<tr><td class="code">' + esc(l.code) + '</td><td>' + esc(l.name)
      + (l.department ? ' <span class="badge">' + esc(l.department) + '</span>' : '')
      + (l.problem ? ' <span class="badge badge-err">' + esc(l.problem) + '</span>' : '') + '</td>'
      + '<td class="num">' + (l.debit_cents ? esc(money(l.debit_cents)) : '') + '</td><td class="num">' + (l.credit_cents ? esc(money(l.credit_cents)) : '') + '</td></tr>').join('');
    return '<section class="card"><div class="card-head"><div><h3>' + (y.status === 'closed' ? 'The closing entry' : 'The closing entry it will post') + '</h3>'
      + '<div class="small muted">Closing book, dated ' + esc(fmtDay(y.end_date, 'long')) + '</div></div></div>'
      + '<div class="card-body stack">' + lead
      + (p.lines.length ? '<div class="table-wrap"><table class="table table-compact"><thead><tr><th>Code</th><th>Account</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead>'
        + '<tbody>' + rows + '</tbody><tfoot><tr><th></th><th>Total</th><th class="num">' + esc(money(p.total_cents)) + '</th><th class="num">' + esc(money(p.total_cents)) + '</th></tr></tfoot></table></div>' : '')
      + '</div></section>';
  };

  const pctInputs = (parts, editable) => '<div class="table-wrap"><table class="table table-compact"><thead><tr><th>Fund or payable</th><th class="num">%</th><th class="num">Amount</th><th>Account</th></tr></thead><tbody>'
    + parts.map((p) => '<tr><td>' + esc(p.label) + (p.key === 'isc' ? ' <span class="xs faint">% of what is left after the funds</span>' : '') + (p.key === 'patronage' ? ' <span class="xs faint">takes the rest</span>' : '') + '</td>'
      + '<td class="num">' + (p.pct === null || p.pct === undefined ? '' : (editable
        ? '<input class="input input-sm num" style="width:6em" type="text" inputmode="decimal" data-pct="' + p.key + '" id="ye-pct-' + p.key + '" aria-label="' + esc(p.label) + ' %" value="' + esc(String(p.pct)) + '">'
        : esc(String(p.pct))))
      + '</td><td class="num" data-amt="' + p.key + '">' + esc(money(p.amount_cents)) + '</td>'
      + '<td>' + (p.account ? '<span class="code">' + esc(p.account.code) + '</span> ' + esc(p.account.name) : '<span class="badge badge-err">No account set</span>') + '</td></tr>').join('')
    + '</tbody></table></div>';

  const allocationCard = (a, y) => {
    if (!a) return '';
    const done = a.done;
    const editable = !done && (y.status === 'open' || st.can.allocate);
    let body = '';
    if (a.net_surplus_cents <= 0 && !done) {
      body = '<p class="muted">' + esc(y.name) + ' has no net surplus to allocate.</p>';
    } else if (done) {
      body = '<p>Allocated on ' + esc(fmtDay(done.date, 'long')) + ' by <a href="journals/' + done.journal_id + '">' + esc(done.journal_no) + '</a>.</p>' + pctInputs(a.parts, false)
        + (st.can.undo_allocation ? '<div class="row gap-2"><button class="btn btn-danger-soft btn-sm" type="button" data-undo-alloc>Undo the allocation</button><span class="small muted">It was posted after the year ended, so it is undone on its own.</span></div>' : '');
    } else {
      body = '<p>The ' + W() + ' of <strong>' + esc(money(a.net_surplus_cents)) + '</strong> is split as below. Change a percentage for this allocation only; Settings › Co-operative holds the usual ones.</p>'
        + pctInputs(a.parts, editable)
        + (y.status === 'open'
          ? '<label class="row gap-2"><input type="checkbox" data-alloc-now id="ye-alloc-now"> <span>Allocate it now, with the closing (dated ' + esc(fmtDay(y.end_date, 'long')) + ')</span></label>'
            + '<p class="small muted">Leave it unticked to allocate later, once the general assembly has approved the allocation.</p>'
          : (st.can.allocate
            ? '<div class="row gap-2"><div class="field"><label class="label" for="ye-alloc-date">Date of the allocation</label>'
              + '<input class="input" type="date" id="ye-alloc-date" data-alloc-date value="' + esc(st.today > y.end_date ? st.today : '') + '" min="' + esc(y.end_date) + '"></div>'
              + '<button class="btn btn-sm" type="button" data-allocate style="align-self:flex-end">Allocate the net surplus</button></div>'
              + '<p class="small muted">Date it when the general assembly approved it, in an open month after ' + esc(y.name) + '.</p>'
            : ''));
    }
    return '<section class="card"><div class="card-head"><div><h3>Allocation of the net surplus</h3><div class="small muted">Statutory funds and what is owed to members (Cooperative Code, Art. 86)</div></div></div>'
      + '<div class="card-body stack">' + body + '</div></section>';
  };

  const journalsCard = (js) => (!js.length ? '' : '<section class="card"><div class="card-head"><h3>Closing-book entries for this year</h3></div>'
    + '<div class="table-wrap"><table class="table table-compact"><thead><tr><th>Number</th><th>Date</th><th>Description</th><th class="num">Amount</th><th></th></tr></thead><tbody>'
    + js.map((j) => '<tr><td><a href="journals/' + j.id + '">' + esc(j.journal_no) + '</a></td><td>' + esc(fmtDay(j.entry_date, 'short')) + '</td><td>' + esc(j.description) + '</td>'
      + '<td class="num">' + esc(money(j.total_cents)) + '</td><td>' + (j.reversed_by_id ? '<span class="small muted">Reversed by <a href="journals/' + j.reversed_by_id + '">' + esc(j.reversed_by_no) + '</a></span>' : '') + '</td></tr>').join('')
    + '</tbody></table></div></section>');

  const closeCard = (y) => {
    if (y.status !== 'open') return '';
    const blocks = st.checks.filter((c) => !c.ok && c.level === 'block').length;
    return '<section class="card card-pad stack"><h3>Close ' + esc(y.name) + '</h3>'
      + (blocks
        ? '<p class="muted">Clear the ' + (blocks === 1 ? 'item' : blocks + ' items') + ' marked in red above first.</p>'
        : '<p>Every month of ' + esc(y.name) + ' will be closed and the closing entry posted. You can reopen the year later if something was missed.</p>')
      + '<div><button class="btn btn-danger" type="button" data-close' + (st.can.close ? '' : ' disabled') + '><span data-icon="lock-key" data-icon-size="18"></span>Close ' + esc(y.name) + '</button></div></section>';
  };

  const render = () => {
    if (!st.year) {
      pick.hidden = true;
      box.innerHTML = emptyState('calendar', 'No fiscal year yet', 'Open the first fiscal year under Fiscal years and periods.');
      return;
    }
    pick.hidden = false;
    pick.innerHTML = st.years.map((y) => '<option value="' + y.id + '"' + (y.id === st.year.id ? ' selected' : '') + '>' + esc(y.name + (y.status === 'closed' ? ' (closed)' : '')) + '</option>').join('');
    const y = st.year;
    box.innerHTML = headCard(y)
      + (y.status === 'open' ? '<section class="card"><div class="card-head"><h3>Before closing</h3></div><div class="card-body stack">' + st.checks.map(checkRow).join('') + '</div></section>' : '')
      + planCard(st.plan, y)
      + (coop() ? allocationCard(st.allocation, y) : '')
      + closeCard(y)
      + journalsCard(st.journals);
  };

  const load = async () => {
    try {
      st = await api.get('/year-end', fyId ? { fiscal_year_id: fyId } : null, { signal: ctx.signal });
      if (st.year) fyId = st.year.id;
      render();
    } catch (err) {
      if (!ctx.signal.aborted) box.innerHTML = emptyState('warning', 'Could not load the year-end', err.message);
    }
  };

  const percentages = () => {
    const out = {};
    root.querySelectorAll('[data-pct]').forEach((i) => { out[i.dataset.pct] = String(i.value).trim().replace(',', '.'); });
    return out;
  };

  const typedName = async (title, hint, label, danger = true) => {
    const name = st.year.name;
    const v = await promptDialog({ title, label: 'Type ' + name + ' to confirm', hint, confirmLabel: label, danger, required: true, maxlength: 20 });
    if (v === null) return null;
    if (v.trim().toLowerCase() !== name.toLowerCase()) { toast('That is not ' + name + '. Nothing was changed.', { kind: 'error' }); return null; }
    return v.trim();
  };

  // ── actions ───────────────────────────────────────────────────────────────
  pick.addEventListener('change', () => {
    fyId = Number(pick.value) || 0;
    syncQuery({ fiscal_year_id: fyId || '' });
    box.innerHTML = '<div class="loading-block"><div class="spinner spinner-lg"></div></div>';
    load();
  });

  root.addEventListener('input', (e) => {
    if (!e.target.closest('[data-pct]') || !st.allocation) return;
    const p = percentages();
    const bad = PCT_KEYS.some((k) => p[k] !== undefined && (p[k] === '' || isNaN(Number(p[k])) || Number(p[k]) < 0 || Number(p[k]) > 100));
    const funds = ['reserve', 'cetf', 'cdf', 'optional'].reduce((a, k) => a + (Number(p[k]) || 0), 0);
    const amounts = split(st.allocation.net_surplus_cents, p);
    Object.keys(amounts).forEach((k) => {
      const cell = qs('[data-amt="' + k + '"]', root);
      if (cell) cell.textContent = bad || funds > 100 ? '—' : money(amounts[k]);
    });
  });

  root.addEventListener('click', async (e) => {
    const y = st && st.year;
    if (!y) return;

    const c = e.target.closest('[data-close]');
    if (c) {
      const now = qs('[data-alloc-now]', root);
      const allocate = !!(now && now.checked);
      const hint = 'Every month of ' + y.name + ' is closed and nothing more can be dated in it.'
        + (allocate ? ' The net surplus is allocated at the same time.' : '') + ' You can reopen the year later.';
      const confirm = await typedName('Close ' + y.name + '?', hint, 'Close ' + y.name);
      if (!confirm) return;
      busy(c, true);
      try {
        st = await api.post('/year-end/' + y.id + '/close', { confirm, allocate, percentages: allocate ? percentages() : undefined });
        toast(y.name + ' is closed.');
        render();
      } catch (err) { toast(err.message, { kind: 'error' }); busy(c, false); }
      return;
    }

    const r = e.target.closest('[data-reopen]');
    if (r) {
      const reason = await promptDialog({ title: 'Reopen ' + y.name + '?', label: 'Why?', hint: 'The closing entries are reversed and ' + y.name + '\'s last month opens again. The reason goes into the audit log.', multiline: true, required: true, maxlength: 300, confirmLabel: 'Next' });
      if (reason === null) return;
      const confirm = await typedName('Reopen ' + y.name + '?', 'Reports for ' + y.name + ' will show its income as not yet closed until it is closed again.', 'Reopen ' + y.name);
      if (!confirm) return;
      busy(r, true);
      try {
        st = await api.post('/year-end/' + y.id + '/reopen', { confirm, reason });
        toast(y.name + ' is open again.');
        render();
      } catch (err) { toast(err.message, { kind: 'error' }); busy(r, false); }
      return;
    }

    const a = e.target.closest('[data-allocate]');
    if (a) {
      const date = (qs('[data-alloc-date]', root) || {}).value || '';
      if (!date) { toast('Enter the date of the allocation.', { kind: 'error' }); return; }
      busy(a, true);
      try {
        st = await api.post('/year-end/' + y.id + '/allocate', { date, percentages: percentages() });
        toast('The net surplus of ' + y.name + ' is allocated.');
        render();
      } catch (err) { toast(err.message, { kind: 'error' }); busy(a, false); }
      return;
    }

    const u = e.target.closest('[data-undo-alloc]');
    if (u) {
      const reason = await promptDialog({ title: 'Undo the allocation?', label: 'Why?', hint: 'A reversing entry is posted today; the funds and payables go back to undivided net surplus.', multiline: true, required: true, maxlength: 300, confirmLabel: 'Undo it', danger: true });
      if (reason === null) return;
      busy(u, true);
      try {
        st = await api.post('/year-end/' + y.id + '/allocation/undo', { reason });
        toast('The allocation is undone.');
        render();
      } catch (err) { toast(err.message, { kind: 'error' }); busy(u, false); }
    }
  });

  await load();
}
