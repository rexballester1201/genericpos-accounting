// import-test.mjs — the CSV imports, against a SCRATCH database only.
//
//   node import-test.mjs <api base> <scratch db>
import { execFileSync } from 'node:child_process';

const [BASE, DB] = process.argv.slice(2);
if (!BASE || !DB || !/scratch/.test(DB)) { console.log('usage: node import-test.mjs <api base> <db whose name says scratch>'); process.exit(2); }
const MYSQL = 'D:/xampp/mysql/bin/mysql.exe';
let passed = 0;
let failed = 0;

const check = (name, cond, info) => {
  if (cond) passed++; else failed++;
  const more = !cond && info !== undefined ? '\n        ' + (typeof info === 'string' ? info : JSON.stringify(info)).slice(0, 600) : '';
  console.log((cond ? 'PASS  ' : 'FAIL  ') + name + more);
  return !!cond;
};
async function call(method, path, body, token) {
  const headers = { Accept: 'application/json' };
  if (body != null) headers['Content-Type'] = 'application/json';
  if (token) headers.Authorization = 'Bearer ' + token;
  const r = await fetch(BASE + path, { method, headers, body: body == null ? undefined : JSON.stringify(body) });
  const t = await r.text();
  let j = null;
  try { j = JSON.parse(t); } catch { /* not JSON */ }
  return { status: r.status, ok: r.ok && !!j && j.status === true, data: j ? j.data : null, message: j ? j.message : t.slice(0, 200) };
}
const sql = (q) => execFileSync(MYSQL, ['-u', 'root', '-N', DB, '-e', q], { encoding: 'utf8' }).trim();
const login = async (e, p) => (await call('POST', '/auth/login', { identifier: e, password: p })).data.access_token;

try {
  const A = await login('admin@example.test', 'DemoAdmin#2026');
  const C = await login('accountant@example.test', 'DemoAcct#2026');
  check('an accountant cannot import (403)', (await call('GET', '/imports', null, C)).status === 403);

  const st = await call('GET', '/imports', null, A);
  check('the administrator sees the four kinds with their columns and a template', st.ok && st.data.kinds.length === 4
    && st.data.kinds.every((k) => k.columns.length && k.sample.header.length), st.data && st.data.kinds.map((k) => k.key));

  // ── chart of accounts ────────────────────────────────────────────────
  const acctRows = [
    { code: '1119', name: 'Petty Cash Fund (imported)', type: 'asset', parent_code: '1110', subtype: 'current', cash_flow: 'cash' },
    { code: '1119-1', name: 'Petty cash — Naga', type: 'asset', parent_code: '1119' },
    { code: '1111', name: 'Cash on Hand', type: 'asset' },
    { code: '', name: 'No code', type: 'asset' },
    { code: '9999', name: 'Bad type', type: 'wrong' },
  ];
  let r = await call('POST', '/imports/accounts', { rows: acctRows, commit: false }, A);
  check('a chart file is checked row by row', r.ok && r.data.rows.length === 5 && r.data.bad === 2, r.data && r.data.rows.map((x) => [x.line, x.ok, x.errors]));
  check('a code already in the chart is left alone, not refused', r.data.rows[2].ok === true && r.data.rows[2].skip === true, r.data.rows[2]);
  check('a parent inside the same file is allowed', r.data.rows[1].ok === true, r.data.rows[1]);
  r = await call('POST', '/imports/accounts', { rows: acctRows, commit: true }, A);
  check('and nothing is written while a row is wrong', r.status === 422 && Number(sql("SELECT COUNT(*) FROM gp_accounts WHERE code IN ('1119','1119-1')")) === 0, r.message);

  r = await call('POST', '/imports/accounts', { rows: acctRows.slice(0, 3), commit: true }, A);
  check('a clean chart file imports, parents first', r.ok && Number(sql("SELECT COUNT(*) FROM gp_accounts WHERE code IN ('1119','1119-1')")) === 2, r.message);
  check('the child points at the parent from the same file',
    sql("SELECT p.code FROM gp_accounts c JOIN gp_accounts p ON p.id = c.parent_id WHERE c.code = '1119-1'") === '1119');

  // ── contacts ─────────────────────────────────────────────────────────
  r = await call('POST', '/imports/contacts', { rows: [
    { code: 'C900', name: 'Imported Trading', role: 'customer', tin: '111-222-333-00000', terms_days: '30' },
    { code: 'S900', name: 'Imported Supplies', role: 'supplier', ewt_rate_pct: '2' },
    { code: 'C900', name: 'Twice in the file', role: 'customer' },
  ], commit: false }, A);
  check('a repeated code in one file is caught', r.ok && r.data.bad === 1 && /twice/i.test(r.data.rows[2].errors.join(' ')), r.data && r.data.rows[2]);
  r = await call('POST', '/imports/contacts', { rows: [
    { code: 'C900', name: 'Imported Trading', role: 'customer', tin: '111-222-333-00000', terms_days: '30' },
    { code: 'S900', name: 'Imported Supplies', role: 'supplier', ewt_rate_pct: '2' },
  ], commit: true }, A);
  check('contacts import with their role and withholding rate', r.ok
    && sql("SELECT CONCAT(is_customer, is_supplier, ewt_rate_bp) FROM gp_contacts WHERE code = 'S900'") === '01200', r.message);

  // ── opening balances ─────────────────────────────────────────────────
  r = await call('POST', '/imports/opening', { rows: [
    { code: '1111', debit: '25,000.00', memo: 'Cash count' },
    { code: '1121', debit: '48000', contact_code: 'C900', memo: 'Open invoice' },
    { code: '3210', credit: '73000' },
    { code: '1121', debit: '1000' },
  ], commit: false }, A);
  check('opening balances: a control account without its customer is caught', r.ok && r.data.bad === 1 && /control account/.test(r.data.rows[3].errors.join(' ')), r.data && r.data.rows[3]);
  r = await call('POST', '/imports/opening', { rows: [
    { code: '1111', debit: '25,000.00', memo: 'Cash count' },
    { code: '1121', debit: '48000', contact_code: 'C900', memo: 'Open invoice' },
    { code: '3210', credit: '73000' },
  ], commit: true }, A);
  check('they land in the opening draft, balanced, not in the ledger', r.ok && /draft/i.test(r.message), r.message);
  const draft = JSON.parse(sql("SELECT v FROM gp_app_state WHERE k = 'opening_draft'") || '{}');
  check('the draft holds the three lines with their customer', (draft.lines || []).length === 3 && draft.lines[1].contact_id > 0, draft.lines);

  // ── journal entries ──────────────────────────────────────────────────
  const today = st.data.today;
  const bad = [
    { entry: 'JV-A', date: today, book: 'general', description: 'Accrual', account_code: '6210', debit: '4500' },
    { entry: 'JV-A', date: today, book: 'general', description: 'Accrual', account_code: '2130', credit: '4000' },
  ];
  r = await call('POST', '/imports/journals', { rows: bad, commit: false }, A);
  check('an entry whose lines do not balance is caught', r.ok && r.data.bad === 2 && /does not balance/.test(JSON.stringify(r.data.rows)), r.data && r.data.rows.map((x) => x.errors));
  const good = [
    { entry: 'JV-A', date: today, book: 'general', description: 'Accrual of utilities', account_code: '6210', debit: '4500', memo: 'August bill' },
    { entry: 'JV-A', date: today, book: 'general', description: 'Accrual of utilities', account_code: '2130', credit: '4500' },
    { entry: 'JV-B', date: today, book: 'adjusting', description: 'Supplies used', account_code: '6240', debit: '1200' },
    { entry: 'JV-B', date: today, book: 'adjusting', description: 'Supplies used', account_code: '1141', credit: '1200' },
  ];
  r = await call('POST', '/imports/journals', { rows: good, commit: false }, A);
  check('two entries are recognised from the entry keys', r.ok && r.data.entries.length === 2 && r.data.bad === 0, r.data && r.data.entries);
  const before = Number(sql("SELECT COUNT(*) FROM gp_journals WHERE status = 'draft'"));
  r = await call('POST', '/imports/journals', { rows: good, commit: true }, A);
  check('they import as drafts, not postings', r.ok && Number(sql("SELECT COUNT(*) FROM gp_journals WHERE status = 'draft'")) === before + 2
    && Number(sql("SELECT COUNT(*) FROM gp_journals WHERE status = 'posted' AND description = 'Accrual of utilities'")) === 0, r.message);
  check('the import is in the audit log', Number(sql("SELECT COUNT(*) FROM gp_admin_audit_log WHERE action LIKE 'import.%'")) >= 4);
  const closed = await call('POST', '/imports/journals', { rows: [
    { entry: 'OLD', date: '2026-02-15', book: 'general', description: 'Into a locked month', account_code: '6210', debit: '100' },
    { entry: 'OLD', date: '2026-02-15', book: 'general', description: 'Into a locked month', account_code: '2130', credit: '100' },
  ], commit: false }, A);
  check('a date in a closed or locked month is refused', closed.ok && closed.data.bad === 2 && /locked|closed/.test(JSON.stringify(closed.data.rows)), closed.data && closed.data.rows[0]);
} catch (e) {
  failed++;
  console.log('FAIL  the script stopped: ' + (e && e.stack ? e.stack.split('\n').slice(0, 3).join(' | ') : e));
}
console.log('\n' + passed + ' passed, ' + failed + ' failed');
process.exit(failed ? 1 : 0);
