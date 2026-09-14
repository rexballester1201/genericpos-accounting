/**
 * auth-pages.js — sign in, forgot/reset password, confirm email
 *
 * GenericPOS Accounting · ES module. One module for the four small screens;
 * the fragment's data-view says which one this is. There is no public sign-up:
 * an administrator adds each user under Users.
 *
 * NOTE: the "next" parameter is followed only through router.safeNext(), which
 * accepts an app path and nothing else — a sign-in page that redirects to any
 * URL it is handed is a phishing tool with this company's name on it.
 */

import { api, login, currentUser, updateUser, clearSession } from './api.js';
import { store } from './store.js';
import { qs, qsa, formValues, formError, setErrors, clearErrors, busy, toast } from './ui.js';
import { safeNext, landingFor } from './router.js';

export function mount(root, ctx) {
  wirePasswordToggles(root);
  loadPasswordRule(root, ctx);

  switch (root.getAttribute('data-view')) {
    case 'login': return viewLogin(root, ctx);
    case 'forgot': return viewForgot(root, ctx);
    case 'reset': return viewReset(root, ctx);
    case 'verify': return viewVerify(root, ctx);
    default: return undefined;
  }
}

// ─── Shared ─────────────────────────────────────────────────────────────────

function wirePasswordToggles(root) {
  qsa('[data-pw-toggle]', root).forEach((btn) => {
    const input = btn.parentElement.querySelector('input');
    btn.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
      const ic = btn.querySelector('[data-icon]');
      if (ic) ic.setAttribute('data-icon', show ? 'eye-slash' : 'eye');
      input.focus();
    });
  });
}

function loadPasswordRule(root, ctx) {
  const slots = qsa('[data-pw-min]', root);
  if (!slots.length) return;
  api.get('/auth', null, { auth: false, signal: ctx.signal })
    .then((c) => { if (c && c.password_min_length) slots.forEach((s) => { s.textContent = String(c.password_min_length); }); })
    .catch(() => {});
}

function showStep(root, name) {
  qsa('[data-step]', root).forEach((el) => { el.hidden = el.getAttribute('data-step') !== name; });
}

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;

// ─── Sign in ────────────────────────────────────────────────────────────────

function viewLogin(root, ctx) {
  const name = store().name;
  const form = qs('form', root);
  qs('[data-welcome]', root).textContent = name ? 'Welcome back to the books of ' + name + '.' : 'Welcome back.';

  api.get('/setup', null, { auth: false, signal: ctx.signal })
    .then((st) => { if (st && st.needed) qs('[data-setup-note]', root).hidden = false; })
    .catch(() => {});

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearErrors(form);
    const v = formValues(form);
    const errors = {};
    if (!v.identifier.trim()) errors.identifier = 'Enter your email, username or mobile number.';
    if (!v.password) errors.password = 'Enter your password.';
    if (Object.keys(errors).length) return setErrors(form, errors);

    const btn = qs('button[type="submit"]', form);
    busy(btn, true);
    try {
      const user = await login(v.identifier.trim(), v.password);
      const first = String(user.full_name || '').split(' ')[0];
      toast('Signed in. Welcome back' + (first ? ', ' + first : '') + '.');
      ctx.navigate(safeNext(ctx.query.get('next')) || landingFor(user), { replace: true });
    } catch (err) {
      busy(btn, false);
      form.elements.password.value = '';
      formError(form, err);
      if (!err.errors) form.elements.password.focus();
    }
  });

  setTimeout(() => form.elements.identifier.focus(), 50);
}

// ─── Forgot password ────────────────────────────────────────────────────────

function viewForgot(root, ctx) {
  const form = qs('form', root);
  const prefill = ctx.query.get('email');
  if (prefill) form.elements.email.value = prefill;

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearErrors(form);
    const email = form.elements.email.value.trim();
    if (!EMAIL_RE.test(email)) return setErrors(form, { email: 'Enter a valid email address.' });

    const btn = qs('button[type="submit"]', form);
    busy(btn, true);
    try {
      await api.post('/auth/forgot-password', { email }, { auth: false });
      showStep(root, 'sent');
    } catch (err) {
      busy(btn, false);
      formError(form, err);
    }
  });

  setTimeout(() => form.elements.email.focus(), 50);
}

// ─── Reset password (from the emailed link) ─────────────────────────────────

async function viewReset(root, ctx) {
  const token = ctx.query.get('token') || '';
  if (!token) return showStep(root, 'invalid');

  try {
    const check = await api.get('/auth/reset-password', { token }, { auth: false, signal: ctx.signal });
    if (!check || !check.valid) return showStep(root, 'invalid');
  } catch {
    if (ctx.signal.aborted) return undefined;
    return showStep(root, 'invalid');
  }
  showStep(root, 'form');

  const form = qs('form', root);
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearErrors(form);
    const v = formValues(form);
    if (!v.password) return setErrors(form, { password: 'Choose a new password.' });
    if (v.password !== v.password_confirm) return setErrors(form, { password_confirm: 'Passwords do not match.' });

    const btn = qs('button[type="submit"]', form);
    busy(btn, true);
    try {
      await api.post('/auth/reset-password', { token, password: v.password, password_confirm: v.password_confirm }, { auth: false });
      /* Every session of that account was ended on the server; drop this
         device's copy too, and take the spent token out of the address bar. */
      await clearSession();
      history.replaceState(history.state, '', location.pathname);
      showStep(root, 'done');
    } catch (err) {
      busy(btn, false);
      if (err.status === 400) return showStep(root, 'invalid');
      formError(form, err);
    }
  });
  setTimeout(() => form.elements.password.focus(), 50);
  return undefined;
}

// ─── Confirm email ──────────────────────────────────────────────────────────

async function viewVerify(root, ctx) {
  const token = ctx.query.get('token') || '';
  const user = await currentUser();

  const wireResend = () => {
    qsa('[data-resend]', root).forEach((b) => {
      b.hidden = !(user && !user.is_email_verified);
      b.onclick = async () => {
        busy(b, true);
        try {
          const r = await api.post('/auth/verify-email/send');
          if (r && r.verified) { await updateUser({ is_email_verified: true }); toast('Your email address is already confirmed.'); }
          else toast('We sent a new confirmation link. Check your email.');
        } catch (err) {
          toast(err.message, { kind: 'error' });
        } finally { busy(b, false); }
      };
    });
    const signin = qs('[data-signin]', root);
    if (signin) {
      signin.hidden = !!user;
      signin.setAttribute('href', 'login?next=' + encodeURIComponent('/verify-email'));
    }
  };

  if (!token) { showStep(root, 'pending'); wireResend(); return; }

  try {
    await api.get('/auth/verify-email', { token }, { auth: false, signal: ctx.signal });
    if (user) await updateUser({ is_email_verified: true });
    history.replaceState(history.state, '', location.pathname);
    showStep(root, 'ok');
  } catch (err) {
    if (ctx.signal.aborted) return;
    qs('[data-failed-text]', root).textContent = err.message || 'This confirmation link is not valid or has already been used.';
    showStep(root, 'failed');
    wireResend();
  }
}
