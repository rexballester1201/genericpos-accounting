/**
 * asset-categories.js — fixed-asset categories
 *
 * GenericPOS Accounting · ES module · every role reads them; accountants change them
 *
 * A category names three accounts — where the cost is kept, where the
 * accumulated depreciation is kept (a contra-asset account), and what the
 * depreciation is charged to — and suggests a method, useful life and
 * residual value for NEW assets. Changing a category never changes an asset
 * already registered: each asset carries its own figures. Once a category has
 * assets its accounts are fixed; the form shows them locked, with the reason.
 */

import { api } from './api.js';
import { qs, esc, emptyState, openModal, busy, formError, clearErrors, setErrors, toast, confirmDialog, statusBadge } from './ui.js';

/** "2.5" → 250 basis points, exactly; null when not a percentage from 0 to 100 with at most two decimals. */
const toBp = (s) => {
  const m = String(s == null ? '' : s).trim().replace(/%$/, '').trim().match(/^(\d{1,3})(?:\.(\d{1,2}))?$/);
  if (!m) return null;
  const bp = Number(m[1]) * 100 + Number((m[2] || '').padEnd(2, '0'));
  return bp <= 10000 ? bp : null;
};
const bpText = (bp) => {
  const n = Number(bp) || 0;
  const frac = n % 100;
  return Math.floor(n / 100) + (frac ? '.' + String(frac).padStart(2, '0').replace(/0$/, '') : '');
};
export const lifeText = (months) => {
  const m = Number(months) || 0;
  if (m % 12 === 0) return (m / 12) + ' year' + (m === 12 ? '' : 's');
  return m + ' months';
};

export async function mount(root, ctx) {
  const tbody = qs('[data-rows]', root);
  let d = null;

  const acctText = (a) => a && a.code ? '<span class="code">' + esc(a.code) + '</span> ' + esc(a.name) : '';
  const render = () => {
    const items = d.items || [];
    tbody.innerHTML = items.length ? items.map((c) => '<tr class="is-link' + (c.is_active ? '' : ' is-inactive') + '" data-id="' + c.id + '">'
      + '<td><b>' + esc(c.name) + '</b></td>'
      + '<td class="small">' + acctText(c.asset_account) + '</td>'
      + '<td class="small">' + acctText(c.accum_account) + '</td>'
      + '<td class="small">' + acctText(c.expense_account) + '</td>'
      + '<td class="small nowrap">' + esc(c.method_label) + '</td>'
      + '<td class="num">' + esc(lifeText(c.useful_life_months)) + '</td>'
      + '<td class="num">' + esc(bpText(c.residual_bp)) + '%</td>'
      + '<td class="num">' + c.in_use_count + (c.asset_count > c.in_use_count ? ' <span class="faint">(' + (c.asset_count - c.in_use_count) + ' disposed)</span>' : '') + '</td>'
      + '<td>' + statusBadge(c.is_active ? 'active' : 'inactive') + '</td></tr>').join('')
      : '<tr><td colspan="9">' + emptyState('folder', 'No categories yet', d.can_edit ? 'Add one for each kind of asset: office equipment, furniture, vehicles, computers.' : 'An accountant sets these up.') + '</td></tr>';
    qs('[data-actions]', root).innerHTML = d.can_edit
      ? '<button class="btn" type="button" data-new><span data-icon="plus" data-icon-size="18"></span>New category</button>' : '';
    qs('[data-foot]', root).textContent = d.can_edit
      ? 'Open a category to change it. Changing its method, life or residual value affects only assets registered afterwards; its accounts are fixed once it has assets.'
      : 'Open a category to see its assets.';
  };

  const load = async () => {
    try {
      d = await api.get('/asset-categories', null, { signal: ctx.signal });
      render();
    } catch (err) {
      if (!ctx.signal.aborted) tbody.innerHTML = '<tr><td colspan="9">' + emptyState('warning', 'Could not load the categories', err.message) + '</td></tr>';
    }
  };

  // ── the form (accountants) ────────────────────────────────────────────
  const opt = (v, label, sel) => '<option value="' + esc(v) + '"' + (sel ? ' selected' : '') + '>' + esc(label) + '</option>';
  const accountOptions = (list, selected, groupPpe) => {
    const one = (a) => opt(a.id, a.code + ' · ' + a.name, a.id === selected);
    const head = '<option value="">Choose an account</option>';
    if (!groupPpe) return head + list.map(one).join('');
    const ppe = list.filter((a) => a.ppe);
    const rest = list.filter((a) => !a.ppe);
    return head + (ppe.length ? '<optgroup label="Property and equipment">' + ppe.map(one).join('') + '</optgroup>' : '')
      + (rest.length ? '<optgroup label="Other asset accounts">' + rest.map(one).join('') + '</optgroup>' : '');
  };
  const field = (id, label, control, name, hint, wide) => '<div class="field' + (wide ? ' span-2' : '') + '"><label class="label" for="' + id + '">' + label + '</label>' + control
    + (hint ? '<div class="hint">' + hint + '</div>' : '') + '<div class="error" data-error-for="' + name + '"></div></div>';

  const edit = (c) => {
    const isNew = !c;
    const x = c || { id: 0, name: '', asset_account: {}, accum_account: {}, expense_account: {}, method: 'straight_line', useful_life_months: 60, residual_bp: 0, is_active: true, asset_count: 0 };
    const locked = x.asset_count > 0;
    const years = x.useful_life_months % 12 === 0;
    const acc = d.accounts || { asset: [], accum: [], expense: [] };

    const html = '<form class="stack" data-f novalidate><div class="alert alert-err" data-banner hidden></div>'
      + (locked ? '<div class="alert alert-neutral"><span data-icon="lock-key"></span><div>' + x.asset_count + ' asset' + (x.asset_count === 1 ? ' uses' : 's use')
        + ' this category, so its accounts are fixed. For different accounts, create a new category.</div></div>' : '')
      + '<div class="form-grid">'
      + field('ac-name', 'Name', '<input class="input" id="ac-name" name="name" maxlength="120" value="' + esc(x.name) + '" autocomplete="off" placeholder="Office Equipment">', 'name', '', true)
      + field('ac-asset', 'Asset account', '<select class="select" id="ac-asset" name="asset_account_id"' + (locked ? ' disabled' : '') + '>' + accountOptions(acc.asset, x.asset_account.id, true) + '</select>',
        'asset_account_id', 'Where the cost is kept.')
      + field('ac-accum', 'Accumulated depreciation account', '<select class="select" id="ac-accum" name="accum_account_id"' + (locked ? ' disabled' : '') + '>' + accountOptions(acc.accum, x.accum_account.id) + '</select>',
        'accum_account_id', 'A contra-asset account.')
      + field('ac-exp', 'Depreciation expense account', '<select class="select" id="ac-exp" name="expense_account_id"' + (locked ? ' disabled' : '') + '>' + accountOptions(acc.expense, x.expense_account.id) + '</select>',
        'expense_account_id', 'What the monthly depreciation is charged to.')
      + field('ac-method', 'Method', '<select class="select" id="ac-method" name="method">' + Object.keys(d.methods || {}).map((k) => opt(k, d.methods[k], k === x.method)).join('') + '</select>',
        'method', 'Straight line charges the same each month; declining balance charges more in the early years.')
      + field('ac-life', 'Useful life', '<div class="row-nw"><input class="input" id="ac-life" name="life" inputmode="numeric" maxlength="4" style="max-width:110px" value="'
        + esc(years ? x.useful_life_months / 12 : x.useful_life_months) + '"><select class="select" name="life_unit" aria-label="Years or months" style="width:auto">'
        + opt('years', 'years', years) + opt('months', 'months', !years) + '</select></div>', 'useful_life_months', 'Suggested for new assets.')
      + field('ac-res', 'Residual value', '<div class="row-nw"><input class="input" id="ac-res" name="residual" inputmode="decimal" maxlength="6" style="max-width:110px" value="'
        + esc(bpText(x.residual_bp)) + '"><span class="muted">% of cost</span></div>', 'residual_bp', 'What the asset is expected to be worth at the end of its life. 0 is common.')
      + '</div>'
      + (isNew ? '' : '<label class="check"><input type="checkbox" name="is_active"' + (x.is_active ? ' checked' : '') + '> <span>Active — new assets can be registered in it</span></label>')
      + '<div class="row between gap-2"><div class="row gap-2">'
      + (!isNew && !x.asset_count ? '<button class="btn btn-danger-soft" type="button" data-del>Delete</button>' : '')
      + (!isNew && x.asset_count ? '<a class="btn btn-ghost" href="assets?category=' + x.id + '&status=all">View its assets</a>' : '')
      + '</div><div class="row gap-2"><button class="btn btn-secondary" type="button" data-x>Cancel</button>'
      + '<button class="btn" type="submit">' + (isNew ? 'Add category' : 'Save') + '</button></div></div></form>';

    const m = openModal({ title: isNew ? 'New asset category' : x.name, size: 'lg', body: html });
    const f = qs('[data-f]', m.el);
    const el = f.elements;
    qs('[data-x]', f).addEventListener('click', () => m.close());

    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErrors(f);
      const errors = {};
      const n = String(el.life.value).trim();
      let months = /^\d{1,4}$/.test(n) ? Number(n) * (el.life_unit.value === 'years' ? 12 : 1) : 0;
      if (months < 1 || months > 1200) { errors.useful_life_months = 'Enter the useful life: whole years, or months (up to 100 years).'; months = 0; }
      const bp = toBp(el.residual.value || '0');
      if (bp === null) errors.residual_bp = 'Enter a percentage from 0 to 100, for example 5 or 2.5.';
      if (Object.keys(errors).length) { setErrors(f, errors); return; }

      const payload = { name: el.name.value.trim(), method: el.method.value, useful_life_months: months, residual_bp: bp };
      if (!locked) {
        payload.asset_account_id = Number(el.asset_account_id.value) || 0;
        payload.accum_account_id = Number(el.accum_account_id.value) || 0;
        payload.expense_account_id = Number(el.expense_account_id.value) || 0;
      }
      if (el.is_active) payload.is_active = el.is_active.checked;
      const b = qs('button[type="submit"]', f);
      busy(b, true);
      try {
        const r = isNew ? await api.post('/asset-categories', payload) : await api.put('/asset-categories/' + x.id, payload);
        m.close();
        toast(isNew ? 'Category ' + r.category.name + ' added.' : 'Category ' + r.category.name + ' saved.');
        load();
      } catch (err) { formError(f, err); } finally { busy(b, false); }
    });

    const del = qs('[data-del]', f);
    if (del) {
      del.addEventListener('click', async () => {
        if (!(await confirmDialog({ title: 'Delete ' + x.name + '?', body: 'No asset has ever used it, so it can go. This cannot be undone.', confirmLabel: 'Delete', danger: true }))) return;
        try {
          await api.del('/asset-categories/' + x.id);
          m.close();
          toast('Category ' + x.name + ' deleted.');
          load();
        } catch (err) { formError(f, err); }
      });
    }
  };

  root.addEventListener('click', (e) => { if (e.target.closest('[data-new]')) edit(null); });
  tbody.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-id]');
    if (!tr || !d) return;
    const c = d.items.find((x) => x.id === Number(tr.dataset.id));
    if (!c) return;
    if (d.can_edit) edit(c);
    else ctx.navigate('/assets?category=' + c.id + '&status=all');
  });

  await load();
}
