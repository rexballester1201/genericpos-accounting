// ar-test.mjs — receivables and payables, end to end, against the SCRATCH database only.
//
//   node ar-test.mjs [api base] [scratch db]
//   defaults: http://127.0.0.1:8781/api/v1  acc_scratch_ar
//
// Runs on a freshly seeded demo company (seed_demo). It posts, cancels,
// closes and reopens a month and writes a bank match directly into the
// scratch database — never point it at the real one.

import { spawnSync } from 'node:child_process';

const BASE = process.argv[2] || 'http://127.0.0.1:8781/api/v1';
const DB = process.argv[3] || 'acc_scratch_ar';
if (!/scratch/.test(DB)) { console.log('Refusing to run against a database whose name does not say scratch: ' + DB); process.exit(2); }

let passed = 0;
let failed = 0;
const check = (name, cond, info) => {
  if (cond) passed++; else failed++;
  const more = !cond && info !== undefined ? '\n        ' + (typeof info === 'string' ? info : JSON.stringify(info)).slice(0, 900) : '';
  console.log((cond ? 'PASS  ' : 'FAIL  ') + name + more);
  return !!cond;
};
const note = (s) => console.log('        ' + s);

async function call(method, path, body, token) {
  const headers = { Accept: 'application/json' };
  if (body != null) headers['Content-Type'] = 'application/json';
  if (token) headers.Authorization = 'Bearer ' + token;
  const r = await fetch(BASE + path, { method, headers, body: body == null ? undefined : JSON.stringify(body) });
  const text = await r.text();
  let json = null;
  try { json = JSON.parse(text); } catch { /* not JSON */ }
  return { status: r.status, ok: r.ok && !!json && json.status === true, data: json ? json.data : null, message: json ? json.message : 'NOT JSON: ' + text.slice(0, 400) };
}
const GET = (p, t) => call('GET', p, null, t);
const POST = (p, b, t) => call('POST', p, b || {}, t);
const PUT = (p, b, t) => call('PUT', p, b || {}, t);
const DEL = (p, t) => call('DELETE', p, null, t);

async function csv(path, token) {
  const r = await fetch(BASE + path, { headers: { Authorization: 'Bearer ' + token } });
  const bytes = new Uint8Array(await r.arrayBuffer());
  return { ok: r.ok, type: r.headers.get('content-type') || '', bytes, text: new TextDecoder('utf-8', { ignoreBOM: true }).decode(bytes) };
}
const bom = (c) => c.ok && /text\/csv/.test(c.type) && c.bytes[0] === 0xEF && c.bytes[1] === 0xBB && c.bytes[2] === 0xBF;

function sql(q) {
  const r = spawnSync('D:/xampp/mysql/bin/mysql.exe', ['-u', 'root', '-N', '-B', DB, '-e', q], { encoding: 'utf8' });
  if (r.status !== 0) throw new Error('mysql: ' + r.stderr);
  return r.stdout.trim();
}

const seq = (no) => parseInt(String(no || '').replace(/^.*-/, ''), 10);
const addDays = (d, n) => { const t = new Date(d + 'T00:00:00Z'); t.setUTCDate(t.getUTCDate() + n); return t.toISOString().slice(0, 10); };
/** Journal lines as [code, debit, credit, contact id, department code] for comparing. */
const jl = (j) => j.lines.map((l) => [l.account_code, l.debit_cents, l.credit_cents, l.contact_id || null, l.department ? l.department.split(' · ')[0] : null]);
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const tbMap = (tb) => Object.fromEntries(tb.rows.map((r) => [r.code, r.debit_cents - r.credit_cents]));

try {
  // ── sign in ───────────────────────────────────────────────────────────
  const USERS = { admin: ['admin@example.test', 'DemoAdmin#2026'], accountant: ['accountant@example.test', 'DemoAcct#2026'],
    bookkeeper: ['bookkeeper@example.test', 'DemoBook#2026'], viewer: ['viewer@example.test', 'DemoView#2026'] };
  const T = {};
  for (const [role, [email, pw]] of Object.entries(USERS)) {
    const r = await POST('/auth/login', { identifier: email, password: pw });
    check('sign in as ' + role, r.ok, r.message);
    T[role] = r.data && r.data.access_token;
  }
  check('no token is refused (401)', (await GET('/contacts')).status === 401);
  check('reports need a sign-in (401)', (await GET('/reports/aging')).status === 401);

  // ── what the demo company has ─────────────────────────────────────────
  const acct = Object.fromEntries((await GET('/accounts', T.viewer)).data.items.map((a) => [a.code, a.id]));
  const lkS = await GET('/documents/lookups?side=sales', T.bookkeeper);
  check('document form lookups (sales)', lkS.ok && lkS.data.accounts.length > 0 && lkS.data.contacts.length >= 6 && lkS.data.tax.rate_bp === 1200, lkS.message);
  check('lookups leave out the control accounts', lkS.ok && !lkS.data.accounts.some((a) => a.code === '1121' || a.code === '2111'));
  const lkP = await GET('/documents/lookups?side=purchases', T.bookkeeper);
  check('document form lookups (purchases) list suppliers', lkP.ok && lkP.data.contacts.every((c) => /^S/.test(c.code)), lkP.message);
  const dept = Object.fromEntries(lkS.data.departments.map((d) => [d.code, d.id]));
  const today = lkS.data.today;
  const sl = await GET('/settlements/lookups?kind=receipt', T.bookkeeper);
  check('receipt lookups: cash and bank accounts from the cash class', sl.ok && sl.data.cash_accounts.some((a) => a.code === '1113') && !sl.data.cash_accounts.some((a) => a.code === '1121'), sl.data && sl.data.cash_accounts);
  note('today ' + today + '; cash accounts ' + (sl.data ? sl.data.cash_accounts.map((a) => a.code).join(', ') : ''));
  const cust = Object.fromEntries((await GET('/contacts?role=customer&per_page=100', T.viewer)).data.items.map((c) => [c.code, c]));
  const supp = Object.fromEntries((await GET('/contacts?role=supplier&per_page=100', T.viewer)).data.items.map((c) => [c.code, c]));
  check('customers list has the demo customers with balances', !!cust.C001 && typeof cust.C001.balance_cents === 'number' && cust.C001.balance_cents >= 0, Object.keys(cust));

  // ── role guards ───────────────────────────────────────────────────────
  check('a viewer cannot add a customer (403)', (await POST('/contacts', { code: 'X1', name: 'x', is_customer: true }, T.viewer)).status === 403);
  check('a viewer cannot prepare an invoice (403)', (await POST('/documents', { doc_type: 'invoice' }, T.viewer)).status === 403);
  check('a viewer cannot record a receipt (403)', (await POST('/settlements', { kind: 'receipt' }, T.viewer)).status === 403);

  // ── customers and suppliers ───────────────────────────────────────────
  const clk = await GET('/contacts/lookups', T.bookkeeper);
  check('the next codes follow the C / S series', clk.ok && /^C\d{3,}$/.test(clk.data.next_code.customer) && /^S\d{3,}$/.test(clk.data.next_code.supplier), clk.data && clk.data.next_code);
  const cCode = clk.data.next_code.customer;
  const sCode = clk.data.next_code.supplier;
  const bad = await POST('/contacts', { code: 'C001', name: '', tin: '12-34', email: 'nope', is_customer: false, is_supplier: false, ewt_rate_bp: 5000 }, T.bookkeeper);
  check('a bad contact is refused with every problem named (422)', bad.status === 422 && ['code', 'name', 'tin', 'email', 'is_customer', 'ewt_rate_bp'].every((k) => bad.data.errors[k]),
    bad.data && bad.data.errors);
  const nc = await POST('/contacts', { code: cCode, name: 'API Test Customer', is_customer: true, tin: '123-456-789-00000', address: 'Magsaysay Ave, Naga City',
    email: 'buyer@example.test', phone: '+63 54 111 2222', credit_limit_cents: 5000000, default_account_id: acct['4110'], vat_registered: true }, T.bookkeeper);
  check('a bookkeeper adds a customer ' + cCode, nc.status === 201 && nc.data.contact.code === cCode && nc.data.contact.terms_days === 30, nc.status + ' ' + nc.message);
  const C = nc.data.contact.id;
  const ns = await POST('/contacts', { code: sCode, name: 'API Test Supplier', is_supplier: true, tin: '987-654-321', ewt_rate_bp: 200, default_account_id: acct['6180'] }, T.bookkeeper);
  check('a bookkeeper adds a supplier ' + sCode + ' with 2 % EWT', ns.status === 201 && ns.data.contact.ewt_rate_bp === 200, ns.status + ' ' + ns.message);
  const S = ns.data.contact.id;
  const badAcct = await POST('/contacts', { code: 'C900', name: 'Wrong default', is_customer: true, default_account_id: acct['6180'] }, T.bookkeeper);
  check('a customer\'s default account must be an income account (422)', badAcct.status === 422 && badAcct.data.errors.default_account_id, badAcct.data);
  const up = await PUT('/contacts/' + C, { ...nc.data.contact, phone: '+63 54 111 3333' }, T.bookkeeper);
  check('a bookkeeper changes a customer', up.ok && up.data.contact.phone === '+63 54 111 3333', up.message);
  const tin = await GET('/contacts?role=customer&q=123456789', T.viewer);
  check('search finds a customer by TIN digits', tin.ok && tin.data.items.some((c) => c.id === C), tin.data && tin.data.items.map((c) => c.code));
  const tmp = await POST('/contacts', { code: 'C998', name: 'Throwaway Customer', is_customer: true }, T.bookkeeper);
  check('an unused contact can be deleted', tmp.status === 201 && (await DEL('/contacts/' + tmp.data.contact.id, T.bookkeeper)).ok);
  const dl = await DEL('/contacts/' + cust.C001.id, T.bookkeeper);
  check('a used contact cannot be deleted (409)', dl.status === 409 && /Deactivate/.test(dl.message), dl.status + ' ' + dl.message);
  const off = await POST('/contacts/' + C + '/active', { active: false }, T.bookkeeper);
  check('a bookkeeper deactivates a customer', off.ok && off.data.contact.is_active === false, off.message);
  const inact = await POST('/documents', { doc_type: 'invoice', contact_id: C, doc_date: today, lines: [{ account_id: acct['4110'], amount_cents: 10000 }] }, T.bookkeeper);
  check('an inactive customer takes no invoice (422)', inact.status === 422 && /inactive/.test(inact.data.errors.contact_id || ''), inact.data);
  check('and is reactivated', (await POST('/contacts/' + C + '/active', { active: true }, T.bookkeeper)).ok);
  const role = await PUT('/contacts/' + cust.C001.id, { ...cust.C001, is_customer: false, is_supplier: true }, T.bookkeeper);
  check('a customer with invoices stays a customer (422)', role.status === 422 && role.data.errors.is_customer, role.data);

  // ── validation ────────────────────────────────────────────────────────
  const v1 = await POST('/documents', { doc_type: 'invoice', contact_id: C, doc_date: today, lines: [] }, T.bookkeeper);
  check('an invoice needs a line (422)', v1.status === 422 && /at least one line/.test(v1.data.errors.lines || ''), v1.data);
  const v2 = await POST('/documents', { doc_type: 'invoice', contact_id: C, doc_date: today, due_date: addDays(today, -1),
    lines: [{ account_id: acct['1121'] || 999999, amount_cents: 100 }] }, T.bookkeeper);
  check('a control account on a line and a due date before the date are refused (422)', v2.status === 422 && /control account/.test(v2.data.errors.lines || '') && v2.data.errors.due_date, v2.data);
  const v3 = await POST('/documents', { doc_type: 'bill', contact_id: C, doc_date: today, lines: [{ account_id: acct['6180'], amount_cents: 100 }] }, T.bookkeeper);
  check('a customer cannot receive a bill (422)', v3.status === 422 && /not set up as a supplier/.test(v3.data.errors.contact_id || ''), v3.data);

  // ── invoice 1: VAT-inclusive, mixed lines, a department ───────────────
  const inv1Body = {
    doc_type: 'invoice', contact_id: C, doc_date: today, reference: 'PO 5501', description: 'Mixed goods', prices_include_tax: true,
    net_cents: 1, vat_cents: 1, total_cents: 1, // the browser's totals: ignored
    lines: [
      { account_id: acct['4110'], description: 'Canned goods, 2 cases', quantity: '2', unit_price_cents: 560000, vat_mode: 'vatable', department_id: dept.SLS },
      { account_id: acct['4110'], description: 'Rice (VAT-exempt)', quantity: '1', amount_cents: 100000, vat_mode: 'exempt' },
      { account_id: acct['4110'], description: 'Soap, 3 packs', quantity: '3', unit_price_cents: 33333, vat_mode: 'vatable' },
    ],
  };
  const i1 = await POST('/documents', inv1Body, T.bookkeeper);
  const d1 = i1.data && i1.data.document;
  check('a bookkeeper saves an invoice draft (no number yet)', i1.status === 201 && d1.status === 'draft' && d1.doc_no === null, i1.status + ' ' + i1.message);
  check('inclusive VAT per line: 11,200.00 → 10,000.00 + 1,200.00; 999.99 → 892.85 + 107.14; exempt 1,000.00',
    same(i1.data.lines.map((l) => [l.amount_cents, l.net_cents, l.vat_cents]), [[1120000, 1000000, 120000], [100000, 100000, 0], [99999, 89285, 10714]]), i1.data && i1.data.lines);
  check('the server\'s totals, not the browser\'s: net 11,892.85, VAT 1,307.14, total 13,199.99',
    d1.net_cents === 1189285 && d1.vat_cents === 130714 && d1.total_cents === 1319999, [d1.net_cents, d1.vat_cents, d1.total_cents]);
  check('the due date follows the terms (30 days)', d1.due_date === addDays(today, 30), d1.due_date);
  check('the breakdown splits VATable and VAT-exempt sales', i1.data.breakdown.vatable_cents === 1089285 && i1.data.breakdown.exempt_cents === 100000, i1.data.breakdown);
  check('its preparer may edit it; nobody posts it from here', i1.data.can.edit && !i1.data.can.post, i1.data.can);
  const e1 = await PUT('/documents/' + d1.id, { ...inv1Body, description: 'Mixed goods (edited)' }, T.bookkeeper);
  check('its preparer edits the draft', e1.ok && e1.data.document.description === 'Mixed goods (edited)', e1.message);
  const e1b = await PUT('/documents/' + d1.id, inv1Body, T.accountant);
  check('nobody else edits it', !e1b.ok, e1b.status + ' ' + JSON.stringify(e1b.data));
  check('a bookkeeper cannot post (403)', (await POST('/documents/' + d1.id + '/post', {}, T.bookkeeper)).status === 403);
  const p1 = await POST('/documents/' + d1.id + '/post', {}, T.accountant);
  const invNo1 = p1.data && p1.data.document.doc_no;
  check('the accountant posts it and it takes the next number (' + invNo1 + ')', p1.ok && /^INV-\d{6}$/.test(invNo1 || '') && p1.data.document.status === 'posted', p1.message);
  check('the toast message names it', /^Invoice INV-\d+ posted\.$/.test(p1.message), p1.message);
  check('posting twice is refused (409)', (await POST('/documents/' + d1.id + '/post', {}, T.accountant)).status === 409);
  check('a posted invoice cannot be edited', !(await PUT('/documents/' + d1.id, inv1Body, T.bookkeeper)).ok);
  const j1 = await GET('/journals/' + p1.data.document.journal_id, T.viewer);
  check('its journal: sales book, source invoice, the document id', j1.ok && j1.data.journal.book === 'sales' && j1.data.journal.source === 'invoice'
    && j1.data.journal.source_id === d1.id && j1.data.journal.reference === invNo1 && j1.data.journal.party_name === 'API Test Customer'
    && j1.data.journal.description === 'Invoice ' + invNo1 + ' — API Test Customer', j1.data && j1.data.journal);
  check('its lines: Dr AR (customer) · Cr sales per line (department) · Cr output VAT', same(jl(j1.data), [
    ['1121', 1319999, 0, C, null], ['4110', 0, 1000000, null, 'SLS'], ['4110', 0, 100000, null, null], ['4110', 0, 89285, null, null], ['2121', 0, 130714, null, null],
  ]) && j1.data.journal.total_cents === 1319999, jl(j1.data));

  // ── invoice 2: VAT-exclusive, zero-rated; maker-checker ───────────────
  const i2 = await POST('/documents', { doc_type: 'invoice', contact_id: C, doc_date: today, prices_include_tax: false, then: 'post',
    lines: [{ account_id: acct['4110'], description: 'Export crate', quantity: '1.5', unit_price_cents: 100000 },
            { account_id: acct['4110'], description: 'Zero-rated sale', quantity: '1', amount_cents: 50000, vat_mode: 'zero_rated' }] }, T.accountant);
  const d2 = i2.data && i2.data.document;
  check('exclusive VAT: 1.5 × 1,000.00 = 1,500.00 + 180.00 VAT; zero-rated 500.00; total 2,180.00',
    i2.status === 201 && d2.net_cents === 200000 && d2.vat_cents === 18000 && d2.total_cents === 218000
    && same(i2.data.lines.map((l) => [l.amount_cents, l.net_cents, l.vat_cents]), [[150000, 150000, 18000], [50000, 50000, 0]]), i2.data && [d2, i2.data.lines]);
  check('"save and post" by its own preparer stops at the draft (self-approval off)', d2.status === 'draft' && /other than you/.test(i2.data.notice.text), i2.data && i2.data.notice);
  const own = await POST('/documents/' + d2.id + '/post', {}, T.accountant);
  check('its preparer cannot post it (409)', own.status === 409 && /another accountant/.test(own.message), own.status + ' ' + own.message);
  const p2 = await POST('/documents/' + d2.id + '/post', {}, T.admin);
  const invNo2 = p2.data && p2.data.document.doc_no;
  check('another approver posts it with the next number', p2.ok && seq(invNo2) === seq(invNo1) + 1, [invNo1, invNo2, p2.message]);
  const j2 = await GET('/journals/' + p2.data.document.journal_id, T.viewer);
  check('its journal lines', j2.ok && same(jl(j2.data), [['1121', 218000, 0, C, null], ['4110', 0, 150000, null, null], ['4110', 0, 50000, null, null], ['2121', 0, 18000, null, null]]), j2.data && jl(j2.data));

  // ── a bill with a department ──────────────────────────────────────────
  const bl = await POST('/documents', { doc_type: 'bill', contact_id: S, doc_date: today, reference: 'SI 77777',
    lines: [{ account_id: acct['6180'], description: 'Truck tyres', amount_cents: 560000, department_id: dept.WHS }] }, T.bookkeeper);
  const db1 = bl.data && bl.data.document;
  check('a bill draft: 5,600.00 inclusive → 5,000.00 + 600.00', bl.status === 201 && db1.net_cents === 500000 && db1.vat_cents === 60000 && db1.total_cents === 560000, bl.status + ' ' + bl.message);
  const pb = await POST('/documents/' + db1.id + '/post', {}, T.accountant);
  const billNo = pb.data && pb.data.document.doc_no;
  check('the accountant posts it (' + billNo + ')', pb.ok && /^BL-\d{6}$/.test(billNo || ''), pb.message);
  const jb = await GET('/journals/' + pb.data.document.journal_id, T.viewer);
  check('its journal: purchases book, source bill, the supplier\'s invoice number as reference', jb.ok && jb.data.journal.book === 'purchases'
    && jb.data.journal.source === 'bill' && jb.data.journal.source_id === db1.id && jb.data.journal.reference === 'SI 77777', jb.data && jb.data.journal);
  check('its lines: Dr expense (department) · Dr input VAT · Cr AP (supplier)', same(jl(jb.data), [['6180', 500000, 0, null, 'WHS'], ['1144', 60000, 0, null, null], ['2111', 0, 560000, S, null]]), jb.data && jl(jb.data));
  const dup = await POST('/documents', { doc_type: 'bill', contact_id: S, doc_date: today, reference: 'si 77777', lines: [{ account_id: acct['6180'], amount_cents: 1000 }] }, T.bookkeeper);
  check('a second bill with the same supplier reference is flagged', dup.status === 201 && dup.data.warnings.length === 1 && /entered twice/.test(dup.data.warnings[0]), dup.data && dup.data.warnings);
  check('a viewer cannot delete a draft (403)', (await DEL('/documents/' + dup.data.document.id, T.viewer)).status === 403);
  const dd = await DEL('/documents/' + dup.data.document.id, T.bookkeeper);
  check('its preparer deletes the draft', dd.ok && (await GET('/documents/' + dup.data.document.id, T.viewer)).status === 404, dd.message);

  // ── a credit note applied to its invoice ──────────────────────────────
  const cn = await POST('/documents', { doc_type: 'credit_note', contact_id: C, doc_date: today, related_document_id: d1.id, description: 'Damaged cans returned',
    lines: [{ account_id: acct['4120'], description: 'Returned goods', amount_cents: 112000 }] }, T.bookkeeper);
  check('a credit note draft against ' + invNo1, cn.status === 201 && cn.data.document.total_cents === 112000 && cn.data.related.id === d1.id, cn.status + ' ' + cn.message);
  const pc = await POST('/documents/' + cn.data.document.id + '/post', {}, T.accountant);
  const cnNo = pc.data && pc.data.document.doc_no;
  check('posted (' + cnNo + ') and applied to the invoice at once', pc.ok && pc.data.document.applied_cents === 112000 && pc.data.document.state === 'applied'
    && pc.data.applied_to.length === 1 && pc.data.applied_to[0].document_id === d1.id, pc.data && pc.data.document);
  const jc = await GET('/journals/' + pc.data.document.journal_id, T.viewer);
  check('its journal: Dr sales returns · Dr output VAT · Cr AR (customer)', jc.ok && jc.data.journal.source === 'credit_note' && jc.data.journal.book === 'sales'
    && same(jl(jc.data), [['4120', 100000, 0, null, null], ['2121', 12000, 0, null, null], ['1121', 0, 112000, C, null]]), jc.data && jl(jc.data));
  const a1 = await GET('/documents/' + d1.id, T.viewer);
  check('the invoice is open by 12,079.99, its credit shown', a1.data.document.open_cents === 1207999 && a1.data.allocations.some((x) => x.source === 'note' && x.amount_cents === 112000), a1.data.document);
  check('an applied credit note cannot be cancelled (409)', (await POST('/documents/' + cn.data.document.id + '/cancel', { reason: 'test' }, T.accountant)).status === 409);
  const pp = await POST('/documents/' + d1.id + '/cancel', { reason: 'test' }, T.accountant);
  check('a partly settled invoice cannot be cancelled (409)', pp.status === 409 && /Remove them first/.test(pp.message), pp.status + ' ' + pp.message);

  // ── a receipt with CWT, allocated to two invoices ─────────────────────
  const ot = await GET('/documents/open-items?side=ar&contact_id=' + C, T.bookkeeper);
  check('open items: the two invoices, oldest first', ot.ok && same(ot.data.targets.map((d) => d.id), [d1.id, d2.id]) && ot.data.targets[0].open_cents === 1207999, ot.data);
  const cwt = 10893 + 2000;
  const rBody = { kind: 'receipt', contact_id: C, settle_date: today, cash_account_id: acct['1113'], reference: 'OR 9001', amount_cents: 1425999 - cwt, withholding_cents: cwt,
    description: 'Collection', allocations: [{ document_id: d1.id, amount_cents: 1207999 }, { document_id: d2.id, amount_cents: 218000 }] };
  const over = await POST('/settlements', { ...rBody, allocations: [{ document_id: d1.id, amount_cents: 1208000 }] }, T.bookkeeper);
  check('overpaying an invoice is refused (422)', over.status === 422 && /left to pay/.test(over.data.errors.allocations || ''), over.data);
  const toomuch = await POST('/settlements', { ...rBody, amount_cents: 1000 }, T.bookkeeper);
  check('allocations above the receipt are refused (422)', toomuch.status === 422 && /more than this receipt/.test(toomuch.data.errors.allocations || ''), toomuch.data);
  const vr = await POST('/settlements', { kind: 'receipt', contact_id: C, settle_date: today, cash_account_id: acct['4110'], amount_cents: 0 }, T.bookkeeper);
  check('a receipt needs an amount and a cash account (422)', vr.status === 422 && vr.data.errors.amount_cents && vr.data.errors.cash_account_id, vr.data);
  const r1 = await POST('/settlements', rBody, T.bookkeeper);
  const s1 = r1.data && r1.data.settlement;
  check('a bookkeeper saves the receipt draft with its plan', r1.status === 201 && s1.status === 'draft' && r1.data.plan.length === 2 && r1.data.allocations.length === 0, r1.status + ' ' + r1.message);
  const a1b = await GET('/documents/' + d1.id, T.viewer);
  check('a draft receipt settles nothing yet', a1b.data.document.open_cents === 1207999, a1b.data.document);
  check('a bookkeeper cannot post a receipt (403)', (await POST('/settlements/' + s1.id + '/post', {}, T.bookkeeper)).status === 403);
  const pr1 = await POST('/settlements/' + s1.id + '/post', {}, T.accountant);
  const rcNo = pr1.data && pr1.data.settlement.settle_no;
  check('the accountant posts it (' + rcNo + '), both invoices paid', pr1.ok && /^RC-\d{6}$/.test(rcNo || '') && pr1.data.allocations.length === 2 && pr1.data.settlement.unapplied_cents === 0, pr1.message);
  const jr1 = await GET('/journals/' + pr1.data.settlement.journal_id, T.viewer);
  check('its journal: cash receipts, source receipt, the OR number', jr1.ok && jr1.data.journal.book === 'cash_receipts' && jr1.data.journal.source === 'receipt'
    && jr1.data.journal.source_id === s1.id && jr1.data.journal.reference === 'OR 9001', jr1.data && jr1.data.journal);
  check('its lines: Dr cash · Dr CWT · Cr AR (customer) for cash + CWT', same(jl(jr1.data), [['1113', 1425999 - cwt, 0, null, null], ['1145', cwt, 0, null, null], ['1121', 0, 1425999, C, null]]), jr1.data && jl(jr1.data));
  const paid = await GET('/documents?side=sales&state=paid&contact_id=' + C, T.viewer);
  check('both invoices are paid', paid.ok && [d1.id, d2.id].every((id) => paid.data.items.some((d) => d.id === id && d.state === 'paid')), paid.data && paid.data.items.map((d) => [d.doc_no, d.state]));
  check('the receipt amount is written in words', /^Fourteen thousand one hundred thirty-one pesos and 06\/100$/.test(pr1.data.amount_in_words), pr1.data.amount_in_words);

  // ── posting refuses what another receipt already paid ─────────────────
  const i3 = await POST('/documents', { doc_type: 'invoice', contact_id: C, doc_date: today, lines: [{ account_id: acct['4110'], description: 'Goods', amount_cents: 336000 }] }, T.bookkeeper);
  const d3 = i3.data.document;
  const p3 = await POST('/documents/' + d3.id + '/post', {}, T.accountant);
  const invNo3 = p3.data && p3.data.document.doc_no;
  check('invoice 3 posted with the next number', p3.ok && seq(invNo3) === seq(invNo2) + 1, [invNo2, invNo3]);
  const twinA = await POST('/settlements', { kind: 'receipt', contact_id: C, settle_date: today, cash_account_id: acct['1113'], amount_cents: 336000, allocations: [{ document_id: d3.id, amount_cents: 336000 }] }, T.bookkeeper);
  const twinB = await POST('/settlements', { kind: 'receipt', contact_id: C, settle_date: today, cash_account_id: acct['1113'], amount_cents: 336000, allocations: [{ document_id: d3.id, amount_cents: 336000 }] }, T.bookkeeper);
  check('two drafts may plan to pay the same invoice', twinA.status === 201 && twinB.status === 201);
  check('the first posts', (await POST('/settlements/' + twinA.data.settlement.id + '/post', {}, T.accountant)).ok);
  const tb2 = await POST('/settlements/' + twinB.data.settlement.id + '/post', {}, T.accountant);
  check('the second is refused at posting: nothing left to pay (409)', tb2.status === 409 && /left to pay/.test(tb2.message), tb2.status + ' ' + tb2.message);
  const del2 = await DEL('/settlements/' + twinB.data.settlement.id, T.accountant);
  check('an accountant deletes someone else\'s draft receipt', del2.ok, del2.message);

  // ── an unapplied receipt, applied later ───────────────────────────────
  const i4 = await POST('/documents', { doc_type: 'invoice', contact_id: C, doc_date: today, lines: [{ account_id: acct['4110'], description: 'More goods', amount_cents: 224000 }] }, T.bookkeeper);
  const d4 = i4.data.document;
  check('invoice 4 posted', (await POST('/documents/' + d4.id + '/post', {}, T.accountant)).ok);
  const r2 = await POST('/settlements', { kind: 'receipt', contact_id: C, settle_date: today, cash_account_id: acct['1113'], reference: 'OR 9002', amount_cents: 300000 }, T.bookkeeper);
  const s2 = r2.data.settlement;
  const pr2 = await POST('/settlements/' + s2.id + '/post', {}, T.accountant);
  check('a receipt with nothing applied posts and stays unapplied', pr2.ok && pr2.data.settlement.unapplied_cents === 300000 && pr2.data.can.apply === true, pr2.message);
  const pr2b = await GET('/settlements/' + s2.id, T.bookkeeper);
  check('a bookkeeper may apply it', pr2b.ok && pr2b.data.can.apply === true, pr2b.data && pr2b.data.can);
  const ap1 = await POST('/settlements/' + s2.id + '/apply', { allocations: [{ document_id: d4.id, amount_cents: 224000 }] }, T.bookkeeper);
  check('applied later to invoice 4: paid, 760.00 left unapplied', ap1.ok && ap1.data.settlement.unapplied_cents === 76000 && ap1.data.allocations.length === 1, ap1.message);
  const i5 = await POST('/documents', { doc_type: 'invoice', contact_id: C, doc_date: today, lines: [{ account_id: acct['4110'], description: 'Small order', amount_cents: 100000 }] }, T.bookkeeper);
  const d5 = i5.data.document;
  await POST('/documents/' + d5.id + '/post', {}, T.accountant);
  const ap2 = await POST('/settlements/' + s2.id + '/apply', { allocations: [{ document_id: d5.id, amount_cents: 80000 }] }, T.bookkeeper);
  check('applying more than is left is refused (409)', ap2.status === 409 && /what is left/.test(ap2.message), ap2.status + ' ' + ap2.message);

  // ── removing an allocation ────────────────────────────────────────────
  const alloc = ap1.data.allocations[0];
  check('a bookkeeper cannot remove an allocation (403)', (await POST('/allocations/' + alloc.id + '/remove', { reason: 'x' }, T.bookkeeper)).status === 403);
  check('removing needs a reason (422)', (await POST('/allocations/' + alloc.id + '/remove', {}, T.accountant)).status === 422);
  const rm = await POST('/allocations/' + alloc.id + '/remove', { reason: 'Applied to the wrong invoice' }, T.accountant);
  const d4b = await GET('/documents/' + d4.id, T.viewer);
  const s2b = await GET('/settlements/' + s2.id, T.viewer);
  check('removed: invoice 4 open again, the receipt unapplied again', rm.ok && d4b.data.document.open_cents === 224000 && s2b.data.settlement.unapplied_cents === 300000, rm.message);

  // ── credit notes: capped at the invoice, used inside a receipt, applied later ──
  const i6 = await POST('/documents', { doc_type: 'invoice', contact_id: C, doc_date: today, lines: [{ account_id: acct['4110'], amount_cents: 112000 }] }, T.bookkeeper);
  const d6 = i6.data.document;
  await POST('/documents/' + d6.id + '/post', {}, T.accountant);
  const r3 = await POST('/settlements', { kind: 'receipt', contact_id: C, settle_date: today, cash_account_id: acct['1111'], amount_cents: 100000,
    allocations: [{ document_id: d6.id, amount_cents: 100000 }] }, T.bookkeeper);
  check('a cash receipt pays 1,000.00 of invoice 6', (await POST('/settlements/' + r3.data.settlement.id + '/post', {}, T.accountant)).ok);
  const cn2 = await POST('/documents', { doc_type: 'credit_note', contact_id: C, doc_date: today, related_document_id: d6.id,
    lines: [{ account_id: acct['4120'], amount_cents: 112000 }] }, T.bookkeeper);
  const pc2 = await POST('/documents/' + cn2.data.document.id + '/post', {}, T.accountant);
  check('a credit note is applied only up to the invoice\'s open 120.00; 1,000.00 stays unapplied', pc2.ok && pc2.data.document.applied_cents === 12000 && pc2.data.document.open_cents === 100000, pc2.data && pc2.data.document);
  const r4 = await POST('/settlements', { kind: 'receipt', contact_id: C, settle_date: today, cash_account_id: acct['1113'], amount_cents: 124000, reference: 'OR 9004',
    allocations: [{ document_id: d4.id, amount_cents: 224000 }, { document_id: cn2.data.document.id, amount_cents: 100000 }] }, T.bookkeeper);
  check('a receipt draft uses the credit note toward invoice 4', r4.status === 201 && r4.data.plan.length === 2, r4.status + ' ' + JSON.stringify(r4.data && r4.data.errors));
  const pr4 = await POST('/settlements/' + r4.data.settlement.id + '/post', {}, T.accountant);
  const d4c = await GET('/documents/' + d4.id, T.viewer);
  check('posted: invoice 4 paid by the note (1,000.00) and the receipt (1,240.00)', pr4.ok && d4c.data.document.open_cents === 0
    && same(d4c.data.allocations.map((x) => [x.source, x.amount_cents]).sort(), [['note', 100000], ['settlement', 124000]]), d4c.data.allocations);
  const cn3 = await POST('/documents', { doc_type: 'credit_note', contact_id: C, doc_date: today, description: 'Volume rebate', lines: [{ account_id: acct['4120'], amount_cents: 56000 }] }, T.bookkeeper);
  await POST('/documents/' + cn3.data.document.id + '/post', {}, T.accountant);
  const ap3 = await POST('/documents/' + cn3.data.document.id + '/apply', { allocations: [{ document_id: d3.id, amount_cents: 1 }] }, T.bookkeeper);
  check('a credit note is not applied to an invoice that is already paid (409)', ap3.status === 409 && /left to pay/.test(ap3.message), ap3.status + ' ' + ap3.message);
  const ap4 = await POST('/documents/' + cn3.data.document.id + '/apply', { allocations: [{ document_id: d5.id, amount_cents: 56000 }] }, T.bookkeeper);
  check('an unapplied credit note is applied later to invoice 5', ap4.ok && ap4.data.document.open_cents === 0 && ap4.data.applied_to[0].document_id === d5.id, ap4.message);

  // ── a payment with EWT ────────────────────────────────────────────────
  const ewt = Math.round(500000 * 200 / 10000);
  const py = await POST('/settlements', { kind: 'payment', contact_id: S, settle_date: today, cash_account_id: acct['1113'], reference: 'CHK 777001', amount_cents: 560000 - ewt,
    withholding_cents: ewt, allocations: [{ document_id: db1.id, amount_cents: 560000 }] }, T.bookkeeper);
  check('a payment draft with 2 % EWT (100.00)', py.status === 201 && py.data.settlement.withholding_cents === 10000, py.status + ' ' + py.message);
  const ownp = await POST('/settlements', { kind: 'payment', contact_id: S, settle_date: today, cash_account_id: acct['1113'], amount_cents: 100 }, T.accountant);
  check('an accountant cannot post their own payment (409)', (await POST('/settlements/' + ownp.data.settlement.id + '/post', {}, T.accountant)).status === 409);
  check('and deletes it', (await DEL('/settlements/' + ownp.data.settlement.id, T.accountant)).ok);
  const ppy = await POST('/settlements/' + py.data.settlement.id + '/post', {}, T.accountant);
  const pvNo = ppy.data && ppy.data.settlement.settle_no;
  check('the accountant posts it (' + pvNo + ') and the bill is paid', ppy.ok && /^PV-\d{6}$/.test(pvNo || '') && ppy.data.allocations[0].amount_cents === 560000, ppy.message);
  const jp = await GET('/journals/' + ppy.data.settlement.journal_id, T.viewer);
  check('its journal: cash disbursements, source payment, the cheque number', jp.ok && jp.data.journal.book === 'cash_disbursements' && jp.data.journal.source === 'payment'
    && jp.data.journal.source_id === py.data.settlement.id && jp.data.journal.reference === 'CHK 777001', jp.data && jp.data.journal);
  check('its lines: Dr AP (supplier) · Cr cash · Cr EWT payable', same(jl(jp.data), [['2111', 560000, 0, S, null], ['1113', 0, 550000, null, null], ['2123', 0, 10000, null, null]]), jp.data && jl(jp.data));
  const billNow = await GET('/documents/' + db1.id, T.viewer);
  check('the bill shows it is paid by ' + pvNo, billNow.data.document.state === 'paid' && billNow.data.allocations[0].source_no === pvNo, billNow.data.document.state);

  // ── cancelling ────────────────────────────────────────────────────────
  const tbBefore = await GET('/reports/trial-balance', T.viewer);
  const i7 = await POST('/documents', { doc_type: 'invoice', contact_id: C, doc_date: today, lines: [{ account_id: acct['4110'], amount_cents: 44800 }] }, T.bookkeeper);
  const d7 = i7.data.document;
  const p7 = await POST('/documents/' + d7.id + '/post', {}, T.accountant);
  const tbMid = await GET('/reports/trial-balance', T.viewer);
  check('invoice 7 posted and the trial balance moved', p7.ok && JSON.stringify(tbMap(tbMid.data)) !== JSON.stringify(tbMap(tbBefore.data)));
  check('a bookkeeper cannot cancel (403)', (await POST('/documents/' + d7.id + '/cancel', { reason: 'x' }, T.bookkeeper)).status === 403);
  check('cancelling needs a reason (422)', (await POST('/documents/' + d7.id + '/cancel', {}, T.accountant)).status === 422);
  const c7 = await POST('/documents/' + d7.id + '/cancel', { reason: 'Keyed twice' }, T.accountant);
  check('the accountant cancels the unapplied invoice: its journal is reversed', c7.ok && c7.data.document.status === 'cancelled' && c7.data.reversal && /reversed by/.test(c7.message), c7.message);
  const jrev = await GET('/journals/' + c7.data.reversal.id, T.viewer);
  check('the reversal mirrors the invoice\'s journal', jrev.ok && jrev.data.journal.source === 'reversal' && jrev.data.reversal_of.id === p7.data.document.journal_id
    && same(jl(jrev.data), [['1121', 0, 44800, C, null], ['4110', 40000, 0, null, null], ['2121', 4800, 0, null, null]]), jrev.data && jl(jrev.data));
  const tbAfter = await GET('/reports/trial-balance', T.viewer);
  check('the trial balance is as it was before invoice 7', JSON.stringify(tbMap(tbAfter.data)) === JSON.stringify(tbMap(tbBefore.data)) && tbAfter.data.balanced);
  check('a cancelled invoice cannot be cancelled again (409)', (await POST('/documents/' + d7.id + '/cancel', { reason: 'again' }, T.accountant)).status === 409);

  // a receipt matched to a bank line cannot be cancelled; unmatched, it can
  const s1j = pr1.data.settlement.journal_id;
  const lineId = sql('SELECT id FROM gp_journal_lines WHERE journal_id = ' + s1j + ' AND debit_cents > 0 ORDER BY line_no LIMIT 1');
  const bankLine = sql('SELECT id FROM gp_bank_lines ORDER BY id LIMIT 1');
  check('the demo has a bank statement line to match against', !!bankLine);
  sql('INSERT INTO gp_bank_matches (bank_line_id, journal_line_id, matched_by, matched_at) VALUES (' + bankLine + ', ' + lineId + ', 1, UTC_TIMESTAMP())');
  const cm = await POST('/settlements/' + s1.id + '/cancel', { reason: 'Bounced' }, T.accountant);
  check('a receipt matched in Banking cannot be cancelled (409)', cm.status === 409 && /Unmatch it in Banking first/.test(cm.message), cm.status + ' ' + cm.message);
  sql('DELETE FROM gp_bank_matches WHERE journal_line_id = ' + lineId);
  const cr1 = await POST('/settlements/' + s1.id + '/cancel', { reason: 'The cheque bounced' }, T.accountant);
  const d1c = await GET('/documents/' + d1.id, T.viewer);
  const d2c = await GET('/documents/' + d2.id, T.viewer);
  check('cancelling the receipt removes its allocations and reopens both invoices', cr1.ok && cr1.data.settlement.status === 'cancelled' && cr1.data.allocations.length === 0
    && d1c.data.document.open_cents === 1207999 && d2c.data.document.open_cents === 218000, cr1.message);
  const jrr = await GET('/journals/' + cr1.data.reversal.id, T.viewer);
  check('its journal is reversed', jrr.ok && same(jl(jrr.data), [['1113', 0, 1425999 - cwt, null, null], ['1145', 0, cwt, null, null], ['1121', 1425999, 0, C, null]]), jrr.data && jl(jrr.data));

  // ── numbers stay gap-free across a failed posting ─────────────────────
  const fys = await GET('/fiscal-years', T.accountant);
  const periods = fys.data.years.flatMap((y) => y.periods);
  const cur = periods.find((p) => p.start_date <= today && today <= p.end_date);
  const prev = periods.find((p) => p.end_date === addDays(cur.start_date, -1));
  check('last month is open in the demo (' + (prev && prev.name) + ')', !!prev && prev.status === 'open', prev);
  const lateDate = addDays(prev.start_date, 14);
  const i8 = await POST('/documents', { doc_type: 'invoice', contact_id: C, doc_date: lateDate, lines: [{ account_id: acct['4110'], amount_cents: 11200 }] }, T.bookkeeper);
  check('an invoice draft dated ' + lateDate, i8.status === 201, i8.message);
  const close = await POST('/periods/' + prev.id + '/status', { status: 'closed' }, T.accountant);
  check('the accountant closes ' + prev.name, close.ok, close.message);
  const p8 = await POST('/documents/' + i8.data.document.id + '/post', {}, T.accountant);
  check('posting into the closed month is refused with the period\'s reason (409)', p8.status === 409 && new RegExp(prev.name + ' is closed').test(p8.message), p8.status + ' ' + p8.message);
  const still = await GET('/documents/' + i8.data.document.id, T.viewer);
  check('it stays a draft without a number', still.data.document.status === 'draft' && still.data.document.doc_no === null);
  const i9 = await POST('/documents', { doc_type: 'invoice', contact_id: C, doc_date: today, lines: [{ account_id: acct['4110'], amount_cents: 22400 }] }, T.bookkeeper);
  const p9 = await POST('/documents/' + i9.data.document.id + '/post', {}, T.accountant);
  const invNo7 = p7.data.document.doc_no;
  check('the next posting takes the next number, no gap (' + invNo7 + ' → ' + (p9.data && p9.data.document.doc_no) + ')',
    p9.ok && seq(p9.data.document.doc_no) === seq(invNo7) + 1, [invNo7, p9.data && p9.data.document.doc_no]);
  check('the accountant reopens ' + prev.name, (await POST('/periods/' + prev.id + '/status', { status: 'open' }, T.accountant)).ok);
  const p8b = await POST('/documents/' + i8.data.document.id + '/post', {}, T.accountant);
  check('reopened, the back-dated invoice posts with the number after that', p8b.ok && seq(p8b.data.document.doc_no) === seq(p9.data.document.doc_no) + 1, p8b.message);

  // ── credit limit warning ──────────────────────────────────────────────
  const big = await POST('/documents', { doc_type: 'invoice', contact_id: C, doc_date: today, lines: [{ account_id: acct['4110'], amount_cents: 4000000 }] }, T.bookkeeper);
  check('an invoice that takes the customer over the limit is flagged, not blocked', big.status === 201 && big.data.credit && big.data.credit.over === true
    && big.data.credit.limit_cents === 5000000, big.data && big.data.credit);
  await DEL('/documents/' + big.data.document.id, T.bookkeeper);

  // ── lists ─────────────────────────────────────────────────────────────
  const ov = await GET('/documents?side=sales&state=overdue&per_page=100', T.viewer);
  check('overdue invoices list: every one overdue', ov.ok && ov.data.items.length > 0 && ov.data.items.every((d) => d.state === 'overdue' && d.days_overdue > 0), ov.data && ov.data.items.slice(0, 3));
  const cnl = await GET('/documents?side=sales&type=credit_note', T.viewer);
  check('credit notes list', cnl.ok && cnl.data.items.length >= 3 && cnl.data.items.every((d) => d.doc_type === 'credit_note'), cnl.data && cnl.data.items.length);
  const qn = await GET('/documents?side=sales&q=' + encodeURIComponent(invNo1), T.viewer);
  check('search by number', qn.ok && qn.data.items.length === 1 && qn.data.items[0].id === d1.id);
  const bills = await GET('/documents?side=purchases&status=posted&per_page=5', T.viewer);
  check('bills list with totals and status counts', bills.ok && bills.data.items.every((d) => d.side === 'purchases') && typeof bills.data.totals.open_cents === 'number' && bills.data.counts.posted > 0, bills.data && bills.data.totals);
  const un = await GET('/settlements?kind=receipt&state=unapplied', T.viewer);
  check('unapplied receipts list', un.ok && un.data.items.some((s) => s.id === s2.id && s.unapplied_cents === 300000), un.data && un.data.items.map((s) => [s.settle_no, s.unapplied_cents]));
  const cd = await GET('/contacts/' + C, T.viewer);
  check('the customer page: balance, open documents with days overdue, activity', cd.ok && typeof cd.data.sides.ar.balance_cents === 'number' && cd.data.open_documents.length > 0 && cd.data.activity.length > 0, cd.message);

  // ── the reports tie to the ledger ─────────────────────────────────────
  for (const side of ['ar', 'ap']) {
    const ag = await GET('/reports/aging?side=' + side + '&as_of=' + today, T.viewer);
    check('aging ' + side.toUpperCase() + ': total ' + (ag.data && ag.data.totals.net_cents) + ' = control account ' + (ag.data && ag.data.ledger.control_cents),
      ag.ok && ag.data.totals.net_cents === ag.data.ledger.control_cents && ag.data.ledger.difference_cents === 0 && ag.data.rows.every((r) => r.difference_cents === 0), ag.data && [ag.data.totals, ag.data.ledger.difference_cents]);
    const buckets = ag.data.rows.reduce((s, r) => s + r.buckets.reduce((a, b) => a + b, 0), 0);
    check('aging ' + side.toUpperCase() + ': the buckets add up, five of them', ag.data.labels.length === 5 && buckets === ag.data.totals.open_cents, ag.data.labels);
    const sub = await GET('/reports/subsidiary-ledger?side=' + side + '&as_of=' + today, T.viewer);
    check('subsidiary ledger ' + side.toUpperCase() + ' = control account', sub.ok && sub.data.ties === true && sub.data.total_cents === sub.data.control_cents && sub.data.control_cents === ag.data.ledger.control_cents,
      sub.data && [sub.data.total_cents, sub.data.control_cents]);
  }
  const mid = addDays(cur.start_date, -40);
  const agOld = await GET('/reports/aging?side=ar&as_of=' + mid, T.viewer);
  check('aging at an earlier date (' + mid + ') ties too', agOld.ok && agOld.data.ledger.difference_cents === 0 && agOld.data.totals.net_cents === agOld.data.ledger.control_cents, agOld.data && agOld.data.ledger);
  const c4 = cust.C004;
  const sub4 = await GET('/reports/subsidiary-ledger?side=ar', T.viewer);
  const bal4 = (sub4.data.rows.find((r) => r.contact_id === c4.id) || {}).balance_cents;
  const soa = await GET('/reports/customer-statement?contact_id=' + c4.id + '&to=' + today, T.viewer);
  check('statement of account for ' + c4.name + ' closes on its ledger balance (' + bal4 + ')', soa.ok && soa.data.closing_cents === bal4 && soa.data.ties === true
    && soa.data.opening_cents + soa.data.debit_cents - soa.data.credit_cents === soa.data.closing_cents, soa.data && [soa.data.opening_cents, soa.data.closing_cents, soa.data.aging]);
  check('its lines name the documents behind them', soa.data.lines.length > 0 && soa.data.lines.every((l) => l.particulars && l.balance_cents !== undefined) && soa.data.lines.some((l) => /^Invoice /.test(l.particulars)), soa.data.lines.slice(0, 3));
  const soaC = await GET('/reports/customer-statement?contact_id=' + C + '&from=' + cur.start_date + '&to=' + today, T.viewer);
  const cdb = (await GET('/contacts/' + C, T.viewer)).data.sides.ar.balance_cents;
  check('the new customer\'s statement closes on the balance its page shows', soaC.ok && soaC.data.closing_cents === cdb && soaC.data.lines.some((l) => /^Cancelled: /.test(l.particulars)), [soaC.data && soaC.data.closing_cents, cdb]);
  const soaS = await GET('/reports/customer-statement?contact_id=' + S + '&to=' + today, T.viewer);
  check('a supplier\'s statement uses the payables side', soaS.ok && soaS.data.side === 'ap' && soaS.data.closing_cents === 0 && soaS.data.lines.length === 2, soaS.data && [soaS.data.side, soaS.data.closing_cents, soaS.data.lines]);
  check('a statement needs a contact (422)', (await GET('/reports/customer-statement', T.viewer)).status === 422);

  // a manual entry on the control account shows as the reconciling difference
  const man = await POST('/journals', { book: 'general', entry_date: today, description: 'Interest charged on a late account', then: 'submit',
    lines: [{ account_id: acct['1121'], debit_cents: 15000, contact_id: C }, { account_id: acct['4910'], credit_cents: 15000 }] }, T.bookkeeper);
  const manP = await POST('/journals/' + man.data.journal.id + '/approve', {}, T.accountant);
  const agMan = await GET('/reports/aging?side=ar&as_of=' + today, T.viewer);
  check('a manual journal on the AR control is shown as the difference (150.00), line by line', manP.ok && agMan.data.ledger.difference_cents === 15000
    && agMan.data.ledger.lines.some((l) => l.journal_id === man.data.journal.id && l.amount_cents === 15000), agMan.data && agMan.data.ledger);
  const subMan = await GET('/reports/subsidiary-ledger?side=ar&as_of=' + today, T.viewer);
  check('while the subsidiary ledger still equals the control account', subMan.data.ties === true);
  await POST('/journals/' + man.data.journal.id + '/reverse', { date: today, reason: 'Waived' }, T.accountant);
  const agRev = await GET('/reports/aging?side=ar&as_of=' + today, T.viewer);
  check('reversed, the difference is gone', agRev.data.ledger.difference_cents === 0 && agRev.data.ledger.lines_total_cents === 0, agRev.data.ledger);

  // inactive contacts with a balance stay in the subsidiary ledger
  await POST('/contacts/' + c4.id + '/active', { active: false }, T.bookkeeper);
  const subIn = await GET('/reports/subsidiary-ledger?side=ar', T.viewer);
  check('an inactive customer with a balance stays in the subsidiary ledger', subIn.data.rows.some((r) => r.contact_id === c4.id && r.is_active === false && r.balance_cents === bal4));
  await POST('/contacts/' + c4.id + '/active', { active: true }, T.bookkeeper);

  // ── files ─────────────────────────────────────────────────────────────
  for (const p of ['aging?side=ar&detail=1', 'aging?side=ap', 'customer-statement?contact_id=' + cust.C001.id, 'subsidiary-ledger?side=ap']) {
    const f = await csv('/reports/' + p + '&format=csv', T.viewer);
    check('CSV ' + p.split('?')[0] + ' starts with a UTF-8 BOM (' + f.text.split('\n').length + ' rows)', bom(f) && f.text.split('\n').length > 6, f.type + ' ' + f.text.slice(0, 120));
  }

  // ── the trial balance, and the audit trail ────────────────────────────
  const tbEnd = await GET('/reports/trial-balance', T.viewer);
  check('the trial balance still balances', tbEnd.ok && tbEnd.data.balanced === true, tbEnd.data && [tbEnd.data.total_debit_cents, tbEnd.data.total_credit_cents]);
  const acts = new Set();
  for (const pre of ['contact.', 'invoice.', 'credit_note.', 'bill.', 'receipt.', 'payment.', 'allocation.']) {
    const au = await GET('/admin/audit?action=' + pre + '&per_page=200', T.admin);
    (au.data ? au.data.items : []).forEach((r) => acts.add(r.action));
  }
  const want = ['contact.create', 'contact.update', 'contact.delete', 'contact.deactivate', 'contact.activate', 'invoice.create', 'invoice.update', 'invoice.post',
    'invoice.cancel', 'invoice.delete', 'credit_note.post', 'credit_note.apply', 'bill.create', 'bill.post', 'bill.delete', 'receipt.create', 'receipt.post',
    'receipt.apply', 'receipt.cancel', 'receipt.delete', 'payment.post', 'payment.delete', 'allocation.remove'];
  check('the audit log recorded every kind of change', want.every((a) => acts.has(a)), want.filter((a) => !acts.has(a)));
  const tr = await GET('/documents/' + d1.id, T.viewer);
  check('an invoice\'s history lists its steps', ['invoice.create', 'invoice.update', 'invoice.post'].every((a) => tr.data.trail.some((t) => t.action === a)), tr.data.trail.map((t) => t.action));
  check('a bookkeeper cannot read the audit log (403)', (await GET('/admin/audit', T.bookkeeper)).status === 403);
} catch (e) {
  failed++;
  console.log('FAIL  the script stopped: ' + (e && e.stack ? e.stack.split('\n').slice(0, 4).join(' | ') : e));
}
console.log('\n' + passed + ' passed, ' + failed + ' failed');
process.exit(failed ? 1 : 0);
