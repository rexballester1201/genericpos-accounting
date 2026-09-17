// cli-test.mjs — installing a ledger from a shell (scratch DB only)
//
//   node tests/cli-test.mjs <scratch db name> [php] [mysql]
//
// Loads SCHEMA.sql into the named database, then drives `tools seed_chart`,
// `tools create_year` and `tools create_admin` the way someone with a shell
// and no browser would, and checks what each one refuses.
import { execFileSync } from 'node:child_process';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = dirname(dirname(fileURLToPath(import.meta.url)));
const DB = process.argv[2] || '';
const PHP = process.argv[3] || 'php';
const MYSQL = process.argv[4] || 'mysql';

if (!/scratch/.test(DB)) {
  console.error('Refusing to run: the database name must say "scratch". Got ' + JSON.stringify(DB));
  process.exit(1);
}

let passed = 0, failed = 0;
const check = (name, cond, info) => {
  if (cond) passed++; else failed++;
  console.log((cond ? 'PASS  ' : 'FAIL  ') + name + (!cond && info !== undefined ? '\n        ' + String(info).slice(0, 700) : ''));
};

// run a command and give back { out, err, code } without throwing
const run = (file, args, opts = {}) => {
  try {
    const out = execFileSync(file, args, { cwd: ROOT, encoding: 'utf8', stdio: ['pipe', 'pipe', 'pipe'], ...opts });
    return { out, err: '', code: 0 };
  } catch (e) {
    return { out: e.stdout || '', err: e.stderr || String(e), code: e.status === undefined ? -1 : e.status };
  }
};
const cliEnv = { ...process.env, GP_DB_NAME: DB };
delete cliEnv.CI_ENV;                              // empty is not an environment; absent is
const tools = (...args) => run(PHP, ['index.php', 'tools', ...args], { env: cliEnv });
const sql = (q) => run(MYSQL, ['-u', 'root', DB, '-N', '-B', '-e', q]).out.trim();

try {
  // ── a database with the schema and nothing in it ──
  run(MYSQL, ['-u', 'root', '-e', 'DROP DATABASE IF EXISTS `' + DB + '`; CREATE DATABASE `' + DB + '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;']);
  run(MYSQL, ['-u', 'root', DB], { input: readFileSync(join(ROOT, 'SCHEMA.sql')) });
  check('an empty ledger: no accounts, no fiscal years', sql('SELECT COUNT(*) FROM gp_accounts') === '0' && sql('SELECT COUNT(*) FROM gp_fiscal_years') === '0');

  // ── the chart ──
  const bad = tools('seed_chart', 'nonsense');
  check('seed_chart names the three charts when given something else', bad.code === 1 && /business_corporation/.test(bad.err), bad.err || bad.out);

  const seeded = tools('seed_chart', 'cooperative');
  const accounts = Number(sql('SELECT COUNT(*) FROM gp_accounts'));
  check('seed_chart cooperative: ' + accounts + ' accounts', seeded.code === 0 && accounts > 50, seeded.err || seeded.out);
  check('and the template\'s settings came with it', sql("SELECT value FROM gp_settings WHERE k='entity_type'") === 'cooperative');

  const again = tools('seed_chart', 'cooperative');
  check('it refuses a ledger that already has a chart, and changes nothing',
    again.code === 1 && /already has/.test(again.err) && Number(sql('SELECT COUNT(*) FROM gp_accounts')) === accounts, again.err);

  // ── the first fiscal year ──
  const noDate = tools('create_year');
  check('create_year asks for a date when there is no year to follow', noDate.code === 1 && /first fiscal year/.test(noDate.err), noDate.err);

  const y1 = tools('create_year', '2026-07-01');
  check('create_year 2026-07-01: ' + (y1.out || '').trim(), y1.code === 0 && /FY2026-27/.test(y1.out), y1.err || y1.out);
  check('twelve open months', sql("SELECT COUNT(*) FROM gp_periods WHERE status='open'") === '12');
  check('and the first year fixed the month the books turn on', sql("SELECT value FROM gp_settings WHERE k='fiscal_year_start_month'") === '7');

  const y2 = tools('create_year');
  check('the next year needs no date: ' + (y2.out || '').trim(), y2.code === 0 && /FY2027-28/.test(y2.out), y2.err || y2.out);

  const gap = tools('create_year', '2029-03-01');
  check('a year that would leave a gap is refused', gap.code === 1 && /must start on 2028-07-01/.test(gap.err), gap.err);

  // ── the administrator ──
  const admin = tools('create_admin', 'owner%40example.test', 'ShellInstall2026aB');
  check('create_admin: ' + (admin.out || '').trim(), admin.code === 0 && /admin #1/.test(admin.out), admin.err || admin.out);
  check('the address was decoded', sql("SELECT email FROM gp_users WHERE id=1") === 'owner@example.test');
  check('their role is admin and the account is active', sql('SELECT CONCAT(role, " ", account_state) FROM gp_users WHERE id=1') === 'admin active');

  // ── nobody signed in did any of it ──
  const audit = sql("SELECT GROUP_CONCAT(CONCAT(admin_id, ':', action) ORDER BY id SEPARATOR ' ') FROM gp_admin_audit_log");
  check('every step is in the audit log as the system: ' + audit,
    /0:cli\.seed_chart/.test(audit) && /0:cli\.create_year/.test(audit) && /0:cli\.create_user/.test(audit), audit);

  // ── and the ledger works ──
  const tb = tools('trial_balance');
  check('the ledger it built prints a balanced trial balance', tb.code === 0 && /Balanced\./.test(tb.out), tb.err || tb.out);
} catch (e) {
  failed++;
  console.log('FAIL  the script stopped: ' + (e && e.message ? e.message : e));
}

console.log('\n' + passed + ' passed, ' + failed + ' failed');
process.exit(failed ? 1 : 0);
