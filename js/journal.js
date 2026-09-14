/**
 * journal.js — one journal entry: its lines, its history, and what can be done with it
 *
 * GenericPOS Accounting · ES module · every role (the actions depend on the role)
 *
 * The server says which actions are open to this person right now (`can`),
 * so the buttons here never offer what would be refused. Posting, rejecting,
 * cancelling and reversing each ask first. A posted entry is never edited: it
 * is reversed, and both entries stay on record, linked to each other.
 */

import { api } from './api.js';
import { money, fmtDay, fmtDate, todayYmd } from './store.js';
import { qs, esc, emptyState, statusBadge, confirmDialog, promptDialog, openModal, toast, busy, formError, clearErrors } from './ui.js';
import { setAdminTitle } from './chrome.js';

const ACTIONS = {
  'journal.create': 'Prepared',
  'journal.update': 'Changed',
  'journal.submit': 'Submitted for approval',
  'journal.reject': 'Rejected',
  'journal.cancel': 'Cancelled',
  'journal.post': 'Approved and posted',
  'journal.reverse': 'Reversed',
};

export async function mount(root, ctx) {
  const id = parseInt(ctx.params.id, 10) || 0;
  const stateEl = qs('[data-state]', root);
  const body = qs('[data-body]', root);
  const actions = qs('[data-actions]', root);
  let d = null;

  const alertBox = (kind, html) => {
    const b = qs('[data-banner]', root);
    if (!html) { b.hidden = true; return; }
    b.className = 'alert alert-' + kind;
    b.innerHTML = html;
    b.hidden = false;
  };
  const link = (x) => '<a href="journals/' + x.id + '"><b class="mono">' + esc(x.journal_no || '#' + x.id) + '</b></a>';

  const paint = () => {
    const j = d.journal;
    const title = j.journal_no || (j.status === 'cancelled' ? 'Cancelled entry #' + j.id : 'Entry #' + j.id);
    qs('[data-title]', root).innerHTML = '<span>' + esc(title) + '</span>' + statusBadge(j.status);
    qs('[data-crumb]', root).textContent = title;
    ctx.setTitle(title);
    setAdminTitle(title);
    qs('[data-sub]', root).textContent = j.book_label + ' · ' + fmtDay(j.entry_date, 'long') + (d.period ? ' · ' + d.period.name : '');

    // ── where it stands ─────────────────────────────────────────────────
    if (j.status === 'rejected') {
      alertBox('err', '<span data-icon="x-circle"></span><div>Returned by <b>' + esc(j.rejected_by_name || 'the approver') + '</b> on '
        + esc(fmtDate(j.rejected_at, 'date')) + ': ' + esc(j.reject_reason || '') + '</div>');
    } else if (j.status === 'submitted') {
      alertBox('warn', '<span data-icon="hourglass"></span><div>Waiting for approval since ' + esc(fmtDate(j.submitted_at)) + '. It reaches the ledger when an accountant approves it.</div>');
    } else if (j.status === 'draft') {
      alertBox('neutral', '<span data-icon="pencil-simple"></span><div>A draft. It reaches the ledger only after it is submitted and approved.</div>');
    } else if (j.status === 'cancelled') {
      alertBox('neutral', '<span data-icon="prohibit"></span><div>Cancelled by ' + esc(j.cancelled_by_name || '') + ' on ' + esc(fmtDate(j.cancelled_at, 'date')) + '. It never reached the ledger.</div>');
    } else if (d.reversed_by) {
      alertBox('info', '<span data-icon="arrow-u-up-left"></span><div>Reversed by ' + link(d.reversed_by) + ', dated ' + esc(fmtDay(d.reversed_by.entry_date)) + '. Together they cancel out in the ledger.</div>');
    } else if (d.reversal_of) {
      alertBox('info', '<span data-icon="arrow-u-up-left"></span><div>This entry reverses ' + link(d.reversal_of) + '.</div>');
    } else if (j.source !== 'manual') {
      alertBox('info', '<span data-icon="info"></span><div>Posted from ' + esc(j.source_label) + '. It is undone from there, so the document and the ledger stay in step.</div>');
    } else {
      alertBox('', '');
    }

    // ── the header ──────────────────────────────────────────────────────
    const fact = (k, v, wide) => '<div' + (wide ? ' class="wide"' : '') + '><div class="k">' + esc(k) + '</div><div class="v">' + (v || '<span class="faint">—</span>') + '</div></div>';
    qs('[data-head]', root).innerHTML =
        fact('Number', j.journal_no ? '<span class="mono">' + esc(j.journal_no) + '</span>' : '<span class="faint">Given when it posts</span>')
      + fact('Date', esc(fmtDay(j.entry_date, 'long')))
      + fact('Book', esc(j.book_label))
      + fact('Period', d.period ? esc(d.period.name) + ' <span class="small muted">(' + esc(d.period.status) + ')</span>' : '')
      + fact('Reference', esc(j.reference || ''))
      + fact('Paid to / received from', esc(j.party_name || ''))
      + fact('Prepared by', esc(j.created_by_name || '') + ' <span class="small muted">' + esc(fmtDate(j.created_at, 'short')) + '</span>')
      + fact('Approved by', j.approved_by_name ? esc(j.approved_by_name) + ' <span class="small muted">' + esc(fmtDate(j.approved_at, 'short')) + '</span>' : '')
      + fact('Amount', '<span class="num">' + money(j.total_cents) + '</span>')
      + fact('Description', esc(j.description), true);

    // ── the lines ───────────────────────────────────────────────────────
    let dr = 0;
    let cr = 0;
    qs('[data-lines]', root).innerHTML = d.lines.map((l) => {
      dr += l.debit_cents;
      cr += l.credit_cents;
      return '<tr><td><span class="code">' + esc(l.account_code) + '</span> ' + esc(l.account_name) + '</td>'
        + '<td class="small">' + esc(l.memo || '') + '</td>'
        + '<td class="small">' + esc(l.department || '') + '</td>'
        + '<td class="small">' + esc(l.contact || '') + '</td>'
        + '<td class="num">' + (l.debit_cents ? money(l.debit_cents) : '') + '</td>'
        + '<td class="num">' + (l.credit_cents ? money(l.credit_cents) : '') + '</td></tr>';
    }).join('');
    qs('[data-totals]', root).innerHTML = '<tr class="totals"><td colspan="4" class="right muted">Totals</td><td class="num">' + money(dr) + '</td><td class="num">' + money(cr) + '</td></tr>';
    qs('[data-line-count]', root).textContent = d.lines.length + ' line' + (d.lines.length === 1 ? '' : 's');

    // ── the history ─────────────────────────────────────────────────────
    qs('[data-trail]', root).innerHTML = d.trail.length ? d.trail.map((t) => {
      const x = t.detail || {};
      return '<li class="is-done"><div class="tl-title">' + esc(ACTIONS[t.action] || t.action) + (x.journal_no && t.action === 'journal.post' ? ' as ' + esc(x.journal_no) : '') + '</div>'
        + '<div class="tl-time">' + esc(t.who) + ' · ' + esc(fmtDate(t.at)) + '</div>'
        + (x.reason ? '<div class="small">“' + esc(x.reason) + '”</div>' : '')
        + (x.reversal_no ? '<div class="small">By ' + esc(x.reversal_no) + '</div>' : '') + '</li>';
    }).join('') : '<li class="muted">Nothing recorded.</li>';

    // ── what this person may do ─────────────────────────────────────────
    const c = d.can;
    const btn = (act, label, icon, cls) => '<button class="btn ' + cls + '" type="button" data-do="' + act + '"><span data-icon="' + icon + '" data-icon-size="18"></span>' + label + '</button>';
    actions.innerHTML = [
      c.copy ? '<a class="btn btn-ghost" href="journals/new?copy=' + j.id + '"><span data-icon="copy" data-icon-size="18"></span>Copy</a>' : '',
      c.cancel ? btn('cancel', 'Cancel entry', 'prohibit', 'btn-ghost') : '',
      c.edit ? '<a class="btn btn-secondary" href="journals/' + j.id + '/edit"><span data-icon="pencil-simple" data-icon-size="18"></span>Edit</a>' : '',
      c.reverse ? btn('reverse', 'Reverse', 'arrow-u-up-left', 'btn-secondary') : '',
      c.reject ? btn('reject', 'Reject', 'x-circle', 'btn-secondary') : '',
      c.submit ? btn('submit', 'Submit for approval', 'paper-plane-tilt', '') : '',
      c.approve ? btn('approve', 'Approve and post', 'check-circle', '') : '',
    ].join('');
  };

  const reverse = () => {
    const j = d.journal;
    const today = todayYmd();
    const m = openModal({
      title: 'Reverse ' + j.journal_no, size: 'sm',
      body: '<form class="stack" data-f novalidate><div class="alert alert-err" data-banner hidden></div>'
        + '<p class="muted mb-0">A new entry with every debit and credit swapped cancels this one out in the ledger. Both stay on record, linked to each other.</p>'
        + '<div class="field"><label class="label" for="rv-date">Date of the reversal</label><input class="input" type="date" id="rv-date" name="date" value="' + (today < j.entry_date ? j.entry_date : today) + '" min="' + j.entry_date + '">'
        + '<div class="hint">On or after ' + esc(fmtDay(j.entry_date)) + ', in an open month.</div><div class="error" data-error-for="date"></div></div>'
        + '<div class="field"><label class="label" for="rv-reason">Why</label><textarea class="textarea" id="rv-reason" name="reason" maxlength="300" placeholder="What was wrong with it"></textarea><div class="error" data-error-for="reason"></div></div>'
        + '<div class="row gap-2" style="justify-content:flex-end"><button class="btn btn-secondary" type="button" data-x>Keep it</button><button class="btn btn-danger" type="submit">Reverse it</button></div></form>',
    });
    const f = qs('[data-f]', m.el);
    qs('[data-x]', f).addEventListener('click', () => m.close());
    f.addEventListener('submit', async (e) => {
      e.preventDefault();
      clearErrors(f);
      const b = qs('button[type="submit"]', f);
      busy(b, true);
      try {
        const r = await api.post('/journals/' + j.id + '/reverse', { date: f.elements.date.value, reason: f.elements.reason.value.trim() });
        m.close();
        toast('Reversed by ' + r.journal.journal_no + '.');
        window.dispatchEvent(new CustomEvent('gp:badges'));
        ctx.navigate('/journals/' + r.reversal_id);
      } catch (err) { formError(f, err); } finally { busy(b, false); }
    });
  };

  actions.addEventListener('click', async (e) => {
    const b = e.target.closest('[data-do]');
    if (!b || !d) return;
    const j = d.journal;
    const who = j.created_by_name || 'its preparer';
    try {
      switch (b.dataset.do) {
        case 'submit':
          busy(b, true);
          d = await api.post('/journals/' + j.id + '/submit');
          toast('Submitted for approval.');
          break;
        case 'approve':
          if (!(await confirmDialog({ title: 'Approve and post this entry?', body: 'It takes its number and reaches the ledger. After that it can only be undone by reversing it.', confirmLabel: 'Approve and post' }))) return;
          busy(b, true);
          d = await api.post('/journals/' + j.id + '/approve');
          toast('Posted as ' + d.journal.journal_no + '.');
          break;
        case 'reject': {
          const reason = await promptDialog({ title: 'Reject this entry', label: 'Tell ' + who + ' what to fix', multiline: true, maxlength: 300, required: true, confirmLabel: 'Reject', danger: true });
          if (reason === null) return;
          busy(b, true);
          d = await api.post('/journals/' + j.id + '/reject', { reason });
          toast('Returned to ' + who + '.');
          break;
        }
        case 'cancel':
          if (!(await confirmDialog({ title: 'Cancel this entry?', body: 'It stays on record as cancelled and never reaches the ledger. This cannot be undone.', confirmLabel: 'Cancel entry', cancelLabel: 'Keep it', danger: true }))) return;
          busy(b, true);
          d = await api.post('/journals/' + j.id + '/cancel');
          toast('Cancelled.');
          break;
        case 'reverse':
          reverse();
          return;
        default:
          return;
      }
      paint();
      window.dispatchEvent(new CustomEvent('gp:badges'));
    } catch (err) {
      toast(err.message, { kind: 'error' });
    } finally {
      busy(b, false);
    }
  });

  try {
    d = await api.get('/journals/' + id, null, { signal: ctx.signal });
  } catch (err) {
    if (ctx.signal.aborted) return;
    stateEl.innerHTML = emptyState(err.isNotFound ? 'magnifying-glass' : 'warning', err.isNotFound ? 'That entry does not exist' : 'Could not load the entry', err.isNotFound ? '' : err.message,
      '<a class="btn btn-secondary" href="journals">Back to the entries</a>');
    return;
  }
  stateEl.innerHTML = '';
  body.hidden = false;
  paint();
}
