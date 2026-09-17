/**
 * ui.js — small DOM toolkit shared by every screen
 *
 * GenericPOS · ES module
 *
 *   esc(), h(), qs(), qsa()            safe markup and element building
 *   toast()                            brief confirmations anchored to the viewport
 *   confirmDialog(), openModal()       <dialog>-based, focus-trapped, Escape closes
 *   formValues(), setErrors(), clearErrors(), banner(), busy()
 *   pager(), statusBadge(), emptyState(), skeletonRows()
 *   copyText(), debounce()
 *
 * Reports print through the browser's own print dialog; css/app.css lays the
 * report sheet out on A4 and hides everything else.
 *
 * NOTE: esc() is the rule, not a courtesy. Anything a person typed — product
 * names, addresses, notes — reaches the DOM either through textContent or
 * through esc(). innerHTML with an unescaped value is how a customer's name
 * becomes a script on the cashier's screen.
 */

// ─── Markup ─────────────────────────────────────────────────────────────────

export function esc(v) {
  return String(v == null ? '' : v)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
}

export const qs = (sel, root = document) => root.querySelector(sel);
export const qsa = (sel, root = document) => Array.from(root.querySelectorAll(sel));

/**
 * h('button', { class: 'btn', onclick: fn, disabled: true }, 'Save', childNode)
 * Strings become text nodes — never parsed as markup.
 */
export function h(tag, attrs, ...children) {
  const el = document.createElement(tag);
  if (attrs) {
    Object.keys(attrs).forEach((k) => {
      const v = attrs[k];
      if (v === undefined || v === null || v === false) return;
      if (k === 'class') el.className = v;
      else if (k === 'text') el.textContent = v;
      else if (k === 'html') el.innerHTML = v;          // caller vouches for it
      else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
      else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2), v);
      else if (k === 'dataset') Object.assign(el.dataset, v);
      else el.setAttribute(k, v === true ? '' : String(v));
    });
  }
  children.flat().forEach((c) => {
    if (c === null || c === undefined || c === false) return;
    el.appendChild(c instanceof Node ? c : document.createTextNode(String(c)));
  });
  return el;
}

export function icon(name, size = 18) {
  return '<span data-icon="' + esc(name) + '" data-icon-size="' + size + '"></span>';
}

export function debounce(fn, ms = 250) {
  let t = null;
  return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
}

// ─── Toasts ─────────────────────────────────────────────────────────────────

export function toast(message, o = {}) {
  const text = String(message ?? '').trim();
  if (!text) return () => {};
  const kind = o.kind || 'ok';
  let box = document.getElementById('gp-toasts');
  if (!box) {
    box = h('div', { id: 'gp-toasts', class: 'toasts', role: 'status', 'aria-live': 'polite' });
    document.body.appendChild(box);
  }
  while (box.children.length >= 3) box.firstElementChild.remove();

  const el = h('div', { class: 'toast toast-' + kind });
  el.innerHTML = icon(o.icon || (kind === 'error' ? 'warning' : kind === 'info' ? 'info' : 'check-circle'), 18);
  el.appendChild(h('span', { text }));
  box.appendChild(el);

  let timer = null;
  const close = () => {
    if (!el.isConnected) return;
    clearTimeout(timer);
    el.classList.add('is-out');
    setTimeout(() => el.remove(), 260);
  };
  el.addEventListener('click', close);
  el.addEventListener('mouseenter', () => clearTimeout(timer));
  el.addEventListener('mouseleave', () => { timer = setTimeout(close, 1600); });
  timer = setTimeout(close, o.ms || (kind === 'error' ? 7000 : 4200));
  return close;
}

export function toastError(err, fallback = 'Something went wrong. Please try again.') {
  const msg = err && err.message ? err.message : fallback;
  toast(msg, { kind: 'error' });
}

// ─── Dialogs ────────────────────────────────────────────────────────────────

/**
 * A modal built on <dialog>: inert background, focus trapped, Escape closes.
 *
 * @param {object} o
 *   title, body (Node | html string — caller escapes), size ('sm'|'lg'|'xl'),
 *   actions: [{ label, kind:'primary'|'secondary'|'danger'|'ghost', onClick(ctl), close:true }],
 *   onClose(), dismissible (default true), drawer (bool)
 * @returns {{ el, body, close, setBusy }}
 */
export function openModal(o = {}) {
  const dlg = document.createElement('dialog');
  dlg.className = (o.drawer ? 'drawer' : 'modal') + (o.size ? ' modal-' + o.size : '');

  const box = h('div', { class: 'modal-box' });
  if (o.title !== undefined) {
    const head = h('div', { class: 'modal-head' }, h('h3', { text: o.title || '' }));
    if (o.dismissible !== false) {
      const x = h('button', { class: 'modal-x', type: 'button', 'aria-label': 'Close', html: icon('x', 18) });
      x.addEventListener('click', () => ctl.close());
      head.appendChild(x);
    }
    box.appendChild(head);
  }
  const body = h('div', { class: 'modal-body' });
  if (o.body instanceof Node) body.appendChild(o.body);
  else if (o.body) body.innerHTML = o.body;
  box.appendChild(body);

  let foot = null;
  if (o.actions && o.actions.length) {
    foot = h('div', { class: 'modal-foot' });
    o.actions.forEach((a) => {
      const cls = a.kind === 'secondary' ? 'btn btn-secondary' : a.kind === 'danger' ? 'btn btn-danger'
        : a.kind === 'ghost' ? 'btn btn-ghost' : 'btn';
      const b = h('button', { class: cls, type: 'button', text: a.label });
      b.addEventListener('click', async () => {
        if (a.onClick) {
          const r = await a.onClick(ctl, b);
          if (r === false) return;
        }
        if (a.close !== false) ctl.close();
      });
      foot.appendChild(b);
    });
    box.appendChild(foot);
  }
  dlg.appendChild(box);
  document.body.appendChild(dlg);

  let closed = false;
  const ctl = {
    el: dlg, body, foot,
    close() {
      if (closed) return;
      closed = true;
      try { dlg.close(); } catch { /* already closed */ }
      dlg.remove();
      if (o.onClose) o.onClose();
    },
    setBusy(on) { qsa('button', foot || dlg).forEach((b) => { b.disabled = !!on; }); },
  };

  dlg.addEventListener('cancel', (e) => { e.preventDefault(); if (o.dismissible !== false) ctl.close(); });
  dlg.addEventListener('click', (e) => { if (e.target === dlg && o.dismissible !== false) ctl.close(); });

  try { dlg.showModal(); } catch { dlg.setAttribute('open', ''); }
  const first = qs('[autofocus]', dlg) || qs('input, select, textarea', body);
  if (first) setTimeout(() => first.focus(), 30);
  return ctl;
}

/**
 * Ask before something irreversible. Resolves TRUE only on the explicit
 * confirm button — Escape, the backdrop and every error resolve FALSE, and
 * the CANCEL button takes focus, so an extra Enter lands on "no".
 */
export function confirmDialog(o = {}) {
  return new Promise((resolve) => {
    let done = false;
    const finish = (v) => { if (!done) { done = true; resolve(v); } };
    const ctl = openModal({
      title: o.title || 'Are you sure?',
      body: o.bodyHtml || (o.body ? '<p>' + esc(o.body) + '</p>' : ''),
      size: 'sm',
      onClose: () => finish(false),
      actions: [
        { label: o.cancelLabel || 'Cancel', kind: 'secondary', onClick: () => { finish(false); } },
        { label: o.confirmLabel || 'Confirm', kind: o.danger ? 'danger' : 'primary', onClick: () => { finish(true); } },
      ],
    });
    const cancel = ctl.foot && ctl.foot.firstElementChild;
    if (cancel) setTimeout(() => cancel.focus(), 40);
  });
}

/** A prompt for one value (reason, reference number…). Resolves the text or null. */
export function promptDialog(o = {}) {
  return new Promise((resolve) => {
    let done = false;
    const finish = (v) => { if (!done) { done = true; resolve(v); } };
    const input = o.multiline
      ? h('textarea', { class: 'textarea', placeholder: o.placeholder || '', maxlength: o.maxlength || 500 })
      : h('input', { class: 'input', type: o.type || 'text', placeholder: o.placeholder || '', maxlength: o.maxlength || 200, inputmode: o.inputmode || null });
    if (o.value) input.value = o.value;
    const wrap = h('div', { class: 'field' },
      o.label ? h('label', { class: 'label', text: o.label }) : null, input,
      o.hint ? h('div', { class: 'hint', text: o.hint }) : null);
    const ctl = openModal({
      title: o.title || '', body: wrap, size: 'sm',
      onClose: () => finish(null),
      actions: [
        { label: 'Cancel', kind: 'secondary', onClick: () => finish(null) },
        { label: o.confirmLabel || 'OK', kind: o.danger ? 'danger' : 'primary', onClick: () => {
          const v = input.value.trim();
          if (o.required && !v) { input.focus(); return false; }
          finish(v);
        } },
      ],
    });
    input.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' && !o.multiline) { e.preventDefault(); ctl.foot.lastElementChild.click(); }
    });
  });
}

// ─── Forms ──────────────────────────────────────────────────────────────────

/** Plain object of a form's named controls; checkboxes become booleans. */
export function formValues(form) {
  const out = {};
  qsa('input, select, textarea', form).forEach((el) => {
    if (!el.name || el.disabled) return;
    if (el.type === 'checkbox') out[el.name] = el.checked;
    else if (el.type === 'radio') { if (el.checked) out[el.name] = el.value; }
    else out[el.name] = el.value;
  });
  return out;
}

/** Paint a 422's { field: message } map next to the matching fields. */
export function setErrors(form, errors) {
  clearErrors(form);
  if (!errors) return;
  let first = null;
  Object.keys(errors).forEach((name) => {
    const slot = qs('[data-error-for="' + name + '"]', form);
    const ctrl = form.elements ? form.elements[name] : null;
    const field = (ctrl && ctrl.closest && ctrl.closest('.field')) || (slot && slot.closest('.field'));
    if (slot) slot.textContent = errors[name];
    if (field) field.classList.add('has-error');
    if (!first && ctrl && ctrl.focus) first = ctrl;
    if (!slot && name !== '_') {
      const b = qs('[data-banner]', form);
      if (b) banner(b, errors[name], 'err');
    }
  });
  if (errors._) { const b = qs('[data-banner]', form); if (b) banner(b, errors._, 'err'); }
  if (first) try { first.focus(); } catch { /* not focusable */ }
}

export function clearErrors(form) {
  qsa('[data-error-for]', form).forEach((e) => { e.textContent = ''; });
  qsa('.field.has-error', form).forEach((f) => f.classList.remove('has-error'));
  const b = qs('[data-banner]', form);
  if (b) banner(b, '');
}

/** Write (or clear) an inline alert. kind: ok | err | warn | info | neutral */
export function banner(el, message, kind = 'err') {
  if (!el) return;
  el.className = 'alert alert-' + (kind === 'error' ? 'err' : kind);
  el.textContent = message || '';
  el.hidden = !message;
}

/** Handle an ApiError from a form submit: field errors inline, the rest in the banner. */
export function formError(form, err) {
  if (err && err.errors) { setErrors(form, err.errors); return; }
  const b = qs('[data-banner]', form);
  if (b) banner(b, (err && err.message) || 'Something went wrong. Please try again.', 'err');
  else toastError(err);
}

export function busy(btn, on) {
  if (!btn) return;
  btn.classList.toggle('is-busy', !!on);
  btn.disabled = !!on;
}

// ─── Pieces ─────────────────────────────────────────────────────────────────

export function emptyState(iconName, title, text, actionHtml = '') {
  return '<div class="empty">' + icon(iconName, 40) + '<h3>' + esc(title) + '</h3>'
    + (text ? '<p>' + esc(text) + '</p>' : '') + (actionHtml || '') + '</div>';
}

export function skeletonRows(n = 4) {
  let s = '';
  for (let i = 0; i < n; i++) s += '<div class="skel skel-line" style="width:' + (60 + ((i * 17) % 35)) + '%"></div>';
  return '<div style="padding:16px 20px">' + s + '</div>';
}

export function loadingBlock() {
  return '<div class="loading-block"><div class="spinner"></div></div>';
}

/**
 * A pager under a list. meta = { page, pages, total }.
 * onPage(n) is called with the page wanted.
 */
export function pager(el, meta, onPage) {
  if (!el) return;
  const page = meta.page || 1;
  const pages = meta.pages || Math.max(1, Math.ceil((meta.total || 0) / (meta.limit || 20)));
  el.innerHTML = '';
  if (pages <= 1 && !(meta.total > 0)) { el.hidden = true; return; }
  el.hidden = false;
  el.className = 'pager';
  el.appendChild(h('span', { text: (meta.total || 0) + ' total · page ' + page + ' of ' + pages }));
  const btns = h('div', { class: 'row gap-2' });
  const prev = h('button', { class: 'btn btn-secondary btn-sm', type: 'button', disabled: page <= 1, text: 'Previous' });
  const next = h('button', { class: 'btn btn-secondary btn-sm', type: 'button', disabled: page >= pages, text: 'Next' });
  prev.addEventListener('click', () => onPage(page - 1));
  next.addEventListener('click', () => onPage(page + 1));
  btns.append(prev, next);
  el.appendChild(btns);
}

const STATUS = {
  // order status
  pending: ['Pending', 'warn'], processing: ['Processing', 'info'], ready: ['Ready for pickup', 'brand'],
  shipped: ['Out for delivery', 'info'], completed: ['Completed', 'ok'], cancelled: ['Cancelled', 'neutral'],
  // payment status
  unpaid: ['Unpaid', 'warn'], partial: ['Part paid', 'warn'], paid: ['Paid', 'ok'], overpaid: ['Overpaid', 'info'],
  partially_refunded: ['Part refunded', 'info'], refunded: ['Refunded', 'neutral'],
  expired: ['Expired', 'neutral'], voided: ['Voided', 'neutral'],
  // misc
  active: ['Active', 'ok'], draft: ['Draft', 'neutral'], archived: ['Archived', 'neutral'],
  suspended: ['Suspended', 'err'], closed: ['Closed', 'neutral'], open: ['Open', 'ok'],
  queued: ['Queued', 'info'], sending: ['Sending', 'warn'], sent: ['Sent', 'info'], failed: ['Failed', 'err'],
  rejected: ['Rejected', 'err'], pending_confirm: ['Awaiting confirmation', 'warn'],
  // journals and periods
  submitted: ['Waiting for approval', 'warn'], posted: ['Posted', 'ok'], locked: ['Locked', 'neutral'],
  parked: ['Parked', 'warn'], credited: ['Credited', 'ok'], applied: ['Applied', 'ok'],
  // the modules: banking, fixed assets, budgets, receivables and payables
  reconciled: ['Reconciled', 'ok'], unmatched: ['Unmatched', 'warn'], matched: ['Matched', 'ok'], ignored: ['Ignored', 'neutral'],
  disposed: ['Disposed', 'neutral'], fully_depreciated: ['Fully depreciated', 'info'], approved: ['Approved', 'ok'],
  overdue: ['Overdue', 'err'], due: ['Due', 'warn'], not_due: ['Not yet due', 'neutral'], inactive: ['Inactive', 'neutral'],
};

export function statusBadge(status, labelOverride) {
  const s = STATUS[status] || [String(status || '').replace(/_/g, ' '), 'neutral'];
  const cls = s[1] === 'neutral' ? 'badge' : 'badge badge-' + s[1];
  return '<span class="' + cls + '">' + esc(labelOverride || s[0]) + '</span>';
}

export async function copyText(text, label = 'Copied') {
  try {
    await navigator.clipboard.writeText(String(text));
    toast(label);
    return true;
  } catch {
    const ta = h('textarea', { style: { position: 'fixed', opacity: '0' } });
    ta.value = String(text);
    document.body.appendChild(ta);
    ta.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch { ok = false; }
    ta.remove();
    toast(ok ? label : 'Could not copy — select the text and copy it manually.', { kind: ok ? 'ok' : 'error' });
    return ok;
  }
}

/** "Page Title · Store Name" */
export function setTitle(title, storeName) {
  document.title = title ? title + (storeName ? ' · ' + storeName : '') : (storeName || document.title);
}

/**
 * Store-written text → safe HTML. Everything is escaped FIRST; then a small
 * set of marks is recognised: blank-line paragraphs, "# " / "## " headings,
 * "- " bullet lists and **bold**. Enough for terms and shipping pages without
 * letting a settings field inject markup.
 */
export function richText(text) {
  const inline = (s) => esc(s).replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
  const blocks = String(text || '').replace(/\r\n?/g, '\n').split(/\n{2,}/);
  return blocks.map((b) => {
    const lines = b.split('\n').filter((l) => l.trim() !== '');
    if (!lines.length) return '';
    if (lines.every((l) => /^\s*[-*]\s+/.test(l))) {
      return '<ul>' + lines.map((l) => '<li>' + inline(l.replace(/^\s*[-*]\s+/, '')) + '</li>').join('') + '</ul>';
    }
    const m = lines[0].match(/^(#{1,2})\s+(.*)$/);
    if (m) {
      const tag = m[1].length === 1 ? 'h2' : 'h3';
      const rest = lines.slice(1);
      return '<' + tag + '>' + inline(m[2]) + '</' + tag + '>' + (rest.length ? '<p>' + rest.map(inline).join('<br>') + '</p>' : '');
    }
    return '<p>' + lines.map(inline).join('<br>') + '</p>';
  }).join('');
}
