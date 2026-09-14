/**
 * dashboard.js — the front page, for every role
 *
 * GenericPOS Accounting · ES module
 *
 * Where the company stands today (cash, receivables, payables, the year's
 * result), how the year has gone month by month, and the work waiting for
 * this person. Every figure comes from posted entries only.
 */

import { api } from './api.js';
import { money, fmtDay } from './store.js';
import { qs, esc, emptyState } from './ui.js';
import { hasRole } from './router.js';

const pct = (v, max) => Math.max(0, Math.round((v / max) * 100));
const plural = (n, one, many) => n + ' ' + (n === 1 ? one : many);

export async function mount(root, ctx) {
  const u = ctx.user;
  const hour = new Date().getHours();
  const first = String((u && u.full_name) || '').split(' ')[0];
  qs('[data-hello]', root).textContent = (hour < 12 ? 'Good morning' : hour < 18 ? 'Good afternoon' : 'Good evening') + (first ? ', ' + first : '');

  const canPrepare = hasRole(u, 'bookkeeper');
  const canApprove = hasRole(u, 'accountant');
  qs('[data-actions]', root).innerHTML = (canPrepare ? '<a class="btn" href="journals/new"><span data-icon="plus" data-icon-size="18"></span>New entry</a>' : '')
    + '<a class="btn btn-secondary" href="reports/trial-balance"><span data-icon="scales" data-icon-size="18"></span>Trial balance</a>';

  let d;
  try {
    d = await api.get('/dashboard', null, { signal: ctx.signal });
  } catch (err) {
    if (ctx.signal.aborted) return;
    qs('[data-kpis]', root).innerHTML = '<div style="grid-column:1/-1">' + emptyState('warning', 'The dashboard did not load', err.message) + '</div>';
    return;
  }

  const fy = d.fiscal_year;
  qs('[data-subline]', root).textContent = fy
    ? fy.name + (d.period ? ' · ' + d.period.name + ' is ' + d.period.status : '') + ' · as of ' + fmtDay(d.today, 'long')
    : 'No fiscal year covers today, ' + fmtDay(d.today, 'long') + '.';

  // ── where things stand ──────────────────────────────────────────────────
  const p = d.position;
  const y = d.year_to_date;
  const stat = (href, icon, label, value, sub, cls) => '<a class="stat" href="' + href + '" style="color:inherit;text-decoration:none">'
    + '<div class="stat-label"><span data-icon="' + icon + '" data-icon-size="16"></span>' + esc(label) + '</div>'
    + '<div class="stat-value' + (cls ? ' ' + cls : '') + '">' + money(value) + '</div><div class="stat-sub">' + sub + '</div></a>';
  qs('[data-kpis]', root).innerHTML =
      stat('reports/balance-sheet', 'bank', 'Cash and banks', p.cash_cents, 'On hand and in the bank')
    + stat('reports/balance-sheet', 'receipt', 'Receivables', p.receivables_cents,
        p.overdue_count ? '<span class="warn-text">' + money(p.overdue_cents) + ' overdue on ' + plural(p.overdue_count, 'invoice', 'invoices') + '</span>' : 'Nothing overdue')
    + stat('reports/balance-sheet', 'wallet', 'Payables', p.payables_cents, 'Owed to suppliers')
    + stat('reports/income-statement', 'chart-line', 'Net income, year to date', y.net_cents,
        'Revenue ' + money(y.revenue_cents) + ' · expenses ' + money(y.expense_cents), y.net_cents < 0 ? 'err-text' : '');

  // ── what needs a person ─────────────────────────────────────────────────
  const q = d.queues;
  const alerts = [];
  if (canApprove && q.awaiting_approval) alerts.push(['check-square', plural(q.awaiting_approval, 'entry is', 'entries are') + ' waiting for approval.', 'approvals', 'Review']);
  if (q.my_rejected) alerts.push(['x-circle', plural(q.my_rejected, 'of your entries was', 'of your entries were') + ' returned to you with a reason.', 'journals?status=rejected&mine=1', 'Open']);
  if (canApprove && q.periods_to_close.length) {
    const names = q.periods_to_close.map((x) => x.name).join(', ');
    alerts.push(['calendar', names + (q.periods_to_close.length === 1 ? ' has ended but is still open.' : ' have ended but are still open.'), 'periods', 'Periods']);
  }
  if (!fy) alerts.push(['warning', 'No fiscal year covers today, so nothing dated today can be posted.', 'periods', 'Fiscal years']);
  qs('[data-alerts]', root).innerHTML = alerts.map(([icon, text, href, label]) => '<div class="alert alert-neutral"><span data-icon="' + icon + '"></span>'
    + '<div class="grow">' + esc(text) + '</div><a class="btn btn-secondary btn-sm" href="' + href + '">' + esc(label) + '</a></div>').join('');

  // ── the year, month by month ────────────────────────────────────────────
  const t = d.trend || [];
  const max = Math.max(1, ...t.map((m) => Math.max(m.revenue_cents, m.expense_cents, 0)));
  qs('[data-trend-title]', root).textContent = fy ? 'Revenue and expenses, ' + fy.name : 'Revenue and expenses';
  qs('[data-trend]', root).innerHTML = t.length
    ? t.map((m) => '<div class="mo' + (m.future ? ' is-future' : '') + '" title="' + esc(m.name + ': revenue ' + money(m.revenue_cents) + ', expenses ' + money(m.expense_cents)) + '">'
        + '<span class="rev" style="height:' + pct(m.revenue_cents, max) + '%"></span><span class="exp" style="height:' + pct(m.expense_cents, max) + '%"></span></div>').join('')
    : '<p class="muted" style="grid-column:1/-1">No fiscal year yet.</p>';
  qs('[data-trend-labels]', root).innerHTML = t.map((m) => '<span>' + esc(m.name.slice(0, 3)) + '</span>').join('');

  // ── this person's work ──────────────────────────────────────────────────
  const work = [];
  if (canPrepare) {
    work.push(['pencil-simple', 'Your drafts, not yet submitted', q.my_drafts, 'journals?status=draft&mine=1']);
    work.push(['hourglass', 'Your entries waiting for approval', q.my_submitted, 'journals?status=submitted&mine=1']);
    work.push(['x-circle', 'Returned to you', q.my_rejected, 'journals?status=rejected&mine=1']);
  }
  if (canApprove) work.push(['check-square', 'Waiting for your approval', q.awaiting_approval, 'approvals']);
  if (!work.length) {
    work.push(['scales', 'The trial balance', null, 'reports/trial-balance']);
    work.push(['list-numbers', 'The chart of accounts', null, 'accounts']);
    work.push(['book-open', 'Posted journal entries', null, 'journals?status=posted']);
  }
  qs('[data-work]', root).innerHTML = work.map(([icon, text, n, href]) => '<a class="list-item" href="' + href + '">'
    + '<span data-icon="' + icon + '" data-icon-size="20" class="faint"></span><span class="grow">' + esc(text) + '</span>'
    + (n === null ? '<span data-icon="caret-right" data-icon-size="16" class="faint"></span>' : '<b class="num' + (n ? '' : ' faint') + '">' + n + '</b>') + '</a>').join('');

  // ── the latest postings ─────────────────────────────────────────────────
  qs('[data-recent]', root).innerHTML = d.recent.length
    ? d.recent.map((j) => '<a class="list-item" href="journals/' + j.id + '"><div class="grow" style="min-width:0">'
        + '<div><b class="mono">' + esc(j.journal_no) + '</b> <span class="small muted">· ' + esc(fmtDay(j.entry_date)) + '</span></div>'
        + '<div class="small muted truncate">' + esc(j.description) + '</div></div><b class="num">' + money(j.total_cents) + '</b></a>').join('')
    : emptyState('book-open', 'Nothing posted yet', 'Posted entries appear here.');
}
