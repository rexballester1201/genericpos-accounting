/**
 * admin-staff.js — the people who use the ledger, and their roles
 *
 * GenericPOS Accounting · ES module · administrators
 *
 *   GET /admin/staff · POST /admin/staff · PUT /admin/staff/{id}
 *
 * The server refuses to demote or suspend the last active administrator and
 * refuses to let anyone suspend themselves; those refusals show under the
 * field they concern.
 */

import { api } from './api.js';
import { fmtDate } from './store.js';
import { qs, esc, emptyState, statusBadge, openModal, busy, formError, setErrors, clearErrors, toast } from './ui.js';
import { ROLE_LABEL } from './chrome.js';

const ROLES = ['viewer', 'bookkeeper', 'accountant', 'admin'];
const ROLE_HINT = {
  viewer: 'Reads the books and every report. Changes nothing.',
  bookkeeper: 'Also prepares journal entries and submits them for approval.',
  accountant: 'Also approves, posts and reverses entries, and closes months.',
  admin: 'Also runs the chart of accounts, fiscal years, users, settings and the audit log.',
};

export async function mount(root, ctx) {
  const tbody = qs('[data-rows]', root);
  const me = ctx.user ? ctx.user.id : 0;
  let rows = [];

  qs('[data-roles]', root).innerHTML = ROLES.map((r) => '<dt>' + esc(ROLE_LABEL[r]) + '</dt><dd>' + esc(ROLE_HINT[r]) + '</dd>').join('');

  const render = () => {
    tbody.innerHTML = rows.length ? rows.map((u) => '<tr class="is-link" data-id="' + u.id + '">'
      + '<td><b>' + esc(u.full_name || u.username) + '</b><div class="small muted">'
      + esc([u.username ? '@' + u.username : '', u.id === me ? 'you' : ''].filter(Boolean).join(' · ')) + '</div></td>'
      + '<td>' + esc(ROLE_LABEL[u.role] || u.role) + '</td>'
      + '<td class="small">' + (u.email ? esc(u.email) + (u.is_email_verified ? '' : '<div class="faint">Not confirmed</div>') : '<span class="faint">None</span>')
      + (u.has_password ? '' : '<div class="err-text">No password, so cannot sign in</div>') + '</td>'
      + '<td>' + statusBadge(u.account_state) + '</td>'
      + '<td class="small">' + (u.last_login_at ? esc(fmtDate(u.last_login_at)) : '<span class="faint">Never</span>') + '</td></tr>').join('')
      : '<tr><td colspan="5">' + emptyState('users-three', 'No users yet', 'Add the people who keep and review the books.') + '</td></tr>';
  };

  const load = async () => {
    try {
      const d = await api.get('/admin/staff', null, { signal: ctx.signal });
      rows = d.items || [];
      render();
    } catch (err) {
      if (!ctx.signal.aborted) tbody.innerHTML = '<tr><td colspan="5">' + emptyState('warning', 'Could not load the users', err.message) + '</td></tr>';
    }
  };

  const field = (n, label, type, val, hint, extra) => '<div class="field"><label class="label" for="st-' + n + '">' + label + '</label>'
    + '<input class="input" id="st-' + n + '" name="' + n + '" type="' + type + '" value="' + esc(val || '') + '"'
    + (type === 'password' ? ' autocomplete="new-password"' : ' autocomplete="off"') + (extra || '') + '>'
    + (hint ? '<div class="hint">' + esc(hint) + '</div>' : '') + '<div class="error" data-error-for="' + n + '"></div></div>';

  const edit = (u) => {
    const isNew = !u;
    const self = !isNew && u.id === me;
    const role = u ? u.role : 'bookkeeper';
    const m = openModal({
      title: isNew ? 'Add a user' : (u.full_name || u.username), size: 'sm',
      body: '<form class="stack" data-f novalidate><div class="alert alert-err" data-banner hidden></div>'
        + field('full_name', 'Name', 'text', u && u.full_name, '', ' maxlength="120"')
        + field('email', 'Email address', 'email', u && u.email, 'They reset a forgotten password through it.', ' autocapitalize="off" spellcheck="false"')
        + field('phone', 'Mobile number', 'tel', u && u.phone, 'Optional.')
        + '<div class="field"><label class="label" for="st-role">Role</label><select class="select" id="st-role" name="role"' + (self ? ' disabled' : '') + '>'
        + ROLES.map((r) => '<option value="' + r + '"' + (role === r ? ' selected' : '') + '>' + esc(ROLE_LABEL[r]) + '</option>').join('') + '</select>'
        + '<div class="hint" data-role-hint>' + esc(self ? 'You cannot change your own role.' : ROLE_HINT[role]) + '</div><div class="error" data-error-for="role"></div></div>'
        + field('password', isNew ? 'Password' : 'New password', 'password', '', isNew ? 'Ask them to change it after they first sign in.' : 'Leave empty to keep their current password.')
        + (!isNew && !self ? '<label class="check"><input type="checkbox" name="suspended"' + (u.account_state === 'suspended' ? ' checked' : '') + '> <span>Suspended: cannot sign in</span></label>'
          + '<div class="error" data-error-for="account_state"></div>' : '')
        + '<div class="row gap-2" style="justify-content:flex-end"><button class="btn btn-secondary" type="button" data-x>Cancel</button>'
        + '<button class="btn" type="submit">' + (isNew ? 'Add user' : 'Save') + '</button></div></form>',
    });
    const f = qs('[data-f]', m.el);
    const el = f.elements;
    el.role.addEventListener('change', () => { qs('[data-role-hint]', f).textContent = ROLE_HINT[el.role.value] || ''; });
    qs('[data-x]', f).addEventListener('click', () => m.close());
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErrors(f);
      const body = { full_name: el.full_name.value.trim(), email: el.email.value.trim(), phone: el.phone.value.trim() };
      if (!self) body.role = el.role.value;
      if (el.password.value) body.password = el.password.value;
      if (el.suspended) body.account_state = el.suspended.checked ? 'suspended' : 'active';

      const errors = {};
      if (!body.full_name) errors.full_name = 'Enter a name.';
      if (!body.email && (isNew || u.email)) errors.email = 'Enter their email address.';
      if (isNew && !body.password) errors.password = 'Give them a password to sign in with.';
      if (Object.keys(errors).length) { setErrors(f, errors); return; }

      const btn = qs('button[type="submit"]', f);
      busy(btn, true);
      try {
        await (isNew ? api.post('/admin/staff', body) : api.put('/admin/staff/' + u.id, body));
        m.close();
        toast(isNew ? body.full_name + ' can now sign in.' : 'Saved.');
        load();
      } catch (err) {
        formError(f, err);
        busy(btn, false);
      }
    });
  };

  tbody.addEventListener('click', (e) => {
    const tr = e.target.closest('tr[data-id]');
    if (tr) edit(rows.find((u) => u.id === Number(tr.dataset.id)));
  });
  qs('[data-add]', root).addEventListener('click', () => edit(null));

  await load();
}
