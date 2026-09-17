// tpl-test.mjs — saved and recurring entries and the scheduled job, against a SCRATCH database.
//
//   node tpl-test.mjs <api base> <scratch db>
//
// Runs `php index.php tools cron` with GP_DB_NAME set to the scratch database.
import { execFileSync } from 'node:child_process';

const [BASE, DB] = process.argv.slice(2);
if (!BASE || !DB || DB === 'accounting') { console.log('usage: node tpl-test.mjs <api base> <scratch db>'); process.exit(2); }
const MYSQL = 'D:/xampp/mysql/bin/mysql.exe';
const PHP = 'D:/xampp/php/php.exe';
const ROOT = 'D:/xampp/htdocs/dashboard/accounting';
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
  const text = await r.text();
  let j = null;
  try { j = JSON.parse(text); } catch { /* not JSON */ }
  return { status: r.status, ok: r.ok && !!j && j.status === true, data: j ? j.data : null, message: j ? j.message : 'NOT JSON: ' + text.slice(0, 300) };
}
const sql = (q) => execFileSync(MYSQL, ['-u', 'root', '-N', DB, '-e', q], { encoding: 'utf8' }).trim();
const cron = () => {
  try {
    return { code: 0, out: execFileSync(PHP, ['index.php', 'tools', 'cron'], { cwd: ROOT, env: { ...process.env, GP_DB_NAME: DB }, encoding: 'utf8' }) };
  } catch (e) { return { code: e.status, out: String(e.stdout || '') + String(e.stderr || '') }; }
};
const login = async (email, pw) => { const r = await call('POST', '/auth/login', { identifier: email, password: pw }); return r.data && r.data.access_token; };

try {
  const B = await login('bookkeeper@example.test', 'DemoBook#2026');
  const C = await login('accountant@example.test', 'DemoAcct#2026');
  const V = await login('viewer@example.test', 'DemoView#2026');
  check('the demo users sign in', B && C && V);
  check('a viewer cannot see saved entries (403)', (await call('GET', '/journal-templates', null, V)).status === 403);

  const lk = (await call('GET', '/journals/lookups', null, B)).data;
  const exp = lk.accounts.find((a) => a.active && a.type === 'expense' && !a.control && !a.needs_department);
  const cash = lk.accounts.find((a) => a.active && a.type === 'asset' && !a.control && /^111/.test(a.code));
  check('an expense and a cash account to use: ' + exp.code + ', ' + cash.code, !!exp && !!cash);
  const today = lk.today;
  const month = new Date(today + 'T00:00:00').toLocaleString('en', { month: 'long' }) + ' ' + today.slice(0, 4);

  const rent = { name: 'Monthly office rent ' + Date.now(), book: 'cash_disbursements', description: 'Office rent for {month}', reference: 'RENT', party_name: 'Landlord Realty Inc.',
    lines: [{ account_id: exp.id, debit_cents: 2500000, credit_cents: 0, memo: 'Rent' }, { account_id: cash.id, debit_cents: 0, credit_cents: 2500000, memo: 'Paid by cheque' }] };
  let r = await call('POST', '/journal-templates', rent, B);
  check('a bookkeeper saves an entry', r.status === 201 && r.data.lines.length === 2 && r.data.total_cents === 2500000, r.message);
  const T1 = r.data;
  check('its lines are stored by account code', sql('SELECT lines_json FROM gp_journal_templates WHERE id = ' + T1.id).includes('"account_code":"' + exp.code + '"'));

  r = await call('POST', '/journal-templates', { ...rent, name: 'Unbalanced', lines: [rent.lines[0], { ...rent.lines[1], credit_cents: 100 }] }, B);
  check('an unbalanced saved entry is refused', r.status === 422 && r.data.errors.lines && /balance/.test(r.data.errors.lines), r.data);
  r = await call('POST', '/journal-templates', { ...rent, name: 'Utilities (to fill in)', lines: rent.lines.map((l) => ({ ...l, debit_cents: 0, credit_cents: 0 })) }, B);
  check('lines left at zero are fine for one used by hand', r.status === 201, r.message + JSON.stringify(r.data));
  const T2 = r.data;
  r = await call('PUT', '/journal-templates/' + T2.id, { ...rent, name: T2.name, lines: rent.lines.map((l) => ({ ...l, debit_cents: 0, credit_cents: 0 })), recur_day: 5 }, B);
  check('but not for a recurring one', r.status === 422 && /every line needs its amount/.test(r.data.errors.lines || ''), r.data);

  r = await call('POST', '/journal-templates/' + T1.id + '/draft', { entry_date: today }, B);
  check('a draft is made from it', r.status === 201 && r.data.journal_id, r.message);
  const j = (await call('GET', '/journals/' + r.data.journal_id, null, B)).data;
  check('the draft: ' + j.journal.description, j.journal.status === 'draft' && j.journal.description === 'Office rent for ' + month && j.journal.book === 'cash_disbursements' && j.lines.length === 2, j.journal);

  r = await call('PUT', '/journal-templates/' + T1.id, { ...rent, name: T1.name, recur_day: 1, next_date: today.slice(0, 7) === '2026-09' ? '2026-08-01' : today.slice(0, 8) + '01', is_active: true }, C);
  check('an accountant makes it recurring on the 1st', r.ok && r.data.recur_day === 1, r.message + JSON.stringify(r.data));
  check('a viewer cannot change it (403)', (await call('PUT', '/journal-templates/' + T1.id, rent, V)).status === 403);

  const before = Number(sql("SELECT COUNT(*) FROM gp_journals WHERE description LIKE 'Office rent for %'"));
  const run = cron();
  console.log('        ' + run.out.trim().split('\n').join('\n        '));
  check('the scheduled job runs cleanly', run.code === 0, run.out);
  const after = Number(sql("SELECT COUNT(*) FROM gp_journals WHERE description LIKE 'Office rent for %'"));
  check('it drafted the months that were due (' + (after - before) + ')', after > before, [before, after]);
  const next = sql('SELECT next_date FROM gp_journal_templates WHERE id = ' + T1.id);
  check('the next draft is due after today: ' + next, next > today, next);
  const bk = sql("SELECT id FROM gp_users WHERE email = 'bookkeeper@example.test'");
  check('the drafts are prepared for the bookkeeper who saved it', sql("SELECT COUNT(*) FROM gp_journals WHERE description LIKE 'Office rent for %' AND status = 'draft' AND created_by <> " + bk) === '0');
  check('the audit log names the system, for the bookkeeper', Number(sql("SELECT COUNT(*) FROM gp_admin_audit_log WHERE admin_id = 0 AND action = 'journal.create' AND detail LIKE '%\"prepared_for\":" + bk + "%'")) === after - before);
  check('the bookkeeper is told in their notifications', Number(sql("SELECT COUNT(*) FROM gp_notifications WHERE user_id = " + bk + " AND type = 'recurring'")) >= after - before);
  const again = cron();
  check('a second run drafts nothing more', again.code === 0 && /Recurring entries: 0 drafted/.test(again.out), again.out);

  sql('UPDATE gp_accounts SET is_active = 0 WHERE id = ' + exp.id);
  const list = (await call('GET', '/journal-templates', null, B)).data.items;
  const t1 = list.find((t) => t.id === T1.id);
  check('a deactivated account shows as a problem', t1 && t1.problems.length === 1 && /inactive/.test(t1.problems[0]), t1 && t1.problems);
  r = await call('POST', '/journal-templates/' + T1.id + '/draft', {}, B);
  check('and no draft is made from it', r.status === 422 && /needs fixing/.test(JSON.stringify(r.data)), r.data);
  sql('UPDATE gp_accounts SET is_active = 1 WHERE id = ' + exp.id);

  r = await call('DELETE', '/journal-templates/' + T2.id, null, B);
  check('its creator deletes a saved entry', r.ok, r.message);
  check('then it is gone (404)', (await call('GET', '/journal-templates/' + T2.id, null, B)).status === 404);
  r = await call('DELETE', '/journal-templates/' + T1.id, null, C);
  check('an accountant may delete one too', r.ok, r.message);
} catch (e) {
  failed++;
  console.log('FAIL  the script stopped: ' + (e && e.stack ? e.stack.split('\n').slice(0, 3).join(' | ') : e));
}
console.log('\n' + passed + ' passed, ' + failed + ' failed');
process.exit(failed ? 1 : 0);
