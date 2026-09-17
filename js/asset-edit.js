/**
 * asset-edit.js — register a fixed asset, or change one
 *
 * GenericPOS Accounting · ES module · bookkeepers and above
 *
 *   /assets/new          a new asset (?category=ID preselects a category)
 *   /assets/{id}/edit    an asset in the register
 *
 * Choosing a category fills in its method, useful life and residual value
 * (a percentage of the cost) until you type your own; the acquisition date
 * sets the first month depreciated by the company's rule (Settings). Amounts
 * are typed in pesos and sent as integer centavos (store.toCents — exact).
 *
 * Once depreciation has been charged the figures it used are fixed, and the
 * form shows them locked with the reason; a disposed asset keeps its
 * department too. The server checks everything again.
 */

import { api } from './api.js';
import { money, toCents, toMajor, fmtDay, store } from './store.js';
import { qs, esc, emptyState, busy, toast, formError, clearErrors, setErrors } from './ui.js';

const YMD = /^\d{4}-\d{2}-\d{2}$/;

/** round(cents × basis points ÷ 10,000), half up, without floats. */
const bpOf = (cents, bp) => Number((BigInt(cents) * BigInt(bp) * 2n + 10000n) / 20000n);
/** round(a ÷ b), half up, for display (a, b ≥ 0). */
const divRound = (a, b) => Number((BigInt(a) * 2n + BigInt(b)) / (BigInt(b) * 2n));
const firstOf = (ymd) => ymd.slice(0, 8) + '01';
const nextMonth = (ymd) => new Date(Date.UTC(+ymd.slice(0, 4), +ymd.slice(5, 7), 1)).toISOString().slice(0, 10);
const monthName = (ymd) => {
  try {
    return new Intl.DateTimeFormat(store().currency.locale || undefined, { month: 'long', year: 'numeric', timeZone: 'UTC' })
      .format(new Date(Date.UTC(+ymd.slice(0, 4), +ymd.slice(5, 7) - 1, 1)));
  } catch { return ymd.slice(0, 7); }
};
const lifeText = (m) => (m % 12 === 0 ? (m / 12) + ' year' + (m === 12 ? '' : 's') : m + ' months');
const pctText = (bp) => (bp % 100 ? (bp / 100).toFixed(2).replace(/0$/, '') : String(bp / 100));

export async function mount(root, ctx) {
  const id = ctx.params.id ? parseInt(ctx.params.id, 10) || 0 : 0;
  const stateEl = qs('[data-state]', root);
  const form = qs('[data-form]', root);
  const f = form.elements;

  let lk;
  let d = null;
  try {
    [lk, d] = await Promise.all([
      api.get('/assets/lookups', null, { signal: ctx.signal }),
      id ? api.get('/assets/' + id, null, { signal: ctx.signal }) : Promise.resolve(null),
    ]);
  } catch (err) {
    if (ctx.signal.aborted) return;
    stateEl.innerHTML = emptyState('warning', 'Could not open the asset form', err.message, '<a class="btn btn-secondary" href="assets">Back to the register</a>');
    return;
  }
  if (id && !d.can.edit) {
    stateEl.innerHTML = emptyState('lock-key', 'This asset cannot be changed here', 'Bookkeepers and above change assets.',
      '<a class="btn btn-secondary" href="assets/' + id + '">Back to the asset</a>');
    return;
  }

  const a = d ? d.asset : null;
  const frozen = new Set(d && d.frozen ? d.frozen.fields : []);
  const catById = new Map(lk.categories.map((c) => [c.id, c]));
  const opt = (v, label, sel) => '<option value="' + esc(v) + '"' + (sel ? ' selected' : '') + '>' + esc(label) + '</option>';

  // ── the pickers ───────────────────────────────────────────────────────
  const cats = lk.categories.filter((c) => c.is_active || (a && c.id === a.category_id));
  f.category_id.innerHTML = opt('', 'Choose a category') + cats.map((c) => opt(c.id, c.name + (c.is_active ? '' : ' (inactive)'))).join('');
  const depts = lk.departments.slice();
  if (a && a.department_id && !depts.some((x) => x.id === a.department_id)) depts.push({ id: a.department_id, code: a.department_code || '#' + a.department_id, name: '(inactive)' });
  f.department_id.innerHTML = opt('', '—') + depts.map((x) => opt(x.id, x.code + ' · ' + x.name)).join('');
  const sups = lk.suppliers.slice();
  if (a && a.supplier_id && !sups.some((x) => x.id === a.supplier_id)) sups.push({ id: a.supplier_id, name: (a.supplier_name || '#' + a.supplier_id) + ' (inactive)' });
  f.supplier_id.innerHTML = opt('', '—') + sups.map((x) => opt(x.id, x.name)).join('');
  f.method.innerHTML = Object.keys(lk.methods).map((k) => opt(k, lk.methods[k])).join('');
  qs('[data-start-hint]', root).textContent = 'The first month depreciated. The company\'s rule: '
    + (lk.start_mode === 'same_month' ? 'the month the asset is acquired.' : 'the month after the asset is acquired.') + ' Change it only for a good reason.';

  // What the person typed themselves is never replaced by a category's suggestion.
  const touched = new Set();
  const setLife = (months) => {
    if (months % 12 === 0) { f.life.value = String(months / 12); f.life_unit.value = 'years'; }
    else { f.life.value = String(months); f.life_unit.value = 'months'; }
  };
  const lifeMonths = () => {
    const n = String(f.life.value).trim();
    if (!/^\d{1,4}$/.test(n)) return null;
    const m = Number(n) * (f.life_unit.value === 'years' ? 12 : 1);
    return m >= 1 && m <= 1200 ? m : null;
  };

  // ── the purchase entries a category's asset account can link to ────────
  let ajSeq = 0;
  const loadJournals = async () => {
    const cid = Number(f.category_id.value) || 0;
    const my = ++ajSeq;
    const keep = f.acquisition_journal_id.value || (a && a.acquisition_journal_id ? String(a.acquisition_journal_id) : '');
    if (!cid) { f.acquisition_journal_id.innerHTML = opt('', 'Choose a category first'); return; }
    try {
      const r = await api.get('/assets/acquisition-journals', { category: cid }, { signal: ctx.signal });
      if (my !== ajSeq) return;
      const items = r.items || [];
      if (keep && !items.some((j) => String(j.id) === keep) && d && d.acquisition_journal) {
        const j = d.acquisition_journal;
        items.unshift({ id: j.id, journal_no: j.journal_no, entry_date: j.entry_date, description: j.description, debit_cents: null, linked: [] });
      }
      f.acquisition_journal_id.innerHTML = opt('', items.length ? '— none —' : 'No posted entry debits ' + r.account.code + ' yet')
        + items.map((j) => opt(j.id, [j.journal_no, fmtDay(j.entry_date), j.description, j.debit_cents !== null ? money(j.debit_cents) : '',
          (j.linked || []).filter((n) => !a || n !== a.asset_no).length ? 'linked to ' + j.linked.filter((n) => !a || n !== a.asset_no).join(', ') : ''].filter(Boolean).join(' · '))).join('');
      f.acquisition_journal_id.value = items.some((j) => String(j.id) === keep) ? keep : '';
      qs('[data-aj-hint]', root).textContent = 'The posted bill or journal entry that recorded the purchase: entries that debit ' + r.account.code + ' ' + r.account.name + '.';
    } catch { if (my === ajSeq) f.acquisition_journal_id.innerHTML = opt('', '— none —'); }
  };

  // ── suggestions ───────────────────────────────────────────────────────
  const applyCategory = () => {
    const c = catById.get(Number(f.category_id.value));
    qs('[data-cat-hint]', root).textContent = c ? 'Posts to ' + c.asset_account.code + ' ' + c.asset_account.name + '.' : '';
    if (!c) return;
    if (!touched.has('method') && !frozen.has('method')) f.method.value = c.method;
    if (!touched.has('life') && !frozen.has('useful_life_months')) setLife(c.useful_life_months);
    applyResidual();
  };
  const applyResidual = () => {
    const c = catById.get(Number(f.category_id.value));
    qs('[data-res-hint]', root).textContent = c && c.residual_bp ? c.name + ' suggests ' + pctText(c.residual_bp) + '% of cost.' : 'What it should be worth at the end of its life. 0 is common.';
    if (!c || touched.has('residual') || frozen.has('residual_cents')) return;
    const cost = toCents(f.cost_cents.value);
    f.residual_cents.value = cost ? toMajor(bpOf(cost, c.residual_bp)) : (c.residual_bp ? '' : '0.00');
  };
  const applyStart = () => {
    if (touched.has('start') || frozen.has('depreciation_start')) return;
    const acq = f.acquired_on.value;
    if (YMD.test(acq)) f.depreciation_start.value = lk.start_mode === 'same_month' ? firstOf(acq) : nextMonth(acq);
  };

  const preview = () => {
    const box = qs('[data-preview]', root);
    const cost = toCents(f.cost_cents.value);
    const res = f.residual_cents.value.trim() === '' ? 0 : toCents(f.residual_cents.value);
    const open = f.opening_accum_cents.value.trim() === '' ? 0 : toCents(f.opening_accum_cents.value);
    const life = lifeMonths();
    const start = f.depreciation_start.value;
    if (!cost || res === null || open === null || !life || !YMD.test(start)) { box.hidden = true; return; }
    const base = cost - res;
    let t;
    if (base <= 0) t = 'Nothing to depreciate: the residual value is the whole cost (land, for example).';
    else if (f.method.value === 'declining_balance') {
      t = 'Declining balance: about ' + money(divRound(Math.max(0, cost - open) * 2, life)) + ' in ' + monthName(start)
        + ', less each month after, switching to straight line near the end, finishing on the residual value of ' + money(res) + ' after ' + lifeText(life) + '.';
    } else {
      t = 'Straight line: about ' + money(divRound(base, life)) + ' a month for ' + lifeText(life) + ' from ' + monthName(start)
        + '. The months add up to exactly ' + money(base) + '.';
    }
    if (base > 0 && open > 0) t += ' ' + money(open) + ' was charged before go-live, so ' + money(Math.max(0, base - open)) + ' is left to charge.';
    box.textContent = t;
    box.hidden = false;
  };

  // ── filling the form ──────────────────────────────────────────────────
  if (a) {
    const t = 'Edit ' + a.asset_no;
    qs('[data-title]', root).textContent = t;
    qs('[data-crumb]', root).textContent = a.asset_no;
    qs('[data-sub]', root).textContent = a.name;
    ctx.setTitle(t);
    qs('a[data-cancel]', root).setAttribute('href', 'assets/' + a.id);
    qs('[data-save]', root).textContent = 'Save changes';
    f.name.value = a.name;
    f.category_id.value = String(a.category_id);
    f.acquired_on.value = a.acquired_on;
    f.department_id.value = a.department_id ? String(a.department_id) : '';
    f.location.value = a.location || '';
    f.serial_no.value = a.serial_no || '';
    f.supplier_id.value = a.supplier_id ? String(a.supplier_id) : '';
    f.notes.value = a.notes || '';
    f.cost_cents.value = toMajor(a.cost_cents);
    f.residual_cents.value = toMajor(a.residual_cents);
    setLife(a.useful_life_months);
    f.method.value = a.method;
    f.depreciation_start.value = a.depreciation_start;
    f.opening_accum_cents.value = toMajor(a.opening_accum_cents);
    ['method', 'life', 'residual', 'start'].forEach((k) => touched.add(k));
    qs('[data-cat-hint]', root).textContent = d.category ? 'Posts to ' + d.category.asset_account.code + ' ' + d.category.asset_account.name + '.' : '';
    if (d.frozen) {
      const b = qs('[data-frozen]', root);
      b.innerHTML = '<span data-icon="lock-key"></span><div>' + esc(d.frozen.reason) + '</div>';
      b.hidden = false;
    }
    const ctl = { category_id: f.category_id, cost_cents: f.cost_cents, residual_cents: f.residual_cents, useful_life_months: [f.life, f.life_unit],
      method: f.method, depreciation_start: f.depreciation_start, opening_accum_cents: f.opening_accum_cents, department_id: f.department_id };
    frozen.forEach((k) => [].concat(ctl[k] || []).forEach((el) => { el.disabled = true; }));
  } else {
    f.acquired_on.value = lk.today;
    f.acquired_on.max = lk.today;
    f.opening_accum_cents.value = '0.00';
    const pre = parseInt(ctx.query.get('category') || '0', 10);
    if (pre && catById.has(pre) && catById.get(pre).is_active) f.category_id.value = String(pre);
    applyCategory();
    applyStart();
  }
  loadJournals();

  // ── wiring ────────────────────────────────────────────────────────────
  const dirty = () => form.setAttribute('data-dirty', '1');
  f.category_id.addEventListener('change', () => { applyCategory(); loadJournals(); preview(); });
  f.acquired_on.addEventListener('change', () => { applyStart(); preview(); });
  f.method.addEventListener('change', () => { touched.add('method'); preview(); });
  f.life.addEventListener('input', () => { touched.add('life'); preview(); });
  f.life_unit.addEventListener('change', () => { touched.add('life'); preview(); });
  f.residual_cents.addEventListener('input', () => { touched.add('residual'); preview(); });
  f.cost_cents.addEventListener('input', () => { applyResidual(); preview(); });
  f.opening_accum_cents.addEventListener('input', preview);
  f.depreciation_start.addEventListener('change', () => {
    touched.add('start');
    if (YMD.test(f.depreciation_start.value)) f.depreciation_start.value = firstOf(f.depreciation_start.value);
    preview();
  });
  form.querySelectorAll('[inputmode="decimal"]').forEach((el) => el.addEventListener('focusout', () => {
    const raw = el.value.trim();
    const c = raw === '' ? null : toCents(raw);
    if (c !== null) el.value = toMajor(c);
    el.classList.toggle('is-invalid', raw !== '' && c === null);
  }));
  form.addEventListener('input', dirty);

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearErrors(form);
    const errors = {};
    const cents = (el, name, blankOk, msg) => {
      const raw = el.value.trim();
      if (raw === '' && blankOk) return 0;
      const c = toCents(raw);
      if (c === null) errors[name] = msg;
      return c;
    };
    if (!f.name.value.trim()) errors.name = 'Describe the asset.';
    if (!f.category_id.value) errors.category_id = 'Choose a category.';
    if (!YMD.test(f.acquired_on.value)) errors.acquired_on = 'Enter the date the asset was acquired.';
    const cost = cents(f.cost_cents, 'cost_cents', false, 'Enter what the asset cost, for example 85000 or 85,000.00.');
    if (cost === 0) errors.cost_cents = 'Enter what the asset cost.';
    const res = cents(f.residual_cents, 'residual_cents', true, 'Enter the residual value in pesos, or 0.');
    const open = cents(f.opening_accum_cents, 'opening_accum_cents', true, 'Enter the accumulated depreciation brought in, or 0.');
    const life = lifeMonths();
    if (!life) errors.useful_life_months = 'Enter the useful life: whole years, or months (up to 100 years).';
    if (!YMD.test(f.depreciation_start.value)) errors.depreciation_start = 'Enter the first month depreciated.';
    if (Object.keys(errors).length) { setErrors(form, errors); return; }

    const body = {
      name: f.name.value.trim(), category_id: Number(f.category_id.value), acquired_on: f.acquired_on.value,
      depreciation_start: firstOf(f.depreciation_start.value), cost_cents: cost, residual_cents: res, useful_life_months: life,
      method: f.method.value, opening_accum_cents: open, department_id: Number(f.department_id.value) || null,
      location: f.location.value.trim(), serial_no: f.serial_no.value.trim(), supplier_id: Number(f.supplier_id.value) || null,
      acquisition_journal_id: Number(f.acquisition_journal_id.value) || null, notes: f.notes.value.trim(),
    };
    frozen.forEach((k) => { delete body[k]; });
    const btn = qs('[data-save]', root);
    busy(btn, true);
    try {
      const r = id ? await api.put('/assets/' + id, body) : await api.post('/assets', body);
      form.removeAttribute('data-dirty');
      toast(id ? 'Asset ' + r.asset.asset_no + ' saved.' : 'Asset ' + r.asset.asset_no + ' registered.');
      ctx.navigate('/assets/' + r.asset.id, { replace: !!id });
    } catch (err) {
      formError(form, err);
    } finally {
      busy(btn, false);
    }
  });

  stateEl.innerHTML = '';
  form.hidden = false;
  preview();
  if (!id) setTimeout(() => f.name.focus(), 40);
  return () => { form.removeAttribute('data-dirty'); };
}
