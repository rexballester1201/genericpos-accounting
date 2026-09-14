/**
 * accounts.js — the chart of accounts
 *
 * GenericPOS Accounting · ES module · every role reads it; administrators change it
 *
 * The chart in tree order with today's balances (headers roll up the accounts
 * under them). Opening an account shows its entries, or — for an
 * administrator — its settings. What can no longer change once an account has
 * entries is shown locked, with the reason; the server enforces the same rules.
 */

import { api } from './api.js';
import { money, fmtDay } from './store.js';
import { qs, qsa, esc, emptyState, openModal, busy, formError, clearErrors, toast, confirmDialog, debounce } from './ui.js';

const TYPES = ['asset', 'liability', 'equity', 'income', 'expense'];
const SUBTYPE_LABELS = { current: 'Current', non_current: 'Non-current', capital: 'Capital', retained: 'Retained earnings', reserve: 'Reserve',
  drawing: 'Drawings', other: 'Other', operating: 'Operating', cost_of_sales: 'Cost of sales', finance: 'Finance cost', income_tax: 'Income tax' };
const CF_LABELS = { operating: 'Operating', investing: 'Investing', financing: 'Financing', cash: 'Cash and cash equivalents' };

export async function mount(root, ctx) {
  const tbody = qs('[data-rows]', root);
  const form = qs('[data-filters]', root);
  const state = { type: TYPES.includes(ctx.query.get('type')) ? ctx.query.get('type') : '', q: '', inactive: false };
  let items = [];
  let meta = { types: {}, subtypes: {}, tags: [] };
  let canEdit = false;

  const render = () => {
    const q = state.q.toLowerCase();
    const rows = items.filter((a) => (!state.type || a.type === state.type) && (state.inactive || a.is_active)
      && (!q || a.code.toLowerCase().includes(q) || a.name.toLowerCase().includes(q)));
    tbody.innerHTML = rows.length ? rows.map((a) => {
      const rules = [];
      if (a.control === 'ar') rules.push('<span class="badge badge-info">Receivables control</span>');
      if (a.control === 'ap') rules.push('<span class="badge badge-info">Payables control</span>');
      if (a.requires_department) rules.push('<span class="badge">Needs a department</span>');
      if (a.is_contra) rules.push('<span class="badge">Contra</span>');
      if (a.default_role) rules.push('<span class="badge badge-brand" title="Settings → Account defaults">Default: ' + esc(a.default_role) + '</span>');
      if (!a.is_active) rules.push('<span class="badge badge-warn">Inactive</span>');
      return '<tr class="is-link' + (a.is_header ? ' is-header' : '') + (a.is_active ? '' : ' is-inactive') + '" data-id="' + a.id + '">'
        + '<td class="code">' + esc(a.code) + '</td>'
        + '<td style="padding-left:' + (12 + (q ? 0 : a.depth * 18)) + 'px">' + esc(a.name) + '</td>'
        + '<td class="small nowrap">' + esc(meta.types[a.type] || a.type) + (a.subtype ? ' · ' + esc(SUBTYPE_LABELS[a.subtype] || a.subtype) : '') + '</td>'
        + '<td class="small">' + (a.normal_side === 'D' ? 'Debit' : 'Credit') + '</td>'
        + '<td><div class="chips-inline">' + rules.join('') + '</div></td>'
        + '<td class="num">' + (a.is_header && !a.balance_cents ? '' : money(a.balance_cents)) + '</td></tr>';
    }).join('') : '<tr><td colspan="6">' + emptyState('list-numbers', 'No accounts match', 'Try another search or type.') + '</td></tr>';
  };

  const paintTypes = () => {
    const box = qs('[data-types]', root);
    box.innerHTML = '<button type="button" data-type="">All</button>' + TYPES.map((t) => '<button type="button" data-type="' + t + '">' + esc(meta.types[t] || t) + '</button>').join('');
    qsa('button', box).forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.type === state.type)));
  };

  const load = async () => {
    try {
      const d = await api.get('/accounts', null, { signal: ctx.signal });
      items = d.items || [];
      meta = d.meta || meta;
      canEdit = !!d.can_edit;
      const n = items.filter((a) => !a.is_header).length;
      qs('[data-sub]', root).textContent = n + ' accounts under ' + items.filter((a) => a.is_header).length + ' headers · balances as of ' + fmtDay(d.as_of, 'long');
      qs('[data-actions]', root).innerHTML = canEdit ? '<button class="btn" type="button" data-new><span data-icon="plus" data-icon-size="18"></span>New account</button>' : '';
      qs('[data-foot]', root).textContent = canEdit
        ? 'Open an account to change it. Its type, contra and header flags freeze once it has postings; whether it is a control account freezes once it has any entry.'
        : 'Open an account to see its entries. Balance-sheet balances run from the first entry; income and expenses from the start of this fiscal year.';
      paintTypes();
      render();
    } catch (err) {
      if (!ctx.signal.aborted) tbody.innerHTML = '<tr><td colspan="6">' + emptyState('warning', 'Could not load the chart', err.message) + '</td></tr>';
    }
  };

  // ── editing (administrators) ──────────────────────────────────────────
  const opt = (v, label, sel) => '<option value="' + esc(v) + '"' + (sel ? ' selected' : '') + '>' + esc(label) + '</option>';
  const field = (id, label, control, name, hint) => '<div class="field"><label class="label" for="' + id + '">' + label + '</label>' + control
    + (hint ? '<div class="hint">' + hint + '</div>' : '') + '<div class="error" data-error-for="' + name + '"></div></div>';
  const check = (name, label, on, disabled) => '<label class="check"><input type="checkbox" name="' + name + '"' + (on ? ' checked' : '') + (disabled ? ' disabled' : '') + '> <span>' + label + '</span></label>';

  const edit = (a) => {
    const isNew = !a;
    const x = a || { id: 0, code: '', name: '', parent_id: null, is_header: false, type: state.type || 'asset', subtype: null, is_contra: false,
      cash_flow: null, control: null, requires_department: false, tags: [], description: '', is_active: true, has_lines: false, has_postings: false };
    const lockType = x.has_postings;
    const lockUse = x.has_lines;

    const html = '<form class="stack" data-f novalidate><div class="alert alert-err" data-banner hidden></div>'
      + (lockType || lockUse ? '<div class="alert alert-neutral"><span data-icon="lock-key"></span><div>'
        + (lockType ? 'This account has postings, so its type and its contra and header flags are fixed.' : 'This account has entries, so whether it is a header or a control account is fixed.')
        + '</div></div>' : '')
      + '<div class="form-grid">'
      + field('ac-code', 'Code', '<input class="input input-mono" id="ac-code" name="code" maxlength="20" value="' + esc(x.code) + '" autocomplete="off">', 'code')
      + field('ac-name', 'Name', '<input class="input" id="ac-name" name="name" maxlength="160" value="' + esc(x.name) + '" autocomplete="off">', 'name')
      + field('ac-type', 'Type', '<select class="select" id="ac-type" name="type"' + (lockType ? ' disabled' : '') + '>' + TYPES.map((t) => opt(t, meta.types[t] || t, t === x.type)).join('') + '</select>', 'type')
      + field('ac-parent', 'Under', '<select class="select" id="ac-parent" name="parent_id"></select>', 'parent_id')
      + field('ac-subtype', 'Classification', '<select class="select" id="ac-subtype" name="subtype"></select>', 'subtype', 'Where it appears on the statements.')
      + field('ac-cf', 'Cash-flow class', '<select class="select" id="ac-cf" name="cash_flow">' + opt('', '—', !x.cash_flow)
        + Object.keys(CF_LABELS).map((k) => opt(k, CF_LABELS[k], x.cash_flow === k)).join('') + '</select>', 'cash_flow')
      + field('ac-control', 'Control account', '<select class="select" id="ac-control" name="control"' + (lockUse ? ' disabled' : '') + '>'
        + opt('', 'No', !x.control) + opt('ar', 'Receivables — every line names a customer', x.control === 'ar') + opt('ap', 'Payables — every line names a supplier', x.control === 'ap') + '</select>', 'control')
      + field('ac-tags', 'Analysis tags', '<input class="input" id="ac-tags" name="tags" value="' + esc((x.tags || []).join(', ')) + '" autocomplete="off">', 'tags',
        'Comma-separated, from: ' + esc((meta.tags || []).join(', ')) + '.')
      + '<div class="field span-2"><label class="label" for="ac-desc">Notes <span class="opt">(optional)</span></label>'
      + '<textarea class="textarea" id="ac-desc" name="description" maxlength="500" style="min-height:64px">' + esc(x.description || '') + '</textarea></div>'
      + '</div>'
      + '<div class="stack gap-2">'
      + check('is_header', 'A header — it groups accounts and takes no entries', x.is_header, lockUse)
      + check('is_contra', 'A contra account — its balance sits on the opposite side (an allowance, accumulated depreciation)', x.is_contra, lockType)
      + check('requires_department', 'Every line on it needs a department', x.requires_department, false)
      + (isNew ? '' : check('is_active', 'Active — it can take new entries', x.is_active, false))
      + '<div class="error" data-error-for="is_header"></div><div class="error" data-error-for="is_contra"></div><div class="error" data-error-for="is_active"></div></div>'
      + '<div class="row between gap-2"><div class="row gap-2">'
      + (!isNew && !x.has_lines ? '<button class="btn btn-danger-soft" type="button" data-del>Delete</button>' : '')
      + (!isNew && !x.is_header ? '<a class="btn btn-ghost" href="journals?account=' + x.id + '">View its entries</a>' : '')
      + '</div><div class="row gap-2"><button class="btn btn-secondary" type="button" data-x>Cancel</button>'
      + '<button class="btn" type="submit">' + (isNew ? 'Add account' : 'Save') + '</button></div></div></form>';

    const m = openModal({ title: isNew ? 'New account' : x.code + ' · ' + x.name, size: 'lg', body: html });
    const f = qs('[data-f]', m.el);
    const el = f.elements;

    const fillForType = () => {
      const t = el.type.value;
      el.parent_id.innerHTML = opt('', '(top level)', !x.parent_id)
        + items.filter((h) => h.is_header && h.type === t && h.id !== x.id).map((h) => opt(h.id, h.code + ' · ' + h.name, h.id === x.parent_id)).join('');
      el.subtype.innerHTML = opt('', '—', !x.subtype) + ((meta.subtypes || {})[t] || []).map((s) => opt(s, SUBTYPE_LABELS[s] || s, s === x.subtype)).join('');
    };
    fillForType();
    el.type.addEventListener('change', fillForType);
    qs('[data-x]', f).addEventListener('click', () => m.close());

    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErrors(f);
      const payload = {
        code: el.code.value.trim(), name: el.name.value.trim(), type: el.type.value,
        parent_id: el.parent_id.value ? Number(el.parent_id.value) : null, subtype: el.subtype.value || null,
        cash_flow: el.cash_flow.value || null, control: el.control.value || null,
        is_header: el.is_header.checked, is_contra: el.is_contra.checked, requires_department: el.requires_department.checked,
        is_active: el.is_active ? el.is_active.checked : true, tags: el.tags.value, description: el.description.value.trim(),
      };
      const b = qs('button[type="submit"]', f);
      busy(b, true);
      try {
        if (isNew) await api.post('/accounts', payload);
        else await api.put('/accounts/' + x.id, payload);
        m.close();
        toast(isNew ? 'Account ' + payload.code + ' added.' : 'Account ' + payload.code + ' saved.');
        load();
      } catch (err) { formError(f, err); } finally { busy(b, false); }
    });

    const del = qs('[data-del]', f);
    if (del) {
      del.addEventListener('click', async () => {
        if (!(await confirmDialog({ title: 'Delete ' + x.code + '?', body: 'Nothing has ever used it, so it can go. This cannot be undone.', confirmLabel: 'Delete', danger: true }))) return;
        try {
          await api.del('/accounts/' + x.id);
          m.close();
          toast('Account ' + x.code + ' deleted.');
          load();
        } catch (err) { formError(f, err); }
      });
    }
  };

  // ── wiring ────────────────────────────────────────────────────────────
  form.addEventListener('submit', (e) => e.preventDefault());
  form.elements.q.addEventListener('input', debounce(() => { state.q = form.elements.q.value.trim(); render(); }, 150));
  form.elements.inactive.addEventListener('change', () => { state.inactive = form.elements.inactive.checked; render(); });
  qs('[data-types]', root).addEventListener('click', (e) => {
    const b = e.target.closest('[data-type]');
    if (!b) return;
    state.type = b.dataset.type;
    paintTypes();
    render();
  });
  root.addEventListener('click', (e) => { if (e.target.closest('[data-new]')) edit(null); });
  tbody.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-id]');
    if (!tr) return;
    const a = items.find((x) => x.id === Number(tr.dataset.id));
    if (!a) return;
    if (canEdit) edit(a);
    else if (!a.is_header) ctx.navigate('/journals?account=' + a.id);
  });

  await load();
}
