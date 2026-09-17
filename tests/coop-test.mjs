// coop-test.mjs — set up an empty co-operative on a SCRATCH database and read every report of an empty ledger.
const BASE = process.argv[2] || 'http://127.0.0.1:8766/api/v1';
const KEY = process.argv[3] || '';
let passed = 0, failed = 0;
const check = (name, cond, info) => {
  if (cond) passed++; else failed++;
  console.log((cond ? 'PASS  ' : 'FAIL  ') + name + (!cond && info !== undefined ? '\n        ' + (typeof info === 'string' ? info : JSON.stringify(info)).slice(0, 600) : ''));
};
const call = async (method, path, body, token) => {
  const r = await fetch(BASE + path, { method, headers: { 'Content-Type': 'application/json', ...(token ? { Authorization: 'Bearer ' + token } : {}) }, body: body ? JSON.stringify(body) : undefined });
  const text = await r.text();
  let j = null; try { j = JSON.parse(text); } catch { /* not JSON */ }
  return { status: r.status, ok: r.ok && !!j && j.status === true, data: j ? j.data : null, message: j ? j.message : 'NOT JSON: ' + text.slice(0, 300) };
};

try {
  const s = await call('POST', '/setup', { setup_key: KEY, company_name: 'Bayanihan Multi-Purpose Cooperative', kind: 'cooperative', fy_start: '2026-01-01',
    currency_code: 'PHP', currency_symbol: '₱', admin: { full_name: 'Co-op Admin', email: 'coop-admin@example.test', password: 'Coop#2026x', password_confirm: 'Coop#2026x' } });
  check('the co-operative is set up', s.status === 201, s.status + ' ' + s.message);
  const T = s.data.access_token;
  const to = '2026-09-14', from = '2026-01-01';

  const bs = await call('GET', '/reports/balance-sheet?as_of=' + to, null, T);
  check('empty ledger: ' + (bs.data && bs.data.title) + ', balanced at zero', bs.ok && bs.data.title === 'Statement of Financial Condition' && bs.data.checks.balanced[0] && bs.data.totals.assets[0] === 0, bs.message);
  check('its equity is members\' equity', bs.ok && bs.data.lines.some((l) => l.type === 'heading' && l.label === "Members' equity"), bs.data && bs.data.lines.map((l) => l.label));
  const is = await call('GET', '/reports/income-statement?from=' + from + '&to=' + to, null, T);
  check('empty ledger: ' + (is.data && is.data.title) + ', net surplus zero', is.ok && is.data.title === 'Statement of Operations' && is.data.totals.net[0] === 0 && is.data.checks.ties[0]
    && is.data.lines[is.data.lines.length - 1].label === 'Net surplus (loss)', is.data && is.data.lines.map((l) => l.label));
  const mo = await call('GET', '/reports/income-statement?compare=monthly&from=' + from + '&to=' + to, null, T);
  check('month by month on an empty ledger', mo.ok && mo.data.columns.length === 10, mo.message);
  const sce = await call('GET', '/reports/changes-in-equity?from=' + from + '&to=' + to, null, T);
  check('empty ledger: ' + (sce.data && sce.data.title), sce.ok && sce.data.title === "Statement of Changes in Members' Equity" && sce.data.checks.ties[0], sce.message);
  const cf = await call('GET', '/reports/cash-flows?from=' + from + '&to=' + to, null, T);
  check('empty ledger: cash flows reconcile at zero', cf.ok && cf.data.checks.reconciled[0] && cf.data.totals.ending === 0, cf.message);
  const tb = await call('GET', '/reports/trial-balance?as_of=' + to, null, T);
  check('empty ledger: the trial balance balances', tb.ok && tb.data.balanced && tb.data.rows.length === 0, tb.message);
  const acc = await call('GET', '/accounts', null, T);
  const loans = acc.data.items.find((a) => a.code === '11210');
  const gl = await call('GET', '/reports/general-ledger?account=' + loans.id + '&from=' + from + '&to=' + to, null, T);
  check('empty ledger: a ledger with no lines', gl.ok && gl.data.lines.length === 0 && gl.data.closing_cents === 0, gl.message);
  const bk = await call('GET', '/reports/books?from=' + from + '&to=' + to, null, T);
  check('empty ledger: no books entries', bk.ok && bk.data.total === 0, bk.message);
  const an = await call('GET', '/reports/analysis?as_of=' + to, null, T);
  check('empty ledger: every ratio is empty, with the co-op indicators', an.ok && an.data.ratios.every((r) => r.value === null || r.unit === 'money')
    && an.data.groups.some((g) => g.key === 'coop') && an.data.ratios.some((r) => r.key === 'portfolio_at_risk'), an.data && an.data.ratios.filter((r) => r.value !== null));
  const lh = bs.data && bs.data.letterhead;
  check('the letterhead names the co-operative and its signatories', !!lh && lh.company === 'Bayanihan Multi-Purpose Cooperative' && lh.entity_type === 'cooperative' && lh.signatories[0].name === 'Co-op Admin', lh);
} catch (e) {
  failed++;
  console.log('FAIL  the script stopped: ' + (e && e.stack ? e.stack.split('\n').slice(0, 3).join(' | ') : e));
}
console.log('\n' + passed + ' passed, ' + failed + ' failed');
process.exit(failed ? 1 : 0);
