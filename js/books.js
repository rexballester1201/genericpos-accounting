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
 *
 * IN COLUMNS: the cash receipts, cash disbursements, sales and purchase books
 * as they are kept by hand — a column for each account that recurs (cash,
 * receivables, sales, VAT…) and a sundry column (Books_lib). The whole period
 * is laid out in printed pages of report_rows_per_page lines, each ending with
 * its page total and the total carried forward, each after the first opening
 * with the total brought forward.
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
const COLUMNAR = ['cash_receipts', 'cash_disbursements', 'sales', 'purchases'];

export async function mount(root, ctx) {
  const q = ctx.query;
  const today = todayYmd();
  const state = {
    book: BOOKS.some((b) => b[0] === q.get('book')) ? q.get('book') : '',
    from: YMD.test(q.get('from') || '') ? q.get('from') : today.slice(0, 8) + '01',
    to: YMD.test(q.get('to') || '') ? q.get('to') : today,
    per_page: ['50', '200', '500'].includes(q.get('per_page')) ? q.get('per_page') : '50',
    layout: q.get('layout') === 'columnar' ? 'columnar' : 'journal',
    page: 1,
  };
  const columnar = () => state.layout === 'columnar' && COLUMNAR.includes(state.book);
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
  f.layout.value = state.layout;
  f.preset.value = presetFor(list, state.from, state.to);
  const paintControls = () => {
    f.layout.disabled = !COLUMNAR.includes(state.book);
    f.layout.title = f.layout.disabled ? 'Columns are for the cash receipts, cash disbursements, sales and purchase books' : '';
    f.per_page.hidden = columnar();
  };
  paintControls();

  /* One entry is one row, however many sundry lines it has; a page never
     splits an entry. */
  const renderColumnar = (d) => {
    ctx.setTitle(d.title);
    const cols = d.columns;
    const per = d.rows_per_page || 40;
    const pages = [];
    let cur = [];
    let used = 0;
    d.rows.forEach((r) => {
      const h = Math.max(1, r.sundry.length);
      if (cur.length && used + h > per) { pages.push(cur); cur = []; used = 0; }
      cur.push(r);
      used += h;
    });
    if (cur.length || !pages.length) pages.push(cur);

    const zero = () => ({ cols: Object.fromEntries(cols.map((c) => [c.key, 0])), sdr: 0, scr: 0 });
    const add = (t, r) => { cols.forEach((c) => { t.cols[c.key] += r.cols[c.key]; }); r.sundry.forEach((s) => { t.sdr += s.debit_cents; t.scr += s.credit_cents; }); };
    const totalRow = (label, t, cls) => '<tr class="' + cls + '"><td></td><td></td><td>' + esc(label) + '</td>'
      + cols.map((c) => '<td class="n">' + amt(t.cols[c.key]) + '</td>').join('') + '<td></td><td class="n">' + amt(t.sdr) + '</td><td class="n">' + amt(t.scr) + '</td></tr>';
    const head = '<thead><tr><th class="l">Date</th><th class="l">No.</th><th class="l">Particulars</th>'
      + cols.map((c) => '<th>' + esc(c.label) + '<span class="sub">' + (c.side === 'dr' ? 'Debit' : 'Credit') + '</span></th>').join('')
      + '<th class="l">Sundry account</th><th>Debit</th><th>Credit</th></tr></thead>';
    const rowHtml = (r) => {
      const n = Math.max(1, r.sundry.length);
      let out = '';
      for (let i = 0; i < n; i++) {
        const s = r.sundry[i];
        out += '<tr' + (i === 0 ? ' class="l-line"' : '') + '>'
          + '<td class="d">' + (i ? '' : esc(fmtDay(r.date))) + '</td>'
          + '<td class="d">' + (i ? '' : '<a class="code" href="journals/' + r.id + '">' + esc(r.journal_no) + '</a>') + '</td>'
          + '<td>' + (i ? '' : esc(r.description) + ((r.reference || r.party) ? '<span class="sub">' + esc([r.reference, r.party].filter(Boolean).join(' · ')) + '</span>' : '')) + '</td>'
          + cols.map((c) => '<td class="n">' + (i || !r.cols[c.key] ? '' : amt(r.cols[c.key])) + '</td>').join('')
          + '<td>' + (s ? '<span class="code">' + esc(s.code) + '</span>' + esc(s.name) : '') + '</td>'
          + '<td class="n">' + (s && s.debit_cents ? amt(s.debit_cents) : '') + '</td>'
          + '<td class="n">' + (s && s.credit_cents ? amt(s.credit_cents) : '') + '</td></tr>';
      }
      return out;
    };

    const cum = zero();
    const lh = d.letterhead || {};
    const period = periodText(d.from, d.to);
    let html = '';
    pages.forEach((pg, i) => {
      const bf = { cols: Object.assign({}, cum.cols), sdr: cum.sdr, scr: cum.scr };
      const pt = zero();
      pg.forEach((r) => { add(pt, r); add(cum, r); });
      const last = i === pages.length - 1;
      html += '<section class="book-page">'
        + (i === 0 ? sheetHead(lh, d.title, [period, d.count + ' ' + (d.count === 1 ? 'entry' : 'entries')])
          : '<div class="bp-head"><b>' + esc(lh.company || '') + '</b> · ' + esc(d.title) + ' · ' + esc(period) + '</div>')
        + '<div class="table-wrap"><table class="stmt columnar">' + head + '<tbody>'
        + (i > 0 ? totalRow('Brought forward', bf, 'l-total') : '')
        + (pg.length ? pg.map(rowHtml).join('') : '<tr><td colspan="' + (6 + cols.length) + '">' + emptyState('book-open', 'Nothing posted in this book in the period', 'Choose another book or period.') + '</td></tr>')
        + totalRow('Page total', pt, 'l-total')
        + totalRow(last ? 'Total for the period' : 'Carried forward', cum, 'l-grand')
        + '</tbody></table></div>'
        + '<div class="bp-foot">Page ' + (i + 1) + ' of ' + pages.length + '</div></section>';
    });
    const warn = [];
    if (d.truncated) warn.push('Showing the first ' + d.rows.length + ' of ' + d.count + ' entries. Choose a shorter period, or download the CSV for all of them.');
    if (!d.totals.balanced) warn.push('The columns do not balance: debits ' + amt(d.totals.debit_cents) + ', credits ' + amt(d.totals.credit_cents) + '. Run the integrity check.');
    qs('[data-sub]', root).textContent = period + ' · ' + d.count + ' ' + (d.count === 1 ? 'entry' : 'entries') + ' · ' + pages.length + ' page' + (pages.length === 1 ? '' : 's') + ' of ' + per + ' lines';
    sheet.innerHTML = (warn.length ? '<div class="alert alert-warn no-print">' + warn.map(esc).join(' ') + '</div>' : '') + html + sheetFoot(lh);
  };

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
    syncQuery({ book: state.book, from: state.from, to: state.to, per_page: state.per_page === '50' ? '' : state.per_page, layout: columnar() ? 'columnar' : '' });
    sheet.classList.add('is-busy');
    sheet.classList.toggle('is-wide', columnar());
    const pagerEl = qs('[data-pager]', root);
    try {
      if (columnar()) {
        const d = await api.get('/reports/columnar-book', { book: state.book, from: state.from, to: state.to }, { signal: ctx.signal, timeout: 60000 });
        if (my === seq) { pagerEl.hidden = true; renderColumnar(d); }
        return;
      }
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
    if (t.name === 'book' || t.name === 'per_page' || t.name === 'layout') {
      state[t.name] = t.value;
      paintControls();
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
  qs('[data-csv]', root).addEventListener('click', (e) => (columnar()
    ? exportCsv(e.currentTarget, '/reports/columnar-book', { book: state.book, from: state.from, to: state.to }, state.book + '-columnar-' + state.from + '-to-' + state.to + '.csv')
    : exportCsv(e.currentTarget, '/reports/books', { book: state.book, from: state.from, to: state.to }, (state.book || 'all-books') + '-' + state.from + '-to-' + state.to + '.csv')));

  await load();
}
