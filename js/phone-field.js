/**
 * phone-field.js — an international mobile number field.
 *
 * GenericPOS · ES module. Used by sign-up, checkout, the account page and the
 * POS customer form.
 *
 * Upgrades a plain `<input name="phone">` (or any input with data-phone) into
 * a country selector plus a national-number box, and keeps the input's own
 * value as E.164 — so every form that reads `form.phone.value` keeps working.
 *
 * ─── WHY THE VALUE STAYS ON THE ORIGINAL INPUT ────────────────────────────
 * A visible box plus a hidden composed value is two places holding one phone
 * number, and the bug that invites is the pair drifting apart. Here the input
 * the form reads IS the input the user types in; the select only changes what
 * prefix gets written into it on blur and submit.
 *
 * The server normalises again on arrival — this is the courtesy, not the gate.
 */

import { COUNTRIES, DEFAULT_ISO, PRIMARY_FOR_DIAL, dialFor } from './countries.js';

/** Split a stored E.164 number back into a country and a national part.
    LONGEST dial code first: +1 must not swallow +263. */
function splitE164(value) {
  const raw = String(value || '').trim();
  if (!raw.startsWith('+')) return null;
  const digits = raw.slice(1).replace(/\D/g, '');
  if (!digits) return null;
  const byLength = [...COUNTRIES].sort((a, b) => b[2].length - a[2].length);
  const hit = byLength.find((c) => digits.startsWith(c[2]));
  if (!hit) return null;
  const dial = hit[2];
  return { iso: PRIMARY_FOR_DIAL[dial] || hit[0], national: digits.slice(dial.length) };
}

/** Upgrade every phone input inside `root`. Safe to call repeatedly. */
export function mountPhoneFields(root) {
  (root || document).querySelectorAll('input[name="phone"], input[data-phone]').forEach((input) => {
    if (input.dataset.phoneReady === '1') return;
    input.dataset.phoneReady = '1';

    const wrap = document.createElement('div');
    wrap.className = 'phone';
    input.parentNode.insertBefore(wrap, input);

    const select = document.createElement('select');
    select.className = 'select phone-cc';
    select.setAttribute('aria-label', 'Country calling code');
    /* The DIAL CODE leads — the closed control is narrow, and "+63 Philipp…"
       still answers what the field will prepend where "Philippines +…" does not. */
    select.innerHTML = COUNTRIES.map(([iso, name, dial]) => `<option value="${iso}">+${dial} ${name}</option>`).join('');

    wrap.appendChild(select);
    wrap.appendChild(input);
    input.classList.add('phone-num');
    input.type = 'tel';
    input.setAttribute('inputmode', 'tel');
    input.autocomplete = 'tel-national';

    let national = '';

    const readNational = () => {
      let d = input.value.replace(/\D/g, '');
      const dial = dialFor(select.value);
      if (input.value.trim().startsWith('+') && d.startsWith(dial)) d = d.slice(dial.length);
      return d.replace(/^0+/, '');        // a trunk prefix (0917…) is not part of E.164
    };

    const compose = () => {
      national = readNational();
      input.value = national === '' ? '' : '+' + dialFor(select.value) + national;
    };

    input.addEventListener('focus', () => { national = readNational(); input.value = national; });
    input.addEventListener('blur', compose);
    input.form?.addEventListener('submit', compose, true);

    select.addEventListener('change', () => { national = readNational(); input.value = national; input.focus(); });

    const seeded = splitE164(input.value);
    if (seeded) {
      select.value = seeded.iso;
      national = seeded.national;
    } else {
      select.value = DEFAULT_ISO;
      national = input.value.replace(/\D/g, '').replace(/^0+/, '');
    }
    input.value = national === '' ? '' : '+' + dialFor(select.value) + national;

    /* A later programmatic fill (the account page painting the saved number)
       does not fire 'input' — callers use this to re-split it. */
    input.gpPhoneSync = () => {
      const s = splitE164(input.value);
      if (s) { select.value = s.iso; national = s.national; }
    };
  });
}

/** The E.164 value of a phone input, composed on demand. */
export function phoneValue(input) {
  if (!input) return '';
  const v = String(input.value || '').trim();
  if (v === '') return '';
  if (v.startsWith('+')) return '+' + v.slice(1).replace(/\D/g, '');
  const select = input.closest('.phone')?.querySelector('.phone-cc');
  const dial = select ? dialFor(select.value) : '';
  return '+' + dial + v.replace(/\D/g, '').replace(/^0+/, '');
}
