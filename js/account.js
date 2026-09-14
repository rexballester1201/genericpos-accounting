/**
 * account.js — your own account: profile, picture, password, appearance
 *
 * GenericPOS Accounting · ES module · every signed-in role
 *
 *   GET/PUT /profile, POST /profile/password, POST|DELETE /profile/avatar,
 *   POST /auth/verify-email/send
 *
 * Your role is set by an administrator under Users; it is shown here, never
 * changed here.
 *
 * NOTE: a password change returns a FRESH token pair — the server ends every
 * other session, including the one this tab was using. Storing the new pair
 * is what keeps this tab signed in; dropping it would sign the user out of
 * the very screen they changed their password on.
 */

import { api, updateUser, setSession, logout } from './api.js';
import { assetUrl, fmtDate, setThemeOverride } from './store.js';
import { qs, qsa, esc, formValues, formError, setErrors, clearErrors, busy, toast, confirmDialog } from './ui.js';
import { mountPhoneFields, phoneValue } from './phone-field.js';
import { ROLE_LABEL } from './chrome.js';

const ROLE_HINT = {
  viewer: 'You can read the books and every report.',
  bookkeeper: 'You prepare journal entries and submit them for approval.',
  accountant: 'You prepare, approve, post and reverse entries, and close months.',
  admin: 'You can do everything, including the chart of accounts, fiscal years, users and settings.',
};

function initials(name) {
  const p = String(name || '').trim().split(/\s+/).filter(Boolean);
  return ((p[0] || '?')[0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase();
}

export async function mount(root, ctx) {
  let me;
  try {
    me = await api.get('/profile', null, { signal: ctx.signal });
  } catch (err) {
    if (ctx.signal.aborted) return;
    toast(err.message, { kind: 'error' });
    me = ctx.user;
    if (!me) return;
  }

  const profileForm = qs('form[data-form="profile"]', root);
  const pwForm = qs('form[data-form="password"]', root);
  mountPhoneFields(profileForm);

  // ── header and notes ──────────────────────────────────────────────────────
  const paintHead = () => {
    const av = me.avatar_url ? '<img src="' + esc(assetUrl(me.avatar_url)) + '" alt="">' : esc(initials(me.full_name || me.username));
    qs('[data-avatar]', root).innerHTML = av;
    qs('[data-avatar-lg]', root).innerHTML = av;
    qs('[data-avatar-remove]', root).hidden = !me.avatar_url;
    qs('[data-name]', root).textContent = me.full_name || me.username || 'My account';
    qs('[data-sub]', root).innerHTML = (me.role ? '<span class="badge badge-brand">' + esc(ROLE_LABEL[me.role] || me.role) + '</span> ' : '')
      + esc([me.email || me.phone || '', me.created_at ? 'user since ' + fmtDate(me.created_at, 'date') : ''].filter(Boolean).join(' · '));
    qs('[data-role-hint]', root).textContent = ROLE_HINT[me.role] || '';
    qs('[data-verify-note]', root).hidden = !(me.email && !me.is_email_verified);
    qs('[data-email-hint]', root).textContent = me.email ? (me.is_email_verified ? 'Confirmed.' : 'Not confirmed yet.') : '';
  };

  // ── profile ───────────────────────────────────────────────────────────────
  const fillProfile = () => {
    const f = profileForm.elements;
    f.full_name.value = me.full_name || '';
    f.email.value = me.email || '';
    f.username.value = me.username || '';
    f.phone.value = me.phone || '';
    if (f.phone.gpPhoneSync) f.phone.gpPhoneSync();
    const wait = parseInt(me.username_cooldown_s, 10) || 0;
    const days = Math.ceil(wait / 86400);
    f.username.disabled = wait > 0;
    qs('[data-username-hint]', root).textContent = wait > 0
      ? 'You can change your username again in ' + days + ' day' + (days === 1 ? '' : 's') + '.'
      : 'Letters, numbers and underscores. You can sign in with it.';
  };

  profileForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearErrors(profileForm);
    const v = formValues(profileForm);
    if (!v.full_name.trim()) return setErrors(profileForm, { full_name: 'Enter your name.' });

    const body = { full_name: v.full_name.trim(), email: v.email.trim(), phone: phoneValue(profileForm.elements.phone) };
    if (!profileForm.elements.username.disabled) body.username = v.username.trim();

    const btn = qs('button[type="submit"]', profileForm);
    busy(btn, true);
    try {
      const r = await api.put('/profile', body);
      me = Object.assign({}, me, r.user);
      await updateUser(r.user);
      paintHead();
      fillProfile();
      ctx.refreshChrome();
      toast(r.changed && r.changed.length ? 'Your profile is saved.' : 'Nothing changed.');
    } catch (err) {
      formError(profileForm, err);
    } finally { busy(btn, false); }
  });

  // ── picture ───────────────────────────────────────────────────────────────
  const fileInput = qs('[data-avatar-input]', root);
  fileInput.addEventListener('change', async () => {
    const file = fileInput.files && fileInput.files[0];
    fileInput.value = '';
    if (!file) return;
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) { toast('Choose a JPG, PNG or WebP image.', { kind: 'error' }); return; }
    if (file.size > 8 * 1024 * 1024) { toast('That image is larger than 8 MB.', { kind: 'error' }); return; }
    const fd = new FormData();
    fd.append('avatar', file);
    const label = fileInput.closest('label');
    label.classList.add('is-busy');
    try {
      const r = await api.upload('/profile/avatar', fd);
      me.avatar_url = r.avatar_url;
      await updateUser({ avatar_url: r.avatar_url });
      paintHead();
      ctx.refreshChrome();
      toast('Your picture is updated.');
    } catch (err) {
      toast((err.errors && err.errors.avatar) || err.message, { kind: 'error' });
    } finally { label.classList.remove('is-busy'); }
  });

  qs('[data-avatar-remove]', root).addEventListener('click', async () => {
    if (!(await confirmDialog({ title: 'Remove your picture?', confirmLabel: 'Remove', danger: true }))) return;
    try {
      await api.del('/profile/avatar');
      me.avatar_url = null;
      await updateUser({ avatar_url: null });
      paintHead();
      ctx.refreshChrome();
      toast('Picture removed.');
    } catch (err) { toast(err.message, { kind: 'error' }); }
  });

  // ── password ──────────────────────────────────────────────────────────────
  const paintPassword = () => {
    const has = me.has_password !== false;
    qs('[data-current-row]', root).hidden = !has;
    qs('[data-no-pw-note]', root).hidden = has;
    qs('[data-pw-title]', root).textContent = has ? 'Password' : 'Set a password';
    qs('[data-pw-submit]', root).textContent = has ? 'Change password' : 'Set password';
    qsa('[data-pw-min]', root).forEach((s) => { s.textContent = String(me.password_min_length || 8); });
  };

  qsa('[data-pw-toggle]', root).forEach((btn) => {
    const input = btn.parentElement.querySelector('input');
    btn.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      const ic = btn.querySelector('[data-icon]');
      if (ic) ic.setAttribute('data-icon', show ? 'eye-slash' : 'eye');
    });
  });

  pwForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearErrors(pwForm);
    const v = formValues(pwForm);
    const errors = {};
    if (me.has_password !== false && !v.current_password) errors.current_password = 'Enter your current password.';
    if (!v.new_password) errors.new_password = 'Choose a new password.';
    else if (v.new_password !== v.confirm_password) errors.confirm_password = 'Passwords do not match.';
    if (Object.keys(errors).length) return setErrors(pwForm, errors);

    const btn = qs('button[type="submit"]', pwForm);
    busy(btn, true);
    try {
      const r = await api.post('/profile/password', {
        current_password: v.current_password || '',
        new_password: v.new_password,
        confirm_password: v.confirm_password,
      });
      if (r && r.access_token) await setSession({ access_token: r.access_token, refresh_token: r.refresh_token });
      me.has_password = true;
      await updateUser({ has_password: true });
      pwForm.reset();
      paintPassword();
      toast('Password changed. You have been signed out on your other devices.');
    } catch (err) {
      formError(pwForm, err);
    } finally { busy(btn, false); }
  });

  // ── email confirmation ───────────────────────────────────────────────────
  qs('[data-send-verify]', root).addEventListener('click', async (e) => {
    const b = e.currentTarget;
    b.disabled = true;
    try {
      const r = await api.post('/auth/verify-email/send');
      if (r && r.verified) { me.is_email_verified = true; await updateUser({ is_email_verified: true }); paintHead(); }
      toast(r && r.verified ? 'Your email address is already confirmed.' : 'Confirmation link sent. Check your email.');
    } catch (err) {
      toast(err.message, { kind: 'error' });
    } finally { b.disabled = false; }
  });

  // ── theme ─────────────────────────────────────────────────────────────────
  const seg = qs('[data-theme-seg]', root);
  const paintTheme = () => {
    let o = null;
    try { o = localStorage.getItem('acc_theme'); } catch { /* storage blocked */ }
    qsa('button', seg).forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.mode === (o || 'auto'))));
  };
  seg.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-mode]');
    if (!b) return;
    setThemeOverride(b.dataset.mode === 'auto' ? null : b.dataset.mode);
    paintTheme();
    ctx.refreshChrome();
  });

  qs('[data-logout]', root).addEventListener('click', async () => {
    await logout();
    toast('You have signed out.');
    ctx.navigate('/login', { replace: true });
  });

  paintHead();
  fillProfile();
  paintPassword();
  paintTheme();
}
