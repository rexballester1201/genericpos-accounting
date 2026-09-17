/**
 * bank-statement.js — one bank statement: its lines, matching them to the books, and reconciling it
 *
 * GenericPOS Accounting · ES module · every role reads it; bookkeepers add
 * lines, import files and match; accountants record entries, reconcile and reopen
 *
 * Left, the bank's lines; right, the book lines on the bank's ledger account
 * that no bank line has cleared yet. Choose one bank line and the book lines
 * that make it up — a deposit is made of book debits, a withdrawal of book
 * credits, and they must add up exactly — then Match. A line the books do not
 * have yet (a charge, interest) gets its entry posted from here and matched at
 * once. Auto-match pairs every line that has exactly one candidate.
 *
 * The server says what this person may do (`can`) and answers every action
 * with the statement as it now stands, so the screen redraws from that.
 */

import { api } from './api.js';
import { money, toCents, toMajor, fmtDay, fmtDate } from './store.js';
import { qs, qsa, esc, emptyState, statusBadge, openModal, confirmDialog, promptDialog, busy, toast, formError, clearErrors, setErrors } from './ui.js';
import { setAdminTitle } from './chrome.js';

const TABS = [['unmatched', 'To match'], ['matched', 'Matched'], ['ignored', 'Set aside'], ['', 'All']];
const STATUS_LABEL = { unmatched: 'To match', matched: 'Matched', ignored: 'Set aside' };

/** An amount from the bank's side: + money in, − money out. */
const signed = (c) => (c < 0 ? '<span class="err-text">' + money(c) + '</span>' : money(c));

const addDays = (ymd, n) => {
  const [y, m, d] = ymd.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d + n)).toISOString().slice(0, 10);
};

export async function mount(root, ctx) {
  const id = parseInt(ctx.params.id, 10) || 0;
  const stateEl = qs('[data-state]', root);
  const body = qs('[data-body]', root);
  const tbody = qs('[data-lines]', root);
  const bbody = qs('[data-books]', root);
  const selbar = qs('[data-selbar]', root);
  let d = null;
  const sel = { line: 0, books: new Set() };
  const view = { tab: 'unmatched', older: false, q: '' };

  const lineById = (lid) => d.lines.find((l) => l.id === lid);
  const bookById = (lid) => d.book_lines.find((b) => b.line_id === lid);

  // ── the head, the facts, the warnings ─────────────────────────────────
  const paintHead = () => {
    const s = d.statement;
    const b = d.bank;
    const f = d.figures;
    const title = b.bank_name + ' · ' + fmtDay(s.statement_date);
    qs('[data-title]', root).innerHTML = '<span>' + esc(title) + '</span>' + statusBadge(s.status);
    qs('[data-crumb]', root).textContent = 'Statement of ' + fmtDay(s.statement_date);
    qs('[data-sub]', root).textContent = (b.account_last4 ? 'Account ending ' + b.account_last4 + ' · ' : '') + 'books: ' + b.ledger_code + ' ' + b.ledger_name
      + ' · ' + fmtDay(f.period_from) + ' to ' + fmtDay(s.statement_date, 'long');
    ctx.setTitle(title);
    setAdminTitle(title);

    const fact = (k, v, wide) => '<div' + (wide ? ' class="wide"' : '') + '><div class="k">' + esc(k) + '</div><div class="v">' + v + '</div></div>';
    const c = f.counts;
    qs('[data-head]', root).innerHTML =
        fact('Opening balance', '<span class="num">' + money(s.opening_balance_cents) + '</span>')
      + fact('Lines', '<span class="num">' + signed(f.checks.lines_total_cents) + '</span> <span class="small muted">(' + c.lines + ')</span>')
      + fact('Closing balance', '<span class="num">' + money(s.closing_balance_cents) + '</span>' + (f.checks.adds_up ? '' : ' <span class="badge badge-err">Does not add up</span>'))
      + fact('Balance per books', '<span class="num">' + money(f.book_side.balance_cents) + '</span>')
      + fact('Adjusted bank balance', '<span class="num">' + money(f.bank_side.adjusted_cents) + '</span>')
      + fact('Adjusted book balance', '<span class="num">' + money(f.book_side.adjusted_cents) + '</span>')
      + fact('Difference', f.difference_cents === 0 ? '<span class="ok-text">None</span>' : '<span class="err-text num">' + money(f.difference_cents) + '</span>')
      + fact('Matched', c.matched + ' of ' + c.lines + (c.ignored ? ' · ' + c.ignored + ' set aside' : ''))
      + fact(s.status === 'reconciled' ? 'Reconciled by' : 'Prepared by', esc(s.status === 'reconciled'
        ? (s.reconciled_by_name || '') + ' · ' + fmtDate(s.reconciled_at, 'date') : (s.created_by_name || '') + ' · ' + fmtDate(s.created_at, 'date')));

    const al = [];
    const alert = (kind, icon, html) => al.push('<div class="alert alert-' + kind + '"><span data-icon="' + icon + '"></span><div>' + html + '</div></div>');
    if (!f.checks.adds_up) {
      alert('err', 'warning', 'The lines add up to ' + money(f.checks.expected_closing_cents) + ', the statement says ' + money(s.closing_balance_cents)
        + '. A line is missing or mistyped, or the closing balance is wrong.');
    }
    if (f.checks.previous && f.checks.opening_agrees === false) {
      alert('warn', 'warning', 'The opening balance should equal the closing balance of the statement of ' + esc(fmtDay(f.checks.previous.statement_date))
        + ' (' + money(f.checks.previous.closing_balance_cents) + ').');
    }
    if (f.checks.first && f.checks.opening_agrees_books === false) {
      alert('warn', 'info', 'The books show ' + money(f.checks.books_at_start_cents) + ' on ' + esc(fmtDay(f.checks.books_at_start_date)) + ', the statement opens at '
        + money(s.opening_balance_cents) + '. Book lines before ' + esc(fmtDay(f.recon_start)) + ' are taken as part of this first statement\'s opening balance, '
        + 'so a cheque or deposit still outstanding then shows as a difference until it clears.');
    }
    if (s.status === 'reconciled') {
      alert('ok', 'check-circle', 'Reconciled by <b>' + esc(s.reconciled_by_name || '') + '</b> on ' + esc(fmtDate(s.reconciled_at, 'date'))
        + '. It is read-only' + (d.can.reopen ? '; you can reopen it while it is the latest reconciled statement.' : '.'));
    } else if (d.can.reconcile && d.reconcile_blockers.length) {
      alert('neutral', 'scales', 'Before it can be reconciled: ' + d.reconcile_blockers.map(esc).join(' '));
    } else if (d.can.reconcile) {
      alert('ok', 'check-circle', 'Every line is matched or set aside and the difference is zero. It is ready to reconcile.');
    }
    qs('[data-alerts]', root).innerHTML = al.join('');

    const cn = d.can;
    const btn = (act, label, icon, cls) => '<button class="btn ' + (cls || '') + '" type="button" data-do="' + act + '"><span data-icon="' + icon + '" data-icon-size="18"></span>' + label + '</button>';
    qs('[data-actions]', root).innerHTML = [
      '<a class="btn btn-ghost" href="reports/bank-reconciliation?statement_id=' + s.id + '"><span data-icon="file-text" data-icon-size="18"></span>Reconciliation</a>',
      cn.delete ? btn('delete', 'Delete', 'trash', 'btn-ghost') : '',
      cn.edit ? btn('edit-statement', 'Edit', 'pencil-simple', 'btn-secondary') : '',
      cn.edit ? btn('import', 'Import CSV', 'upload-simple', 'btn-secondary') : '',
      cn.edit ? btn('add-line', 'Add line', 'plus', 'btn-secondary') : '',
      cn.match ? btn('auto', 'Auto-match', 'lightning', 'btn-secondary') : '',
      cn.reconcile ? btn('reconcile', 'Reconcile', 'check-circle', '') : '',
      cn.reopen ? btn('reopen', 'Reopen', 'lock-open', 'btn-secondary') : '',
    ].join('');
    qs('[data-foot]', root).textContent = 'A bank line matches book lines on ' + d.bank.ledger_code + ' dated up to ' + d.window_days
      + ' days after the statement date; a deposit is made of book debits, a withdrawal of book credits, and they must add up exactly. A book line clears only once.';
  };

  // ── the bank lines ────────────────────────────────────────────────────
  const paintLines = () => {
    const counts = { unmatched: 0, matched: 0, ignored: 0, '': d.lines.length };
    d.lines.forEach((l) => { counts[l.status]++; });
    qs('[data-tabs]', root).innerHTML = TABS.map(([v, label]) => '<button class="tab" type="button" role="tab" data-tab="' + v + '" aria-selected="' + String(view.tab === v)
      + '">' + esc(label) + ' <span class="faint num">' + counts[v] + '</span></button>').join('');
    qs('[data-line-count]', root).textContent = d.lines.length + ' line' + (d.lines.length === 1 ? '' : 's');

    const rows = d.lines.filter((l) => !view.tab || l.status === view.tab);
    const cn = d.can;
    tbody.innerHTML = rows.length ? rows.map((l) => {
      const pick = cn.match && l.status === 'unmatched'
        ? '<input type="radio" name="bs-line" value="' + l.id + '" aria-label="Choose this line"' + (sel.line === l.id ? ' checked' : '') + '>' : '';
      const tools = [];
      if (l.status === 'unmatched' && cn.edit) {
        tools.push('<button class="link-btn small" type="button" data-line-edit="' + l.id + '">Edit</button>');
        tools.push('<button class="link-btn small" type="button" data-line-del="' + l.id + '">Delete</button>');
      }
      if (l.status === 'matched' && cn.match && !l.journal_id) tools.push('<button class="link-btn small" type="button" data-unmatch="' + l.id + '">Unmatch</button>');
      if (l.status === 'matched' && l.journal_id && cn.record) tools.push('<button class="link-btn small" type="button" data-undo="' + l.id + '">Undo entry</button>');
      if (l.status === 'ignored' && cn.match) tools.push('<button class="link-btn small" type="button" data-unignore="' + l.id + '">Bring back</button>');

      let sub = '';
      if (l.status === 'matched') {
        sub = l.matches.map((m) => '<div class="small muted">↳ <a href="journals/' + m.journal_id + '" class="mono">' + esc(m.journal_no || '#' + m.journal_id) + '</a> · '
          + esc(fmtDay(m.date)) + (m.reference ? ' · ' + esc(m.reference) : '') + ' · <span class="num">' + money(m.net_cents) + '</span></div>').join('')
          + (l.journal_id ? '<div class="small ok-text">Entry recorded from this line</div>' : '');
      } else if (l.status === 'ignored') {
        sub = '<div class="small muted">Set aside' + (l.ignore_reason ? ': ' + esc(l.ignore_reason) : '') + '</div>';
      }
      return '<tr data-line="' + l.id + '"' + (sel.line === l.id ? ' style="background:var(--brand-softer)"' : '') + '>'
        + '<td style="width:34px">' + pick + '</td>'
        + '<td class="nowrap small">' + esc(fmtDay(l.txn_date, 'short')) + '</td>'
        + '<td style="min-width:160px"><div>' + esc(l.description || '—') + '</div>'
        + (l.reference ? '<div class="small muted mono">' + esc(l.reference) + '</div>' : '') + sub
        + (tools.length ? '<div class="row gap-2 mt-1">' + tools.join('') + '</div>' : '') + '</td>'
        + '<td class="num">' + signed(l.amount_cents) + '</td>'
        + '<td class="nowrap">' + statusBadge(l.status, STATUS_LABEL[l.status]) + '</td></tr>';
    }).join('') : '<tr><td colspan="5">' + emptyState(d.lines.length ? 'check-circle' : 'table', d.lines.length ? 'Nothing here' : 'No lines yet',
      d.lines.length ? 'Choose another tab.' : (cn.edit ? 'Add the lines by hand or import the bank\'s CSV file.' : '')) + '</td></tr>';
  };

  // ── the book lines ────────────────────────────────────────────────────
  const paintBooks = () => {
    const open = d.statement.status === 'open';
    const cut = addDays(d.figures.recon_start, -d.window_days);
    const line = sel.line ? lineById(sel.line) : null;
    const q = view.q.toLowerCase();
    const older = d.book_lines.filter((b) => b.date < cut).length;
    let rows = d.book_lines.filter((b) => (view.older || b.date >= cut || sel.books.has(b.line_id)));
    if (line) rows = rows.filter((b) => (line.amount_cents > 0) === (b.net_cents > 0) || sel.books.has(b.line_id));
    if (q) {
      rows = rows.filter((b) => [b.journal_no, b.reference, b.description, b.memo, b.party, toMajor(Math.abs(b.net_cents))]
        .some((x) => String(x || '').toLowerCase().includes(q)) || sel.books.has(b.line_id));
    }
    if (line) {
      const near = (b) => Math.abs((Date.parse(b.date) - Date.parse(line.txn_date)) / 86400000);
      rows = rows.slice().sort((a, b) => (Number(b.net_cents === line.amount_cents) - Number(a.net_cents === line.amount_cents)) || (near(a) - near(b)));
    }
    qs('[data-book-count]', root).textContent = open ? d.book_lines.length + ' unmatched' + (older ? ' · ' + older + ' older' : '') : '';
    qs('[data-older]', root).closest('label').hidden = !older;

    if (!open) {
      bbody.innerHTML = '<tr><td colspan="4">' + emptyState('lock-key', 'Reconciled', 'A reconciled statement no longer takes matches.') + '</td></tr>';
      return;
    }
    bbody.innerHTML = rows.length ? rows.map((b) => {
      const on = sel.books.has(b.line_id);
      const same = line && b.net_cents === line.amount_cents;
      return '<tr data-book="' + b.line_id + '"' + (on ? ' style="background:var(--brand-softer)"' : '') + '>'
        + '<td style="width:34px">' + (d.can.match ? '<input type="checkbox" value="' + b.line_id + '" aria-label="Choose this book line"' + (on ? ' checked' : '') + '>' : '') + '</td>'
        + '<td class="nowrap small">' + esc(fmtDay(b.date, 'short')) + '</td>'
        + '<td style="min-width:160px"><div><a href="journals/' + b.journal_id + '" class="mono">' + esc(b.journal_no || '#' + b.journal_id) + '</a> '
        + (same ? '<span class="badge badge-ok">Same amount</span>' : '') + '</div>'
        + '<div class="small muted truncate" style="max-width:320px">' + esc([b.reference, b.description].filter(Boolean).join(' · ')) + '</div></td>'
        + '<td class="num">' + signed(b.net_cents) + '</td></tr>';
    }).join('') : '<tr><td colspan="4">' + emptyState('magnifying-glass', 'No book lines here',
      line ? 'No unmatched ' + (line.amount_cents > 0 ? 'debits' : 'credits') + ' fit. The books may not have this line yet: record an entry for it.' : 'Every book line up to the statement is matched.') + '</td></tr>';
  };

  // ── the selection ─────────────────────────────────────────────────────
  const paintSel = () => {
    const line = sel.line ? lineById(sel.line) : null;
    if (!line || line.status !== 'unmatched') { sel.line = 0; }
    const total = [...sel.books].reduce((n, lid) => n + ((bookById(lid) || {}).net_cents || 0), 0);
    if (!sel.line && !sel.books.size) { selbar.hidden = true; return; }
    selbar.hidden = false;
    const l = sel.line ? lineById(sel.line) : null;
    const diff = l ? l.amount_cents - total : 0;
    qs('[data-sel-text]', root).innerHTML = (l
      ? '<b>' + esc(fmtDay(l.txn_date, 'short')) + ' · ' + esc(l.description || '') + (l.reference ? ' · ' + esc(l.reference) : '') + '</b> <span class="num">' + signed(l.amount_cents) + '</span>'
      : '<span class="muted">Choose a bank line on the left.</span>')
      + ' · ' + sel.books.size + ' book line' + (sel.books.size === 1 ? '' : 's') + ' <span class="num">' + money(total) + '</span>'
      + (l && sel.books.size ? (diff === 0 ? ' · <span class="ok-text">They agree</span>' : ' · <span class="err-text">Short by ' + money(Math.abs(diff)) + '</span>') : '');
    const b = (act, label, cls, dis) => '<button class="btn btn-sm ' + cls + '" type="button" data-sel="' + act + '"' + (dis ? ' disabled' : '') + '>' + label + '</button>';
    qs('[data-sel-actions]', root).innerHTML = [
      b('clear', 'Clear', 'btn-ghost', false),
      l ? b('ignore', 'Set aside…', 'btn-secondary', false) : '',
      l && d.can.record ? b('record', 'Record an entry…', 'btn-secondary', false) : '',
      b('match', 'Match', '', !(l && sel.books.size && diff === 0)),
    ].join('');
  };

  const paint = () => {
    paintHead();
    paintLines();
    paintBooks();
    paintSel();
  };

  /** After an action: the server's statement replaces ours. */
  const apply = (r, keepSel) => {
    d = r;
    if (!keepSel) { sel.line = 0; sel.books.clear(); }
    paint();
    if (r.notice) toast(r.notice);
  };

  // ── actions ───────────────────────────────────────────────────────────
  const act = async (btn, fn) => {
    busy(btn, true);
    try {
      apply(await fn());
    } catch (err) {
      toast(err.message, { kind: 'error' });
    } finally {
      busy(btn, false);
    }
  };

  const field = (fid, label, control, name, hint) => '<div class="field"><label class="label" for="' + fid + '">' + label + '</label>' + control
    + (hint ? '<div class="hint">' + hint + '</div>' : '') + '<div class="error" data-error-for="' + name + '"></div></div>';
  const formEnd = (label) => '<div class="row gap-2" style="justify-content:flex-end"><button class="btn btn-secondary" type="button" data-x>Cancel</button>'
    + '<button class="btn" type="submit">' + label + '</button></div>';

  /** A modal form: fill it, submit it, and the statement that comes back is painted. */
  const modalForm = (title, size, html, submit) => {
    const m = openModal({ title, size, body: '<form class="stack" data-f novalidate><div class="alert alert-err" data-banner hidden></div>' + html + '</form>' });
    const f = qs('[data-f]', m.el);
    qs('[data-x]', f).addEventListener('click', () => m.close());
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErrors(f);
      const b = qs('button[type="submit"]', f);
      busy(b, true);
      try {
        const r = await submit(f.elements, f);
        if (r) { m.close(); apply(r); }
      } catch (err) { formError(f, err); } finally { busy(b, false); }
    });
    return { m, f };
  };

  /** Add a line by hand, or change an unmatched one. */
  const lineForm = (l) => {
    const x = l || { txn_date: d.statement.statement_date, description: '', reference: '', amount_cents: 0 };
    const [min, max] = d.date_range;
    modalForm(l ? 'Change the line' : 'Add a line', 'sm',
        field('bl-date', 'Date', '<input class="input" type="date" id="bl-date" name="txn_date" min="' + min + '" max="' + max + '" value="' + esc(x.txn_date) + '">', 'txn_date')
      + field('bl-desc', 'Description', '<input class="input" id="bl-desc" name="description" maxlength="255" value="' + esc(x.description) + '" autocomplete="off" placeholder="DEPOSIT, CHECK ENCASHED, SERVICE CHARGE">', 'description')
      + field('bl-ref', 'Reference <span class="opt">(optional)</span>', '<input class="input" id="bl-ref" name="reference" maxlength="60" value="' + esc(x.reference || '') + '" autocomplete="off" placeholder="Cheque or deposit slip number">', 'reference')
      + '<div class="form-grid">'
      + field('bl-dep', 'Deposit', '<input class="input" id="bl-dep" name="deposit" inputmode="decimal" autocomplete="off" value="' + (x.amount_cents > 0 ? esc(toMajor(x.amount_cents)) : '') + '">', 'deposit', 'Money into the account.')
      + field('bl-wd', 'Withdrawal', '<input class="input" id="bl-wd" name="withdrawal" inputmode="decimal" autocomplete="off" value="' + (x.amount_cents < 0 ? esc(toMajor(-x.amount_cents)) : '') + '">', 'withdrawal', 'Money out of it.')
      + '</div><div class="error" data-error-for="amount_cents"></div>'
      + formEnd(l ? 'Save' : 'Add line'),
      async (el, f) => {
        const dep = el.deposit.value.trim() ? toCents(el.deposit.value) : 0;
        const wd = el.withdrawal.value.trim() ? toCents(el.withdrawal.value) : 0;
        const errs = {};
        if (!el.txn_date.value) errs.txn_date = 'Enter the date shown on the statement.';
        if (dep === null || wd === null) errs.amount_cents = 'Type the amount like 1,234.56.';
        else if (dep && wd) errs.amount_cents = 'Enter either a deposit or a withdrawal, not both.';
        else if (!dep && !wd) errs.amount_cents = 'Enter the deposit or the withdrawal.';
        if (Object.keys(errs).length) { setErrors(f, errs); return null; }
        const body = { txn_date: el.txn_date.value, description: el.description.value.trim(), reference: el.reference.value.trim(), amount_cents: dep - wd };
        return l ? api.put('/banking/lines/' + l.id, body) : api.post('/banking/statements/' + id + '/lines', body);
      });
  };

  const statementForm = () => {
    const s = d.statement;
    modalForm('Edit the statement', 'sm',
        field('st-date', 'Statement date', '<input class="input" type="date" id="st-date" name="statement_date" value="' + esc(s.statement_date) + '">', 'statement_date',
          'It can change only while no line is matched or set aside.')
      + field('st-open', 'Opening balance', '<input class="input" id="st-open" name="opening" inputmode="decimal" autocomplete="off" value="' + esc(toMajor(s.opening_balance_cents)) + '">', 'opening_balance_cents')
      + field('st-close', 'Closing balance', '<input class="input" id="st-close" name="closing" inputmode="decimal" autocomplete="off" value="' + esc(toMajor(s.closing_balance_cents)) + '">', 'closing_balance_cents')
      + formEnd('Save'),
      async (el, f) => {
        const open = toCents(el.opening.value, true);
        const close = toCents(el.closing.value, true);
        const errs = {};
        if (open === null) errs.opening_balance_cents = 'Type the opening balance like 199,892.24.';
        if (close === null) errs.closing_balance_cents = 'Type the closing balance like 961,895.71.';
        if (Object.keys(errs).length) { setErrors(f, errs); return null; }
        return api.put('/banking/statements/' + id, { statement_date: el.statement_date.value, opening_balance_cents: open, closing_balance_cents: close });
      });
  };

  /** Post the entry the books do not have yet (a charge, interest) and match it. */
  const recordForm = (l) => {
    const e = d.entry;
    const charge = l.amount_cents < 0;
    const amt = Math.abs(l.amount_cents);
    const TYPES = { expense: 'Expenses', income: 'Income', asset: 'Assets', liability: 'Liabilities', equity: 'Equity' };
    const order = charge ? ['expense', 'asset', 'liability', 'income', 'equity'] : ['income', 'asset', 'liability', 'expense', 'equity'];
    const opts = (selected, blank) => (blank ? '<option value="">' + blank + '</option>' : '') + order.map((t) => {
      const list = e.accounts.filter((a) => a.type === t);
      return list.length ? '<optgroup label="' + TYPES[t] + '">' + list.map((a) => '<option value="' + a.id + '"' + (a.id === selected ? ' selected' : '') + '>'
        + esc(a.code + ' · ' + a.name) + '</option>').join('') + '</optgroup>' : '';
    }).join('');
    const grossUp = (net, bp) => { const n = BigInt(net) * 10000n; const dd = BigInt(10000 - bp); return Number((n * 2n + dd) / (2n * dd)); };
    const pct = e.final_tax_bp / 100;
    const cash = d.bank.ledger_code + ' · ' + d.bank.ledger_name;

    const { f } = modalForm(charge ? 'Record the bank charge' : 'Record the bank credit', 'lg',
        '<p class="muted mb-0">' + esc(fmtDay(l.txn_date)) + ' · ' + esc(l.description || '') + (l.reference ? ' · ' + esc(l.reference) : '') + ' · <b class="num">' + money(l.amount_cents) + '</b>. '
        + 'It posts to the ' + (charge ? 'cash disbursements' : 'cash receipts') + ' book, dated like the bank line, and is matched to it at once.</p>'
        + '<div class="form-grid">'
        + field('re-acct', charge ? 'Charge to' : 'Credit to', '<select class="select" id="re-acct" name="account_id">' + opts(charge ? e.default_charges_id : e.default_interest_id, 'Choose an account') + '</select>', 'account_id')
        + (e.departments.length ? field('re-dept', 'Department <span class="opt">(optional)</span>', '<select class="select" id="re-dept" name="department_id"><option value="">—</option>'
          + e.departments.map((x) => '<option value="' + x.id + '">' + esc(x.code + ' · ' + x.name) + '</option>').join('') + '</select>', 'department_id') : '')
        + '<div class="field span-2"><label class="label" for="re-desc">Description <span class="opt">(optional)</span></label><input class="input" id="re-desc" name="description" maxlength="500" autocomplete="off" placeholder="'
        + esc((charge ? 'Bank charge' : 'Bank credit') + ' per the ' + d.bank.bank_name + ' statement of ' + d.statement.statement_date + ': ' + (l.description || '')) + '"></div>'
        + '</div>'
        + (charge ? '' : '<label class="check"><input type="checkbox" name="final_tax"> <span>The bank withheld final tax on this interest (' + pct + '%)</span></label>'
          + '<div class="form-grid" data-tax hidden>'
          + field('re-tax-acct', 'Final tax account', '<select class="select" id="re-tax-acct" name="final_tax_account_id">' + opts(0, 'Choose an account') + '</select>', 'final_tax_account_id')
          + field('re-tax', 'Final tax withheld', '<input class="input" id="re-tax" name="final_tax" inputmode="decimal" autocomplete="off" value="' + esc(toMajor(grossUp(amt, e.final_tax_bp) - amt)) + '">', 'final_tax_cents',
            'Worked out as ' + pct + '% of the interest before tax. Change it to what the bank shows.')
          + '</div>')
        + '<div class="card card-flush"><div class="table-wrap"><table class="table table-compact"><thead><tr><th>What goes into the books</th><th class="num">Debit</th><th class="num">Credit</th></tr></thead><tbody data-preview></tbody></table></div></div>'
        + formEnd('Post and match'),
      async (el, form) => {
        const errs = {};
        const body = { account_id: Number(el.account_id.value) || 0, description: el.description.value.trim() };
        if (el.department_id) body.department_id = Number(el.department_id.value) || 0;
        if (!body.account_id) errs.account_id = 'Choose the account.';
        if (!charge && el.final_tax.checked) {
          const tax = toCents(qs('#re-tax', form).value);
          body.final_tax = true;
          body.final_tax_account_id = Number(el.final_tax_account_id.value) || 0;
          if (!body.final_tax_account_id) errs.final_tax_account_id = 'Choose the account for the final tax.';
          if (!tax || tax < 0) errs.final_tax_cents = 'Type the tax withheld, like 11.30.';
          else body.final_tax_cents = tax;
        }
        if (Object.keys(errs).length) { setErrors(form, errs); return null; }
        return api.post('/banking/lines/' + l.id + '/record', body);
      });

    const name = (sid) => { const a = e.accounts.find((x) => x.id === Number(sid)); return a ? a.code + ' · ' + a.name : 'the chosen account'; };
    const preview = () => {
      const el = f.elements;
      const taxOn = !charge && el.final_tax.checked;
      const box = qs('[data-tax]', f);
      if (box) box.hidden = !taxOn;
      const tax = taxOn ? (toCents(qs('#re-tax', f).value) || 0) : 0;
      const row = (label, dr, cr) => '<tr><td>' + esc(label) + '</td><td class="num">' + (dr ? money(dr) : '') + '</td><td class="num">' + (cr ? money(cr) : '') + '</td></tr>';
      qs('[data-preview]', f).innerHTML = charge
        ? row(name(el.account_id.value), amt, 0) + row(cash, 0, amt)
        : row(cash, amt, 0) + (tax ? row(name(el.final_tax_account_id.value), tax, 0) : '') + row(name(el.account_id.value), 0, amt + tax);
    };
    f.addEventListener('change', preview);
    f.addEventListener('input', preview);
    preview();
  };

  qs('[data-actions]', root).addEventListener('click', async (e) => {
    const b = e.target.closest('[data-do]');
    if (!b || !d) return;
    const s = d.statement;
    switch (b.dataset.do) {
      case 'auto': act(b, () => api.post('/banking/statements/' + id + '/auto-match')); break;
      case 'add-line': lineForm(null); break;
      case 'edit-statement': statementForm(); break;
      case 'import': importForm(); break;
      case 'reconcile':
        if (!(await confirmDialog({ title: 'Reconcile this statement?', body: 'Its lines and matches are locked from then on. An accountant can reopen it while it is the latest reconciled statement.', confirmLabel: 'Reconcile' }))) return;
        act(b, () => api.post('/banking/statements/' + id + '/reconcile'));
        break;
      case 'reopen': {
        const reason = await promptDialog({ title: 'Reopen this statement', label: 'Why it needs to change', multiline: true, maxlength: 300, required: true, confirmLabel: 'Reopen' });
        if (reason === null) return;
        act(b, () => api.post('/banking/statements/' + id + '/reopen', { reason }));
        break;
      }
      case 'delete':
        if (!(await confirmDialog({ title: 'Delete the statement of ' + fmtDay(s.statement_date) + '?', body: 'Its ' + d.lines.length + ' lines go with it. Nothing in the books changes.', confirmLabel: 'Delete', danger: true }))) return;
        busy(b, true);
        try {
          await api.del('/banking/statements/' + id);
          toast('Statement deleted.');
          ctx.navigate('/banking');
        } catch (err) { toast(err.message, { kind: 'error' }); busy(b, false); }
        break;
      default: break;
    }
  });

  selbar.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-sel]');
    if (!b) return;
    const l = sel.line ? lineById(sel.line) : null;
    switch (b.dataset.sel) {
      case 'clear': sel.line = 0; sel.books.clear(); paintLines(); paintBooks(); paintSel(); break;
      case 'match': if (l) act(b, () => api.post('/banking/lines/' + l.id + '/match', { journal_line_ids: [...sel.books] })); break;
      case 'record': if (l) recordForm(l); break;
      case 'ignore': {
        if (!l) return;
        const reason = await promptDialog({ title: 'Set this line aside', label: 'Why', multiline: true, maxlength: 300, required: true, confirmLabel: 'Set aside',
          hint: 'For a bank error and its correction. The reason is kept in the audit trail; the line counts as a bank error on the reconciliation until the bank corrects it.' });
        if (reason === null) return;
        act(b, () => api.post('/banking/lines/' + l.id + '/ignore', { reason }));
        break;
      }
      default: break;
    }
  });

  tbody.addEventListener('click', async (e) => {
    const t = e.target;
    const pick = (attr) => { const x = t.closest('[' + attr + ']'); return x ? lineById(Number(x.getAttribute(attr))) : null; };
    let l;
    if ((l = pick('data-line-edit'))) { lineForm(l); return; }
    if ((l = pick('data-line-del'))) {
      if (!(await confirmDialog({ title: 'Delete this line?', body: fmtDay(l.txn_date) + ' · ' + (l.description || '') + ' · ' + money(l.amount_cents), confirmLabel: 'Delete', danger: true }))) return;
      act(t, () => api.del('/banking/lines/' + l.id));
      return;
    }
    if ((l = pick('data-unmatch'))) { act(t, () => api.post('/banking/lines/' + l.id + '/unmatch')); return; }
    if ((l = pick('data-unignore'))) { act(t, () => api.post('/banking/lines/' + l.id + '/unignore')); return; }
    if ((l = pick('data-undo'))) {
      const reason = await promptDialog({ title: 'Undo ' + (l.journal_no || 'the entry'), label: 'Why', multiline: true, maxlength: 250, required: true, confirmLabel: 'Undo entry', danger: true,
        hint: 'A reversing entry is posted on the same date, and the bank line is unmatched again.' });
      if (reason === null) return;
      act(t, () => api.post('/banking/lines/' + l.id + '/undo-record', { reason }));
      return;
    }
    const tr = t.closest('tr[data-line]');
    if (tr && !t.closest('a, button, input') && d.can.match) {
      const x = lineById(Number(tr.dataset.line));
      if (x && x.status === 'unmatched') { sel.line = sel.line === x.id ? 0 : x.id; paintLines(); paintBooks(); paintSel(); }
    }
  });

  bbody.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-book]');
    if (!tr || e.target.closest('a, input') || !d.can.match || d.statement.status !== 'open') return;
    const lid = Number(tr.dataset.book);
    if (sel.books.has(lid)) sel.books.delete(lid); else sel.books.add(lid);
    paintBooks();
    paintSel();
  });

  // ── importing the bank's CSV file ─────────────────────────────────────
  const importForm = () => {
    const [min, max] = d.date_range;
    const st = { rows: [], header: true, mode: 'amount', fmt: 'Y-m-d', map: {}, file: '' };
    const m = openModal({
      title: 'Import the bank\'s CSV file', size: 'xl',
      body: '<div class="stack"><div class="alert alert-err" data-banner hidden></div>'
        + '<label class="dropzone" for="im-file"><span data-icon="upload-simple" data-icon-size="28"></span><div><b data-file-name>Choose the CSV file</b></div>'
        + '<div class="small">The file stays on this computer until you import; only the rows are sent.</div></label>'
        + '<input type="file" id="im-file" accept=".csv,.txt,text/csv" hidden>'
        + '<div class="stack" data-map hidden></div>'
        + '<div data-preview></div>'
        + '<div class="row gap-2" style="justify-content:flex-end"><button class="btn btn-secondary" type="button" data-x>Cancel</button>'
        + '<button class="btn" type="button" data-go disabled>Import</button></div></div>',
    });
    const el = m.el;
    const banner = qs('[data-banner]', el);
    const go = qs('[data-go]', el);
    qs('[data-x]', el).addEventListener('click', () => m.close());

    const cols = () => (st.rows[0] || []).map((h, i) => (st.header ? String(h || '').trim() || 'Column ' + (i + 1) : 'Column ' + (i + 1)));
    const dataRows = () => st.rows.slice(st.header ? 1 : 0).map((r, i) => ({ r, n: i + 1 + (st.header ? 1 : 0) }));
    const cell = (r, k) => (st.map[k] >= 0 ? String(r[st.map[k]] == null ? '' : r[st.map[k]]).trim() : '');

    const guess = () => {
      const hs = (st.rows[0] || []).map((h) => String(h || '').toLowerCase());
      const find = (re) => hs.findIndex((h) => re.test(h));
      const looksLikeData = (st.rows[0] || []).some((x) => parseAmount(x) !== null && /\d/.test(String(x))) && (st.rows[0] || []).some((x) => FORMATS.some(([f]) => parseDate(x, f)));
      st.header = !looksLikeData;
      if (!st.header) { st.map = { date: 0, description: 1, reference: -1, amount: 2, withdrawal: -1, deposit: -1 }; return; }
      st.map = { date: find(/date/), description: find(/desc|particular|detail|narrat|remark|transaction/), reference: find(/ref|check|cheque|chq|slip/),
        amount: find(/amount/), withdrawal: find(/withdraw|debit|^dr\b/), deposit: find(/deposit|credit|^cr\b/) };
      st.mode = st.map.amount < 0 && st.map.withdrawal >= 0 && st.map.deposit >= 0 ? 'split' : 'amount';
    };
    const guessFormat = () => {
      let best = st.fmt;
      let score = -1;
      FORMATS.forEach(([f]) => {
        const n = dataRows().filter(({ r }) => parseDate(cell(r, 'date'), f)).length;
        if (n > score) { score = n; best = f; }
      });
      st.fmt = best;
    };

    /** Every row as the server will read it. */
    const read = () => dataRows().map(({ r, n }) => {
      const raw = { row: n, date: cell(r, 'date'), description: cell(r, 'description'), reference: cell(r, 'reference') };
      const bad = [];
      const date = parseDate(raw.date, st.fmt);
      if (!raw.date) bad.push('no date');
      else if (!date) bad.push('“' + raw.date + '” is not a date in that format');
      else if (date > max) bad.push('after the statement date');
      else if (date < min) bad.push('before this statement\'s period');
      if (raw.reference.length > 60) bad.push('reference over 60 characters');
      let amount = null;
      if (st.mode === 'amount') {
        raw.amount = cell(r, 'amount');
        amount = parseAmount(raw.amount);
        if (!raw.amount) bad.push('no amount'); else if (amount === null) bad.push('“' + raw.amount + '” is not an amount'); else if (amount === 0) bad.push('the amount is zero');
      } else {
        raw.withdrawal = cell(r, 'withdrawal');
        raw.deposit = cell(r, 'deposit');
        const w = raw.withdrawal ? parseAmount(raw.withdrawal) : 0;
        const dp = raw.deposit ? parseAmount(raw.deposit) : 0;
        if (w === null || dp === null) bad.push('an amount cannot be read');
        else if (w && dp) bad.push('both a withdrawal and a deposit');
        else if (!w && !dp) bad.push('no amount');
        else amount = Math.abs(dp) - Math.abs(w);
      }
      return { raw, date, amount, bad };
    });

    const paintMap = () => {
      const c = cols();
      const sel = (k, label) => '<div class="field"><label class="label" for="im-' + k + '">' + label + '</label><select class="select" id="im-' + k + '" data-k="' + k + '">'
        + '<option value="-1">—</option>' + c.map((h, i) => '<option value="' + i + '"' + (st.map[k] === i ? ' selected' : '') + '>' + esc(h) + '</option>').join('') + '</select></div>';
      qs('[data-map]', el).innerHTML = '<label class="check"><input type="checkbox" data-header' + (st.header ? ' checked' : '') + '> <span>The first row holds the column names</span></label>'
        + '<div class="grid-3">' + sel('date', 'Date') + sel('description', 'Description') + sel('reference', 'Reference') + '</div>'
        + '<div class="segmented" role="group" aria-label="Amounts"><button type="button" data-mode="amount" aria-pressed="' + (st.mode === 'amount') + '">One amount column (− for withdrawals)</button>'
        + '<button type="button" data-mode="split" aria-pressed="' + (st.mode === 'split') + '">Withdrawal and deposit columns</button></div>'
        + '<div class="grid-3">' + (st.mode === 'amount' ? sel('amount', 'Amount') : sel('withdrawal', 'Withdrawals (debit)') + sel('deposit', 'Deposits (credit)'))
        + '<div class="field"><label class="label" for="im-fmt">Dates are written</label><select class="select" id="im-fmt" data-fmt>'
        + FORMATS.map(([f, l]) => '<option value="' + f + '"' + (st.fmt === f ? ' selected' : '') + '>' + esc(l) + '</option>').join('') + '</select></div></div>';
      qs('[data-map]', el).hidden = false;
    };

    const paintPreview = () => {
      const all = read();
      const good = all.filter((x) => !x.bad.length);
      const bad = all.length - good.length;
      qs('[data-preview]', el).innerHTML = '<p class="small ' + (bad ? 'warn-text' : 'muted') + '">' + all.length + ' rows read · ' + good.length + ' can be imported'
        + (bad ? ' · ' + bad + ' with problems are left out' : '') + '. Dates must fall between ' + esc(fmtDay(min)) + ' and ' + esc(fmtDay(max))
        + '. A line already on the statement (same date, amount and reference) is skipped.</p>'
        + '<div class="table-wrap" style="max-height:340px;overflow-y:auto"><table class="table table-compact"><thead><tr><th>Row</th><th>Date</th><th>Description</th><th>Reference</th><th class="num">Amount</th><th>Problem</th></tr></thead><tbody>'
        + all.slice(0, 300).map((x) => '<tr' + (x.bad.length ? ' style="background:var(--err-bg)"' : '') + '><td class="num small">' + x.raw.row + '</td>'
          + '<td class="nowrap small">' + esc(x.date ? fmtDay(x.date, 'short') : x.raw.date) + '</td><td class="small">' + esc(x.raw.description) + '</td>'
          + '<td class="small mono">' + esc(x.raw.reference) + '</td><td class="num">' + (x.amount !== null ? signed(x.amount) : '') + '</td>'
          + '<td class="small err-text">' + esc(x.bad.join('; ')) + '</td></tr>').join('')
        + '</tbody></table></div>' + (all.length > 300 ? '<p class="small muted">Showing the first 300 rows.</p>' : '');
      go.disabled = !good.length;
      go.textContent = good.length ? 'Import ' + good.length + ' row' + (good.length === 1 ? '' : 's') : 'Import';
      return good;
    };

    qs('#im-file', el).addEventListener('change', (e) => {
      const file = e.target.files && e.target.files[0];
      if (!file) return;
      banner.hidden = true;
      if (file.size > 5 * 1024 * 1024) { banner.textContent = 'That file is over 5 MB. A statement file is much smaller; check that it is the right file.'; banner.hidden = false; return; }
      const fr = new FileReader();
      fr.onload = () => {
        st.rows = parseCsv(String(fr.result || ''));
        st.file = file.name;
        qs('[data-file-name]', el).textContent = file.name + ' · ' + st.rows.length + ' rows';
        if (!st.rows.length) { banner.textContent = 'The file is empty.'; banner.hidden = false; return; }
        guess();
        guessFormat();
        paintMap();
        paintPreview();
      };
      fr.onerror = () => { banner.textContent = 'The file could not be read.'; banner.hidden = false; };
      fr.readAsText(file);
    });
    qs('[data-map]', el).addEventListener('change', (e) => {
      const t = e.target;
      if (t.matches('[data-header]')) { st.header = t.checked; paintMap(); }
      else if (t.matches('[data-k]')) st.map[t.dataset.k] = Number(t.value);
      else if (t.matches('[data-fmt]')) st.fmt = t.value;
      paintPreview();
    });
    qs('[data-map]', el).addEventListener('click', (e) => {
      const b = e.target.closest('[data-mode]');
      if (!b) return;
      st.mode = b.dataset.mode;
      paintMap();
      paintPreview();
    });
    go.addEventListener('click', async () => {
      const good = paintPreview();
      if (!good.length) return;
      banner.hidden = true;
      busy(go, true);
      try {
        const r = await api.post('/banking/statements/' + id + '/import', { date_format: st.fmt, file_name: st.file, rows: good.map((x) => x.raw) });
        m.close();
        apply(r);
      } catch (err) {
        const rows = err.data && err.data.row_errors ? ' ' + err.data.row_errors.slice(0, 5).join(' ') : '';
        banner.textContent = err.message + (rows && !err.message.includes(rows.trim()) ? rows : '');
        banner.hidden = false;
      } finally { busy(go, false); }
    });
  };

  // ── wiring ────────────────────────────────────────────────────────────
  qs('[data-tabs]', root).addEventListener('click', (e) => {
    const t = e.target.closest('[data-tab]');
    if (!t) return;
    view.tab = t.dataset.tab;
    paintLines();
  });
  tbody.addEventListener('change', (e) => {
    if (e.target.name !== 'bs-line') return;
    sel.line = Number(e.target.value);
    paintLines();
    paintBooks();
    paintSel();
  });
  bbody.addEventListener('change', (e) => {
    if (e.target.type !== 'checkbox') return;
    const lid = Number(e.target.value);
    if (e.target.checked) sel.books.add(lid); else sel.books.delete(lid);
    paintBooks();
    paintSel();
  });
  qs('[data-book-q]', root).addEventListener('input', (e) => { view.q = e.target.value.trim(); paintBooks(); });
  qs('[data-older]', root).addEventListener('change', (e) => { view.older = e.target.checked; paintBooks(); });

  try {
    d = await api.get('/banking/statements/' + id, null, { signal: ctx.signal });
  } catch (err) {
    if (ctx.signal.aborted) return;
    stateEl.innerHTML = emptyState(err.isNotFound ? 'magnifying-glass' : 'warning', err.isNotFound ? 'That statement does not exist' : 'Could not load the statement',
      err.isNotFound ? '' : err.message, '<a class="btn btn-secondary" href="banking">Back to Banking</a>');
    return;
  }
  if (!d.lines.some((l) => l.status === 'unmatched')) view.tab = '';
  stateEl.innerHTML = '';
  body.hidden = false;
  paint();
}
