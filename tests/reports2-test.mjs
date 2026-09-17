// reports2-test.mjs — the worksheet, the columnar books and the integrity check, against a SCRATCH database.
//
//   node reports2-test.mjs <api base> <scratch db>
import { execFileSync } from 'node:child_process';

const [BASE, DB] = process.argv.slice(2);
if (!BASE || !DB || DB === 'accounting') { console.log('usage: node reports2-test.mjs <api base> <scratch db>'); process.exit(2); }
const MYSQL = 'D:/xampp/mysql/bin/mysql.exe';
let passed = 0;
let failed = 0;

const check = (name, cond, info) => {
  if (cond) passed++; else failed++;
  const more = !cond && info !== undefined ? '\n        ' + (typeof info === 'string' ? info : JSON.stringify(info)).slice(0, 700) : '';
  console.log((cond ? 'PASS  ' : 'FAIL  ') + name + more);
  return !!cond;
};
async function call(method, path, body, token) {
  const headers = { Accept: 'application/json' };
  if (body != null) headers['Content-Type'] = 'application/json';
  if (token) headers.Authorization = 'Bearer ' + token;
  const r = await fetch(BASE + path, { method, headers, body: body == null ? undefined : JSON.stringify(body) });
  const buf = Buffer.from(await r.arrayBuffer());
  let j = null;
  try { j = JSON.parse(buf.toString('utf8')); } catch { /* CSV */ }
  return { status: r.status, ok: r.ok && !!j && j.status === true, data: j ? j.data : null, message: j ? j.message : '', buf };
}
const sql = (q) => execFileSync(MYSQL, ['-u', 'root', '-N', DB, '-e', q], { encoding: 'utf8' }).trim();
const login = async (email, pw) => { const r = await call('POST', '/auth/login', { identifier: email, password: pw }); return r.data && r.data.access_token; };

try {
  const V = await login('viewer@example.test', 'DemoView#2026');
  const B = await login('bookkeeper@example.test', 'DemoBook#2026');
  const C = await login('accountant@example.test', 'DemoAcct#2026');
  check('the demo users sign in', V && B && C);
  const asOf = '2026-09-14';

  // ── the worksheet ─────────────────────────────────────────────────────────
  const ws = await call('GET', '/reports/worksheet?as_of=' + asOf, null, V);
  check('the worksheet: every pair of columns balances', ws.ok && ws.data.balanced, ws.data && ws.data.totals);
  const tb = await call('GET', '/reports/trial-balance?as_of=' + asOf + '&kind=adjusted', null, V);
  check('its adjusted columns are the adjusted trial balance', ws.data.totals.ab_dr === tb.data.total_debit_cents && ws.data.totals.ab_cr === tb.data.total_credit_cents, [ws.data.totals.ab_dr, tb.data.total_debit_cents]);
  const tbu = await call('GET', '/reports/trial-balance?as_of=' + asOf + '&kind=unadjusted', null, V);
  check('its unadjusted columns are the unadjusted trial balance', ws.data.totals.ub_dr === tbu.data.total_debit_cents, [ws.data.totals.ub_dr, tbu.data.total_debit_cents]);
  check('each row: unadjusted + adjustments = adjusted', ws.data.rows.every((r) => (r.ub_dr - r.ub_cr) + (r.adj_dr - r.adj_cr) === (r.ab_dr - r.ab_cr)));
  const adjBook = Number(sql("SELECT COALESCE(SUM(total_cents), 0) FROM gp_journals WHERE status = 'posted' AND book = 'adjusting' AND entry_date BETWEEN '2026-01-01' AND '" + asOf + "'"));
  check('the adjustments are the adjusting book (' + adjBook + ' at most, netted per account)', ws.data.totals.adj_dr <= adjBook && ws.data.totals.adj_dr === ws.data.totals.adj_cr, [ws.data.totals.adj_dr, adjBook]);
  const is = await call('GET', '/reports/income-statement?from=2026-01-01&to=' + asOf, null, V);
  check('its net income is the income statement\'s', ws.data.net_income_cents === is.data.totals.net[0], [ws.data.net_income_cents, is.data.totals.net[0]]);
  const wcsv = await call('GET', '/reports/worksheet?as_of=' + asOf + '&format=csv', null, V);
  check('the worksheet downloads as CSV', wcsv.status === 200 && wcsv.buf[0] === 0xEF && wcsv.buf.toString('utf8').includes('Adjustments Dr'));

  // ── the columnar books ────────────────────────────────────────────────────
  for (const book of ['cash_receipts', 'cash_disbursements', 'sales', 'purchases']) {
    const r = await call('GET', '/reports/columnar-book?book=' + book + '&from=2026-01-01&to=' + asOf, null, V);
    const n = Number(sql("SELECT COUNT(*) FROM gp_journals WHERE status = 'posted' AND book = '" + book + "' AND entry_date BETWEEN '2026-01-01' AND '" + asOf + "'"));
    const total = Number(sql("SELECT COALESCE(SUM(total_cents), 0) FROM gp_journals WHERE status = 'posted' AND book = '" + book + "' AND entry_date BETWEEN '2026-01-01' AND '" + asOf + "'"));
    const signed = r.data.rows.some((x) => Object.values(x.cols).some((v) => v < 0));
    check(r.data.title + ': ' + n + ' entries in ' + r.data.columns.length + ' columns + sundry, balanced', r.ok && r.data.count === n && r.data.rows.length === n && r.data.totals.balanced
      && (signed || r.data.totals.debit_cents === total), [r.data && r.data.totals, total]);
  }
  const cr = await call('GET', '/reports/columnar-book?book=cash_receipts&from=2026-01-01&to=' + asOf, null, V);
  const cashIds = sql("SELECT GROUP_CONCAT(a.id) FROM gp_accounts a WHERE a.code IN ('1111','1112','1113','1114')").split(',').map(Number);
  const cashDr = Number(sql("SELECT COALESCE(SUM(l.debit_cents - l.credit_cents), 0) FROM gp_ledger l WHERE l.book = 'cash_receipts' AND l.entry_date BETWEEN '2026-01-01' AND '" + asOf + "' AND l.account_id IN (" + cashIds.join(',') + ')'));
  check('the cash column of the cash receipts book is the cash the ledger received', cr.data.totals.cols.cash === cashDr, [cr.data.totals.cols.cash, cashDr]);
  check('the rows per printed page come from Settings', cr.data.rows_per_page >= 10 && cr.data.rows_per_page <= 80, cr.data.rows_per_page);
  check('the general journal is not a columnar book (422)', (await call('GET', '/reports/columnar-book?book=general', null, V)).status === 422);
  const ccsv = await call('GET', '/reports/columnar-book?book=sales&from=2026-01-01&to=' + asOf + '&format=csv', null, V);
  check('a columnar book downloads as CSV', ccsv.status === 200 && ccsv.buf[0] === 0xEF && ccsv.buf.toString('utf8').includes('Sundry Dr'));

  // ── the integrity check ───────────────────────────────────────────────────
  check('a viewer cannot run the integrity check (403)', (await call('GET', '/reports/integrity', null, V)).status === 403);
  check('nor a bookkeeper (403)', (await call('GET', '/reports/integrity', null, B)).status === 403);
  let ic = await call('GET', '/reports/integrity', null, C);
  const failing = ic.data ? ic.data.checks.filter((c) => c.status === 'fail') : [];
  check('on the demo books nothing fails (' + (ic.data && ic.data.checks.length) + ' checks, ' + (ic.data && ic.data.summary.warn) + ' warnings)', ic.ok && ic.data.summary.fail === 0, failing);

  const line = sql("SELECT l.id FROM gp_journal_lines l JOIN gp_journals j ON j.id = l.journal_id WHERE j.status = 'posted' AND l.debit_cents > 0 ORDER BY l.id LIMIT 1");
  sql('UPDATE gp_journal_lines SET debit_cents = debit_cents + 1 WHERE id = ' + line);
  ic = await call('GET', '/reports/integrity', null, C);
  const bal = ic.data.checks.find((c) => c.key === 'balance');
  check('a line changed behind the ledger\'s back is caught', bal.status === 'fail' && bal.count === 1 && /journals\/\d+/.test(bal.items[0].link), bal);
  check('and the trial balance check fails with it', ic.data.checks.find((c) => c.key === 'trial_balance').status === 'fail');
  sql('UPDATE gp_journal_lines SET debit_cents = debit_cents - 1 WHERE id = ' + line);

  const doc = sql("SELECT id FROM gp_documents WHERE status = 'posted' AND applied_cents > 0 ORDER BY id LIMIT 1");
  sql('UPDATE gp_documents SET applied_cents = applied_cents + 100 WHERE id = ' + doc);
  ic = await call('GET', '/reports/integrity', null, C);
  check('an invoice whose applied amount disagrees with its allocations is caught', ic.data.checks.find((c) => c.key === 'documents').status === 'fail');
  sql('UPDATE gp_documents SET applied_cents = applied_cents - 100 WHERE id = ' + doc);

  const icsv = await call('GET', '/reports/integrity?format=csv', null, C);
  check('the integrity check downloads as CSV', icsv.status === 200 && icsv.buf[0] === 0xEF);
  ic = await call('GET', '/reports/integrity', null, C);
  check('all clean again afterwards', ic.data.summary.fail === 0, ic.data.checks.filter((c) => c.status === 'fail'));
} catch (e) {
  failed++;
  console.log('FAIL  the script stopped: ' + (e && e.stack ? e.stack.split('\n').slice(0, 3).join(' | ') : e));
}
console.log('\n' + passed + ' passed, ' + failed + ' failed');
process.exit(failed ? 1 : 0);
