// budget-smoke.mjs — quick look at every GET endpoint of the budgets module (scratch DB only)
const BASE = process.argv[2] || 'http://127.0.0.1:8784/api/v1';
async function call(method, path, body, token) {
  const headers = { Accept: 'application/json' };
  if (body != null) headers['Content-Type'] = 'application/json';
  if (token) headers.Authorization = 'Bearer ' + token;
  const r = await fetch(BASE + path, { method, headers, body: body == null ? undefined : JSON.stringify(body) });
  const text = await r.text();
  let j = null;
  try { j = JSON.parse(text); } catch { /* not JSON */ }
  return { status: r.status, data: j ? j.data : null, message: j ? j.message : 'NOT JSON: ' + text.slice(0, 1500) };
}
const lr = await call('POST', '/auth/login', { identifier: 'viewer@example.test', password: 'DemoView#2026' });
const V = lr.data.access_token;
const show = (name, r, pick) => console.log(name + ' → ' + r.status + ' ' + r.message + (r.status < 300 && pick ? '\n   ' + JSON.stringify(pick(r.data)).slice(0, 900) : (r.status >= 300 ? '\n   ' + JSON.stringify(r.data).slice(0, 600) : '')));

show('departments', await call('GET', '/departments', null, V), (d) => ({ items: d.items.map((x) => [x.code, x.ytd, x.used_by, x.can_delete]), none: d.no_department, total: d.total, period: d.period }));
const bl = await call('GET', '/budgets', null, V);
show('budgets', bl, (d) => ({ items: d.items.map((x) => [x.id, x.name, x.status, x.is_primary, x.totals]), years: d.fiscal_years, can: d.can }));
const id = bl.data.items[0].id;
show('budget ' + id, await call('GET', '/budgets/' + id, null, V), (d) => ({ periods: d.periods.map((p) => p.name).join(','), sections: d.sections.map((s) => s.key + ':' + s.rows.length), depts: d.departments.map((x) => x.code || '0'), lines: d.lines.length, can: d.can, trail: d.trail, words: d.words.net }));
show('summary', await call('GET', '/budgets/summary', null, V), (d) => d);
const bva = await call('GET', '/reports/budget-vs-actual', null, V);
show('bva', bva, (d) => ({ params: d.params, summary: d.summary, checks: d.checks, lines: d.lines.slice(0, 6) }));
show('bva dept', await call('GET', '/reports/budget-vs-actual?department_id=' + (await call('GET', '/departments', null, V)).data.items[1].id + '&from_period=1&to_period=12&levels=0', null, V), (d) => ({ params: d.params, summary: d.summary, n: d.lines.length }));
show('bva none', await call('GET', '/reports/budget-vs-actual?department_id=0', null, V), (d) => ({ summary: d.summary }));
const di = await call('GET', '/reports/department-income', null, V);
show('dept income', di, (d) => ({ cols: d.columns.map((c) => c.label), checks: d.checks, net: d.totals.net, depts: d.departments.map((x) => x.code) }));
const csv = await fetch(BASE + '/budgets/' + id + '/export?department_id=all', { headers: { Authorization: 'Bearer ' + V } });
const t = new TextDecoder('utf-8', { ignoreBOM: true }).decode(new Uint8Array(await csv.arrayBuffer()));
console.log('export → ' + csv.status + ' ' + csv.headers.get('content-type') + '\n' + t.split('\n').slice(0, 12).join('\n'));
