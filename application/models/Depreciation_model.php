<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Depreciation_model.php — monthly depreciation runs: preview, post, undo
 *
 * GenericPOS Accounting · tables gp_depreciation_runs, gp_depreciation_entries
 *
 * ─── A RUN ────────────────────────────────────────────────────────────────
 * One per period (uq_deprun_period), one entry per asset per period
 * (uq_depentry). A run posts ONE journal:
 *   book 'adjusting', source 'depreciation', source_id = the run,
 *   dated the period's last day, reference DEP-YYYY-MM (as the demo's runs),
 *   "Depreciation for September 2026"
 *   Dr each category's depreciation expense account, per department
 *      Cr each category's accumulated-depreciation account
 * and in the same transaction writes the run, its entries (with the
 * accumulated depreciation after each) and the assets that reached their
 * base as fully depreciated.
 *
 * ─── WHICH ASSETS ─────────────────────────────────────────────────────────
 * Active assets (not disposed, not fully depreciated) whose depreciation has
 * started by the period, charged what Depreciation_lib says for that month of
 * their life. Straight-line assets catch up any month that was never run.
 *
 * ─── ORDER ────────────────────────────────────────────────────────────────
 * History stays in order: a period earlier than the latest run can no longer
 * be run, and only the latest run can be undone. Undoing reverses its journal
 * on the same date (the period must still be open), deletes its entries and
 * the run, and puts fully depreciated assets back to active. A run whose
 * assets have since been disposed of cannot be undone before the disposal.
 *
 * NOTE: runs, undos and disposals all hold the fixed-asset lock
 * (Asset_model::lock()), so they never interleave.
 */
class Depreciation_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
        require_once APPPATH . 'libraries/Depreciation_lib.php';
        $this->load->model('Asset_model', 'assets');
    }

    // =========================================================================
    // READ
    // =========================================================================

    /** Every run, newest first. */
    public function runs()
    {
        $rows = $this->db->query(
            'SELECT r.*, p.name AS period_name, p.start_date, p.end_date, p.status AS period_status, fy.status AS fy_status,
                    j.journal_no, j.reversed_by_id, u.full_name, u.username,
                    (SELECT COUNT(*) FROM gp_depreciation_entries e WHERE e.run_id = r.id) AS asset_count
               FROM gp_depreciation_runs r
               JOIN gp_periods p ON p.id = r.period_id
               JOIN gp_fiscal_years fy ON fy.id = p.fiscal_year_id
          LEFT JOIN gp_journals j ON j.id = r.journal_id
          LEFT JOIN gp_users u ON u.id = r.run_by
              ORDER BY p.start_date DESC'
        )->result_array();
        $first = TRUE;
        return array_map(function ($r) use (&$first) {
            $out = $this->_shape_run($r) + ['latest' => $first];
            $first = FALSE;
            return $out;
        }, $rows);
    }

    private function _shape_run(array $r)
    {
        return [
            'id'            => (int) $r['id'],
            'period_id'     => (int) $r['period_id'],
            'period'        => $r['period_name'],
            'start_date'    => $r['start_date'],
            'end_date'      => $r['end_date'],
            'period_status' => $r['fy_status'] === 'open' ? $r['period_status'] : 'closed',
            'total_cents'   => (int) $r['total_cents'],
            'journal_id'    => $r['journal_id'] !== NULL ? (int) $r['journal_id'] : NULL,
            'journal_no'    => $r['journal_no'] ?? NULL,
            'run_by'        => (int) $r['run_by'],
            'run_by_name'   => ($r['full_name'] ?? '') ?: (($r['username'] ?? '') ?: '#' . (int) $r['run_by']),
            'run_at'        => $r['run_at'],
            'asset_count'   => (int) ($r['asset_count'] ?? 0),
        ];
    }

    /** One run with its entries. */
    public function run_detail($id)
    {
        $r = $this->db->query(
            'SELECT r.*, p.name AS period_name, p.start_date, p.end_date, p.status AS period_status, fy.status AS fy_status,
                    j.journal_no, u.full_name, u.username
               FROM gp_depreciation_runs r
               JOIN gp_periods p ON p.id = r.period_id
               JOIN gp_fiscal_years fy ON fy.id = p.fiscal_year_id
          LEFT JOIN gp_journals j ON j.id = r.journal_id
          LEFT JOIN gp_users u ON u.id = r.run_by
              WHERE r.id = ?', [(int) $id]
        )->row_array();
        if ( ! $r) return NULL;
        $latest = $this->latest();
        $entries = array_map(function ($e) {
            return ['asset_id' => (int) $e['asset_id'], 'asset_no' => $e['asset_no'], 'name' => $e['name'], 'category' => $e['category_name'],
                    'department' => $e['department_code'], 'amount_cents' => (int) $e['amount_cents'], 'accum_after_cents' => (int) $e['accum_after_cents'],
                    'nbv_after_cents' => (int) $e['cost_cents'] - (int) $e['accum_after_cents'], 'status' => $e['status']];
        }, $this->db->query(
            'SELECT e.*, a.asset_no, a.name, a.cost_cents, a.status, c.name AS category_name, d.code AS department_code
               FROM gp_depreciation_entries e
               JOIN gp_assets a ON a.id = e.asset_id
               JOIN gp_asset_categories c ON c.id = a.category_id
          LEFT JOIN gp_departments d ON d.id = a.department_id
              WHERE e.run_id = ? ORDER BY c.name, a.asset_no', [(int) $id]
        )->result_array());
        return ['run' => $this->_shape_run($r) + ['latest' => $latest && (int) $latest['id'] === (int) $r['id']], 'entries' => $entries];
    }

    /** The run for the latest period, or NULL. */
    public function latest()
    {
        return $this->db->query(
            'SELECT r.*, p.name AS period_name, p.start_date, p.end_date FROM gp_depreciation_runs r
               JOIN gp_periods p ON p.id = r.period_id ORDER BY p.start_date DESC LIMIT 1'
        )->row_array() ?: NULL;
    }

    /** Open periods that can still be run: after the latest run, in an open year, without a run. */
    public function choosable_periods()
    {
        $latest = $this->latest();
        return array_map(function ($p) {
            return ['id' => (int) $p['id'], 'name' => $p['name'], 'start_date' => $p['start_date'], 'end_date' => $p['end_date']];
        }, $this->db->query(
            "SELECT p.id, p.name, p.start_date, p.end_date FROM gp_periods p JOIN gp_fiscal_years fy ON fy.id = p.fiscal_year_id
              WHERE p.status = 'open' AND fy.status = 'open' AND p.start_date > ?
                AND NOT EXISTS (SELECT 1 FROM gp_depreciation_runs r WHERE r.period_id = p.id)
              ORDER BY p.start_date",
            [$latest ? $latest['start_date'] : '0000-01-01']
        )->result_array());
    }

    public function period($id, $lock = FALSE)
    {
        return $this->db->query(
            'SELECT p.*, fy.name AS fy_name, fy.status AS fy_status FROM gp_periods p JOIN gp_fiscal_years fy ON fy.id = p.fiscal_year_id
              WHERE p.id = ?' . ($lock ? ' FOR UPDATE' : ''), [(int) $id]
        )->row_array() ?: NULL;
    }

    /** '' when $p can be run now, otherwise why not. */
    public function refusal($p)
    {
        if ( ! $p) return 'Choose a period.';
        if ($p['fy_status'] !== 'open') return $p['fy_name'] . ' is closed.';
        if ($p['status'] === 'locked')  return $p['name'] . ' is locked.';
        if ($p['status'] !== 'open')    return $p['name'] . ' is closed. An accountant can reopen it on the Fiscal years screen.';
        $has = $this->db->query('SELECT r.id, j.journal_no FROM gp_depreciation_runs r LEFT JOIN gp_journals j ON j.id = r.journal_id WHERE r.period_id = ?',
                                [(int) $p['id']])->row_array();
        if ($has) return $p['name'] . ' already has a depreciation run' . ($has['journal_no'] ? ' (' . $has['journal_no'] . ')' : '') . '. Undo it first to run the month again.';
        $latest = $this->latest();
        if ($latest && $p['start_date'] < $latest['start_date']) {
            return 'Depreciation has already been run for ' . $latest['period_name'] . '. Runs go month by month, so ' . $p['name'] . ' can no longer be run.';
        }
        return '';
    }

    // =========================================================================
    // WHAT A RUN WOULD POST
    // =========================================================================

    /**
     * The assets a run for $p charges, what each is charged, and the journal
     * lines. $lock re-reads the assets FOR UPDATE (inside the run itself).
     *
     * @return array rows, lines, total_cents, problems, caught_up (months since the last run that were never run)
     */
    public function compute(array $p, $lock = FALSE)
    {
        $assets = $this->db->query(
            "SELECT * FROM gp_assets WHERE status = 'active' AND depreciation_start <= ? ORDER BY asset_no" . ($lock ? ' FOR UPDATE' : ''),
            [$p['start_date']]
        )->result_array();

        $dep = [];
        if ($assets) {
            $ids = array_map('intval', array_column($assets, 'id'));
            foreach ($this->db->query('SELECT asset_id, SUM(amount_cents) AS s FROM gp_depreciation_entries WHERE asset_id IN ('
                                      . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY asset_id', $ids)->result_array() as $r) {
                $dep[(int) $r['asset_id']] = (int) $r['s'];
            }
        }

        $cats = [];
        foreach ($this->db->query(
            'SELECT c.id, c.name, c.expense_account_id, c.accum_account_id,
                    ae.code AS exp_code, ae.name AS exp_name, ae.is_active AS exp_active, ae.is_header AS exp_header, ae.requires_department AS exp_rd,
                    ac.code AS acc_code, ac.name AS acc_name, ac.is_active AS acc_active, ac.is_header AS acc_header, ac.requires_department AS acc_rd
               FROM gp_asset_categories c
               JOIN gp_accounts ae ON ae.id = c.expense_account_id
               JOIN gp_accounts ac ON ac.id = c.accum_account_id'
        )->result_array() as $c) $cats[(int) $c['id']] = $c;

        $depts = [];
        foreach ($this->db->get('gp_departments')->result_array() as $d) $depts[(int) $d['id']] = $d;

        $rows = [];
        $problems = [];
        foreach ($assets as $a) {
            $k      = Depreciation_lib::month_index($a['depreciation_start'], $p['start_date']);
            $before = (int) $a['opening_accum_cents'] + ($dep[(int) $a['id']] ?? 0);
            $amt    = Depreciation_lib::charge($a, $before, $k);
            if ($amt <= 0) continue;

            $c    = $cats[(int) $a['category_id']];
            $did  = $a['department_id'] !== NULL ? (int) $a['department_id'] : NULL;
            $d    = $did ? ($depts[$did] ?? NULL) : NULL;
            $after = $before + $amt;
            $rows[] = [
                'asset_id'          => (int) $a['id'],
                'asset_no'          => $a['asset_no'],
                'name'              => $a['name'],
                'category_id'       => (int) $a['category_id'],
                'category'          => $c['name'],
                'department_id'     => $did,
                'department'        => $d ? $d['code'] : NULL,
                'month_of_life'     => $k,
                'life_months'       => (int) $a['useful_life_months'],
                'method'            => $a['method'],
                'accum_before_cents'=> $before,
                'amount_cents'      => $amt,
                'accum_after_cents' => $after,
                'nbv_after_cents'   => (int) $a['cost_cents'] - $after,
                'fully'             => Depreciation_lib::is_fully_depreciated($a, $after),
            ];
            if ($d && ! (int) $d['is_active']) $problems[] = $a['asset_no'] . '\'s department ' . $d['code'] . ' is inactive. Give the asset an active department.';
            if ((int) $c['exp_rd'] && ! $did) $problems[] = $a['asset_no'] . ' has no department, and ' . $c['exp_code'] . ' ' . $c['exp_name'] . ' needs one. Give the asset a department.';
            if ((int) $c['acc_rd'] && ! $did) $problems[] = $a['asset_no'] . ' has no department, and ' . $c['acc_code'] . ' needs one. Give the asset a department.';
        }
        foreach (array_unique(array_column($rows, 'category_id')) as $cid) {
            $c = $cats[$cid];
            if ( ! (int) $c['exp_active'] || (int) $c['exp_header']) $problems[] = $c['name'] . '\'s depreciation expense account ' . $c['exp_code'] . ' cannot take entries. Choose another account for the category.';
            if ( ! (int) $c['acc_active'] || (int) $c['acc_header']) $problems[] = $c['name'] . '\'s accumulated depreciation account ' . $c['acc_code'] . ' cannot take entries.';
        }

        /* Debits per category and department; credits per category (and per
           department only when the account insists on one). */
        $dr = [];
        $cr = [];
        foreach ($rows as $r) {
            $c  = $cats[$r['category_id']];
            $kd = $r['category_id'] . '|' . (int) $r['department_id'];
            $kc = $r['category_id'] . '|' . ((int) $c['acc_rd'] ? (int) $r['department_id'] : 0);
            if ( ! isset($dr[$kd])) $dr[$kd] = ['cat' => $c, 'dept' => $r['department_id'], 'code' => $r['department'], 'cents' => 0, 'assets' => []];
            if ( ! isset($cr[$kc])) $cr[$kc] = ['cat' => $c, 'dept' => (int) $c['acc_rd'] ? $r['department_id'] : NULL, 'code' => (int) $c['acc_rd'] ? $r['department'] : NULL, 'cents' => 0, 'assets' => []];
            $dr[$kd]['cents'] += $r['amount_cents'];
            $cr[$kc]['cents'] += $r['amount_cents'];
            $dr[$kd]['assets'][] = $r['asset_no'];
            $cr[$kc]['assets'][] = $r['asset_no'];
        }
        $order = function ($x, $y) { return strcmp($x['cat']['name'], $y['cat']['name']) ?: strcmp((string) $x['code'], (string) $y['code']); };
        uasort($dr, $order);
        uasort($cr, $order);
        $memo = function ($g) {
            $n = count($g['assets']);
            return mb_substr($g['cat']['name'] . ': ' . ($n <= 4 ? implode(', ', $g['assets']) : $n . ' assets'), 0, 255);
        };
        $lines = [];
        foreach ($dr as $g) {
            $lines[] = ['account_id' => (int) $g['cat']['expense_account_id'], 'account_code' => $g['cat']['exp_code'], 'account_name' => $g['cat']['exp_name'],
                        'debit_cents' => $g['cents'], 'credit_cents' => 0, 'memo' => $memo($g), 'department_id' => $g['dept'], 'department' => $g['code'],
                        'category_id' => (int) $g['cat']['id'], 'contact_id' => NULL];
        }
        foreach ($cr as $g) {
            $lines[] = ['account_id' => (int) $g['cat']['accum_account_id'], 'account_code' => $g['cat']['acc_code'], 'account_name' => $g['cat']['acc_name'],
                        'debit_cents' => 0, 'credit_cents' => $g['cents'], 'memo' => $memo($g), 'department_id' => $g['dept'], 'department' => $g['code'],
                        'category_id' => (int) $g['cat']['id'], 'contact_id' => NULL];
        }

        $latest = $this->latest();
        $caught = $latest ? max(0, Depreciation_lib::month_no($p['start_date']) - Depreciation_lib::month_no($latest['start_date']) - 1) : 0;

        return [
            'rows'        => $rows,
            'lines'       => $lines,
            'total_cents' => array_sum(array_column($rows, 'amount_cents')),
            'problems'    => array_values(array_unique($problems)),
            'caught_up'   => $caught,
        ];
    }

    /** The journal header a run for $p posts. */
    public static function head(array $p)
    {
        return [
            'book'        => 'adjusting',
            'entry_date'  => $p['end_date'],
            'reference'   => 'DEP-' . substr($p['start_date'], 0, 7),
            'party_name'  => NULL,
            'description' => 'Depreciation for ' . date('F Y', strtotime($p['start_date'])),
        ];
    }

    // =========================================================================
    // RUN AND UNDO
    // =========================================================================

    /**
     * Post the depreciation for period $period_id.
     * @return array [run id, error]
     */
    public function run($period_id, array $claims)
    {
        if ( ! $this->assets->lock()) return [0, 'Another depreciation run or a disposal is being posted. Try again in a moment.'];
        $run_id = 0;
        try {
            $err = $this->assets->_tx(function () use ($period_id, $claims, &$run_id) {
                $p = $this->period($period_id, TRUE);
                $why = $this->refusal($p);
                if ($why !== '') return $why;

                $calc = $this->compute($p, TRUE);
                if ($calc['problems']) return $calc['problems'][0];
                if ( ! $calc['rows']) return 'Nothing to depreciate in ' . $p['name'] . ': no asset in use is due for depreciation that month.';

                $now = date('Y-m-d H:i:s');
                $uid = (int) $claims['user_id'];
                $this->db->insert('gp_depreciation_runs', ['period_id' => (int) $p['id'], 'total_cents' => $calc['total_cents'], 'run_by' => $uid, 'run_at' => $now]);
                $run_id = (int) $this->db->insert_id();

                $lines = array_map(function ($l) {
                    return ['account_id' => $l['account_id'], 'debit_cents' => $l['debit_cents'], 'credit_cents' => $l['credit_cents'],
                            'memo' => $l['memo'], 'department_id' => $l['department_id'], 'contact_id' => NULL];
                }, $calc['lines']);
                $this->load->model('Journal_model', 'journals');
                list($jid, $errs) = $this->journals->post_system(self::head($p), $lines, $uid, 'depreciation', $run_id);
                if ( ! $jid) return (string) reset($errs);
                $this->db->where('id', $run_id)->update('gp_depreciation_runs', ['journal_id' => $jid]);

                $batch = [];
                $done  = [];
                foreach ($calc['rows'] as $r) {
                    $batch[] = ['run_id' => $run_id, 'asset_id' => $r['asset_id'], 'period_id' => (int) $p['id'],
                                'amount_cents' => $r['amount_cents'], 'accum_after_cents' => $r['accum_after_cents']];
                    if ($r['fully']) $done[] = $r['asset_id'];
                }
                $this->db->insert_batch('gp_depreciation_entries', $batch);
                if ($done) {
                    $this->db->where_in('id', $done)->where('status', 'active')
                             ->update('gp_assets', ['status' => 'fully_depreciated', 'updated_at' => $now]);
                }

                $j = $this->journals->find($jid);
                return log_admin_action($claims, 'depreciation.run', 'depreciation_run', $run_id, [
                    'period' => $p['name'], 'journal_id' => $jid, 'journal_no' => $j['journal_no'], 'total_cents' => $calc['total_cents'],
                    'assets' => count($calc['rows']), 'fully_depreciated' => count($done),
                ]) ? '' : 'The audit trail could not be written, so nothing was saved.';
            }, 'The depreciation run could not be posted. Nothing was saved.');
        } finally {
            $this->assets->unlock();
        }
        return $err === '' ? [$run_id, ''] : [0, $err];
    }

    /**
     * Undo the latest run: its journal reversed on the same date, its entries
     * and the run deleted, fully depreciated assets back to active.
     * @return array [reversal journal id, error]
     */
    public function undo($run_id, $reason, array $claims)
    {
        $reason = trim(preg_replace('/\s+/u', ' ', sanitise_text($reason)));
        if ($reason === '')           return [0, 'Say why the run is undone.'];
        if (mb_strlen($reason) > 300) return [0, 'Keep the reason under 300 characters.'];

        if ( ! $this->assets->lock()) return [0, 'Another depreciation run or a disposal is being posted. Try again in a moment.'];
        $rid = 0;
        try {
            $err = $this->assets->_tx(function () use ($run_id, $reason, $claims, &$rid) {
                $r = $this->db->query(
                    'SELECT r.*, p.name AS period_name, p.start_date, p.end_date FROM gp_depreciation_runs r JOIN gp_periods p ON p.id = r.period_id
                      WHERE r.id = ? FOR UPDATE', [(int) $run_id]
                )->row_array();
                if ( ! $r) return 'No such depreciation run.';
                $latest = $this->latest();
                if ((int) $latest['id'] !== (int) $r['id']) {
                    return 'Only the latest run can be undone. Undo ' . $latest['period_name'] . ' first.';
                }
                $gone = $this->db->query(
                    "SELECT a.asset_no, a.disposed_on FROM gp_depreciation_entries e JOIN gp_assets a ON a.id = e.asset_id
                      WHERE e.run_id = ? AND a.status = 'disposed' ORDER BY a.asset_no LIMIT 1", [(int) $run_id]
                )->row_array();
                if ($gone) {
                    return $gone['asset_no'] . ' was disposed of on ' . $gone['disposed_on'] . ' with this run\'s depreciation counted. Undo that disposal first.';
                }
                if ( ! $r['journal_id']) return 'This run has no journal entry to reverse. Ask your administrator to check it.';

                $ids = array_map('intval', array_column($this->db->select('asset_id')->get_where('gp_depreciation_entries', ['run_id' => (int) $run_id])->result_array(), 'asset_id'));
                if ($ids) $this->db->query('SELECT id FROM gp_assets WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE', $ids);

                $this->load->model('Journal_model', 'journals');
                list($rid, $e) = $this->journals->reverse_system((int) $r['journal_id'], (int) $claims['user_id'], $r['end_date'], $reason, 'depreciation');
                if ( ! $rid) return $e;

                $this->db->where('run_id', (int) $run_id)->delete('gp_depreciation_entries');
                $this->db->where('id', (int) $run_id)->delete('gp_depreciation_runs');
                if ($ids) {
                    $this->db->where_in('id', $ids)->where('status', 'fully_depreciated')
                             ->update('gp_assets', ['status' => 'active', 'updated_at' => date('Y-m-d H:i:s')]);
                }

                $x = $this->journals->find($rid);
                $o = $this->journals->find((int) $r['journal_id']);
                return log_admin_action($claims, 'depreciation.undo', 'depreciation_run', (int) $run_id, [
                    'period' => $r['period_name'], 'journal_id' => (int) $r['journal_id'], 'journal_no' => $o['journal_no'],
                    'reversal_id' => $rid, 'reversal_no' => $x['journal_no'], 'total_cents' => (int) $r['total_cents'], 'assets' => count($ids), 'reason' => $reason,
                ]) ? '' : 'The audit trail could not be written, so nothing was saved.';
            }, 'The run could not be undone. Nothing was changed.');
        } finally {
            $this->assets->unlock();
        }
        return $err === '' ? [$rid, ''] : [0, $err];
    }
}
