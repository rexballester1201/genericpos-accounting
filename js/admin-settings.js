/**
 * admin-settings.js — every setting, drawn from the server's registry
 *
 * GenericPOS Accounting · ES module · administrators
 *
 * The form is built from what GET /admin/settings describes (type, limits,
 * options, hint, default), so a setting added to Settings_model appears here
 * with no front-end change. Only changed fields are sent; each is saved or
 * refused on its own, and a refusal shows under its field.
 *
 *   readonly   chosen at setup (the kind of organisation, the month the fiscal
 *              year starts): shown, never sent
 *   account    a default account, picked from the chart and stored as its
 *              code; only active accounts of the types it accepts are offered
 */

import { api } from './api.js';
import { money, toMajor, assetUrl, loadStore } from './store.js';
import { qs, esc, toast, busy, emptyState, openModal, formError, clearErrors, confirmDialog } from './ui.js';

export async function mount(root, ctx) {
  let groups = {};
  let list = [];
  let accounts = null;
  let current = ctx.query.get('group') || 'company';

  const byGroup = () => {
    const m = {};
    list.forEach((s) => { (m[s.group] = m[s.group] || []).push(s); });
    return m;
  };
  const setting = (key) => list.find((s) => s.key === key) || {};

  /* The co-operative group only means something to a co-operative. */
  const hiddenGroup = (k) => k === 'coop' && setting('entity_type').value !== 'cooperative';

  const accountLabel = (code) => {
    const a = accounts && accounts.find((x) => x.code === String(code));
    return a ? a.code + ' · ' + a.name : String(code);
  };

  const shown = (s, v) => {
    if (s.type === 'money') return money(v);
    if (s.type === 'bool') return v ? 'On' : 'Off';
    if (s.type === 'enum' && s.options) return s.options[v] || String(v);
    if (s.type === 'account' && v) return accountLabel(v);
    if (Array.isArray(v)) return v.join(', ');
    return v === '' || v == null ? '(empty)' : String(v);
  };
  const inputValue = (s) => {
    const v = s.value;
    if (s.type === 'money') return toMajor(v);
    if (Array.isArray(v)) return v.join(', ');
    return v == null ? '' : String(v);
  };

  const accountSelect = (s, id, dis) => {
    const value = inputValue(s);
    const types = s.account_types || null;
    const offer = accounts.filter((a) => a.code === value || (a.active && (!types || types.includes(a.type))));
    const known = offer.some((a) => a.code === value);
    return '<select class="select" id="' + id + '" name="' + s.key + '"' + dis + '>'
      + (value === '' ? '<option value="" selected>Not set</option>' : '')
      + (value !== '' && !known ? '<option value="' + esc(value) + '" selected>' + esc(value) + ' (not in the chart)</option>' : '')
      + offer.map((a) => '<option value="' + esc(a.code) + '"' + (a.code === value ? ' selected' : '') + '>'
        + esc(a.code + ' · ' + a.name) + (a.active ? '' : ' (inactive)') + '</option>').join('')
      + '</select>';
  };

  const fieldHtml = (s) => {
    const id = 'set-' + s.key;
    const dis = s.readonly ? ' disabled' : '';
    let input;
    if (s.type === 'bool') {
      input = '<label class="check"><input type="checkbox" id="' + id + '" name="' + s.key + '"' + (s.value ? ' checked' : '') + dis + '> <span>' + esc(s.label) + '</span></label>';
    } else if (s.type === 'enum') {
      input = '<select class="select" id="' + id + '" name="' + s.key + '"' + dis + '>' + Object.keys(s.options || {}).map((k) => '<option value="' + esc(k) + '"'
        + (String(s.value) === k ? ' selected' : '') + '>' + esc(s.options[k]) + '</option>').join('') + '</select>';
    } else if (s.type === 'account' && accounts) {
      input = accountSelect(s, id, dis);
    } else if (s.type === 'text') {
      input = '<textarea class="textarea" id="' + id + '" name="' + s.key + '" rows="4"' + (s.max_len ? ' maxlength="' + s.max_len + '"' : '') + dis + '>' + esc(inputValue(s)) + '</textarea>';
    } else if (s.type === 'color') {
      input = '<div class="row gap-2"><input type="color" value="' + esc(inputValue(s) || '#000000') + '" data-color-for="' + s.key + '" aria-label="' + esc(s.label) + '" style="width:48px;height:38px;padding:2px"' + dis + '>'
        + '<input class="input" id="' + id + '" name="' + s.key + '" value="' + esc(inputValue(s)) + '" maxlength="7" style="max-width:140px"' + dis + '></div>';
    } else {
      const mode = ['int', 'float', 'money'].includes(s.type) ? ' inputmode="decimal"' : '';
      input = '<input class="input" id="' + id + '" name="' + s.key + '" type="' + (s.type === 'email' ? 'email' : 'text') + '"' + mode
        + (s.max_len ? ' maxlength="' + s.max_len + '"' : '') + ' value="' + esc(inputValue(s)) + '" autocomplete="off"' + dis + '>';
    }
    const upload = s.upload && !s.readonly ? '<div class="row gap-2" style="margin-bottom:6px">'
      + (s.value ? '<img src="' + esc(assetUrl(s.value)) + '" alt="" style="max-height:56px;max-width:180px;border:1px solid var(--line);border-radius:6px;background:#fff">' : '')
      + '<label class="btn btn-secondary btn-sm" style="cursor:pointer"><span data-icon="upload-simple" data-icon-size="16"></span>Upload'
      + '<input type="file" accept="image/jpeg,image/png,image/webp" data-upload="' + esc(s.upload) + '" hidden></label></div>' : '';
    const limits = [s.min != null ? 'min ' + (s.type === 'money' ? money(s.min) : s.min) : '', s.max != null ? 'max ' + (s.type === 'money' ? money(s.max) : s.max) : ''].filter(Boolean).join(', ');
    const reset = s.overridden && !s.readonly
      ? '<span class="faint">Default: ' + esc(shown(s, s.default)) + ' · <a href="#" data-reset="' + s.key + '">reset</a></span>' : '';
    return '<div class="field" data-key="' + s.key + '">'
      + (s.type === 'bool' ? '' : '<label class="label" for="' + id + '">' + esc(s.label) + '</label>') + upload + input
      + '<div class="hint">' + (s.hint ? esc(s.hint) + ' ' : '') + (limits ? '(' + esc(limits) + ') ' : '') + reset + '</div>'
      + '<div class="error" data-error-for="' + s.key + '"></div></div>';
  };

  const render = () => {
    const g = byGroup();
    const keys = Object.keys(groups).filter((k) => g[k] && !hiddenGroup(k));
    if (!keys.includes(current)) current = keys[0];
    qs('[data-nav]', root).innerHTML = keys.map((k) => '<a href="#" class="side-link' + (k === current ? ' is-active' : '') + '" data-group="' + k + '">'
      + '<span data-icon="' + esc(groups[k].icon || 'gear') + '" data-icon-size="18"></span>' + esc(groups[k].label) + '</a>').join('');
    const mine = g[current] || [];
    qs('[data-group-title]', root).textContent = groups[current] ? groups[current].label : '';
    const changed = mine.filter((s) => s.overridden && !s.readonly).length;
    qs('[data-group-note]', root).textContent = changed ? changed + ' changed from the defaults' : '';
    qs('[data-fields]', root).innerHTML = mine.map(fieldHtml).join('');
    qs('[data-mail]', root).hidden = current !== 'notifications';
    history.replaceState(history.state, '', location.pathname + '?group=' + encodeURIComponent(current));
  };

  const load = async () => {
    const [d, lk] = await Promise.all([
      api.get('/admin/settings', null, { signal: ctx.signal }),
      api.get('/journals/lookups', null, { signal: ctx.signal }).catch(() => null),
    ]);
    groups = d.groups || {};
    list = d.settings || [];
    accounts = lk && Array.isArray(lk.accounts) ? lk.accounts : null;
  };

  const form = qs('[data-form]', root);
  const collect = () => {
    const out = {};
    (byGroup()[current] || []).forEach((s) => {
      if (s.readonly) return;
      const el = form.elements[s.key];
      if (!el) return;
      if (s.type === 'bool') { if (el.checked !== !!s.value) out[s.key] = el.checked; return; }
      const v = String(el.value).trim();
      if (v !== inputValue(s).trim()) out[s.key] = v;
    });
    return out;
  };

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearErrors(form);
    const changes = collect();
    if (!Object.keys(changes).length) { toast('Nothing changed.', { kind: 'info' }); return; }
    const btn = qs('button[type="submit"]', form);
    busy(btn, true);
    try {
      const r = await api.post('/admin/settings', { settings: changes });
      list = r.settings || list;
      render();
      if (r.errors && Object.keys(r.errors).length) {
        formError(form, { errors: r.errors });
        toast('Some settings could not be saved. The reasons are shown in red.', { kind: 'error' });
      } else toast('Saved.');
      loadStore(true).catch(() => {});
    } catch (err) { formError(form, err); } finally { busy(btn, false); }
  });

  form.addEventListener('input', (e) => {
    const c = e.target.closest('[data-color-for]');
    if (c) form.elements[c.dataset.colorFor].value = c.value;
  });

  form.addEventListener('change', async (e) => {
    const up = e.target.closest('[data-upload]');
    if (!up || !up.files || !up.files[0]) return;
    const fd = new FormData();
    fd.append('file', up.files[0]);
    fd.append('kind', up.dataset.upload);
    try {
      const r = await api.upload('/admin/settings/upload', fd);
      list = r.settings || list;
      render();
      toast('Image saved.');
      loadStore(true).catch(() => {});
    } catch (err) { toast((err.errors && (err.errors.file || err.errors.kind)) || err.message, { kind: 'error' }); }
  });

  root.addEventListener('click', async (e) => {
    const g = e.target.closest('[data-group]');
    if (g) {
      e.preventDefault();
      if (g.dataset.group === current) return;
      if (Object.keys(collect()).length && !(await confirmDialog({ title: 'Leave without saving?', body: 'Your changes in this group are not saved yet.', confirmLabel: 'Leave', danger: true }))) return;
      current = g.dataset.group;
      render();
      return;
    }
    const rs = e.target.closest('[data-reset]');
    if (rs) {
      e.preventDefault();
      try {
        const r = await api.post('/admin/settings/reset', { key: rs.dataset.reset });
        list = r.settings || list;
        render();
        toast('Back to the default.');
        loadStore(true).catch(() => {});
      } catch (err) { toast((err.errors && err.errors.key) || err.message, { kind: 'error' }); }
    }
  });

  qs('[data-mail]', root).addEventListener('click', async (e) => {
    const b = e.currentTarget;
    busy(b, true);
    try {
      const r = await api.post('/admin/settings/mail-test');
      openModal({
        title: r.ok ? 'Handed to the mail server' : 'The test email did not go out', size: 'sm',
        body: '<dl class="kv"><dt>To</dt><dd>' + esc(r.to || '') + '</dd><dt>How</dt><dd>' + esc(r.transport || '') + '</dd>'
          + (r.subject ? '<dt>Subject</dt><dd>' + esc(r.subject) + '</dd>' : '') + (r.detail ? '<dt>Detail</dt><dd>' + esc(r.detail) + '</dd>' : '') + '</dl>'
          + '<p class="' + (r.ok ? 'muted' : 'err-text') + '" style="margin-top:12px">' + esc(r.note || '') + '</p>',
        actions: [{ label: 'OK' }],
      });
    } catch (err) { toast(err.message, { kind: 'error' }); } finally { busy(b, false); }
  });

  try {
    await load();
    qs('[data-state]', root).innerHTML = '';
    qs('[data-body]', root).hidden = false;
    render();
  } catch (err) {
    if (!ctx.signal.aborted) qs('[data-state]', root).innerHTML = emptyState('warning', 'Could not load the settings', err.message);
  }
}
