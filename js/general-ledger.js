/**
 * general-ledger.js — one account's ledger, or a header's
 *
 * GenericPOS Accounting · ES module · every role
 *
 *   /reports/general-ledger?account=&from=&to=
 *
 * The balance brought forward, every posted line in date order with the
 * running balance on the account's normal side, the period's totals, and the
 * balance carried forward. Choosing a header takes in every account under
 * it. The arrows step through the accounts that have entries, in chart
 * order, for going through the ledger account by account.
 */

import { api } from './api.js';
import { todayYmd, fmtDay } from './store.js';
import { qs, esc, emptyState } from './ui.js';
import { amt, sheetHead, sheetFoot, presets, presetFor, periodText, syncQuery, exportCsv } from './report-kit.js';

const YMD = /^\d{4}-\d{2}-\d{2}$/;
const TYPE = { asset: 'Assets', liability: 'Liabilities', equity: 'Equity', income: 'Income', expense: 'Expenses' };

export async function mount(root, ctx) {
  const q = ctx.query;
  const today = todayYmd();
  const state = {
    account: parseInt(q.get('account') || '0', 10) || 0,
    from: YMD.test(q.get('from') || '') ? q.get('from') : '',
    to: YMD.test(q.get('to') || '') ? q.get('to') : today,
  };
  const form = qs('[data-controls]', root);
  const f = form.elements;
  const sheet = qs('[data-sheet]', root);
  const csvBtn = qs('[data-csv]', root);
  const printBtn = qs('[data-print]', root);

  let accounts = [];
  let years = [];
  try {
    const [a, y] = await Promise.all([api.get('/accounts', null, { signal: ctx.signal }), api.get('/fiscal-years', null, { signal: ctx.signal })]);
    accounts = a.items || [];
    years = y.years || [];
  } catch (err) {
    if (!ctx.signal.aborted) sheet.innerHTML = emptyState('warning', 'Could not load the chart of accounts', err.message);
    return;
  }
  if (!state.from) {
    const fy = years.find((y) => y.start_date <= state.to && state.to <= y.end_date);
    state.from = fy ? fy.start_date : state.to.slice(0, 4) + '-01-01';
  }
  const list = presets(years, today);
  const stepList = accounts.filter((a) => !a.is_header && a.has_postings);

  let group = '';
  let opts = '<option value="">Choose an account</option>';
  accounts.forEach((a) => {
    if (a.type !== group) { opts += (group ? '</optgroup>' : '') + '<optgroup label="' + esc(TYPE[a.type] || a.type) + '">'; group = a.type; }
    opts += '<option value="' + a.id + '">' + '  '.repeat(a.depth)
      + esc(a.code + ' · ' + a.name + (a.is_header ? ' (all under it)' : '') + (a.is_active ? '' : ' (inactive)')) + '</option>';
  });
  f.account.innerHTML = opts + (group ? '</optgroup>' : '');
  f.preset.innerHTML = list.map((p) => '<option value="' + p.key + '">' + esc(p.label) + '</option>').join('') + '<option value="custom">Custom dates</option>';
  f.account.value = state.account ? String(state.account) : '';
  f.from.value = state.from;
  f.to.value = state.to;
  f.preset.value = presetFor(list, state.from, state.to);

  const cell = (v) => (v === null ? '<td></td>' : '<td class="n">' + amt(v) + '</td>');
  const row = (cls, date, entry, jid, text, sub, dr, cr, bal) => '<tr class="' + cls + '">'
    + '<td class="d">' + (date ? esc(fmtDay(date)) : '') + '</td>'
    + '<td class="d">' + (jid ? '<a class="code" href="journals/' + jid + '">' + esc(entry || '#' + jid) + '</a>' : '') + '</td>'
    + '<td>' + text + (sub ? '<span class="sub">' + esc(sub) + '</span>' : '') + '</td>'
    + cell(dr) + cell(cr) + cell(bal) + '</tr>';

  const render = (d) => {
    const a = d.account;
    const lines = [a.code + ' · ' + a.name + (a.is_header ? ', with every account under it' : ''), periodText(d.from, d.to)];
    ctx.setTitle(a.code + ' ' + a.name + ' · General ledger');
    qs('[data-sub]', root).textContent = lines.join(' · ');

    let body = row('l-computed', '', '', 0, 'Balance brought forward',
      d.counts_from ? 'Income and expense accounts count from the start of the fiscal year, ' + fmtDay(d.counts_from) + '.' : '', null, null, d.opening_cents);
    body += d.lines.map((l) => row('l-line', l.date, l.journal_no, l.journal_id, esc(l.description),
      [l.account, l.memo && l.memo !== l.description ? l.memo : '', l.contact, l.department ? 'Dept ' + l.department : '', l.reference].filter(Boolean).join(' · '),
      l.debit_cents || null, l.credit_cents || null, l.balance_cents)).join('');
    body += row('l-total', '', '', 0, 'Totals for the period', '', d.debit_cents, d.credit_cents, null);
    body += row('l-grand', '', '', 0, 'Balance carried forward', '', null, null, d.closing_cents);

    const notes = ['Balances are on the account\'s ' + (a.normal_side === 'D' ? 'debit' : 'credit') + ' side; a figure in parentheses is a balance on the other side.'];
    if (!d.lines.length) notes.push('Nothing was posted to this account in the period.');
    if (d.truncated) notes.push('Showing the first ' + d.lines.length + ' of ' + d.line_count + ' lines; the CSV file has all of them.');
    notes.push('Open an entry to see all of its lines.');

    sheet.innerHTML = sheetHead(d.letterhead, 'General Ledger', lines)
      + '<div class="table-wrap"><table class="stmt"><thead><tr><th class="l">Date</th><th class="l">Entry</th><th class="l">Particulars</th>'
      + '<th>Debit</th><th>Credit</th><th>Balance</th></tr></thead><tbody>' + body + '</tbody></table></div>'
      + '<p class="report-foot">' + esc(notes.join(' ')) + '</p>'
      + sheetFoot(d.letterhead);
    csvBtn.disabled = false;
    printBtn.disabled = false;
  };

  let seq = 0;
  const load = async () => {
    syncQuery({ account: state.account || '', from: state.from, to: state.to });
    const my = ++seq;
    if (!state.account) {
      sheet.innerHTML = emptyState('rows', 'Choose an account', 'Pick an account, or a header to take in every account under it. The arrows step through the accounts that have entries.');
      csvBtn.disabled = true;
      printBtn.disabled = true;
      return;
    }
    sheet.classList.add('is-busy');
    try {
      const d = await api.get('/reports/general-ledger', { account: state.account, from: state.from, to: state.to }, { signal: ctx.signal });
      if (my === seq) render(d);
    } catch (err) {
      if (!ctx.signal.aborted && my === seq) sheet.innerHTML = emptyState('warning', 'Could not load the ledger', err.errors ? Object.values(err.errors).join(' ') : err.message);
    } finally {
      if (my === seq) sheet.classList.remove('is-busy');
    }
  };

  form.addEventListener('submit', (e) => e.preventDefault());
  form.addEventListener('change', (e) => {
    const t = e.target;
    if (t.name === 'account') {
      state.account = parseInt(t.value, 10) || 0;
    } else if (t.name === 'preset') {
      const p = list.find((x) => x.key === t.value);
      if (!p) return;
      state.from = p.from;
      state.to = p.to;
      f.from.value = p.from;
      f.to.value = p.to;
    } else if (t.name === 'from' || t.name === 'to') {
      if (!YMD.test(t.value)) return;
      state[t.name] = t.value;
      f.preset.value = presetFor(list, state.from, state.to);
    } else {
      return;
    }
    load();
  });
  form.addEventListener('click', (e) => {
    const b = e.target.closest('[data-step]');
    if (!b || !stepList.length) return;
    const step = Number(b.dataset.step);
    const i = stepList.findIndex((a) => a.id === state.account);
    const next = i < 0 ? stepList[step > 0 ? 0 : stepList.length - 1] : stepList[(i + step + stepList.length) % stepList.length];
    state.account = next.id;
    f.account.value = String(next.id);
    load();
  });
  printBtn.addEventListener('click', () => window.print());
  csvBtn.addEventListener('click', (e) => {
    const a = accounts.find((x) => x.id === state.account);
    exportCsv(e.currentTarget, '/reports/general-ledger', { account: state.account, from: state.from, to: state.to },
      'general-ledger-' + (a ? a.code : state.account) + '-' + state.from + '-to-' + state.to + '.csv');
  });

  await load();
}
