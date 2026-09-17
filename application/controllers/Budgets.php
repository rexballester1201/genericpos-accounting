<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Budgets.php — budgets: the list, one budget's grid, and its workflow
 *
 * GenericPOS Accounting · budgets and departments module
 *
 *   GET    /api/v1/budgets                  every budget with its totals, and the fiscal years (every role)
 *   GET    /api/v1/budgets/summary          "budget this year" for the dashboard (every role)
 *   GET    /api/v1/budgets/{id}             the grid: sections, periods, departments, lines, history (every role)
 *   GET    /api/v1/budgets/{id}/export      ?department_id=all|0|N — the grid as a CSV file (every role)
 *   POST   /api/v1/budgets                  { fiscal_year_id, name, notes, source: blank|copy|actuals,
 *                                            copy_budget_id, uplift_pct } (accountants)
 *   PUT    /api/v1/budgets/{id}             { name?, notes?, lines?: [{account_id, department_id, period_no,
 *                                            amount_cents}] } — a draft; 0 clears a cell (accountants)
 *   POST   /api/v1/budgets/{id}/import      multipart file (or csv text) + department_id — a draft (accountants)
 *   POST   /api/v1/budgets/{id}/approve     draft → approved, read-only (accountants)
 *   POST   /api/v1/budgets/{id}/return      approved → draft, { reason } (accountants)
 *   POST   /api/v1/budgets/{id}/primary     the fiscal year's primary budget (accountants)
 *   DELETE /api/v1/budgets/{id}             a draft (accountants)
 *
 * Budgets never post. The rules live in Budget_model and answer with a reason.
 */
class Budgets extends CI_Controller
{
    const MAX_UPLOAD = 2097152;

    private $stmt = NULL;

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('Budget_model', 'budgets');
        $this->load->model('Period_model', 'periods');
    }

    /* Statement_lib reads the kind of organisation from Settings, which reach
       the request only after the constructor — so it loads on first use. */
    private function _lib()
    {
        if ($this->stmt === NULL) {
            $this->load->library('Statement_lib', NULL, 'statements');
            $this->stmt = $this->statements;
        }
        return $this->stmt;
    }

    /** GET /api/v1/budgets */
    public function index()
    {
        $claims = viewer_check();
        require_method('GET');

        $rows = $this->budgets->all();
        $tot  = $this->budgets->totals(array_column($rows, 'id'));
        $items = array_map(function ($b) use ($tot) { return $this->budgets->shape($b, $tot[(int) $b['id']]); }, $rows);

        $today = company_today();
        $all   = $this->periods->years();
        $ends  = array_column($all, 'end_date');
        $years = array_map(function ($y) use ($today, $ends) {
            return ['id' => (int) $y['id'], 'name' => $y['name'], 'start_date' => $y['start_date'], 'end_date' => $y['end_date'], 'status' => $y['status'],
                    'current' => $y['start_date'] <= $today && $today <= $y['end_date'],
                    'has_previous' => in_array(date('Y-m-d', strtotime($y['start_date'] . ' -1 day')), $ends, TRUE)];
        }, $all);

        return json_response([
            'items'        => $items,
            'fiscal_years' => $years,
            'today'        => $today,
            'can'          => ['create' => role_rank($claims['role']) >= 3],
        ], 'Budgets');
    }

    /** GET /api/v1/budgets/summary */
    public function summary()
    {
        viewer_check();
        require_method('GET');
        return json_response(['summary' => $this->budgets->year_summary(company_today())], 'Budget this year');
    }

    /** GET /api/v1/budgets/{id} */
    public function show($id)
    {
        $claims = viewer_check();
        require_method('GET');
        $d = $this->_detail((int) $id, $claims);
        if ( ! $d) return json_error('That budget does not exist.', 404);
        return json_response($d, 'Budget');
    }

    /** POST /api/v1/budgets */
    public function create()
    {
        $claims = accountant_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        list($id, $e, $report) = $this->budgets->create(get_json_body(), $claims);
        if ( ! $id) {
            if (isset($e['_'])) return json_error($e['_'], 409);
            return json_invalid($e);
        }
        $d = $this->_detail($id, $claims);
        return json_response($d + ['report' => $report], 'Budget "' . $d['budget']['name'] . '" created. ' . $report['text'], 201);
    }

    /** PUT /api/v1/budgets/{id} */
    public function update($id)
    {
        $claims = accountant_check();
        require_method('PUT');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $b = $this->budgets->find((int) $id);
        if ( ! $b) return json_error('That budget does not exist.', 404);
        if ($b['status'] !== 'draft') {
            return json_error('"' . $b['name'] . '" is approved, so it is read-only. An accountant can return it to draft to change it.', 409);
        }

        $in   = get_json_body();
        $head = NULL;
        if (array_key_exists('name', $in) || array_key_exists('notes', $in)) {
            list($head, $he) = $this->budgets->clean_header($in, ['id' => $b['fiscal_year_id'], 'name' => $b['fiscal_year_name']], $b);
            if ($he) return json_invalid($he);
        }
        $clean = [];
        if (array_key_exists('lines', $in)) {
            list($clean, $errs) = $this->budgets->clean_cells($in['lines'], $this->_period_names($b));
            if ($errs) return $this->_cell_errors($errs);
        }
        if ($head === NULL && ! $clean) return json_invalid(['lines' => 'Nothing to save: change some amounts, or the name or notes.']);

        list($res, $err) = $this->budgets->save((int) $id, $clean, $head, $claims);
        if ($res === NULL) return json_error($err, 409);

        return json_response($this->_detail((int) $id, $claims) + ['result' => $res], $this->_saved_text($res));
    }

    /** POST /api/v1/budgets/{id}/import */
    public function import($id)
    {
        $claims = accountant_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $b = $this->budgets->find((int) $id);
        if ( ! $b) return json_error('That budget does not exist.', 404);
        if ($b['status'] !== 'draft') {
            return json_error('"' . $b['name'] . '" is approved, so nothing can be imported into it. Return it to draft first.', 409);
        }

        $in  = get_json_body();
        $raw = (string) ($in['department_id'] ?? '');
        $this->load->model('Department_model', 'departments');
        $dept = ctype_digit($raw) && (int) $raw > 0 ? $this->departments->find((int) $raw) : NULL;
        if ( ! ctype_digit($raw) || ((int) $raw > 0 && ( ! $dept || ! (int) $dept['is_active']))) {
            return json_invalid(['department_id' => 'Choose the department to import into: company-wide, or an active department.']);
        }

        $text = NULL;
        if ( ! empty($_FILES['file']) && is_array($_FILES['file'])) {
            $f = $_FILES['file'];
            if ((int) $f['error'] === UPLOAD_ERR_INI_SIZE || (int) $f['error'] === UPLOAD_ERR_FORM_SIZE || (int) $f['size'] > self::MAX_UPLOAD) {
                return json_invalid(['file' => 'The file is larger than 2 MB. A budget grid is far smaller: export it again and fill that in.']);
            }
            if ((int) $f['error'] !== UPLOAD_ERR_OK || ! is_uploaded_file($f['tmp_name'])) return json_invalid(['file' => 'The file did not arrive. Choose it again.']);
            $text = (string) file_get_contents($f['tmp_name']);
        } elseif (isset($in['csv']) && is_string($in['csv'])) {
            if (strlen($in['csv']) > self::MAX_UPLOAD) return json_invalid(['file' => 'The file is larger than 2 MB.']);
            $text = $in['csv'];
        }
        if ($text === NULL) return json_invalid(['file' => 'Choose the CSV file to import.']);

        $periods = $this->_period_names($b);
        list($cells, $report, $err) = $this->budgets->parse_import($text, (int) $raw, $periods);
        if ($err !== '') return json_invalid(['file' => $err]);

        $label = (int) $raw > 0 ? $dept['code'] . ' · ' . $dept['name'] : 'Company-wide';
        $res = ['set' => 0, 'deleted' => 0, 'unchanged' => 0, 'header' => []];
        if ($cells) {
            $dicts = array_map(function ($c) { return ['account_id' => $c[0], 'department_id' => $c[1], 'period_no' => $c[2], 'amount_cents' => $c[3]]; }, $cells);
            list($clean, $errs) = $this->budgets->clean_cells($dicts, $periods);
            if ($errs) return $this->_cell_errors($errs);
            list($res, $err) = $this->budgets->save((int) $id, $clean, NULL, $claims, 'budget.import', [
                'department' => $label, 'rows' => $report['rows'], 'accounts' => $report['used'], 'skipped' => count($report['skipped']),
            ]);
            if ($res === NULL) return json_error($err, 409);
        }

        $n = $report['used'];
        $s = count($report['skipped']);
        $msg = $n
            ? 'Imported ' . $n . ' account' . ($n === 1 ? '' : 's') . ' into ' . $label . ': ' . $res['set'] . ' amount' . ($res['set'] === 1 ? '' : 's') . ' changed, '
              . $res['deleted'] . ' cleared, ' . $res['unchanged'] . ' already so.'
            : 'Nothing was imported into ' . $label . '.';
        if ($s) $msg .= ' ' . $s . ' row' . ($s === 1 ? ' was' : 's were') . ' skipped; see why below.';

        return json_response($this->_detail((int) $id, $claims) + ['import' => $report + ['result' => $res, 'department' => $label]], $msg);
    }

    /** GET /api/v1/budgets/{id}/export */
    public function export($id)
    {
        $claims = viewer_check();
        require_method('GET');
        rate_limit((int) $claims['user_id'], 'report_export');

        $b = $this->budgets->find((int) $id);
        if ( ! $b) return json_error('That budget does not exist.', 404);

        $raw = (string) $this->input->get('department_id');
        $dept = NULL;
        if ($raw === '' || $raw === 'all') {
            $filter = NULL;
            $label  = 'All departments (totals)';
            $slug   = 'all-departments';
        } elseif (ctype_digit($raw)) {
            $filter = (int) $raw;
            if ($filter > 0) {
                $this->load->model('Department_model', 'departments');
                $dept = $this->departments->find($filter);
                if ( ! $dept) return json_invalid(['department_id' => 'That department does not exist.']);
            }
            $label = $filter > 0 ? 'Department ' . $dept['code'] . ' · ' . $dept['name'] : 'Company-wide';
            $slug  = $filter > 0 ? $dept['code'] : 'company-wide';
        } else {
            return json_invalid(['department_id' => 'Choose all departments, company-wide, or one department.']);
        }

        $S     = $this->_lib();
        $lines = $this->budgets->lines((int) $id);
        $with  = [];
        $amt   = [];
        foreach ($lines as list($a, $d, $p, $c)) {
            $with[$a] = TRUE;
            if ($filter === NULL || $d === $filter) $amt[$a][$p] = ($amt[$a][$p] ?? 0) + $c;
        }
        $names = $this->_period_names($b);

        $rows   = report_csv_head('Budget: ' . $b['name'] . ($b['status'] === 'draft' ? ' (draft)' : ' (approved)'), $b['fiscal_year_name'] . ' · ' . $label);
        $rows[] = array_merge(['Code', 'Account'], array_values($names), ['Total']);
        $sums   = [];
        $words  = $S->words();
        foreach ($this->budgets->grid_sections($S, $with) as $sec) {
            $sum = array_fill(1, 12, 0);
            foreach ($sec['rows'] as $r) {
                if ($r['kind'] !== 'account') continue;
                $vals = [];
                for ($p = 1; $p <= 12; $p++) {
                    $v = $amt[$r['id']][$p] ?? 0;
                    $vals[] = money_major($v);
                    $sum[$p] += $r['sign'] * $v;
                }
                $rows[] = array_merge([$r['code'], $r['name']], $vals, [money_major(array_sum($amt[$r['id']] ?? []))]);
            }
            $sums[] = [$sec, $sum];
        }
        $rows[] = [];
        $net = array_fill(1, 12, 0);
        foreach ($sums as list($sec, $sum)) {
            $rows[] = array_merge(['', 'Total ' . lcfirst($sec['title'])], array_map('money_major', array_values($sum)), [money_major(array_sum($sum))]);
            foreach ($sum as $p => $v) $net[$p] += $sec['type'] === 'income' ? $v : -$v;
        }
        $rows[] = array_merge(['', $words['income']], array_map('money_major', array_values($net)), [money_major(array_sum($net))]);

        return report_csv('budget-' . $b['fiscal_year_name'] . '-' . slugify($b['name'], 40) . '-' . $slug . '.csv', $rows);
    }

    /** POST /api/v1/budgets/{id}/approve */
    public function approve($id)
    {
        $claims = accountant_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $b = $this->budgets->find((int) $id);
        if ( ! $b) return json_error('That budget does not exist.', 404);
        $err = $this->budgets->approve((int) $id, $claims);
        if ($err !== '') return json_error($err, 409);
        return json_response($this->_detail((int) $id, $claims), '"' . $b['name'] . '" approved. It is read-only now.');
    }

    /** POST /api/v1/budgets/{id}/return */
    public function return_to_draft($id)
    {
        $claims = accountant_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $reason = clean_line((string) (get_json_body()['reason'] ?? ''), 400);
        if ($reason === '')           return json_invalid(['reason' => 'Say why the budget goes back to draft.']);
        if (mb_strlen($reason) > 300) return json_invalid(['reason' => 'Keep the reason under 300 characters.']);

        $b = $this->budgets->find((int) $id);
        if ( ! $b) return json_error('That budget does not exist.', 404);
        $err = $this->budgets->return_to_draft((int) $id, $reason, $claims);
        if ($err !== '') return json_error($err, 409);
        return json_response($this->_detail((int) $id, $claims), '"' . $b['name'] . '" is a draft again. Approve it once the changes are made.');
    }

    /** POST /api/v1/budgets/{id}/primary */
    public function primary($id)
    {
        $claims = accountant_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $b = $this->budgets->find((int) $id);
        if ( ! $b) return json_error('That budget does not exist.', 404);
        $err = $this->budgets->set_primary((int) $id, $claims);
        if ($err !== '') return json_error($err, 409);
        return json_response($this->_detail((int) $id, $claims),
            '"' . $b['name'] . '" is now the primary budget for ' . $b['fiscal_year_name'] . '. Reports and the dashboard use it unless you choose another.');
    }

    /** DELETE /api/v1/budgets/{id} */
    public function delete($id)
    {
        $claims = accountant_check();
        require_method('DELETE');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $b = $this->budgets->find((int) $id);
        if ( ! $b) return json_error('That budget does not exist.', 404);
        $err = $this->budgets->delete((int) $id, $claims);
        if ($err !== '') return json_error($err, 409);
        return json_response(['id' => (int) $id], 'Budget "' . $b['name'] . '" deleted.');
    }

    // =========================================================================

    private function _detail($id, array $claims)
    {
        $b = $this->budgets->find($id);
        if ( ! $b) return NULL;
        $S = $this->_lib();

        $lines = $this->budgets->lines($id);
        $with  = [];
        $dwith = [];
        foreach ($lines as $l) { $with[$l[0]] = TRUE; $dwith[$l[1]] = TRUE; }

        $this->load->model('Department_model', 'departments');
        $depts = [['id' => 0, 'code' => '', 'name' => 'Company-wide', 'is_active' => TRUE, 'depth' => 0, 'has_lines' => isset($dwith[0])]];
        foreach ($this->departments->tree() as $d) {
            $depts[] = ['id' => (int) $d['id'], 'code' => $d['code'], 'name' => $d['name'], 'is_active' => (bool) (int) $d['is_active'],
                        'depth' => (int) $d['depth'], 'has_lines' => isset($dwith[(int) $d['id']])];
        }

        $periods = array_map(function ($p) {
            return ['no' => (int) $p['period_no'], 'name' => $p['name'], 'start_date' => $p['start_date'], 'end_date' => $p['end_date']];
        }, $this->periods->periods((int) $b['fiscal_year_id']));

        $tot   = $this->budgets->totals([$id])[$id];
        $rank  = role_rank($claims['role']);
        $draft = $b['status'] === 'draft';
        return [
            'budget'      => $this->budgets->shape($b, $tot),
            'periods'     => $periods,
            'sections'    => $this->budgets->grid_sections($S, $with),
            'departments' => $depts,
            'lines'       => $lines,
            'words'       => $S->words(),
            'coop'        => $S->is_coop(),
            'can'         => [
                'edit'    => $rank >= 3 && $draft,
                'approve' => $rank >= 3 && $draft && $tot['line_count'] > 0,
                'return'  => $rank >= 3 && ! $draft,
                'primary' => $rank >= 3 && ! $draft && ! (int) $b['is_primary'],
                'delete'  => $rank >= 3 && $draft,
                'import'  => $rank >= 3 && $draft,
            ],
            'trail'       => $this->budgets->trail($id),
        ];
    }

    /** period_no => 'Jan 2026' */
    private function _period_names(array $b)
    {
        $out = [];
        foreach ($this->periods->periods((int) $b['fiscal_year_id']) as $p) $out[(int) $p['period_no']] = $p['name'];
        for ($p = 1; $p <= 12; $p++) if ( ! isset($out[$p])) $out[$p] = 'Period ' . $p;
        ksort($out);
        return $out;
    }

    private function _cell_errors(array $errs)
    {
        $more = count($errs) - 1;
        $fields = ['lines' => $errs[0] . ($more ? ' (and ' . $more . ' more problem' . ($more === 1 ? '' : 's') . ')' : '')];
        foreach (array_slice($errs, 0, 50) as $i => $m) $fields['lines.' . $i] = $m;
        return json_invalid($fields, 'Nothing was saved. ' . $errs[0]);
    }

    private function _saved_text(array $r)
    {
        if ( ! $r['set'] && ! $r['deleted'] && ! $r['header']) return 'Nothing changed.';
        $bits = [];
        if ($r['set'])     $bits[] = $r['set'] . ' amount' . ($r['set'] === 1 ? '' : 's') . ' saved';
        if ($r['deleted']) $bits[] = $r['deleted'] . ' cleared';
        if ($r['header'])  $bits[] = 'the ' . implode(' and ', array_keys($r['header'])) . ' changed';
        return ucfirst(implode(', ', $bits)) . '.';
    }
}
