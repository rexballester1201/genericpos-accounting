/**
 * books.js — the books of accounts
 *
 * GenericPOS Accounting · ES module · every role
 *
 *   /reports/books?book=&from=&to=
 *
 * Every posted entry with its lines, in date and number order, for one book
 * or all of them: the general journal, the cash receipts and cash
 * disbursements books, and the sales and purchase books. Credits are set in
 * from the debits, as a journal is written by hand. A page shows its own
 * total and the period's; the CSV file holds the whole period.
 */

import { api } from './api.js';
import { todayYmd, fmtDay } from './store.js';
import { qs, esc, emptyState, pager } from './ui.js';
import { amt, sheetHead, sheetFoot, presets, presetFor, periodText, syncQuery, exportCsv } from './report-kit.js';

const YMD = /^\d{4}-\d{2}-\d{2}$/;
const BOOKS = [['', 'All books', 'Journal Entries, All Books'], ['general', 'General journal', 'General Journal'],
  ['cash_receipts', 'Cash receipts book', 'Cash Receipts Book'], ['cash_disbursements', 'Cash disbursements book', 'Cash Disbursements Book'],
  ['sales', 'Sales book', 'Sales Book'], ['purchases', 'Purchase book', 'Purchase Book'], ['adjusting', 'Adjusting entries', 'Adjusting Entries'],
  ['closing', 'Closing entries', 'Closing Entries'], ['opening', 'Opening balances', 'Opening Balances']];

export async function mount(root, ctx) {
  const q = ctx.query;
  const today = todayYmd();
  const state = {
    book: BOOKS.some((b) => b[0] === q.get('book')) ? q.get('book') : '',
    from: YMD.test(q.get('from') || '') ? q.get('from') : today.slice(0, 8) + '01',
    to: YMD.test(q.get('to') || '') ? q.get('to') : today,
    per_page: ['50', '200', '500'].includes(q.get('per_page')) ? q.get('per_page') : '50',
    page: 1,
  };
  const form = qs('[data-controls]', root);
  const f = form.elements;
  const sheet = qs('[data-sheet]', root);

  let years = [];
  try { years = (await api.get('/fiscal-years', null, { signal: ctx.signal })).years || []; } catch { /* the presets fall back to the calendar */ }
  if (ctx.signal.aborted) return;
  const list = presets(years, today);

  f.book.innerHTML = BOOKS.map(([k, l]) => '<option value="' + k + '">' + esc(l) + '</option>').join('');
  f.preset.innerHTML = list.map((p) => '<option value="' + p.key + '">' + esc(p.label) + '</option>').join('') + '<option value="custom">Custom dates</option>';
  f.book.value = state.book;
  f.from.value = state.from;
  f.to.value = state.to;
  f.per_page.value = state.per_page;
  f.preset.value = presetFor(list, state.from, state.to);

  const title = () => (BOOKS.find((b) => b[0] === state.book) || BOOKS[0])[2];

  const render = (d) => {
    const lines = [periodText(d.from, d.to), d.total + ' ' + (d.total === 1 ? 'entry' : 'entries') + (d.pages > 1 ? ', page ' + d.page + ' of ' + d.pages : '')];
    ctx.setTitle(title());
    qs('[data-sub]', root).textContent = lines.join(' · ');
    let dr = 0;
    let cr = 0;
    const body = (d.items || []).map((j) => j.lines.map((l, i) => {
      dr += l.debit_cents;
      cr += l.credit_cents;
      const first = i === 0;
      return '<tr' + (first ? ' class="l-line"' : '') + '>'
        + '<td class="d">' + (first ? esc(fmtDay(j.date)) : '') + '</td>'
        + '<td class="d">' + (first ? '<a class="code" href="journals/' + j.id + '">' + esc(j.journal_no) + '</a>' : '') + '</td>'
        + '<td>' + (first ? esc(j.description) + ((j.reference || j.party) ? '<span class="sub">' + esc([j.reference, j.party].filter(Boolean).join(' · ')) + '</span>' : '') : '') + '</td>'
        + '<td style="padding-left:' + (l.credit_cents ? 30 : 10) + 'px"><span class="code">' + esc(l.code) + '</span>' + esc(l.name)
        + ((l.contact || l.memo) ? '<span class="sub">' + esc([l.contact, l.memo].filter(Boolean).join(' · ')) + '</span>' : '') + '</td>'
        + '<td class="n">' + (l.debit_cents ? amt(l.debit_cents) : '') + '</td>'
        + '<td class="n">' + (l.credit_cents ? amt(l.credit_cents) : '') + '</td></tr>';
    }).join('')).join('');
    const foot = (d.pages > 1 ? '<tr class="l-total"><td></td><td></td><td colspan="2">Total for this page</td><td class="n">' + amt(dr) + '</td><td class="n">' + amt(cr) + '</td></tr>' : '')
      + '<tr class="l-grand"><td></td><td></td><td colspan="2">Total for the period</td><td class="n">' + amt(d.total_cents) + '</td><td class="n">' + amt(d.total_cents) + '</td></tr>';

    sheet.innerHTML = sheetHead(d.letterhead, title(), lines)
      + '<div class="table-wrap"><table class="stmt"><thead><tr><th class="l">Date</th><th class="l">Entry</th><th class="l">Particulars</th><th class="l">Account</th>'
      + '<th>Debit</th><th>Credit</th></tr></thead><tbody>'
      + (body || '<tr><td colspan="6">' + emptyState('book-open', 'Nothing posted in this book in the period', 'Choose another book or period.') + '</td></tr>')
      + foot + '</tbody></table></div>'
      + sheetFoot(d.letterhead);
    pager(qs('[data-pager]', root), d, (p) => { state.page = p; load(); window.scrollTo(0, 0); });
  };

  let seq = 0;
  const load = async () => {
    const my = ++seq;
    syncQuery({ book: state.book, from: state.from, to: state.to, per_page: state.per_page === '50' ? '' : state.per_page });
    sheet.classList.add('is-busy');
    try {
      const d = await api.get('/reports/books', { book: state.book, from: state.from, to: state.to, page: state.page, per_page: state.per_page }, { signal: ctx.signal });
      if (my === seq) render(d);
    } catch (err) {
      if (!ctx.signal.aborted && my === seq) sheet.innerHTML = emptyState('warning', 'Could not load the book', err.errors ? Object.values(err.errors).join(' ') : err.message);
    } finally {
      if (my === seq) sheet.classList.remove('is-busy');
    }
  };

  form.addEventListener('submit', (e) => e.preventDefault());
  form.addEventListener('change', (e) => {
    const t = e.target;
    if (t.name === 'book' || t.name === 'per_page') {
      state[t.name] = t.value;
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
    state.page = 1;
    load();
  });
  qs('[data-print]', root).addEventListener('click', () => window.print());
  qs('[data-csv]', root).addEventListener('click', (e) => exportCsv(e.currentTarget, '/reports/books', { book: state.book, from: state.from, to: state.to },
    (state.book || 'all-books') + '-' + state.from + '-to-' + state.to + '.csv'));

  await load();
}
