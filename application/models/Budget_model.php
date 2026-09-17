<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Budget_model.php — budgets and their lines
 *
 * GenericPOS Accounting · tables gp_budgets, gp_budget_lines (budgets and departments module)
 *
 * ─── WHAT A LINE MEANS ────────────────────────────────────────────────────
 * One amount per account, department and month (period_no 1–12 of the
 * budget's fiscal year), in whole centavos, in the account's NORMAL direction:
 * a positive revenue budget is a credit, a positive expense budget a debit, a
 * positive budget on a contra account (sales returns) a reduction. Amounts are
 * never below zero; a zero is simply no line. department_id 0 is company-wide.
 *
 * On a statement the sign follows the statement: income credit-positive,
 * expenses debit-positive, so a contra account's budget counts against its
 * section — exactly as its actual does (sign()).
 *
 * ─── LIFE ─────────────────────────────────────────────────────────────────
 *   draft ⇄ approved    approve (it becomes read-only) · return to draft, with a reason
 *   primary             one budget per fiscal year: the one reports and the
 *                       dashboard use by default. Only an approved budget becomes primary.
 *   delete              drafts only
 * Every change is audited inside its own transaction — no change without its record.
 *
 * Budgets never post: nothing here writes to the ledger.
 */
class Budget_model extends CI_Model
{
    const T = 'gp_budgets';
    const L = 'gp_budget_lines';

    const MAX_CELLS  = 20000;
    const MAX_AMOUNT = 99999999999999;   // 999,999,999,999.99 — far beyond any budget line

    // =========================================================================
    // READ
    // =========================================================================

    private function _select()
    {
        return 'SELECT b.*, fy.name AS fiscal_year_name, fy.start_date AS fy_start, fy.end_date AS fy_end, fy.status AS fy_status,
                       u.full_name AS created_by_name, u.username AS created_by_username
                  FROM gp_budgets b
                  JOIN gp_fiscal_years fy ON fy.id = b.fiscal_year_id
             LEFT JOIN gp_users u ON u.id = b.created_by';
    }

    public function find($id)
    {
        return $this->db->query($this->_select() . ' WHERE b.id = ?', [(int) $id])->row_array() ?: NULL;
    }

    /** Every budget, newest fiscal year first, the primary one first within its year. */
    public function all()
    {
        return $this->db->query($this->_select() . ' ORDER BY fy.start_date DESC, b.is_primary DESC, b.name ASC')->result_array();
    }

    /** The budget reports use when none is named: this year's primary, else this year's latest, else the latest. */
    public function default_budget($today)
    {
        $b = $this->db->query($this->_select() . ' WHERE fy.start_date <= ? AND fy.end_date >= ?
                                ORDER BY b.is_primary DESC, b.updated_at DESC, b.id DESC LIMIT 1', [$today, $today])->row_array();
        if ($b) return $b;
        return $this->db->query($this->_select() . ' ORDER BY b.updated_at DESC, b.id DESC LIMIT 1')->row_array() ?: NULL;
    }

    /** [[account_id, department_id, period_no, amount_cents], …] */
    public function lines($budget_id)
    {
        $out = [];
        foreach ($this->db->query('SELECT account_id, department_id, period_no, amount_cents FROM gp_budget_lines WHERE budget_id = ?
                                    ORDER BY department_id, account_id, period_no', [(int) $budget_id])->result_array() as $r) {
            $out[] = [(int) $r['account_id'], (int) $r['department_id'], (int) $r['period_no'], (int) $r['amount_cents']];
        }
        return $out;
    }

    /**
     * Totals on the statement's sign: income, expenses and net, and how many lines.
     * @return array [budget id => [income_cents, expense_cents, net_cents, line_count]]
     */
    public function totals(array $ids)
    {
        $out = [];
        foreach ($ids as $id) $out[(int) $id] = ['income_cents' => 0, 'expense_cents' => 0, 'net_cents' => 0, 'line_count' => 0];
        if ( ! $out) return $out;
        $in = implode(',', array_map('intval', array_keys($out)));
        foreach ($this->db->query(
            "SELECT bl.budget_id, a.type, COUNT(*) AS n,
                    SUM(CASE WHEN a.normal_side = IF(a.type = 'income', 'C', 'D') THEN bl.amount_cents ELSE -bl.amount_cents END) AS amt
               FROM gp_budget_lines bl JOIN gp_accounts a ON a.id = bl.account_id
              WHERE bl.budget_id IN (" . $in . ")
              GROUP BY bl.budget_id, a.type"
        )->result_array() as $r) {
            $t =& $out[(int) $r['budget_id']];
            $t['line_count'] += (int) $r['n'];
            if ($r['type'] === 'income')  $t['income_cents']  += (int) $r['amt'];
            if ($r['type'] === 'expense') $t['expense_cents'] += (int) $r['amt'];
            $t['net_cents'] = $t['income_cents'] - $t['expense_cents'];
            unset($t);
        }
        return $out;
    }

    /** The budget's history, from the audit log. */
    public function trail($budget_id)
    {
        $out = [];
        foreach ($this->db->query(
            "SELECT a.action, a.detail, a.occurred_at, a.admin_id, u.full_name, u.username
               FROM gp_admin_audit_log a LEFT JOIN gp_users u ON u.id = a.admin_id
              WHERE a.target_type = 'budget' AND a.target_id = ? ORDER BY a.id", [(int) $budget_id]
        )->result_array() as $r) {
            $d = $r['detail'] !== NULL ? json_decode($r['detail'], TRUE) : NULL;
            $out[] = ['action' => $r['action'], 'at' => $r['occurred_at'], 'who' => $r['full_name'] ?: ($r['username'] ?: '#' . (int) $r['admin_id']),
                      'detail' => is_array($d) ? $d : NULL];
        }
        return $out;
    }

    public function shape(array $b, array $totals = NULL)
    {
        return [
            'id'              => (int) $b['id'],
            'name'            => $b['name'],
            'status'          => $b['status'],
            'is_primary'      => (bool) (int) $b['is_primary'],
            'notes'           => (string) ($b['notes'] ?? ''),
            'fiscal_year_id'  => (int) $b['fiscal_year_id'],
            'fiscal_year'     => ['id' => (int) $b['fiscal_year_id'], 'name' => $b['fiscal_year_name'], 'start_date' => $b['fy_start'],
                                  'end_date' => $b['fy_end'], 'status' => $b['fy_status']],
            'created_by_name' => $b['created_by_name'] ?: ($b['created_by_username'] ?: NULL),
            'created_at'      => $b['created_at'],
            'updated_at'      => $b['updated_at'],
            'totals'          => $totals ?: ['income_cents' => 0, 'expense_cents' => 0, 'net_cents' => 0, 'line_count' => 0],
        ];
    }

    /** +1 when a positive budget on this account adds to its statement section, -1 for a contra account. */
    public static function sign(array $a)
    {
        return $a['normal_side'] === ($a['type'] === 'income' ? 'C' : 'D') ? 1 : -1;
    }

    /** Round $num / $den half away from zero, in integers. */
    public static function round_div($num, $den)
    {
        $num = (int) $num;
        $den = (int) $den;
        $q = intdiv(abs($num) * 2 + abs($den), 2 * abs($den));
        return (($num < 0) xor ($den < 0)) ? -$q : $q;
    }

    // =========================================================================
    // THE GRID: rows grouped like the income statement
    // =========================================================================

    /**
     * The income and expense sections in the income statement's order, each
     * with its headers and accounts in chart order. An account shows when it
     * is active and postable, or when this budget has lines on it (shown as
     * inactive). A lone header at the top of a section is not repeated: the
     * section's own caption says it (Statement_lib does the same).
     *
     * @param  Statement_lib $S           for the chart, the sections and the words
     * @param  array         $with_lines  account ids this budget has lines on (as keys)
     * @return array [[key, title, type, rows: [[kind header|account, id, code, name, depth, sign, active]]]]
     */
    public function grid_sections($S, array $with_lines = [])
    {
        $chart = $S->chart();
        $w     = $S->words();
        $coop  = $S->is_coop();
        $kids  = [];
        foreach ($chart as $id => $a) $kids[(int) $a['parent_id']][] = $id;

        $list = [
            ['revenue', 'income', 'operating', $w['revenue']],
            ['cost_of_sales', 'expense', 'cost_of_sales', $w['cos']],
        ];
        $opx = ['operating_expenses', 'expense', 'operating', $coop ? 'Operating and administrative costs' : 'Operating expenses'];
        $fin = ['finance', 'expense', 'finance', $coop ? 'Financing costs' : 'Finance costs'];
        if ($coop) { $list[] = $fin; $list[] = $opx; } else { $list[] = $opx; $list[] = $fin; }
        $list[] = ['other_income', 'income', 'other', 'Other income'];
        $list[] = ['other_expenses', 'expense', 'other', 'Other expenses'];
        $list[] = ['income_tax', 'expense', 'income_tax', 'Income tax expense'];

        $out = [];
        foreach ($list as list($key, $type, $sub, $title)) {
            $vis = [];
            foreach ($chart as $id => $a) {
                if ($a['is_header'] || $a['type'] !== $type || $S->sub_of($a) !== $sub) continue;
                if ( ! (int) $a['is_active'] && ! isset($with_lines[$id])) continue;
                $vis[$id] = TRUE;
                for ($p = (int) $a['parent_id']; $p && isset($chart[$p]) && ! isset($vis[$p]); $p = (int) $chart[$p]['parent_id']) $vis[$p] = TRUE;
            }
            if ( ! $vis) continue;

            $visible = function ($pid) use (&$kids, &$vis) {
                return array_values(array_filter($kids[$pid] ?? [], function ($k) use (&$vis) { return isset($vis[$k]); }));
            };
            $top = $visible(0);
            while (count($top) === 1 && $chart[$top[0]]['is_header']) $top = $visible($top[0]);

            $rows = [];
            $emit = function ($id, $depth) use (&$emit, &$rows, $chart, $visible) {
                $a = $chart[$id];
                if ($a['is_header']) {
                    $rows[] = ['kind' => 'header', 'id' => $id, 'code' => $a['code'], 'name' => $a['name'], 'depth' => $depth];
                    foreach ($visible($id) as $k) $emit($k, $depth + 1);
                    return;
                }
                $rows[] = ['kind' => 'account', 'id' => $id, 'code' => $a['code'], 'name' => $a['name'], 'depth' => $depth,
                           'sign' => self::sign($a), 'active' => (bool) (int) $a['is_active']];
            };
            foreach ($top as $id) $emit($id, 0);
            $out[] = ['key' => $key, 'title' => $title, 'type' => $type, 'rows' => $rows];
        }
        return $out;
    }

    // =========================================================================
    // VALIDATION
    // =========================================================================

    /**
     * Check a batch of cells before anything is written.
     *
     * @param  array    $cells    [{account_id, department_id, period_no, amount_cents}]
     * @param  string[] $periods  period_no => name ('Jan 2026'), for the messages
     * @return array [clean [[account, department, period, cents]], messages[]]
     */
    public function clean_cells($cells, array $periods)
    {
        if ( ! is_array($cells)) return [[], ['Send the changed amounts as a list of cells.']];
        if (count($cells) > self::MAX_CELLS) return [[], ['Save at most ' . self::MAX_CELLS . ' amounts at a time.']];

        $accts = [];
        foreach ($this->db->select('id, code, name, type, is_header, is_active, normal_side')->get('gp_accounts')->result_array() as $a) $accts[(int) $a['id']] = $a;
        $depts = [];
        foreach ($this->db->select('id, code, name, is_active')->get('gp_departments')->result_array() as $d) $depts[(int) $d['id']] = $d;

        $clean = [];
        $errs  = [];
        $seen  = [];
        foreach (array_values($cells) as $i => $c) {
            if ( ! is_array($c)) { $errs[] = 'Amount ' . ($i + 1) . ' is not a cell.'; continue; }
            $aid = (int) ($c['account_id'] ?? 0);
            $did = (int) ($c['department_id'] ?? 0);
            $p   = $c['period_no'] ?? NULL;
            $v   = $c['amount_cents'] ?? NULL;

            $a     = $accts[$aid] ?? NULL;
            $where = ($a ? $a['code'] . ' ' . $a['name'] : 'Account #' . $aid)
                   . (is_numeric($p) && isset($periods[(int) $p]) ? ', ' . $periods[(int) $p] : '')
                   . ($did ? (isset($depts[$did]) ? ', department ' . $depts[$did]['code'] : '') : ', company-wide');

            if (is_int($v))                                                         $amt = $v;
            elseif (is_string($v) && preg_match('/^\s*-?\d{1,17}\s*$/', $v))       $amt = (int) trim($v);
            elseif (is_float($v) && is_finite($v) && floor($v) === $v && abs($v) < 1e17) $amt = (int) $v;
            else                                                                    $amt = NULL;

            if ( ! $a)                                                  $m = 'that account does not exist';
            elseif ((int) $a['is_header'])                              $m = 'it is a header account; budget the accounts under it';
            elseif ( ! in_array($a['type'], ['income', 'expense'], TRUE)) $m = 'only income and expense accounts take a budget';
            elseif ( ! is_numeric($p) || (int) $p != $p || (int) $p < 1 || (int) $p > 12) $m = 'the month must be period 1 to 12';
            elseif ($did < 0 || ($did > 0 && ! isset($depts[$did])))    $m = 'that department does not exist';
            elseif ($amt === NULL)                                      $m = 'the amount must be a whole number of centavos';
            elseif ($amt < 0)                                           $m = 'a budget amount cannot be below zero — enter what you expect to earn or spend as a positive amount';
            elseif ($amt > self::MAX_AMOUNT)                            $m = 'the amount is too large';
            elseif ($amt > 0 && ! (int) $a['is_active'])                $m = 'the account is inactive; it can only be cleared';
            elseif ($amt > 0 && $did > 0 && ! (int) $depts[$did]['is_active']) $m = 'the department is inactive; it can only be cleared';
            else                                                        $m = '';

            if ($m === '') {
                $k = $aid . ':' . $did . ':' . (int) $p;
                if (isset($seen[$k])) $m = 'the same month appears twice in one save';
                $seen[$k] = TRUE;
            }
            if ($m !== '') { $errs[] = $where . ': ' . $m . '.'; continue; }
            $clean[] = [$aid, $did, (int) $p, $amt];
        }
        return [$clean, $errs];
    }

    /**
     * Name and notes, for a new budget or a change.
     * @return array [data (name, notes), errors by field]
     */
    public function clean_header(array $in, $fiscal_year, $existing = NULL)
    {
        $e = [];
        $name  = array_key_exists('name', $in) ? clean_line($in['name'], 200) : ($existing ? $existing['name'] : '');
        $notes = array_key_exists('notes', $in) ? clean_text($in['notes'], 1000) : ($existing ? (string) $existing['notes'] : '');
        if ($name === '')                   $e['name'] = 'Give the budget a name, such as "Original budget".';
        elseif (mb_strlen($name) > 80)      $e['name'] = 'Keep the name under 80 characters.';
        elseif ($fiscal_year) {
            $dup = $this->db->query('SELECT id FROM gp_budgets WHERE fiscal_year_id = ? AND name = ?' . ($existing ? ' AND id <> ' . (int) $existing['id'] : '') . ' LIMIT 1',
                                    [(int) $fiscal_year['id'], $name])->row_array();
            if ($dup) $e['name'] = $fiscal_year['name'] . ' already has a budget called "' . $name . '". Choose another name.';
        }
        if (mb_strlen($notes) > 500) $e['notes'] = 'Keep the notes under 500 characters.';
        return [['name' => $name, 'notes' => $notes !== '' ? $notes : NULL], $e];
    }

    /** "5", "-2.5", "+10%" → basis points; NULL when it is not a sensible change. */
    public static function uplift_bp($raw)
    {
        $s = str_replace(['%', ' '], '', trim((string) $raw));
        if ($s === '') return 0;
        if ( ! preg_match('/^([+-]?)(\d{1,4})(?:\.(\d{1,2}))?$/', $s, $m)) return NULL;
        $bp = (int) $m[2] * 100 + (int) str_pad($m[3] ?? '', 2, '0');
        if ($m[1] === '-') $bp = -$bp;
        return ($bp <= -10000 || $bp > 100000) ? NULL : $bp;
    }

    /** $cents changed by $bp basis points and rounded to the whole currency unit (whole pesos). */
    public static function uplift_whole($cents, $bp)
    {
        $scale = currency_scale();
        return self::round_div((int) $cents * (10000 + (int) $bp), 10000 * $scale) * $scale;
    }

    // =========================================================================
    // CREATE
    // =========================================================================

    /**
     * A new draft budget: blank, a copy of another budget, or last year's
     * actuals (per account, department and month, closing entries left out),
     * either of the last two changed by an optional percentage.
     *
     * @param  array $in  fiscal_year_id, name, notes, source (blank|copy|actuals), copy_budget_id, uplift_pct
     * @return array [id|0, errors by field, report [lines, skipped reason => count, text]]
     */
    public function create(array $in, array $claims)
    {
        $e  = [];
        $fy = $this->db->get_where('gp_fiscal_years', ['id' => (int) ($in['fiscal_year_id'] ?? 0)], 1)->row_array();
        if ( ! $fy) $e['fiscal_year_id'] = 'Choose the fiscal year the budget is for.';
        list($head, $he) = $this->clean_header($in, $fy);
        $e += $he;

        $source = (string) ($in['source'] ?? 'blank');
        if ( ! in_array($source, ['blank', 'copy', 'actuals'], TRUE)) $e['source'] = 'Choose how to start: blank, a copy of another budget, or last year\'s actuals.';
        $bp = self::uplift_bp($in['uplift_pct'] ?? '');
        if ($bp === NULL) $e['uplift_pct'] = 'Enter the change as a percentage between -99.99 and 1000, such as 5 or -2.5.';

        $src = NULL;
        $prev = NULL;
        if ($source === 'copy') {
            $src = $this->find((int) ($in['copy_budget_id'] ?? 0));
            if ( ! $src) $e['copy_budget_id'] = 'Choose the budget to copy.';
        } elseif ($source === 'actuals' && $fy) {
            $prev = $this->db->get_where('gp_fiscal_years', ['end_date' => date('Y-m-d', strtotime($fy['start_date'] . ' -1 day'))], 1)->row_array();
            if ( ! $prev) $e['source'] = 'There is no fiscal year before ' . $fy['name'] . ', so there are no actuals to start from. Start blank or from a copy instead.';
        }
        if ($e) return [0, $e, NULL];

        list($lines, $skipped) = $source === 'copy' ? $this->_from_budget((int) $src['id'], (int) $bp)
                               : ($source === 'actuals' ? $this->_from_actuals((int) $prev['id'], (int) $bp) : [[], []]);

        $pct = $bp ? rtrim(rtrim(number_format($bp / 100, 2, '.', ''), '0'), '.') : '';
        $what = $source === 'copy' ? 'a copy of ' . $src['fiscal_year_name'] . ' ' . $src['name']
              : ($source === 'actuals' ? $prev['name'] . '\'s actuals' : 'blank');
        if ($pct !== '') $what .= ', ' . ($bp > 0 ? '+' : '') . $pct . '%';

        list($id, $err) = $this->_tx(function () use ($fy, $head, $lines, $source, $src, $prev, $bp, $skipped, $what, $claims) {
            $now = date('Y-m-d H:i:s');
            $this->db->insert(self::T, ['fiscal_year_id' => (int) $fy['id'], 'name' => $head['name'], 'status' => 'draft', 'is_primary' => 0,
                'notes' => $head['notes'], 'created_by' => (int) $claims['user_id'], 'created_at' => $now, 'updated_at' => $now]);
            $id = (int) $this->db->insert_id();
            $this->_upsert($id, $lines);
            $this->_audit($claims, 'budget.create', $id, [
                'name' => $head['name'], 'fiscal_year' => $fy['name'], 'source' => $source, 'started_from' => $what,
                'copy_of' => $src ? (int) $src['id'] : NULL, 'actuals_of' => $prev ? $prev['name'] : NULL,
                'uplift_bp' => (int) $bp, 'lines' => count($lines), 'left_out' => $skipped,
            ]);
            return $id;
        }, [1062 => 'NAME']);
        if ($err === 'NAME') return [0, ['name' => $fy['name'] . ' already has a budget called "' . $head['name'] . '". Choose another name.'], NULL];
        if ( ! $id) return [0, ['_' => $err], NULL];

        return [$id, [], ['lines' => count($lines), 'skipped' => $skipped, 'text' => $this->_report_text($what, count($lines), $skipped)]];
    }

    private function _report_text($what, $n, array $skipped)
    {
        $t = 'Started from ' . $what . ($n ? ': ' . $n . ' amount' . ($n === 1 ? '' : 's') . '.' : '.');
        $words = ['inactive_account' => 'on inactive or non-budget accounts', 'inactive_department' => 'on inactive departments', 'below_zero' => 'below zero'];
        $bits = [];
        foreach ($skipped as $k => $c) if ($c) $bits[] = $c . ' ' . ($words[$k] ?? $k);
        return $t . ($bits ? ' Left out: ' . implode(', ', $bits) . '.' : '');
    }

    /** What may take a budget amount now: [active postable income and expense accounts (id => row), active departments (id => TRUE)]. */
    private function _budgetable()
    {
        $accts = [];
        foreach ($this->db->select('id, normal_side, type')->where('is_header', 0)->where('is_active', 1)->where_in('type', ['income', 'expense'])
                          ->get('gp_accounts')->result_array() as $a) $accts[(int) $a['id']] = $a;
        $depts = [];
        foreach ($this->db->select('id')->where('is_active', 1)->get('gp_departments')->result_array() as $d) $depts[(int) $d['id']] = TRUE;
        return [$accts, $depts];
    }

    private function _from_budget($src_id, $bp)
    {
        list($accts, $depts) = $this->_budgetable();
        $out  = [];
        $skip = ['inactive_account' => 0, 'inactive_department' => 0, 'below_zero' => 0];
        foreach ($this->lines($src_id) as list($a, $d, $p, $c)) {
            if ( ! isset($accts[$a]))         { $skip['inactive_account']++; continue; }
            if ($d > 0 && ! isset($depts[$d])) { $skip['inactive_department']++; continue; }
            $v = $bp ? self::uplift_whole($c, $bp) : $c;
            if ($v > 0) $out[] = [$a, $d, $p, $v];
        }
        return [$out, array_filter($skip)];
    }

    private function _from_actuals($fy_id, $bp)
    {
        list($accts, $depts) = $this->_budgetable();
        $out  = [];
        $skip = ['inactive_account' => 0, 'inactive_department' => 0, 'below_zero' => 0];
        foreach ($this->db->query(
            "SELECT l.account_id, COALESCE(l.department_id, 0) AS department_id, p.period_no,
                    SUM(l.debit_cents) AS dr, SUM(l.credit_cents) AS cr
               FROM gp_ledger l
               JOIN gp_periods p ON p.id = l.period_id
               JOIN gp_accounts a ON a.id = l.account_id
              WHERE l.fiscal_year_id = ? AND a.type IN ('income', 'expense') AND l.book <> 'closing'
              GROUP BY l.account_id, COALESCE(l.department_id, 0), p.period_no", [(int) $fy_id]
        )->result_array() as $r) {
            $a = (int) $r['account_id'];
            $d = (int) $r['department_id'];
            if ((int) $r['dr'] === (int) $r['cr']) continue;
            if ( ! isset($accts[$a]))          { $skip['inactive_account']++; continue; }
            if ($d > 0 && ! isset($depts[$d])) { $skip['inactive_department']++; continue; }
            $c = $accts[$a]['normal_side'] === 'D' ? (int) $r['dr'] - (int) $r['cr'] : (int) $r['cr'] - (int) $r['dr'];
            $v = self::uplift_whole($c, $bp);
            if ($v < 0) { $skip['below_zero']++; continue; }
            if ($v > 0) $out[] = [$a, $d, (int) $r['period_no'], $v];
        }
        return [$out, array_filter($skip)];
    }

    // =========================================================================
    // CHANGE: name and notes, and a batch of cells
    // =========================================================================

    /**
     * Save a batch of cleaned cells (0 deletes the line) and, optionally, the
     * name and notes — a draft only, under a lock on the budget.
     *
     * @return array [['set' => n, 'deleted' => n, 'unchanged' => n, 'header' => [field => [from, to]]] | NULL, error '']
     */
    public function save($id, array $clean, $head, array $claims, $action = 'budget.update', array $extra = [])
    {
        return $this->_tx(function () use ($id, $clean, $head, $claims, $action, $extra) {
            $b = $this->_lock($id);
            if ($b['status'] !== 'draft') throw new DomainException('"' . $b['name'] . '" is approved, so it is read-only. An accountant can return it to draft to change it.');

            $old = [];
            foreach ($this->lines($id) as list($a, $d, $p, $c)) $old[$a . ':' . $d . ':' . $p] = $c;
            $set = [];
            $del = [];
            $same = 0;
            foreach ($clean as list($a, $d, $p, $c)) {
                $k = $a . ':' . $d . ':' . $p;
                $was = $old[$k] ?? 0;
                if ($was === $c) { $same++; continue; }
                if ($c > 0) $set[] = [$a, $d, $p, $c]; else $del[] = [$a, $d, $p];
            }

            $hchg = [];
            if (is_array($head)) {
                foreach (['name', 'notes'] as $f) {
                    if ((string) $b[$f] !== (string) $head[$f]) $hchg[$f] = ['from' => $b[$f], 'to' => $head[$f]];
                }
            }
            $res = ['set' => count($set), 'deleted' => count($del), 'unchanged' => $same, 'header' => $hchg];
            if ( ! $set && ! $del && ! $hchg) return $res;

            $this->_upsert((int) $id, $set);
            $this->_delete_cells((int) $id, $del);
            $upd = ['updated_at' => date('Y-m-d H:i:s')];
            foreach ($hchg as $f => $x) $upd[$f] = $x['to'];
            $this->db->where('id', (int) $id)->update(self::T, $upd);

            $this->_audit($claims, $action, (int) $id, ['name' => $head['name'] ?? $b['name'], 'set' => count($set), 'deleted' => count($del)] + $hchg + $extra);
            return $res;
        }, [1062 => 'That name is already used by another budget of the same fiscal year.']);
    }

    // =========================================================================
    // THE WORKFLOW
    // =========================================================================

    /** @return string error, '' on success */
    public function approve($id, array $claims)
    {
        list(, $err) = $this->_tx(function () use ($id, $claims) {
            $b = $this->_lock($id);
            if ($b['status'] !== 'draft') throw new DomainException('"' . $b['name'] . '" is already approved.');
            $t = $this->totals([(int) $id])[(int) $id];
            if ( ! $t['line_count']) throw new DomainException('"' . $b['name'] . '" has no amounts yet. Enter the budget before approving it.');
            $this->db->where('id', (int) $id)->update(self::T, ['status' => 'approved', 'updated_at' => date('Y-m-d H:i:s')]);
            $this->_audit($claims, 'budget.approve', (int) $id, ['name' => $b['name'], 'fiscal_year' => $b['fiscal_year_name']] + $t);
            return TRUE;
        });
        return $err;
    }

    /** @return string error, '' on success */
    public function return_to_draft($id, $reason, array $claims)
    {
        list(, $err) = $this->_tx(function () use ($id, $reason, $claims) {
            $b = $this->_lock($id);
            if ($b['status'] !== 'approved') throw new DomainException('"' . $b['name'] . '" is already a draft.');
            $this->db->where('id', (int) $id)->update(self::T, ['status' => 'draft', 'updated_at' => date('Y-m-d H:i:s')]);
            $this->_audit($claims, 'budget.return', (int) $id, ['name' => $b['name'], 'reason' => $reason, 'was_primary' => (bool) (int) $b['is_primary']]);
            return TRUE;
        });
        return $err;
    }

    /** Make this the fiscal year's primary budget. @return string error, '' on success */
    public function set_primary($id, array $claims)
    {
        list(, $err) = $this->_tx(function () use ($id, $claims) {
            $b = $this->_lock($id);
            $year = $this->db->query('SELECT id, name, is_primary FROM gp_budgets WHERE fiscal_year_id = ? FOR UPDATE', [(int) $b['fiscal_year_id']])->result_array();
            if ((int) $b['is_primary']) throw new DomainException('"' . $b['name'] . '" is already the primary budget for ' . $b['fiscal_year_name'] . '.');
            if ($b['status'] !== 'approved') throw new DomainException('Approve "' . $b['name'] . '" before making it the primary budget.');
            $was = NULL;
            foreach ($year as $y) if ((int) $y['is_primary']) $was = $y;
            $now = date('Y-m-d H:i:s');
            $this->db->where('fiscal_year_id', (int) $b['fiscal_year_id'])->where('id <>', (int) $id)->where('is_primary', 1)->update(self::T, ['is_primary' => 0, 'updated_at' => $now]);
            $this->db->where('id', (int) $id)->update(self::T, ['is_primary' => 1, 'updated_at' => $now]);
            $this->_audit($claims, 'budget.primary', (int) $id, ['name' => $b['name'], 'fiscal_year' => $b['fiscal_year_name'],
                'previous_id' => $was ? (int) $was['id'] : NULL, 'previous_name' => $was ? $was['name'] : NULL]);
            return TRUE;
        });
        return $err;
    }

    /** @return string error, '' on success */
    public function delete($id, array $claims)
    {
        list(, $err) = $this->_tx(function () use ($id, $claims) {
            $b = $this->_lock($id);
            if ($b['status'] !== 'draft') throw new DomainException('"' . $b['name'] . '" is approved. Return it to draft before deleting it.');
            $t = $this->totals([(int) $id])[(int) $id];
            $this->db->where('id', (int) $id)->delete(self::T);
            $this->_audit($claims, 'budget.delete', (int) $id, ['name' => $b['name'], 'fiscal_year' => $b['fiscal_year_name'],
                'was_primary' => (bool) (int) $b['is_primary']] + $t);
            return TRUE;
        });
        return $err;
    }

    // =========================================================================
    // CSV IMPORT: the file the grid exports, read back into one department
    // =========================================================================

    /**
     * Read an exported grid: a heading row "Code, Account, <12 months>, Total",
     * then one row per account. Months are matched by position; the Total
     * column and rows without a code (the totals) are ignored. An account in
     * the file has all twelve months set (a blank is zero); accounts not in
     * the file keep what they had.
     *
     * @return array [cells [[account, department, period, cents]], report [rows, used, skipped [[row, code, reason]]], error '']
     */
    public function parse_import($text, $department_id, array $periods)
    {
        $text = (string) $text;
        if (strncmp($text, "\xEF\xBB\xBF", 3) === 0) $text = substr($text, 3);
        if (trim($text) === '') return [[], NULL, 'The file is empty.'];
        if ( ! mb_check_encoding($text, 'UTF-8')) $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');

        $rows = NULL;
        $head = -1;
        foreach ([',', ';', "\t"] as $sep) {
            $fh = fopen('php://temp', 'r+');
            fwrite($fh, $text);
            rewind($fh);
            $try = [];
            while (($r = fgetcsv($fh, 0, $sep, '"', '')) !== FALSE) {
                $try[] = $r;
                if (count($try) > 5000) break;
            }
            fclose($fh);
            foreach ($try as $i => $r) {
                if (count($r) >= 14 && strcasecmp(self::_cell($r[0]), 'Code') === 0 && strcasecmp(self::_cell($r[1]), 'Account') === 0) { $head = $i; break; }
            }
            if ($head >= 0) { $rows = $try; break; }
        }
        if ($rows === NULL) {
            return [[], NULL, 'The file has no heading row with Code, Account and the twelve months. Export the budget first and fill in that file.'];
        }

        $accts = [];
        foreach ($this->db->select('id, code, name, type, is_header, is_active')->get('gp_accounts')->result_array() as $a) $accts[strtoupper($a['code'])] = $a;

        $cells = [];
        $skip  = [];
        $seen  = [];
        $read  = 0;
        for ($i = $head + 1; $i < count($rows); $i++) {
            $r    = $rows[$i];
            $code = self::_cell($r[0] ?? '');
            if ($code === '') continue;                  // a blank row, or a total
            $read++;
            $line = $i + 1;
            $a = $accts[strtoupper($code)] ?? NULL;
            if ( ! $a)                                                     { $skip[] = [$line, $code, 'No account has this code.']; continue; }
            if ((int) $a['is_header'])                                     { $skip[] = [$line, $code, $a['name'] . ' is a header account; budget the accounts under it.']; continue; }
            if ( ! in_array($a['type'], ['income', 'expense'], TRUE))      { $skip[] = [$line, $code, $a['name'] . ' is not an income or expense account.']; continue; }
            if ( ! (int) $a['is_active'])                                  { $skip[] = [$line, $code, $a['name'] . ' is inactive.']; continue; }
            if (isset($seen[$a['id']]))                                    { $skip[] = [$line, $code, 'The account appears more than once; only its first row was used.']; continue; }

            $vals = [];
            $bad  = '';
            for ($p = 1; $p <= 12; $p++) {
                $raw = self::_cell($r[$p + 1] ?? '');
                $pn  = $periods[$p] ?? ('month ' . $p);
                if ($raw === '' || $raw === '-' || $raw === '–') { $vals[$p] = 0; continue; }
                $v = money_cents($raw, TRUE);
                if ($v === NULL) { $bad = $pn . ' is not an amount ("' . mb_substr($raw, 0, 20) . '").'; break; }
                if ($v < 0)      { $bad = $pn . ' is below zero; a budget amount cannot be negative.'; break; }
                if ($v > self::MAX_AMOUNT) { $bad = $pn . ' is too large.'; break; }
                $vals[$p] = $v;
            }
            if ($bad !== '') { $skip[] = [$line, $code, $bad . ' Nothing from this row was used.']; continue; }

            $seen[$a['id']] = TRUE;
            foreach ($vals as $p => $v) $cells[] = [(int) $a['id'], (int) $department_id, $p, $v];
        }
        return [$cells, ['rows' => $read, 'used' => count($seen), 'skipped' => $skip], ''];
    }

    private static function _cell($v)
    {
        $s = trim((string) $v);
        return (isset($s[0]) && $s[0] === "'") ? trim(substr($s, 1)) : $s;
    }

    // =========================================================================
    // FIGURES FOR THE REPORTS AND THE DASHBOARD
    // =========================================================================

    /**
     * Debit-positive budget per account over periods [$from_p, $to_p] — the
     * shape Statement_lib::net() returns, so the income statement can lay a
     * budget out exactly as it lays out the ledger.
     *
     * @param int|NULL $department  NULL every line · 0 company-wide lines · N one department
     */
    public function statement_map($budget_id, $from_p, $to_p, $department = NULL)
    {
        $sql  = "SELECT bl.account_id, a.normal_side, SUM(bl.amount_cents) AS amt
                   FROM gp_budget_lines bl JOIN gp_accounts a ON a.id = bl.account_id
                  WHERE bl.budget_id = ? AND bl.period_no BETWEEN ? AND ? AND a.type IN ('income', 'expense')";
        $args = [(int) $budget_id, (int) $from_p, (int) $to_p];
        if ($department !== NULL) { $sql .= ' AND bl.department_id = ?'; $args[] = (int) $department; }
        $out = [];
        foreach ($this->db->query($sql . ' GROUP BY bl.account_id, a.normal_side', $args)->result_array() as $r) {
            $out[(int) $r['account_id']] = $r['normal_side'] === 'D' ? (int) $r['amt'] : -(int) $r['amt'];
        }
        return $out;
    }

    /**
     * "Budget this year" for the dashboard: income and expenses from the start
     * of the fiscal year to today against the primary budget over the same
     * stretch — the months gone by in full, the current month for the days
     * gone by (a day's share, rounded to the centavo).
     *
     * @return array|NULL NULL when today's fiscal year has no primary budget
     */
    public function year_summary($today)
    {
        $fy = $this->db->where('start_date <=', $today)->where('end_date >=', $today)->limit(1)->get('gp_fiscal_years')->row_array();
        if ( ! $fy) return NULL;
        $b = $this->db->query($this->_select() . ' WHERE b.fiscal_year_id = ? AND b.is_primary = 1 LIMIT 1', [(int) $fy['id']])->row_array();
        if ( ! $b) return NULL;

        $by = [];   // period_no => [income, expense] on the statement's sign
        foreach ($this->db->query(
            "SELECT bl.period_no, a.type,
                    SUM(CASE WHEN a.normal_side = IF(a.type = 'income', 'C', 'D') THEN bl.amount_cents ELSE -bl.amount_cents END) AS amt
               FROM gp_budget_lines bl JOIN gp_accounts a ON a.id = bl.account_id
              WHERE bl.budget_id = ? AND a.type IN ('income', 'expense') GROUP BY bl.period_no, a.type", [(int) $b['id']]
        )->result_array() as $r) {
            $by[(int) $r['period_no']][$r['type']] = (int) $r['amt'];
        }

        $bi = 0; $be = 0; $yi = 0; $ye = 0;
        $current = NULL;
        foreach ($this->db->where('fiscal_year_id', (int) $fy['id'])->order_by('period_no')->get('gp_periods')->result_array() as $p) {
            $i = $by[(int) $p['period_no']]['income'] ?? 0;
            $x = $by[(int) $p['period_no']]['expense'] ?? 0;
            $yi += $i;
            $ye += $x;
            if ($p['end_date'] <= $today) { $bi += $i; $be += $x; continue; }
            if ($p['start_date'] <= $today) {
                $days = (int) round((strtotime($today) - strtotime($p['start_date'])) / 86400) + 1;
                $of   = (int) round((strtotime($p['end_date']) - strtotime($p['start_date'])) / 86400) + 1;
                $bi  += self::round_div($i * $days, $of);
                $be  += self::round_div($x * $days, $of);
                $current = ['name' => $p['name'], 'days' => $days, 'of' => $of];
            }
        }

        $ai = 0; $ae = 0;
        foreach ($this->db->query(
            "SELECT a.type, SUM(l.net_cents) AS net FROM gp_ledger l JOIN gp_accounts a ON a.id = l.account_id
              WHERE a.type IN ('income', 'expense') AND l.book <> 'closing' AND l.entry_date BETWEEN ? AND ? GROUP BY a.type",
            [$fy['start_date'], $today]
        )->result_array() as $r) {
            if ($r['type'] === 'income') $ai = -(int) $r['net']; else $ae = (int) $r['net'];
        }

        $cmp = function ($actual, $budget, $polarity) {
            $v = $actual - $budget;
            return ['actual_cents' => $actual, 'budget_cents' => $budget, 'variance_cents' => $v,
                    'variance_pct' => $budget !== 0 ? round($v * 100 / abs($budget), 1) : NULL,
                    'favourable' => $v === 0 ? NULL : ($polarity * $v > 0)];
        };
        return [
            'budget'          => ['id' => (int) $b['id'], 'name' => $b['name'], 'status' => $b['status']],
            'fiscal_year'     => ['id' => (int) $fy['id'], 'name' => $fy['name'], 'start_date' => $fy['start_date'], 'end_date' => $fy['end_date']],
            'from'            => $fy['start_date'],
            'to'              => $today,
            'current_period'  => $current,
            'income'          => $cmp($ai, $bi, 1),
            'expenses'        => $cmp($ae, $be, -1),
            'net'             => $cmp($ai - $ae, $bi - $be, 1),
            'full_year'       => ['income_cents' => $yi, 'expense_cents' => $ye, 'net_cents' => $yi - $ye],
        ];
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    /** The budget row, locked for the rest of the transaction; a refusal when it is gone. */
    private function _lock($id)
    {
        $row = $this->db->query('SELECT id FROM gp_budgets WHERE id = ? FOR UPDATE', [(int) $id])->row_array();
        if ( ! $row) throw new DomainException('That budget no longer exists.');
        return $this->find((int) $id);
    }

    private function _upsert($id, array $cells)
    {
        foreach (array_chunk($cells, 400) as $chunk) {
            $args = [];
            foreach ($chunk as $c) array_push($args, (int) $id, (int) $c[0], (int) $c[1], (int) $c[2], (int) $c[3]);
            $this->db->query('INSERT INTO gp_budget_lines (budget_id, account_id, department_id, period_no, amount_cents) VALUES '
                . implode(', ', array_fill(0, count($chunk), '(?, ?, ?, ?, ?)'))
                . ' ON DUPLICATE KEY UPDATE amount_cents = VALUES(amount_cents)', $args);
        }
    }

    private function _delete_cells($id, array $cells)
    {
        foreach (array_chunk($cells, 300) as $chunk) {
            $args = [(int) $id];
            foreach ($chunk as $c) array_push($args, (int) $c[0], (int) $c[1], (int) $c[2]);
            $this->db->query('DELETE FROM gp_budget_lines WHERE budget_id = ? AND ('
                . implode(' OR ', array_fill(0, count($chunk), '(account_id = ? AND department_id = ? AND period_no = ?)')) . ')', $args);
        }
    }

    /**
     * Run $fn in a transaction. A DomainException is a refusal the user can
     * act on; anything else is logged and answered plainly.
     *
     * @param array $sql_messages MySQL error number => message
     * @return array [what $fn returned | NULL, error '']
     */
    private function _tx(callable $fn, array $sql_messages = [])
    {
        $this->db->trans_begin();
        try {
            $out = $fn();
            $this->db->trans_commit();
            return [$out, ''];
        } catch (DomainException $e) {
            $this->db->trans_rollback();
            return [NULL, $e->getMessage()];
        } catch (Throwable $t) {
            $this->db->trans_rollback();
            if (isset($sql_messages[(int) $t->getCode()])) return [NULL, $sql_messages[(int) $t->getCode()]];
            log_message('error', '[Budget_model] ' . $t->getMessage());
            return [NULL, 'The database refused the change, so nothing was saved. Try again.'];
        }
    }

    private function _audit(array $claims, $action, $id, array $detail)
    {
        if ( ! log_admin_action($claims, $action, 'budget', (int) $id, $detail)) {
            throw new DomainException('The audit trail could not be written, so nothing was saved.');
        }
    }
}
