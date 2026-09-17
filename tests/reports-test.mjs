// reports-test.mjs — every report checked against the ledger's own identities (scratch DB only)
const BASE = process.argv[2] || 'http://127.0.0.1:8765/api/v1';
let passed = 0, failed = 0;
const check = (name, cond, info) => {
  if (cond) passed++; else failed++;
  console.log((cond ? 'PASS  ' : 'FAIL  ') + name + (!cond && info !== undefined ? '\n        ' + (typeof info === 'string' ? info : JSON.stringify(info)).slice(0, 700) : ''));
  return !!cond;
};
async function get(path, token) {
  const t0 = Date.now();
  const r = await fetch(BASE + path, { headers: { Accept: 'application/json', ...(token ? { Authorization: 'Bearer ' + token } : {}) } });
  const text = await r.text();
  let j = null;
  try { j = JSON.parse(text); } catch { /* not JSON */ }
  return { status: r.status, ok: r.ok && !!j && j.status === true, data: j ? j.data : null, message: j ? j.message : 'NOT JSON: ' + text.slice(0, 400), ms: Date.now() - t0 };
}
const sum = (a) => a.reduce((s, x) => s + x, 0);
const dayBefore = (d) => { const t = new Date(d + 'T00:00:00Z'); t.setUTCDate(t.getUTCDate() - 1); return t.toISOString().slice(0, 10); };
const monthsBetween = (a, b) => (+b.slice(0, 4) - +a.slice(0, 4)) * 12 + (+b.slice(5, 7) - +a.slice(5, 7)) + 1;

try {
  const lr = await fetch(BASE + '/auth/login', { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ identifier: 'viewer@example.test', password: 'DemoView#2026' }) });
  const V = (await lr.json()).data.access_token;
  const today = (await get('/journals/lookups', V)).data.today;
  const fy = (await get('/fiscal-years', V)).data.years.find((y) => y.start_date <= today && today <= y.end_date);
  const Y = fy.start_date.slice(0, 4);
  console.log('        today ' + today + ', ' + fy.name + ' from ' + fy.start_date);

  const tbp = (await get('/reports/trial-balance?kind=post_closing&as_of=' + today, V)).data;
  const tba = (await get('/reports/trial-balance?kind=adjusted&as_of=' + today, V)).data;
  const tbNet = (tb, types) => sum(tb.rows.filter((r) => types.includes(r.type)).map((r) => r.debit_cents - r.credit_cents));

  // ── balance sheet ──
  const bs = await get('/reports/balance-sheet?as_of=' + today, V);
  check('balance sheet loads (' + bs.ms + ' ms): ' + (bs.data && bs.data.title), bs.ok, bs.message);
  check('it balances', bs.data.checks.balanced[0] === true, bs.data.totals);
  check('total assets equal the trial balance', bs.data.totals.assets[0] === tbNet(tbp, ['asset']), [bs.data.totals.assets[0], tbNet(tbp, ['asset'])]);
  const eqTB = -tbNet(tbp, ['equity']) - tbNet(tbp, ['income', 'expense']) - tbp.unclosed_prior_cents;
  check('total equity equals the trial balance with income not yet closed', bs.data.totals.equity[0] === eqTB, [bs.data.totals.equity[0], eqTB]);
  for (const lv of [0, 1, 2]) {
    const r = await get('/reports/balance-sheet?levels=' + lv + '&as_of=' + today, V);
    check('detail level ' + lv + ': ' + (r.data && r.data.lines.length) + ' lines, same totals, balanced', r.ok && r.data.totals.assets[0] === bs.data.totals.assets[0] && r.data.checks.balanced[0], r.message);
  }
  for (const c of ['prior_year_end', 'prior_year']) {
    const r = await get('/reports/balance-sheet?compare=' + c + '&as_of=' + today, V);
    check('compared ' + c + ': two columns (' + (r.data && r.data.columns.map((x) => x.as_of).join(', ')) + '), both balanced',
      r.ok && r.data.columns.length === 2 && r.data.checks.balanced.every(Boolean), r.message);
  }
  const bsQ1 = await get('/reports/balance-sheet?as_of=' + Y + '-03-31', V);
  check('balance sheet at 31 March balances', bsQ1.ok && bsQ1.data.checks.balanced[0], bsQ1.data && bsQ1.data.totals);
  const bsNone = await get('/reports/balance-sheet?as_of=2020-01-01', V);
  check('before the first entry: all zero, balanced', bsNone.ok && bsNone.data.totals.assets[0] === 0 && bsNone.data.checks.balanced[0], bsNone.data && bsNone.data.totals);
  const bsZero = await get('/reports/balance-sheet?zero=1&levels=0&as_of=' + today, V);
  check('with zero balances shown: more lines, same totals', bsZero.ok && bsZero.data.lines.length > bs.data.lines.length && bsZero.data.totals.assets[0] === bs.data.totals.assets[0]);

  // ── income statement ──
  const is = await get('/reports/income-statement?from=' + fy.start_date + '&to=' + today, V);
  check('income statement loads (' + is.ms + ' ms): ' + (is.data && is.data.title), is.ok, is.message);
  check('it ties to its accounts', is.data.checks.ties[0], is.data.totals);
  const netTB = -tbNet(tba, ['income', 'expense']);
  check('net income equals the trial balance: ' + is.data.totals.net[0], is.data.totals.net[0] === netTB, [is.data.totals.net[0], netTB]);
  const bsNI = bs.data.lines.find((l) => l.type === 'computed' && /year to date/.test(l.label));
  check('the balance sheet carries the same net income', !!bsNI && bsNI.amounts[0] === is.data.totals.net[0], bsNI);
  const mo = await get('/reports/income-statement?compare=monthly&from=' + fy.start_date + '&to=' + today, V);
  const mn = mo.data.totals.net;
  check('month by month: ' + (mo.data.columns.length - 1) + ' months and a total',
    mo.ok && mo.data.columns.length === monthsBetween(fy.start_date, today) + 1 && mo.data.columns[mo.data.columns.length - 1].total === true, mo.data && mo.data.columns.length);
  check('the months add up to the total', sum(mn.slice(0, -1)) === mn[mn.length - 1] && mn[mn.length - 1] === is.data.totals.net[0], mn);
  check('every month ties', mo.data.checks.ties.every(Boolean));
  for (const c of ['prior_year', 'prior_period']) {
    const r = await get('/reports/income-statement?compare=' + c + '&from=' + fy.start_date + '&to=' + today, V);
    check('compared ' + c + ' (' + (r.data && r.data.columns[1].from + ' to ' + r.data.columns[1].to) + ')', r.ok && r.data.columns.length === 2 && r.data.checks.ties.every(Boolean), r.message);
  }
  const aug = await get('/reports/income-statement?compare=prior_period&from=' + Y + '-08-01&to=' + Y + '-08-31', V);
  check('August beside the previous period means July', aug.ok && aug.data.columns[1].from === Y + '-07-01' && aug.data.columns[1].to === Y + '-07-31', aug.data && aug.data.columns);
  for (const d of is.data.departments) {
    const r = await get('/reports/income-statement?department=' + d.id + '&from=' + fy.start_date + '&to=' + today, V);
    check('department ' + d.code + ' ties (net ' + (r.data && r.data.totals.net[0]) + ')', r.ok && r.data.checks.ties[0] && r.data.params.department === d.id, r.message);
  }
  for (const lv of [0, 1]) {
    const r = await get('/reports/income-statement?levels=' + lv + '&from=' + fy.start_date + '&to=' + today, V);
    check('income statement detail level ' + lv + ' keeps the same net income', r.ok && r.data.totals.net[0] === is.data.totals.net[0]);
  }
  const longer = await get('/reports/income-statement?compare=monthly&from=' + (+Y - 1) + '-01-01&to=' + today, V);
  check('month by month refuses more than twelve months (422)', longer.status === 422, longer.status + ' ' + longer.message);
  const back = await get('/reports/income-statement?from=' + today + '&to=' + fy.start_date, V);
  check('a start after the end is refused (422)', back.status === 422, back.status);

  // ── changes in equity ──
  const sce = await get('/reports/changes-in-equity?from=' + fy.start_date + '&to=' + today, V);
  check('changes in equity loads: ' + (sce.data && sce.data.title), sce.ok, sce.message);
  check('its columns tie', sce.data.checks.ties[0]);
  check('closing equity equals the balance sheet', sce.data.totals.ending === bs.data.totals.equity[0], [sce.data.totals.ending, bs.data.totals.equity[0]]);
  const bsPrev = await get('/reports/balance-sheet?as_of=' + dayBefore(fy.start_date), V);
  const typeOf = Object.fromEntries((await get('/accounts', V)).data.items.map((a) => [a.code, a.type]));
  const ob = await get('/reports/books?book=opening&per_page=500&from=' + fy.start_date + '&to=' + today, V);
  const openEq = sum(ob.data.items.flatMap((j) => j.lines).filter((l) => typeOf[l.code] === 'equity').map((l) => l.credit_cents - l.debit_cents));
  check('opening equity is the balance the day before plus the opening entry\'s equity (' + openEq + ')',
    sce.data.totals.beginning === bsPrev.data.totals.equity[0] + openEq && sce.data.totals.opening_entries === openEq && openEq > 0,
    [sce.data.totals.beginning, bsPrev.data.totals.equity[0], openEq, sce.data.totals.opening_entries]);
  check('so the opening capital is no longer shown as a change', sce.data.lines.filter((l) => l.type === 'account').every((l) => l.amounts[2] !== l.amounts[3] || l.amounts[3] === 0),
    sce.data.lines.filter((l) => l.type === 'account').map((l) => [l.label, l.amounts]));
  check('its net income equals the income statement', sce.data.totals.net_income === is.data.totals.net[0], [sce.data.totals.net_income, is.data.totals.net[0]]);

  // ── cash flows ──
  const cf = await get('/reports/cash-flows?from=' + fy.start_date + '&to=' + today, V);
  const t = cf.data && cf.data.totals;
  check('cash flows load: ' + (cf.data && cf.data.title), cf.ok, cf.message);
  check('they reconcile to the cash accounts', cf.data.checks.reconciled[0], t);
  const cashLine = bs.data.lines.find((l) => l.type === 'group' && /^Cash/.test(l.label));
  check('cash at the end equals the balance sheet: ' + t.ending, !!cashLine && t.ending === cashLine.amounts[0], [t.ending, cashLine && cashLine.amounts[0]]);
  check('operating + investing + financing = net change', t.operating + t.investing + t.financing === t.change, t);
  check('they start from the income statement\'s net income', t.net_income === is.data.totals.net[0], [t.net_income, is.data.totals.net[0]]);
  console.log('        operating ' + t.operating + ' · investing ' + t.investing + ' · financing ' + t.financing + ' · beginning ' + t.beginning + ' · end ' + t.ending);
  const cf0 = await get('/reports/cash-flows?levels=0&from=' + fy.start_date + '&to=' + today, V);
  check('by every account they reconcile too', cf0.ok && cf0.data.checks.reconciled[0] && cf0.data.totals.change === t.change);
  const cfA = await get('/reports/cash-flows?from=' + Y + '-08-01&to=' + Y + '-08-31', V);
  check('August\'s cash flows reconcile', cfA.ok && cfA.data.checks.reconciled[0], cfA.data && cfA.data.totals);

  // ── cash flows, the direct method ──
  const cd = await get('/reports/cash-flows?method=direct&from=' + fy.start_date + '&to=' + today, V);
  const d = cd.data && cd.data.totals;
  check('the direct method loads: ' + (cd.data && cd.data.title), cd.ok && cd.data.method === 'direct', cd.message);
  check('it reconciles to the cash accounts', cd.data.checks.reconciled[0], d);
  check('the same cash moved either way: ' + d.change, d.change === t.change && d.beginning === t.beginning && d.ending === t.ending,
    [d.change, t.change, d.beginning, t.beginning, d.ending, t.ending]);
  check('operating + investing + financing = net change', d.operating + d.investing + d.financing === d.change, d);
  const caps = cd.data.lines.filter((l) => /^Cash (received|paid)/.test(l.label)).map((l) => l.label);
  check('it says who the money came from and went to: ' + caps.join(' · '), caps.length >= 2, cd.data.lines.map((l) => l.label));
  console.log('        operating ' + d.operating + ' · investing ' + d.investing + ' · financing ' + d.financing
    + (d.non_cash ? ' · non-cash investing/financing ' + d.non_cash : ''));

  // the reconciliation note: every line from its heading down must foot to the same operating figure
  const noteAt = cd.data.lines.findIndex((l) => /^Reconciliation of/.test(l.label));
  const note = cd.data.lines.slice(noteAt + 1);
  const noteEnd = note.findIndex((l) => l.type === 'total');
  const noteSum = sum(note.slice(0, noteEnd).map((l) => (l.amounts ? l.amounts[0] : 0)));
  check('the note reaches the same operating figure from net income', noteAt > 0 && noteEnd > 0
    && noteSum === d.operating && note[noteEnd].amounts[0] === d.operating, [noteAt, noteEnd, noteSum, d.operating]);
  check('and it starts from the income statement\'s net income', d.net_income === is.data.totals.net[0], [d.net_income, is.data.totals.net[0]]);

  const cd0 = await get('/reports/cash-flows?method=direct&levels=0&from=' + fy.start_date + '&to=' + today, V);
  check('by every account it reconciles too', cd0.ok && cd0.data.checks.reconciled[0] && cd0.data.totals.change === d.change,
    cd0.data && cd0.data.totals);
  const cdA = await get('/reports/cash-flows?method=direct&from=' + Y + '-08-01&to=' + Y + '-08-31', V);
  check('August by the direct method reconciles, and moved the same cash as the indirect',
    cdA.ok && cdA.data.checks.reconciled[0] && cdA.data.totals.change === cfA.data.totals.change,
    [cdA.data && cdA.data.totals.change, cfA.data.totals.change]);

  // ── general ledger ──
  const accts = (await get('/accounts', V)).data.items;
  const byCode = Object.fromEntries(accts.map((a) => [a.code, a]));
  const tbRow = (tb, code) => { const r = tb.rows.find((x) => x.code === code); return r ? r.debit_cents - r.credit_cents : 0; };
  for (const code of ['1111', '1121', '2111', '4110', '6100']) {
    const a = byCode[code];
    const g = await get('/reports/general-ledger?account=' + a.id + '&from=' + fy.start_date + '&to=' + today, V);
    const want = (a.normal_side === 'D' ? 1 : -1) * tbRow(['income', 'expense'].includes(a.type) ? tba : tbp, code);
    const lastLine = g.data && g.data.lines[g.data.lines.length - 1];
    check('ledger of ' + code + ' ' + a.name + ': ' + (g.data && g.data.lines.length) + ' lines, ends at its trial balance (' + g.ms + ' ms)',
      g.ok && g.data.closing_cents === want && (!lastLine || lastLine.balance_cents === g.data.closing_cents), [g.data && g.data.closing_cents, want]);
  }
  const g1110 = await get('/reports/general-ledger?account=' + byCode['1110'].id + '&from=' + fy.start_date + '&to=' + today, V);
  const cashTB = ['1111', '1112', '1113', '1114'].reduce((s, c) => s + tbRow(tbp, c), 0);
  check('a header\'s ledger takes in every account under it', g1110.ok && g1110.data.closing_cents === cashTB && g1110.data.lines.every((l) => l.account),
    [g1110.data && g1110.data.closing_cents, cashTB]);
  check('the ledger needs an account (422)', (await get('/reports/general-ledger', V)).status === 422);

  // ── books ──
  const labels = (await get('/reports/books', V)).data.books;
  for (const b of ['', ...Object.keys(labels)]) {
    const r = await get('/reports/books?per_page=500&book=' + b + '&from=' + fy.start_date + '&to=' + today, V);
    const lines = r.data.items.flatMap((j) => j.lines);
    const dr = sum(lines.map((l) => l.debit_cents));
    const cr = sum(lines.map((l) => l.credit_cents));
    const list = await get('/journals?status=posted&per_page=1&from=' + fy.start_date + '&to=' + today + (b ? '&book=' + b : ''), V);
    check('book ' + (b || 'all') + ': ' + r.data.total + ' entries, debits = credits = ' + dr,
      r.ok && dr === cr && dr === r.data.total_cents && r.data.total === list.data.total && r.data.items.length === Math.min(r.data.total, 500),
      { dr, cr, total: r.data.total_cents, n: r.data.total, list: list.data && list.data.total });
  }

  // ── analysis ──
  const an = await get('/reports/analysis?as_of=' + today, V);
  check('analysis loads (' + an.ms + ' ms)', an.ok, an.message);
  const rv = (k) => an.data.ratios.find((r) => r.key === k);
  const ca = bs.data.lines.find((l) => l.type === 'subtotal' && l.label === 'Total Current Assets');
  const cl = bs.data.lines.find((l) => l.type === 'subtotal' && l.label === 'Total Current Liabilities');
  const cur = rv('current_ratio');
  check('the current ratio uses the balance sheet\'s current assets and liabilities',
    !!ca && !!cl && cur.inputs[0].cents === ca.amounts[0] && cur.inputs[1].cents === cl.amounts[0] && Math.abs(cur.value - ca.amounts[0] / cl.amounts[0]) < 1e-9,
    { cur, ca: ca && ca.amounts, cl: cl && cl.amounts });
  check('the net margin is net income over revenue', Math.abs(rv('net_margin').value - is.data.totals.net[0] / is.data.totals.revenue[0]) < 1e-9);
  check('the debt ratio is liabilities over assets', Math.abs(rv('debt_ratio').value - bs.data.totals.liabilities[0] / bs.data.totals.assets[0]) < 1e-9);
  check('every ratio is a number or empty', an.data.ratios.every((r) => r.value === null || Number.isFinite(r.value)));
  console.log('        ' + an.data.ratios.map((r) => r.key + '=' + (r.value === null ? '–' : Math.round(r.value * 100) / 100)).join('  '));

  // ── files and access ──
  for (const p of ['balance-sheet?compare=prior_year_end', 'income-statement?compare=monthly', 'changes-in-equity', 'cash-flows', 'trial-balance',
                   'general-ledger?account=' + byCode['1111'].id, 'books?book=cash_receipts', 'analysis']) {
    const r = await fetch(BASE + '/reports/' + p + (p.includes('?') ? '&' : '?') + 'format=csv', { headers: { Authorization: 'Bearer ' + V } });
    const b = new Uint8Array(await r.arrayBuffer());
    const text = new TextDecoder('utf-8', { ignoreBOM: true }).decode(b);
    check('CSV ' + p.split('?')[0] + ' (' + text.split('\n').length + ' rows)',
      r.ok && /text\/csv/.test(r.headers.get('content-type') || '') && b[0] === 0xEF && b[1] === 0xBB && b[2] === 0xBF && text.split('\n').length > 6,
      r.status + ' ' + text.slice(0, 200));
  }
  check('reports need a sign-in (401)', (await get('/reports/balance-sheet')).status === 401);
} catch (e) {
  failed++;
  console.log('FAIL  the script stopped: ' + (e && e.stack ? e.stack.split('\n').slice(0, 3).join(' | ') : e));
}
console.log('\n' + passed + ' passed, ' + failed + ' failed');
process.exit(failed ? 1 : 0);
