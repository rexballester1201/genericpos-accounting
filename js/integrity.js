/**
 * integrity.js — does everything still add up?
 *
 * GenericPOS Accounting · ES module · accountants and administrators
 *
 * The integrity report (Integrity_lib): each check passes, warns or fails,
 * with up to twenty examples that link to where the problem is looked into.
 * Nothing here changes anything.
 */

import { api } from './api.js';
import { qs, esc, emptyState, busy } from './ui.js';
import { sheetHead, sheetFoot, asOfText, exportCsv } from './report-kit.js';

const LOOK = { ok: ['check-circle', 'Passed'], warn: ['warning', 'Worth a look'], fail: ['x-circle', 'Needs fixing'] };

export async function mount(root, ctx) {
  const sheet = qs('[data-sheet]', root);

  const render = (d) => {
    const s = d.summary;
    const lead = s.fail
      ? '<div class="alert alert-err"><span data-icon="x-circle" data-icon-size="18"></span><div><strong>' + s.fail + ' check' + (s.fail === 1 ? '' : 's') + ' failed.</strong> Open the examples below; each links to the record to look into. Tell your administrator if you cannot see why.</div></div>'
      : s.warn
        ? '<div class="alert alert-warn"><span data-icon="warning" data-icon-size="18"></span><div><strong>No failures.</strong> ' + s.warn + ' check' + (s.warn === 1 ? ' is' : 's are') + ' worth a look.</div></div>'
        : '<div class="alert alert-ok"><span data-icon="check-circle" data-icon-size="18"></span><div><strong>All ' + s.ok + ' checks passed.</strong> The books add up.</div></div>';
    const checks = d.checks.map((c) => {
      const [icon, label] = LOOK[c.status];
      return '<section class="int-check is-' + c.status + '">'
        + '<div class="int-head"><span data-icon="' + icon + '" data-icon-size="20"></span><div class="grow"><div class="int-title">' + esc(c.title) + '</div>'
        + (c.about ? '<div class="small muted">' + esc(c.about) + '</div>' : '') + '</div>'
        + '<span class="badge ' + (c.status === 'ok' ? 'badge-ok' : c.status === 'warn' ? 'badge-warn' : 'badge-err') + '">' + esc(label) + (c.count ? ' · ' + c.count : '') + '</span></div>'
        + (c.items.length ? '<details' + (c.status === 'fail' ? ' open' : '') + '><summary class="small">' + (c.count > c.items.length ? 'The first ' + c.items.length + ' of ' + c.count : c.count + ' found') + '</summary><ul class="int-items">'
          + c.items.map((i) => '<li>' + (i.link ? '<a href="' + esc(i.link) + '">' + esc(i.text) + '</a>' : esc(i.text)) + '</li>').join('') + '</ul></details>' : '')
        + '</section>';
    }).join('');
    sheet.innerHTML = sheetHead(d.letterhead, 'Integrity check', [asOfText(d.as_of)]) + lead + '<div class="int-list">' + checks + '</div>' + sheetFoot(d.letterhead);
  };

  const load = async () => {
    sheet.classList.add('is-busy');
    try {
      render(await api.get('/reports/integrity', null, { signal: ctx.signal, timeout: 90000 }));
    } catch (err) {
      if (!ctx.signal.aborted) sheet.innerHTML = emptyState('warning', 'Could not run the integrity check', err.message);
    } finally { sheet.classList.remove('is-busy'); }
  };

  qs('[data-rerun]', root).addEventListener('click', async (e) => { const b = e.currentTarget; busy(b, true); await load(); busy(b, false); });
  qs('[data-print]', root).addEventListener('click', () => window.print());
  qs('[data-csv]', root).addEventListener('click', (e) => exportCsv(e.currentTarget, '/reports/integrity', {}, 'integrity-check.csv'));

  await load();
}
