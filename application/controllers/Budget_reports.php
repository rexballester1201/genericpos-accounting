<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Budget_reports.php — budget vs actual, and income by department (every role)
 *
 * GenericPOS Accounting · budgets and departments module
 *
 *   GET /api/v1/reports/budget-vs-actual    ?budget_id=&from_period=&to_period=&department_id=&levels=&zero=1
 *                                            department_id: '' every line · 0 company-wide lines only · N one department
 *   GET /api/v1/reports/department-income   ?from=&to=&levels=&zero=1
 *
 *   &format=csv on either returns the same report as a spreadsheet file.
 *
 * ─── THE FIGURES ARE THE INCOME STATEMENT'S OWN ───────────────────────────
 * Both reports are laid out by Statement_lib::income_statement(), so their
 * rows, subtotals and net income are the income statement's, line for line,
 * and an actual here always equals the income statement for the same range:
 *   · budget vs actual asks for two columns — the ledger's, and the budget's.
 *     Budget_statement hands the budget's figures (debit-positive per
 *     account, the shape net() returns) to the second column instead of the
 *     ledger's, so the budget lands on exactly the same lines.
 *   · income by department asks for one column per department (a range that
 *     carries department_id), one for lines without a department, and the
 *     total — which is the company's income statement.
 *
 * A variance is actual − budget on the statement's sign. It is favourable
 * when income is above its budget or an expense below it.
 */

require_once APPPATH . 'libraries/Statement_lib.php';

/** Statement_lib, with the second column of the next income statement taken from a budget. */
class Budget_statement extends Statement_lib
{
    /** Figures handed back instead of the ledger's, one per net() call, in order; NULL means the ledger. */
    private $queue = [];

    /** @param array $map debit-positive budget per account id */
    public function budget_column(array $map)
    {
        $this->queue = [NULL, $map];
        return $this;
    }

    public function net($from, $to, array $opts = [])
    {
        if ($this->queue) {
            $next = array_shift($this->queue);
            if ($next !== NULL) return $next;
        }
        return parent::net($from, $to, $opts);
    }

    public function income_statement(array $ranges, $levels = 2, $zero = FALSE, $dept = 0)
    {
        try {
            return parent::income_statement($ranges, $levels, $zero, $dept);
        } finally {
            $this->queue = [];
        }
    }
}

class Budget_reports extends CI_Controller
{
    private $stmt = NULL;

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('Period_model', 'periods');
        $this->load->model('Budget_model', 'budgets');
        $this->load->model('Department_model', 'departments');
    }

    /* Statement_lib reads the kind of organisation from Settings, which reach
       the request only after the constructor — so it is built on first use. */
    private function _lib()
    {
        if ($this->stmt === NULL) $this->stmt = new Budget_statement();
        return $this->stmt;
    }

    // =========================================================================
    // BUDGET VS ACTUAL
    // =========================================================================

    /** GET /api/v1/reports/budget-vs-actual */
    public function budget_vs_actual()
    {
        $claims = viewer_check();
        require_method('GET');
        $today = company_today();

        $list = array_map(function ($b) {
            return ['id' => (int) $b['id'], 'name' => $b['name'], 'status' => $b['status'], 'is_primary' => (bool) (int) $b['is_primary'],
                    'fiscal_year_id' => (int) $b['fiscal_year_id'], 'fiscal_year' => $b['fiscal_year_name']];
        }, $this->budgets->all());

        $bid = (int) $this->input->get('budget_id');
        $b   = $bid ? $this->budgets->find($bid) : $this->budgets->default_budget($today);
        if ($bid && ! $b) return json_error('That budget does not exist.', 404);
        if ( ! $b) {
            return json_response(['budget' => NULL, 'budgets' => [], 'letterhead' => report_letterhead($claims)],
                'There is no budget yet. An accountant creates one under Budgets.');
        }

        $periods = [];
        $cur = 0;
        foreach ($this->periods->periods((int) $b['fiscal_year_id']) as $p) {
            $periods[(int) $p['period_no']] = ['no' => (int) $p['period_no'], 'name' => $p['name'], 'start_date' => $p['start_date'], 'end_date' => $p['end_date']];
            if ($p['start_date'] <= $today && $today <= $p['end_date']) $cur = (int) $p['period_no'];
        }
        if ( ! $periods) return json_error('The budget\'s fiscal year has no periods.', 409);
        $last = max(array_keys($periods));

        $fp = (string) $this->input->get('from_period');
        $tp = (string) $this->input->get('to_period');
        $from_p = $fp === '' ? 1 : (int) $fp;
        $to_p   = $tp === '' ? ($cur ?: $last) : (int) $tp;
        $e = [];
        if ( ! isset($periods[$from_p]) || ($fp !== '' && ! ctype_digit($fp))) $e['from_period'] = 'Choose the first month, 1 to ' . $last . '.';
        if ( ! isset($periods[$to_p]) || ($tp !== '' && ! ctype_digit($tp)))   $e['to_period'] = 'Choose the last month, 1 to ' . $last . '.';
        if ( ! $e && $from_p > $to_p) $e['to_period'] = 'The last month is before the first. Choose a later one.';
        if ($e) return json_invalid($e);

        $raw  = trim((string) $this->input->get('department_id'));
        $mode = NULL;
        $dept = NULL;
        if ($raw === '' || $raw === 'all') {
            $raw = '';
        } elseif ($raw === '0' || $raw === 'none') {
            $raw  = '0';
            $mode = 0;
        } elseif (ctype_digit($raw) && ($dept = $this->departments->find((int) $raw))) {
            $mode = (int) $raw;
        } else {
            return json_invalid(['department_id' => 'Choose all departments, company-wide lines only, or one department.']);
        }

        $levels = $this->_levels(2);
        $zero   = (bool) $this->input->get('zero');
        $from   = $periods[$from_p]['start_date'];
        $to     = $periods[$to_p]['end_date'];

        $actual = ['from' => $from, 'to' => $to, 'label' => 'Actual'];
        $budget = ['from' => $from, 'to' => $to, 'label' => 'Budget'];
        if ($mode === 0) { $actual['department_id'] = 'none'; $budget['department_id'] = 'none'; }
        elseif ($mode)   { $actual['department_id'] = $mode;  $budget['department_id'] = $mode; }

        $S = $this->_lib();
        $s = $S->budget_column($this->budgets->statement_map((int) $b['id'], $from_p, $to_p, $mode))
               ->income_statement([$actual, $budget], $levels, $zero, 0);
        $s = $this->_variances($s, $S->chart());
        $w = $S->words();

        $t   = $s['totals'];
        $inc = [$t['revenue'][0] + $t['other_income'][0], $t['revenue'][1] + $t['other_income'][1]];
        $exp = [0, 0];
        foreach (['cost_of_sales', 'operating_expenses', 'finance', 'other_expenses', 'tax'] as $k) { $exp[0] += $t[$k][0]; $exp[1] += $t[$k][1]; }
        $summary = [
            'income'   => ['label' => 'Total income'] + self::_cmp($inc[0], $inc[1], 1),
            'expenses' => ['label' => 'Total expenses'] + self::_cmp($exp[0], $exp[1], -1),
            'net'      => ['label' => $w['income']] + self::_cmp($t['net'][0], $t['net'][1], 1),
        ];

        $dtext = $mode === NULL ? 'All departments' : ($mode === 0 ? 'Company-wide lines only (no department)' : 'Department ' . $dept['code'] . ' · ' . $dept['name']);
        $ptext = $periods[$from_p]['name'] . ($from_p === $to_p ? '' : ' to ' . $periods[$to_p]['name']) . ' (' . $from . ' to ' . $to . ')';

        if (report_csv_wanted($claims)) {
            $fav  = function ($x) { return $x === NULL ? '' : ($x ? 'Favourable' : 'Unfavourable'); };
            $pct  = function ($x) { return $x === NULL ? '' : number_format($x, 1, '.', '') . '%'; };
            $rows = report_csv_head('Budget vs actual: ' . $b['name'] . ($b['status'] === 'draft' ? ' (draft)' : ''), $ptext . ' · ' . $dtext);
            $rows[] = ['Code', 'Particulars', 'Actual', 'Budget', 'Variance', 'Variance %', 'Favourable or unfavourable'];
            foreach ($s['lines'] as $l) {
                $code  = in_array($l['type'], ['account', 'group'], TRUE) ? (string) ($l['code'] ?? '') : '';
                $label = str_repeat('  ', (int) ($l['indent'] ?? 0)) . $l['label'];
                if (empty($l['amounts'])) { $rows[] = [$code, $label]; continue; }
                $rows[] = [$code, $label, money_major($l['amounts'][0]), money_major($l['amounts'][1]), money_major($l['variance']), $pct($l['variance_pct']), $fav($l['favourable'])];
            }
            $rows[] = [];
            foreach ($summary as $x) {
                $rows[] = ['', $x['label'], money_major($x['actual_cents']), money_major($x['budget_cents']), money_major($x['variance_cents']), $pct($x['variance_pct']), $fav($x['favourable'])];
            }
            return report_csv('budget-vs-actual-' . $b['fiscal_year_name'] . '-' . slugify($b['name'], 40) . '-p' . $from_p . '-' . $to_p . '.csv', $rows);
        }

        $depts = array_map(function ($d) {
            return ['id' => (int) $d['id'], 'code' => $d['code'], 'name' => $d['name'], 'is_active' => (bool) (int) $d['is_active'], 'depth' => (int) $d['depth']];
        }, $this->departments->tree());

        return json_response([
            'title'           => 'Budget vs actual',
            'statement_title' => $s['title'],
            'columns'         => $s['columns'],
            'lines'           => $s['lines'],
            'checks'          => $s['checks'],
            'summary'         => $summary,
            'budget'          => $this->budgets->shape($b, $this->budgets->totals([(int) $b['id']])[(int) $b['id']]),
            'budgets'         => $list,
            'periods'         => array_values($periods),
            'current_period'  => $cur ?: NULL,
            'departments'     => $depts,
            'department_text' => $dtext,
            'params'          => ['budget_id' => (int) $b['id'], 'from_period' => $from_p, 'to_period' => $to_p, 'department_id' => $raw,
                                  'levels' => $levels, 'zero' => $zero, 'from' => $from, 'to' => $to],
            'words'           => $w,
            'letterhead'      => report_letterhead($claims),
        ], 'Budget vs actual');
    }

    /**
     * Each line with an amount gets its variance, variance % and favourable
     * flag. Income lines (and the profit lines computed from them) are better
     * above budget; expense lines, and a computed "Total expenses", below it.
     */
    private function _variances(array $s, array $chart)
    {
        $pol = 1;
        foreach ($s['lines'] as $i => $l) {
            if ($l['type'] === 'heading') { $pol = 1; continue; }
            if (isset($l['id']) && isset($chart[$l['id']])) $pol = $chart[$l['id']]['type'] === 'income' ? 1 : -1;
            if (empty($l['amounts'])) continue;
            $lp = $pol;
            if ($l['type'] === 'computed') $lp = strpos((string) $l['label'], 'Total') === 0 ? -1 : 1;
            elseif ($l['type'] === 'grand') $lp = 1;
            $c = self::_cmp((int) $l['amounts'][0], (int) $l['amounts'][1], $lp);
            $s['lines'][$i] += ['variance' => $c['variance_cents'], 'variance_pct' => $c['variance_pct'], 'favourable' => $c['favourable'], 'polarity' => $lp];
        }
        return $s;
    }

    private static function _cmp($actual, $budget, $polarity)
    {
        $v = $actual - $budget;
        return ['actual_cents' => $actual, 'budget_cents' => $budget, 'variance_cents' => $v,
                'variance_pct' => $budget !== 0 ? round($v * 100 / abs($budget), 1) : NULL,
                'favourable'   => $v === 0 ? NULL : ($polarity * $v > 0)];
    }

    // =========================================================================
    // INCOME BY DEPARTMENT
    // =========================================================================

    /** GET /api/v1/reports/department-income */
    public function department_income()
    {
        $claims = viewer_check();
        require_method('GET');

        list($from, $to) = $this->_range();
        $levels = $this->_levels(2);
        $zero   = (bool) $this->input->get('zero');

        /* Every active department, and an inactive one that still has income
           or expenses in the range — so the columns always add up to the total. */
        $act  = $this->departments->activity($from, $to);
        $cols = [];
        foreach ($this->departments->tree() as $d) {
            $id = (int) $d['id'];
            if ( ! (int) $d['is_active'] && ! isset($act[$id])) continue;
            $cols[] = ['id' => $id, 'code' => $d['code'], 'name' => $d['name'], 'is_active' => (bool) (int) $d['is_active'],
                       'depth' => (int) $d['depth'], 'parent_id' => $d['parent_id'] !== NULL ? (int) $d['parent_id'] : NULL];
        }
        $ranges = [];
        foreach ($cols as $c) $ranges[] = ['from' => $from, 'to' => $to, 'department_id' => $c['id'], 'label' => $c['code']];
        $ranges[] = ['from' => $from, 'to' => $to, 'department_id' => 'none', 'label' => 'No department'];
        $ranges[] = ['from' => $from, 'to' => $to, 'total' => TRUE, 'label' => 'Total'];

        $s = $this->_lib()->income_statement($ranges, $levels, $zero, 0);

        $adds = TRUE;
        foreach ($s['lines'] as $l) {
            if (empty($l['amounts'])) continue;
            $k = count($l['amounts']) - 1;
            if (array_sum(array_slice($l['amounts'], 0, $k)) !== $l['amounts'][$k]) { $adds = FALSE; break; }
        }
        $s['checks']['columns_add_up'] = [$adds];

        if (report_csv_wanted($claims)) {
            $rows = report_csv_head($s['title'] . ' by department', 'For ' . $from . ' to ' . $to);
            $rows[] = array_merge(['Code', 'Particulars'], array_map(function ($c) { return $c['code'] . ' ' . $c['name'] . ($c['is_active'] ? '' : ' (inactive)'); }, $cols),
                                  ['No department', 'Total']);
            foreach ($s['lines'] as $l) {
                $code = in_array($l['type'], ['account', 'group'], TRUE) ? (string) ($l['code'] ?? '') : '';
                $row  = [$code, str_repeat('  ', (int) ($l['indent'] ?? 0)) . $l['label']];
                if ( ! empty($l['amounts'])) foreach ($l['amounts'] as $c) $row[] = money_major((int) $c);
                $rows[] = $row;
            }
            return report_csv('income-by-department-' . $from . '-to-' . $to . '.csv', $rows);
        }

        return json_response($s + [
            'departments' => $cols,
            'params'      => ['from' => $from, 'to' => $to, 'levels' => $levels, 'zero' => $zero],
            'letterhead'  => report_letterhead($claims),
        ], $s['title'] . ' by department');
    }

    // =========================================================================
    // SHARED
    // =========================================================================

    private function _date($key, $default)
    {
        $v = (string) $this->input->get($key);
        return Period_model::valid_date($v) ? $v : $default;
    }

    /** [from, to]; the start defaults to the first day of the fiscal year that holds the end. */
    private function _range()
    {
        $to   = $this->_date('to', company_today());
        $from = (string) $this->input->get('from');
        if ( ! Period_model::valid_date($from)) {
            $fy   = $this->periods->year_for_date($to);
            $from = $fy ? $fy['start_date'] : substr($to, 0, 4) . '-01-01';
        }
        if ($from > $to) json_invalid(['from' => 'The start date is after the end date.']);
        return [$from, $to];
    }

    private function _levels($default)
    {
        $v = $this->input->get('levels');
        return ($v !== NULL && $v !== '' && in_array((int) $v, [0, 1, 2, 3], TRUE)) ? (int) $v : $default;
    }
}
