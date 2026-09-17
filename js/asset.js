/**
 * asset.js — one fixed asset: its figures, its schedule, its entries and what can be done with it
 *
 * GenericPOS Accounting · ES module · every role (the actions depend on the role)
 *
 * The schedule shows the months already charged (each linked to its run's
 * journal entry) and the months still to come, worked out the way the next
 * depreciation run will work them out. The server says which actions are
 * open to this person (`can`):
 *   Edit, Delete (nothing posted yet) — bookkeepers and above
 *   Dispose of, Take the disposal back — accountants and above
 * A disposal posts one journal entry at once; the dialog shows it first.
 */

import { api } from './api.js';
import { money, fmtDay, fmtDate, toCents, toMajor, store } from './store.js';
import { attachmentsPanel } from './attachments.js';
import { qs, esc, emptyState, statusBadge, confirmDialog, promptDialog, openModal, toast, busy, formError, clearErrors, setErrors } from './ui.js';
import { setAdminTitle } from './chrome.js';

const ACTIONS = {
  'asset.create': 'Registered',
  'asset.update': 'Changed',
  'asset.dispose': 'Disposed of',
  'asset.undo_disposal': 'Disposal taken back',
};
const FIELD_NAMES = {
  name: 'description', category_id: 'category', acquired_on: 'date acquired', depreciation_start: 'depreciation start', cost_cents: 'cost',
  residual_cents: 'residual value', useful_life_months: 'useful life', method: 'method', opening_accum_cents: 'accumulated depreciation brought in',
  department_id: 'department', location: 'location', serial_no: 'serial no.', supplier_id: 'supplier', acquisition_journal_id: 'purchase entry', notes: 'notes',
};
const lifeText = (m) => (m % 12 === 0 ? (m / 12) + ' year' + (m === 12 ? '' : 's') : m + ' months');
const monthLabel = (ymd) => {
  try {
    return new Intl.DateTimeFormat(store().currency.locale || undefined, { month: 'short', year: 'numeric', timeZone: 'UTC' })
      .format(new Date(Date.UTC(+ymd.slice(0, 4), +ymd.slice(5, 7) - 1, 1)));
  } catch { return ymd.slice(0, 7); }
};
const monthNo = (ymd) => +ymd.slice(0, 4) * 12 + +ymd.slice(5, 7) - 1;

export async function mount(root, ctx) {
  const id = parseInt(ctx.params.id, 10) || 0;
  const stateEl = qs('[data-state]', root);
  const body = qs('[data-body]', root);
  const actions = qs('[data-actions]', root);
  let d = null;
  let view = 'years';

  const banner = (kind, html) => {
    const b = qs('[data-banner]', root);
    if (!html) { b.hidden = true; return; }
    b.className = 'alert alert-' + kind;
    b.innerHTML = html;
    b.hidden = false;
  };
  const jlink = (j) => (j && j.id ? '<a href="journals/' + j.id + '"><span class="mono">' + esc(j.journal_no || '#' + j.id) + '</span></a>' : '');

  const paint = () => {
    const a = d.asset;
    const c = d.category || {};
    const title = a.asset_no;
    qs('[data-title]', root).innerHTML = '<span>' + esc(a.asset_no + ' · ' + a.name) + '</span>' + statusBadge(a.status);
    qs('[data-crumb]', root).textContent = a.asset_no;
    ctx.setTitle(title + ' · ' + a.name);
    setAdminTitle(title);
    qs('[data-sub]', root).textContent = [a.category_name, a.department, a.location].filter(Boolean).join(' · ');

    // ── where it stands ─────────────────────────────────────────────────
    if (a.status === 'disposed' && d.disposal) {
      const x = d.disposal;
      const result = x.gain_cents ? 'a gain of ' + money(x.gain_cents) : x.loss_cents ? 'a loss of ' + money(x.loss_cents) : 'no gain or loss';
      banner('neutral', '<span data-icon="archive"></span><div>Disposed of on <b>' + esc(fmtDay(x.disposed_on, 'long')) + '</b>'
        + (x.proceeds_cents ? ' for ' + money(x.proceeds_cents) : ' with no proceeds') + ': book value ' + money(x.nbv_cents) + ', ' + result + '.'
        + (x.journal ? ' Posted as ' + jlink(x.journal) + '.' : '') + ' It stays in the register for the record; nothing more is depreciated.</div>');
    } else if (a.status === 'fully_depreciated') {
      banner('ok', '<span data-icon="check-circle"></span><div>Fully depreciated: its book value is its residual value, ' + money(a.residual_cents)
        + '. It stays in the register until it is disposed of.</div>');
    } else if (a.base_cents === 0) {
      banner('neutral', '<span data-icon="info"></span><div>Nothing to depreciate: its residual value is its whole cost.</div>');
    } else {
      banner('', '');
    }

    // ── the figures ─────────────────────────────────────────────────────
    const left = a.status === 'active' && d.schedule.projected.length;
    const stat = (label, value, sub) => '<div class="stat"><div class="stat-label">' + esc(label) + '</div><div class="stat-value">' + value + '</div>'
      + (sub ? '<div class="stat-sub">' + sub + '</div>' : '') + '</div>';
    qs('[data-stats]', root).innerHTML =
        stat('Cost', money(a.cost_cents), a.residual_cents ? 'Residual value ' + money(a.residual_cents) : 'No residual value')
      + stat('Accumulated depreciation', money(a.accumulated_cents), a.opening_accum_cents ? esc(money(a.opening_accum_cents)) + ' brought in at go-live' : a.months_charged + ' month' + (a.months_charged === 1 ? '' : 's') + ' charged')
      + stat('Net book value', money(a.nbv_cents), a.status === 'disposed' ? 'When it was disposed of' : '')
      + stat(a.status === 'active' ? 'Left to depreciate' : 'Depreciation', a.status === 'active' ? money(Math.max(0, a.base_cents - a.accumulated_cents)) : (a.status === 'disposed' ? 'Stopped' : 'Complete'),
        left ? left + ' month' + (left === 1 ? '' : 's') + ', to ' + esc(monthLabel(d.schedule.ends)) : '');

    const fact = (k, v, wide) => '<div' + (wide ? ' class="wide"' : '') + '><div class="k">' + esc(k) + '</div><div class="v">' + (v || '<span class="faint">—</span>') + '</div></div>';
    const acct = (x) => (x && x.code ? '<span class="mono">' + esc(x.code) + '</span> ' + esc(x.name) : '');
    qs('[data-head]', root).innerHTML =
        fact('Number', '<span class="mono">' + esc(a.asset_no) + '</span>')
      + fact('Category', esc(a.category_name || ''))
      + fact('Acquired', esc(fmtDay(a.acquired_on, 'long')))
      + fact('Depreciation starts', esc(monthLabel(a.depreciation_start)))
      + fact('Method', esc(a.method_label))
      + fact('Useful life', esc(lifeText(a.useful_life_months)))
      + fact('Department', esc(a.department || ''))
      + fact('Location', esc(a.location || ''))
      + fact('Serial or plate no.', esc(a.serial_no || ''))
      + fact('Supplier', esc(a.supplier_name || ''))
      + fact('Purchase entry', d.acquisition_journal ? jlink(d.acquisition_journal) + ' <span class="small muted">' + esc(fmtDay(d.acquisition_journal.entry_date)) + '</span>' : '')
      + fact('Asset account', acct(c.asset_account))
      + fact('Accumulated depreciation account', acct(c.accum_account))
      + fact('Depreciation charged to', acct(c.expense_account))
      + (a.notes ? fact('Notes', esc(a.notes), true) : '');

    paintSchedule();

    // ── the history ─────────────────────────────────────────────────────
    qs('[data-trail]', root).innerHTML = d.trail.length ? d.trail.map((t) => {
      const x = t.detail || {};
      const changed = x.changed ? Object.keys(x.changed).map((k) => FIELD_NAMES[k] || k) : [];
      return '<li class="is-done"><div class="tl-title">' + esc(ACTIONS[t.action] || t.action)
        + (x.journal_no && t.action === 'asset.dispose' ? ' (' + esc(x.journal_no) + ')' : '') + (x.reversal_no ? ' (' + esc(x.reversal_no) + ')' : '') + '</div>'
        + '<div class="tl-time">' + esc(t.who) + ' · ' + esc(fmtDate(t.at)) + '</div>'
        + (changed.length ? '<div class="small">' + esc(changed.join(', ')) + '</div>' : '')
        + (x.reason ? '<div class="small">“' + esc(x.reason) + '”</div>' : '') + '</li>';
    }).join('') : '<li class="muted">Nothing recorded.</li>';

    // ── what this person may do ─────────────────────────────────────────
    const can = d.can;
    const btn = (act, label, icon, cls) => '<button class="btn ' + cls + '" type="button" data-do="' + act + '"><span data-icon="' + icon + '" data-icon-size="18"></span>' + label + '</button>';
    actions.innerHTML = [
      can.delete ? btn('delete', 'Delete', 'trash', 'btn-ghost') : '',
      can.edit ? '<a class="btn btn-secondary" href="assets/' + a.id + '/edit"><span data-icon="pencil-simple" data-icon-size="18"></span>Edit</a>' : '',
      can.undo_disposal ? btn('undo', 'Take the disposal back', 'arrow-counter-clockwise', 'btn-secondary') : '',
      can.dispose ? btn('dispose', 'Dispose of', 'archive', '') : '',
    ].join('');
  };

  const paintSchedule = () => {
    const a = d.asset;
    const s = d.schedule;
    const tbl = qs('[data-schedule]', root);
    qs('[data-view]', root).querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.v === view)));
    const opening = a.opening_accum_cents
      ? '<tr><td>Before these books</td><td class="small muted">Brought in at go-live</td><td class="num">' + money(a.opening_accum_cents) + '</td><td class="num">'
        + money(a.opening_accum_cents) + '</td><td class="num">' + money(a.cost_cents - a.opening_accum_cents) + '</td></tr>' : '';

    if (!s.entries.length && !s.projected.length) {
      tbl.innerHTML = '<tbody><tr><td>' + emptyState('clock-counter-clockwise', 'No depreciation', a.status === 'disposed' ? 'It was disposed of before any depreciation was charged.'
        : a.base_cents === 0 ? 'Its residual value is its whole cost.' : 'Nothing is left to charge.') + '</td></tr></tbody>';
    } else if (view === 'years') {
      tbl.innerHTML = '<thead><tr><th>Fiscal year</th><th>Months</th><th class="num">Depreciation</th><th class="num">Accumulated at the end</th><th class="num">Book value at the end</th></tr></thead><tbody>'
        + opening + s.years.map((y) => '<tr' + (y.projected ? ' class="muted"' : '') + '><td>' + esc(y.label) + (y.projected ? ' <span class="badge">Projected</span>' : '') + '</td>'
          + '<td class="small">' + y.months + '</td><td class="num">' + money(y.depreciation_cents) + '</td><td class="num">' + money(y.accum_end_cents)
          + '</td><td class="num">' + money(y.nbv_end_cents) + '</td></tr>').join('') + '</tbody>';
    } else {
      const rows = s.entries.map((e) => '<tr><td>' + esc(monthLabel(e.month)) + '</td><td>' + (e.journal_id ? jlink({ id: e.journal_id, journal_no: e.journal_no }) : '') + '</td>'
          + '<td class="num">' + money(e.amount_cents) + '</td><td class="num">' + money(e.accum_after_cents) + '</td><td class="num">' + money(e.nbv_after_cents) + '</td></tr>')
        .concat(s.projected.map((p) => '<tr class="muted"><td>' + esc(monthLabel(p.month)) + '</td><td><span class="badge">Projected</span></td>'
          + '<td class="num">' + money(p.amount_cents) + '</td><td class="num">' + money(p.accum_after_cents) + '</td><td class="num">' + money(p.nbv_after_cents) + '</td></tr>'));
      tbl.innerHTML = '<thead><tr><th>Month</th><th>Entry</th><th class="num">Depreciation</th><th class="num">Accumulated after</th><th class="num">Book value after</th></tr></thead><tbody>'
        + opening + rows.join('') + '</tbody>';
    }
    const notes = [];
    if (s.entries.length) notes.push(s.entries.length + ' month' + (s.entries.length === 1 ? '' : 's') + ' charged by depreciation runs; each opens its journal entry.');
    if (s.projected.length) {
      const first = s.projected[0];
      const k = monthNo(first.month) - monthNo(a.depreciation_start) + 1;
      notes.push('Projected months start with ' + monthLabel(first.month) + ', the next month a run can still charge.'
        + (a.method === 'straight_line' && k > 1 && first.amount_cents > s.projected[Math.min(1, s.projected.length - 1)].amount_cents ? ' It catches up months that were not charged.' : '')
        + ' Straight-line months follow the line exactly, so they add up to the cost less the residual value.');
    }
    qs('[data-schedule-note]', root).textContent = notes.join(' ');
  };

  // ── disposal ──────────────────────────────────────────────────────────
  const dispose = async () => {
    let lk;
    try { lk = await api.get('/assets/lookups', null, { signal: ctx.signal }); } catch (err) { toast(err.message, { kind: 'error' }); return; }
    const a = d.asset;
    const c = d.category;
    const last = d.schedule.entries.length ? d.schedule.entries[d.schedule.entries.length - 1] : null;
    const opt = (v, label) => '<option value="' + esc(v) + '">' + esc(label) + '</option>';

    const m = openModal({
      title: 'Dispose of ' + a.asset_no, size: 'lg',
      body: '<form class="stack" data-f novalidate><div class="alert alert-err" data-banner hidden></div>'
        + '<p class="muted mb-0">Sold, scrapped, lost or given away: the asset leaves the books. One entry removes its cost and accumulated depreciation and records the proceeds and any gain or loss. Nothing is depreciated in the month of disposal or after.</p>'
        + '<div class="form-grid">'
        + '<div class="field"><label class="label" for="dp-date">Date of disposal</label><input class="input" type="date" id="dp-date" name="disposed_on" value="' + esc(lk.today) + '" max="' + esc(lk.today) + '" min="' + esc(a.acquired_on) + '"><div class="error" data-error-for="disposed_on"></div></div>'
        + '<div class="field"><label class="label" for="dp-amt">Proceeds</label><input class="input" id="dp-amt" name="proceeds_cents" inputmode="decimal" value="0.00" autocomplete="off"><div class="hint">What the company received or will receive. 0 if scrapped or lost.</div><div class="error" data-error-for="proceeds_cents"></div></div>'
        + '<div class="field" data-acct-field hidden><label class="label" for="dp-acct">Received into</label><select class="select" id="dp-acct" name="proceeds_account_id">' + opt('', 'Choose an account')
        + lk.proceeds_accounts.map((x) => opt(x.id, x.code + ' · ' + x.name)).join('') + '</select><div class="error" data-error-for="proceeds_account_id"></div></div>'
        + '<div class="field" data-cust-field hidden><label class="label" for="dp-cust">Customer</label><select class="select" id="dp-cust" name="contact_id">' + opt('', 'Choose the customer')
        + lk.customers.map((x) => opt(x.id, x.name)).join('') + '</select><div class="error" data-error-for="contact_id"></div></div>'
        + '<div class="field"><label class="label" for="dp-to">Sold to <span class="opt">(optional)</span></label><input class="input" id="dp-to" name="sold_to" maxlength="160" autocomplete="off"><div class="error" data-error-for="sold_to"></div></div>'
        + '<div class="field span-2"><label class="label" for="dp-why">What happened</label><textarea class="textarea" id="dp-why" name="reason" maxlength="300" style="min-height:64px" placeholder="Sold to a private buyer; OR 10234"></textarea><div class="error" data-error-for="reason"></div></div>'
        + '</div><div class="alert alert-warn" data-warn hidden></div><div data-summary></div>'
        + '<div class="row gap-2" style="justify-content:flex-end"><button class="btn btn-secondary" type="button" data-x>Cancel</button><button class="btn btn-danger" type="submit">Post the disposal</button></div></form>',
    });
    const f = qs('[data-f]', m.el);
    const el = f.elements;
    qs('[data-x]', f).addEventListener('click', () => m.close());

    const recalc = () => {
      const p = el.proceeds_cents.value.trim() === '' ? 0 : toCents(el.proceeds_cents.value);
      const acct = lk.proceeds_accounts.find((x) => String(x.id) === el.proceeds_account_id.value);
      qs('[data-acct-field]', f).hidden = !(p > 0);
      qs('[data-cust-field]', f).hidden = !(p > 0 && acct && acct.control === 'ar');
      const warn = [];
      const date = el.disposed_on.value;
      if (last && /^\d{4}-\d{2}-\d{2}$/.test(date) && monthNo(last.month) >= monthNo(date)) {
        warn.push('The depreciation run for ' + last.period + ' includes this asset, and nothing is depreciated in the month of disposal. Undo that run on the Depreciation screen first, or date the disposal in a later month.');
      } else if (a.status === 'active' && d.schedule.projected.length && /^\d{4}-\d{2}-\d{2}$/.test(date) && monthNo(d.schedule.projected[0].month) < monthNo(date)) {
        warn.push('Depreciation has been charged up to ' + (last ? last.period : 'before ' + monthLabel(d.schedule.projected[0].month))
          + '. To charge the months before ' + monthLabel(date) + ', run them on the Depreciation screen first; runs after the disposal leave the asset out.');
      }
      if (p === null) { qs('[data-summary]', f).innerHTML = ''; qs('[data-warn]', f).hidden = !warn.length; qs('[data-warn]', f).textContent = warn.join(' '); return; }
      const nbv = a.cost_cents - a.accumulated_cents;
      const gain = Math.max(0, p - nbv);
      const loss = Math.max(0, nbv - p);
      if (gain && !lk.gain_account) warn.push('No gain on disposal account is set in Settings → Account defaults. An administrator must set it first.');
      if (loss && !lk.loss_account) warn.push('No loss on disposal account is set in Settings → Account defaults. An administrator must set it first.');
      const w = qs('[data-warn]', f);
      w.hidden = !warn.length;
      w.textContent = warn.join(' ');

      const acctName = (x) => (x ? x.code + ' · ' + x.name : '');
      const line = (name, dr, cr) => '<tr><td>' + esc(name) + '</td><td class="num">' + (dr ? money(dr) : '') + '</td><td class="num">' + (cr ? money(cr) : '') + '</td></tr>';
      const lines = [];
      if (a.accumulated_cents) lines.push(line(acctName(c.accum_account), a.accumulated_cents, 0));
      if (p) lines.push(line(acct ? acctName(acct) : 'The account the proceeds went into', p, 0));
      if (loss) lines.push(line(lk.loss_account ? acctName(lk.loss_account) : 'Loss on disposal', loss, 0));
      lines.push(line(acctName(c.asset_account), 0, a.cost_cents));
      if (gain) lines.push(line(lk.gain_account ? acctName(lk.gain_account) : 'Gain on disposal', 0, gain));
      qs('[data-summary]', f).innerHTML = '<div class="je-head" style="margin-bottom:var(--s3)">'
        + '<div><div class="k">Cost</div><div class="v">' + money(a.cost_cents) + '</div></div>'
        + '<div><div class="k">Accumulated to date</div><div class="v">' + money(a.accumulated_cents) + '</div></div>'
        + '<div><div class="k">Book value</div><div class="v">' + money(nbv) + '</div></div>'
        + '<div><div class="k">' + (gain ? 'Gain' : 'Loss') + '</div><div class="v ' + (gain ? 'ok-text' : loss ? 'err-text' : '') + '">' + money(gain || loss) + '</div></div></div>'
        + '<div class="table-wrap"><table class="table table-compact"><thead><tr><th>What goes into the books</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody>'
        + lines.join('') + '</tbody><tfoot><tr class="totals"><td class="right muted">Totals</td><td class="num">' + money(a.accumulated_cents + p + loss) + '</td><td class="num">'
        + money(a.cost_cents + gain) + '</td></tr></tfoot></table></div>';
    };
    f.addEventListener('input', recalc);
    f.addEventListener('change', recalc);
    el.proceeds_cents.addEventListener('focusout', () => { const c2 = toCents(el.proceeds_cents.value); if (c2 !== null) el.proceeds_cents.value = toMajor(c2); });
    recalc();

    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErrors(f);
      const p = el.proceeds_cents.value.trim() === '' ? 0 : toCents(el.proceeds_cents.value);
      if (p === null) { setErrors(f, { proceeds_cents: 'Enter the amount received, for example 70000 or 70,000.00; 0 if nothing.' }); return; }
      const b = qs('button[type="submit"]', f);
      busy(b, true);
      try {
        const r = await api.post('/assets/' + a.id + '/dispose', {
          disposed_on: el.disposed_on.value, proceeds_cents: p, proceeds_account_id: Number(el.proceeds_account_id.value) || 0,
          contact_id: Number(el.contact_id.value) || 0, sold_to: el.sold_to.value.trim(), reason: el.reason.value.trim(),
        });
        m.close();
        d = r;
        paint();
        toast(a.asset_no + ' disposed of' + (r.disposal && r.disposal.journal ? ': posted as ' + r.disposal.journal.journal_no : '') + '.');
      } catch (err) { formError(f, err); } finally { busy(b, false); }
    });
  };

  actions.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-do]');
    if (!b || !d) return;
    const a = d.asset;
    try {
      switch (b.dataset.do) {
        case 'dispose':
          await dispose();
          return;
        case 'undo': {
          const reason = await promptDialog({ title: 'Take back the disposal of ' + a.asset_no, label: 'Why is it taken back?', multiline: true, maxlength: 300, required: true,
            hint: 'The disposal entry is reversed on its own date (that month must still be open) and the asset is in use again.', confirmLabel: 'Take it back', danger: true });
          if (reason === null) return;
          busy(b, true);
          d = await api.post('/assets/' + a.id + '/undo-disposal', { reason });
          paint();
          toast('Disposal of ' + a.asset_no + ' taken back.');
          return;
        }
        case 'delete':
          if (!(await confirmDialog({ title: 'Delete ' + a.asset_no + '?', body: 'Nothing has been posted for it, so it can go. Its number is not used again. This cannot be undone.', confirmLabel: 'Delete', danger: true }))) return;
          busy(b, true);
          await api.del('/assets/' + a.id);
          toast('Asset ' + a.asset_no + ' deleted.');
          ctx.navigate('/assets', { replace: true });
          return;
        default:
      }
    } catch (err) {
      toast(err.message, { kind: 'error' });
    } finally {
      busy(b, false);
    }
  });

  qs('[data-view]', root).addEventListener('click', (e) => {
    const b = e.target.closest('button[data-v]');
    if (!b || !d || b.dataset.v === view) return;
    view = b.dataset.v;
    paintSchedule();
  });

  try {
    d = await api.get('/assets/' + id, null, { signal: ctx.signal });
  } catch (err) {
    if (ctx.signal.aborted) return;
    stateEl.innerHTML = emptyState(err.isNotFound ? 'magnifying-glass' : 'warning', err.isNotFound ? 'That asset does not exist' : 'Could not load the asset', err.isNotFound ? '' : err.message,
      '<a class="btn btn-secondary" href="assets">Back to the register</a>');
    return;
  }
  stateEl.innerHTML = '';
  body.hidden = false;
  paint();
  attachmentsPanel(qs('[data-attachments]', root), { owner: 'asset', id, user: ctx.user });
}
