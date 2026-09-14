/**
 * reports.js — every report, in one place
 *
 * GenericPOS Accounting · ES module · every role
 *
 * A co-operative sees its statements under the names the CDA uses
 * (financial condition, operations).
 */

import { store } from './store.js';
import { qs, esc } from './ui.js';

export function mount(root) {
  const coop = store().entity_type === 'cooperative';
  const groups = [
    ['Financial statements', [
      ['reports/balance-sheet', 'scales', coop ? 'Statement of financial condition' : 'Balance sheet',
        'What the ' + (coop ? 'co-operative' : 'company') + ' owns and owes, and its equity, on any date — beside an earlier date if you like.'],
      ['reports/income-statement', 'chart-line', coop ? 'Statement of operations' : 'Income statement',
        'Revenue, costs and ' + (coop ? 'net surplus' : 'net income') + ' for any period: beside the previous period or last year, month by month, or for one department.'],
      ['reports/changes-in-equity', 'stack', 'Changes in equity', 'How each equity account moved over the period, with the ' + (coop ? 'net surplus' : 'net income') + ' not yet closed.'],
      ['reports/cash-flows', 'coins', 'Cash flows', 'Where cash came from and where it went: operating, investing and financing activities.'],
    ]],
    ['Ledgers and books', [
      ['reports/trial-balance', 'calculator', 'Trial balance', 'Every account\'s balance on a date: unadjusted, adjusted or post-closing.'],
      ['reports/general-ledger', 'rows', 'General ledger', 'One account\'s entries with a running balance, from the balance brought forward.'],
      ['reports/books', 'book-open', 'Books of accounts', 'The general journal, the cash receipts and disbursements books, and the sales and purchase books, entry by entry.'],
    ]],
    ['Analysis', [
      ['reports/analysis', 'chart-bar', 'Financial analysis', 'Liquidity, solvency, profitability and efficiency ratios, each with its formula, beside the same date last year.'],
      ['reports/income-statement?pct=1', 'percent', 'Common-size income statement', 'Every line as a percentage of ' + (coop ? 'revenues' : 'revenue') + '.'],
      ['reports/balance-sheet?compare=prior_year_end&pct=1', 'percent', 'Comparative balance sheet', 'This date beside the end of last year, with the change and the percentage of total assets.'],
      ['reports/income-statement?compare=monthly', 'chart-line', 'Month by month', 'Each month of the year side by side, to see the trend.'],
    ]],
  ];

  qs('[data-groups]', root).innerHTML = groups.map(([title, items]) => '<section class="report-group"><h2>' + esc(title) + '</h2><div class="report-cards">'
    + items.map(([href, icon, name, about]) => '<a class="report-card" href="' + esc(href) + '"><span data-icon="' + icon + '" data-icon-size="22"></span>'
      + '<span><b>' + esc(name) + '</b><span class="d">' + esc(about) + '</span></span></a>').join('')
    + '</div></section>').join('');
}
