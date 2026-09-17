/**
 * aging.js — how old the receivables and payables are
 *
 * GenericPOS Accounting · ES module · every role
 *
 *   /reports/aging?side=ar|ap&as_of=
 *
 * Each customer's (or supplier's) open documents, put in buckets by how far
 * past their due date they are, with anything not applied yet in its own
 * column. The total is set against the control account's balance in the
 * ledger at the same date, and any difference — a line posted to the control
 * account by hand — is listed underneath, because the two must agree.
 */

import { api } from './api.js';
import { todayYmd, money } from './store.js';
import { qs, esc, emptyState } from './ui.js';
import { amt, sheetHead, sheetFoot, asOfText, syncQuery, exportCsv } from './report-kit.js';

const YMD = /^\d{4}-\d{2}-\d{2}$/;

export async function mount(root, ctx) {
  const today = todayYmd();
  const q = ctx.query;
  const state = {
    side: q.get('side') === 'ap' ? 'ap' : 'ar',
    as_of: YMD.test(q.get('as_of') || '') ? q.get('as_of') : today,
    detail: q.get('detail') === '1',
  };
  const sheet = qs('[data-sheet]', root);
  const form = qs('[data-filters]', root);
  form.elements.as_of.value = state.as_of;
  form.elements.detail.checked = state.detail;

  const paintSides = () => qs('[data-sides]', root).querySelectorAll('[data-side]').forEach((b) => {
    b.classList.toggle('is-on', b.dataset.side === state.side);
    b.setAttribute('aria-pressed', b.dataset.side === state.side ? 'true' : 'false');
  });

  const render = (d) => {
    const who = d.side === 'ar' ? 'Customer' : 'Supplier';
    ctx.setTitle(d.title);
    qs('[data-head]', root).textContent = d.title;
    qs('[data-sub]', root).textContent = asOfText(d.as_of) + ' · ' + d.rows.length + ' ' + (d.rows.length === 1 ? who.toLowerCase() : who.toLowerCase() + 's')
      + ' · ' + money(d.totals.net_cents) + ' open';

    const cols = d.labels.length;
    const item = (i) => '<tr class="l-sub"><td class="l" style="padding-left:2em">' + esc((i.kind === 'settlement' ? 'Unapplied ' : '') + (i.number || ''))
      + '<span class="sub">' + esc([i.date, i.due_date ? 'due ' + i.due_date : '', i.days_overdue ? i.days_overdue + ' days overdue' : ''].filter(Boolean).join(' · ')) + '</span></td>'
      + d.labels.map((x, n) => '<td class="n">' + (i.bucket === n ? amt(i.open_cents) : '') + '</td>').join('')
      + '<td class="n">' + (i.bucket === null ? amt(i.open_cents) : '') + '</td><td class="n"></td></tr>';

    const rows = d.rows.map((r) => '<tr class="l-line"><td class="l"><a href="reports/customer-statement?side=' + d.side + '&contact_id=' + r.contact_id + '">'
      + '<span class="code">' + esc(r.code) + '</span>' + esc(r.name) + '</a>' + (r.is_active ? '' : ' <span class="badge">inactive</span>') + '</td>'
      + r.buckets.map((b) => '<td class="n">' + amt(b) + '</td>').join('')
      + '<td class="n">' + amt(-r.unapplied_cents) + '</td><td class="n">' + amt(r.net_cents) + '</td></tr>'
      + (state.detail ? r.items.map(item).join('') : '')).join('');

    const tie = d.ledger || {};
    const diff = tie.difference_cents || 0;
    const tieBlock = '<table class="stmt" style="margin-top:var(--s5);width:auto"><tbody>'
      + '<tr><td class="l">Total open, ' + esc(d.side === 'ar' ? 'customer by customer' : 'supplier by supplier') + '</td><td class="n">' + amt(d.totals.net_cents) + '</td></tr>'
      + '<tr><td class="l">' + esc((tie.accounts || []).map((a) => a.code + ' ' + a.name).join(', ') || 'The control account') + ' in the ledger</td>'
      + '<td class="n">' + amt(tie.control_cents || 0) + '</td></tr>'
      + '<tr class="' + (diff ? 'l-grand' : 'l-total') + '"><td class="l">' + (diff ? 'Difference — look into it' : 'They agree') + '</td><td class="n">' + amt(diff) + '</td></tr>'
      + '</tbody></table>'
      + (diff && (tie.lines || []).length
        ? '<p class="small muted" style="margin-top:var(--s3)">Posted to the control account by hand, outside an invoice or a payment:</p>'
          + '<div class="table-wrap"><table class="stmt"><thead><tr><th class="l">Date</th><th class="l">Entry</th><th class="l">Particulars</th><th>Amount</th></tr></thead><tbody>'
          + tie.lines.map((l) => '<tr><td class="d">' + esc(l.date) + '</td><td class="d"><a class="code" href="journals/' + l.journal_id + '">' + esc(l.journal_no) + '</a></td>'
            + '<td>' + esc(l.description || '') + '</td><td class="n">' + amt(l.net_cents) + '</td></tr>').join('') + '</tbody></table></div>'
        : '');

    sheet.innerHTML = sheetHead(d.letterhead, d.title, [asOfText(d.as_of), 'By days past the due date'])
      + '<div class="table-wrap"><table class="stmt"><thead><tr><th class="l">' + esc(who) + '</th>'
      + d.labels.map((l) => '<th>' + esc(l) + '</th>').join('') + '<th>Unapplied</th><th>Balance</th></tr></thead><tbody>'
      + (rows || '<tr><td colspan="' + (cols + 3) + '">' + emptyState('hourglass', 'Nothing open at this date', 'Everything is settled, or nothing has been posted yet.') + '</td></tr>')
      + '<tr class="l-grand"><td class="l">Total</td>' + d.totals.buckets.map((b) => '<td class="n">' + amt(b) + '</td>').join('')
      + '<td class="n">' + amt(-d.totals.unapplied_cents) + '</td><td class="n">' + amt(d.totals.net_cents) + '</td></tr>'
      + '</tbody></table></div>' + tieBlock + sheetFoot(d.letterhead);
  };

  let seq = 0;
  const load = async () => {
    const my = ++seq;
    syncQuery({ side: state.side === 'ar' ? '' : 'ap', as_of: state.as_of === today ? '' : state.as_of, detail: state.detail ? '1' : '' });
    sheet.classList.add('is-busy');
    try {
      const d = await api.get('/reports/aging', { side: state.side, as_of: state.as_of, detail: state.detail ? 1 : '' }, { signal: ctx.signal });
      if (my === seq) render(d);
    } catch (err) {
      if (!ctx.signal.aborted && my === seq) sheet.innerHTML = emptyState('warning', 'Could not load the aging', err.message);
    } finally {
      if (my === seq) sheet.classList.remove('is-busy');
    }
  };

  form.addEventListener('submit', (e) => e.preventDefault());
  form.addEventListener('change', (e) => {
    if (e.target.name === 'as_of' && YMD.test(e.target.value)) state.as_of = e.target.value;
    else if (e.target.name === 'detail') state.detail = e.target.checked;
    else return;
    load();
  });
  qs('[data-sides]', root).addEventListener('click', (e) => {
    const b = e.target.closest('[data-side]');
    if (!b || b.dataset.side === state.side) return;
    state.side = b.dataset.side;
    paintSides();
    load();
  });
  qs('[data-print]', root).addEventListener('click', () => window.print());
  qs('[data-csv]', root).addEventListener('click', (e) => exportCsv(e.currentTarget, '/reports/aging',
    { side: state.side, as_of: state.as_of, detail: state.detail ? 1 : '' }, (state.side === 'ar' ? 'receivables' : 'payables') + '-aging-' + state.as_of + '.csv'));

  paintSides();
  await load();
}
