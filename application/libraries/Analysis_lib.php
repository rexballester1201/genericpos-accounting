<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Analysis_lib.php — financial ratios, each with its formula and its inputs
 *
 * GenericPOS Accounting
 *
 *   ratios($as_of)   liquidity, solvency, profitability and efficiency at $as_of,
 *                    beside the same date a year earlier; for a co-operative,
 *                    the loan-portfolio and capital indicators as well
 *
 * ─── WHERE THE FIGURES COME FROM ──────────────────────────────────────────
 *   · Balance-sheet figures at the date, by subtype (current assets, current
 *     liabilities) and by analysis tag (cash, trade receivables, inventory,
 *     trade payables …: the vocabulary in Account_model::TAGS), inherited
 *     down the chart exactly as the statements read them (Statement_lib).
 *   · Income-statement figures for the fiscal year to date, without closing
 *     entries.
 *   · Turnover and return ratios are ANNUALISED (× 365 ÷ days so far) and use
 *     the AVERAGE of the opening and closing balances. The opening balance
 *     includes the year's opening entries, so a company that started keeping
 *     its books here this year still gets a meaningful average.
 *
 * NOTE: a ratio whose denominator is zero is NULL, never infinity and never
 * zero: "no inventory" and "inventory that never turns" are different facts.
 */
class Analysis_lib
{
    const GROUPS = [
        'liquidity'     => ['Liquidity', 'Whether what falls due within a year can be paid.'],
        'solvency'      => ['Solvency', 'How much of the company is financed by debt, and whether it can carry it.'],
        'profitability' => ['Profitability', 'How much of each unit of revenue becomes profit, and what the resources earn.'],
        'efficiency'    => ['Efficiency', 'How fast receivables are collected, stock is sold and suppliers are paid.'],
        'coop'          => ['Co-operative indicators', 'PESOS-style indicators of the loan portfolio and of the capital structure.'],
    ];

    private $CI;
    private $S;

    public function __construct()
    {
        $this->CI =& get_instance();
        if ( ! isset($this->CI->statements)) $this->CI->load->library('Statement_lib', NULL, 'statements');
        $this->S = $this->CI->statements;
    }

    public function ratios($as_of)
    {
        $coop = $this->S->is_coop();
        $now  = $this->_figures($as_of);
        $then = $this->_figures(Statement_lib::minus_year($as_of));
        if ($then['empty']) $then = NULL;
        $labels = $this->_input_labels($coop);

        $items = [];
        foreach ($this->_defs($coop) as $d) {
            $items[] = [
                'key'     => $d['key'],
                'group'   => $d['group'],
                'label'   => $d['label'],
                'unit'    => $d['unit'],
                'better'  => $d['better'],
                'formula' => $d['formula'],
                'value'   => $d['calc']($now),
                'prior'   => $then ? $d['calc']($then) : NULL,
                'inputs'  => array_map(function ($k) use ($now, $then, $labels) {
                    return ['label' => $labels[$k], 'cents' => $now['v'][$k], 'prior_cents' => $then ? $then['v'][$k] : NULL];
                }, $d['inputs']),
            ];
        }

        $groups = [];
        foreach (self::GROUPS as $k => $g) {
            if ($k === 'coop' && ! $coop) continue;
            $groups[] = ['key' => $k, 'label' => $g[0], 'about' => $g[1]];
        }

        $figure = function ($k) use ($now, $then, $labels) {
            return ['key' => $k, 'label' => $labels[$k], 'cents' => $now['v'][$k], 'prior_cents' => $then ? $then['v'][$k] : NULL];
        };

        return [
            'as_of'       => $as_of,
            'from'        => $now['from'],
            'days'        => $now['days'],
            'fiscal_year' => $now['fy'],
            'prior_as_of' => $then ? $then['as_of'] : NULL,
            'prior_from'  => $then ? $then['from'] : NULL,
            'figures'     => array_map($figure, ['revenue', 'net', 'cash', 'total_assets', 'total_liabilities', 'equity']),
            'groups'      => $groups,
            'ratios'      => $items,
        ];
    }

    // =========================================================================

    /** Every figure a ratio needs, at $as_of and for its fiscal year to date. */
    private function _figures($as_of)
    {
        $S     = $this->S;
        $chart = $S->chart();
        $fy    = $this->CI->periods->year_for_date($as_of);
        $from  = $fy ? $fy['start_date'] : substr($as_of, 0, 4) . '-01-01';

        $close = $S->net(NULL, $as_of);
        $open  = $S->net(NULL, Statement_lib::day_before($from));
        $with  = $S->net($from, $as_of);
        $sans  = $S->net($from, $as_of, ['exclude_books' => ['opening']]);
        foreach ($with as $id => $v) $open[$id] = ($open[$id] ?? 0) + $v - ($sans[$id] ?? 0);
        $pl = $S->net($from, $as_of, ['types' => ['income', 'expense'], 'exclude_books' => ['closing']]);

        $by_cf = FALSE;
        foreach ($chart as $a) if ( ! $a['is_header'] && $a['cf'] === 'cash') { $by_cf = TRUE; break; }

        $sum = function (array $net, callable $pick) use ($chart) {
            $t = 0;
            foreach ($chart as $id => $a) if ( ! $a['is_header'] && $pick($a)) $t += $net[$id] ?? 0;
            return $t;
        };
        $of = function ($type, $sub = NULL) use ($S) {
            return function ($a) use ($type, $sub, $S) { return $a['type'] === $type && ($sub === NULL || $S->sub_of($a) === $sub); };
        };
        $tag = function ($t) { return function ($a) use ($t) { return isset($a['tagset'][$t]); }; };
        $cash = $by_cf ? function ($a) { return $a['cf'] === 'cash'; } : $tag('cash');
        $pl_type = function ($a) { return in_array($a['type'], ['income', 'expense'], TRUE); };

        $bal = function (array $m) use ($sum, $of, $tag, $cash, $pl_type) {
            return [
                'current_assets'      => $sum($m, $of('asset', 'current')),
                'current_liabilities' => -$sum($m, $of('liability', 'current')),
                'total_assets'        => $sum($m, $of('asset')),
                'total_liabilities'   => -$sum($m, $of('liability')),
                'equity'              => -$sum($m, $of('equity')) - $sum($m, $pl_type),
                'cash'                => $sum($m, $cash),
                'receivables'         => $sum($m, $tag('receivable')),
                'trade_receivables'   => $sum($m, $tag('trade_receivable')),
                'inventory'           => $sum($m, $tag('inventory')),
                'trade_payables'      => -$sum($m, $tag('trade_payable')),
                'loans'               => $sum($m, $tag('loans_current')) + $sum($m, $tag('loans_past_due')),
                'loans_past_due'      => $sum($m, $tag('loans_past_due')),
                'loan_allowance'      => -$sum($m, $tag('loan_allowance')),
                'share_capital'       => -$sum($m, $tag('share_capital')),
                'statutory'           => -$sum($m, $tag('statutory')),
            ];
        };
        $c = $bal($close);
        $o = $bal($open);

        $revenue  = -$sum($pl, $of('income', 'operating'));
        $cos      = $sum($pl, $of('expense', 'cost_of_sales'));
        $opex     = $sum($pl, $of('expense', 'operating'));
        $finance  = $sum($pl, $of('expense', 'finance'));
        $tax      = $sum($pl, $of('expense', 'income_tax'));
        $interest = $sum($pl, $tag('interest_expense')) ?: $finance;
        $net      = -array_sum($pl);
        $gross    = $revenue - $cos;
        $days     = max(1, (int) round((strtotime($as_of) - strtotime($from)) / 86400) + 1);

        $v = $c + [
            'working_capital'       => $c['current_assets'] - $c['current_liabilities'],
            'revenue'               => $revenue,
            'cost_of_sales'         => $cos,
            'gross'                 => $gross,
            'operating'             => $S->is_coop() ? $gross - $opex - $finance : $gross - $opex,
            'net'                   => $net,
            'interest'              => $interest,
            'ebit'                  => $net + $tax + $interest,
            'expenses'              => $opex + $finance,
            'avg_total_assets'      => intdiv($o['total_assets'] + $c['total_assets'], 2),
            'avg_equity'            => intdiv($o['equity'] + $c['equity'], 2),
            'avg_trade_receivables' => intdiv($o['trade_receivables'] + $c['trade_receivables'], 2),
            'avg_inventory'         => intdiv($o['inventory'] + $c['inventory'], 2),
            'avg_trade_payables'    => intdiv($o['trade_payables'] + $c['trade_payables'], 2),
        ];

        return [
            'as_of'  => $as_of,
            'from'   => $from,
            'fy'     => $fy ? $fy['name'] : NULL,
            'days'   => $days,
            'annual' => 365 / $days,
            'v'      => $v,
            'empty'  => ! array_filter($close) && ! array_filter($pl),
        ];
    }

    private function _input_labels($coop)
    {
        $ni = $coop ? 'Net surplus' : 'Net income';
        return [
            'current_assets' => 'Current assets', 'current_liabilities' => 'Current liabilities',
            'cash' => 'Cash and cash equivalents', 'receivables' => 'Receivables',
            'total_assets' => 'Total assets', 'total_liabilities' => 'Total liabilities', 'equity' => $coop ? "Members' equity" : 'Equity',
            'working_capital' => 'Working capital', 'trade_receivables' => 'Trade receivables', 'inventory' => 'Inventory', 'trade_payables' => 'Trade payables',
            'revenue' => 'Revenue, year to date', 'cost_of_sales' => 'Cost of sales, year to date', 'gross' => ($coop ? 'Gross margin' : 'Gross profit') . ', year to date',
            'operating' => ($coop ? 'Net surplus from operations' : 'Operating income') . ', year to date', 'net' => $ni . ', year to date',
            'interest' => 'Interest expense, year to date', 'ebit' => 'Earnings before interest and tax, year to date',
            'expenses' => 'Operating and financing costs, year to date',
            'avg_total_assets' => 'Average total assets', 'avg_equity' => 'Average equity', 'avg_trade_receivables' => 'Average trade receivables',
            'avg_inventory' => 'Average inventory', 'avg_trade_payables' => 'Average trade payables',
            'loans' => 'Loans receivable, current and past due', 'loans_past_due' => 'Loans past due',
            'loan_allowance' => 'Allowance for probable losses on loans', 'share_capital' => 'Share capital', 'statutory' => 'Statutory funds',
        ];
    }

    /** The ratios, in the order PLAN.md §7 lists them. */
    private function _defs($coop)
    {
        $r = function ($a, $b) { return $b ? $a / $b : NULL; };
        $ann = function (array $f, $k) { return $f['v'][$k] * $f['annual']; };
        $turn = function (array $f, $num, $avg) use ($r, $ann) { return $r($ann($f, $num), $f['v'][$avg]); };
        $days = function ($t) { return $t ? 365 / $t : NULL; };
        $ni = $coop ? 'Net surplus' : 'Net income';

        $defs = [
            ['liquidity', 'current_ratio', 'Current ratio', 'x', 'higher', 'Current assets ÷ current liabilities', ['current_assets', 'current_liabilities'],
                function ($f) use ($r) { return $r($f['v']['current_assets'], $f['v']['current_liabilities']); }],
            ['liquidity', 'quick_ratio', 'Quick ratio', 'x', 'higher', '(Cash + receivables) ÷ current liabilities', ['cash', 'receivables', 'current_liabilities'],
                function ($f) use ($r) { return $r($f['v']['cash'] + $f['v']['receivables'], $f['v']['current_liabilities']); }],
            ['liquidity', 'cash_ratio', 'Cash ratio', 'x', 'higher', 'Cash ÷ current liabilities', ['cash', 'current_liabilities'],
                function ($f) use ($r) { return $r($f['v']['cash'], $f['v']['current_liabilities']); }],
            ['liquidity', 'working_capital', 'Working capital', 'money', 'higher', 'Current assets − current liabilities', ['current_assets', 'current_liabilities'],
                function ($f) { return $f['v']['working_capital']; }],

            ['solvency', 'debt_ratio', 'Debt ratio', 'pct', 'lower', 'Total liabilities ÷ total assets', ['total_liabilities', 'total_assets'],
                function ($f) use ($r) { return $r($f['v']['total_liabilities'], $f['v']['total_assets']); }],
            ['solvency', 'debt_to_equity', 'Debt-to-equity ratio', 'x', 'lower', 'Total liabilities ÷ equity', ['total_liabilities', 'equity'],
                function ($f) use ($r) { return $r($f['v']['total_liabilities'], $f['v']['equity']); }],
            ['solvency', 'equity_ratio', 'Equity ratio', 'pct', 'higher', 'Equity ÷ total assets', ['equity', 'total_assets'],
                function ($f) use ($r) { return $r($f['v']['equity'], $f['v']['total_assets']); }],
            ['solvency', 'times_interest_earned', 'Times interest earned', 'x', 'higher', 'Earnings before interest and tax ÷ interest expense', ['ebit', 'interest'],
                function ($f) use ($r) { return $r($f['v']['ebit'], $f['v']['interest']); }],

            ['profitability', 'gross_margin', $coop ? 'Gross margin ratio' : 'Gross profit margin', 'pct', 'higher', ($coop ? 'Gross margin' : 'Gross profit') . ' ÷ revenue', ['gross', 'revenue'],
                function ($f) use ($r) { return $r($f['v']['gross'], $f['v']['revenue']); }],
            ['profitability', 'operating_margin', 'Operating margin', 'pct', 'higher', ($coop ? 'Net surplus from operations' : 'Operating income') . ' ÷ revenue', ['operating', 'revenue'],
                function ($f) use ($r) { return $r($f['v']['operating'], $f['v']['revenue']); }],
            ['profitability', 'net_margin', $coop ? 'Net surplus margin' : 'Net profit margin', 'pct', 'higher', $ni . ' ÷ revenue', ['net', 'revenue'],
                function ($f) use ($r) { return $r($f['v']['net'], $f['v']['revenue']); }],
            ['profitability', 'return_on_assets', 'Return on assets', 'pct', 'higher', $ni . ', annualised ÷ average total assets', ['net', 'avg_total_assets'],
                function ($f) use ($turn) { return $turn($f, 'net', 'avg_total_assets'); }],
            ['profitability', 'return_on_equity', 'Return on equity', 'pct', 'higher', $ni . ', annualised ÷ average equity', ['net', 'avg_equity'],
                function ($f) use ($turn) { return $turn($f, 'net', 'avg_equity'); }],

            ['efficiency', 'receivable_turnover', 'Receivable turnover', 'x', 'higher', 'Revenue, annualised ÷ average trade receivables', ['revenue', 'avg_trade_receivables'],
                function ($f) use ($turn) { return $turn($f, 'revenue', 'avg_trade_receivables'); }],
            ['efficiency', 'days_sales_outstanding', 'Days sales outstanding', 'days', 'lower', '365 ÷ receivable turnover', ['revenue', 'avg_trade_receivables'],
                function ($f) use ($turn, $days) { return $days($turn($f, 'revenue', 'avg_trade_receivables')); }],
            ['efficiency', 'inventory_turnover', 'Inventory turnover', 'x', 'higher', 'Cost of sales, annualised ÷ average inventory', ['cost_of_sales', 'avg_inventory'],
                function ($f) use ($turn) { return $turn($f, 'cost_of_sales', 'avg_inventory'); }],
            ['efficiency', 'days_inventory_outstanding', 'Days inventory outstanding', 'days', 'lower', '365 ÷ inventory turnover', ['cost_of_sales', 'avg_inventory'],
                function ($f) use ($turn, $days) { return $days($turn($f, 'cost_of_sales', 'avg_inventory')); }],
            ['efficiency', 'payable_turnover', 'Payable turnover', 'x', NULL, 'Cost of sales, annualised ÷ average trade payables', ['cost_of_sales', 'avg_trade_payables'],
                function ($f) use ($turn) { return $turn($f, 'cost_of_sales', 'avg_trade_payables'); }],
            ['efficiency', 'days_payables_outstanding', 'Days payables outstanding', 'days', NULL, '365 ÷ payable turnover', ['cost_of_sales', 'avg_trade_payables'],
                function ($f) use ($turn, $days) { return $days($turn($f, 'cost_of_sales', 'avg_trade_payables')); }],
            ['efficiency', 'cash_conversion_cycle', 'Cash conversion cycle', 'days', 'lower', 'Days sales outstanding + days inventory outstanding − days payables outstanding',
                ['avg_trade_receivables', 'avg_inventory', 'avg_trade_payables'],
                function ($f) use ($turn, $days) {
                    $dso = $days($turn($f, 'revenue', 'avg_trade_receivables'));
                    $dpo = $days($turn($f, 'cost_of_sales', 'avg_trade_payables'));
                    $dio = $f['v']['avg_inventory'] ? $days($turn($f, 'cost_of_sales', 'avg_inventory')) : 0;
                    return ($dso === NULL || $dpo === NULL || $dio === NULL) ? NULL : $dso + $dio - $dpo;
                }],
            ['efficiency', 'asset_turnover', 'Asset turnover', 'x', 'higher', 'Revenue, annualised ÷ average total assets', ['revenue', 'avg_total_assets'],
                function ($f) use ($turn) { return $turn($f, 'revenue', 'avg_total_assets'); }],
        ];

        if ($coop) {
            $defs = array_merge($defs, [
                ['coop', 'portfolio_at_risk', 'Portfolio at risk', 'pct', 'lower', 'Loans past due ÷ loans receivable', ['loans_past_due', 'loans'],
                    function ($f) use ($r) { return $r($f['v']['loans_past_due'], $f['v']['loans']); }],
                ['coop', 'allowance_cover', 'Allowance cover', 'pct', 'higher', 'Allowance for probable losses ÷ loans past due', ['loan_allowance', 'loans_past_due'],
                    function ($f) use ($r) { return $r($f['v']['loan_allowance'], $f['v']['loans_past_due']); }],
                ['coop', 'share_capital_ratio', 'Share capital to total assets', 'pct', 'higher', 'Share capital ÷ total assets', ['share_capital', 'total_assets'],
                    function ($f) use ($r) { return $r($f['v']['share_capital'], $f['v']['total_assets']); }],
                ['coop', 'statutory_fund_ratio', 'Statutory funds to total assets', 'pct', 'higher', 'Statutory funds ÷ total assets', ['statutory', 'total_assets'],
                    function ($f) use ($r) { return $r($f['v']['statutory'], $f['v']['total_assets']); }],
                ['coop', 'cost_ratio', 'Operating cost ratio', 'pct', 'lower', 'Operating and financing costs ÷ revenue', ['expenses', 'revenue'],
                    function ($f) use ($r) { return $r($f['v']['expenses'], $f['v']['revenue']); }],
            ]);
        }

        return array_map(function ($d) {
            return ['group' => $d[0], 'key' => $d[1], 'label' => $d[2], 'unit' => $d[3], 'better' => $d[4], 'formula' => $d[5], 'inputs' => $d[6], 'calc' => $d[7]];
        }, $defs);
    }
}
