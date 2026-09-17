/**
 * statement-of-account.js — one customer's or supplier's account over a period
 *
 * GenericPOS Accounting · ES module · every role
 *
 *   /reports/customer-statement?side=ar|ap&contact_id=&from=&to=
 *
 * The balance brought forward, every charge and payment in between, and the
 * balance to carry — taken from the posted ledger, so the closing figure is
 * the one on the control account. An aging summary at the foot shows how much
 * of it is past due, and the printed sheet is the statement itself.
 */

import { api } from './api.js';
import { todayYmd, money, fmtDay } from './store.js';
import { qs, esc, emptyState } from './ui.js';
import { amt, sheetHead, sheetFoot, presets, presetFor, periodText, syncQuery, exportCsv } from './report-kit.js';

const YMD = /^\d{4}-\d{2}-\d{2}$/;

export async function mount(root, ctx) {
  const today = todayYmd();
  const q = ctx.query;
  const state = {
    side: q.get('side') === 'ap' ? 'ap' : 'ar',
    contact_id: parseInt(q.get('contact_id') || '0', 10) || 0,
    from: YMD.test(q.get('from') || '') ? q.get('from') : today.slice(0, 4) + '-01-01',
    to: YMD.test(q.get('to') || '') ? q.get('to') : today,
  };
  const sheet = qs('[data-sheet]', root);
  const form = qs('[data-filters]', root);
  const f = form.elements;

  let years = [];
  try { years = (await api.get('/fiscal-years', null, { signal: ctx.signal })).years || []; } catch { /* the presets fall back to the calendar */ }
  if (ctx.signal.aborted) return;
  const list = presets(years, today);
  f.preset.innerHTML = list.map((p) => '<option value="' + p.key + '">' + esc(p.label) + '</option>').join('') + '<option value="custom">Custom dates</option>';
  f.from.value = state.from;
  f.to.value = state.to;
  f.preset.value = presetFor(list, state.from, state.to);

  const paintSides = () => qs('[data-sides]', root).querySelectorAll('[data-side]').forEach((b) => {
    b.classList.toggle('is-on', b.dataset.side === state.side);
    b.setAttribute('aria-pressed', b.dataset.side === state.side ? 'true' : 'false');
  });

  let people = [];
  const loadPeople = async () => {
    try {
      people = (await api.get('/contacts', { role: state.side === 'ar' ? 'customer' : 'supplier', per_page: 500 }, { signal: ctx.signal })).items || [];
    } catch { people = []; }
    f.contact_id.innerHTML = people.length
      ? people.map((p) => '<option value="' + p.id + '">' + esc(p.name) + (p.balance_cents ? ' — ' + money(p.balance_cents) : '') + '</option>').join('')
      : '<option value="">No ' + (state.side === 'ar' ? 'customers' : 'suppliers') + ' yet</option>';
    if (!people.some((p) => p.id === state.contact_id)) state.contact_id = people.length ? people[0].id : 0;
    f.contact_id.value = String(state.contact_id || '');
  };

  const render = (d) => {
    const c = d.contact;
    const charges = d.side === 'ar' ? 'Charges' : 'Bills';
    const payments = d.side === 'ar' ? 'Payments and credits' : 'Payments and debits';
    ctx.setTitle('Statement · ' + c.name);
    qs('[data-sub]', root).textContent = c.name + ' · ' + periodText(d.from, d.to) + ' · closing ' + money(d.closing_cents);

    const party = '<div><div class="small muted">' + (d.side === 'ar' ? 'To' : 'Account with') + '</div>'
      + '<div style="font-weight:700;font-size:15px">' + esc(c.name) + '</div>'
      + (c.address ? '<div class="small">' + esc(c.address).replace(/\n/g, '<br>') + '</div>' : '')
      + (c.tin ? '<div class="small">TIN ' + esc(c.tin) + '</div>' : '')
      + (c.contact_person ? '<div class="small">Attention: ' + esc(c.contact_person) + '</div>' : '') + '</div>';

    const rows = d.lines.map((l) => '<tr class="l-line"><td class="d">' + esc(fmtDay(l.date)) + '</td>'
      + '<td class="d">' + (l.href ? '<a class="code" href="' + esc(l.href) + '">' + esc(l.number || '') + '</a>'
        : (l.journal_id ? '<a class="code" href="journals/' + l.journal_id + '">' + esc(l.number || l.journal_no || '') + '</a>' : '<span class="code">' + esc(l.number || '') + '</span>')) + '</td>'
      + '<td>' + esc(l.particulars || '') + '</td>'
      + '<td class="n">' + (l.debit_cents ? amt(l.debit_cents) : '') + '</td>'
      + '<td class="n">' + (l.credit_cents ? amt(l.credit_cents) : '') + '</td>'
      + '<td class="n">' + amt(l.balance_cents) + '</td></tr>').join('');

    const ag = d.aging || { labels: [], buckets: [] };
    const aging = ag.labels.length
      ? '<table class="stmt" style="margin-top:var(--s5)"><thead><tr>' + ag.labels.map((l) => '<th>' + esc(l) + '</th>').join('')
        + (ag.unapplied_cents ? '<th>Unapplied</th>' : '') + '<th>Total</th></tr></thead><tbody><tr class="l-total">'
        + ag.buckets.map((b) => '<td class="n">' + amt(b) + '</td>').join('')
        + (ag.unapplied_cents ? '<td class="n">' + amt(-ag.unapplied_cents) + '</td>' : '')
        + '<td class="n">' + amt(ag.net_cents) + '</td></tr></tbody></table>'
      : '';

    sheet.innerHTML = sheetHead(d.letterhead, d.title, [periodText(d.from, d.to)])
      + '<div class="grid-2" style="margin-bottom:var(--s5);align-items:start">' + party
      + '<div style="justify-self:end;text-align:right"><div class="xs muted">Balance as of ' + esc(fmtDay(d.to, 'long')) + '</div>'
      + '<div class="va-figure">' + esc(money(d.closing_cents)) + '</div></div></div>'
      + '<div class="table-wrap"><table class="stmt"><thead><tr><th class="l">Date</th><th class="l">Reference</th><th class="l">Particulars</th>'
      + '<th>' + esc(charges) + '</th><th>' + esc(payments) + '</th><th>Balance</th></tr></thead><tbody>'
      + '<tr class="l-total"><td class="d">' + esc(fmtDay(d.from)) + '</td><td></td><td>Balance brought forward</td><td class="n"></td><td class="n"></td>'
      + '<td class="n">' + amt(d.opening_cents) + '</td></tr>'
      + (rows || '<tr><td colspan="6">' + emptyState('article', 'Nothing in this period', 'Choose another period, or another ' + (d.side === 'ar' ? 'customer' : 'supplier') + '.') + '</td></tr>')
      + '<tr class="l-total"><td></td><td></td><td>Totals for the period</td><td class="n">' + amt(d.debit_cents) + '</td><td class="n">' + amt(d.credit_cents) + '</td><td class="n"></td></tr>'
      + '<tr class="l-grand"><td></td><td></td><td>Balance carried forward</td><td class="n"></td><td class="n"></td><td class="n">' + amt(d.closing_cents) + '</td></tr>'
      + '</tbody></table></div>'
      + aging
      + '<p class="report-foot">' + esc(d.side === 'ar'
        ? 'Please check this statement against your records and settle the balance shown. Tell us at once if anything does not agree.'
        : 'Please check this statement against your records and tell us at once if anything does not agree.') + '</p>'
      + sheetFoot(d.letterhead);
  };

  let seq = 0;
  const load = async () => {
    const my = ++seq;
    syncQuery({ side: state.side === 'ar' ? '' : 'ap', contact_id: state.contact_id || '', from: state.from, to: state.to });
    if (!state.contact_id) {
      sheet.innerHTML = emptyState('users', 'No ' + (state.side === 'ar' ? 'customers' : 'suppliers') + ' yet',
        'Add one under ' + (state.side === 'ar' ? 'Sales › Customers' : 'Purchases › Suppliers') + ' first.');
      return;
    }
    sheet.classList.add('is-busy');
    try {
      const d = await api.get('/reports/customer-statement', { side: state.side, contact_id: state.contact_id, from: state.from, to: state.to }, { signal: ctx.signal });
      if (my === seq) render(d);
    } catch (err) {
      if (!ctx.signal.aborted && my === seq) sheet.innerHTML = emptyState('warning', 'Could not load the statement', err.errors ? Object.values(err.errors).join(' ') : err.message);
    } finally {
      if (my === seq) sheet.classList.remove('is-busy');
    }
  };

  form.addEventListener('submit', (e) => e.preventDefault());
  form.addEventListener('change', async (e) => {
    const t = e.target;
    if (t.name === 'contact_id') {
      state.contact_id = parseInt(t.value, 10) || 0;
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
    await load();
  });
  qs('[data-sides]', root).addEventListener('click', async (e) => {
    const b = e.target.closest('[data-side]');
    if (!b || b.dataset.side === state.side) return;
    state.side = b.dataset.side;
    state.contact_id = 0;
    paintSides();
    await loadPeople();
    await load();
  });
  qs('[data-print]', root).addEventListener('click', () => window.print());
  qs('[data-csv]', root).addEventListener('click', (e) => exportCsv(e.currentTarget, '/reports/customer-statement',
    { side: state.side, contact_id: state.contact_id, from: state.from, to: state.to }, 'statement-of-account-' + state.from + '-to-' + state.to + '.csv'));

  paintSides();
  await loadPeople();
  await load();
}
