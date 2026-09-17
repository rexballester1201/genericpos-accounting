// ye-test.mjs — year-end closing, reopening, a co-op's net-surplus allocation and opening balances,
// against SCRATCH databases only.
//
//   node ye-test.mjs business <api base> <db>            the demo company (seed_demo)
//   node ye-test.mjs coop <api base> <db> <setup key>    an empty schema: sets up a co-op first
import { execFileSync } from 'node:child_process';

const [MODE, BASE, DB, KEY = ''] = process.argv.slice(2);
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
  const text = await r.text();
  let j = null;
  try { j = JSON.parse(text); } catch { /* not JSON */ }
  return { status: r.status, ok: r.ok && !!j && j.status === true, data: j ? j.data : null, message: j ? j.message : 'NOT JSON: ' + text.slice(0, 300) };
}
const sql = (q) => execFileSync(MYSQL, ['-u', 'root', '-N', DB, '-e', q], { encoding: 'utf8' }).trim();
const login = async (email, pw) => { const r = await call('POST', '/auth/login', { identifier: email, password: pw }); return r.data && r.data.access_token; };
const money = (c) => (c / 100).toLocaleString('en-PH', { minimumFractionDigits: 2 });
const sum = (xs, f) => xs.reduce((a, x) => a + Number(f(x)), 0);

async function statements(T, from, to) {
  const [bs, is, sce, cf, tbA, tbP] = await Promise.all([
    call('GET', `/reports/balance-sheet?as_of=${to}`, null, T),
    call('GET', `/reports/income-statement?from=${from}&to=${to}`, null, T),
    call('GET', `/reports/changes-in-equity?from=${from}&to=${to}`, null, T),
    call('GET', `/reports/cash-flows?from=${from}&to=${to}`, null, T),
    call('GET', `/reports/trial-balance?as_of=${to}&kind=adjusted`, null, T),
    call('GET', `/reports/trial-balance?as_of=${to}&kind=post_closing`, null, T),
  ]);
  return { bs: bs.data, is: is.data, sce: sce.data, cf: cf.data, tbA: tbA.data, tbP: tbP.data };
}

try {
  if (MODE === 'business') {
    const A = await login('admin@example.test', 'DemoAdmin#2026');
    const C = await login('accountant@example.test', 'DemoAcct#2026');
    const V = await login('viewer@example.test', 'DemoView#2026');
    check('the demo users sign in', A && C && V);
    check('a viewer cannot open year-end (403)', (await call('GET', '/year-end', null, V)).status === 403);
    check('an accountant cannot either (403)', (await call('GET', '/year-end', null, C)).status === 403);

    let s = await call('GET', '/year-end', null, A);
    check('the administrator sees FY2026, open', s.ok && s.data.year.name === 'FY2026' && s.data.year.status === 'open', s.message);
    const fy = s.data.year;
    const FROM = fy.start_date;
    const TO = fy.end_date;
    const waiting = s.data.checks.find((c) => c.key === 'waiting');
    check('entries waiting for approval block the closing', waiting && !waiting.ok && waiting.level === 'block' && s.data.can.close === false, waiting);
    check('the plan closes income and expenses to ' + (s.data.plan.closing_account && s.data.plan.closing_account.code), !!s.data.plan.closing_account && s.data.plan.lines.length > 2, s.data.plan);
    check('the unfinished year and the missing FY2027 are warnings, not blocks', ['ended', 'next_year'].every((k) => { const c = s.data.checks.find((x) => x.key === k); return c && c.level === 'warn'; }), s.data.checks);

    const ob = await call('GET', '/opening-balances', null, A);
    check('opening balances: the demo entry is in force', ob.ok && ob.data.active && /^OB-/.test(ob.data.active.journal_no), ob.data && ob.data.active);
    const obUndo = await call('POST', `/opening-balances/${ob.data.active.id}/undo`, { reason: 'test' }, A);
    check('undoing it is refused while January is locked', obUndo.status === 409 && /locked/i.test(obUndo.message), obUndo.message);
    const obPost = await call('POST', '/opening-balances/post', null, A);
    check('posting an empty draft is refused', obPost.status === 422 || obPost.status === 409, obPost.status + ' ' + obPost.message);
    check('a viewer cannot read opening balances (403)', (await call('GET', '/opening-balances', null, V)).status === 403);

    const noConfirm = await call('POST', `/year-end/${fy.id}/close`, {}, A);
    check('closing without typing the year name is refused (422)', noConfirm.status === 422, noConfirm.message);
    const blocked = await call('POST', `/year-end/${fy.id}/close`, { confirm: 'FY2026' }, A);
    check('closing with entries waiting is refused (409)', blocked.status === 409 && /waiting/.test(blocked.message), blocked.message);

    sql("UPDATE gp_journals SET status = 'cancelled' WHERE status IN ('draft', 'submitted')");

    const before = await statements(A, FROM, TO);
    const ni = before.is.totals.net[0];
    check('before: the statements agree (net income ' + money(ni) + ')', before.bs.checks.balanced[0] && before.sce.totals.net_income === ni && before.cf.checks.reconciled[0], [before.bs.checks, before.sce.totals]);

    const closed = await call('POST', `/year-end/${fy.id}/close`, { confirm: 'fy2026' }, A);
    check('the administrator closes FY2026', closed.ok && closed.data.year.status === 'closed', closed.message);
    const jid = closed.data.result.closing_journal_id;
    const j = await call('GET', `/journals/${jid}`, null, A);
    const J = j.data.journal;
    const L = j.data.lines || J.lines || [];
    check('one closing entry: closing book, source closing, the fiscal year, dated the year end', J.book === 'closing' && J.source === 'closing' && Number(J.source_id) === fy.id && J.entry_date === TO && /^CJ-2026-/.test(J.journal_no || ''), J);
    check('it balances', sum(L, (l) => l.debit_cents) === sum(L, (l) => l.credit_cents) && sum(L, (l) => l.debit_cents) === Number(J.total_cents), [sum(L, (l) => l.debit_cents), sum(L, (l) => l.credit_cents), J.total_cents]);
    const reNet = sum(L.filter((l) => l.account_code === s.data.plan.closing_account.code), (l) => Number(l.credit_cents) - Number(l.debit_cents));
    check('net income ' + money(ni) + ' goes to ' + s.data.plan.closing_account.code, reNet === ni, [reNet, ni]);

    s = await call('GET', '/year-end?fiscal_year_id=' + fy.id, null, A);
    check('every month of FY2026 is closed or locked', s.data.year.periods.every((p) => p.status !== 'open'), s.data.year.periods);
    check('the year can be reopened', s.data.can.reopen === true && s.data.can.close === false);

    const after = await statements(A, FROM, TO);
    check('after: the income statement still shows the year\'s income', after.is.totals.net[0] === ni, [after.is.totals.net[0], ni]);
    check('after: the balance sheet balances, totals unchanged', after.bs.checks.balanced[0] && after.bs.totals.assets[0] === before.bs.totals.assets[0] && after.bs.totals.equity[0] === before.bs.totals.equity[0], [before.bs.totals, after.bs.totals]);
    check('after: no income is left "not yet closed"', !after.bs.lines.some((l) => /not yet closed/i.test(l.label)), after.bs.lines.filter((l) => /closed/i.test(l.label)));
    check('after: changes in equity tie to the balance sheet', after.sce.checks.ties[0] && after.sce.totals.ending === after.bs.totals.equity[0] && after.sce.totals.net_income === ni, [after.sce.totals, after.bs.totals.equity]);
    check('after: cash flows unchanged', after.cf.checks.reconciled[0] && after.cf.totals.change === before.cf.totals.change, [after.cf.totals, before.cf.totals]);
    check('after: the adjusted trial balance is unchanged', after.tbA.balanced && after.tbA.total_debit_cents === before.tbA.total_debit_cents, [after.tbA.total_debit_cents, before.tbA.total_debit_cents]);
    check('after: the post-closing trial balance has no income or expense', after.tbP.balanced && !after.tbP.rows.some((r) => r.type === 'income' || r.type === 'expense'), after.tbP.rows.filter((r) => r.type === 'income' || r.type === 'expense').slice(0, 3));
    const lk = await call('GET', '/journals/lookups', null, A);
    check('nothing can be dated in FY2026 any more', lk.ok && !lk.data.open_periods.some((p) => p.start_date >= FROM && p.start_date <= TO), lk.data && lk.data.open_periods);

    const again = await call('POST', `/year-end/${fy.id}/close`, { confirm: 'FY2026' }, A);
    check('closing it twice is refused', again.status === 409, again.message);
    const noReason = await call('POST', `/year-end/${fy.id}/reopen`, { confirm: 'FY2026' }, A);
    check('reopening without a reason is refused (422)', noReason.status === 422, noReason.message);
    const re1 = await call('POST', `/year-end/${fy.id}/reopen`, { confirm: 'FY2026', reason: 'A December supplier bill arrived late' }, A);
    check('the administrator reopens FY2026', re1.ok && re1.data.year.status === 'open' && re1.data.result.reversal_ids.length === 1, re1.message);
    const dec = re1.data.year.periods[re1.data.year.periods.length - 1];
    check('its last month is open again', dec.status === 'open', dec);
    const reopened = await statements(A, FROM, TO);
    check('reopened: income accounts carry the year\'s balances again', reopened.tbP.total_debit_cents === before.tbP.total_debit_cents, [reopened.tbP.total_debit_cents, before.tbP.total_debit_cents]);
    check('reopened: the year\'s income shows as not yet closed', reopened.bs.lines.some((l) => /not yet closed/i.test(l.label)) && reopened.bs.totals.equity[0] === before.bs.totals.equity[0]);
    const orig = await call('GET', `/journals/${jid}`, null, A);
    check('the closing entry is reversed, not deleted', orig.data.journal.status === 'posted' && !!orig.data.journal.reversed_by_id, orig.data.journal);

    const close2 = await call('POST', `/year-end/${fy.id}/close`, { confirm: 'FY2026' }, A);
    check('and closes it again', close2.ok, close2.message);
    const nos = sql("SELECT GROUP_CONCAT(journal_no ORDER BY id) FROM gp_journals WHERE book = 'closing'");
    check('the closing book has no gaps: ' + nos, nos === 'CJ-2026-00001,CJ-2026-00002,CJ-2026-00003', nos);
    const audits = sql("SELECT GROUP_CONCAT(action ORDER BY id) FROM gp_admin_audit_log WHERE action LIKE 'fiscal_year.%'");
    check('closing and reopening are in the audit log', audits.includes('fiscal_year.close,fiscal_year.reopen,fiscal_year.close'), audits);
  }

  if (MODE === 'coop') {
    const s0 = await call('POST', '/setup', { setup_key: KEY, company_name: 'Bayanihan Multi-Purpose Cooperative', kind: 'cooperative', fy_start: '2026-01-01',
      currency_code: 'PHP', currency_symbol: '₱', admin: { full_name: 'Co-op Admin', email: 'coop-admin@example.test', password: 'Coop#2026x', password_confirm: 'Coop#2026x' } });
    check('the co-op is set up', s0.status === 201, s0.message);
    const A = s0.data.access_token;
    const accts = (await call('GET', '/accounts', null, A)).data.items;
    const id = (code) => { const a = accts.find((x) => x.code === code); if (!a) throw new Error('no account ' + code); return a.id; };

    const draft = { entry_date: '2026-01-01', lines: [
      { account_id: id('11110'), debit_cents: 50000000, credit_cents: 0, memo: 'Cash at go-live' },
      { account_id: id('31100'), debit_cents: 0, credit_cents: 30000000, memo: 'Paid-up share capital' },
      { account_id: id('42100'), debit_cents: 0, credit_cents: 40000000, memo: 'Sales to date' },
      { account_id: id('53310'), debit_cents: 20000000, credit_cents: 0, memo: 'Expenses to date' },
    ] };
    let r = await call('PUT', '/opening-balances', { entry_date: '2026-01-01', lines: draft.lines.slice(0, 3) }, A);
    check('an unbalanced draft is saved', r.ok, r.message);
    r = await call('POST', '/opening-balances/post', null, A);
    check('but not posted: debits and credits must balance', r.status === 422 && /balance/.test(JSON.stringify(r.data)), r.status + ' ' + r.message + ' ' + JSON.stringify(r.data));
    r = await call('PUT', '/opening-balances', draft, A);
    check('the balanced draft is saved', r.ok && r.data.draft.lines.length === 4, r.message);
    r = await call('POST', '/opening-balances/post', null, A);
    check('and posted', r.status === 201 && r.data.active && /^OB-2026-/.test(r.data.active.journal_no), r.message);
    const ob1 = r.data.active;
    r = await call('POST', '/opening-balances/post', null, A);
    check('a second opening entry for the year is refused', r.status === 409 && /already posted/.test(r.message), r.message);
    r = await call('POST', `/opening-balances/${ob1.id}/undo`, { reason: 'Share capital was 250,000' }, A);
    check('the opening entry is undone; its lines return to the draft', r.ok && r.data.active === null && r.data.draft.lines.length === 4, r.message);
    draft.lines[0].debit_cents = 45000000;
    draft.lines[1].credit_cents = 25000000;
    await call('PUT', '/opening-balances', draft, A);
    r = await call('POST', '/opening-balances/post', null, A);
    check('and posted again, corrected, as OB-2026-00003', r.status === 201 && r.data.active.journal_no === 'OB-2026-00003', r.data && r.data.active);

    let y = await call('GET', '/year-end', null, A);
    check('year-end: the net surplus is 200,000.00', y.ok && y.data.plan.net_income_cents === 20000000 && y.data.allocation.net_surplus_cents === 20000000, y.data && y.data.plan);
    const parts = Object.fromEntries(y.data.allocation.parts.map((p) => [p.key, p.amount_cents]));
    check('the allocation preview adds up: reserve 20,000.00, ISC 42,000.00, patronage 98,000.00', Object.values(parts).reduce((a, b) => a + b, 0) === 20000000 && parts.reserve === 2000000 && parts.isc === 4200000 && parts.patronage === 9800000, parts);
    const fy = y.data.year;
    r = await call('POST', `/year-end/${fy.id}/close`, { confirm: fy.name, allocate: true }, A);
    check('FY2026 closes with the allocation', r.ok && !!r.data.result.allocation_journal_id, r.message);
    const aj = await call('GET', `/journals/${r.data.result.allocation_journal_id}`, null, A);
    check('the allocation entry: closing book, Dr undivided net surplus 200,000.00', aj.data.journal.book === 'closing' && (aj.data.lines || []).some((l) => l.account_code === y.data.plan.closing_account.code && Number(l.debit_cents) === 20000000), aj.data.lines);
    check('the allocation is recorded', sql('SELECT net_surplus_cents FROM gp_surplus_allocations') === '20000000');
    const bs = await call('GET', '/reports/balance-sheet?as_of=2026-12-31', null, A);
    check('the statement of financial condition balances', bs.data.checks.balanced[0], bs.data.checks);
    r = await call('POST', `/year-end/${fy.id}/reopen`, { confirm: fy.name, reason: 'The general assembly changed the percentages' }, A);
    check('reopening reverses the allocation and the closing', r.ok && r.data.result.reversal_ids.length === 2 && sql('SELECT COUNT(*) FROM gp_surplus_allocations') === '0', r.message);
    r = await call('POST', `/year-end/${fy.id}/close`, { confirm: fy.name }, A);
    check('closed again, without allocating', r.ok && r.data.can.allocate === true, r.message);
    r = await call('POST', `/year-end/${fy.id}/allocate`, { date: '2026-12-31' }, A);
    check('an allocation dated inside the closed year is refused', r.status === 409, r.message);
    r = await call('POST', `/year-end/${fy.id}/allocate`, { date: '2027-02-15' }, A);
    check('and one dated where no year exists yet', r.status === 409 && /No fiscal period/.test(r.message), r.message);
    const ny = await call('POST', '/fiscal-years', {}, A);
    check('FY2027 opens', ny.status === 201, ny.message);
    r = await call('POST', `/year-end/${fy.id}/allocate`, { date: '2027-02-15', percentages: { reserve: 20, cetf: 5, cdf: 3, optional: 2, isc: 50 } }, A);
    check('the general assembly\'s allocation is posted on 2027-02-15', r.ok && r.data.allocation.done && r.data.allocation.done.date === '2027-02-15', r.message);
    const p2 = r.data ? Object.fromEntries(r.data.allocation.parts.map((p) => [p.key, p.amount_cents])) : {};
    check('with its own percentages: reserve 40,000.00, ISC 70,000.00', p2.reserve === 4000000 && p2.isc === 7000000 && Object.values(p2).reduce((a, b) => a + b, 0) === 20000000, p2);
    check('the year cannot be reopened while that allocation stands', r.data && r.data.can.reopen === false && r.data.can.undo_allocation === true, r.data && r.data.can);
    r = await call('POST', `/year-end/${fy.id}/reopen`, { confirm: fy.name, reason: 'x' }, A);
    check('reopening is refused (409)', r.status === 409 && /Undo it first/.test(r.message), r.message);
    r = await call('POST', `/year-end/${fy.id}/allocation/undo`, { reason: 'Wrong percentages' }, A);
    check('the allocation is undone', r.ok && r.data.allocation.done === null && r.data.can.reopen === true, r.message);
    const tb = await call('GET', '/reports/trial-balance?as_of=2027-02-28', null, A);
    check('the trial balance still balances', tb.ok && tb.data.balanced, tb.message);
  }
} catch (e) {
  failed++;
  console.log('FAIL  the script stopped: ' + (e && e.stack ? e.stack.split('\n').slice(0, 3).join(' | ') : e));
}
console.log('\n' + passed + ' passed, ' + failed + ' failed');
process.exit(failed ? 1 : 0);
