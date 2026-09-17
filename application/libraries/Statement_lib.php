<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Statement_lib.php — the financial statements, laid out from the chart itself
 *
 * GenericPOS Accounting
 *
 *   balance_sheet($dates, $levels, $zero)             financial position (co-op: financial condition)
 *   income_statement($ranges, $levels, $zero, $dept)  comprehensive income (co-op: operations)
 *   changes_in_equity($from, $to, $levels)
 *   cash_flows($from, $to, $levels)                   the indirect method
 *
 * Each returns { title, columns, lines, base, checks }. A line is one row of
 * the printed statement:
 *   section   a caption in capitals ("ASSETS")
 *   heading   a caption inside a section ("Liabilities", "Cost of sales")
 *   header    a chart header, shown open with the accounts under it
 *   account   one account                            carries id and code
 *   group     a header folded into a single line     carries id and code
 *   subtotal  "Total …" under an open header
 *   total     a section's total
 *   computed  a derived line: gross profit, net income not yet closed …
 *   grand     the last line of a block, ruled twice
 * Amounts are integer centavos on the statement's own sign: assets and
 * expenses debit-positive; liabilities, equity and income credit-positive.
 *
 * ─── WHERE THE LAYOUT COMES FROM ──────────────────────────────────────────
 * The chart of accounts is the layout. The type chooses the statement, the
 * subtype the section (current and non-current; cost of sales, operating,
 * finance, other and income tax), and the header tree the line items and
 * their subtotals. A company that renames, renumbers or regroups its chart
 * gets statements that follow, with nothing here to edit.
 *
 * ─── WHICH ENTRIES COUNT (PLAN.md §2.5–2.6) ───────────────────────────────
 *   · Balance-sheet figures include every posted entry up to the date.
 *   · Income-statement figures leave out closing entries, so a closed year
 *     still shows what it earned.
 *   · Net income not yet closed into equity is a line of its own in equity,
 *     so the balance sheet balances before and after the year-end close.
 *   · Cash flows leave out opening entries (they set the starting balances)
 *     and closing entries (they only move income into equity).
 *
 * NOTE: EVERY STATEMENT CARRIES ITS OWN PROOF in `checks`: the balance sheet
 * whether assets equal liabilities and equity, the income statement whether
 * its net income equals the income and expense accounts, the cash flow
 * statement whether its net change equals the movement of the cash accounts.
 * Posted entries always balance, so a false there means damaged data.
 */
class Statement_lib
{
    const PL = ['income', 'expense'];

    private $CI;
    private $acc  = NULL;   // id => account row in chart (tree) order, with sub, cf and tagset inherited
    private $kids = [];     // parent id (0 at the top) => child ids in chart order
    private $coop = FALSE;
    private $sole = FALSE;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->model('Account_model', 'accounts');
        $this->CI->load->model('Ledger_model', 'ledger');
        $this->coop = shop_cfg('entity_type') === 'cooperative';
        $this->sole = ! $this->coop && shop_cfg('business_form') === 'sole_proprietorship';
    }

    /** The words that differ between a business, a sole proprietorship and a co-operative. */
    public function words()
    {
        if ($this->coop) {
            return [
                'bs' => 'Statement of Financial Condition', 'is' => 'Statement of Operations',
                'sce' => "Statement of Changes in Members' Equity", 'cf' => 'Statement of Cash Flows',
                'equity' => "Members' equity", 'income' => 'Net surplus', 'net' => 'Net surplus (loss)',
                'revenue' => 'Revenues', 'cos' => 'Cost of goods sold', 'gross' => 'Gross margin',
            ];
        }
        return [
            'bs' => 'Statement of Financial Position', 'is' => 'Statement of Comprehensive Income',
            'sce' => $this->sole ? "Statement of Changes in Owner's Equity" : 'Statement of Changes in Equity',
            'cf' => 'Statement of Cash Flows',
            'equity' => $this->sole ? "Owner's equity" : 'Equity', 'income' => 'Net income', 'net' => 'Net income (loss)',
            'revenue' => 'Revenue', 'cos' => 'Cost of sales', 'gross' => 'Gross profit',
        ];
    }

    public function is_coop() { return $this->coop; }

    // =========================================================================
    // DATES
    // =========================================================================

    /** The same day a year earlier; 29 February becomes the 28th. */
    public static function minus_year($ymd)
    {
        list($y, $m, $d) = array_map('intval', explode('-', $ymd));
        $y--;
        return sprintf('%04d-%02d-%02d', $y, $m, min($d, (int) date('t', mktime(0, 0, 0, $m, 1, $y))));
    }

    public static function day_before($ymd)
    {
        return date('Y-m-d', strtotime($ymd . ' -1 day'));
    }

    /** [$from, $to] cut into calendar months, the first and last clipped to the range. */
    public static function months($from, $to)
    {
        $out = [];
        $cur = $from;
        while ($cur <= $to && count($out) <= 400) {
            $end = date('Y-m-t', strtotime($cur));
            if ($end > $to) $end = $to;
            $out[] = ['from' => $cur, 'to' => $end];
            $cur = date('Y-m-d', strtotime($end . ' +1 day'));
        }
        return $out;
    }

    /** The period before [$from, $to]: the same number of whole months, or of days. */
    public static function prior_period($from, $to)
    {
        $until = self::day_before($from);
        if (substr($from, 8, 2) === '01' && $to === date('Y-m-t', strtotime($to))) {
            $n = count(self::months($from, $to));
            return ['from' => date('Y-m-01', strtotime(substr($from, 0, 8) . '01 -' . $n . ' months')), 'to' => $until];
        }
        $days = (int) round((strtotime($to) - strtotime($from)) / 86400) + 1;
        return ['from' => date('Y-m-d', strtotime($until . ' -' . ($days - 1) . ' days')), 'to' => $until];
    }

    // =========================================================================
    // THE CHART AND ITS FIGURES (also read by Analysis_lib)
    // =========================================================================

    /** Every account, in chart order, with its subtype, cash-flow class and tags as inherited. */
    public function chart()
    {
        if ($this->acc !== NULL) return $this->acc;
        $this->acc = [];
        foreach ($this->CI->accounts->tree() as $a) {
            $id = (int) $a['id'];
            $a['id']        = $id;
            $a['parent_id'] = (int) $a['parent_id'];
            $a['depth']     = (int) $a['depth'];
            $a['is_header'] = (bool) (int) $a['is_header'];
            $a['is_contra'] = (bool) (int) $a['is_contra'];
            $this->acc[$id] = $a;
            $this->kids[$a['parent_id']][] = $id;
        }
        /* Tree order puts every parent before its children, so one pass can
           hand down what an account leaves empty. */
        foreach (array_keys($this->acc) as $id) {
            $a = $this->acc[$id];
            $p = ($a['parent_id'] && isset($this->acc[$a['parent_id']])) ? $this->acc[$a['parent_id']] : NULL;
            $own = ($a['tags'] !== NULL && $a['tags'] !== '') ? explode(',', $a['tags']) : [];
            $this->acc[$id]['sub']    = $a['subtype'] ?: ($p ? $p['sub'] : NULL);
            $this->acc[$id]['cf']     = $a['cash_flow'] ?: ($p ? $p['cf'] : NULL);
            $this->acc[$id]['tagset'] = array_fill_keys(array_merge($p ? array_keys($p['tagset']) : [], $own), TRUE);
        }
        return $this->acc;
    }

    /** Debit-positive net per account over [$from, $to] (Ledger_model::movements options). */
    public function net($from, $to, array $opts = [])
    {
        $out = [];
        foreach ($this->CI->ledger->movements($from, $to, $opts) as $id => $m) $out[(int) $id] = (int) $m['dr'] - (int) $m['cr'];
        return $out;
    }

    /** The section an account belongs in, with a default for one left unclassified. */
    public function sub_of(array $a)
    {
        $s = $a['sub'];
        switch ($a['type']) {
            case 'income':    return $s === 'other' ? 'other' : 'operating';
            case 'expense':   return in_array($s, ['cost_of_sales', 'finance', 'other', 'income_tax'], TRUE) ? $s : 'operating';
            case 'asset':
            case 'liability': return $s === 'non_current' ? 'non_current' : 'current';
            default:          return $s ?: 'other';
        }
    }

    /** Net income not yet closed into equity at the end of a net map: minus every income and expense balance. */
    private function _unclosed(array $net)
    {
        $t = 0;
        foreach ($net as $id => $v) {
            if (isset($this->acc[$id]) && in_array($this->acc[$id]['type'], self::PL, TRUE)) $t += $v;
        }
        return -$t;
    }

    // =========================================================================
    // STATEMENT OF FINANCIAL POSITION
    // =========================================================================

    /** @param string[] $dates one column per date, the first is the report date */
    public function balance_sheet(array $dates, $levels = 2, $zero = FALSE)
    {
        $this->chart();
        $w = $this->words();

        $bs = [];
        $cur = [];
        $prior = [];
        $cols = [];
        foreach ($dates as $d) {
            $net = $this->net(NULL, $d);
            $fy  = $this->CI->periods->year_for_date($d);
            $all = $this->_unclosed($net);
            $now = $fy ? -array_sum($this->net($fy['start_date'], $d, ['types' => self::PL])) : $all;
            $bs[]    = $net;
            $cur[]   = $now;
            $prior[] = $all - $now;
            $cols[]  = ['as_of' => $d, 'fiscal_year' => $fy ? $fy['name'] : NULL, 'fy_start' => $fy ? $fy['start_date'] : NULL];
        }
        $credit = array_map(function ($m) { return self::_neg($m); }, $bs);

        list($al, $ta) = $this->_section($this->_members('asset'), $bs, $levels, $zero, 1);
        list($ll, $tl) = $this->_section($this->_members('liability'), $credit, $levels, $zero, 2);
        list($el, $te) = $this->_section($this->_members('equity'), $credit, $levels, $zero, 2);

        if (self::_any($cur))   $el[] = ['type' => 'computed', 'label' => $w['income'] . ' for the year to date, not yet closed', 'indent' => 2, 'amounts' => $cur];
        if (self::_any($prior)) $el[] = ['type' => 'computed', 'label' => $w['income'] . ' of earlier years, not yet closed', 'indent' => 2, 'amounts' => $prior];
        $te  = self::_add($te, $cur, $prior);
        $tle = self::_add($tl, $te);
        $eq  = lcfirst($w['equity']);

        $lines = array_merge(
            [['type' => 'section', 'label' => 'Assets', 'indent' => 0]],
            $al,
            [['type' => 'grand', 'label' => 'Total assets', 'indent' => 0, 'amounts' => $ta]],
            [['type' => 'section', 'label' => 'Liabilities and ' . $eq, 'indent' => 0]],
            [['type' => 'heading', 'label' => 'Liabilities', 'indent' => 1]],
            $ll,
            [['type' => 'total', 'label' => 'Total liabilities', 'indent' => 1, 'amounts' => $tl]],
            [['type' => 'heading', 'label' => $w['equity'], 'indent' => 1]],
            $el,
            [['type' => 'total', 'label' => 'Total ' . $eq, 'indent' => 1, 'amounts' => $te]],
            [['type' => 'grand', 'label' => 'Total liabilities and ' . $eq, 'indent' => 0, 'amounts' => $tle]]
        );

        $balanced = [];
        foreach ($ta as $i => $x) $balanced[] = $x === $tle[$i];

        return [
            'title'      => $w['bs'],
            'columns'    => $cols,
            'lines'      => $lines,
            'base'       => $ta,
            'base_label' => 'total assets',
            'checks'     => ['balanced' => $balanced],
            'totals'     => ['assets' => $ta, 'liabilities' => $tl, 'equity' => $te],
        ];
    }

    // =========================================================================
    // STATEMENT OF COMPREHENSIVE INCOME / OPERATIONS
    // =========================================================================

    /** @param array[] $ranges one column per [from, to]; a range with 'total' => TRUE is marked as the total column */
    public function income_statement(array $ranges, $levels = 2, $zero = FALSE, $dept = 0)
    {
        $this->chart();
        $w = $this->words();

        $opts = ['types' => self::PL, 'exclude_books' => ['closing']];
        if ($dept) $opts['department_id'] = (int) $dept;
        $dr = [];
        /* A range may carry its own department_id (a column per department);
           'none' selects the lines no department was put on. */
        foreach ($ranges as $r) {
            $o = $opts;
            if (array_key_exists('department_id', $r)) {
                unset($o['department_id'], $o['no_department']);
                if ($r['department_id'] === 'none') $o['no_department'] = TRUE;
                elseif ((int) $r['department_id'] > 0) $o['department_id'] = (int) $r['department_id'];
            }
            $dr[] = $this->net($r['from'], $r['to'], $o);
        }
        $cr = array_map(function ($m) { return self::_neg($m); }, $dr);
        $n  = count($ranges);

        $sec = function ($type, $sub, $title, $indent = 1) use ($dr, $cr, $levels, $zero) {
            list($lines, $total) = $this->_section($this->_members($type, [$sub]), $type === 'income' ? $cr : $dr, $levels, $zero, $indent);
            return ['title' => $title, 'lines' => $lines, 'total' => $total];
        };
        $rev = $sec('income', 'operating', $w['revenue']);
        $cos = $sec('expense', 'cost_of_sales', $w['cos']);
        $opx = $sec('expense', 'operating', $this->coop ? 'Operating and administrative costs' : 'Operating expenses');
        $fin = $sec('expense', 'finance', $this->coop ? 'Financing costs' : 'Finance costs');
        $oin = $sec('income', 'other', 'Other income');
        $oex = $sec('expense', 'other', 'Other expenses');
        $tax = $sec('expense', 'income_tax', 'Income tax expense');

        $L = [];
        $block = function (array $s, $total_label) use (&$L) {
            $L[] = ['type' => 'heading', 'label' => $s['title'], 'indent' => 0];
            foreach ($s['lines'] as $x) $L[] = $x;
            $L[] = ['type' => 'total', 'label' => $total_label, 'indent' => 0, 'amounts' => $s['total']];
        };
        $computed = function ($label, $amounts, $strong = TRUE) use (&$L) {
            $L[] = ['type' => 'computed', 'label' => $label, 'indent' => 0, 'amounts' => $amounts, 'strong' => $strong];
        };

        $block($rev, 'Total ' . lcfirst($w['revenue']));
        $gross = self::_sub($rev['total'], $cos['total']);
        if ($cos['lines']) {
            $block($cos, 'Total ' . lcfirst($w['cos']));
            $computed($w['gross'], $gross);
        }

        if ($this->coop) {
            /* A co-op's financing costs are part of what it costs to serve its
               members, so they sit with the expenses, above the net surplus. */
            if ($fin['lines']) $block($fin, 'Total ' . lcfirst($fin['title']));
            if ($opx['lines']) $block($opx, 'Total ' . lcfirst($opx['title']));
            $expenses = self::_add($fin['total'], $opx['total']);
            if ($fin['lines'] && $opx['lines']) $computed('Total expenses', $expenses, FALSE);
            $operating = self::_sub($gross, $expenses);
            $below = $oin['lines'] || $oex['lines'] || $tax['lines'];
            if ($below) $computed('Net surplus (loss) from operations', $operating);
            if ($oin['lines']) $block($oin, 'Total other income');
            if ($oex['lines']) $block($oex, 'Total other expenses');
            $before = self::_sub(self::_add($operating, $oin['total']), $oex['total']);
        } else {
            if ($opx['lines'] || ! $cos['lines']) $block($opx, 'Total operating expenses');
            $operating = self::_sub($gross, $opx['total']);
            $below = $oin['lines'] || $fin['lines'] || $oex['lines'] || $tax['lines'];
            if ($below) $computed('Operating income (loss)', $operating);
            if ($oin['lines']) $block($oin, 'Total other income');
            if ($fin['lines']) $block($fin, 'Total finance costs');
            if ($oex['lines']) $block($oex, 'Total other expenses');
            $before = self::_sub(self::_sub(self::_add($operating, $oin['total']), $fin['total']), $oex['total']);
        }
        if ($tax['lines']) {
            $computed($this->coop ? 'Net surplus before income tax' : 'Income (loss) before income tax', $before);
            $block($tax, 'Total income tax expense');
        }
        $net = self::_sub($before, $tax['total']);
        $L[] = ['type' => 'grand', 'label' => $w['net'], 'indent' => 0, 'amounts' => $net];

        $ties = [];
        foreach ($dr as $i => $m) $ties[] = -array_sum($m) === $net[$i];

        $cols = [];
        foreach ($ranges as $r) {
            $col = ['from' => $r['from'], 'to' => $r['to'], 'total' => ! empty($r['total'])];
            if (array_key_exists('department_id', $r)) $col['department_id'] = $r['department_id'];
            if (isset($r['label'])) $col['label'] = (string) $r['label'];
            $cols[] = $col;
        }

        return [
            'title'      => $w['is'],
            'columns'    => $cols,
            'lines'      => $L,
            'base'       => $rev['total'],
            'base_label' => lcfirst($w['revenue']),
            'checks'     => ['ties' => $ties],
            'totals'     => [
                'revenue' => $rev['total'], 'cost_of_sales' => $cos['total'], 'gross' => $gross,
                'operating_expenses' => $opx['total'], 'finance' => $fin['total'], 'operating' => $operating,
                'other_income' => $oin['total'], 'other_expenses' => $oex['total'], 'before_tax' => $before,
                'tax' => $tax['total'], 'net' => $net, 'columns' => $n,
            ],
        ];
    }

    // =========================================================================
    // STATEMENT OF CHANGES IN EQUITY
    // =========================================================================

    /**
     * Columns: balance at the start, net income for the period, other changes,
     * balance at the end. "Other changes" is what is left once net income is
     * counted: contributions, withdrawals, dividends, transfers between funds,
     * and the closing entries that move net income into equity.
     *
     * Opening entries dated inside the period count in the balance at the
     * start: they bring in what the company had when its books began here, so
     * a first year opens on its opening balances instead of on zero. The
     * ending ties to the balance sheet by construction, and the net income is
     * the income statement's.
     */
    public function changes_in_equity($from, $to, $levels = 0)
    {
        $this->chart();
        $w = $this->words();
        $before = self::day_before($from);

        $prior = $this->net(NULL, $before);
        $with  = $this->net($from, $to);
        $sans  = $this->net($from, $to, ['exclude_books' => ['opening']]);
        $end   = $this->net(NULL, $to);
        $ni    = -array_sum($this->net($from, $to, ['types' => self::PL, 'exclude_books' => ['closing']]));

        $b = [];
        $o = [];
        $e = [];
        $opening = 0;
        foreach ($this->_members('equity') as $id) {
            $in = ($with[$id] ?? 0) - ($sans[$id] ?? 0);
            $opening -= $in;
            $b[$id] = -(($prior[$id] ?? 0) + $in);
            $e[$id] = -($end[$id] ?? 0);
            $o[$id] = $e[$id] - $b[$id];
        }
        list($lines, $tot) = $this->_section($this->_members('equity'), [$b, [], $o, $e], $levels, FALSE, 0);

        /* Income brought in by an opening entry is this year's income: it
           stays in the net income column, so only earlier years' unclosed
           income starts the line below. */
        $ub  = $this->_unclosed($prior);
        $ue  = $this->_unclosed($end);
        $row = [$ub, $ni, $ue - $ub - $ni, $ue];
        if (self::_any($row)) $lines[] = ['type' => 'computed', 'label' => $w['income'] . ' not yet closed into equity', 'indent' => 0, 'amounts' => $row];
        $tot = self::_add($tot, $row);
        $lines[] = ['type' => 'grand', 'label' => 'Total ' . lcfirst($w['equity']), 'indent' => 0, 'amounts' => $tot];

        return [
            'title'   => $w['sce'],
            'columns' => [
                ['key' => 'beginning', 'as_of' => $before, 'label_date' => $from],
                ['key' => 'net_income', 'from' => $from, 'to' => $to],
                ['key' => 'other', 'from' => $from, 'to' => $to],
                ['key' => 'ending', 'as_of' => $to],
            ],
            'lines'   => $lines,
            'base'    => NULL,
            'checks'  => ['ties' => [$tot[0] + $tot[1] + $tot[2] === $tot[3]]],
            'totals'  => ['net_income' => $ni, 'beginning' => $tot[0], 'ending' => $tot[3], 'opening_entries' => $opening],
            'closing_account' => (string) shop_cfg('acct_retained_earnings', ''),
        ];
    }

    // =========================================================================
    // STATEMENT OF CASH FLOWS (INDIRECT)
    // =========================================================================

    /**
     * Net income, then the change in every other balance-sheet account, each
     * in the activity its cash-flow class names. Accumulated depreciation is
     * always an operating add-back, whatever class its header carries.
     *
     * $levels = 0 lists every account; otherwise accounts are grouped under
     * the header that names their line item ("Trade and Other Receivables").
     */
    public function cash_flows($from, $to, $levels = 2)
    {
        $this->chart();
        $w = $this->words();
        $before = self::day_before($from);

        $flow  = $this->net($from, $to, ['exclude_books' => ['opening', 'closing']]);
        $all   = $this->net($from, $to);
        $prior = $this->net(NULL, $before);

        $cash = [];
        foreach ($this->acc as $id => $a) if ( ! $a['is_header'] && $a['cf'] === 'cash') $cash[] = $id;
        if ( ! $cash) foreach ($this->acc as $id => $a) if ( ! $a['is_header'] && isset($a['tagset']['cash'])) $cash[] = $id;
        $is_cash = array_fill_keys($cash, TRUE);

        /* A DISPOSAL IS ONE MOVEMENT, NOT SEVERAL. Selling an asset clears its
           cost and its accumulated depreciation and leaves a gain or a loss in
           net income, but the only cash that moved is what the buyer paid. So
           the disposal's own lines are taken out of the account movements, the
           gain or loss is taken back out of net income, and the proceeds are
           shown where they belong: investing. The total is unchanged — the
           lines of one entry add to zero — only where it sits. */
        $disposal = [];
        foreach ($this->CI->db->query(
            "SELECT l.account_id, COALESCE(SUM(l.net_cents), 0) AS n FROM gp_ledger l
               LEFT JOIN gp_journals o ON l.source = 'reversal' AND o.id = l.source_id
              WHERE l.entry_date >= ? AND l.entry_date <= ? AND (l.source = 'disposal' OR o.source = 'disposal')
              GROUP BY l.account_id", [$from, $to]
        )->result_array() as $r) $disposal[(int) $r['account_id']] = (int) $r['n'];

        $proceeds = 0;
        foreach ($disposal as $id => $n) if (isset($is_cash[$id])) $proceeds += $n;

        $ni = 0;
        $gain_loss = 0;
        $groups = ['depreciation' => [], 'operating' => [], 'investing' => [], 'financing' => []];
        foreach ($this->acc as $id => $a) {
            if ($a['is_header']) continue;
            $v = $flow[$id] ?? 0;
            if (in_array($a['type'], self::PL, TRUE)) { $ni -= $v; $gain_loss += $disposal[$id] ?? 0; continue; }
            if (isset($is_cash[$id])) continue;
            $v -= $disposal[$id] ?? 0;
            if ($v === 0) continue;
            $class = $this->_cf_class($a);
            $key = $class === 'depreciation' ? 'all' : ((int) $levels === 0 ? $id : $this->_cf_group($id));
            $groups[$class][$key] = ($groups[$class][$key] ?? 0) - $v;
        }

        $L = [['type' => 'heading', 'label' => 'Cash flows from operating activities', 'indent' => 0],
              ['type' => 'computed', 'label' => $w['net'], 'indent' => 1, 'amounts' => [$ni], 'strong' => FALSE]];
        $op = $ni;
        if ($groups['depreciation']) {
            $d = array_sum($groups['depreciation']);
            $L[] = ['type' => 'computed', 'label' => 'Depreciation and amortization', 'indent' => 1, 'amounts' => [$d], 'strong' => FALSE];
            $op += $d;
        }
        if ($gain_loss !== 0) {
            $L[] = ['type' => 'computed', 'label' => 'Loss (gain) on the disposal of property and equipment', 'indent' => 1, 'amounts' => [$gain_loss], 'strong' => FALSE];
            $op += $gain_loss;
        }
        foreach ($groups['operating'] as $key => $v) { $L[] = $this->_cf_line($key, $v, TRUE); $op += $v; }
        $L[] = ['type' => 'total', 'label' => 'Net cash from (used in) operating activities', 'indent' => 0, 'amounts' => [$op]];

        $sum = ['investing' => 0, 'financing' => 0];
        foreach (['investing' => 'Cash flows from investing activities', 'financing' => 'Cash flows from financing activities'] as $k => $title) {
            $L[] = ['type' => 'heading', 'label' => $title, 'indent' => 0];
            if ($k === 'investing' && $proceeds !== 0) {
                $L[] = ['type' => 'computed', 'label' => 'Proceeds from the disposal of property and equipment', 'indent' => 1, 'amounts' => [$proceeds], 'strong' => FALSE];
                $sum[$k] += $proceeds;
            }
            foreach ($groups[$k] as $key => $v) { $L[] = $this->_cf_line($key, $v, FALSE); $sum[$k] += $v; }
            $L[] = ['type' => 'total', 'label' => 'Net cash from (used in) ' . $k . ' activities', 'indent' => 0, 'amounts' => [$sum[$k]]];
        }

        $change = $op + $sum['investing'] + $sum['financing'];
        $begin  = 0;
        $finish = 0;
        foreach ($cash as $id) {
            /* Opening entries dated inside the period set starting balances,
               so their cash counts as cash at the beginning. */
            $begin  += ($prior[$id] ?? 0) + (($all[$id] ?? 0) - ($flow[$id] ?? 0));
            $finish += ($prior[$id] ?? 0) + ($all[$id] ?? 0);
        }
        $L[] = ['type' => 'computed', 'label' => 'Net increase (decrease) in cash and cash equivalents', 'indent' => 0, 'amounts' => [$change], 'strong' => TRUE];
        $L[] = ['type' => 'computed', 'label' => 'Cash and cash equivalents at the beginning of the period', 'indent' => 0, 'amounts' => [$begin], 'strong' => FALSE];
        $L[] = ['type' => 'grand', 'label' => 'Cash and cash equivalents at the end of the period', 'indent' => 0, 'amounts' => [$finish]];

        return [
            'title'   => $w['cf'],
            'columns' => [['from' => $from, 'to' => $to]],
            'lines'   => $L,
            'base'    => NULL,
            'checks'  => ['reconciled' => [$begin + $change === $finish]],
            'totals'  => ['net_income' => $ni, 'operating' => $op, 'investing' => $sum['investing'], 'financing' => $sum['financing'],
                          'change' => $change, 'beginning' => $begin, 'ending' => $finish],
        ];
    }

    private function _cf_class(array $a)
    {
        if (isset($a['tagset']['accum_depr'])) return 'depreciation';
        if (in_array($a['cf'], ['operating', 'investing', 'financing'], TRUE)) return $a['cf'];
        if ($a['type'] === 'equity') return 'financing';
        if ($this->sub_of($a) === 'non_current') return $a['type'] === 'asset' ? 'investing' : 'financing';
        return 'operating';
    }

    /** The line item an account's cash flow is shown under: its header, unless that header is a whole section. */
    private function _cf_group($id)
    {
        $p = $this->acc[$id]['parent_id'];
        return ($p && isset($this->acc[$p]) && $this->acc[$p]['depth'] >= 2) ? $p : $id;
    }

    private function _cf_line($id, $amount, $operating)
    {
        $a = $this->acc[$id];
        $label = $a['name'];
        if ($operating) $label = ($a['type'] === 'asset' ? '(Increase) decrease in ' : 'Increase (decrease) in ') . $a['name'];
        return ['type' => $a['is_header'] ? 'group' : 'account', 'id' => $id, 'code' => $a['code'], 'label' => $label, 'indent' => 1, 'amounts' => [$amount]];
    }

    // =========================================================================
    // LAYING OUT A SECTION
    // =========================================================================

    /** Postable accounts of a type, optionally only some sections of it. */
    private function _members($type, array $subs = NULL)
    {
        $out = [];
        foreach ($this->acc as $id => $a) {
            if ($a['is_header'] || $a['type'] !== $type) continue;
            if ($subs !== NULL && ! in_array($this->sub_of($a), $subs, TRUE)) continue;
            $out[] = $id;
        }
        return $out;
    }

    /**
     * The lines of one section: the chart's headers and accounts that hold any
     * of $members, in chart order, with a subtotal under every open header
     * that has more than one line.
     *
     * A header that is the only thing at the top (the "Assets" header of the
     * asset section) is not repeated: the statement's own caption says it.
     *
     * @param int[]   $members postable accounts in the section
     * @param array[] $cols    per column, [account id => amount] on the section's sign
     * @param int     $levels  0 shows every account; N folds headers N levels down into one line
     * @param bool    $zero    keep ACTIVE accounts that are zero in every column
     * @param int     $indent  indent of the section's first level
     * @return array [lines, total per column]
     */
    private function _section(array $members, array $cols, $levels, $zero, $indent)
    {
        $n   = count($cols);
        $in  = array_fill_keys($members, TRUE);
        $sum = [];
        $vis = [];
        /* Reverse tree order meets every node after all of its descendants. */
        foreach (array_reverse(array_keys($this->acc)) as $id) {
            $a = $this->acc[$id];
            if ( ! $a['is_header']) {
                if ( ! isset($in[$id])) continue;
                $v = [];
                foreach ($cols as $c) $v[] = (int) ($c[$id] ?? 0);
                $sum[$id] = $v;
                $vis[$id] = self::_any($v) || ($zero && (int) $a['is_active']);
                continue;
            }
            $v = NULL;
            $show = FALSE;
            foreach ($this->kids[$id] ?? [] as $k) {
                if ( ! isset($sum[$k])) continue;
                if ($v === NULL) $v = array_fill(0, $n, 0);
                foreach ($sum[$k] as $i => $x) $v[$i] += $x;
                if ( ! empty($vis[$k])) $show = TRUE;
            }
            if ($v !== NULL) { $sum[$id] = $v; $vis[$id] = $show; }
        }

        $visible = function (array $ids) use (&$vis) {
            return array_values(array_filter($ids, function ($k) use (&$vis) { return ! empty($vis[$k]); }));
        };
        $top = $visible($this->kids[0] ?? []);
        while (count($top) === 1 && $this->acc[$top[0]]['is_header']) $top = $visible($this->kids[$top[0]] ?? []);

        $lines = [];
        $emit = function ($id, $depth) use (&$emit, &$lines, &$sum, $visible, $levels, $indent) {
            $a    = $this->acc[$id];
            $line = ['id' => $id, 'code' => $a['code'], 'label' => $a['name'], 'indent' => $indent + $depth];
            if ( ! $a['is_header']) {
                $lines[] = $line + ['type' => 'account', 'amounts' => $sum[$id]];
                return;
            }
            if ($levels > 0 && $depth >= $levels - 1) {
                $lines[] = $line + ['type' => 'group', 'amounts' => $sum[$id]];
                return;
            }
            $kids = $visible($this->kids[$id] ?? []);
            $lines[] = $line + ['type' => 'header'];
            foreach ($kids as $k) $emit($k, $depth + 1);
            if (count($kids) > 1) $lines[] = ['type' => 'subtotal', 'id' => $id, 'code' => $a['code'], 'label' => 'Total ' . $a['name'], 'indent' => $indent + $depth, 'amounts' => $sum[$id]];
        };
        foreach ($top as $id) $emit($id, 0);

        $total = array_fill(0, $n, 0);
        foreach ($top as $id) foreach ($sum[$id] as $i => $x) $total[$i] += $x;
        return [$lines, $total];
    }

    // =========================================================================

    private static function _neg(array $m)
    {
        foreach ($m as $k => $v) $m[$k] = -$v;
        return $m;
    }

    private static function _any(array $v)
    {
        foreach ($v as $x) if ($x !== 0) return TRUE;
        return FALSE;
    }

    private static function _add(array ...$vs)
    {
        $out = array_fill(0, count($vs[0]), 0);
        foreach ($vs as $v) foreach ($v as $i => $x) $out[$i] += $x;
        return $out;
    }

    private static function _sub(array $a, array $b)
    {
        foreach ($a as $i => $x) $a[$i] = $x - $b[$i];
        return $a;
    }
}
