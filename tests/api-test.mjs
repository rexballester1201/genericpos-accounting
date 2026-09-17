// api-test.mjs — end-to-end checks of the ledger API against a SCRATCH database.
//
//   node api-test.mjs <api base> ledger            the demo company (seed_demo)
//   node api-test.mjs <api base> setup <setup key> an empty schema: first run
//
// Never point this at the real database: it posts, reverses, closes months,
// adds users and opens a fiscal year.

const [BASE, MODE = 'ledger', SETUP_KEY = ''] = process.argv.slice(2);
let passed = 0;
let failed = 0;

const check = (name, cond, info) => {
  if (cond) passed++; else failed++;
  const more = !cond && info !== undefined ? '\n        ' + (typeof info === 'string' ? info : JSON.stringify(info)).slice(0, 500) : '';
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
  return {
    status: r.status,
    ok: r.ok && !!json && json.status === true,
    data: json ? json.data : null,
    message: json ? json.message : 'NOT JSON: ' + text.slice(0, 300),
  };
}
const GET = (p, t) => call('GET', p, null, t);
const POST = (p, b, t) => call('POST', p, b || {}, t);
const PUT = (p, b, t) => call('PUT', p, b || {}, t);
const DEL = (p, t) => call('DELETE', p, null, t);

// ─── the demo company ───────────────────────────────────────────────────────

async function ledger() {
  const USERS = {
    admin: ['admin@example.test', 'DemoAdmin#2026'], accountant: ['accountant@example.test', 'DemoAcct#2026'],
    bookkeeper: ['bookkeeper@example.test', 'DemoBook#2026'], viewer: ['viewer@example.test', 'DemoView#2026'],
  };
  const T = {};
  const U = {};
  for (const [role, [email, pw]] of Object.entries(USERS)) {
    const r = await POST('/auth/login', { identifier: email, password: pw });
    check('sign in as ' + role, r.ok && r.data.access_token, r.message);
    T[role] = r.data && r.data.access_token;
    U[role] = r.data && r.data.user;
  }
  check('no token is refused (401)', (await GET('/journals')).status === 401);

  // ── every screen's first request ──
  const pub = await GET('/store');
  check('public config names the company', pub.ok && !!pub.data.name, pub.message);
  const st = await GET('/setup');
  check('setup reports already set up', st.ok && st.data.needed === false, st.data);
  for (const role of ['viewer', 'bookkeeper', 'accountant', 'admin']) {
    const d = await GET('/dashboard', T[role]);
    check('dashboard (' + role + ')', d.ok, d.message);
    if (role === 'admin' && d.ok) console.log('        dashboard keys: ' + Object.keys(d.data).join(', '));
    const b = await GET('/dashboard/badges', T[role]);
    check('badges (' + role + ')', b.ok && typeof b.data.unread === 'number', b.data || b.message);
  }
  const acc = await GET('/accounts', T.viewer);
  check('chart of accounts, read-only for a viewer', acc.ok && acc.data.items.length > 50 && acc.data.can_edit === false, acc.message);
  check('chart of accounts, editable for an administrator', (await GET('/accounts', T.admin)).data.can_edit === true);
  const fy = await GET('/fiscal-years', T.viewer);
  check('fiscal years: a viewer cannot close', fy.ok && fy.data.years.length >= 1 && fy.data.can.close === false, fy.message);
  const fyA = await GET('/fiscal-years', T.accountant);
  check('fiscal years: an accountant closes but does not lock', fyA.ok && fyA.data.can.close === true && fyA.data.can.lock === false, fyA.data && fyA.data.can);
  const jl = await GET('/journals', T.viewer);
  check('journal list with status counts', jl.ok && Array.isArray(jl.data.items) && jl.data.counts && typeof jl.data.counts.posted === 'number', jl.message);
  for (const s of ['draft', 'submitted', 'posted', 'rejected', 'cancelled']) {
    const r = await GET('/journals?status=' + s + '&per_page=10', T.viewer);
    check('journal list filtered to ' + s, r.ok && r.data.items.every((j) => j.status === s), r.data && r.data.items.map((j) => j.status));
  }
  const wt = await GET('/journals?status=waiting&per_page=50', T.viewer);
  check('journal list "not posted" holds no posted or cancelled entry', wt.ok && wt.data.items.every((j) => !['posted', 'cancelled'].includes(j.status)), wt.data && wt.data.items.map((j) => j.status));
  const lk = await GET('/journals/lookups', T.bookkeeper);
  check('entry form lookups', lk.ok && lk.data.accounts.length > 0 && Array.isArray(lk.data.open_periods) && lk.data.open_periods.length > 0, lk.message);
  for (const kind of ['unadjusted', 'adjusted', 'post_closing']) {
    const r = await GET('/reports/trial-balance?kind=' + kind, T.viewer);
    check('trial balance, ' + kind + ', balances', r.ok && r.data.balanced === true && r.data.total_debit_cents > 0, r.data ? [r.data.total_debit_cents, r.data.total_credit_cents] : r.message);
  }
  const csv = await fetch(BASE + '/reports/trial-balance?format=csv', { headers: { Authorization: 'Bearer ' + T.viewer } });
  const bytes = new Uint8Array(await csv.arrayBuffer());
  const csvText = new TextDecoder('utf-8', { ignoreBOM: true }).decode(bytes);
  check('trial balance as a CSV file (BOM, a Total row)', csv.ok && /text\/csv/.test(csv.headers.get('content-type') || '')
    && bytes[0] === 0xEF && bytes[1] === 0xBB && bytes[2] === 0xBF && csvText.includes('"Total"'), (csv.headers.get('content-type') || '') + ' ' + csvText.slice(0, 160));
  const nb = await GET('/notifications', T.bookkeeper);
  check('notifications list', nb.ok && Array.isArray(nb.data.items) && typeof nb.data.unread === 'number', nb.message);
  check('marking read needs an id or all (422)', (await POST('/notifications/read', {}, T.bookkeeper)).status === 422);
  const staff = await GET('/admin/staff', T.admin);
  check('users list with the four roles', staff.ok && staff.data.items.length >= 4 && staff.data.roles.join() === 'viewer,bookkeeper,accountant,admin', staff.data && staff.data.roles);
  const audit = await GET('/admin/audit?per_page=5', T.admin);
  check('audit log', audit.ok && audit.data.items.length > 0, audit.message);
  const auditJ = await GET('/admin/audit?action=journal.&per_page=20', T.admin);
  check('audit log filtered to journal actions', auditJ.ok && auditJ.data.items.length > 0 && auditJ.data.items.every((r) => r.action.startsWith('journal.')), auditJ.message);
  const sets = await GET('/admin/settings', T.admin);
  check('settings: groups, and the setup choices read-only', sets.ok && sets.data.groups.company && sets.data.settings.some((s) => s.key === 'entity_type' && s.readonly === true), sets.message);

  // ── who may do what ──
  const period0 = lk.data.open_periods[0];
  check('a viewer cannot prepare entries (403)', (await POST('/journals', { description: 'x' }, T.viewer)).status === 403);
  check('a bookkeeper cannot open Users (403)', (await GET('/admin/staff', T.bookkeeper)).status === 403);
  check('a bookkeeper cannot open Settings (403)', (await GET('/admin/settings', T.bookkeeper)).status === 403);
  check('a bookkeeper cannot open the audit log (403)', (await GET('/admin/audit', T.bookkeeper)).status === 403);
  check('a bookkeeper cannot add accounts (403)', (await POST('/accounts', { code: 'X' }, T.bookkeeper)).status === 403);
  check('a bookkeeper cannot close a month (403)', (await POST('/periods/' + period0.id + '/status', { status: 'closed' }, T.bookkeeper)).status === 403);
  check('an accountant cannot lock a month (403)', (await POST('/periods/' + period0.id + '/status', { status: 'locked' }, T.accountant)).status === 403);
  check('an accountant cannot open a fiscal year (403)', (await POST('/fiscal-years', {}, T.accountant)).status === 403);

  // ── an entry's life ──
  const usable = lk.data.accounts.filter((a) => a.active && !a.control && !a.needs_department);
  const exp = usable.find((a) => a.type === 'expense');
  const cash = usable.find((a) => a.type === 'asset');
  const op = lk.data.open_periods;
  const inOpen = (d) => op.some((p) => p.start_date <= d && d <= p.end_date);
  const date = inOpen(lk.data.today) ? lk.data.today : op[op.length - 1].start_date;
  const period = op.find((p) => p.start_date <= date && date <= p.end_date);
  console.log('        entries dated ' + date + ' in ' + period.name + ': ' + exp.code + ' ' + exp.name + ' / ' + cash.code + ' ' + cash.name);
  const entry = (desc, cents, then) => ({
    book: 'general', entry_date: date, description: desc, reference: 'API-TEST',
    lines: [{ account_id: exp.id, debit_cents: cents, memo: 'test' }, { account_id: cash.id, credit_cents: cents }], then,
  });

  const bad = await POST('/journals', { ...entry('Unbalanced', 100, 'draft'), lines: [{ account_id: exp.id, debit_cents: 100 }, { account_id: cash.id, credit_cents: 99 }] }, T.bookkeeper);
  check('an unbalanced entry is refused (422)', bad.status === 422, bad.status + ' ' + JSON.stringify(bad.data));

  const c1 = await POST('/journals', entry('API test: office supplies', 12345, 'draft'), T.bookkeeper);
  check('a bookkeeper saves a draft', c1.status === 201 && c1.data.journal.status === 'draft' && c1.data.can.submit === true, c1.data || c1.message);
  const id1 = c1.data.journal.id;
  const e1 = await PUT('/journals/' + id1, entry('API test: office supplies (edited)', 12345, 'draft'), T.bookkeeper);
  check('its preparer edits the draft', e1.ok && e1.data.journal.description.includes('edited'), e1.message);
  const e1b = await PUT('/journals/' + id1, entry('Someone else', 12345, 'draft'), T.accountant);
  check('nobody else edits it', !e1b.ok && e1b.status >= 400, e1b.status + ' ' + e1b.message);
  const s1 = await POST('/journals/' + id1 + '/submit', {}, T.bookkeeper);
  check('the bookkeeper submits it', s1.ok && s1.data.journal.status === 'submitted', s1.message);
  const na = await GET('/notifications', T.accountant);
  check('the accountant is told it waits', na.ok && na.data.items.some((n) => n.type === 'journal.submitted' && n.url === '/journals/' + id1), na.data && na.data.items.slice(0, 2));
  const bd = await GET('/dashboard/badges', T.accountant);
  check('the approvals badge counts it', bd.ok && bd.data.awaiting_approval >= 1, bd.data);
  check('a bookkeeper cannot approve (403)', (await POST('/journals/' + id1 + '/approve', {}, T.bookkeeper)).status === 403);
  const a1 = await POST('/journals/' + id1 + '/approve', {}, T.accountant);
  check('the accountant approves it and it posts with a number', a1.ok && a1.data.journal.status === 'posted' && /\d/.test(a1.data.journal.journal_no || ''), a1.data ? a1.data.journal : a1.message);
  if (a1.ok) console.log('        posted as ' + a1.data.journal.journal_no);
  const nbk = await GET('/notifications', T.bookkeeper);
  check('the preparer is told it posted', nbk.ok && nbk.data.items.some((n) => n.type === 'journal.posted' && n.url === '/journals/' + id1), nbk.data && nbk.data.items.slice(0, 2));
  check('a posted entry cannot be edited', !(await PUT('/journals/' + id1, entry('x', 1, 'draft'), T.bookkeeper)).ok);

  const selfOk = !!(lk.data.policy && lk.data.policy.self_approve);
  const c2 = await POST('/journals', entry('API test: the accountant\'s own entry', 5000, 'post'), T.accountant);
  check('"save and post" by its own preparer ' + (selfOk ? 'posts (self-approval on)' : 'stops at waiting (self-approval off)'),
    c2.status === 201 && c2.data.journal.status === (selfOk ? 'posted' : 'submitted'), c2.data ? [c2.data.journal.status, c2.data.notice] : c2.message);
  if (!selfOk && c2.status === 201) {
    const id2 = c2.data.journal.id;
    const a2 = await POST('/journals/' + id2 + '/approve', {}, T.accountant);
    check('its preparer cannot approve it', !a2.ok && a2.status === 409, a2.status + ' ' + a2.message);
    const a2b = await POST('/journals/' + id2 + '/approve', {}, T.admin);
    check('another approver can', a2b.ok && a2b.data.journal.status === 'posted', a2b.message);
  }

  const c3 = await POST('/journals', entry('API test: to be rejected', 777, 'submit'), T.bookkeeper);
  check('a bookkeeper saves and submits in one step', c3.status === 201 && c3.data.journal.status === 'submitted', c3.data ? c3.data.notice : c3.message);
  const id3 = c3.data.journal.id;
  check('rejecting needs a reason (422)', (await POST('/journals/' + id3 + '/reject', {}, T.accountant)).status === 422);
  const r3 = await POST('/journals/' + id3 + '/reject', { reason: 'Attach the receipt.' }, T.accountant);
  check('the accountant rejects it with a reason', r3.ok && r3.data.journal.status === 'rejected' && r3.data.journal.reject_reason === 'Attach the receipt.', r3.data ? r3.data.journal : r3.message);
  const nb3 = await GET('/notifications', T.bookkeeper);
  check('the preparer is told why', nb3.ok && nb3.data.items.some((n) => n.type === 'journal.rejected' && n.url === '/journals/' + id3), nb3.data && nb3.data.items.slice(0, 2));
  const b3 = await GET('/dashboard/badges', T.bookkeeper);
  check('the rejected badge counts it', b3.ok && b3.data.my_rejected >= 1, b3.data);
  const f3 = await PUT('/journals/' + id3, entry('API test: to be rejected (fixed)', 777, 'submit'), T.bookkeeper);
  check('the preparer fixes and resubmits it', f3.ok && f3.data.journal.status === 'submitted', f3.data ? [f3.data.journal.status, f3.data.notice] : f3.message);
  const k3 = await POST('/journals/' + id3 + '/cancel', {}, T.bookkeeper);
  check('the preparer cancels it', k3.ok && k3.data.journal.status === 'cancelled', k3.message);
  check('a cancelled entry cannot be approved', !(await POST('/journals/' + id3 + '/approve', {}, T.accountant)).ok);

  check('reversing needs a date and a reason (422)', (await POST('/journals/' + id1 + '/reverse', {}, T.accountant)).status === 422);
  const v1 = await POST('/journals/' + id1 + '/reverse', { date, reason: 'API test reversal' }, T.accountant);
  check('the accountant reverses the posted entry', v1.ok && v1.data.reversal_id > 0 && v1.data.journal.status === 'posted', v1.message);
  const o1 = await GET('/journals/' + id1, T.viewer);
  check('the original links to its reversal', o1.ok && o1.data.reversed_by && o1.data.reversed_by.id === (v1.data && v1.data.reversal_id) && o1.data.can.reverse === false,
    o1.data ? [o1.data.reversed_by, o1.data.can] : o1.message);
  check('it cannot be reversed twice', !(await POST('/journals/' + id1 + '/reverse', { date, reason: 'again' }, T.accountant)).ok);
  const trail = (o1.data && o1.data.trail || []).map((t) => t.action);
  check('its history lists every step', ['journal.create', 'journal.update', 'journal.submit', 'journal.post', 'journal.reverse'].every((a) => trail.includes(a)), trail);

  // ── month-end ──
  const c4 = await POST('/journals', entry('API test: waiting', 100, 'draft'), T.bookkeeper);
  const cl = await POST('/periods/' + period.id + '/status', { status: 'closed' }, T.accountant);
  check('a month with a waiting entry cannot close', cl.status === 409 && /waiting/.test(cl.message), cl.status + ' ' + cl.message);
  if (c4.data) await POST('/journals/' + c4.data.journal.id + '/cancel', {}, T.bookkeeper);
  const w = await GET('/journals?period=' + period.id + '&per_page=100', T.accountant);
  for (const j of w.data.items.filter((x) => x.status === 'draft' || x.status === 'submitted')) await POST('/journals/' + j.id + '/cancel', {}, T.accountant);
  const cl2 = await POST('/periods/' + period.id + '/status', { status: 'closed' }, T.accountant);
  check('once nothing waits, the accountant closes the month', cl2.ok, cl2.status + ' ' + cl2.message);
  const c5 = await POST('/journals', entry('API test: into a closed month', 100, 'submit'), T.bookkeeper);
  let postedIntoClosed = false;
  if (c5.status === 201) {
    const a5 = await POST('/journals/' + c5.data.journal.id + '/approve', {}, T.accountant);
    postedIntoClosed = a5.ok;
    await POST('/journals/' + c5.data.journal.id + '/cancel', {}, T.accountant);
  }
  check('nothing posts into a closed month', !postedIntoClosed, c5.status + ' ' + JSON.stringify(c5.data && (c5.data.errors || c5.data.notice)));
  console.log('        (a new entry dated in the closed month: ' + c5.status + (c5.data && c5.data.errors ? ' ' + JSON.stringify(c5.data.errors) : '') + ')');
  const ro = await POST('/periods/' + period.id + '/status', { status: 'open' }, T.accountant);
  check('the accountant reopens it', ro.ok, ro.message);

  // ── the chart of accounts ──
  const items = (await GET('/accounts', T.admin)).data.items;
  const head = items.find((a) => a.is_header && a.type === 'expense' && a.depth >= 1) || items.find((a) => a.is_header && a.type === 'expense');
  const codes = new Set(items.map((a) => a.code));
  let code = null;
  for (let n = 6990; n < 7000 && !code; n++) if (!codes.has(String(n))) code = String(n);
  const acct = { code, name: 'API test expense', type: 'expense', parent_id: head.id, subtype: null, cash_flow: null, control: null,
    is_header: false, is_contra: false, requires_department: false, is_active: true, tags: '', description: '' };
  const na1 = await POST('/accounts', acct, T.admin);
  check('an administrator adds an account under ' + head.code, na1.status === 201 && na1.data.account.code === code, na1.status + ' ' + JSON.stringify(na1.data || na1.message));
  if (na1.status === 201) {
    const aid = na1.data.account.id;
    const up = await PUT('/accounts/' + aid, { ...acct, name: 'API test expense (renamed)' }, T.admin);
    check('and renames it', up.ok && up.data.account.name.includes('renamed'), up.message);
    const dl = await DEL('/accounts/' + aid, T.admin);
    check('an unused account can be deleted', dl.ok, dl.message);
  }
  const used = items.find((a) => a.has_postings && !a.is_header);
  const dl2 = await DEL('/accounts/' + used.id, T.admin);
  check('an account with entries cannot be deleted', dl2.status === 409, dl2.status + ' ' + dl2.message);
  const rt = await PUT('/accounts/' + used.id, { ...acct, code: used.code, name: used.name, parent_id: used.parent_id, type: used.type === 'asset' ? 'expense' : 'asset' }, T.admin);
  check('an account with entries keeps its type', rt.status === 422, rt.status + ' ' + JSON.stringify(rt.data && rt.data.errors));

  // ── users ──
  const nu = await POST('/admin/staff', { full_name: 'API Test Viewer', email: 'api-test-viewer@example.test', role: 'viewer', password: 'ApiTest#2026Z' }, T.admin);
  check('an administrator adds a user', nu.status === 201 && nu.data.role === 'viewer', nu.status + ' ' + nu.message);
  const dem = await PUT('/admin/staff/' + U.admin.id, { role: 'viewer' }, T.admin);
  check('the only administrator cannot be demoted (422)', dem.status === 422, dem.status + ' ' + dem.message);
  if (nu.status === 201) {
    const lnu = await POST('/auth/login', { identifier: 'api-test-viewer@example.test', password: 'ApiTest#2026Z' });
    check('the new user signs in', lnu.ok, lnu.message);
    const sn = await PUT('/admin/staff/' + nu.data.id, { account_state: 'suspended' }, T.admin);
    check('an administrator suspends them', sn.ok && sn.data.account_state === 'suspended', sn.message);
    const lns = await POST('/auth/login', { identifier: 'api-test-viewer@example.test', password: 'ApiTest#2026Z' });
    check('a suspended user cannot sign in', !lns.ok, lns.status + ' ' + lns.message);
    if (lnu.ok) check('their open session stops working (403)', (await GET('/journals', lnu.data.access_token)).status === 403);
  }

  // ── settings ──
  const sv = await POST('/admin/settings', { settings: { ledger_number_digits: '6' } }, T.admin);
  check('an administrator changes a setting', sv.ok && sv.data.saved.includes('ledger_number_digits'), sv.message);
  const ro2 = await POST('/admin/settings', { settings: { entity_type: 'cooperative' } }, T.admin);
  check('a setup choice cannot change', ro2.status === 422 && ro2.data.errors.entity_type, ro2.status + ' ' + JSON.stringify(ro2.data));
  const acd = await POST('/admin/settings', { settings: { acct_ar_control: '9999999' } }, T.admin);
  check('a default account must exist in the chart', acd.status === 422, acd.status + ' ' + JSON.stringify(acd.data && acd.data.errors));
  const rs = await POST('/admin/settings/reset', { key: 'ledger_number_digits' }, T.admin);
  check('a setting goes back to its default', rs.ok, rs.message);

  // ── the next year ──
  const ny = await POST('/fiscal-years', {}, T.admin);
  check('an administrator opens the next fiscal year', ny.status === 201 && ny.data.years.length === fy.data.years.length + 1, ny.status + ' ' + ny.message);

  // ── afterwards ──
  const tb = await GET('/reports/trial-balance', T.viewer);
  check('the trial balance still balances', tb.ok && tb.data.balanced === true, tb.data && [tb.data.total_debit_cents, tb.data.total_credit_cents]);
  const au = await GET('/admin/audit?per_page=200', T.admin);
  const acts = new Set((au.data ? au.data.items : []).map((r) => r.action));
  const want = ['journal.create', 'journal.update', 'journal.submit', 'journal.post', 'journal.reject', 'journal.cancel', 'journal.reverse',
    'period.close', 'period.reopen', 'account.create', 'account.update', 'account.delete', 'staff.create', 'staff.update',
    'settings.update', 'settings.reset', 'fiscal_year.create'];
  check('the audit log recorded every kind of change', want.every((a) => acts.has(a)), want.filter((a) => !acts.has(a)));
  for (const role of ['viewer', 'bookkeeper', 'accountant', 'admin']) check('dashboard afterwards (' + role + ')', (await GET('/dashboard', T[role])).ok);
}

// ─── the first run ──────────────────────────────────────────────────────────

async function setup() {
  const st = await GET('/setup');
  check('a fresh install needs setup', st.ok && st.data.needed === true && st.data.enabled === true && Object.keys(st.data.kinds || {}).length === 3, st.data || st.message);
  const base = {
    setup_key: SETUP_KEY, company_name: 'Scratch Multi-Purpose Cooperative', kind: 'cooperative', fy_start: '2026-07-01',
    currency_code: 'PHP', currency_symbol: '₱',
    admin: { full_name: 'Scratch Admin', email: 'scratch-admin@example.test', password: 'Scratch#2026', password_confirm: 'Scratch#2026' },
  };
  const wk = await POST('/setup', { ...base, setup_key: 'wrong-key-000000' });
  check('a wrong setup key is refused', wk.status === 422 && wk.data.errors.setup_key, wk.status + ' ' + wk.message);
  const miss = await POST('/setup', { ...base, company_name: '', kind: 'nonsense', fy_start: '2026-07-15' });
  check('missing and wrong fields are named', miss.status === 422 && miss.data.errors.company_name && miss.data.errors.kind && miss.data.errors.fy_start, miss.data && miss.data.errors);
  const ok = await POST('/setup', base);
  check('setup creates the books', ok.status === 201 && ok.data.access_token && ok.data.user.role === 'admin', ok.status + ' ' + ok.message);
  const t = ok.data && ok.data.access_token;
  const acc = await GET('/accounts', t);
  check('the co-operative chart is in place', acc.ok && acc.data.items.length > 50, acc.data ? acc.data.items.length : acc.message);
  const fy = await GET('/fiscal-years', t);
  const y = fy.data && fy.data.years[0];
  check('the first fiscal year runs July to June in twelve open months', fy.ok && fy.data.years.length === 1 && y.start_date === '2026-07-01' && y.end_date === '2027-06-30'
    && y.periods.length === 12 && y.periods.every((p) => p.status === 'open'), y);
  const sets = await GET('/admin/settings', t);
  const val = (k) => ((sets.data ? sets.data.settings : []).find((s) => s.key === k) || {}).value;
  check('the kind, the start month and the name are recorded', val('entity_type') === 'cooperative' && String(val('fiscal_year_start_month')) === '7' && val('store_name') === base.company_name,
    [val('entity_type'), val('fiscal_year_start_month'), val('store_name')]);
  check('the default accounts point into the chart', !!val('acct_ar_control') && !!val('acct_retained_earnings'), [val('acct_ar_control'), val('acct_retained_earnings')]);
  const again = await GET('/setup');
  check('setup now reports done', again.ok && again.data.needed === false);
  const twice = await POST('/setup', { ...base, admin: { ...base.admin, email: 'someone-else@example.test' } });
  check('setup cannot run twice (409)', twice.status === 409, twice.status + ' ' + twice.message);
  const tb = await GET('/reports/trial-balance', t);
  check('the empty ledger balances', tb.ok && tb.data.balanced === true && tb.data.rows.length === 0, tb.data || tb.message);
  const au = await GET('/admin/audit', t);
  const acts = au.data ? au.data.items.map((r) => r.action) : [];
  check('setup is in the audit log', acts.includes('setup.complete') && acts.includes('fiscal_year.create'), acts);
  const lk = await GET('/journals/lookups', t);
  check('entries can be prepared straight away', lk.ok && lk.data.open_periods.length === 12 && lk.data.accounts.length > 0, lk.message);
}

try {
  if (MODE === 'setup') await setup(); else await ledger();
} catch (e) {
  failed++;
  console.log('FAIL  the script stopped: ' + (e && e.stack ? e.stack.split('\n').slice(0, 3).join(' | ') : e));
}
console.log('\n' + passed + ' passed, ' + failed + ' failed');
process.exit(failed ? 1 : 0);
