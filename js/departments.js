/**
 * departments.js — departments, branches and cost centres
 *
 * GenericPOS Accounting · ES module · every role reads it; administrators change it
 *
 * The departments as a tree, each with this fiscal year's income, expenses
 * and net from the ledger, and links to the reports that break the books
 * down by department. Administrators add, change, deactivate and delete them
 * in a dialog; whatever the server refuses comes back with its reason (a
 * department with entries waiting to post cannot be deactivated, a used one
 * cannot be deleted).
 */

import { api } from './api.js';
import { money, fmtDay, store } from './store.js';
import { qs, esc, emptyState, statusBadge, openModal, busy, formError, clearErrors, toast, confirmDialog, debounce } from './ui.js';

export async function mount(root, ctx) {
  const coop = store().entity_type === 'cooperative';
  const tbody = qs('[data-rows]', root);
  const tfoot = qs('[data-total-rows]', root);
  const form = qs('[data-filters]', root);
  const state = { q: '', inactive: ctx.query.get('inactive') === '1' };
  let d = null;

  form.elements.inactive.checked = state.inactive;
  qs('[data-net-head]', root).textContent = coop ? 'Net surplus' : 'Net income';

  const period = () => (d && d.period) || { from: '', to: '' };
  const isHref = (id) => 'reports/income-statement?' + new URLSearchParams({ department: String(id), from: period().from, to: period().to }).toString();
  const bvaHref = (id) => 'reports/budget-vs-actual?department_id=' + id;
  const figures = (y) => '<td class="num">' + money(y.income_cents) + '</td><td class="num">' + money(y.expense_cents) + '</td>'
    + '<td class="num' + (y.net_cents < 0 ? ' err-text' : '') + '">' + money(y.net_cents) + '</td>';

  const subtree = (id) => {
    const out = [id];
    for (let i = 0; i < out.length; i++) d.items.forEach((x) => { if (x.parent_id === out[i] && !out.includes(x.id)) out.push(x.id); });
    return out;
  };

  const render = () => {
    const q = state.q.toLowerCase();
    const rows = d.items.filter((x) => (state.inactive || x.is_active) && (!q || x.code.toLowerCase().includes(q) || x.name.toLowerCase().includes(q)));
    tbody.innerHTML = rows.length ? rows.map((x) => {
      const w = x.ytd_with_children;
      return '<tr class="' + (d.can_edit ? 'is-link' : '') + (x.is_active ? '' : ' is-inactive') + '" data-id="' + x.id + '">'
        + '<td class="code">' + esc(x.code) + '</td>'
        + '<td style="padding-left:' + (12 + (q ? 0 : x.depth * 20)) + 'px">' + esc(x.name)
        + (w ? '<div class="small muted">With its sub-departments: ' + (coop ? 'net surplus ' : 'net ') + money(w.net_cents) + '</div>' : '') + '</td>'
        + figures(x.ytd)
        + '<td>' + statusBadge(x.is_active ? 'active' : 'inactive') + '</td>'
        + '<td class="nowrap right"><a class="btn btn-ghost btn-sm" href="' + esc(isHref(x.id)) + '">Income statement</a>'
        + '<a class="btn btn-ghost btn-sm" href="' + esc(bvaHref(x.id)) + '">Budget vs actual</a></td></tr>';
    }).join('') : '<tr><td colspan="7">' + (d.items.length
      ? emptyState('magnifying-glass', 'No departments match', 'Try another search, or show the inactive ones.')
      : emptyState('buildings', 'No departments yet', d.can_edit ? 'Add one to put it on journal lines, invoices, bills and budgets.' : 'An administrator adds them.')) + '</td></tr>';

    tfoot.innerHTML = d.items.length
      ? '<tr class="muted"><td></td><td>No department</td>' + figures(d.no_department) + '<td colspan="2"></td></tr>'
        + '<tr class="totals"><td></td><td><b>Total</b></td>' + figures(d.total) + '<td colspan="2" class="right"><a class="btn btn-ghost btn-sm" href="'
        + esc('reports/income-statement?' + new URLSearchParams({ from: period().from, to: period().to }).toString()) + '">Income statement</a></td></tr>'
      : '';
  };

  const load = async () => {
    try {
      d = await api.get('/departments', null, { signal: ctx.signal });
    } catch (err) {
      if (!ctx.signal.aborted) tbody.innerHTML = '<tr><td colspan="7">' + emptyState('warning', 'Could not load the departments', err.message) + '</td></tr>';
      return;
    }
    const p = period();
    const active = d.items.filter((x) => x.is_active).length;
    qs('[data-sub]', root).textContent = active + ' active department' + (active === 1 ? '' : 's')
      + (d.items.length > active ? ', ' + (d.items.length - active) + ' inactive' : '') + ' · ' + (p.fiscal_year ? p.fiscal_year + ' to date' : 'this year to date');
    qs('[data-actions]', root).innerHTML = '<a class="btn btn-secondary" href="' + esc('reports/department-income?' + new URLSearchParams({ from: p.from, to: p.to }).toString()) + '">'
      + '<span data-icon="chart-bar" data-icon-size="18"></span>Income by department</a>'
      + (d.can_edit ? '<button class="btn" type="button" data-new><span data-icon="plus" data-icon-size="18"></span>New department</button>' : '');
    qs('[data-foot]', root).textContent = 'Income, expenses and ' + (coop ? 'net surplus' : 'net income') + ' come from posted entries, ' + fmtDay(p.from, 'long') + ' to '
      + fmtDay(p.to, 'long') + ', with closing entries left out. Each department shows its own lines; a department with sub-departments also shows them added in. '
      + (d.can_edit ? 'Open a department to change, deactivate or delete it.' : 'Only an administrator can change departments.');
    render();
  };

  // ── adding and changing (administrators) ──────────────────────────────
  const field = (id, label, control, name, hint) => '<div class="field"><label class="label" for="' + id + '">' + label + '</label>' + control
    + (hint ? '<div class="hint">' + hint + '</div>' : '') + '<div class="error" data-error-for="' + name + '"></div></div>';

  const edit = (x) => {
    const isNew = !x;
    const cur = x || { id: 0, code: '', name: '', parent_id: null, is_active: true, can_delete: false, used_by: '' };
    const below = new Set(isNew ? [] : subtree(cur.id));
    const opts = '<option value="">(none: a top-level department)</option>' + d.items.filter((p) => !below.has(p.id)).map((p) => '<option value="' + p.id + '"'
      + (p.id === cur.parent_id ? ' selected' : '') + (!p.is_active && p.id !== cur.parent_id ? ' disabled' : '') + '>'
      + esc(' '.repeat(p.depth) + p.code + ' · ' + p.name + (p.is_active ? '' : ' (inactive)')) + '</option>').join('');

    const html = '<form class="stack" data-f novalidate><div class="alert alert-err" data-banner hidden></div>'
      + (!isNew && !cur.is_active ? '<div class="alert alert-neutral"><span data-icon="prohibit"></span><div>Inactive: it is not offered on new entries, invoices, bills or budget amounts. Its history and reports stay.</div></div>' : '')
      + '<div class="form-grid">'
      + field('dp-code', 'Code', '<input class="input input-mono" id="dp-code" name="code" maxlength="20" value="' + esc(cur.code) + '" autocomplete="off" spellcheck="false" style="text-transform:uppercase">',
        'code', 'Capital letters, digits, - and _, such as SLS or BR-NAGA.')
      + field('dp-name', 'Name', '<input class="input" id="dp-name" name="name" maxlength="120" value="' + esc(cur.name) + '" autocomplete="off">', 'name')
      + '<div class="field span-2"><label class="label" for="dp-parent">Part of <span class="opt">(optional)</span></label><select class="select" id="dp-parent" name="parent_id">' + opts + '</select>'
      + '<div class="hint">A branch under a region, or a section under a department. Reports keep each one\'s own lines.</div><div class="error" data-error-for="parent_id"></div></div>'
      + '</div>'
      + (!isNew && cur.used_by ? '<p class="small muted mb-0">Used by ' + esc(cur.used_by) + '.</p>' : '')
      + '<div class="row between gap-2"><div class="row gap-2">'
      + (!isNew && cur.can_delete ? '<button class="btn btn-danger-soft" type="button" data-del>Delete</button>' : '')
      + (!isNew ? '<button class="btn btn-ghost" type="button" data-toggle>' + (cur.is_active ? 'Deactivate' : 'Reactivate') + '</button>' : '')
      + '</div><div class="row gap-2"><button class="btn btn-secondary" type="button" data-x>Cancel</button>'
      + '<button class="btn" type="submit">' + (isNew ? 'Add department' : 'Save') + '</button></div></div></form>';

    const m = openModal({ title: isNew ? 'New department' : cur.code + ' · ' + cur.name, body: html });
    const f = qs('[data-f]', m.el);
    const el = f.elements;
    qs('[data-x]', f).addEventListener('click', () => m.close());

    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErrors(f);
      const payload = { code: el.code.value.trim().toUpperCase(), name: el.name.value.trim(), parent_id: el.parent_id.value ? Number(el.parent_id.value) : null };
      const b = qs('button[type="submit"]', f);
      busy(b, true);
      try {
        if (isNew) await api.post('/departments', payload);
        else await api.put('/departments/' + cur.id, payload);
        m.close();
        toast('Department ' + payload.code + (isNew ? ' added.' : ' saved.'));
        load();
      } catch (err) { formError(f, err); } finally { busy(b, false); }
    });

    const toggle = qs('[data-toggle]', f);
    if (toggle) {
      toggle.addEventListener('click', async () => {
        const off = cur.is_active;
        const ok = await confirmDialog(off
          ? { title: 'Deactivate ' + cur.code + '?', body: 'It will no longer be offered on new entries, invoices, bills or budget amounts. Its history and reports stay, and you can reactivate it later.', confirmLabel: 'Deactivate' }
          : { title: 'Reactivate ' + cur.code + '?', body: 'It can be put on entries and budgets again.', confirmLabel: 'Reactivate' });
        if (!ok) return;
        busy(toggle, true);
        try {
          await api.post('/departments/' + cur.id + (off ? '/deactivate' : '/reactivate'));
          m.close();
          toast('Department ' + cur.code + (off ? ' is inactive.' : ' is active again.'));
          load();
        } catch (err) { formError(f, err); } finally { busy(toggle, false); }
      });
    }

    const del = qs('[data-del]', f);
    if (del) {
      del.addEventListener('click', async () => {
        if (!(await confirmDialog({ title: 'Delete ' + cur.code + '?', body: 'Nothing has ever used it, so it can go. This cannot be undone.', confirmLabel: 'Delete', danger: true }))) return;
        busy(del, true);
        try {
          await api.del('/departments/' + cur.id);
          m.close();
          toast('Department ' + cur.code + ' deleted.');
          load();
        } catch (err) { formError(f, err); } finally { busy(del, false); }
      });
    }
  };

  // ── wiring ────────────────────────────────────────────────────────────
  form.addEventListener('submit', (e) => e.preventDefault());
  form.elements.q.addEventListener('input', debounce(() => { state.q = form.elements.q.value.trim(); if (d) render(); }, 150));
  form.elements.inactive.addEventListener('change', () => { state.inactive = form.elements.inactive.checked; if (d) render(); });
  root.addEventListener('click', (e) => { if (e.target.closest('[data-new]')) edit(null); });
  tbody.addEventListener('click', (e) => {
    if (!d || !d.can_edit || e.target.closest('a, button')) return;
    const tr = e.target.closest('tr[data-id]');
    const x = tr && d.items.find((i) => i.id === Number(tr.dataset.id));
    if (x) edit(x);
  });

  await load();
}
