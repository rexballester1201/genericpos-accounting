/**
 * imports.js — bringing a spreadsheet in: chart, contacts, opening balances, entries
 *
 * GenericPOS Accounting · ES module · administrators (PLAN.md §8)
 *
 *   Choose what to import → drop in a CSV file (or paste it) → match the
 *   columns → Check the file → Import.
 *
 * The file is read here and its rows are sent as data; the server checks every
 * row and answers row by row, and only writes when told to — in one
 * transaction. Opening balances land in the opening-balance draft and entries
 * land as drafts, so nothing reaches the ledger without the usual approval.
 */

import { api } from './api.js';
import { qs, esc, emptyState, toast, busy, confirmDialog } from './ui.js';

/** A CSV reader that copes with quotes, commas and newlines inside fields. */
function parseCsv(text) {
  const rows = [];
  let row = [];
  let field = '';
  let quoted = false;
  const s = String(text).replace(/^﻿/, '').replace(/\r\n?/g, '\n');
  for (let i = 0; i < s.length; i++) {
    const ch = s[i];
    if (quoted) {
      if (ch === '"') {
        if (s[i + 1] === '"') { field += '"'; i++; } else quoted = false;
      } else field += ch;
    } else if (ch === '"') {
      quoted = true;
    } else if (ch === ',' || ch === ';' || ch === '\t') {
      row.push(field);
      field = '';
    } else if (ch === '\n') {
      row.push(field);
      field = '';
      if (row.some((c) => c.trim() !== '')) rows.push(row);
      row = [];
    } else {
      field += ch;
    }
  }
  row.push(field);
  if (row.some((c) => c.trim() !== '')) rows.push(row);
  return rows.map((r) => r.map((c) => c.trim()));
}

const csvCell = (v) => {
  const s = String(v == null ? '' : v);
  return /[",\n]/.test(s) ? '"' + s.replace(/"/g, '""') + '"' : s;
};

export async function mount(root, ctx) {
  const box = qs('[data-body]', root);
  let st = null;
  let kind = null;          // the chosen kind definition
  let header = [];          // the file's own header row
  let rows = [];            // the file's data rows
  let map = {};             // our column key → the file's column index
  let checked = null;       // the last answer from the server
  let fileName = '';

  const K = () => st.kinds.find((k) => k.key === kind) || st.kinds[0];

  // ── the pieces ────────────────────────────────────────────────────────
  const chooser = () => '<section class="card card-pad stack"><h3>What are you importing?</h3>'
    + '<div class="row gap-2">' + st.kinds.map((k) => '<button class="btn ' + (k.key === kind ? '' : 'btn-secondary') + ' btn-sm" type="button" data-kind="' + k.key + '">'
      + esc(k.label) + '</button>').join('') + '</div>'
    + '<p class="small muted">' + esc(K().about) + '</p></section>';

  const columnsCard = () => {
    const k = K();
    return '<section class="card"><div class="card-head"><div><h3>The columns of the file</h3>'
      + '<div class="small muted">A heading row first. Extra columns are ignored; the ones marked are needed.</div></div>'
      + '<button class="btn btn-secondary btn-sm" type="button" data-template><span data-icon="download-simple" data-icon-size="16"></span>Download a template</button></div>'
      + '<div class="table-wrap"><table class="table table-compact"><thead><tr><th>Column</th><th>What goes in it</th><th>In your file</th></tr></thead><tbody>'
      + k.columns.map((c) => '<tr><td>' + esc(c.label) + (c.required ? ' <span class="badge badge-err">needed</span>' : '') + '</td>'
        + '<td class="small muted">' + esc(c.help || '') + '</td>'
        + '<td>' + (header.length
          ? '<select class="select" data-map="' + c.key + '" aria-label="Which column holds ' + esc(c.label) + '"><option value="">— none —</option>'
            + header.map((h, i) => '<option value="' + i + '"' + (map[c.key] === i ? ' selected' : '') + '>' + esc(h || 'Column ' + (i + 1)) + '</option>').join('') + '</select>'
          : '<span class="faint">Choose a file first</span>') + '</td></tr>').join('')
      + '</tbody></table></div></section>';
  };

  const fileCard = () => '<section class="card card-pad stack">'
    + '<h3>The file</h3>'
    + '<div class="row gap-2">'
    + '<label class="btn btn-secondary"><span data-icon="upload-simple" data-icon-size="18"></span>Choose a CSV file<input type="file" accept=".csv,text/csv,text/plain" hidden data-file></label>'
    + (rows.length ? '<span class="small">' + esc(fileName || 'Pasted') + ' · ' + rows.length + ' row' + (rows.length === 1 ? '' : 's') + '</span>' : '')
    + '</div>'
    + '<details' + (rows.length ? '' : ' open') + '><summary class="small">Or paste the rows here</summary>'
    + '<textarea class="textarea" data-paste rows="6" placeholder="code,name,type&#10;1115,Petty Cash Fund,asset"></textarea>'
    + '<div class="row gap-2 mt-2"><button class="btn btn-secondary btn-sm" type="button" data-use-paste>Use what I pasted</button></div></details>'
    + (rows.length ? '<div class="row gap-2"><button class="btn" type="button" data-check><span data-icon="check-circle" data-icon-size="18"></span>Check the file</button>'
      + (checked && !checked.bad ? '<button class="btn btn-danger" type="button" data-import><span data-icon="upload-simple" data-icon-size="18"></span>Import ' + rows.length + ' row' + (rows.length === 1 ? '' : 's') + '</button>' : '')
      + '</div>' : '')
    + '</section>';

  const resultCard = () => {
    if (!checked) return '';
    const bad = checked.bad || 0;
    const head = checked.imported
      ? '<div class="alert alert-ok"><span data-icon="check-circle" data-icon-size="18"></span><div>' + esc(checked.message || 'Imported.') + '</div></div>'
      : bad
        ? '<div class="alert alert-err"><span data-icon="x-circle" data-icon-size="18"></span><div>' + bad + ' row' + (bad === 1 ? '' : 's') + ' need fixing. Nothing has been written.</div></div>'
        : '<div class="alert alert-ok"><span data-icon="check-circle" data-icon-size="18"></span><div>All ' + checked.rows.length + ' rows look right. Import them when you are ready.</div></div>';
    const totals = checked.totals
      ? '<p class="small">Debits ' + esc(money(checked.totals.debit_cents)) + ' · credits ' + esc(money(checked.totals.credit_cents))
        + (checked.note ? ' · ' + esc(checked.note) : '') + '</p>' : '';
    const entries = (checked.entries || []).length
      ? '<div class="table-wrap"><table class="table table-compact"><thead><tr><th>Entry</th><th>Date</th><th>Book</th><th>Description</th><th class="num">Lines</th><th class="num">Amount</th></tr></thead><tbody>'
        + checked.entries.map((e) => '<tr><td class="code">' + esc(e.key) + '</td><td>' + esc(e.date) + '</td><td>' + esc(e.book) + '</td><td>' + esc(e.description) + '</td>'
          + '<td class="num">' + e.lines + '</td><td class="num">' + esc(money(e.total_cents)) + '</td></tr>').join('') + '</tbody></table></div>' : '';
    return '<section class="card"><div class="card-head"><h3>The check</h3><span class="small muted">' + checked.rows.length + ' rows</span></div>'
      + '<div class="card-body stack">' + head + totals + entries + '</div>'
      + '<div class="table-wrap"><table class="table table-compact"><thead><tr><th style="width:5em">Line</th><th>Row</th><th>What happens</th></tr></thead><tbody>'
      + checked.rows.map((r) => '<tr class="' + (r.ok ? '' : 'is-bad') + '"><td class="num">' + r.line + '</td><td>' + esc(r.summary) + '</td>'
        + '<td>' + (r.ok ? (r.skip ? '<span class="small muted">Already here — left alone</span>' : '<span class="badge badge-ok">Ready</span>')
          : '<span class="badge badge-err">' + esc(r.errors.join(' ')) + '</span>') + '</td></tr>').join('')
      + '</tbody></table></div></section>';
  };

  const money = (c) => (Number(c) / 100).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  const render = () => {
    box.innerHTML = chooser() + fileCard() + columnsCard() + resultCard();
  };

  // ── reading a file ────────────────────────────────────────────────────
  const take = (text, name) => {
    const all = parseCsv(text);
    if (!all.length) { toast('That file has no rows.', { kind: 'error' }); return; }
    header = all[0];
    rows = all.slice(1);
    fileName = name || '';
    checked = null;
    map = {};
    const norm = (s) => String(s).toLowerCase().replace(/[^a-z0-9]/g, '');
    K().columns.forEach((c) => {
      const i = header.findIndex((h) => norm(h) === norm(c.key) || norm(h) === norm(c.label));
      if (i >= 0) map[c.key] = i;
    });
    if (rows.length > st.max_rows) {
      toast('That file has ' + rows.length + ' rows; import up to ' + st.max_rows + ' at a time.', { kind: 'error' });
      rows = rows.slice(0, st.max_rows);
    }
    render();
  };

  const body = () => ({
    rows: rows.map((r) => {
      const o = {};
      K().columns.forEach((c) => { if (map[c.key] !== undefined) o[c.key] = r[map[c.key]] || ''; });
      return o;
    }),
  });

  const send = async (commit, btn) => {
    const missing = K().columns.filter((c) => c.required && map[c.key] === undefined).map((c) => c.label);
    if (missing.length) { toast('Say which column holds: ' + missing.join(', ') + '.', { kind: 'error' }); return; }
    busy(btn, true);
    try {
      const r = await api.post('/imports/' + kind, Object.assign(body(), { commit }), { timeout: 120000 });
      checked = r;
      if (commit) {
        toast(r.result ? r.result.message : 'Imported.');
        checked.imported = true;
        checked.message = r.result ? r.result.message : 'Imported.';
        rows = [];
        header = [];
      }
      render();
    } catch (err) {
      toast(err.errors ? Object.values(err.errors).join(' ') : err.message, { kind: 'error' });
      busy(btn, false);
    }
  };

  // ── events ────────────────────────────────────────────────────────────
  root.addEventListener('click', async (e) => {
    const k = e.target.closest('[data-kind]');
    if (k) { kind = k.dataset.kind; rows = []; header = []; checked = null; render(); return; }

    if (e.target.closest('[data-template]')) {
      const s = K().sample;
      const csv = [s.header.join(','), ...s.rows.map((r) => r.map(csvCell).join(','))].join('\r\n');
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv' }));
      a.download = kind + '-template.csv';
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(a.href), 10000);
      return;
    }

    if (e.target.closest('[data-use-paste]')) {
      const t = qs('[data-paste]', root).value.trim();
      if (!t) { toast('Paste the rows first, with a heading row.', { kind: 'error' }); return; }
      take(t, 'Pasted rows');
      return;
    }

    const c = e.target.closest('[data-check]');
    if (c) { await send(false, c); return; }

    const i = e.target.closest('[data-import]');
    if (i) {
      const k2 = K();
      const ok = await confirmDialog({
        title: 'Import ' + rows.length + ' row' + (rows.length === 1 ? '' : 's') + '?',
        body: k2.about + ' It goes in as one change: if any row fails, nothing is written.',
        confirmLabel: 'Import them',
      });
      if (!ok) return;
      await send(true, i);
    }
  });

  root.addEventListener('change', (e) => {
    const f = e.target.closest('[data-file]');
    if (f && f.files && f.files[0]) {
      const file = f.files[0];
      if (file.size > 5 * 1024 * 1024) { toast('That file is larger than 5 MB.', { kind: 'error' }); return; }
      const reader = new FileReader();
      reader.onload = () => take(String(reader.result), file.name);
      reader.onerror = () => toast('That file could not be read.', { kind: 'error' });
      reader.readAsText(file);
      return;
    }
    const m = e.target.closest('[data-map]');
    if (m) {
      if (m.value === '') delete map[m.dataset.map]; else map[m.dataset.map] = Number(m.value);
      checked = null;
    }
  });

  try {
    st = await api.get('/imports', null, { signal: ctx.signal });
    kind = st.kinds[0].key;
    render();
  } catch (err) {
    if (!ctx.signal.aborted) box.innerHTML = emptyState('warning', 'Could not open the imports', err.message);
  }
}
