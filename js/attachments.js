/**
 * attachments.js — the files on a record, as a panel any detail page can mount
 *
 * GenericPOS Accounting · ES module
 *
 *   attachmentsPanel(el, { owner: 'journal', id: 12, user })
 *     owner: journal | document | settlement | asset
 *
 * The server never serves these files by plain address (Attachments.php), so
 * they are fetched with the session and opened from memory: a PDF or a
 * picture in a new tab, anything else saved to the device. Bookkeepers and up
 * attach; the uploader (on the day) or an accountant removes.
 */

import { api, apiFetch, download } from './api.js';
import { fmtDate } from './store.js';
import { esc, toast, confirmDialog } from './ui.js';
import { hasRole } from './router.js';

const ACCEPT = '.pdf,.jpg,.jpeg,.png,.webp,.gif,.csv,.txt,.xlsx,.xls,.docx,.doc';
const size = (n) => (n < 1024 ? n + ' B' : n < 1048576 ? Math.round(n / 1024) + ' KB' : (n / 1048576).toFixed(1) + ' MB');
const icon = (mime) => (mime === 'application/pdf' ? 'file-text' : /^image\//.test(mime) ? 'image' : 'paperclip');

export function attachmentsPanel(el, o) {
  let items = [];
  const canAdd = hasRole(o.user, 'bookkeeper');
  const q = { owner_type: o.owner, owner_id: o.id };

  const row = (a) => '<li class="att-row" data-att="' + a.id + '">'
    + '<span data-icon="' + icon(a.mime) + '" data-icon-size="18"></span>'
    + '<button class="att-name" type="button" data-open title="' + (a.inline ? 'Open' : 'Save') + ' ' + esc(a.name) + '">' + esc(a.name) + '</button>'
    + '<span class="xs faint">' + esc(size(a.bytes) + ' · ' + a.uploaded_by + ' · ' + fmtDate(a.created_at, 'date')) + '</span>'
    + '<button class="icon-btn" type="button" data-save aria-label="Save ' + esc(a.name) + '"><span data-icon="download-simple" data-icon-size="16"></span></button>'
    + (a.can_remove ? '<button class="icon-btn" type="button" data-remove aria-label="Remove ' + esc(a.name) + '"><span data-icon="trash" data-icon-size="16"></span></button>' : '')
    + '</li>';

  const render = () => {
    el.innerHTML = '<section class="card no-print"><div class="card-head"><div><h3>Attachments</h3>'
      + '<div class="small muted">Scanned receipts, invoices, contracts — PDF, pictures, spreadsheets. Up to 10 MB each.</div></div>'
      + (canAdd ? '<label class="btn btn-secondary btn-sm"><span data-icon="paperclip" data-icon-size="16"></span>Attach a file'
        + '<input type="file" data-att-input accept="' + ACCEPT + '" hidden></label>' : '') + '</div>'
      + (items.length ? '<ul class="att-list">' + items.map(row).join('') + '</ul>' : '<div class="card-body"><p class="small muted">No files attached.</p></div>')
      + '</section>';
  };

  const load = async () => {
    try {
      items = (await api.get('/attachments', q)).items || [];
    } catch (err) {
      items = [];
      if (!err.isForbidden) toast('Could not load the attachments: ' + err.message, { kind: 'error' });
    }
    render();
  };

  el.addEventListener('change', async (e) => {
    const input = e.target.closest('[data-att-input]');
    if (!input || !input.files || !input.files[0]) return;
    const file = input.files[0];
    input.value = '';
    if (file.size > 10 * 1024 * 1024) { toast(file.name + ' is larger than 10 MB.', { kind: 'error' }); return; }
    const fd = new FormData();
    fd.append('owner_type', o.owner);
    fd.append('owner_id', String(o.id));
    fd.append('file', file);
    const label = input.closest('label');
    label.classList.add('is-busy');
    try {
      const r = await api.upload('/attachments', fd, { timeout: 120000 });
      items = r.items || [];
      toast(file.name + ' is attached.');
      render();
    } catch (err) {
      toast((err.errors && (err.errors.file || err.errors.owner)) || err.message, { kind: 'error' });
      label.classList.remove('is-busy');
    }
  });

  el.addEventListener('click', async (e) => {
    const li = e.target.closest('[data-att]');
    if (!li) return;
    const a = items.find((x) => x.id === Number(li.dataset.att));
    if (!a) return;
    const path = '/attachments/' + a.id + '/file';

    if (e.target.closest('[data-save]') || (e.target.closest('[data-open]') && !a.inline)) {
      try { await download(path + '?download=1', a.name); } catch (err) { toast(err.message, { kind: 'error' }); }
      return;
    }
    if (e.target.closest('[data-open]')) {
      /* Open the tab now, while the click still counts as the person's own:
         a window opened after the download would be taken for a pop-up. */
      const w = window.open('', '_blank');
      try {
        const blob = await apiFetch('GET', path, null, { blob: true, timeout: 60000 });
        const url = URL.createObjectURL(blob);
        if (w) w.location.href = url; else window.location.assign(url);
        setTimeout(() => URL.revokeObjectURL(url), 60000);
      } catch (err) {
        if (w) w.close();
        toast(err.message, { kind: 'error' });
      }
      return;
    }
    if (e.target.closest('[data-remove]')) {
      if (!(await confirmDialog({ title: 'Remove ' + a.name + '?', body: 'The file is deleted from the server. The record it supported stays as it is.', confirmLabel: 'Remove it', danger: true }))) return;
      try {
        const r = await api.del('/attachments/' + a.id);
        items = r.items || [];
        toast(a.name + ' is removed.');
        render();
      } catch (err) { toast(err.message, { kind: 'error' }); }
    }
  });

  el.innerHTML = '';
  load();
  return { reload: load };
}
