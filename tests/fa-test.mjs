// fa-test.mjs — fixed assets end to end on the demo company (SCRATCH database only)
//
//   node fa-test.mjs [api base]        default http://127.0.0.1:8783/api/v1
//
// It registers assets, posts and undoes depreciation runs, disposes of assets
// and takes disposals back. Never point it at the real database.
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const BASE = process.argv[2] || 'http://127.0.0.1:8783/api/v1';
const HERE = dirname(fileURLToPath(import.meta.url));
let passed = 0;
let failed = 0;

const check = (name, cond, info) => {
  if (cond) passed++; else failed++;
  const more = !cond && info !== undefined ? '\n        ' + (typeof info === 'string' ? info : JSON.stringify(info)).slice(0, 700) : '';
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
  return { status: r.status, ok: r.ok && !!json && json.status === true, data: json ? json.data : null, message: json ? json.message : 'NOT JSON: ' + text.slice(0, 300) };
}
const GET = (p, t) => call('GET', p, null, t);
const POST = (p, b, t) => call('POST', p, b || {}, t);
const PUT = (p, b, t) => call('PUT', p, b || {}, t);
const DEL = (p, t) => call('DELETE', p, null, t);

async function csvBytes(path, token) {
  const r = await fetch(BASE + path, { headers: { Authorization: 'Bearer ' + token } });
  const b = new Uint8Array(await r.arrayBuffer());
  return { ok: r.ok, type: r.headers.get('content-type') || '', bytes: b, text: new TextDecoder('utf-8', { ignoreBOM: true }).decode(b) };
}
const bom = (c) => c.ok && /text\/csv/.test(c.type) && c.bytes[0] === 0xEF && c.bytes[1] === 0xBB && c.bytes[2] === 0xBF;

// The straight line, the way Depreciation_lib works it (integers, half up).
const divRound = (a, b) => { const A = BigInt(a), B = BigInt(b); const q = A / B, r = A % B; return Number(r * 2n >= B ? q + 1n : q); };
const monthNo = (d) => +d.slice(0, 4) * 12 + +d.slice(5, 7) - 1;
const slCharge = (a, accum, k) => {
  const base = a.cost_cents - a.residual_cents;
  if (base <= 0 || k < 1 || accum >= base) return 0;
  const target = k >= a.useful_life_months ? base : divRound(base * k, a.useful_life_months);
  return Math.max(0, Math.min(target - accum, base - accum));
};
const tbMap = (tb) => Object.fromEntries(tb.rows.map((r) => [r.code, r.debit_cents - r.credit_cents]).filter(([, n]) => n !== 0));
const sameMap = (a, b) => { const k = new Set([...Object.keys(a), ...Object.keys(b)]); return [...k].every((x) => (a[x] || 0) === (b[x] || 0)); };
const diffMap = (a, b) => { const k = new Set([...Object.keys(a), ...Object.keys(b)]); return [...k].filter((x) => (a[x] || 0) !== (b[x] || 0)).map((x) => [x, a[x] || 0, b[x] || 0]); };
const sortObj = (o) => JSON.stringify(Object.entries(o).sort((x, y) => (x[0] < y[0] ? -1 : x[0] > y[0] ? 1 : 0)));
const lineKey = (l) => [l.account_id, l.debit_cents, l.credit_cents, l.department_id || 0].join('/');
const sameLines = (a, b) => JSON.stringify(a.map(lineKey).sort()) === JSON.stringify(b.map(lineKey).sort());

try {
  // ── the library ──────────────────────────────────────────────────────────
  let libOut = '';
  let libOk = false;
  try { libOut = execFileSync('D:/xampp/php/php.exe', [join(HERE, 'fa-lib-test.php')], { encoding: 'utf8' }); libOk = true; }
  catch (e) { libOut = String(e.stdout || e.message); }
  const libLine = (libOut.trim().split('\n').pop() || '').trim();
  check('Depreciation_lib unit checks: ' + libLine, libOk && / 0 failed$/.test(libLine), libOut.split('\n').filter((l) => l.startsWith('FAIL')).join(' | '));

  // ── who is who ───────────────────────────────────────────────────────────
  const USERS = { admin: ['admin@example.test', 'DemoAdmin#2026'], accountant: ['accountant@example.test', 'DemoAcct#2026'],
    bookkeeper: ['bookkeeper@example.test', 'DemoBook#2026'], viewer: ['viewer@example.test', 'DemoView#2026'] };
  const T = {};
  for (const [role, [email, pw]] of Object.entries(USERS)) {
    const r = await POST('/auth/login', { identifier: email, password: pw });
    check('sign in as ' + role, r.ok && r.data.access_token, r.message);
    T[role] = r.data && r.data.access_token;
  }
  check('no token: the register is refused (401)', (await GET('/assets')).status === 401);

  const lk = (await GET('/journals/lookups', T.bookkeeper)).data;
  const today = lk.today;
  const acct = Object.fromEntries(lk.accounts.map((a) => [a.code, a]));
  const contacts = Object.fromEntries(lk.contacts.map((c) => [c.name, c]));
  const depts = Object.fromEntries(lk.departments.map((d) => [d.code, d]));

  let runs = (await GET('/depreciation/runs', T.viewer)).data;
  if (!runs.periods.length) {
    note('no open period after the latest run: opening the next fiscal year');
    await POST('/fiscal-years', {}, T.admin);
    runs = (await GET('/depreciation/runs', T.viewer)).data;
  }
  const latest0 = runs.latest;
  const P1 = runs.periods[0];
  const P2 = runs.periods[1];
  const tbDate = runs.periods[runs.periods.length - 1].end_date;
  note('today ' + today + '; latest seeded run ' + (latest0 && latest0.period) + '; next periods ' + P1.name + ', ' + (P2 && P2.name) + '; trial balances as of ' + tbDate);
  check('runs list: every role reads it, a viewer cannot run', runs.items.length >= 1 && runs.can_run === false && runs.items[0].latest === true);
  const TB = async () => (await GET('/reports/trial-balance?kind=post_closing&as_of=' + tbDate, T.viewer)).data;
  const disposeDate = today >= P1.start_date && today <= P1.end_date ? today : null;

  // ── the demo data ties ───────────────────────────────────────────────────
  const ls0 = await GET('/reports/lapsing-schedule', T.viewer);
  check('lapsing schedule on the demo data ties to the ledger (' + (ls0.data && ls0.data.tie_out.length) + ' accounts, no difference)',
    ls0.ok && ls0.data.ties === true && ls0.data.tie_out.every((t) => t.difference_cents === 0) && ls0.data.others.length === 0,
    ls0.data && ls0.data.tie_out.filter((t) => t.difference_cents));
  check('lapsing schedule carries the letterhead and defaults to the fiscal year to date',
    !!(ls0.data.letterhead && ls0.data.letterhead.company) && ls0.data.to === today && ls0.data.from.endsWith('-01'), [ls0.data.from, ls0.data.to]);
  const t0 = ls0.data.total;
  check('lapsing identities: cost start + additions − disposals = cost end; accumulated start + depreciation − disposals = accumulated end; NBV = cost − accumulated',
    t0.cost_start_cents + t0.additions_cents - t0.disposals_cents === t0.cost_end_cents
    && t0.accum_start_cents + t0.depreciation_cents - t0.accum_disposals_cents === t0.accum_end_cents
    && t0.cost_end_cents - t0.accum_end_cents === t0.nbv_end_cents, t0);
  const reg0 = await GET('/assets?status=in_use', T.viewer);
  check('register: the four demo assets in use, totals equal the lapsing schedule at today',
    reg0.ok && reg0.data.total === 4 && reg0.data.totals.cost_cents === t0.cost_end_cents && reg0.data.totals.accumulated_cents === t0.accum_end_cents
    && reg0.data.items.map((a) => a.asset_no).join() === 'FA-0001,FA-0002,FA-0003,FA-0004', reg0.data && [reg0.data.totals, t0]);
  const lsCsv = await csvBytes('/reports/lapsing-schedule?format=csv', T.viewer);
  check('lapsing schedule CSV starts with a UTF-8 BOM and carries the tie-out', bom(lsCsv) && lsCsv.text.includes('Tie-out to the ledger') && lsCsv.text.includes('"FA-0003"'), lsCsv.type + ' ' + lsCsv.text.slice(0, 200));
  const regCsv = await csvBytes('/assets?status=all&format=csv', T.viewer);
  check('register CSV starts with a UTF-8 BOM', bom(regCsv) && regCsv.text.includes('"Fixed asset register"'), regCsv.text.slice(0, 200));
  const fy = ls0.data.fiscal_years[0];
  const lsFy = await GET('/reports/lapsing-schedule?fiscal_year_id=' + fy.id, T.viewer);
  check('lapsing schedule for a whole fiscal year (' + fy.name + ')', lsFy.ok && lsFy.data.from === fy.start_date && lsFy.data.to === fy.end_date && lsFy.data.ties === true);
  check('a start after the end is refused (422)', (await GET('/reports/lapsing-schedule?from=' + today + '&to=' + fy.start_date, T.viewer)).status === 422);

  // ── categories ───────────────────────────────────────────────────────────
  const cats = await GET('/asset-categories', T.viewer);
  check('categories: a viewer reads them, cannot edit', cats.ok && cats.data.items.length === 4 && cats.data.can_edit === false);
  const catByName = Object.fromEntries(cats.data.items.map((c) => [c.name, c]));
  const TE = catByName['Transportation Equipment'];
  const OE = catByName['Office Equipment'];
  const newCat = { name: 'Leasehold Improvements (test)', asset_account_id: acct['1223'].id, accum_account_id: acct['1224'].id,
    expense_account_id: acct['6200'].id, method: 'straight_line', useful_life_months: 120, residual_bp: 0 };
  check('a bookkeeper cannot add a category (403)', (await POST('/asset-categories', newCat, T.bookkeeper)).status === 403);
  const badCat = await POST('/asset-categories', { ...newCat, accum_account_id: acct['1214'].id, useful_life_months: 0, name: 'Office Equipment' }, T.accountant);
  check('category validation: a non-contra accumulated account, a zero life and a duplicate name are named',
    badCat.status === 422 && badCat.data.errors.accum_account_id && badCat.data.errors.useful_life_months && badCat.data.errors.name, badCat.data);
  const c1 = await POST('/asset-categories', newCat, T.accountant);
  check('an accountant adds a category', c1.status === 201 && c1.data.category.accum_account.code === '1224', c1.message);
  const cid = c1.data && c1.data.category.id;
  const cu = await PUT('/asset-categories/' + cid, { residual_bp: 500, useful_life_months: 60 }, T.accountant);
  check('and changes its residual (5%) and life (60 months)', cu.ok && cu.data.category.residual_bp === 500 && cu.data.category.useful_life_months === 60, cu.message);
  const cd = await PUT('/asset-categories/' + cid, { is_active: false }, T.accountant);
  check('and deactivates it', cd.ok && cd.data.category.is_active === false);
  const inInactive = await POST('/assets', { name: 'Test', category_id: cid, acquired_on: today, cost_cents: 100000 }, T.bookkeeper);
  check('an inactive category takes no new assets (422)', inInactive.status === 422 && /inactive/.test(inInactive.data.errors.category_id || ''), inInactive.data);
  check('an unused category can be deleted', (await DEL('/asset-categories/' + cid, T.accountant)).ok);
  const fixedAccts = await PUT('/asset-categories/' + OE.id, { asset_account_id: acct['1221'].id }, T.accountant);
  check('a category with assets keeps its accounts (422)', fixedAccts.status === 422 && /fixed/.test(fixedAccts.data.errors.asset_account_id || ''), fixedAccts.data);
  check('a category with assets cannot be deleted (409)', (await DEL('/asset-categories/' + OE.id, T.accountant)).status === 409);

  // ── registering ──────────────────────────────────────────────────────────
  const acquired = latest0 ? latest0.start_date.slice(0, 8) + '20' : P1.start_date;
  check('a viewer cannot register an asset (403)', (await POST('/assets', { name: 'x' }, T.viewer)).status === 403);
  const bad = await POST('/assets', { name: '', category_id: TE.id, acquired_on: '2999-01-01', cost_cents: 0 }, T.bookkeeper);
  check('asset validation names each field: name, future acquisition date, cost', bad.status === 422 && bad.data.errors.name && bad.data.errors.acquired_on && bad.data.errors.cost_cents, bad.data);
  const wrongJ = await POST('/assets', { name: 'Motorcycle', category_id: TE.id, acquired_on: acquired, cost_cents: 10000001, acquisition_journal_id: 116 }, T.bookkeeper);
  check('a purchase entry that does not debit the category\'s asset account is refused (the laptop bill for a vehicle)',
    wrongJ.status === 422 && /does not debit 1218/.test(wrongJ.data.errors.acquisition_journal_id || ''), wrongJ.data);

  const pj = await POST('/journals', { book: 'purchases', entry_date: acquired, description: 'Honda delivery motorcycle (fixed-asset test)', reference: 'SI 77001',
    party_name: 'Bicol Truck and Auto Care', lines: [{ account_id: acct['1218'].id, debit_cents: 10000001, memo: 'Motorcycle' }, { account_id: acct['1113'].id, credit_cents: 10000001 }], then: 'submit' }, T.bookkeeper);
  const pja = pj.data ? await POST('/journals/' + pj.data.journal.id + '/approve', {}, T.accountant) : { ok: false };
  check('the purchase is booked: prepared by the bookkeeper, approved by the accountant', pj.status === 201 && pja.ok && pja.data.journal.status === 'posted', pj.message + ' / ' + pja.message);
  const pjId = pj.data && pj.data.journal.id;
  const cand = await GET('/assets/acquisition-journals?category=' + TE.id, T.bookkeeper);
  check('the purchase appears among the entries a vehicle can be linked to', cand.ok && cand.data.items.some((j) => j.id === pjId && j.debit_cents === 10000001), cand.data && cand.data.items.slice(0, 3));

  const moto = { name: 'Honda delivery motorcycle', category_id: TE.id, acquired_on: acquired, cost_cents: 10000001, useful_life_months: 37,
    department_id: depts.WHS.id, location: 'Warehouse', serial_no: 'MC-2026-001', supplier_id: contacts['Bicol Truck and Auto Care'].id,
    acquisition_journal_id: pjId, notes: 'Registered by fa-test.mjs' };
  const a5 = await POST('/assets', moto, T.bookkeeper);
  check('a bookkeeper registers it: number FA-0005 continues the seed, starts the month after acquisition, residual from the category (0)',
    a5.status === 201 && a5.data.asset.asset_no === 'FA-0005' && a5.data.asset.depreciation_start === P1.start_date && a5.data.asset.residual_cents === 0
    && a5.data.asset.method === 'straight_line' && a5.data.asset.status === 'active', a5.data ? a5.data.asset : a5.message);
  const id5 = a5.data && a5.data.asset.id;
  const sched = a5.data && a5.data.schedule;
  check('its schedule projects from ' + P1.name + ' (270,270 the first month) and ends exactly on the cost',
    sched && sched.projected.length === 37 && sched.projected[0].month === P1.start_date && sched.projected[0].amount_cents === 270270
    && sched.projected[36].accum_after_cents === 10000001 && sched.projected.reduce((s, m) => s + m.amount_cents, 0) === 10000001, sched && sched.projected.slice(0, 2));
  check('its acquisition entry is linked', a5.data.acquisition_journal && a5.data.acquisition_journal.id === pjId);
  const e5 = await PUT('/assets/' + id5, { cost_cents: 10000002, location: 'Warehouse, bay 2' }, T.bookkeeper);
  check('before any depreciation its cost can still change', e5.ok && e5.data.asset.cost_cents === 10000002 && e5.data.asset.location === 'Warehouse, bay 2', e5.message);
  await PUT('/assets/' + id5, { cost_cents: 10000001 }, T.bookkeeper);

  const bad2 = await POST('/assets', { name: 'Refused', category_id: OE.id, acquired_on: today, cost_cents: 'abc' }, T.bookkeeper);
  const a6 = await POST('/assets', { name: 'Office printer (never booked)', category_id: OE.id, acquired_on: today, cost_cents: 1234567, department_id: depts.ADM.id }, T.bookkeeper);
  check('a refused registration takes no number: the next asset is FA-0006', bad2.status === 422 && a6.status === 201 && a6.data.asset.asset_no === 'FA-0006', [bad2.status, a6.data && a6.data.asset.asset_no]);
  const ls1 = await GET('/reports/lapsing-schedule', T.viewer);
  const d1214 = ls1.data.tie_out.find((t) => t.code === '1214');
  check('an asset registered but never booked shows in the tie-out: 1214 register exceeds the ledger by 12,345.67',
    ls1.data.ties === false && d1214 && d1214.difference_cents === 1234567, ls1.data.tie_out.filter((t) => t.difference_cents));
  const d6 = await DEL('/assets/' + a6.data.asset.id, T.bookkeeper);
  check('an asset with no depreciation can be deleted, and the schedule ties again', d6.ok && (await GET('/reports/lapsing-schedule', T.viewer)).data.ties === true, d6.message);

  // ── depreciation: preview, run, refuse, undo ────────────────────────────
  check('a bookkeeper cannot preview a run (403)', (await GET('/depreciation/preview?period_id=' + P1.id, T.bookkeeper)).status === 403);
  check('a bookkeeper cannot run depreciation (403)', (await POST('/depreciation/runs', { period_id: P1.id }, T.bookkeeper)).status === 403);
  const detail = async (id) => (await GET('/assets/' + id, T.viewer)).data;
  const inUse = (await GET('/assets?status=in_use&per_page=200', T.viewer)).data.items;
  const expected = (p) => Object.fromEntries(inUse.filter((a) => a.depreciation_start <= p.start_date).map((a) => {
    const k = monthNo(p.start_date) - monthNo(a.depreciation_start) + 1;
    return [a.asset_no, slCharge(a, a.accumulated_cents, k)];
  }).filter(([, v]) => v > 0));

  const pv = await GET('/depreciation/preview?period_id=' + P1.id, T.accountant);
  const exp1 = expected(P1);
  const got1 = pv.data ? Object.fromEntries(pv.data.rows.map((r) => [r.asset_no, r.amount_cents])) : {};
  check('preview for ' + P1.name + ': each asset\'s charge is the straight line (' + Object.entries(got1).map(([k, v]) => k + ' ' + v).join(', ') + ')',
    pv.ok && sortObj(got1) === sortObj(exp1), [got1, exp1]);
  check('the laptops (FA-0004) are pulled back onto the line: 416,665 instead of the seed\'s 416,667', got1['FA-0004'] === 416665);
  check('the preview names the journal: adjusting, DEP-' + P1.start_date.slice(0, 7) + ', dated ' + P1.end_date,
    pv.data.journal.book === 'adjusting' && pv.data.journal.reference === 'DEP-' + P1.start_date.slice(0, 7) && pv.data.journal.entry_date === P1.end_date
    && /^Depreciation for \w+ \d{4}$/.test(pv.data.journal.description), pv.data.journal);
  const catSum = {};
  for (const r of pv.data.rows) catSum[r.category] = (catSum[r.category] || 0) + r.amount_cents;
  const lineSum = {};
  for (const l of pv.data.lines) if (l.credit_cents) lineSum[l.memo.split(':')[0]] = (lineSum[l.memo.split(':')[0]] || 0) + l.credit_cents;
  check('one credit per category to its accumulated account, equal to the category\'s assets', sortObj(catSum) === sortObj(lineSum), [catSum, lineSum]);
  check('debits go to each category\'s expense account with the asset\'s department',
    pv.data.lines.filter((l) => l.debit_cents).every((l) => l.account_code === '6200' && l.department_id) && pv.data.lines.some((l) => l.department === 'WHS' && l.memo.includes('FA-0005')));

  const tbBefore = tbMap(await TB());
  const run1 = await POST('/depreciation/runs', { period_id: P1.id }, T.accountant);
  check('the accountant runs ' + P1.name + ': ' + run1.message, run1.status === 201 && run1.data.run.total_cents === pv.data.total_cents && run1.data.entries.length === pv.data.rows.length, run1.message);
  const r1 = run1.data.run;
  const j1 = (await GET('/journals/' + r1.journal_id, T.viewer)).data;
  check('its journal: posted, book adjusting, source depreciation, source_id = the run, dated the period end, DEP reference',
    j1.journal.status === 'posted' && j1.journal.book === 'adjusting' && j1.journal.source === 'depreciation' && j1.journal.source_id === r1.id
    && j1.journal.entry_date === P1.end_date && j1.journal.reference === 'DEP-' + P1.start_date.slice(0, 7) && j1.journal.total_cents === pv.data.total_cents, j1.journal);
  check('its lines are exactly the preview\'s (account, debit, credit, department)', sameLines(j1.lines, pv.data.lines), [j1.lines.map(lineKey), pv.data.lines.map(lineKey)]);
  check('it cannot be reversed from the journal screen', j1.can.reverse === false);
  const again = await POST('/depreciation/runs', { period_id: P1.id }, T.accountant);
  check('a second run for ' + P1.name + ' is refused (409)', again.status === 409 && /already has a depreciation run/.test(again.message), again.status + ' ' + again.message);
  const tbRun = await TB();
  check('the trial balance still balances after the run', tbRun.balanced === true);
  const m5 = await detail(id5);
  check('FA-0005 now shows one month charged, and its figures are fixed', m5.asset.months_charged === 1 && m5.frozen && m5.frozen.fields.includes('cost_cents') && m5.schedule.entries[0].journal_id === r1.journal_id);
  const frozen = await PUT('/assets/' + id5, { cost_cents: 10000002, useful_life_months: 40, name: 'Honda motorcycle' }, T.bookkeeper);
  check('after the first entry cost and life are frozen (422)', frozen.status === 422 && frozen.data.errors.cost_cents && frozen.data.errors.useful_life_months && !frozen.data.errors.name, frozen.data);
  const rename = await PUT('/assets/' + id5, { name: 'Honda delivery motorcycle (TMX 155)' }, T.bookkeeper);
  check('its name can still change', rename.ok && rename.data.asset.name.includes('TMX'), rename.message);

  check('undoing needs a reason (422)', (await POST('/depreciation/runs/' + r1.id + '/undo', {}, T.accountant)).status === 422);
  check('a bookkeeper cannot undo a run (403)', (await POST('/depreciation/runs/' + r1.id + '/undo', { reason: 'x' }, T.bookkeeper)).status === 403);
  const older = runs.items.find((r) => !r.latest);
  const undoOld = await POST('/depreciation/runs/' + older.id + '/undo', { reason: 'test' }, T.accountant);
  check('only the latest run can be undone (' + older.period + ' refused)', undoOld.status === 409 && /Only the latest run/.test(undoOld.message), undoOld.message);
  const u1 = await POST('/depreciation/runs/' + r1.id + '/undo', { reason: 'fa-test: checking the undo' }, T.accountant);
  check('the accountant undoes the latest run: ' + u1.message, u1.ok && u1.data.reversal_id > 0, u1.message);
  const j1b = (await GET('/journals/' + r1.journal_id, T.viewer)).data;
  const rv1 = (await GET('/journals/' + u1.data.reversal_id, T.viewer)).data;
  check('its journal is reversed by a posted mirror entry on the same date, linked both ways',
    j1b.reversed_by && j1b.reversed_by.id === u1.data.reversal_id && rv1.journal.status === 'posted' && rv1.journal.source === 'reversal'
    && rv1.journal.entry_date === P1.end_date && rv1.journal.reversal_of_id === r1.journal_id, [j1b.reversed_by, rv1.journal.entry_date]);
  check('the run and its entries are gone', (await GET('/depreciation/runs/' + r1.id, T.viewer)).status === 404 && (await detail(id5)).asset.months_charged === 0);
  check('the trial balance is exactly as before the run', sameMap(tbMap(await TB()), tbBefore), diffMap(tbMap(await TB()), tbBefore));

  // ── a skipped month, and history in order ────────────────────────────────
  if (P2) {
    const pv2 = await GET('/depreciation/preview?period_id=' + P2.id, T.accountant);
    const exp2 = expected(P2);
    const got2 = Object.fromEntries(pv2.data.rows.map((r) => [r.asset_no, r.amount_cents]));
    check('skipping ' + P1.name + ': ' + P2.name + ' catches up both months (' + Object.entries(got2).map(([k, v]) => k + ' ' + v).join(', ') + ')',
      pv2.ok && sortObj(got2) === sortObj(exp2) && got2['FA-0001'] === 1500000 && got2['FA-0005'] === 540541 && pv2.data.caught_up === 1, [got2, exp2, pv2.data && pv2.data.caught_up]);
    const run2 = await POST('/depreciation/runs', { period_id: P2.id }, T.accountant);
    check('run ' + P2.name, run2.status === 201, run2.message);
    const early = await POST('/depreciation/runs', { period_id: P1.id }, T.accountant);
    check('a run for ' + P1.name + ', earlier than the latest run, is refused (409)', early.status === 409 && /can no longer be run/.test(early.message), early.message);
    const earlyPv = await GET('/depreciation/preview?period_id=' + P1.id, T.accountant);
    check('and so is its preview', earlyPv.status === 409);
    const u2 = await POST('/depreciation/runs/' + run2.data.run.id + '/undo', { reason: 'fa-test: skipped month check' }, T.accountant);
    check('undo ' + P2.name, u2.ok, u2.message);
    check('the trial balance is back where it was', sameMap(tbMap(await TB()), tbBefore), diffMap(tbMap(await TB()), tbBefore));
  }

  // ── disposals ────────────────────────────────────────────────────────────
  const runB = await POST('/depreciation/runs', { period_id: P1.id }, T.accountant);
  check('run ' + P1.name + ' again', runB.status === 201, runB.message);
  const ff = inUse.find((a) => a.asset_no === 'FA-0002');
  const truck = inUse.find((a) => a.asset_no === 'FA-0003');
  const oe = inUse.find((a) => a.asset_no === 'FA-0001');
  if (!disposeDate) {
    note('today (' + today + ') is not in ' + P1.name + ': the disposal checks need a date in the next run month, so they are skipped');
  } else {
    const early = await POST('/assets/' + ff.id + '/dispose', { disposed_on: disposeDate, proceeds_cents: 5000001, proceeds_account_id: acct['1113'].id, reason: 'Sold' }, T.accountant);
    check('a disposal in a month whose run includes the asset is refused (409): ' + early.message, early.status === 409 && /Undo that run/.test(early.message));
    check('a bookkeeper cannot dispose of an asset (403)', (await POST('/assets/' + ff.id + '/dispose', { disposed_on: disposeDate, reason: 'x' }, T.bookkeeper)).status === 403);
    const uB = await POST('/depreciation/runs/' + runB.data.run.id + '/undo', { reason: 'fa-test: disposals first' }, T.accountant);
    check('undo ' + P1.name + ' to record the disposals first', uB.ok, uB.message);

    const tbD = tbMap(await TB());
    const v1 = await POST('/assets/' + truck.id + '/dispose', { disposed_on: disposeDate, proceeds_cents: 7000000, reason: 'Sold' }, T.accountant);
    const v2 = await POST('/assets/' + truck.id + '/dispose', { disposed_on: '2999-01-01', proceeds_cents: 0, reason: '' }, T.accountant);
    check('disposal validation: proceeds need an account; the date cannot be in the future; a reason is needed',
      v1.status === 422 && v1.data.errors.proceeds_account_id && v2.status === 422 && v2.data.errors.disposed_on && v2.data.errors.reason, [v1.data, v2.data]);
    const v3 = await POST('/assets/' + ff.id + '/dispose', { disposed_on: disposeDate, proceeds_cents: 5000001, proceeds_account_id: acct['1121'].id, reason: 'Sold on credit' }, T.accountant);
    check('proceeds to the receivables control account need the customer (422)', v3.status === 422 && v3.data.errors.contact_id, v3.data);

    const g = await POST('/assets/' + truck.id + '/dispose', { disposed_on: disposeDate, proceeds_cents: 70000000, proceeds_account_id: acct['1113'].id,
      sold_to: 'Juan Dela Cruz', reason: 'Sold to a private buyer' }, T.accountant);
    check('dispose of the truck with a gain: ' + g.message, g.ok && g.data.asset.status === 'disposed' && g.data.disposal.gain_cents === 6000000 && g.data.disposal.nbv_cents === 64000000, g.message);
    const gj = (await GET('/journals/' + g.data.journal_id, T.viewer)).data;
    const want = [
      { account_id: acct['1219'].id, debit_cents: 56000000, credit_cents: 0 },
      { account_id: acct['1113'].id, debit_cents: 70000000, credit_cents: 0 },
      { account_id: acct['1218'].id, debit_cents: 0, credit_cents: 120000000 },
      { account_id: acct['4920'].id, debit_cents: 0, credit_cents: 6000000, department_id: depts.WHS.id },
    ];
    check('its journal: general, source disposal, source_id = the asset; Dr 1219 560,000.00 · Dr 1113 700,000.00 · Cr 1218 1,200,000.00 · Cr 4920 60,000.00',
      gj.journal.book === 'general' && gj.journal.source === 'disposal' && gj.journal.source_id === truck.id && gj.journal.entry_date === disposeDate
      && gj.journal.reference === 'FA-0003' && gj.journal.party_name === 'Juan Dela Cruz' && gj.journal.total_cents === 126000000 && sameLines(gj.lines, want),
      [gj.journal, gj.lines.map(lineKey)]);
    const twice = await POST('/assets/' + truck.id + '/dispose', { disposed_on: disposeDate, proceeds_cents: 0, reason: 'again' }, T.accountant);
    check('an asset cannot be disposed of twice (409)', twice.status === 409, twice.message);

    const cust = contacts['Legazpi Mini Mart'];
    const l = await POST('/assets/' + ff.id + '/dispose', { disposed_on: disposeDate, proceeds_cents: 5000001, proceeds_account_id: acct['1121'].id, contact_id: cust.id,
      reason: 'Sold on account to Legazpi Mini Mart' }, T.accountant);
    check('dispose of the furniture with a loss, on account: ' + l.message, l.ok && l.data.disposal.loss_cents === 5199999, l.message);
    const lj = (await GET('/journals/' + l.data.journal_id, T.viewer)).data;
    const wantL = [
      { account_id: acct['1217'].id, debit_cents: 7800000, credit_cents: 0 },
      { account_id: acct['1121'].id, debit_cents: 5000001, credit_cents: 0 },
      { account_id: acct['7200'].id, debit_cents: 5199999, credit_cents: 0, department_id: depts.ADM.id },
      { account_id: acct['1216'].id, debit_cents: 0, credit_cents: 18000000 },
    ];
    check('its journal to the centavo: Dr 1217 78,000.00 · Dr 1121 50,000.01 (the customer) · Dr 7200 51,999.99 · Cr 1216 180,000.00',
      sameLines(lj.lines, wantL) && lj.lines.find((x) => x.account_id === acct['1121'].id).contact_id === cust.id && lj.journal.total_cents === 18000000, lj.lines.map(lineKey));
    const tbAfter = await TB();
    check('the trial balance balances after both disposals', tbAfter.balanced === true);

    const dEdit = await PUT('/assets/' + ff.id, { cost_cents: 1, department_id: depts.WHS.id }, T.bookkeeper);
    check('a disposed asset keeps its figures and department (422)', dEdit.status === 422 && dEdit.data.errors.cost_cents && dEdit.data.errors.department_id, dEdit.data);
    const dNote = await PUT('/assets/' + ff.id, { notes: 'Sold with the old office layout' }, T.bookkeeper);
    check('but its notes can change', dNote.ok, dNote.message);
    check('a disposed asset cannot be deleted (409)', (await DEL('/assets/' + ff.id, T.bookkeeper)).status === 409);

    check('taking a disposal back needs a reason (422)', (await POST('/assets/' + ff.id + '/undo-disposal', {}, T.accountant)).status === 422);
    check('a bookkeeper cannot take a disposal back (403)', (await POST('/assets/' + ff.id + '/undo-disposal', { reason: 'x' }, T.bookkeeper)).status === 403);
    const ud = await POST('/assets/' + ff.id + '/undo-disposal', { reason: 'The buyer backed out' }, T.accountant);
    check('the accountant takes the furniture disposal back: ' + ud.message, ud.ok && ud.data.asset.status === 'active' && ud.data.asset.disposed_on === null, ud.message);
    const ljb = (await GET('/journals/' + l.data.journal_id, T.viewer)).data;
    check('its journal is reversed on the disposal date', ljb.reversed_by && ljb.reversed_by.entry_date === disposeDate);
    const tbU = tbMap(await TB());
    const tbDwithTruck = { ...tbD };
    for (const w of want) {
      const code = Object.values(acct).find((a) => a.id === w.account_id).code;
      tbDwithTruck[code] = (tbDwithTruck[code] || 0) + w.debit_cents - w.credit_cents;
    }
    check('the ledger is as it was before the furniture disposal', sameMap(tbU, tbDwithTruck), diffMap(tbU, tbDwithTruck));

    // a run cannot be undone under an asset disposed of after it
    const latestNow = (await GET('/depreciation/runs', T.viewer)).data.latest;
    const o1 = await POST('/assets/' + oe.id + '/dispose', { disposed_on: disposeDate, proceeds_cents: 0, reason: 'Scrapped (test)' }, T.accountant);
    check('scrap the office equipment for nothing: the whole book value is the loss', o1.ok && o1.data.disposal.loss_cents === o1.data.disposal.nbv_cents && o1.data.disposal.proceeds_cents === 0, o1.message);
    const uLate = await POST('/depreciation/runs/' + latestNow.id + '/undo', { reason: 'test' }, T.accountant);
    check('the ' + latestNow.period + ' run cannot be undone while an asset it depreciated has since been disposed of (409)', uLate.status === 409 && /Undo that disposal first/.test(uLate.message), uLate.message);
    const uo = await POST('/assets/' + oe.id + '/undo-disposal', { reason: 'Test over' }, T.accountant);
    check('take the scrapping back', uo.ok, uo.message);

    const pvF = await GET('/depreciation/preview?period_id=' + P1.id, T.accountant);
    const namesF = pvF.data.rows.map((r) => r.asset_no);
    check('a run after the disposal leaves the disposed truck out (' + namesF.join(', ') + ')', pvF.ok && !namesF.includes('FA-0003') && namesF.includes('FA-0002') && namesF.includes('FA-0005'));
    const runF = await POST('/depreciation/runs', { period_id: P1.id }, T.accountant);
    check('run ' + P1.name + ' for good', runF.status === 201, runF.message);

    const lsF = await GET('/reports/lapsing-schedule?to=' + P1.end_date, T.viewer);
    const truckRow = lsF.data.categories.flatMap((c) => c.rows).find((r) => r.asset_no === 'FA-0003');
    check('lapsing schedule to ' + P1.end_date + ': the truck leaves at cost 1,200,000 with 560,000 accumulated, no months remaining',
      truckRow && truckRow.disposals_cents === 120000000 && truckRow.accum_disposals_cents === 56000000 && truckRow.cost_end_cents === 0 && truckRow.months_remaining === null, truckRow);
    const motoRow = lsF.data.categories.flatMap((c) => c.rows).find((r) => r.asset_no === 'FA-0005');
    check('the motorcycle is an addition with one month of depreciation', motoRow && motoRow.additions_cents === 10000001 && motoRow.depreciation_cents === 270270, motoRow);
    check('after everything the lapsing schedule still ties to the ledger (' + P1.end_date + ')', lsF.data.ties === true, lsF.data.tie_out.filter((t) => t.difference_cents));
    check('and on ' + today, (await GET('/reports/lapsing-schedule?to=' + today, T.viewer)).data.ties === true);
  }

  // ── afterwards ───────────────────────────────────────────────────────────
  const tbEnd = await TB();
  check('the trial balance balances at the end', tbEnd.balanced === true, [tbEnd.total_debit_cents, tbEnd.total_credit_cents]);
  const au1 = await GET('/admin/audit?action=asset&per_page=200', T.admin);
  const au2 = await GET('/admin/audit?action=depreciation&per_page=200', T.admin);
  const acts = new Set([...(au1.data ? au1.data.items : []), ...(au2.data ? au2.data.items : [])].map((r) => r.action));
  const wantActs = ['asset_category.create', 'asset_category.update', 'asset_category.delete', 'asset.create', 'asset.update', 'asset.delete',
    'depreciation.run', 'depreciation.undo'].concat(disposeDate ? ['asset.dispose', 'asset.undo_disposal'] : []);
  check('the audit log recorded every kind of change', wantActs.every((a) => acts.has(a)), wantActs.filter((a) => !acts.has(a)));
  const runAudit = (au2.data.items || []).find((r) => r.action === 'depreciation.run');
  check('a run\'s audit row names the period, the journal and the total', runAudit && runAudit.target_type === 'depreciation_run' && runAudit.detail.journal_no && runAudit.detail.total_cents > 0, runAudit);
  const last = await detail(id5);
  check('FA-0005\'s history lists its registration and changes', last.trail.map((t) => t.action).join() === 'asset.create,asset.update,asset.update,asset.update', last.trail.map((t) => t.action));
} catch (e) {
  failed++;
  console.log('FAIL  the script stopped: ' + (e && e.stack ? e.stack.split('\n').slice(0, 3).join(' | ') : e));
}
console.log('\n' + passed + ' passed, ' + failed + ' failed');
process.exit(failed ? 1 : 0);
