/**
 * budgets.js — the budgets of each fiscal year
 *
 * GenericPOS Accounting · ES module · every role reads them; accountants prepare and approve them
 *
 * Each budget with its status, whether it is the year's primary budget (the
 * one reports and the dashboard use), and its totals on the income
 * statement's terms. An accountant starts a new one here — blank, as a copy
 * of another budget, or from last year's actuals, optionally changed by a
 * percentage — and fills it in on its own page.
 */

import { api } from './api.js';
import { money, fmtDate, store } from './store.js';
import { qs, esc, emptyState, statusBadge, openModal, busy, formError, clearErrors, toast } from './ui.js';

export async function mount(root, ctx) {
  const coop = store().entity_type === 'cooperative';
  const cur = store().currency || {};
  const whole = cur.code && cur.code !== 'PHP' ? 'whole ' + cur.code : 'whole pesos';
  const tbody = qs('[data-rows]', root);
  const f = qs('[data-filters]', root).elements;
  let d = null;
  let fy = ctx.query.get('fy') || '';

  qs('[data-net-head]', root).textContent = coop ? 'Net surplus' : 'Net income';
  qs('[data-foot]', root).textContent = 'Income and expenses are totals on the income statement\'s terms: a budget on a contra account, such as sales returns, counts against its section. '
    + 'The primary budget of a year is the one budget vs actual and the dashboard use unless you choose another.';

  const row = (b) => '<tr class="is-link" data-id="' + b.id + '">'
    + '<td><b>' + esc(b.name) + '</b>' + (b.is_primary ? ' <span class="badge badge-brand">Primary</span>' : '')
    + '<div class="small muted truncate" style="max-width:420px">' + esc(b.fiscal_year.name + (b.notes ? ' · ' + b.notes : '')) + '</div></td>'
    + '<td>' + statusBadge(b.status) + '</td>'
    + '<td class="num">' + money(b.totals.income_cents) + '</td>'
    + '<td class="num">' + money(b.totals.expense_cents) + '</td>'
    + '<td class="num' + (b.totals.net_cents < 0 ? ' err-text' : '') + '">' + money(b.totals.net_cents) + '</td>'
    + '<td class="small nowrap">' + esc(fmtDate(b.updated_at, 'date')) + (b.created_by_name ? '<div class="muted">by ' + esc(b.created_by_name) + '</div>' : '') + '</td></tr>';

  const render = () => {
    const items = d.items.filter((b) => !fy || String(b.fiscal_year_id) === fy);
    const p = new URLSearchParams();
    if (fy) p.set('fy', fy);
    history.replaceState(history.state, '', location.pathname + (p.toString() ? '?' + p.toString() : ''));
    qs('[data-count]', root).textContent = items.length + ' budget' + (items.length === 1 ? '' : 's');
    tbody.innerHTML = items.length ? items.map(row).join('') : '<tr><td colspan="6">'
      + emptyState('sliders', 'No budgets ' + (fy ? 'for this fiscal year' : 'yet'), d.can.create ? 'Start one with New budget: blank, as a copy, or from last year\'s actuals.' : 'An accountant prepares them.') + '</td></tr>';
  };

  const load = async () => {
    try {
      d = await api.get('/budgets', null, { signal: ctx.signal });
    } catch (err) {
      if (!ctx.signal.aborted) tbody.innerHTML = '<tr><td colspan="6">' + emptyState('warning', 'Could not load the budgets', err.message) + '</td></tr>';
      return;
    }
    const now = d.fiscal_years.find((y) => y.current);
    if (!fy && !ctx.query.has('fy') && now && d.items.some((b) => b.fiscal_year_id === now.id)) fy = String(now.id);
    f.fy.innerHTML = '<option value="">Every fiscal year</option>' + d.fiscal_years.map((y) => '<option value="' + y.id + '">' + esc(y.name + (y.current ? ' (this year)' : '')) + '</option>').join('');
    f.fy.value = fy;
    if (f.fy.value !== fy) fy = f.fy.value;
    qs('[data-actions]', root).innerHTML = '<a class="btn btn-secondary" href="reports/budget-vs-actual"><span data-icon="scales" data-icon-size="18"></span>Budget vs actual</a>'
      + (d.can.create ? '<button class="btn" type="button" data-new><span data-icon="plus" data-icon-size="18"></span>New budget</button>' : '');
    render();
  };

  // ── a new budget (accountants) ────────────────────────────────────────
  const create = () => {
    const years = d.fiscal_years;
    if (!years.length) { toast('Open a fiscal year first (Fiscal years and periods).', { kind: 'error' }); return; }
    const pick = fy || String((years.find((y) => y.current) || years[0]).id);
    const radio = (v, label, hint, on) => '<label class="check"><input type="radio" name="source" value="' + v + '"' + (on ? ' checked' : '') + '> <span><b>' + esc(label) + '</b>'
      + '<span class="hint" style="display:block" data-hint-for="' + v + '">' + esc(hint) + '</span></span></label>';
    const html = '<form class="stack" data-f novalidate><div class="alert alert-err" data-banner hidden></div><div class="form-grid">'
      + '<div class="field"><label class="label" for="nb-fy">Fiscal year</label><select class="select" id="nb-fy" name="fiscal_year_id">'
      + years.map((y) => '<option value="' + y.id + '"' + (String(y.id) === pick ? ' selected' : '') + '>' + esc(y.name) + '</option>').join('') + '</select><div class="error" data-error-for="fiscal_year_id"></div></div>'
      + '<div class="field"><label class="label" for="nb-name">Name</label><input class="input" id="nb-name" name="name" maxlength="80" placeholder="Original budget" autocomplete="off"><div class="error" data-error-for="name"></div></div>'
      + '<div class="field span-2"><label class="label" for="nb-notes">Notes <span class="opt">(optional)</span></label><textarea class="textarea" id="nb-notes" name="notes" maxlength="500" style="min-height:64px" placeholder="Approved by the board on …"></textarea><div class="error" data-error-for="notes"></div></div>'
      + '</div>'
      + '<fieldset class="stack gap-2" style="border:0;padding:0;margin:0"><legend class="label">Start from</legend>'
      + radio('blank', 'A blank budget', 'Every amount starts at zero.', true)
      + radio('copy', 'A copy of another budget', 'Every amount of the budget you choose, for the same accounts, departments and months.')
      + '<div class="field" data-copy hidden style="margin-left:26px"><select class="select" name="copy_budget_id" aria-label="Budget to copy">'
      + d.items.map((b) => '<option value="' + b.id + '">' + esc(b.fiscal_year.name + ' · ' + b.name + (b.status === 'draft' ? ' (draft)' : '')) + '</option>').join('')
      + '</select><div class="error" data-error-for="copy_budget_id"></div></div>'
      + radio('actuals', 'Last year\'s actuals', 'What each account earned or cost in the fiscal year before, by department and month, rounded to ' + whole + '. Closing entries are left out.')
      + '<div class="error" data-error-for="source"></div></fieldset>'
      + '<div class="field" data-uplift hidden><label class="label" for="nb-up">Change every amount by <span class="opt">(optional)</span></label>'
      + '<div class="row gap-2" style="align-items:center"><input class="input" id="nb-up" name="uplift_pct" inputmode="decimal" placeholder="0" style="max-width:120px" autocomplete="off"><span>%</span></div>'
      + '<div class="hint">5 for five per cent more, -2.5 for less. Changed amounts are rounded to ' + whole + '.</div><div class="error" data-error-for="uplift_pct"></div></div>'
      + '<div class="row gap-2" style="justify-content:flex-end"><button class="btn btn-secondary" type="button" data-x>Cancel</button><button class="btn" type="submit">Create budget</button></div></form>';

    const m = openModal({ title: 'New budget', body: html });
    const fm = qs('[data-f]', m.el);
    const el = fm.elements;
    const actuals = qs('input[value="actuals"]', fm);
    const copyOpt = qs('input[value="copy"]', fm);
    const hintA = qs('[data-hint-for="actuals"]', fm);
    const hintText = hintA.textContent;
    if (!d.items.length) { copyOpt.disabled = true; qs('[data-hint-for="copy"]', fm).textContent = 'There is no budget to copy yet.'; }

    const sync = () => {
      const y = years.find((x) => String(x.id) === el.fiscal_year_id.value);
      actuals.disabled = !(y && y.has_previous);
      hintA.textContent = actuals.disabled ? 'There is no fiscal year before ' + (y ? y.name : 'this one') + ', so there are no actuals to start from.' : hintText;
      if (actuals.disabled && actuals.checked) qs('input[value="blank"]', fm).checked = true;
      const src = (qs('input[name="source"]:checked', fm) || {}).value;
      qs('[data-copy]', fm).hidden = src !== 'copy';
      qs('[data-uplift]', fm).hidden = src === 'blank';
    };
    sync();
    fm.addEventListener('change', sync);
    qs('[data-x]', fm).addEventListener('click', () => m.close());

    fm.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErrors(fm);
      const source = (qs('input[name="source"]:checked', fm) || {}).value || 'blank';
      const b = qs('button[type="submit"]', fm);
      busy(b, true);
      try {
        const r = await api.post('/budgets', {
          fiscal_year_id: Number(el.fiscal_year_id.value), name: el.name.value.trim(), notes: el.notes.value.trim(), source,
          copy_budget_id: source === 'copy' ? Number(el.copy_budget_id.value) : null, uplift_pct: source === 'blank' ? '' : el.uplift_pct.value.trim(),
        });
        m.close();
        toast('Budget "' + r.budget.name + '" created. ' + ((r.report && r.report.text) || ''));
        ctx.navigate('/budgets/' + r.budget.id);
      } catch (err) { formError(fm, err); } finally { busy(b, false); }
    });
  };

  f.fy.addEventListener('change', () => { fy = f.fy.value; if (d) render(); });
  qs('[data-filters]', root).addEventListener('submit', (e) => e.preventDefault());
  root.addEventListener('click', (e) => { if (e.target.closest('[data-new]') && d) create(); });
  tbody.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-id]');
    if (tr) ctx.navigate('/budgets/' + tr.dataset.id);
  });

  await load();
}
