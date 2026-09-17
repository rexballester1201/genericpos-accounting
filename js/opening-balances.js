/**
 * opening-balances.js — the balances the books start from
 *
 * GenericPOS Accounting · ES module · administrators (PLAN.md §8)
 *
 *   the draft   one line per account — per customer or supplier on a
 *               receivables or payables control account, with a department
 *               where the account requires one — saved on the server
 *   post        one entry in the opening book, dated the go-live day
 *   undo        reverses a posted opening entry (same date) and brings its
 *               lines back into the draft for correcting
 *
 * Amounts are typed in pesos and sent as centavos (toCents: exact, no floats).
 * The server checks everything again when the draft is posted.
 */

import { api } from './api.js';
import { money, toMajor, toCents, fmtDay, fmtDate } from './store.js';
import { qs, esc, emptyState, toast, busy, confirmDialog, promptDialog } from './ui.js';

const TYPES = [['asset', 'Assets'], ['liability', 'Liabilities'], ['equity', 'Equity'], ['income', 'Income'], ['expense', 'Expenses']];
const blank = () => ({ account_id: '', contact_id: '', department_id: '', debit: '', credit: '', memo: '' });
const cents = (s) => (String(s).trim() === '' ? 0 : toCents(s));

export async function mount(root, ctx) {
  const box = qs('[data-body]', root);
  let st = null;
  let lines = [];
  let date = '';
  let dirty = false;

  const acct = (id) => st.accounts.find((a) => a.id === Number(id)) || null;

  const accountOptions = (sel) => '<option value="">Choose an account…</option>' + TYPES.map(([t, label]) => {
    const list = st.accounts.filter((a) => a.type === t && (a.active || a.id === Number(sel)));
    return list.length ? '<optgroup label="' + label + '">' + list.map((a) => '<option value="' + a.id + '"' + (a.id === Number(sel) ? ' selected' : '') + '>'
      + esc(a.code + ' ' + a.name) + '</option>').join('') + '</optgroup>' : '';
  }).join('');

  const contactOptions = (a, sel) => {
    const want = a.control === 'ar' ? 'customer' : 'supplier';
    return '<option value="">' + (want === 'customer' ? 'Choose the customer…' : 'Choose the supplier…') + '</option>'
      + st.contacts.filter((c) => c[want]).map((c) => '<option value="' + c.id + '"' + (c.id === Number(sel) ? ' selected' : '') + '>' + esc(c.name + ' (' + c.code + ')') + '</option>').join('');
  };

  const deptOptions = (sel) => '<option value="">Choose the department…</option>'
    + st.departments.map((d) => '<option value="' + d.id + '"' + (d.id === Number(sel) ? ' selected' : '') + '>' + esc(d.code + ' ' + d.name) + '</option>').join('');

  const rowHtml = (l, i) => {
    const a = acct(l.account_id);
    const who = a && (a.control === 'ar' || a.control === 'ap');
    const dep = a && a.needs_department;
    return '<tr data-i="' + i + '">'
      + '<td><select class="select" data-f="account_id" aria-label="Account, line ' + (i + 1) + '">' + accountOptions(l.account_id) + '</select></td>'
      + '<td>' + (who ? '<select class="select" data-f="contact_id" aria-label="Customer or supplier, line ' + (i + 1) + '">' + contactOptions(a, l.contact_id) + '</select>' : '')
      + (dep ? '<select class="select" data-f="department_id" aria-label="Department, line ' + (i + 1) + '">' + deptOptions(l.department_id) + '</select>' : '')
      + (!who && !dep ? '<span class="xs faint">—</span>' : '') + '</td>'
      + '<td><input class="input num" data-f="debit" inputmode="decimal" autocomplete="off" value="' + esc(l.debit) + '" aria-label="Debit, line ' + (i + 1) + '"></td>'
      + '<td><input class="input num" data-f="credit" inputmode="decimal" autocomplete="off" value="' + esc(l.credit) + '" aria-label="Credit, line ' + (i + 1) + '"></td>'
      + '<td><input class="input" data-f="memo" maxlength="255" value="' + esc(l.memo) + '" aria-label="Memo, line ' + (i + 1) + '"></td>'
      + '<td><button class="btn btn-ghost btn-sm" type="button" data-remove aria-label="Remove line ' + (i + 1) + '"><span data-icon="trash" data-icon-size="16"></span></button></td></tr>';
  };

  const paintRows = () => {
    const body = qs('[data-rows]', root);
    if (body) body.innerHTML = lines.map(rowHtml).join('');
    paintTotals();
  };

  const paintTotals = () => {
    let dr = 0;
    let cr = 0;
    const bad = [];
    const byType = {};
    lines.forEach((l, i) => {
      const d = cents(l.debit);
      const c = cents(l.credit);
      if (d === null || c === null) { bad.push(i + 1); return; }
      dr += d;
      cr += c;
      const a = acct(l.account_id);
      if (a) byType[a.type] = (byType[a.type] || 0) + (d - c);
    });
    const diff = dr - cr;
    const set = (sel, html) => { const el = qs(sel, root); if (el) el.innerHTML = html; };
    set('[data-tdr]', esc(money(dr)));
    set('[data-tcr]', esc(money(cr)));
    set('[data-diff]', bad.length
      ? '<span class="badge badge-err">Check the amount on line ' + bad.join(', ') + '</span>'
      : (diff === 0 ? (dr ? '<span class="badge badge-ok">Balanced</span>' : '')
        : '<span class="badge badge-err">' + esc((diff > 0 ? 'Debits exceed credits by ' : 'Credits exceed debits by ') + money(Math.abs(diff))) + '</span>'));
    const normal = { asset: 1, expense: 1, liability: -1, equity: -1, income: -1 };
    set('[data-bytype]', TYPES.filter(([t]) => byType[t]).map(([t, label]) => esc(label) + ' ' + esc(money(byType[t] * normal[t]))).join(' · '));
  };

  const fromDraft = (d) => {
    date = d.entry_date || st.default_date || st.today || '';
    lines = (d.lines || []).map((l) => ({
      account_id: l.account_id || '', contact_id: l.contact_id || '', department_id: l.department_id || '',
      debit: l.debit_cents ? toMajor(l.debit_cents) : '', credit: l.credit_cents ? toMajor(l.credit_cents) : '', memo: l.memo || '',
    }));
    if (!lines.length) lines = [blank(), blank(), blank()];
    dirty = false;
  };

  const entriesCard = (es) => (!es.length ? '' : '<section class="card"><div class="card-head"><h3>Opening entries posted</h3></div>'
    + '<div class="table-wrap"><table class="table table-compact"><thead><tr><th>Number</th><th>Date</th><th class="num">Amount</th><th>State</th></tr></thead><tbody>'
    + es.map((j) => '<tr><td><a href="journals/' + j.id + '">' + esc(j.journal_no) + '</a></td><td>' + esc(fmtDay(j.entry_date, 'short')) + '</td>'
      + '<td class="num">' + esc(money(j.total_cents)) + '</td><td>'
      + (j.is_reversal ? '<span class="small muted">Reversal</span>'
        : (j.reversed_by_id ? '<span class="small muted">Undone by <a href="journals/' + j.reversed_by_id + '">' + esc(j.reversed_by_no) + '</a></span>' : '<span class="badge badge-ok">In force</span>'))
      + '</td></tr>').join('') + '</tbody></table></div></section>');

  const render = () => {
    const act = st.active;
    box.innerHTML = (act
      ? '<div class="alert alert-ok"><span data-icon="check-circle" data-icon-size="18"></span><div class="grow">The opening balances'
        + (st.fiscal_year ? ' for ' + esc(st.fiscal_year.name) : '') + ' are posted as <a href="journals/' + act.id + '">' + esc(act.journal_no) + '</a>, dated '
        + esc(fmtDay(act.entry_date, 'long')) + ' (' + esc(money(act.total_cents)) + '). To correct them, undo that entry: its lines come back here to edit and post again.</div>'
        + '<button class="btn btn-danger-soft btn-sm" type="button" data-undo="' + act.id + '">Undo</button></div>' : '')
      + '<section class="card"><div class="card-head"><div><h3>' + (act ? 'Draft for a correction' : 'Draft') + '</h3>'
      + '<div class="small muted">' + (st.draft.updated_at ? 'Saved ' + esc(fmtDate(st.draft.updated_at)) : 'Not saved yet') + '</div></div>'
      + '<a class="btn btn-secondary btn-sm" href="imports?kind=opening"><span data-icon="upload-simple" data-icon-size="16"></span>Import a CSV</a></div>'
      + '<div class="card-body stack">'
      + '<div class="field" style="max-width:260px"><label class="label" for="ob-date">The books start on</label>'
      + '<input class="input" type="date" id="ob-date" data-date value="' + esc(date) + '">'
      + '<div class="hint">Usually the first day of your first fiscal year here. The entry is dated on this day.</div></div>'
      + '<div class="table-wrap"><table class="table table-compact"><thead><tr><th style="min-width:15em">Account</th><th style="min-width:13em">Customer, supplier or department</th>'
      + '<th class="num" style="min-width:9em">Debit</th><th class="num" style="min-width:9em">Credit</th><th style="min-width:10em">Memo</th><th></th></tr></thead>'
      + '<tbody data-rows></tbody>'
      + '<tfoot><tr><th colspan="2">Total</th><th class="num" data-tdr></th><th class="num" data-tcr></th><th colspan="2" data-diff></th></tr></tfoot></table></div>'
      + '<div class="row gap-2"><button class="btn btn-secondary btn-sm" type="button" data-add><span data-icon="plus" data-icon-size="16"></span>Add a line</button>'
      + '<button class="btn btn-ghost btn-sm" type="button" data-add-all>Add a line for every account</button></div>'
      + '<div class="small muted" data-bytype></div>'
      + '<div class="row gap-2"><button class="btn btn-secondary" type="button" data-save>Save draft</button>'
      + '<button class="btn" type="button" data-post' + (act ? ' disabled title="Undo the posted opening balances first"' : '') + '><span data-icon="check" data-icon-size="18"></span>Post opening balances</button></div>'
      + '</div></section>'
      + entriesCard(st.entries);
    paintRows();
  };

  const payload = () => {
    const out = [];
    const bad = [];
    lines.forEach((l, i) => {
      const d = cents(l.debit);
      const c = cents(l.credit);
      if (d === null || c === null) { bad.push(i + 1); return; }
      if (!l.account_id && !d && !c) return;
      out.push({ account_id: Number(l.account_id) || 0, contact_id: Number(l.contact_id) || null, department_id: Number(l.department_id) || null,
                 debit_cents: d, credit_cents: c, memo: l.memo });
    });
    return { out, bad };
  };

  const errText = (err) => (err.errors ? Object.values(err.errors).join(' ') : err.message);

  const save = async (quiet) => {
    const { out, bad } = payload();
    if (bad.length) { toast('Check the amount on line ' + bad.join(', ') + ': use numbers like 1250 or 1,250.50.', { kind: 'error' }); return false; }
    st = await api.put('/opening-balances', { entry_date: date, lines: out });
    fromDraft(st.draft);
    if (!quiet) toast('Draft saved.');
    return true;
  };

  const load = async () => {
    try {
      st = await api.get('/opening-balances', null, { signal: ctx.signal });
      fromDraft(st.draft);
      render();
    } catch (err) {
      if (!ctx.signal.aborted) box.innerHTML = emptyState('warning', 'Could not load the opening balances', err.message);
    }
  };

  // ── editing ───────────────────────────────────────────────────────────────
  const onField = (e) => {
    if (e.target.matches('[data-date]')) { date = e.target.value; dirty = true; return; }
    const f = e.target.closest('[data-f]');
    if (!f) return;
    const i = Number(f.closest('tr').dataset.i);
    lines[i][f.dataset.f] = f.value;
    dirty = true;
    if (f.dataset.f === 'account_id') {
      lines[i].contact_id = '';
      lines[i].department_id = '';
      const tr = f.closest('tr');
      tr.outerHTML = rowHtml(lines[i], i);
    }
    if (f.dataset.f === 'debit' && String(f.value).trim() !== '') lines[i].credit = '';
    if (f.dataset.f === 'credit' && String(f.value).trim() !== '') lines[i].debit = '';
    paintTotals();
  };
  root.addEventListener('change', onField);
  root.addEventListener('input', (e) => { if (e.target.matches('input[data-f], [data-date]')) onField(e); });
  root.addEventListener('focusout', (e) => {
    const f = e.target.closest('[data-f="debit"], [data-f="credit"]');
    if (!f) return;
    const tr = f.closest('tr');
    const l = lines[Number(tr.dataset.i)];
    qs('[data-f="debit"]', tr).value = l.debit;
    qs('[data-f="credit"]', tr).value = l.credit;
  });

  root.addEventListener('click', async (e) => {
    if (e.target.closest('[data-add]')) { lines.push(blank()); dirty = true; paintRows(); return; }

    if (e.target.closest('[data-add-all]')) {
      const have = new Set(lines.map((l) => Number(l.account_id)).filter(Boolean));
      const add = st.accounts.filter((a) => a.active && !have.has(a.id));
      if (!add.length) { toast('Every account already has a line.'); return; }
      if (add.length > 40 && !(await confirmDialog({ title: 'Add ' + add.length + ' lines?', body: 'One line for every active account without one. Lines left at zero are dropped when you save.', confirmLabel: 'Add them' }))) return;
      lines = lines.filter((l) => l.account_id || l.debit || l.credit).concat(add.map((a) => Object.assign(blank(), { account_id: a.id })));
      dirty = true;
      paintRows();
      return;
    }

    const rm = e.target.closest('[data-remove]');
    if (rm) { lines.splice(Number(rm.closest('tr').dataset.i), 1); if (!lines.length) lines.push(blank()); dirty = true; paintRows(); return; }

    const sv = e.target.closest('[data-save]');
    if (sv) {
      busy(sv, true);
      try { if (await save(false)) render(); } catch (err) { toast(errText(err), { kind: 'error' }); } finally { busy(sv, false); }
      return;
    }

    const po = e.target.closest('[data-post]');
    if (po) {
      const { out, bad } = payload();
      if (bad.length) { toast('Check the amount on line ' + bad.join(', ') + '.', { kind: 'error' }); return; }
      const dr = out.reduce((a, l) => a + l.debit_cents, 0);
      const cr = out.reduce((a, l) => a + l.credit_cents, 0);
      if (dr !== cr) { toast('Debits and credits must balance before posting: they differ by ' + money(Math.abs(dr - cr)) + '.', { kind: 'error' }); return; }
      if (!(await confirmDialog({ title: 'Post the opening balances?', body: out.length + ' lines, ' + money(dr) + ' each side, dated ' + fmtDay(date, 'long') + '. They go into the opening book at once; to change them later you undo the entry and post again.', confirmLabel: 'Post them' }))) return;
      busy(po, true);
      try {
        await save(true);
        st = await api.post('/opening-balances/post', {});
        fromDraft(st.draft);
        toast('Opening balances posted' + (st.active ? ' as ' + st.active.journal_no : '') + '.');
        render();
      } catch (err) { toast(errText(err), { kind: 'error' }); busy(po, false); }
      return;
    }

    const un = e.target.closest('[data-undo]');
    if (un) {
      const reason = await promptDialog({ title: 'Undo the opening balances?', label: 'Why?', hint: 'A reversing entry is posted on the same date and the lines come back into the draft. The reason goes into the audit log.', multiline: true, required: true, maxlength: 300, confirmLabel: 'Undo them', danger: true });
      if (reason === null) return;
      if (dirty && !(await confirmDialog({ title: 'Replace the draft?', body: 'The draft here has unsaved changes. Undoing brings the posted lines back and replaces it.', confirmLabel: 'Replace it', danger: true }))) return;
      busy(un, true);
      try {
        st = await api.post('/opening-balances/' + un.dataset.undo + '/undo', { reason });
        fromDraft(st.draft);
        toast('Opening balances undone. Correct the draft and post it again.');
        render();
      } catch (err) { toast(errText(err), { kind: 'error' }); busy(un, false); }
    }
  });

  await load();
}
