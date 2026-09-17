// bank-smoke.mjs — a quick look at the banking API on the scratch DB
const BASE = process.argv[2] || 'http://127.0.0.1:8782/api/v1';
async function call(method, path, body, token) {
  const headers = { Accept: 'application/json' };
  if (body != null) headers['Content-Type'] = 'application/json';
  if (token) headers.Authorization = 'Bearer ' + token;
  const r = await fetch(BASE + path, { method, headers, body: body == null ? undefined : JSON.stringify(body) });
  const text = await r.text();
  let j = null;
  try { j = JSON.parse(text); } catch { /* not JSON */ }
  return { status: r.status, ok: r.ok && j && j.status === true, data: j ? j.data : null, message: j ? j.message : 'NOT JSON: ' + text.slice(0, 600) };
}
const login = async (e, p) => (await call('POST', '/auth/login', { identifier: e, password: p })).data.access_token;
const A = await login('accountant@example.test', 'DemoAcct#2026');
const B = await login('bookkeeper@example.test', 'DemoBook#2026');

const ov = await call('GET', '/banking', null, B);
console.log('GET /banking', ov.status, ov.message);
if (ov.data) for (const a of ov.data.accounts) console.log('  ', a.label, a.ledger_code, a.book_balance_cents, 'stmts', a.statements.length, 'unmatched', a.unmatched_count);
const st = await call('GET', '/banking/statements/1', null, B);
console.log('GET statement 1', st.status, st.message);
if (st.data) {
  const f = st.data.figures;
  console.log('  lines', st.data.lines.length, 'pool', st.data.book_lines.length, 'period', f.period_from, 'start', f.recon_start);
  console.log('  bank', JSON.stringify(f.bank_side));
  console.log('  book', JSON.stringify(f.book_side));
  console.log('  diff', f.difference_cents, 'checks', JSON.stringify(f.checks));
  console.log('  blockers', JSON.stringify(st.data.reconcile_blockers));
}
const am = await call('POST', '/banking/statements/1/auto-match', {}, B);
console.log('auto-match', am.status, am.message, am.data && JSON.stringify(am.data.auto));
if (am.data) {
  const left = am.data.lines.filter((l) => l.status === 'unmatched').map((l) => l.id + ' ' + l.description + ' ' + l.amount_cents);
  console.log('  still unmatched:', left.join(' | '));
  console.log('  diff', am.data.figures.difference_cents);
}
const rp = await call('GET', '/reports/bank-reconciliation?statement_id=1', null, B);
console.log('report', rp.status, rp.message);
if (rp.data && rp.data.report) {
  const r = rp.data.report;
  console.log('  DIT', r.items.deposits_in_transit.map((i) => i.date + ' ' + i.reference + ' ' + i.amount_cents).join('; '));
  console.log('  OC ', r.items.outstanding_cheques.map((i) => i.date + ' ' + i.reference + ' ' + i.amount_cents).join('; '));
  console.log('  CM ', r.items.credit_memos.map((i) => i.date + ' ' + i.amount_cents).join('; '), ' DM', r.items.debit_memos.map((i) => i.date + ' ' + i.amount_cents).join('; '));
  console.log('  adjusted bank', r.bank_side.adjusted_cents, 'adjusted book', r.book_side.adjusted_cents, 'diff', r.difference_cents);
}
const csv = await fetch(BASE + '/reports/bank-reconciliation?statement_id=1&format=csv', { headers: { Authorization: 'Bearer ' + B } });
const bytes = new Uint8Array(await csv.arrayBuffer());
console.log('csv', csv.status, csv.headers.get('content-type'), bytes[0], bytes[1], bytes[2]);
console.log(new TextDecoder().decode(bytes).split('\r\n').slice(0, 14).join('\n'));
const sum = await call('GET', '/banking/summary', null, B);
console.log('summary', sum.status, JSON.stringify(sum.data).slice(0, 400));
