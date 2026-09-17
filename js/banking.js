/**
 * banking.js — the bank accounts and their statements
 *
 * GenericPOS Accounting · ES module · every role reads it; bookkeepers add
 * statements; accountants add, change and deactivate bank accounts
 *
 * One card per bank account: its balance in the books today, its last
 * statement and how many statement lines still wait to be matched, then its
 * statements, newest first. A bank account keeps only the last four digits of
 * its number; the full number is never typed in here.
 */

import { api } from './api.js';
import { money, toCents, toMajor, fmtDay } from './store.js';
import { qs, esc, emptyState, statusBadge, openModal, busy, formError, clearErrors, setErrors, toast } from './ui.js';

export async function mount(root, ctx) {
  const box = qs('[data-accounts]', root);
  let d = null;

  const stat = (label, value, sub) => '<div class="stat"><div class="stat-label">' + esc(label) + '</div>'
    + '<div class="stat-value" style="font-size:21px">' + value + '</div><div class="stat-sub">' + sub + '</div></div>';

  const card = (a) => {
    const last = a.last_statement;
    const rows = a.statements.map((s) => '<tr class="is-link" data-statement="' + s.id + '">'
      + '<td class="nowrap"><a href="banking/statements/' + s.id + '">' + esc(fmtDay(s.statement_date)) + '</a></td>'
      + '<td class="num">' + money(s.opening_balance_cents) + '</td>'
      + '<td class="num">' + money(s.closing_balance_cents) + '</td>'
      + '<td class="num">' + s.line_count + '</td>'
      + '<td class="num">' + s.matched_count + '</td>'
      + '<td class="num">' + (s.unmatched_count ? '<b class="warn-text">' + s.unmatched_count + '</b>' : '0') + '</td>'
      + '<td class="nowrap">' + statusBadge(s.status)
      + (s.adds_up ? '' : ' <span class="badge badge-err" title="The opening balance and the lines do not add up to the closing balance">Does not add up</span>') + '</td></tr>').join('');

    const acts = [
      d.can.statements && a.is_active ? '<button class="btn btn-sm" type="button" data-new-statement="' + a.id + '"><span data-icon="plus" data-icon-size="16"></span>New statement</button>' : '',
      d.can.manage ? '<button class="btn btn-secondary btn-sm" type="button" data-edit="' + a.id + '"><span data-icon="pencil-simple" data-icon-size="16"></span>Edit</button>' : '',
    ].join('');

    return '<section class="card card-flush mb-4" data-account="' + a.id + '">'
      + '<div class="card-head"><div style="min-width:0"><h3 class="row gap-2"><span data-icon="bank" data-icon-size="20"></span>' + esc(a.bank_name)
      + (a.is_active ? '' : ' ' + statusBadge('inactive')) + '</h3>'
      + '<div class="small muted">' + esc([a.account_name, a.account_last4 ? 'account ending ' + a.account_last4 : '', 'books: ' + a.ledger_code + ' · ' + a.ledger_name].filter(Boolean).join(' · ')) + '</div></div>'
      + '<div class="row gap-2">' + acts + '</div></div>'
      + '<div style="padding:var(--s4) var(--s5)"><div class="stats">'
      + stat('Balance in the books today', money(a.book_balance_cents), 'Posted entries on ' + esc(a.ledger_code))
      + stat('Last statement', last ? esc(fmtDay(last.statement_date)) : '—', last ? statusBadge(last.status) : 'None yet')
      + stat('Lines to match', String(a.unmatched_count), a.open_count ? a.open_count + ' open statement' + (a.open_count === 1 ? '' : 's') : 'Nothing open')
      + '</div></div>'
      + (rows
        ? '<div class="table-wrap"><table class="table table-compact"><thead><tr><th>Statement date</th><th class="num">Opening</th><th class="num">Closing</th>'
          + '<th class="num">Lines</th><th class="num">Matched</th><th class="num">Unmatched</th><th>Status</th></tr></thead><tbody>' + rows + '</tbody></table></div>'
        : '<div class="small faint" style="padding:0 var(--s5) var(--s4)">No statements yet.' + (d.can.statements && a.is_active ? ' Add the first one with New statement.' : '') + '</div>')
      + '</section>';
  };

  const paint = () => {
    qs('[data-actions]', root).innerHTML = d.can.manage
      ? '<button class="btn" type="button" data-new-account><span data-icon="plus" data-icon-size="18"></span>New bank account</button>' : '';
    qs('[data-foot]', root).textContent = 'A statement\'s lines are matched to posted entries on the bank\'s ledger account, within '
      + d.window_days + ' days of each other. Only the last four digits of an account number are kept.';
    box.innerHTML = d.accounts.length ? d.accounts.map(card).join('')
      : emptyState('bank', 'No bank accounts yet', d.can.manage
        ? 'Add one for each bank account the company keeps, linked to its cash account in the chart of accounts.'
        : 'An accountant adds them.');
  };

  const load = async () => {
    try {
      d = await api.get('/banking', null, { signal: ctx.signal });
    } catch (err) {
      if (!ctx.signal.aborted) box.innerHTML = emptyState('warning', 'Could not load the bank accounts', err.message);
      return;
    }
    paint();
  };

  // ── a bank account (accountants) ──────────────────────────────────────
  const field = (id, label, control, name, hint) => '<div class="field"><label class="label" for="' + id + '">' + label + '</label>' + control
    + (hint ? '<div class="hint">' + hint + '</div>' : '') + '<div class="error" data-error-for="' + name + '"></div></div>';

  const editAccount = (a) => {
    const isNew = !a;
    const x = a || { bank_name: '', account_name: '', account_last4: '', account_id: 0, is_active: true };
    const opts = (d.ledger_accounts || []).slice();
    if (a && !opts.some((o) => o.id === a.account_id)) opts.unshift({ id: a.account_id, code: a.ledger_code, name: a.ledger_name });
    const m = openModal({
      title: isNew ? 'New bank account' : 'Edit ' + x.bank_name, size: 'lg',
      body: '<form class="stack" data-f novalidate><div class="alert alert-err" data-banner hidden></div><div class="form-grid">'
        + field('ba-bank', 'Bank', '<input class="input" id="ba-bank" name="bank_name" maxlength="120" value="' + esc(x.bank_name) + '" autocomplete="off" placeholder="BDO Unibank">', 'bank_name')
        + field('ba-name', 'Account name <span class="opt">(optional)</span>', '<input class="input" id="ba-name" name="account_name" maxlength="160" value="' + esc(x.account_name || '') + '" autocomplete="off">', 'account_name', 'As the bank prints it.')
        + field('ba-last4', 'Last 4 digits', '<input class="input input-mono" id="ba-last4" name="account_last4" maxlength="4" inputmode="numeric" value="' + esc(x.account_last4 || '') + '" autocomplete="off" placeholder="4821">', 'account_last4', 'Only the last four digits. The full account number is never stored.')
        + field('ba-ledger', 'Ledger account', '<select class="select" id="ba-ledger" name="account_id"><option value="">Choose a cash account</option>'
          + opts.map((o) => '<option value="' + o.id + '"' + (o.id === x.account_id ? ' selected' : '') + '>' + esc(o.code + ' · ' + o.name) + '</option>').join('') + '</select>', 'account_id',
          'A cash account in the chart that no other bank account uses.' + (a && a.statements.length ? ' It cannot change once the account has statements.' : ''))
        + '</div>'
        + (isNew ? '' : '<label class="check"><input type="checkbox" name="is_active"' + (x.is_active ? ' checked' : '') + '> <span>Active — new statements can be added</span></label><div class="error" data-error-for="is_active"></div>')
        + '<div class="row gap-2" style="justify-content:flex-end"><button class="btn btn-secondary" type="button" data-x>Cancel</button>'
        + '<button class="btn" type="submit">' + (isNew ? 'Add bank account' : 'Save') + '</button></div></form>',
    });
    const f = qs('[data-f]', m.el);
    qs('[data-x]', f).addEventListener('click', () => m.close());
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErrors(f);
      const el = f.elements;
      const digits = el.account_last4.value.replace(/\D/g, '');
      if (el.account_last4.value.trim() && digits.length !== 4) {
        setErrors(f, { account_last4: digits.length > 4 ? 'Enter only the last 4 digits. The full account number is never stored.' : 'Enter the last 4 digits of the account number.' });
        return;
      }
      const body = { bank_name: el.bank_name.value.trim(), account_name: el.account_name.value.trim(), account_last4: digits,
        account_id: Number(el.account_id.value) || 0 };
      if (!isNew) body.is_active = el.is_active.checked;
      const b = qs('button[type="submit"]', f);
      busy(b, true);
      try {
        await (isNew ? api.post('/banking/accounts', body) : api.put('/banking/accounts/' + x.id, body));
        m.close();
        toast(isNew ? body.bank_name + ' added.' : body.bank_name + ' saved.');
        load();
      } catch (err) { formError(f, err); } finally { busy(b, false); }
    });
  };

  // ── a new statement (bookkeepers) ─────────────────────────────────────
  const newStatement = (a) => {
    const last = a.last_statement;
    const m = openModal({
      title: 'New statement · ' + a.bank_name, size: 'sm',
      body: '<form class="stack" data-f novalidate><div class="alert alert-err" data-banner hidden></div>'
        + field('ns-date', 'Statement date', '<input class="input" type="date" id="ns-date" name="statement_date" max="' + esc(d.today) + '">', 'statement_date', 'The closing date printed on the statement.')
        + field('ns-open', 'Opening balance', '<input class="input" id="ns-open" name="opening" inputmode="decimal" autocomplete="off" value="' + (last ? esc(toMajor(last.closing_balance_cents)) : '') + '">', 'opening_balance_cents',
          last ? 'The last statement (' + esc(fmtDay(last.statement_date)) + ') closed at ' + money(last.closing_balance_cents) + '.' : 'The balance at the start of the statement.')
        + field('ns-close', 'Closing balance', '<input class="input" id="ns-close" name="closing" inputmode="decimal" autocomplete="off">', 'closing_balance_cents')
        + '<div class="row gap-2" style="justify-content:flex-end"><button class="btn btn-secondary" type="button" data-x>Cancel</button><button class="btn" type="submit">Create statement</button></div></form>',
    });
    const f = qs('[data-f]', m.el);
    qs('[data-x]', f).addEventListener('click', () => m.close());
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErrors(f);
      const el = f.elements;
      const open = toCents(el.opening.value, true);
      const close = toCents(el.closing.value, true);
      const errs = {};
      if (!el.statement_date.value) errs.statement_date = 'Enter the statement date.';
      if (open === null) errs.opening_balance_cents = 'Enter the opening balance, like 199,892.24.';
      if (close === null) errs.closing_balance_cents = 'Enter the closing balance, like 961,895.71.';
      if (Object.keys(errs).length) { setErrors(f, errs); return; }
      const b = qs('button[type="submit"]', f);
      busy(b, true);
      try {
        const r = await api.post('/banking/statements', { bank_account_id: a.id, statement_date: el.statement_date.value, opening_balance_cents: open, closing_balance_cents: close });
        m.close();
        toast('Statement created. Add its lines or import them from the bank\'s file.');
        ctx.navigate('/banking/statements/' + r.statement.id);
      } catch (err) { formError(f, err); } finally { busy(b, false); }
    });
  };

  // ── wiring ────────────────────────────────────────────────────────────
  root.addEventListener('click', (e) => {
    if (!d) return;
    const byId = (id) => d.accounts.find((a) => a.id === Number(id));
    const t = e.target;
    if (t.closest('[data-new-account]')) { editAccount(null); return; }
    const ed = t.closest('[data-edit]');
    if (ed) { editAccount(byId(ed.dataset.edit)); return; }
    const ns = t.closest('[data-new-statement]');
    if (ns) { newStatement(byId(ns.dataset.newStatement)); return; }
    const tr = t.closest('tr[data-statement]');
    if (tr && !t.closest('a')) ctx.navigate('/banking/statements/' + tr.dataset.statement);
  });

  await load();
}
