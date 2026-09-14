/**
 * setup.js — first run: the company, its chart, its first fiscal year, and
 * the first administrator
 *
 * GenericPOS Accounting · ES module. Talks to GET|POST /api/v1/setup. The
 * server refuses once any administrator exists and always requires
 * GP_SETUP_KEY; this page is only the form.
 */

import { api, setSession } from './api.js';
import { loadStore, fmtDay } from './store.js';
import { qs, qsa, esc, formValues, formError, setErrors, clearErrors, busy, toast } from './ui.js';

const MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const KIND_HINT = {
  business_corporation: 'Capital stock and retained earnings.',
  business_sole_proprietorship: 'The owner\'s capital and drawings.',
  cooperative: 'Laid out after the CDA standard chart, with share capital and the statutory funds. Compare it with the edition you report under.',
};
const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
const pad = (n) => String(n).padStart(2, '0');

function showStep(root, name) {
  qsa('[data-step]', root).forEach((el) => { el.hidden = el.getAttribute('data-step') !== name; });
}

export async function mount(root, ctx) {
  const form = qs('form', root);
  const f = form.elements;

  qsa('[data-pw-toggle]', root).forEach((btn) => {
    const input = btn.parentElement.querySelector('input');
    btn.addEventListener('click', () => {
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      const ic = btn.querySelector('[data-icon]');
      if (ic) ic.setAttribute('data-icon', show ? 'eye-slash' : 'eye');
    });
  });

  f.fy_month.innerHTML = MONTHS.map((m, i) => '<option value="' + (i + 1) + '">' + m + '</option>').join('');

  /* Named and dated the way the server will create it. */
  const fyHint = () => {
    const m = parseInt(f.fy_month.value, 10);
    const y = parseInt(f.fy_year.value, 10);
    const el = qs('[data-fy-hint]', root);
    if (!(m >= 1 && m <= 12) || !(y >= 2000 && y <= 2100)) { el.textContent = ''; return; }
    const endY = m === 1 ? y : y + 1;
    const endM = m === 1 ? 12 : m - 1;
    const endD = new Date(Date.UTC(endY, endM, 0)).getUTCDate();
    const name = y === endY ? 'FY' + y : 'FY' + y + '-' + String(endY).slice(2);
    el.textContent = name + ' runs from ' + fmtDay(y + '-' + pad(m) + '-01', 'long') + ' to ' + fmtDay(endY + '-' + pad(endM) + '-' + pad(endD), 'long')
      + '. Its twelve months open for entries at once, and each later year follows on from it.';
  };
  f.fy_month.addEventListener('change', fyHint);
  f.fy_year.addEventListener('input', fyHint);

  const check = async () => {
    showStep(root, 'checking');
    try {
      const st = await api.get('/setup', null, { auth: false, signal: ctx.signal });
      if (!st.needed) return showStep(root, 'done');
      if (!st.enabled) return showStep(root, 'disabled');
      const kinds = st.kinds || {};
      qs('[data-kinds]', root).innerHTML = Object.keys(kinds).map((k, i) => '<label class="check"><input type="radio" name="kind" value="' + esc(k) + '"'
        + (i === 0 ? ' checked' : '') + '> <span><b>' + esc(kinds[k]) + '</b><span class="small muted" style="display:block">' + esc(KIND_HINT[k] || '') + '</span></span></label>').join('');
      f.fy_month.value = String(st.start_month || 1);
      f.fy_year.value = String(st.today || '').slice(0, 4) || String(new Date().getFullYear());
      f.currency_code.value = st.currency_code || 'PHP';
      f.currency_symbol.value = st.currency_symbol || '₱';
      fyHint();
      showStep(root, 'form');
      setTimeout(() => f.company_name.focus(), 50);
    } catch (err) {
      if (ctx.signal.aborted) return;
      showStep(root, 'disabled');
      toast(err.message, { kind: 'error' });
    }
  };

  qs('[data-recheck]', root).addEventListener('click', check);

  api.get('/auth', null, { auth: false, signal: ctx.signal }).then((c) => {
    const n = (c && c.password_min_length) || 8;
    qs('[data-pw-hint]', root).textContent = 'Use at least ' + n + ' characters. This account can change every setting, so make it strong.';
  }).catch(() => {});

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearErrors(form);
    const v = formValues(form);
    const m = parseInt(v.fy_month, 10);
    const y = parseInt(v.fy_year, 10);
    const errors = {};
    if (!v.company_name.trim()) errors.company_name = 'Enter the company name.';
    if (!v.kind) errors.kind = 'Choose the kind of organisation.';
    if (!(m >= 1 && m <= 12) || !(y >= 2000 && y <= 2100)) errors.fy_start = 'Choose the month and year the first fiscal year starts.';
    if (!v.full_name.trim()) errors.full_name = 'Enter your name.';
    if (!EMAIL_RE.test(v.email.trim())) errors.email = 'Enter a valid email address.';
    if (!v.password) errors.password = 'Choose a password.';
    if (v.password !== v.password_confirm) errors.password_confirm = 'Passwords do not match.';
    if (!v.setup_key.trim()) errors.setup_key = 'Enter the setup key from the server.';
    if (Object.keys(errors).length) return setErrors(form, errors);

    const btn = qs('button[type="submit"]', form);
    busy(btn, true);
    try {
      const d = await api.post('/setup', {
        setup_key: v.setup_key.trim(),
        company_name: v.company_name.trim(),
        kind: v.kind,
        fy_start: y + '-' + pad(m) + '-01',
        currency_code: v.currency_code.trim().toUpperCase(),
        currency_symbol: v.currency_symbol.trim(),
        admin: { full_name: v.full_name.trim(), email: v.email.trim(), password: v.password, password_confirm: v.password_confirm },
      }, { auth: false });
      await setSession(d);
      await loadStore(true);
      toast('The books are ready. Welcome!');
      ctx.navigate('/', { replace: true });
    } catch (err) {
      busy(btn, false);
      if (err.status === 409) return showStep(root, 'done');
      formError(form, err);
    }
    return undefined;
  });

  await check();
}
