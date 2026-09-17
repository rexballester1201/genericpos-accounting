// platform-test.mjs — the security and platform fixes (D1, E2, E4, C8) against a SCRATCH database.
//
//   node platform-test.mjs <api base> <scratch db name>
//
// Never point this at the real database: it changes a demo user's email and
// replays refresh tokens.
import { execFileSync } from 'node:child_process';

const [BASE = 'http://127.0.0.1:8765/api/v1', DB = 'acc_regress'] = process.argv.slice(2);
const MYSQL = 'D:/xampp/mysql/bin/mysql.exe';
let passed = 0;
let failed = 0;

const check = (name, cond, info) => {
  if (cond) passed++; else failed++;
  const more = !cond && info !== undefined ? '\n        ' + (typeof info === 'string' ? info : JSON.stringify(info)).slice(0, 400) : '';
  console.log((cond ? 'PASS  ' : 'FAIL  ') + name + more);
  return !!cond;
};

async function call(method, path, body, token) {
  const headers = { Accept: 'application/json' };
  if (body != null) headers['Content-Type'] = 'application/json';
  if (token) headers.Authorization = 'Bearer ' + token;
  const r = await fetch(BASE + path, { method, headers, body: body == null ? undefined : JSON.stringify(body) });
  const text = await r.text();
  let json = null;
  try { json = JSON.parse(text); } catch { /* not JSON */ }
  return { status: r.status, json, text, type: r.headers.get('content-type') || '' };
}
const sql = (q) => execFileSync(MYSQL, ['-u', 'root', '-N', DB, '-e', q], { encoding: 'utf8' }).trim();

// ── D1: API errors come back as JSON ─────────────────────────────────────────
{
  const r = await call('GET', '/definitely/not/here');
  check('an unknown API path answers JSON', r.type.includes('json') && r.json && r.json.status === false, r.text.slice(0, 200));
  const bad = await call('GET', '/journals/abc%3Cscript%3E');
  check('a malformed path still answers JSON', bad.type.includes('json'), bad.status + ' ' + bad.text.slice(0, 200));
}

// ── E4: refresh tokens are spent once; a replay ends every session ───────────
{
  const login = await call('POST', '/auth/login', { identifier: 'viewer@example.test', password: 'DemoView#2026' });
  check('viewer signs in', login.status === 200, login.text.slice(0, 200));
  const A = login.json.data.refresh_token;

  const [r1, r2] = await Promise.all([
    call('POST', '/auth/refresh', { refresh_token: A }),
    call('POST', '/auth/refresh', { refresh_token: A }),
  ]);
  const oks = [r1, r2].filter((r) => r.status === 200);
  check('two refreshes racing with one token: exactly one succeeds', oks.length === 1, [r1.status, r2.status]);
  const B = oks[0].json.data.refresh_token;

  const again = await call('POST', '/auth/refresh', { refresh_token: A });
  check('the spent token used again within the grace period is refused, nothing more', again.status === 401 && !/used twice/.test(again.json.message), again.text.slice(0, 200));
  const stillB = await call('POST', '/auth/refresh', { refresh_token: B });
  check('the newer session still works after a race', stillB.status === 200, stillB.text.slice(0, 200));
  const C = stillB.json.data.refresh_token;

  // Age the spent token past the grace period, then replay it.
  sql("UPDATE gp_refresh_tokens SET revoked_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) WHERE revoked_at IS NOT NULL");
  const replay = await call('POST', '/auth/refresh', { refresh_token: A });
  check('a replayed old token is refused as reuse', replay.status === 401 && /used twice/.test(replay.json.message), replay.text.slice(0, 200));
  const dead = await call('POST', '/auth/refresh', { refresh_token: C });
  check('after a replay every session of the account has ended', dead.status === 401, dead.text.slice(0, 200));
  check('the replay is in the audit log as the system', sql("SELECT COUNT(*) FROM gp_admin_audit_log WHERE action = 'auth.refresh_reuse' AND admin_id = 0") !== '0');

  const out = await call('POST', '/auth/login', { identifier: 'viewer@example.test', password: 'DemoView#2026' });
  const tok = out.json.data.refresh_token;
  await call('POST', '/auth/logout', { refresh_token: tok });
  const afterLogout = await call('POST', '/auth/refresh', { refresh_token: tok });
  check('a signed-out token is simply expired, not a replay', afterLogout.status === 401 && !/used twice/.test(afterLogout.json.message), afterLogout.text.slice(0, 200));
}

// ── E2: moving the account email takes the current password ─────────────────
{
  const login = await call('POST', '/auth/login', { identifier: 'viewer@example.test', password: 'DemoView#2026' });
  const t = login.json.data.access_token;
  const none = await call('PUT', '/profile', { email: 'viewer.moved@example.test' }, t);
  check('an email change without the password is refused', none.status === 422 && none.json.data && none.json.data.errors && none.json.data.errors.current_password, none.text.slice(0, 300));
  const wrong = await call('PUT', '/profile', { email: 'viewer.moved@example.test', current_password: 'nope-nope' }, t);
  check('an email change with a wrong password is refused', wrong.status === 422 && /not correct/.test(JSON.stringify(wrong.json)), wrong.text.slice(0, 300));
  const name = await call('PUT', '/profile', { full_name: 'Demo Viewer' }, t);
  check('a name change still needs no password', name.status === 200, name.text.slice(0, 300));
  const ok = await call('PUT', '/profile', { email: 'viewer.moved@example.test', current_password: 'DemoView#2026' }, t);
  check('an email change with the password is saved', ok.status === 200 && ok.json.data.user.email === 'viewer.moved@example.test', ok.text.slice(0, 300));
  check('the email change is audited', sql("SELECT COUNT(*) FROM gp_admin_audit_log WHERE action = 'profile.email_change'") !== '0');
  const back = await call('PUT', '/profile', { email: 'viewer@example.test', current_password: 'DemoView#2026' }, t);
  check('and back again', back.status === 200, back.text.slice(0, 300));
  sql("DELETE FROM gp_login_attempts");
}

console.log('\n' + passed + ' passed, ' + failed + ' failed');
process.exit(failed ? 1 : 0);
