/**
 * notifications.js — what the ledger has told you
 *
 * GenericPOS Accounting · ES module · every signed-in role
 *
 *   GET  /notifications          newest first, with the unread count
 *   POST /notifications/read     { id } one, or { all: true }
 *
 * Entries waiting for your approval, entries sent back to you with a reason,
 * and your entries once they are posted. Each notice opens its entry, and
 * opening it marks it read; the bell in the header follows along.
 */

import { api } from './api.js';
import { timeAgo, fmtDate } from './store.js';
import { qs, esc, emptyState, pager, busy, toast } from './ui.js';

const ICON = { 'journal.submitted': 'hourglass', 'journal.rejected': 'arrow-u-up-left', 'journal.approved': 'check-circle', 'journal.posted': 'check-circle' };
const badges = () => window.dispatchEvent(new CustomEvent('gp:badges'));

export async function mount(root, ctx) {
  const list = qs('[data-list]', root);
  const sub = qs('[data-sub]', root);
  const readAll = qs('[data-read-all]', root);
  let page = 1;

  const item = (n) => {
    const unread = !n.is_read;
    const href = String(n.url || '').replace(/^\/+/, '') || 'notifications';
    return '<a class="list-item' + (unread ? ' is-unread' : '') + '" href="' + esc(href) + '" data-id="' + n.id + '"' + (unread ? ' data-unread' : '') + '>'
      + '<span data-icon="' + (ICON[n.type] || 'bell') + '" data-icon-size="20" class="' + (unread ? 'brand-text' : 'faint') + '"></span>'
      + '<div class="grow" style="min-width:0"><div>' + (unread ? '<b>' + esc(n.title) + '</b>' : esc(n.title)) + '</div>'
      + (n.body ? '<div class="small muted">' + esc(n.body) + '</div>' : '')
      + '<div class="small faint" title="' + esc(fmtDate(n.created_at)) + '">' + esc(timeAgo(n.created_at)) + '</div></div>'
      + (unread ? '<span class="unread-dot" aria-label="Unread"></span>' : '') + '</a>';
  };

  const load = async () => {
    try {
      const d = await api.get('/notifications', { page, per_page: 20 }, { signal: ctx.signal });
      const items = d.items || [];
      sub.textContent = d.unread ? d.unread + ' unread'
        : (items.length ? 'All caught up.' : 'Entries waiting for your approval, entries sent back to you, and your entries once posted.');
      readAll.hidden = !d.unread;
      list.innerHTML = items.length ? items.map(item).join('')
        : emptyState('bell', 'No notifications yet', 'You are told here when an entry waits for your approval, when one of yours is rejected, and when it is posted.');
      pager(qs('[data-pager]', root), d, (p) => { page = p; load(); window.scrollTo(0, 0); });
    } catch (err) {
      if (!ctx.signal.aborted) list.innerHTML = emptyState('warning', 'Could not load your notifications', err.message);
    }
  };

  /* Marked read on the way out, without holding the link up. */
  list.addEventListener('click', (e) => {
    const a = e.target.closest('a[data-unread]');
    if (a) api.post('/notifications/read', { id: Number(a.dataset.id) }).then(badges, () => {});
  });

  readAll.addEventListener('click', async () => {
    busy(readAll, true);
    try {
      await api.post('/notifications/read', { all: true });
      toast('All marked as read.');
      badges();
      await load();
    } catch (err) { toast(err.message, { kind: 'error' }); } finally { busy(readAll, false); }
  });

  await load();
}
