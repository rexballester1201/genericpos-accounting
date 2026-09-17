/**
 * templates.js — saved and recurring journal entries
 *
 * GenericPOS Accounting · ES module · bookkeepers and above
 *
 *   Use it         opens a new entry filled from the saved entry (journals/new?template=ID)
 *   Schedule       a day of the month makes it recurring: the scheduled job
 *                  drafts it for its owner on that day (Template_model::run_due)
 *   Delete         its creator, or an accountant
 *
 * Saved entries are made from the entry form ("Save as a saved entry"); to
 * change a saved entry's lines, use it, correct them, and save it again over
 * itself.
 */

import { api } from './api.js';
import { money, fmtDay } from './store.js';
import { qs, esc, emptyState, toast, busy, confirmDialog } from './ui.js';
import { hasRole } from './router.js';

const ordinal = (n) => { const s = ['th', 'st', 'nd', 'rd']; const v = n % 100; return n + (s[(v - 20) % 10] || s[v] || s[0]); };
const dayText = (d) => (d === 31 ? 'last day' : d >= 29 ? ordinal(d) + ' (or the last day of a shorter month)' : ordinal(d));

export async function mount(root, ctx) {
  const box = qs('[data-body]', root);
  let items = [];
  const me = Number(ctx.user && ctx.user.id) || 0;

  const schedule = (t) => (t.recur_day
    ? (t.is_active ? 'Drafted every month on the ' + dayText(t.recur_day) + (t.next_date ? ' · next on ' + fmtDay(t.next_date, 'short') : '') : 'Recurring, paused')
    : 'Used by hand');

  const card = (t) => {
    const canChange = t.created_by === me || hasRole(ctx.user, 'accountant');
    return '<section class="card" data-t="' + t.id + '"><div class="card-head"><div><h3>' + esc(t.name) + '</h3>'
      + '<div class="small muted">' + esc(t.book_label + ' · ' + schedule(t) + (t.created_by_name ? ' · saved by ' + t.created_by_name : '')) + '</div></div>'
      + '<div class="row gap-2"><a class="btn btn-sm" href="journals/new?template=' + t.id + '"><span data-icon="copy" data-icon-size="16"></span>Use it</a>'
      + (canChange ? '<button class="btn btn-secondary btn-sm" type="button" data-toggle aria-expanded="false"><span data-icon="calendar" data-icon-size="16"></span>Schedule</button>'
        + '<button class="btn btn-ghost btn-sm" type="button" data-delete aria-label="Delete ' + esc(t.name) + '"><span data-icon="trash" data-icon-size="16"></span></button>' : '')
      + '</div></div>'
      + '<div class="card-body stack">'
      + '<p class="small">' + esc(t.description) + (t.reference ? ' · Ref. ' + esc(t.reference) : '') + (t.party_name ? ' · ' + esc(t.party_name) : '') + '</p>'
      + (t.problems.length ? '<div class="alert alert-warn"><span data-icon="warning" data-icon-size="18"></span><div>Needs fixing: ' + esc(t.problems.join('; '))
        + '. Use it, correct the lines, and save it again over itself.</div></div>' : '')
      + '<div class="table-wrap"><table class="table table-compact"><thead><tr><th>Account</th><th>Memo</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>'
      + t.lines.map((l) => '<tr><td><span class="code">' + esc(l.code) + '</span> ' + esc(l.name)
        + (l.department_code ? ' <span class="badge">' + esc(l.department_code) + '</span>' : '')
        + (l.contact_name ? ' <span class="small muted">' + esc(l.contact_name) + '</span>' : '') + '</td>'
        + '<td>' + esc(l.memo) + '</td><td class="num">' + (l.debit_cents ? esc(money(l.debit_cents)) : (l.credit_cents ? '' : '<span class="faint">to fill in</span>')) + '</td>'
        + '<td class="num">' + (l.credit_cents ? esc(money(l.credit_cents)) : '') + '</td></tr>').join('')
      + '</tbody></table></div>'
      + (canChange ? '<form class="stack" data-sched hidden novalidate>'
        + '<div class="row gap-3" style="align-items:flex-end">'
        + '<div class="field"><label class="label" for="tpl-day-' + t.id + '">Draft it every month on</label>'
        + '<select class="select" name="recur_day" id="tpl-day-' + t.id + '"><option value="">Never — I use it by hand</option>'
        + Array.from({ length: 31 }, (_, i) => i + 1).map((d) => '<option value="' + d + '"' + (d === t.recur_day ? ' selected' : '') + '>the ' + esc(dayText(d)) + '</option>').join('') + '</select></div>'
        + '<div class="field"><label class="label" for="tpl-next-' + t.id + '">Next draft on</label>'
        + '<input class="input" type="date" name="next_date" id="tpl-next-' + t.id + '" value="' + esc(t.next_date || '') + '"></div>'
        + '<label class="row gap-2" for="tpl-on-' + t.id + '"><input type="checkbox" name="is_active" id="tpl-on-' + t.id + '"' + (t.is_active ? ' checked' : '') + '> Active</label>'
        + '<button class="btn btn-sm" type="submit">Save the schedule</button></div>'
        + '<p class="small muted">The draft is made for ' + esc(t.created_by === me ? 'you' : t.created_by_name) + ' on that day when the scheduled job runs, and you are told in your notifications. A recurring entry needs every amount filled in.</p>'
        + '</form>' : '')
      + '</div></section>';
  };

  const render = () => {
    box.innerHTML = items.length ? items.map(card).join('')
      : emptyState('copy', 'No saved entries yet', 'Fill in an entry you make often, then choose "Save as a saved entry" below its lines.', '<a class="btn" href="journals/new">New entry</a>');
  };

  const load = async () => {
    try {
      items = (await api.get('/journal-templates', null, { signal: ctx.signal })).items || [];
      render();
    } catch (err) {
      if (!ctx.signal.aborted) box.innerHTML = emptyState('warning', 'Could not load the saved entries', err.message);
    }
  };

  const bodyOf = (t, over) => Object.assign({
    name: t.name, book: t.book, description: t.description, reference: t.reference || '', party_name: t.party_name || '',
    recur_day: t.recur_day, next_date: t.next_date, is_active: t.is_active,
    lines: t.lines.map((l) => ({ account_id: l.account_id, debit_cents: l.debit_cents, credit_cents: l.credit_cents, memo: l.memo, department_id: l.department_id || 0, contact_id: l.contact_id || 0 })),
  }, over);

  root.addEventListener('click', async (e) => {
    const sec = e.target.closest('[data-t]');
    if (!sec) return;
    const t = items.find((x) => x.id === Number(sec.dataset.t));
    if (!t) return;

    const tog = e.target.closest('[data-toggle]');
    if (tog) {
      const form = qs('[data-sched]', sec);
      form.hidden = !form.hidden;
      tog.setAttribute('aria-expanded', form.hidden ? 'false' : 'true');
      return;
    }

    const del = e.target.closest('[data-delete]');
    if (del) {
      if (!(await confirmDialog({ title: 'Delete "' + t.name + '"?', body: 'Entries already made from it stay as they are.', confirmLabel: 'Delete it', danger: true }))) return;
      busy(del, true);
      try {
        await api.del('/journal-templates/' + t.id);
        items = items.filter((x) => x.id !== t.id);
        toast('"' + t.name + '" is deleted.');
        render();
      } catch (err) { toast(err.message, { kind: 'error' }); busy(del, false); }
    }
  });

  root.addEventListener('submit', async (e) => {
    const form = e.target.closest('[data-sched]');
    if (!form) return;
    e.preventDefault();
    const t = items.find((x) => x.id === Number(form.closest('[data-t]').dataset.t));
    const day = parseInt(form.elements.recur_day.value, 10) || null;
    const btn = qs('button[type="submit"]', form);
    busy(btn, true);
    try {
      const saved = await api.put('/journal-templates/' + t.id, bodyOf(t, { recur_day: day, next_date: day ? form.elements.next_date.value : '', is_active: form.elements.is_active.checked }));
      items = items.map((x) => (x.id === t.id ? saved : x));
      toast(day ? '"' + t.name + '" will be drafted on the ' + dayText(day) + ' of each month.' : '"' + t.name + '" is no longer recurring.');
      render();
    } catch (err) {
      toast(err.errors ? Object.values(err.errors).join(' ') : err.message, { kind: 'error' });
      busy(btn, false);
    }
  });

  await load();
}
