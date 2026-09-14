/**
 * periods.js — fiscal years and their months
 *
 * GenericPOS Accounting · ES module · every role reads it
 *
 *   close / reopen a month    accountants
 *   lock a month for good     administrators
 *   open the next year        administrators (years follow on from each other)
 *
 * A month with entries still waiting (drafts, submitted) cannot close: they
 * are posted, rejected or cancelled first.
 */

import { api } from './api.js';
import { fmtDay, fmtDate } from './store.js';
import { qs, esc, emptyState, statusBadge, confirmDialog, toast, busy } from './ui.js';

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

export async function mount(root, ctx) {
  const box = qs('[data-years]', root);
  const actions = qs('[data-actions]', root);
  let st = null;

  const tile = (p, fy, can) => {
    const counts = [p.posted ? p.posted + ' posted' : 'Nothing posted', p.waiting ? p.waiting + ' waiting' : '', p.rejected ? p.rejected + ' rejected' : ''].filter(Boolean).join(' · ');
    const btns = [];
    if (p.status === 'open' && can.close) {
      btns.push(p.waiting
        ? '<button class="btn btn-secondary btn-sm" type="button" disabled>Close</button><span class="xs faint">' + p.waiting + ' waiting</span>'
        : '<button class="btn btn-secondary btn-sm" type="button" data-to="closed" data-id="' + p.id + '">Close</button>');
    }
    if (p.status === 'closed' && can.close && fy.status === 'open') btns.push('<button class="btn btn-ghost btn-sm" type="button" data-to="open" data-id="' + p.id + '">Reopen</button>');
    if (p.status === 'closed' && can.lock) btns.push('<button class="btn btn-ghost btn-sm" type="button" data-to="locked" data-id="' + p.id + '">Lock</button>');
    return '<div class="period" data-status="' + p.status + '">'
      + '<div class="p-name"><span>' + esc(p.name) + '</span>' + statusBadge(p.status) + '</div>'
      + '<div class="p-meta">' + esc(fmtDay(p.start_date, 'short') + ' – ' + fmtDay(p.end_date, 'short')) + '</div>'
      + '<div class="small"><a href="journals?period=' + p.id + '">' + esc(counts) + '</a></div>'
      + (p.closed_by ? '<div class="p-meta">Closed by ' + esc(p.closed_by) + (p.closed_at ? ', ' + esc(fmtDate(p.closed_at, 'date')) : '') + '</div>' : '')
      + (btns.length ? '<div class="p-actions row gap-2">' + btns.join('') + '</div>' : '') + '</div>';
  };

  const firstYearForm = () => '<section class="card card-pad" style="max-width:560px"><h3>Open the first fiscal year</h3>'
    + '<p class="muted">Every entry needs an open month of an open year. Choose when the first year starts; each year after it follows on by itself.</p>'
    + '<form class="row gap-2" data-first novalidate>'
    + '<select class="select" name="month" id="fy-month" aria-label="First month" style="width:auto">'
    + MONTHS.map((m, i) => '<option value="' + (i + 1) + '"' + (i + 1 === st.start_month ? ' selected' : '') + '>' + m + '</option>').join('') + '</select>'
    + '<input class="input" name="year" id="fy-year" type="number" min="2000" max="2100" value="' + String(st.today).slice(0, 4) + '" aria-label="Year" style="width:110px">'
    + '<button class="btn" type="submit">Open it</button></form><div class="error mt-2" data-first-err></div></section>';

  const render = () => {
    const can = st.can;
    actions.innerHTML = can.create_year && st.next_start
      ? '<button class="btn" type="button" data-new-year><span data-icon="plus" data-icon-size="18"></span>Open the year from ' + esc(fmtDay(st.next_start)) + '</button>' : '';
    if (!st.years.length) {
      box.innerHTML = can.create_year ? firstYearForm() : emptyState('calendar', 'No fiscal year yet', 'An administrator opens the first one. Until then nothing can be posted.');
      return;
    }
    box.innerHTML = st.years.map((fy) => '<section class="card">'
      + '<div class="card-head"><div><h3>' + esc(fy.name) + '</h3><div class="small muted">' + esc(fmtDay(fy.start_date) + ' – ' + fmtDay(fy.end_date)) + '</div></div>' + statusBadge(fy.status) + '</div>'
      + '<div class="card-body"><div class="periods">' + fy.periods.map((p) => tile(p, fy, can)).join('') + '</div></div></section>').join('');
  };

  const load = async () => {
    try {
      st = await api.get('/fiscal-years', null, { signal: ctx.signal });
      render();
    } catch (err) {
      if (!ctx.signal.aborted) box.innerHTML = emptyState('warning', 'Could not load the fiscal years', err.message);
    }
  };

  const PROMPT = {
    closed: (n) => ({ title: 'Close ' + n + '?', body: 'Nothing more can be posted into ' + n + ' until an accountant reopens it.', confirmLabel: 'Close ' + n }),
    open: (n) => ({ title: 'Reopen ' + n + '?', body: 'Entries can be posted into ' + n + ' again until it is closed.', confirmLabel: 'Reopen' }),
    locked: (n) => ({ title: 'Lock ' + n + ' for good?', body: 'A locked month can never be reopened. Lock it once its figures are final — after the audit, or once the returns for it are filed.', confirmLabel: 'Lock it', danger: true }),
  };

  root.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-to]');
    if (b) {
      const p = st.years.flatMap((y) => y.periods).find((x) => x.id === Number(b.dataset.id));
      if (!p || !(await confirmDialog(PROMPT[b.dataset.to](p.name)))) return;
      busy(b, true);
      try {
        st = await api.post('/periods/' + p.id + '/status', { status: b.dataset.to });
        toast(p.name + ({ closed: ' is closed.', open: ' is open again.', locked: ' is locked.' })[b.dataset.to]);
        render();
        window.dispatchEvent(new CustomEvent('gp:badges'));
      } catch (err) { toast(err.message, { kind: 'error' }); busy(b, false); }
      return;
    }
    const n = e.target.closest('[data-new-year]');
    if (n) {
      if (!(await confirmDialog({ title: 'Open the next fiscal year?', body: 'Twelve open months starting ' + fmtDay(st.next_start, 'long') + '. Entries can then be dated in them.', confirmLabel: 'Open it' }))) return;
      busy(n, true);
      try {
        st = await api.post('/fiscal-years', {});
        toast('The new fiscal year is open.');
        render();
      } catch (err) { toast(err.message, { kind: 'error' }); busy(n, false); }
    }
  });

  root.addEventListener('submit', async (e) => {
    const f = e.target.closest('[data-first]');
    if (!f) return;
    e.preventDefault();
    const m = String(f.elements.month.value).padStart(2, '0');
    const y = String(f.elements.year.value).trim();
    const errEl = qs('[data-first-err]', root);
    errEl.textContent = '';
    if (!/^\d{4}$/.test(y)) { errEl.textContent = 'Enter the year.'; return; }
    const b = qs('button[type="submit"]', f);
    busy(b, true);
    try {
      st = await api.post('/fiscal-years', { start: y + '-' + m + '-01' });
      toast('The fiscal year is open.');
      render();
    } catch (err) {
      errEl.textContent = (err.errors && err.errors.start) || err.message;
      busy(b, false);
    }
  });

  await load();
}
