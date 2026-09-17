<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Asset_model.php — fixed assets: categories, the register, disposals, the lapsing schedule
 *
 * GenericPOS Accounting · tables gp_asset_categories, gp_assets (reads
 * gp_depreciation_entries; Depreciation_model writes them)
 *
 * ─── WHAT AN ASSET CARRIES ────────────────────────────────────────────────
 * Each asset keeps its OWN cost, residual value, useful life, method and
 * depreciation start; the category only suggests them when the asset is
 * registered. The category supplies the three accounts: where the cost sits,
 * where the accumulated depreciation sits (a contra-asset account), and what
 * the depreciation is charged to.
 *
 *   accumulated = opening_accum_cents (charged before go-live, already inside
 *                 the opening balance of the accumulated-depreciation account)
 *               + every depreciation entry
 *
 * ─── WHAT FREEZES ─────────────────────────────────────────────────────────
 *   · Once an asset has a depreciation entry, its category, cost, residual,
 *     life, method, start and opening accumulated are fixed: the entries were
 *     worked out from them and posted to the category's accounts.
 *   · A disposed asset keeps those and its department too; only its
 *     description, serial number, location, supplier and notes can change.
 *   · Once a category has assets, its accounts are fixed (the register's
 *     tie-out to the ledger would otherwise move to other accounts).
 *
 * ─── DISPOSAL ─────────────────────────────────────────────────────────────
 * One journal, book 'general', source 'disposal', source_id = the asset:
 *   Dr accumulated depreciation (everything charged, opening included)
 *   Dr proceeds account (cash, bank or a receivable), when there are proceeds
 *   Dr loss on disposal, when the book value is more than the proceeds
 *      Cr the asset account (cost)
 *      Cr gain on disposal, when the proceeds are more than the book value
 * Nothing is depreciated in the month of disposal or after, so a disposal is
 * refused while a depreciation run for that month (or a later one) includes
 * the asset. Runs made after a disposal leave the asset out.
 *
 * NOTE: EVERY POSTING HERE HOLDS THE FIXED-ASSET LOCK (lock()) — a named
 * MariaDB lock that Depreciation_model takes too — so a disposal and a
 * depreciation run can never interleave. The asset row is locked as well
 * (FOR UPDATE) and its status re-read under the lock.
 */
class Asset_model extends CI_Model
{
    const T = 'gp_assets';
    const C = 'gp_asset_categories';

    const STATUSES = ['active', 'fully_depreciated', 'disposed'];

    /** Fixed once the asset has a depreciation entry, or is disposed of. */
    const FROZEN = ['category_id', 'cost_cents', 'residual_cents', 'useful_life_months', 'method', 'depreciation_start', 'opening_accum_cents'];

    const FIELD_LABELS = [
        'category_id' => 'category', 'cost_cents' => 'cost', 'residual_cents' => 'residual value', 'useful_life_months' => 'useful life',
        'method' => 'method', 'depreciation_start' => 'depreciation start', 'opening_accum_cents' => 'opening accumulated depreciation',
        'department_id' => 'department',
    ];

    /** The largest amount the forms take: 10 trillion pesos less a centavo. */
    const MAX_CENTS = 999999999999999;

    public function __construct()
    {
        parent::__construct();
        $this->config->load('app', FALSE, TRUE);
        require_once APPPATH . 'libraries/Depreciation_lib.php';
    }

    // =========================================================================
    // THE FIXED-ASSET LOCK
    // =========================================================================

    /**
     * Take the lock every fixed-asset posting holds (depreciation runs, their
     * undoing, disposals and theirs). Named per database: the server may hold
     * other companies' books. @return bool
     */
    public function lock($wait = 15)
    {
        $r = $this->db->query('SELECT GET_LOCK(?, ?) AS ok', [$this->_lock_name(), (int) $wait])->row_array();
        return $r && (string) $r['ok'] === '1';
    }

    public function unlock()
    {
        try { $this->db->query('SELECT RELEASE_LOCK(?) AS ok', [$this->_lock_name()]); }
        catch (Throwable $t) { log_message('error', '[Asset_model] release lock: ' . $t->getMessage()); }
    }

    private function _lock_name()
    {
        return 'acc_fa:' . substr((string) $this->db->database, 0, 50);
    }

    // =========================================================================
    // CATEGORIES
    // =========================================================================

    /** Every category, active first, with its accounts and how many assets use it. */
    public function categories()
    {
        $rows = $this->db->query(
            "SELECT c.*,
                    aa.code AS asset_code, aa.name AS asset_name,
                    ac.code AS accum_code, ac.name AS accum_name,
                    ae.code AS expense_code, ae.name AS expense_name,
                    (SELECT COUNT(*) FROM gp_assets a WHERE a.category_id = c.id) AS asset_count,
                    (SELECT COUNT(*) FROM gp_assets a WHERE a.category_id = c.id AND a.status <> 'disposed') AS in_use_count
               FROM gp_asset_categories c
               JOIN gp_accounts aa ON aa.id = c.asset_account_id
               JOIN gp_accounts ac ON ac.id = c.accum_account_id
               JOIN gp_accounts ae ON ae.id = c.expense_account_id
              ORDER BY c.is_active DESC, c.name"
        )->result_array();
        return array_map([$this, 'shape_category'], $rows);
    }

    public function category_row($id)
    {
        return $this->db->get_where(self::C, ['id' => (int) $id], 1)->row_array() ?: NULL;
    }

    public function category($id)
    {
        foreach ($this->categories() as $c) if ($c['id'] === (int) $id) return $c;
        return NULL;
    }

    public function shape_category(array $r)
    {
        $acct = function ($id, $code, $name) { return ['id' => (int) $id, 'code' => $code, 'name' => $name]; };
        return [
            'id'                 => (int) $r['id'],
            'name'               => $r['name'],
            'asset_account'      => $acct($r['asset_account_id'], $r['asset_code'] ?? NULL, $r['asset_name'] ?? NULL),
            'accum_account'      => $acct($r['accum_account_id'], $r['accum_code'] ?? NULL, $r['accum_name'] ?? NULL),
            'expense_account'    => $acct($r['expense_account_id'], $r['expense_code'] ?? NULL, $r['expense_name'] ?? NULL),
            'method'             => $r['method'],
            'method_label'       => Depreciation_lib::METHOD_LABELS[$r['method']] ?? $r['method'],
            'useful_life_months' => (int) $r['useful_life_months'],
            'residual_bp'        => (int) $r['residual_bp'],
            'is_active'          => (bool) (int) $r['is_active'],
            'asset_count'        => (int) ($r['asset_count'] ?? 0),
            'in_use_count'       => (int) ($r['in_use_count'] ?? 0),
        ];
    }

    /** The accounts each picker on the category form offers. */
    public function category_accounts()
    {
        $shape = function ($r) {
            return ['id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name'],
                    'ppe' => in_array('ppe', explode(',', (string) $r['tags']), TRUE) || $r['subtype'] === 'non_current'];
        };
        $q = function ($where) use ($shape) {
            return array_map($shape, $this->db->query(
                'SELECT id, code, name, tags, subtype FROM gp_accounts WHERE is_header = 0 AND is_active = 1 AND ' . $where . ' ORDER BY sort_order, code'
            )->result_array());
        };
        return [
            'asset'   => $q("type = 'asset' AND is_contra = 0 AND control IS NULL"),
            'accum'   => $q("type = 'asset' AND is_contra = 1"),
            'expense' => $q("type = 'expense'"),
        ];
    }

    /**
     * @param array      $in        name, asset_account_id, accum_account_id, expense_account_id,
     *                              method, useful_life_months, residual_bp, is_active
     * @param array|NULL $existing  the stored row, for an update
     * @return array [data, errors]
     */
    public function category_validate(array $in, $existing = NULL)
    {
        $e = [];
        $pick = function ($k, $d = NULL) use ($in, $existing) {
            return array_key_exists($k, $in) ? $in[$k] : ($existing !== NULL && array_key_exists($k, $existing) ? $existing[$k] : $d);
        };

        $name = trim(preg_replace('/\s+/u', ' ', (string) $pick('name', '')));
        if ($name === '')               $e['name'] = 'Name the category, for example Office Equipment.';
        elseif (mb_strlen($name) > 120) $e['name'] = 'Keep the name under 120 characters.';
        else {
            $dup = $this->db->select('id')->get_where(self::C, ['name' => $name], 1)->row_array();
            if ($dup && ( ! $existing || (int) $dup['id'] !== (int) $existing['id'])) $e['name'] = 'Another category already has that name.';
        }

        $ids   = ['asset_account_id' => (int) $pick('asset_account_id', 0), 'accum_account_id' => (int) $pick('accum_account_id', 0),
                  'expense_account_id' => (int) $pick('expense_account_id', 0)];
        $accts = [];
        $list  = array_values(array_filter($ids));
        if ($list) foreach ($this->db->where_in('id', $list)->get('gp_accounts')->result_array() as $r) $accts[(int) $r['id']] = $r;
        $usable = function ($a) { return $a && ! (int) $a['is_header'] && (int) $a['is_active']; };

        $a = $accts[$ids['asset_account_id']] ?? NULL;
        if ( ! $usable($a) || $a['type'] !== 'asset' || (int) $a['is_contra'] || $a['control'] !== NULL) {
            $e['asset_account_id'] = 'Choose the active asset account the cost is kept in (such as 1214 Office Equipment).';
        }
        $a = $accts[$ids['accum_account_id']] ?? NULL;
        if ( ! $usable($a) || $a['type'] !== 'asset' || ! (int) $a['is_contra']) {
            $e['accum_account_id'] = 'Choose an active contra-asset account for the accumulated depreciation (such as 1215).';
        }
        $a = $accts[$ids['expense_account_id']] ?? NULL;
        if ( ! $usable($a) || $a['type'] !== 'expense') {
            $e['expense_account_id'] = 'Choose an active expense account for the depreciation (such as 6200 Depreciation Expense).';
        }

        $method = (string) $pick('method', 'straight_line');
        if ( ! in_array($method, Depreciation_lib::METHODS, TRUE)) $e['method'] = 'Choose straight line or declining balance.';

        $life = $pick('useful_life_months', '');
        if ( ! preg_match('/^\d{1,4}$/', trim((string) $life)) || (int) $life < 1 || (int) $life > Depreciation_lib::MAX_LIFE_MONTHS) {
            $e['useful_life_months'] = 'Enter the useful life in months, from 1 to 1,200 (5 years = 60 months).';
        }
        $bp = $pick('residual_bp', 0);
        if ( ! preg_match('/^\d{1,5}$/', trim((string) $bp)) || (int) $bp > 10000) {
            $e['residual_bp'] = 'The residual value is a percentage of cost, from 0 to 100.';
        }

        $active = array_key_exists('is_active', $in) ? (bool) $in['is_active'] : ($existing ? (bool) (int) $existing['is_active'] : TRUE);

        if ($existing && $this->db->where('category_id', (int) $existing['id'])->count_all_results(self::T) > 0) {
            foreach ($ids as $k => $v) {
                if ($v !== (int) $existing[$k]) $e[$k] = 'This category has assets, so its accounts are fixed. Create a new category for different accounts.';
            }
        }

        $data = $ids + [
            'name'               => $name,
            'method'             => $method,
            'useful_life_months' => (int) $life,
            'residual_bp'        => (int) $bp,
            'is_active'          => $active ? 1 : 0,
        ];
        return [$data, $e];
    }

    /** @return array [id, errors] */
    public function category_create(array $in, array $claims)
    {
        list($d, $e) = $this->category_validate($in);
        if ($e) return [0, $e];
        $id  = 0;
        $err = $this->_tx(function () use ($d, $claims, &$id) {
            $now = date('Y-m-d H:i:s');
            $this->db->insert(self::C, $d + ['created_at' => $now, 'updated_at' => $now]);
            $id = (int) $this->db->insert_id();
            return $this->_audit($claims, 'asset_category.create', 'asset_category', $id, ['name' => $d['name']]);
        }, 'The category could not be saved.');
        return $err === '' ? [$id, []] : [0, ['_' => $err]];
    }

    /** @return array errors */
    public function category_update($id, array $in, array $claims)
    {
        $fields = [];
        $err = $this->_tx(function () use ($id, $in, $claims, &$fields) {
            $c = $this->db->query('SELECT * FROM gp_asset_categories WHERE id = ? FOR UPDATE', [(int) $id])->row_array();
            if ( ! $c) return 'No such category.';
            list($d, $e) = $this->category_validate($in, $c);
            if ($e) { $fields = $e; return 'invalid'; }
            $this->db->where('id', (int) $id)->update(self::C, $d + ['updated_at' => date('Y-m-d H:i:s')]);
            $changed = [];
            foreach ($d as $k => $v) if ((string) $v !== (string) $c[$k]) $changed[$k] = ['from' => $c[$k], 'to' => $v];
            return $this->_audit($claims, 'asset_category.update', 'asset_category', (int) $id, ['name' => $d['name'], 'changed' => $changed]);
        }, 'The category could not be saved.');
        if ($fields) return $fields;
        return $err === '' ? [] : ['_' => $err];
    }

    /** Only a category no asset has ever used. @return string error */
    public function category_delete($id, array $claims)
    {
        return $this->_tx(function () use ($id, $claims) {
            $c = $this->db->query('SELECT * FROM gp_asset_categories WHERE id = ? FOR UPDATE', [(int) $id])->row_array();
            if ( ! $c) return 'No such category.';
            $n = $this->db->where('category_id', (int) $id)->count_all_results(self::T);
            if ($n > 0) return $c['name'] . ' has ' . $n . ' asset' . ($n === 1 ? '' : 's') . ', so it stays. Mark it inactive instead.';
            $this->db->where('id', (int) $id)->delete(self::C);
            return $this->_audit($claims, 'asset_category.delete', 'asset_category', (int) $id, ['name' => $c['name']]);
        }, 'The category could not be deleted.');
    }

    // =========================================================================
    // THE REGISTER
    // =========================================================================

    /** The asset number prefix from Settings, letters and digits only. */
    public static function prefix()
    {
        $p = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) shop_cfg('fa_asset_prefix', 'FA')));
        return $p !== '' ? $p : 'FA';
    }

    /** 'next_month' | 'same_month' (Settings → fa_depreciation_start). */
    public static function start_mode()
    {
        return shop_cfg('fa_depreciation_start', 'next_month') === 'same_month' ? 'same_month' : 'next_month';
    }

    private function _select()
    {
        return 'SELECT a.*, c.name AS category_name, c.asset_account_id, c.accum_account_id, c.expense_account_id,
                       d.code AS department_code, d.name AS department_name, s.name AS supplier_name,
                       COALESCE(e.dep, 0) AS depreciated_cents, COALESCE(e.months, 0) AS months_charged
                  FROM gp_assets a
                  JOIN gp_asset_categories c ON c.id = a.category_id
             LEFT JOIN gp_departments d ON d.id = a.department_id
             LEFT JOIN gp_contacts s ON s.id = a.supplier_id
             LEFT JOIN (SELECT asset_id, SUM(amount_cents) AS dep, COUNT(*) AS months
                          FROM gp_depreciation_entries GROUP BY asset_id) e ON e.asset_id = a.id';
    }

    /**
     * @param array $f  status (in_use | active | fully_depreciated | disposed | '' for all),
     *                  category_id, department_id, q
     * @return array [rows, total, totals, counts by status]
     */
    public function search(array $f, $limit = 50, $offset = 0)
    {
        list($w, $args) = $this->_filters($f, TRUE);
        $rows = $this->db->query($this->_select() . ' WHERE ' . $w . ' ORDER BY a.asset_no LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset, $args)->result_array();
        $t = $this->db->query(
            'SELECT COUNT(*) AS n, COALESCE(SUM(a.cost_cents), 0) AS cost, COALESCE(SUM(a.opening_accum_cents + COALESCE(e.dep, 0)), 0) AS accum
               FROM gp_assets a
          LEFT JOIN (SELECT asset_id, SUM(amount_cents) AS dep FROM gp_depreciation_entries GROUP BY asset_id) e ON e.asset_id = a.id
              WHERE ' . $w, $args
        )->row_array();

        list($w2, $args2) = $this->_filters($f, FALSE);
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($this->db->query('SELECT a.status, COUNT(*) AS n FROM gp_assets a WHERE ' . $w2 . ' GROUP BY a.status', $args2)->result_array() as $r) {
            $counts[$r['status']] = (int) $r['n'];
        }
        $counts['in_use'] = $counts['active'] + $counts['fully_depreciated'];
        $counts['all']    = $counts['in_use'] + $counts['disposed'];

        $totals = ['count' => (int) $t['n'], 'cost_cents' => (int) $t['cost'], 'accumulated_cents' => (int) $t['accum'],
                   'nbv_cents' => (int) $t['cost'] - (int) $t['accum']];
        return [array_map([$this, 'shape'], $rows), (int) $t['n'], $totals, $counts];
    }

    private function _filters(array $f, $with_status)
    {
        $w = ['1 = 1'];
        $a = [];
        $st = (string) ($f['status'] ?? '');
        if ($with_status) {
            if ($st === 'in_use') $w[] = "a.status <> 'disposed'";
            elseif (in_array($st, self::STATUSES, TRUE)) { $w[] = 'a.status = ?'; $a[] = $st; }
        }
        if ( ! empty($f['category_id']))   { $w[] = 'a.category_id = ?';   $a[] = (int) $f['category_id']; }
        if ( ! empty($f['department_id'])) { $w[] = 'a.department_id = ?'; $a[] = (int) $f['department_id']; }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 80)) . '%';
            $w[] = '(a.asset_no LIKE ? OR a.name LIKE ? OR a.serial_no LIKE ? OR a.location LIKE ?)';
            array_push($a, $like, $like, $like, $like);
        }
        return [implode(' AND ', $w), $a];
    }

    public function find($id)
    {
        return $this->db->get_where(self::T, ['id' => (int) $id], 1)->row_array() ?: NULL;
    }

    /** One asset with its category, department, supplier and what has been charged. */
    public function row($id)
    {
        return $this->db->query($this->_select() . ' WHERE a.id = ?', [(int) $id])->row_array() ?: NULL;
    }

    public function entry_count($id)
    {
        return (int) $this->db->where('asset_id', (int) $id)->count_all_results('gp_depreciation_entries');
    }

    /** An asset row as the API shows it. */
    public function shape(array $r)
    {
        $dep   = (int) ($r['depreciated_cents'] ?? 0);
        $accum = (int) $r['opening_accum_cents'] + $dep;
        $cost  = (int) $r['cost_cents'];
        $int   = function ($v) { return $v !== NULL && $v !== '' ? (int) $v : NULL; };
        return [
            'id'                      => (int) $r['id'],
            'asset_no'                => $r['asset_no'],
            'name'                    => $r['name'],
            'category_id'             => (int) $r['category_id'],
            'category_name'           => $r['category_name'] ?? NULL,
            'acquired_on'             => $r['acquired_on'],
            'depreciation_start'      => $r['depreciation_start'],
            'cost_cents'              => $cost,
            'residual_cents'          => (int) $r['residual_cents'],
            'base_cents'              => max(0, $cost - (int) $r['residual_cents']),
            'useful_life_months'      => (int) $r['useful_life_months'],
            'method'                  => $r['method'],
            'method_label'            => Depreciation_lib::METHOD_LABELS[$r['method']] ?? $r['method'],
            'opening_accum_cents'     => (int) $r['opening_accum_cents'],
            'depreciated_cents'       => $dep,
            'accumulated_cents'       => $accum,
            'nbv_cents'               => $cost - $accum,
            'months_charged'          => (int) ($r['months_charged'] ?? 0),
            'department_id'           => $int($r['department_id']),
            'department_code'         => $r['department_code'] ?? NULL,
            'department'              => isset($r['department_code']) ? $r['department_code'] . ' · ' . $r['department_name'] : NULL,
            'location'                => $r['location'],
            'serial_no'               => $r['serial_no'],
            'supplier_id'             => $int($r['supplier_id']),
            'supplier_name'           => $r['supplier_name'] ?? NULL,
            'status'                  => $r['status'],
            'disposed_on'             => $r['disposed_on'],
            'disposal_proceeds_cents' => $int($r['disposal_proceeds_cents']),
            'disposal_journal_id'     => $int($r['disposal_journal_id']),
            'acquisition_journal_id'  => $int($r['acquisition_journal_id']),
            'notes'                   => $r['notes'],
            'created_at'              => $r['created_at'],
            'updated_at'              => $r['updated_at'],
        ];
    }

    /**
     * One asset for its page: the facts, the schedule (entries so far and the
     * months still to come), the linked entries, its history, and what the
     * person asking may do with it.
     */
    public function detail($id, array $claims)
    {
        $r = $this->row($id);
        if ( ! $r) return NULL;
        $a    = $this->shape($r);
        $rank = role_rank($claims['role'] ?? '');

        $entries = array_map(function ($e) use ($a) {
            return [
                'run_id'            => (int) $e['run_id'],
                'period_id'         => (int) $e['period_id'],
                'period'            => $e['period_name'],
                'month'             => $e['start_date'],
                'end_date'          => $e['end_date'],
                'amount_cents'      => (int) $e['amount_cents'],
                'accum_after_cents' => (int) $e['accum_after_cents'],
                'nbv_after_cents'   => $a['cost_cents'] - (int) $e['accum_after_cents'],
                'journal_id'        => $e['journal_id'] !== NULL ? (int) $e['journal_id'] : NULL,
                'journal_no'        => $e['journal_no'],
            ];
        }, $this->db->query(
            'SELECT e.run_id, e.period_id, e.amount_cents, e.accum_after_cents, p.name AS period_name, p.start_date, p.end_date,
                    r.journal_id, j.journal_no
               FROM gp_depreciation_entries e
               JOIN gp_periods p ON p.id = e.period_id
               JOIN gp_depreciation_runs r ON r.id = e.run_id
          LEFT JOIN gp_journals j ON j.id = r.journal_id
              WHERE e.asset_id = ? ORDER BY p.start_date',
            [(int) $id]
        )->result_array());

        $projected = [];
        $from      = NULL;
        if ($a['status'] === 'active') {
            $from = $this->projection_start($r, $entries ? end($entries)['month'] : NULL);
            $k    = Depreciation_lib::month_index($r['depreciation_start'], $from);
            $projected = array_map(function ($m) {
                return ['month' => $m['month'], 'amount_cents' => $m['amount_cents'], 'accum_after_cents' => $m['accum_after_cents'],
                        'nbv_after_cents' => $m['nbv_after_cents']];
            }, Depreciation_lib::project($r, $a['accumulated_cents'], $k, $r['depreciation_start']));
        }

        $journal = function ($jid) {
            if ( ! $jid) return NULL;
            $j = $this->db->select('id, journal_no, entry_date, description, status, source, reversed_by_id')->get_where('gp_journals', ['id' => (int) $jid], 1)->row_array();
            return $j ? ['id' => (int) $j['id'], 'journal_no' => $j['journal_no'], 'entry_date' => $j['entry_date'], 'description' => $j['description'],
                         'status' => $j['status'], 'source' => $j['source'], 'reversed' => $j['reversed_by_id'] !== NULL] : NULL;
        };

        $disposal = NULL;
        if ($a['status'] === 'disposed') {
            list($nbv, $gain, $loss) = Depreciation_lib::disposal_split($a['cost_cents'], $a['accumulated_cents'], (int) $a['disposal_proceeds_cents']);
            $disposal = ['disposed_on' => $a['disposed_on'], 'proceeds_cents' => (int) $a['disposal_proceeds_cents'], 'nbv_cents' => $nbv,
                         'gain_cents' => $gain, 'loss_cents' => $loss, 'journal' => $journal($a['disposal_journal_id'])];
        }

        $trail = [];
        foreach ($this->db->query(
            "SELECT a.action, a.detail, a.occurred_at, a.admin_id, u.full_name, u.username
               FROM gp_admin_audit_log a LEFT JOIN gp_users u ON u.id = a.admin_id
              WHERE a.target_type = 'asset' AND a.target_id = ? ORDER BY a.id",
            [(int) $id]
        )->result_array() as $t) {
            $d = $t['detail'] !== NULL ? json_decode($t['detail'], TRUE) : NULL;
            $trail[] = ['action' => $t['action'], 'at' => $t['occurred_at'], 'who' => $t['full_name'] ?: ($t['username'] ?: '#' . (int) $t['admin_id']),
                        'detail' => is_array($d) ? $d : NULL];
        }

        $frozen = NULL;
        if ($a['status'] === 'disposed') {
            $frozen = ['fields' => array_merge(self::FROZEN, ['department_id']),
                       'reason' => 'This asset was disposed of on ' . $a['disposed_on'] . ', so only its name, serial number, location, supplier, notes and purchase entry can change.'];
        } elseif ($a['months_charged'] > 0) {
            $frozen = ['fields' => self::FROZEN,
                       'reason' => 'Depreciation has been charged on this asset (' . $a['months_charged'] . ' month' . ($a['months_charged'] === 1 ? '' : 's')
                                 . '), so its category, cost, residual value, useful life, method, depreciation start and opening accumulated depreciation are fixed.'];
        }

        return [
            'asset'               => $a,
            'category'            => $this->category($a['category_id']),
            'acquisition_journal' => $journal($a['acquisition_journal_id']),
            'disposal'            => $disposal,
            'schedule'            => [
                'entries'    => $entries,
                'projected'  => $projected,
                'from'       => $from,
                'years'      => $this->_years($a, $entries, $projected),
                'ends'       => $projected ? end($projected)['month'] : NULL,
            ],
            'frozen'              => $frozen,
            'trail'               => $trail,
            'can'                 => [
                'edit'          => $rank >= 2,
                'delete'        => $rank >= 2 && $a['status'] !== 'disposed' && $a['months_charged'] === 0,
                'dispose'       => $rank >= 3 && $a['status'] !== 'disposed',
                'undo_disposal' => $rank >= 3 && $a['status'] === 'disposed',
            ],
        ];
    }

    /**
     * The first month the next depreciation run can still charge for this
     * asset: not before its start, not before the month after the latest run
     * (runs never go back), not before the month after its own last entry —
     * and, before any run exists, not before the first fiscal year (go-live).
     */
    public function projection_start(array $asset, $last_entry_month = NULL)
    {
        $m = Depreciation_lib::month_no($asset['depreciation_start']);
        $latest = $this->db->query('SELECT p.start_date FROM gp_depreciation_runs r JOIN gp_periods p ON p.id = r.period_id ORDER BY p.start_date DESC LIMIT 1')->row_array();
        if ($latest) {
            $m = max($m, Depreciation_lib::month_no($latest['start_date']) + 1);
        } else {
            $fy = $this->db->query('SELECT MIN(start_date) AS s FROM gp_fiscal_years')->row_array();
            if ($fy && $fy['s']) $m = max($m, Depreciation_lib::month_no($fy['s']));
        }
        if ($last_entry_month) $m = max($m, Depreciation_lib::month_no($last_entry_month) + 1);
        return Depreciation_lib::month_start($m);
    }

    /** The schedule by fiscal year: charged, then projected. */
    private function _years(array $a, array $entries, array $projected)
    {
        $m0 = max(1, min(12, (int) shop_cfg('fiscal_year_start_month', 1)));
        $label = function ($ymd) use ($m0) {
            $y = (int) substr($ymd, 0, 4);
            $s = (int) substr($ymd, 5, 2) >= $m0 ? $y : $y - 1;
            return $m0 === 1 ? 'FY' . $s : 'FY' . $s . '-' . substr((string) ($s + 1), 2);
        };
        $out = [];
        $add = function ($m, $amt, $accum, $proj) use (&$out, $label, $a) {
            $k = $label($m);
            if ( ! isset($out[$k])) $out[$k] = ['label' => $k, 'depreciation_cents' => 0, 'accum_end_cents' => 0, 'nbv_end_cents' => 0, 'months' => 0, 'projected' => FALSE];
            $out[$k]['depreciation_cents'] += $amt;
            $out[$k]['accum_end_cents'] = $accum;
            $out[$k]['nbv_end_cents'] = $a['cost_cents'] - $accum;
            $out[$k]['months']++;
            if ($proj) $out[$k]['projected'] = TRUE;
        };
        foreach ($entries as $e) $add($e['month'], $e['amount_cents'], $e['accum_after_cents'], FALSE);
        foreach ($projected as $p) $add($p['month'], $p['amount_cents'], $p['accum_after_cents'], TRUE);
        return array_values($out);
    }

    // =========================================================================
    // REGISTERING AND CHANGING AN ASSET
    // =========================================================================

    /** A whole number of centavos from an int or a digit string; NULL when blank; FALSE when not a number. */
    private static function _cents($v)
    {
        if ($v === NULL || $v === '') return NULL;
        if (is_int($v)) return $v >= 0 ? $v : FALSE;
        if (is_string($v) && preg_match('/^\d{1,15}$/', trim($v))) return (int) trim($v);
        return FALSE;
    }

    private static function _date_ok($d)
    {
        return is_string($d) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /**
     * Check an asset. Blank depreciation start, residual, life and method take
     * the category's (and the Settings rule for the start).
     *
     * @return array [data, errors]
     */
    public function validate(array $in, $existing = NULL, $has_entries = FALSE)
    {
        $e = [];
        $pick = function ($k, $d = NULL) use ($in, $existing) {
            return array_key_exists($k, $in) ? $in[$k] : ($existing !== NULL && array_key_exists($k, $existing) ? $existing[$k] : $d);
        };
        $blank = function ($v) { return $v === NULL || (is_string($v) && trim($v) === ''); };

        $name = trim(preg_replace('/\s+/u', ' ', (string) $pick('name', '')));
        if ($name === '')               $e['name'] = 'Describe the asset, for example Isuzu delivery truck.';
        elseif (mb_strlen($name) > 160) $e['name'] = 'Keep the name under 160 characters.';

        $cat_id = (int) $pick('category_id', 0);
        $cat    = $cat_id ? $this->category_row($cat_id) : NULL;
        if ( ! $cat) $e['category_id'] = 'Choose a category.';
        elseif ( ! (int) $cat['is_active'] && ( ! $existing || (int) $existing['category_id'] !== $cat_id)) {
            $e['category_id'] = $cat['name'] . ' is inactive. Choose another category.';
        }

        $acq = trim((string) $pick('acquired_on', ''));
        if ( ! self::_date_ok($acq))       $e['acquired_on'] = 'Enter the date the asset was acquired.';
        elseif ($acq > company_today())    $e['acquired_on'] = 'The acquisition date cannot be in the future.';

        $start = trim((string) $pick('depreciation_start', ''));
        if ($start === '' && ! isset($e['acquired_on'])) $start = Depreciation_lib::default_start($acq, self::start_mode());
        if ( ! self::_date_ok($start) || substr($start, 8, 2) !== '01') {
            $e['depreciation_start'] = 'Depreciation starts on the first day of a month.';
        } elseif ( ! isset($e['acquired_on']) && $start < substr($acq, 0, 8) . '01') {
            $e['depreciation_start'] = 'Depreciation cannot start before the month the asset was acquired.';
        }

        $cost = self::_cents($pick('cost_cents'));
        if ( ! is_int($cost) || $cost <= 0) { $e['cost_cents'] = 'Enter what the asset cost.'; $cost = 0; }
        elseif ($cost > self::MAX_CENTS)    { $e['cost_cents'] = 'That amount is too large.'; $cost = 0; }

        $res_in = $pick('residual_cents');
        $residual = $blank($res_in) ? ($cat && $cost ? Depreciation_lib::residual_for($cost, (int) $cat['residual_bp']) : 0) : self::_cents($res_in);
        if ( ! is_int($residual))              { $e['residual_cents'] = 'Enter the residual value (0 if none).'; $residual = 0; }
        elseif ($cost && $residual > $cost)    $e['residual_cents'] = 'The residual value cannot be more than the cost.';

        $life_in = $pick('useful_life_months');
        $life = $blank($life_in) ? ($cat ? (int) $cat['useful_life_months'] : 0) : $life_in;
        if ( ! preg_match('/^\d{1,4}$/', trim((string) $life)) || (int) $life < 1 || (int) $life > Depreciation_lib::MAX_LIFE_MONTHS) {
            $e['useful_life_months'] = 'Enter the useful life in months, from 1 to 1,200 (5 years = 60 months).';
        }
        $life = (int) $life;

        $method = $pick('method');
        if ($blank($method)) $method = $cat ? $cat['method'] : 'straight_line';
        if ( ! in_array($method, Depreciation_lib::METHODS, TRUE)) $e['method'] = 'Choose straight line or declining balance.';

        $open = $pick('opening_accum_cents');
        $open = $blank($open) ? 0 : self::_cents($open);
        if ( ! is_int($open)) { $e['opening_accum_cents'] = 'Enter the depreciation charged before go-live (0 for a new asset).'; $open = 0; }
        elseif ($cost && $open > max(0, $cost - $residual)) $e['opening_accum_cents'] = 'The opening accumulated depreciation cannot be more than the cost less the residual value.';

        $dept = (int) $pick('department_id', 0);
        if ($dept) {
            $d = $this->db->get_where('gp_departments', ['id' => $dept], 1)->row_array();
            if ( ! $d) $e['department_id'] = 'Choose a department from the list.';
            elseif ( ! (int) $d['is_active'] && ( ! $existing || (int) $existing['department_id'] !== $dept)) $e['department_id'] = $d['code'] . ' is inactive. Choose an active department.';
        }

        $sup = (int) $pick('supplier_id', 0);
        if ($sup) {
            $s = $this->db->get_where('gp_contacts', ['id' => $sup], 1)->row_array();
            if ( ! $s || ! (int) $s['is_supplier']) $e['supplier_id'] = 'Choose a supplier from the list.';
            elseif ( ! (int) $s['is_active'] && ( ! $existing || (int) $existing['supplier_id'] !== $sup)) $e['supplier_id'] = $s['name'] . ' is inactive.';
        }

        $text = [];
        foreach (['location' => 120, 'serial_no' => 80, 'notes' => 500] as $k => $max) {
            $v = $k === 'notes' ? trim(sanitise_text($pick($k, ''))) : trim(preg_replace('/\s+/u', ' ', sanitise_text($pick($k, ''))));
            if (mb_strlen($v) > $max) $e[$k] = 'Keep it under ' . $max . ' characters.';
            $text[$k] = $v !== '' ? $v : NULL;
        }

        $aj = (int) $pick('acquisition_journal_id', 0);
        if ($aj && $cat) {
            $j = $this->db->select('id, journal_no, status, reversed_by_id')->get_where('gp_journals', ['id' => $aj], 1)->row_array();
            $acct = $this->db->select('code, name')->get_where('gp_accounts', ['id' => (int) $cat['asset_account_id']], 1)->row_array();
            if ( ! $j)                           $e['acquisition_journal_id'] = 'That entry does not exist.';
            elseif ($j['status'] !== 'posted')   $e['acquisition_journal_id'] = 'Only a posted entry can be linked.';
            elseif ($j['reversed_by_id'])        $e['acquisition_journal_id'] = $j['journal_no'] . ' has been reversed. Link the entry that stands.';
            else {
                $dr = (int) $this->db->query('SELECT COALESCE(SUM(debit_cents), 0) AS dr FROM gp_journal_lines WHERE journal_id = ? AND account_id = ?',
                                             [$aj, (int) $cat['asset_account_id']])->row()->dr;
                if ($dr <= 0) $e['acquisition_journal_id'] = $j['journal_no'] . ' does not debit ' . $acct['code'] . ' ' . $acct['name'] . '. Link the bill or entry that recorded the purchase.';
            }
        }

        $data = [
            'name'                   => $name,
            'category_id'            => $cat_id,
            'acquired_on'            => $acq,
            'depreciation_start'     => $start,
            'cost_cents'             => $cost,
            'residual_cents'         => $residual,
            'useful_life_months'     => $life,
            'method'                 => $method,
            'opening_accum_cents'    => $open,
            'department_id'          => $dept ?: NULL,
            'location'               => $text['location'],
            'serial_no'              => $text['serial_no'],
            'supplier_id'            => $sup ?: NULL,
            'acquisition_journal_id' => $aj ?: NULL,
            'notes'                  => $text['notes'],
        ];

        if ($existing && ($has_entries || $existing['status'] === 'disposed')) {
            $disposed = $existing['status'] === 'disposed';
            $why = $disposed ? 'Fixed: the asset has been disposed of.' : 'Fixed: depreciation has already been charged on this asset.';
            foreach (array_merge(self::FROZEN, $disposed ? ['department_id'] : []) as $k) {
                if ((string) $data[$k] !== (string) $existing[$k]) $e[$k] = $why;
            }
        }
        return [$data, $e];
    }

    /** Register an asset; its number is taken inside the same transaction. @return array [id, errors] */
    public function create(array $in, array $claims)
    {
        list($d, $e) = $this->validate($in);
        if ($e) return [0, $e];

        $id  = 0;
        $err = $this->_tx(function () use ($d, $claims, &$id) {
            $no  = self::prefix() . '-' . str_pad((string) next_sequence('doc:asset'), 4, '0', STR_PAD_LEFT);
            $now = date('Y-m-d H:i:s');
            $this->db->insert(self::T, $d + [
                'asset_no'   => $no,
                'status'     => Depreciation_lib::is_fully_depreciated($d, $d['opening_accum_cents']) ? 'fully_depreciated' : 'active',
                'created_by' => (int) $claims['user_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $id = (int) $this->db->insert_id();
            return $this->_audit($claims, 'asset.create', 'asset', $id, ['asset_no' => $no, 'name' => $d['name'], 'cost_cents' => $d['cost_cents']]);
        }, 'The asset could not be saved.');
        return $err === '' ? [$id, []] : [0, ['_' => $err]];
    }

    /** @return array errors ('_' for a refusal of the whole change) */
    public function update($id, array $in, array $claims)
    {
        $fields = [];
        $err = $this->_tx(function () use ($id, $in, $claims, &$fields) {
            $a = $this->db->query('SELECT * FROM gp_assets WHERE id = ? FOR UPDATE', [(int) $id])->row_array();
            if ( ! $a) return 'No such asset.';
            $dep = (int) $this->db->query('SELECT COALESCE(SUM(amount_cents), 0) AS s, COUNT(*) AS n FROM gp_depreciation_entries WHERE asset_id = ?', [(int) $id])->row()->s;
            $has = $this->entry_count($id) > 0;

            list($d, $e) = $this->validate($in, $a, $has);
            if ($e) { $fields = $e; return 'invalid'; }

            $status = $a['status'];
            if ($status !== 'disposed') {
                $status = Depreciation_lib::is_fully_depreciated($d, $d['opening_accum_cents'] + $dep) ? 'fully_depreciated' : 'active';
            }
            $this->db->where('id', (int) $id)->update(self::T, $d + ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')]);

            $changed = [];
            foreach ($d as $k => $v) if ((string) $v !== (string) $a[$k]) $changed[$k] = ['from' => $a[$k], 'to' => $v];
            return $this->_audit($claims, 'asset.update', 'asset', (int) $id, ['asset_no' => $a['asset_no'], 'changed' => $changed]);
        }, 'The asset could not be saved.');
        if ($fields) return $fields;
        return $err === '' ? [] : ['_' => $err];
    }

    /** Only an asset nothing has been posted for: no depreciation, not disposed. @return string error */
    public function delete($id, array $claims)
    {
        return $this->_tx(function () use ($id, $claims) {
            $a = $this->db->query('SELECT * FROM gp_assets WHERE id = ? FOR UPDATE', [(int) $id])->row_array();
            if ( ! $a) return 'No such asset.';
            if ($a['status'] === 'disposed') return $a['asset_no'] . ' has been disposed of, so it stays on record.';
            if ($this->entry_count($id) > 0)  return $a['asset_no'] . ' has depreciation entries, so it stays on record. Dispose of it instead.';
            $this->db->where('id', (int) $id)->delete(self::T);
            return $this->_audit($claims, 'asset.delete', 'asset', (int) $id, ['asset_no' => $a['asset_no'], 'name' => $a['name'], 'cost_cents' => (int) $a['cost_cents']]);
        }, 'The asset could not be deleted.');
    }

    // =========================================================================
    // PICKERS
    // =========================================================================

    /** What the asset form and the disposal dialog choose from. */
    public function lookups()
    {
        $cats = array_map(function ($c) {
            return ['id' => $c['id'], 'name' => $c['name'], 'method' => $c['method'], 'useful_life_months' => $c['useful_life_months'],
                    'residual_bp' => $c['residual_bp'], 'asset_account' => $c['asset_account'], 'is_active' => $c['is_active']];
        }, $this->categories());

        $contacts = function ($flag) {
            return array_map(function ($c) { return ['id' => (int) $c['id'], 'code' => $c['code'], 'name' => $c['name']]; },
                $this->db->select('id, code, name')->where($flag, 1)->where('is_active', 1)->order_by('name')->get('gp_contacts')->result_array());
        };

        return [
            'categories'        => $cats,
            'departments'       => array_map(function ($d) { return ['id' => (int) $d['id'], 'code' => $d['code'], 'name' => $d['name']]; },
                                    $this->db->select('id, code, name')->where('is_active', 1)->order_by('code')->get('gp_departments')->result_array()),
            'suppliers'         => $contacts('is_supplier'),
            'customers'         => $contacts('is_customer'),
            'proceeds_accounts' => $this->proceeds_accounts(),
            'gain_account'      => $this->_default_account('acct_gain_on_disposal'),
            'loss_account'      => $this->_default_account('acct_loss_on_disposal'),
            'methods'           => Depreciation_lib::METHOD_LABELS,
            'start_mode'        => self::start_mode(),
            'prefix'            => self::prefix(),
            'today'             => company_today(),
        ];
    }

    /** Where disposal proceeds may go: cash and bank accounts, and receivables (not loans or allowances). */
    public function proceeds_accounts()
    {
        return array_map(function ($a) {
            return ['id' => (int) $a['id'], 'code' => $a['code'], 'name' => $a['name'], 'control' => $a['control'], 'cash' => $a['cash_flow'] === 'cash'];
        }, $this->db->query(
            "SELECT id, code, name, control, cash_flow FROM gp_accounts
              WHERE is_header = 0 AND is_active = 1 AND type = 'asset' AND is_contra = 0
                AND (cash_flow = 'cash' OR FIND_IN_SET('receivable', tags) > 0 OR FIND_IN_SET('trade_receivable', tags) > 0)
                AND FIND_IN_SET('loans', tags) = 0
              ORDER BY sort_order, code"
        )->result_array());
    }

    /** Posted entries that debit $account_id — the purchase an asset can be linked to — newest first. */
    public function acquisition_journals($account_id, $limit = 60)
    {
        $rows = $this->db->query(
            "SELECT j.id, j.journal_no, j.entry_date, j.description, j.party_name, j.reference, j.source, SUM(l.debit_cents) AS debit_cents
               FROM gp_journals j JOIN gp_journal_lines l ON l.journal_id = j.id
              WHERE j.status = 'posted' AND j.reversed_by_id IS NULL AND j.source NOT IN ('reversal', 'disposal', 'depreciation')
                AND l.account_id = ? AND l.debit_cents > 0
              GROUP BY j.id ORDER BY j.entry_date DESC, j.id DESC LIMIT " . (int) $limit,
            [(int) $account_id]
        )->result_array();

        $linked = [];
        if ($rows) {
            foreach ($this->db->select('acquisition_journal_id AS j, asset_no')->where_in('acquisition_journal_id', array_column($rows, 'id'))
                              ->order_by('asset_no')->get(self::T)->result_array() as $x) {
                $linked[(int) $x['j']][] = $x['asset_no'];
            }
        }
        return array_map(function ($r) use ($linked) {
            return ['id' => (int) $r['id'], 'journal_no' => $r['journal_no'], 'entry_date' => $r['entry_date'], 'description' => $r['description'],
                    'party_name' => $r['party_name'], 'reference' => $r['reference'], 'source' => $r['source'],
                    'debit_cents' => (int) $r['debit_cents'], 'linked' => $linked[(int) $r['id']] ?? []];
        }, $rows);
    }

    /** A Settings account default (an account CODE) as {id, code, name}, or NULL when unset or unusable. */
    private function _default_account($key)
    {
        $code = trim((string) shop_cfg($key, ''));
        if ($code === '') return NULL;
        $a = $this->db->get_where('gp_accounts', ['code' => $code], 1)->row_array();
        if ( ! $a) return NULL;
        return ['id' => (int) $a['id'], 'code' => $a['code'], 'name' => $a['name'], 'usable' => ! (int) $a['is_header'] && (int) $a['is_active'],
                'requires_department' => (bool) (int) $a['requires_department']];
    }

    // =========================================================================
    // DISPOSAL
    // =========================================================================

    /**
     * Sell, scrap or write off an asset: one posted journal and the asset marked
     * disposed, in one transaction under the fixed-asset lock.
     *
     * @param array $in  disposed_on, proceeds_cents, proceeds_account_id, contact_id (a receivables
     *                   control account needs the customer), sold_to, reason
     * @return array [journal id, errors] — errors carry field keys (422) or '_' (409)
     */
    public function dispose($id, array $in, array $claims)
    {
        $e = [];
        $date = trim((string) ($in['disposed_on'] ?? ''));
        if ( ! self::_date_ok($date))       $e['disposed_on'] = 'Enter the date of the disposal.';
        elseif ($date > company_today())    $e['disposed_on'] = 'The disposal date cannot be in the future.';

        $proceeds = self::_cents($in['proceeds_cents'] ?? 0);
        if ($proceeds === NULL) $proceeds = 0;
        if ( ! is_int($proceeds) || $proceeds > self::MAX_CENTS) { $e['proceeds_cents'] = 'Enter the amount received (0 if the asset was scrapped or lost).'; $proceeds = 0; }

        $acct = NULL;
        $contact = NULL;
        if ($proceeds > 0) {
            $aid = (int) ($in['proceeds_account_id'] ?? 0);
            foreach ($this->proceeds_accounts() as $p) if ($p['id'] === $aid) $acct = $p;
            if ( ! $acct) $e['proceeds_account_id'] = 'Choose where the proceeds went: a cash or bank account, or a receivable.';
            elseif ($acct['control'] === 'ar') {
                $cid = (int) ($in['contact_id'] ?? 0);
                $contact = $cid ? $this->db->get_where('gp_contacts', ['id' => $cid], 1)->row_array() : NULL;
                if ( ! $contact || ! (int) $contact['is_customer'] || ! (int) $contact['is_active']) {
                    $e['contact_id'] = $acct['code'] . ' is the receivables control account: choose the customer who owes the proceeds.';
                    $contact = NULL;
                }
            }
        }
        $reason = trim(preg_replace('/\s+/u', ' ', sanitise_text($in['reason'] ?? '')));
        if ($reason === '')             $e['reason'] = 'Say what happened to the asset: sold, scrapped, lost, donated.';
        elseif (mb_strlen($reason) > 300) $e['reason'] = 'Keep the reason under 300 characters.';
        $sold_to = trim(preg_replace('/\s+/u', ' ', sanitise_text($in['sold_to'] ?? '')));
        if (mb_strlen($sold_to) > 160)  $e['sold_to'] = 'Keep the name under 160 characters.';
        if ($e) return [0, $e];

        if ( ! $this->lock()) return [0, ['_' => 'A depreciation run or another disposal is being posted. Try again in a moment.']];
        $jid = 0;
        $fields = [];
        try {
            $err = $this->_tx(function () use ($id, $date, $proceeds, $acct, $contact, $reason, $sold_to, $claims, &$jid, &$fields) {
                $a = $this->db->query('SELECT * FROM gp_assets WHERE id = ? FOR UPDATE', [(int) $id])->row_array();
                if ( ! $a) return 'No such asset.';
                if ($a['status'] === 'disposed') return $a['asset_no'] . ' was already disposed of on ' . $a['disposed_on'] . '.';
                if ($date < $a['acquired_on']) { $fields['disposed_on'] = 'The asset was acquired on ' . $a['acquired_on'] . '; date the disposal on or after that.'; return 'invalid'; }

                $late = $this->db->query(
                    'SELECT p.name FROM gp_depreciation_entries e JOIN gp_periods p ON p.id = e.period_id
                      WHERE e.asset_id = ? AND p.end_date >= ? ORDER BY p.start_date DESC LIMIT 1',
                    [(int) $id, $date]
                )->row_array();
                if ($late) {
                    return 'The depreciation run for ' . $late['name'] . ' includes ' . $a['asset_no'] . ', and nothing is depreciated in the month of disposal or after. '
                         . 'Undo that run on the Depreciation screen first, then dispose of the asset and run the month again.';
                }

                $c = $this->db->query(
                    'SELECT c.name, aa.id AS asset_id, aa.code AS asset_code, aa.is_active AS asset_active, aa.requires_department AS asset_rd,
                            ac.id AS accum_id, ac.code AS accum_code, ac.is_active AS accum_active, ac.requires_department AS accum_rd
                       FROM gp_asset_categories c
                       JOIN gp_accounts aa ON aa.id = c.asset_account_id
                       JOIN gp_accounts ac ON ac.id = c.accum_account_id
                      WHERE c.id = ?', [(int) $a['category_id']]
                )->row_array();

                $dep   = (int) $this->db->query('SELECT COALESCE(SUM(amount_cents), 0) AS s FROM gp_depreciation_entries WHERE asset_id = ?', [(int) $id])->row()->s;
                $accum = (int) $a['opening_accum_cents'] + $dep;
                $cost  = (int) $a['cost_cents'];
                list($nbv, $gain, $loss) = Depreciation_lib::disposal_split($cost, $accum, $proceeds);
                $dept = $a['department_id'] !== NULL ? (int) $a['department_id'] : NULL;

                $pl = function ($key, $what) use ($dept) {
                    $x = $this->_default_account($key);
                    if ( ! $x || ! $x['usable']) {
                        throw new DomainException('Set the ' . $what . ' account in Settings → Account defaults first (an administrator can).');
                    }
                    if ($x['requires_department'] && ! $dept) throw new DomainException($x['code'] . ' needs a department: give the asset a department first.');
                    return $x['id'];
                };
                $need = function ($rd) use ($dept) { return $rd ? $dept : NULL; };

                $no = $a['asset_no'] . ' ' . $a['name'];
                $lines = [];
                if ($accum > 0) $lines[] = ['account_id' => (int) $c['accum_id'], 'debit_cents' => $accum, 'credit_cents' => 0,
                                            'memo' => mb_substr('Accumulated depreciation of ' . $no, 0, 255), 'department_id' => $need((int) $c['accum_rd']), 'contact_id' => NULL];
                if ($proceeds > 0) $lines[] = ['account_id' => $acct['id'], 'debit_cents' => $proceeds, 'credit_cents' => 0,
                                               'memo' => mb_substr('Proceeds from ' . $no, 0, 255), 'department_id' => NULL, 'contact_id' => $contact ? (int) $contact['id'] : NULL];
                if ($loss > 0) $lines[] = ['account_id' => $pl('acct_loss_on_disposal', 'loss on disposal'), 'debit_cents' => $loss, 'credit_cents' => 0,
                                           'memo' => mb_substr('Loss on disposal of ' . $no, 0, 255), 'department_id' => $dept, 'contact_id' => NULL];
                $lines[] = ['account_id' => (int) $c['asset_id'], 'debit_cents' => 0, 'credit_cents' => $cost,
                            'memo' => mb_substr('Cost of ' . $no, 0, 255), 'department_id' => $need((int) $c['asset_rd']), 'contact_id' => NULL];
                if ($gain > 0) $lines[] = ['account_id' => $pl('acct_gain_on_disposal', 'gain on disposal'), 'debit_cents' => 0, 'credit_cents' => $gain,
                                           'memo' => mb_substr('Gain on disposal of ' . $no, 0, 255), 'department_id' => $dept, 'contact_id' => NULL];
                if (((int) $c['accum_rd'] || (int) $c['asset_rd']) && ! $dept) {
                    return $c['name'] . '\'s accounts need a department: give ' . $a['asset_no'] . ' a department first.';
                }

                $party = $sold_to !== '' ? $sold_to : ($contact ? $contact['name'] : NULL);
                $head = [
                    'book'        => 'general',
                    'entry_date'  => $date,
                    'reference'   => $a['asset_no'],
                    'party_name'  => $party,
                    'description' => mb_substr('Disposal of ' . $no . ($proceeds > 0 ? ', proceeds ' . money_format_cents($proceeds) : ', no proceeds') . ': ' . $reason, 0, 500),
                ];
                $this->load->model('Journal_model', 'journals');
                list($jid, $errs) = $this->journals->post_system($head, $lines, (int) $claims['user_id'], 'disposal', (int) $id);
                if ( ! $jid) return (string) reset($errs);

                $this->db->where('id', (int) $id)->update(self::T, [
                    'status' => 'disposed', 'disposed_on' => $date, 'disposal_proceeds_cents' => $proceeds,
                    'disposal_journal_id' => $jid, 'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $j = $this->journals->find($jid);
                return $this->_audit($claims, 'asset.dispose', 'asset', (int) $id, [
                    'asset_no' => $a['asset_no'], 'disposed_on' => $date, 'journal_id' => $jid, 'journal_no' => $j['journal_no'],
                    'proceeds_cents' => $proceeds, 'accumulated_cents' => $accum, 'nbv_cents' => $nbv, 'gain_cents' => $gain, 'loss_cents' => $loss,
                    'reason' => $reason,
                ]);
            }, 'The disposal could not be posted. Nothing was changed.');
        } finally {
            $this->unlock();
        }
        if ($fields) return [0, $fields];
        return $err === '' ? [$jid, []] : [0, ['_' => $err]];
    }

    /**
     * Take a disposal back: its journal reversed on the disposal date (that
     * month must still be open) and the asset in use again.
     *
     * @return array [reversal journal id, error]
     */
    public function undo_disposal($id, $reason, array $claims)
    {
        $reason = trim(preg_replace('/\s+/u', ' ', sanitise_text($reason)));
        if ($reason === '')               return [0, 'Say why the disposal is taken back.'];
        if (mb_strlen($reason) > 300)     return [0, 'Keep the reason under 300 characters.'];

        if ( ! $this->lock()) return [0, 'A depreciation run or a disposal is being posted. Try again in a moment.'];
        $rid = 0;
        try {
            $err = $this->_tx(function () use ($id, $reason, $claims, &$rid) {
                $a = $this->db->query('SELECT * FROM gp_assets WHERE id = ? FOR UPDATE', [(int) $id])->row_array();
                if ( ! $a) return 'No such asset.';
                if ($a['status'] !== 'disposed' || ! $a['disposal_journal_id']) return $a['asset_no'] . ' is not disposed of.';

                $this->load->model('Journal_model', 'journals');
                list($rid, $e) = $this->journals->reverse_system((int) $a['disposal_journal_id'], (int) $claims['user_id'], $a['disposed_on'], $reason, 'disposal');
                if ( ! $rid) return $e;

                $dep = (int) $this->db->query('SELECT COALESCE(SUM(amount_cents), 0) AS s FROM gp_depreciation_entries WHERE asset_id = ?', [(int) $id])->row()->s;
                $status = Depreciation_lib::is_fully_depreciated($a, (int) $a['opening_accum_cents'] + $dep) ? 'fully_depreciated' : 'active';
                $this->db->where('id', (int) $id)->update(self::T, [
                    'status' => $status, 'disposed_on' => NULL, 'disposal_proceeds_cents' => NULL, 'disposal_journal_id' => NULL,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                $r = $this->journals->find($rid);
                return $this->_audit($claims, 'asset.undo_disposal', 'asset', (int) $id, [
                    'asset_no' => $a['asset_no'], 'journal_id' => (int) $a['disposal_journal_id'], 'reversal_id' => $rid,
                    'reversal_no' => $r['journal_no'], 'disposed_on' => $a['disposed_on'], 'reason' => $reason,
                ]);
            }, 'The disposal could not be taken back. Nothing was changed.');
        } finally {
            $this->unlock();
        }
        return $err === '' ? [$rid, ''] : [0, $err];
    }

    // =========================================================================
    // THE LAPSING SCHEDULE
    // =========================================================================

    /**
     * Every asset held during [$from, $to], by category: cost and accumulated
     * depreciation at the start, what came in and went out, and where they
     * stand at the end — then tied to the ledger balances of the category
     * accounts on $to.
     *
     * Depreciation entries count on their period's end date and disposals on
     * their date, exactly as their journals sit in the ledger, so the register
     * and the ledger can be compared on any date. An asset's opening
     * accumulated depreciation counts from the start (it is inside the opening
     * balance at go-live).
     */
    public function lapsing($from, $to)
    {
        $cats = [];
        foreach ($this->categories() as $c) $cats[$c['id']] = $c + ['rows' => []];

        $ent = [];
        foreach ($this->db->query(
            'SELECT e.asset_id,
                    SUM(CASE WHEN p.end_date < ? THEN e.amount_cents ELSE 0 END) AS before_c,
                    SUM(CASE WHEN p.end_date >= ? AND p.end_date <= ? THEN e.amount_cents ELSE 0 END) AS in_c
               FROM gp_depreciation_entries e JOIN gp_periods p ON p.id = e.period_id
              GROUP BY e.asset_id',
            [$from, $from, $to]
        )->result_array() as $r) {
            $ent[(int) $r['asset_id']] = [(int) $r['before_c'], (int) $r['in_c']];
        }

        $mon_to  = Depreciation_lib::month_no($to);
        $to_full = $to === date('Y-m-t', strtotime($to));
        foreach ($this->db->query('SELECT * FROM gp_assets WHERE acquired_on <= ? ORDER BY asset_no', [$to])->result_array() as $a) {
            $disposed = $a['status'] === 'disposed' && $a['disposed_on'] !== NULL && $a['disposed_on'] <= $to;
            $held     = $a['acquired_on'] < $from && ! ($disposed && $a['disposed_on'] < $from);
            $added    = $a['acquired_on'] >= $from;
            if ( ! $held && ! $added) continue;                           // gone before the period began
            $out      = $disposed && $a['disposed_on'] >= $from;
            list($before, $in) = $ent[(int) $a['id']] ?? [0, 0];

            $cost        = (int) $a['cost_cents'];
            $cost_start  = $held ? $cost : 0;
            $additions   = $added ? $cost : 0;
            $disposals   = $out ? $cost : 0;
            $accum_start = (int) $a['opening_accum_cents'] + $before;
            $accum_out   = $out ? $accum_start + $in : 0;
            $accum_end   = $accum_start + $in - $accum_out;
            $cost_end    = $cost_start + $additions - $disposals;
            $base        = max(0, $cost - (int) $a['residual_cents']);

            $remaining = NULL;
            if ( ! $out && $base > 0) {
                if ($accum_end >= $base) $remaining = 0;
                else {
                    $life    = (int) $a['useful_life_months'];
                    $elapsed = $mon_to - Depreciation_lib::month_no($a['depreciation_start']) + ($to_full ? 1 : 0);
                    $remaining = $life - max(0, min($life, $elapsed));
                }
            }

            $cid = (int) $a['category_id'];
            $cats[$cid]['rows'][] = [
                'id'                    => (int) $a['id'],
                'asset_no'              => $a['asset_no'],
                'name'                  => $a['name'],
                'acquired_on'           => $a['acquired_on'],
                'life_months'           => (int) $a['useful_life_months'],
                'status'                => $out ? 'disposed' : ($base > 0 && $accum_end >= $base ? 'fully_depreciated' : 'active'),
                'disposed_on'           => $out ? $a['disposed_on'] : NULL,
                'cost_start_cents'      => $cost_start,
                'additions_cents'       => $additions,
                'disposals_cents'       => $disposals,
                'cost_end_cents'        => $cost_end,
                'accum_start_cents'     => $accum_start,
                'depreciation_cents'    => $in,
                'accum_disposals_cents' => $accum_out,
                'accum_end_cents'       => $accum_end,
                'nbv_end_cents'         => $cost_end - $accum_end,
                'months_remaining'      => $remaining,
            ];
        }

        $keys = ['cost_start_cents', 'additions_cents', 'disposals_cents', 'cost_end_cents', 'accum_start_cents', 'depreciation_cents',
                 'accum_disposals_cents', 'accum_end_cents', 'nbv_end_cents'];
        $zero  = array_fill_keys($keys, 0);
        $total = $zero;
        $out   = [];
        $book  = [];                                           // account id => register amount
        foreach ($cats as $c) {
            $sub = $zero;
            foreach ($c['rows'] as $r) foreach ($keys as $k) $sub[$k] += $r[$k];
            foreach ($keys as $k) $total[$k] += $sub[$k];
            $book['cost'][$c['asset_account']['id']]['cents'] = ($book['cost'][$c['asset_account']['id']]['cents'] ?? 0) + $sub['cost_end_cents'];
            $book['cost'][$c['asset_account']['id']]['categories'][] = $c['name'];
            $book['accum'][$c['accum_account']['id']]['cents'] = ($book['accum'][$c['accum_account']['id']]['cents'] ?? 0) + $sub['accum_end_cents'];
            $book['accum'][$c['accum_account']['id']]['categories'][] = $c['name'];
            if ($c['rows']) {
                $out[] = ['id' => $c['id'], 'name' => $c['name'], 'asset_account' => $c['asset_account'], 'accum_account' => $c['accum_account'],
                          'rows' => $c['rows'], 'subtotal' => $sub];
            }
        }

        $ids = array_merge(array_keys($book['cost'] ?? []), array_keys($book['accum'] ?? []));
        $bal = [];
        $accts = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach ($this->db->query('SELECT account_id, SUM(net_cents) AS n FROM gp_ledger WHERE entry_date <= ? AND account_id IN (' . $in . ') GROUP BY account_id',
                                      array_merge([$to], $ids))->result_array() as $r) {
                $bal[(int) $r['account_id']] = (int) $r['n'];
            }
            foreach ($this->db->select('id, code, name')->where_in('id', $ids)->get('gp_accounts')->result_array() as $r) $accts[(int) $r['id']] = $r;
        }

        $tie  = [];
        $ties = TRUE;
        foreach (['cost' => 1, 'accum' => -1] as $kind => $sign) {
            foreach ($book[$kind] ?? [] as $aid => $x) {
                $ledger = $sign * ($bal[$aid] ?? 0);
                $diff   = $x['cents'] - $ledger;
                if ($diff !== 0) $ties = FALSE;
                $tie[] = ['kind' => $kind === 'cost' ? 'cost' : 'accumulated', 'account_id' => $aid, 'code' => $accts[$aid]['code'] ?? '',
                          'name' => $accts[$aid]['name'] ?? '', 'categories' => array_values(array_unique($x['categories'])),
                          'register_cents' => $x['cents'], 'ledger_cents' => $ledger, 'difference_cents' => $diff];
            }
        }
        usort($tie, function ($p, $q) { return strcmp($p['code'], $q['code']); });

        $others = [];
        $args = [$to];
        $not = '';
        if ($ids) { $not = ' AND a.id NOT IN (' . implode(',', array_fill(0, count($ids), '?')) . ')'; $args = array_merge($args, $ids); }
        foreach ($this->db->query(
            "SELECT a.id, a.code, a.name, a.is_contra, SUM(l.net_cents) AS n
               FROM gp_accounts a JOIN gp_ledger l ON l.account_id = a.id
              WHERE a.is_header = 0 AND FIND_IN_SET('ppe', a.tags) > 0 AND l.entry_date <= ?" . $not . "
              GROUP BY a.id, a.code, a.name, a.is_contra HAVING SUM(l.net_cents) <> 0 ORDER BY a.code",
            $args
        )->result_array() as $r) {
            $others[] = ['account_id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name'],
                         'balance_cents' => ((int) $r['is_contra'] ? -1 : 1) * (int) $r['n']];
        }

        return ['from' => $from, 'to' => $to, 'categories' => $out, 'total' => $total, 'tie_out' => $tie, 'others' => $others, 'ties' => $ties];
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    /**
     * Run $fn in a transaction: '' commits; an error string, a DomainException
     * or any other exception rolls back. A posting refusal from Journal_model
     * (a RuntimeException fit for the user) passes through; a database error
     * never does — it is logged and $fallback is shown instead.
     */
    public function _tx(callable $fn, $fallback)
    {
        $this->db->trans_begin();
        try {
            $err = (string) $fn();
        } catch (DomainException $d) {
            $err = $d->getMessage();
        } catch (Throwable $t) {
            $err = self::plain_error($t, $fallback);
        }
        if ($err === '') {
            $this->db->trans_commit();
            return '';
        }
        $this->db->trans_rollback();
        return $err;
    }

    public static function plain_error(Throwable $t, $fallback)
    {
        log_message('error', '[fixed assets] ' . get_class($t) . ': ' . $t->getMessage());
        if ($t instanceof mysqli_sql_exception) return $fallback;
        if ($t instanceof RuntimeException && $t->getMessage() !== '') return $t->getMessage();
        return $fallback;
    }

    /** '' when the audit row is written; otherwise the error that rolls the change back. */
    private function _audit(array $claims, $action, $type, $id, array $detail)
    {
        return log_admin_action($claims, $action, $type, (int) $id, $detail)
            ? '' : 'The audit trail could not be written, so nothing was saved.';
    }
}
