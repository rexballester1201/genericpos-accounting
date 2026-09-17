/**
 * depreciation.js — monthly depreciation runs
 *
 * GenericPOS Accounting · ES module · every role sees the runs; accountants run and undo them
 *
 *   GET  /depreciation/runs                 the runs and the months that can be run next
 *   GET  /depreciation/preview?period_id=   what a run would charge, per asset and per journal line
 *   POST /depreciation/runs                 post it
 *   POST /depreciation/runs/{id}/undo       the latest run only, with a reason
 *
 * Nothing posts without a preview first: pick the month, look at every asset's
 * charge and the journal lines, then post. The run's entry opens from the list.
 */

import { api } from './api.js';
import { money, fmtDay, fmtDate } from './store.js';
import { qs, esc, emptyState, confirmDialog, promptDialog, toast, busy, banner } from './ui.js';

export async function mount(root, ctx) {
  const card = qs('[data-run-card]', root);
  const runForm = qs('[data-run-form]', root);
  const previewEl = qs('[data-preview]', root);
  const tbody = qs('[data-rows]', root);
  let d = null;
  let pv = null;
  const open = new Set();

  const jlink = (id, no) => (id ? '<a href="journals/' + id + '"><span class="mono">' + esc(no || '#' + id) + '</span></a>' : '<span class="faint">—</span>');

  // ── the runs ──────────────────────────────────────────────────────────
  const paintRuns = () => {
    const items = d.items || [];
    qs('[data-count]', root).textContent = items.length ? items.length + ' run' + (items.length === 1 ? '' : 's') : '';
    tbody.innerHTML = items.length ? items.map((r) => '<tr class="is-link" data-id="' + r.id + '">'
      + '<td class="nowrap"><b>' + esc(r.period) + '</b>' + (r.latest ? ' <span class="badge badge-brand">Latest</span>' : '') + '</td>'
      + '<td>' + jlink(r.journal_id, r.journal_no) + '</td>'
      + '<td class="num">' + money(r.total_cents) + '</td>'
      + '<td class="num">' + r.asset_count + '</td>'
      + '<td class="small">' + esc(r.run_by_name) + '</td>'
      + '<td class="small nowrap">' + esc(fmtDate(r.run_at, 'date')) + '</td>'
      + '<td class="right">' + (r.can_undo ? '<button class="btn btn-ghost btn-sm" type="button" data-undo="' + r.id + '"><span data-icon="arrow-counter-clockwise" data-icon-size="16"></span>Undo</button>' : '') + '</td></tr>'
      + (open.has(r.id) ? '<tr data-detail="' + r.id + '"><td colspan="7" style="background:var(--surface-2)"><div data-entries="' + r.id + '"><div class="loading-block"><div class="spinner"></div></div></div></td></tr>' : '')).join('')
      : '<tr><td colspan="7">' + emptyState('clock-counter-clockwise', 'No depreciation has been run yet', d.can_run ? 'Pick a month above, preview it, then post it.' : 'An accountant runs it each month.') + '</td></tr>';
    open.forEach((id) => loadEntries(id));
  };

  const loadEntries = async (id) => {
    const box = qs('[data-entries="' + id + '"]', root);
    if (!box) return;
    try {
      const r = await api.get('/depreciation/runs/' + id, null, { signal: ctx.signal });
      box.innerHTML = '<table class="table table-compact"><thead><tr><th>Asset</th><th>Category</th><th>Department</th><th class="num">Charged</th><th class="num">Accumulated after</th><th class="num">Book value after</th></tr></thead><tbody>'
        + r.entries.map((e) => '<tr><td><a href="assets/' + e.asset_id + '"><span class="code">' + esc(e.asset_no) + '</span></a> ' + esc(e.name) + '</td>'
          + '<td class="small">' + esc(e.category) + '</td><td class="small">' + esc(e.department || '') + '</td>'
          + '<td class="num">' + money(e.amount_cents) + '</td><td class="num">' + money(e.accum_after_cents) + '</td><td class="num">' + money(e.nbv_after_cents) + '</td></tr>').join('')
        + '</tbody></table>';
    } catch (err) {
      if (!ctx.signal.aborted) box.innerHTML = '<div class="alert alert-err">' + esc(err.message) + '</div>';
    }
  };

  // ── running ───────────────────────────────────────────────────────────
  const paintRunCard = () => {
    card.hidden = !d.can_run;
    if (!d.can_run) return;
    const sel = runForm.elements.period_id;
    const keep = sel.value;
    sel.innerHTML = (d.periods || []).map((p) => '<option value="' + p.id + '">' + esc(p.name) + '</option>').join('');
    sel.value = (d.periods || []).some((p) => String(p.id) === keep) ? keep : String(d.next_period_id || '');
    runForm.hidden = !(d.periods || []).length;
    if (!(d.periods || []).length) {
      previewEl.innerHTML = emptyState('calendar-check', 'No month can be run now',
        'Every open month after ' + (d.latest ? d.latest.period : 'the start of the books') + ' already has a run, or no month after it is open. An accountant can reopen a month on the Fiscal years screen.');
    }
  };

  const paintPreview = () => {
    if (!pv) { previewEl.innerHTML = ''; return; }
    const p = pv.period;
    const rows = pv.rows || [];
    const lines = pv.lines || [];
    const dr = lines.reduce((s, l) => s + l.debit_cents, 0);
    const cr = lines.reduce((s, l) => s + l.credit_cents, 0);
    const notes = [];
    if (pv.caught_up) notes.push(pv.caught_up + ' month' + (pv.caught_up === 1 ? ' was' : 's were') + ' not run since the last run. Straight-line assets catch those months up in this run; declining-balance assets spread them over their remaining life.');
    const done = rows.filter((r) => r.fully).length;
    if (done) notes.push(done + ' asset' + (done === 1 ? ' reaches' : 's reach') + ' the end of depreciation with this run and will show as fully depreciated.');

    if (!rows.length) {
      previewEl.innerHTML = emptyState('check-circle', 'Nothing to depreciate in ' + p.name, 'No asset in use is due for depreciation that month: every one is fully depreciated, disposed of, or starts later.');
      return;
    }
    previewEl.innerHTML = '<div class="alert alert-neutral"><span data-icon="info"></span><div><b>' + esc(pv.journal.description) + '</b>: one adjusting entry of '
      + money(pv.total_cents) + ' for ' + rows.length + ' asset' + (rows.length === 1 ? '' : 's') + ', dated ' + esc(fmtDay(pv.journal.entry_date, 'long'))
      + ', reference ' + esc(pv.journal.reference) + '.' + (notes.length ? ' ' + esc(notes.join(' ')) : '') + '</div></div>'
      + (pv.problems.length ? '<div class="alert alert-err"><span data-icon="warning"></span><div>Fix these before posting: ' + pv.problems.map(esc).join(' ') + '</div></div>' : '')
      + '<div class="table-wrap"><table class="table table-compact"><thead><tr><th>Asset</th><th>Category</th><th>Department</th><th>Month of its life</th>'
      + '<th class="num">This month</th><th class="num">Accumulated after</th><th class="num">Book value after</th></tr></thead><tbody>'
      + rows.map((r) => '<tr><td><a href="assets/' + r.asset_id + '"><span class="code">' + esc(r.asset_no) + '</span></a> ' + esc(r.name) + '</td>'
        + '<td class="small">' + esc(r.category) + '</td><td class="small">' + esc(r.department || '') + '</td>'
        + '<td class="small nowrap">' + r.month_of_life + ' of ' + r.life_months + (r.method === 'declining_balance' ? ' · declining' : '') + '</td>'
        + '<td class="num">' + money(r.amount_cents) + '</td><td class="num">' + money(r.accum_after_cents) + '</td>'
        + '<td class="num">' + money(r.nbv_after_cents) + (r.fully ? ' <span class="badge badge-info">Done</span>' : '') + '</td></tr>').join('')
      + '</tbody><tfoot><tr class="totals"><td colspan="4" class="right muted">Total</td><td class="num">' + money(pv.total_cents) + '</td><td colspan="2"></td></tr></tfoot></table></div>'
      + '<h3 class="mt-4 mb-2">The journal entry</h3>'
      + '<div class="table-wrap"><table class="table table-compact"><thead><tr><th>Account</th><th>Memo</th><th>Department</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>'
      + lines.map((l) => '<tr><td><span class="code">' + esc(l.account_code) + '</span> ' + esc(l.account_name) + '</td><td class="small">' + esc(l.memo) + '</td>'
        + '<td class="small">' + esc(l.department || '') + '</td><td class="num">' + (l.debit_cents ? money(l.debit_cents) : '') + '</td>'
        + '<td class="num">' + (l.credit_cents ? money(l.credit_cents) : '') + '</td></tr>').join('')
      + '</tbody><tfoot><tr class="totals"><td colspan="3" class="right muted">Totals</td><td class="num">' + money(dr) + '</td><td class="num">' + money(cr) + '</td></tr></tfoot></table></div>'
      + (pv.can_post ? '<div class="form-actions"><button class="btn" type="button" data-post><span data-icon="check-circle" data-icon-size="18"></span>Post depreciation for ' + esc(p.name) + '</button></div>' : '');
  };

  const preview = async () => {
    const pid = runForm.elements.period_id.value;
    if (!pid) return;
    const b = qs('button[type="submit"]', runForm);
    busy(b, true);
    try {
      pv = await api.get('/depreciation/preview', { period_id: pid }, { signal: ctx.signal });
      paintPreview();
    } catch (err) {
      pv = null;
      if (!ctx.signal.aborted) {
        previewEl.innerHTML = '<div class="alert alert-err" data-err></div>';
        banner(qs('[data-err]', previewEl), err.message, 'err');
      }
    } finally { busy(b, false); }
  };

  const post = async (b) => {
    if (!pv) return;
    const p = pv.period;
    if (!(await confirmDialog({ title: 'Post depreciation for ' + p.name + '?', body: 'One adjusting entry of ' + money(pv.total_cents) + ' for ' + pv.rows.length + ' asset'
      + (pv.rows.length === 1 ? '' : 's') + ', dated ' + fmtDay(pv.journal.entry_date, 'long') + '. It can be undone while ' + p.name + ' is open and no later month has been run.', confirmLabel: 'Post depreciation' }))) return;
    busy(b, true);
    try {
      const r = await api.post('/depreciation/runs', { period_id: p.id });
      toast('Depreciation for ' + p.name + ' posted as ' + r.run.journal_no + '.');
      pv = null;
      previewEl.innerHTML = '';
      await load();
    } catch (err) {
      toast(err.message, { kind: 'error' });
    } finally { busy(b, false); }
  };

  const undo = async (id, b) => {
    const r = (d.items || []).find((x) => x.id === id);
    if (!r) return;
    const reason = await promptDialog({ title: 'Undo depreciation for ' + r.period, label: 'Why is it undone?', multiline: true, maxlength: 300, required: true,
      hint: r.journal_no + ' is reversed on the same date, its charges come off every asset, and the month can be run again.', confirmLabel: 'Undo the run', danger: true });
    if (reason === null) return;
    busy(b, true);
    try {
      const x = await api.post('/depreciation/runs/' + id + '/undo', { reason });
      toast('Depreciation for ' + r.period + ' undone: ' + r.journal_no + ' reversed by ' + x.reversal_no + '.');
      pv = null;
      previewEl.innerHTML = '';
      open.delete(id);
      await load();
    } catch (err) {
      toast(err.message, { kind: 'error' });
    } finally { busy(b, false); }
  };

  const load = async () => {
    try {
      d = await api.get('/depreciation/runs', null, { signal: ctx.signal });
      paintRunCard();
      paintRuns();
    } catch (err) {
      if (!ctx.signal.aborted) tbody.innerHTML = '<tr><td colspan="7">' + emptyState('warning', 'Could not load the runs', err.message) + '</td></tr>';
    }
  };

  runForm.addEventListener('submit', (e) => { e.preventDefault(); preview(); });
  runForm.elements.period_id.addEventListener('change', () => { pv = null; previewEl.innerHTML = ''; });
  previewEl.addEventListener('click', (e) => { const b = e.target.closest('[data-post]'); if (b) post(b); });
  tbody.addEventListener('click', (e) => {
    const u = e.target.closest('[data-undo]');
    if (u) { undo(Number(u.dataset.undo), u); return; }
    if (e.target.closest('a')) return;
    const tr = e.target.closest('tr[data-id]');
    if (!tr) return;
    const id = Number(tr.dataset.id);
    if (open.has(id)) open.delete(id); else open.add(id);
    paintRuns();
  });

  await load();
}
