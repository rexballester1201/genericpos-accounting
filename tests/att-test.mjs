// att-test.mjs — attachments (E3), vouchers and source links, against a SCRATCH database.
//
//   node att-test.mjs <api base> <scratch db>
//
// The files land in the project's uploads/attachments/ (the folder Apache also
// serves), so the deny-all rule is checked through Apache at localhost too.
import { execFileSync } from 'node:child_process';
import { existsSync } from 'node:fs';

const [BASE, DB] = process.argv.slice(2);
if (!BASE || !DB || DB === 'accounting') { console.log('usage: node att-test.mjs <api base> <scratch db>'); process.exit(2); }
const MYSQL = 'D:/xampp/mysql/bin/mysql.exe';
const DIR = 'D:/xampp/htdocs/dashboard/accounting/uploads/attachments/';
let passed = 0;
let failed = 0;

const check = (name, cond, info) => {
  if (cond) passed++; else failed++;
  const more = !cond && info !== undefined ? '\n        ' + (typeof info === 'string' ? info : JSON.stringify(info)).slice(0, 500) : '';
  console.log((cond ? 'PASS  ' : 'FAIL  ') + name + more);
  return !!cond;
};
async function call(method, path, body, token, form) {
  const headers = { Accept: 'application/json' };
  if (body != null && !form) headers['Content-Type'] = 'application/json';
  if (token) headers.Authorization = 'Bearer ' + token;
  const r = await fetch(BASE + path, { method, headers, body: form || (body == null ? undefined : JSON.stringify(body)) });
  const buf = Buffer.from(await r.arrayBuffer());
  let j = null;
  try { j = JSON.parse(buf.toString('utf8')); } catch { /* a file, not JSON */ }
  return { status: r.status, ok: r.ok && !!j && j.status === true, data: j ? j.data : null, message: j ? j.message : '', buf, headers: r.headers };
}
const sql = (q) => execFileSync(MYSQL, ['-u', 'root', '-N', DB, '-e', q], { encoding: 'utf8' }).trim();
const login = async (email, pw) => { const r = await call('POST', '/auth/login', { identifier: email, password: pw }); return r.data && r.data.access_token; };
const form = (owner, id, name, bytes, type = 'application/octet-stream') => {
  const f = new FormData();
  f.append('owner_type', owner);
  f.append('owner_id', String(id));
  f.append('file', new Blob([bytes], { type }), name);
  return f;
};
const PDF = Buffer.from('%PDF-1.4\n1 0 obj <<>> endobj\ntrailer <<>>\n%%EOF\n');

try {
  const B = await login('bookkeeper@example.test', 'DemoBook#2026');
  const C = await login('accountant@example.test', 'DemoAcct#2026');
  const V = await login('viewer@example.test', 'DemoView#2026');
  check('the demo users sign in', B && C && V);

  const jid = Number(sql("SELECT id FROM gp_journals WHERE status = 'posted' AND book = 'cash_disbursements' AND source = 'payment' ORDER BY id LIMIT 1"));
  let r = await call('POST', '/attachments', null, B, form('journal', jid, 'Official receipt 1234.pdf', PDF, 'application/pdf'));
  check('a bookkeeper attaches a PDF to a posted entry', r.status === 201 && r.data.items.length >= 1 && r.data.items.some((a) => a.name === 'Official receipt 1234.pdf' && a.mime === 'application/pdf'), r.message);
  const aid = r.data.id;
  const stored = sql('SELECT file FROM gp_attachments WHERE id = ' + aid);
  check('it is stored under a random name', /^[0-9a-f]{32}\.pdf$/.test(stored) && existsSync(DIR + stored), stored);
  check('the folder has its deny-all rule', existsSync(DIR + '.htaccess') && existsSync(DIR + 'index.html'));

  r = await call('POST', '/attachments', null, B, form('journal', jid, 'photo.png', Buffer.from('<html><script>alert(1)</script></html>'), 'image/png'));
  check('HTML dressed as a picture is refused', r.status === 422, r.status + ' ' + JSON.stringify(r.data));
  r = await call('POST', '/attachments', null, B, form('journal', jid, 'invoice.pdf', Buffer.from('MZ\x90\x00\x03\x00\x00\x00program'), 'application/pdf'));
  check('a program dressed as a PDF is refused', r.status === 422, r.status + ' ' + JSON.stringify(r.data));
  r = await call('POST', '/attachments', null, B, form('journal', 99999999, 'x.pdf', PDF, 'application/pdf'));
  check('attaching to a record that does not exist is refused (404)', r.status === 404, r.status);
  r = await call('POST', '/attachments', null, V, form('journal', jid, 'x.pdf', PDF, 'application/pdf'));
  check('a viewer cannot attach (403)', r.status === 403, r.status);

  r = await call('GET', '/attachments?owner_type=journal&owner_id=' + jid, null, V);
  check('a viewer sees the list', r.ok && r.data.items.length >= 1 && r.data.items.every((a) => a.can_remove === false), r.data);
  r = await call('GET', '/attachments/' + aid + '/file', null, V);
  check('a viewer opens the file', r.status === 200 && r.buf.subarray(0, 5).toString() === '%PDF-' && r.headers.get('content-type') === 'application/pdf', r.status + ' ' + r.headers.get('content-type'));
  check('it is sent with nosniff and a sandbox', r.headers.get('x-content-type-options') === 'nosniff' && /sandbox/.test(r.headers.get('content-security-policy') || ''), [...r.headers]);
  r = await call('GET', '/attachments/' + aid + '/file');
  check('without signing in it is refused (401)', r.status === 401, r.status);
  try {
    const direct = await fetch('http://localhost/dashboard/accounting/uploads/attachments/' + stored);
    check('Apache refuses the file by its address (' + direct.status + ')', direct.status === 403, direct.status);
  } catch (e) {
    console.log('info  Apache is not answering on localhost; the direct-address check was skipped');
  }

  r = await call('DELETE', '/attachments/' + aid, null, V);
  check('a viewer cannot remove it (403)', r.status === 403, r.status);
  r = await call('DELETE', '/attachments/' + aid, null, B);
  check('its uploader removes it the same day', r.ok && !existsSync(DIR + stored), r.message);
  r = await call('POST', '/attachments', null, B, form('journal', jid, 'contract.pdf', PDF, 'application/pdf'));
  const aid2 = r.data.id;
  r = await call('DELETE', '/attachments/' + aid2, null, C);
  check('an accountant may remove one too', r.ok, r.message);
  check('adding and removing are in the audit log', Number(sql("SELECT COUNT(*) FROM gp_admin_audit_log WHERE action IN ('attachment.add','attachment.remove') AND target_type = 'journal' AND target_id = " + jid)) >= 4);

  const v = await call('GET', '/journals/' + jid + '/voucher', null, V);
  check('the voucher of a payment: letterhead, the cash paid, in words', v.ok && v.data.letterhead && v.data.cash_cents > 0 && /pesos? and \d\d\/100$/.test(v.data.amount_words), v.data && [v.data.cash_cents, v.data.amount_words]);
  check('the cash paid is the cash line, not the total (' + (v.data && v.data.cash_cents) + ' of ' + (v.data && v.data.journal.total_cents) + ')', v.data && v.data.cash_cents <= v.data.journal.total_cents);
  check('and it links back to its payment', v.data && v.data.source_link === 'settlements/' + v.data.journal.source_id, v.data && v.data.source_link);
  const inv = Number(sql("SELECT id FROM gp_journals WHERE source = 'invoice' ORDER BY id LIMIT 1"));
  const d = await call('GET', '/journals/' + inv, null, V);
  check('an invoice entry links to its document', d.ok && /^documents\/\d+$/.test(d.data.source_link || ''), d.data && d.data.source_link);
} catch (e) {
  failed++;
  console.log('FAIL  the script stopped: ' + (e && e.stack ? e.stack.split('\n').slice(0, 3).join(' | ') : e));
}
console.log('\n' + passed + ' passed, ' + failed + ' failed');
process.exit(failed ? 1 : 0);
