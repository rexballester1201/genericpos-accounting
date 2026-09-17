<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Assets.php — the fixed-asset register, and disposals
 *
 * GenericPOS Accounting · fixed assets
 *
 *   GET    /api/v1/assets/lookups               categories, departments, suppliers, customers, proceeds accounts, settings
 *   GET    /api/v1/assets/acquisition-journals  ?category= (or ?account=) posted entries that debit its asset account
 *   GET    /api/v1/assets                       ?status=in_use|active|fully_depreciated|disposed|all&category=&department=&q=
 *                                               &page=&per_page= · &format=csv the whole list as a spreadsheet
 *   GET    /api/v1/assets/{id}                  the asset, its schedule, its entries, its history, what you may do
 *   POST   /api/v1/assets                       bookkeeper: register an asset (its number is given on saving)
 *   PUT    /api/v1/assets/{id}                  bookkeeper: change it (what depreciation used is fixed once charged)
 *   DELETE /api/v1/assets/{id}                  bookkeeper: only an asset with no depreciation and no disposal
 *   POST   /api/v1/assets/{id}/dispose          accountant: { disposed_on, proceeds_cents, proceeds_account_id, contact_id,
 *                                                             sold_to, reason } — posts the disposal
 *   POST   /api/v1/assets/{id}/undo-disposal    accountant: { reason } — reverses it
 *
 * Amounts are integer centavos. Every rule lives in Asset_model; this
 * controller checks roles and shapes input and output.
 */
class Assets extends CI_Controller
{
    const FIELDS = ['name', 'category_id', 'acquired_on', 'depreciation_start', 'cost_cents', 'residual_cents', 'useful_life_months', 'method',
                    'opening_accum_cents', 'department_id', 'location', 'serial_no', 'supplier_id', 'acquisition_journal_id', 'notes'];

    const STATUS_LABELS = ['active' => 'Active', 'fully_depreciated' => 'Fully depreciated', 'disposed' => 'Disposed'];

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('Asset_model', 'assets');
    }

    /** GET /api/v1/assets/lookups */
    public function lookups()
    {
        viewer_check();
        require_method('GET');
        return json_response($this->assets->lookups(), 'Lookups');
    }

    /** GET /api/v1/assets/acquisition-journals */
    public function acquisition_journals()
    {
        viewer_check();
        require_method('GET');
        $account = (int) $this->input->get('account');
        $cat_id  = (int) $this->input->get('category');
        if ($cat_id) {
            $c = $this->assets->category_row($cat_id);
            if ( ! $c) return json_error('That category does not exist.', 404);
            $account = (int) $c['asset_account_id'];
        }
        if ($account <= 0) return json_invalid(['category' => 'Choose a category.']);
        $a = $this->db->select('id, code, name')->get_where('gp_accounts', ['id' => $account], 1)->row_array();
        if ( ! $a) return json_error('That account does not exist.', 404);
        return json_response([
            'account' => ['id' => (int) $a['id'], 'code' => $a['code'], 'name' => $a['name']],
            'items'   => $this->assets->acquisition_journals($account),
        ], 'Entries that debit ' . $a['code']);
    }

    /** GET /api/v1/assets */
    public function index()
    {
        $claims = viewer_check();
        require_method('GET');

        $st = $this->input->get('status');
        $st = $st === NULL ? 'in_use' : (string) $st;
        if ($st === 'all') $st = '';
        $f = [
            'status'        => $st,
            'category_id'   => (int) $this->input->get('category'),
            'department_id' => (int) $this->input->get('department'),
            'q'             => (string) $this->input->get('q'),
        ];

        if (report_csv_wanted($claims)) {
            list($rows, , $t) = $this->assets->search($f, 100000, 0);
            $today = company_today();
            $out = report_csv_head('Fixed asset register', 'As of ' . $today . ($st !== '' ? ' · ' . ($st === 'in_use' ? 'assets in use' : strtolower(self::STATUS_LABELS[$st] ?? $st)) : ''));
            $out[] = ['Number', 'Asset', 'Category', 'Acquired', 'Depreciation starts', 'Method', 'Life (months)', 'Cost', 'Residual value',
                      'Accumulated depreciation', 'Net book value', 'Status', 'Disposed on', 'Department', 'Location', 'Serial no.'];
            foreach ($rows as $r) {
                $out[] = [$r['asset_no'], $r['name'], $r['category_name'], $r['acquired_on'], $r['depreciation_start'], $r['method_label'], $r['useful_life_months'],
                          money_major($r['cost_cents']), money_major($r['residual_cents']), money_major($r['accumulated_cents']), money_major($r['nbv_cents']),
                          self::STATUS_LABELS[$r['status']] ?? $r['status'], (string) $r['disposed_on'], (string) $r['department'], (string) $r['location'], (string) $r['serial_no']];
            }
            $out[] = ['', 'Total (' . $t['count'] . ' assets)', '', '', '', '', '', money_major($t['cost_cents']), '', money_major($t['accumulated_cents']), money_major($t['nbv_cents'])];
            return report_csv('fixed-asset-register-' . $today . '.csv', $out);
        }

        $p = get_pagination_params(50, 200);
        list($rows, $total, $totals, $counts) = $this->assets->search($f, $p['limit'], $p['offset']);
        $out = build_pagination_meta($rows, $total, $p['page'], $p['limit']);
        $out += [
            'totals'      => $totals,
            'counts'      => $counts,
            'status'      => $st === '' ? 'all' : $st,
            'categories'  => array_map(function ($c) { return ['id' => $c['id'], 'name' => $c['name'], 'is_active' => $c['is_active']]; }, $this->assets->categories()),
            'departments' => array_map(function ($d) { return ['id' => (int) $d['id'], 'code' => $d['code'], 'name' => $d['name']]; },
                              $this->db->select('id, code, name')->order_by('code')->get('gp_departments')->result_array()),
            'can_create'  => role_rank($claims['role']) >= 2,
        ];
        return json_response($out, 'Fixed assets');
    }

    /** GET /api/v1/assets/{id} */
    public function show($id)
    {
        $claims = viewer_check();
        require_method('GET');
        $d = $this->assets->detail((int) $id, $claims);
        if ( ! $d) return json_error('That asset does not exist.', 404);
        return json_response($d, 'Asset');
    }

    /** POST /api/v1/assets */
    public function create()
    {
        $claims = bookkeeper_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        list($id, $e) = $this->assets->create($this->_input(), $claims);
        if ( ! $id) return $this->_fail($e);
        $d = $this->assets->detail($id, $claims);
        return json_response($d, 'Asset ' . $d['asset']['asset_no'] . ' registered.', 201);
    }

    /** PUT /api/v1/assets/{id} */
    public function update($id)
    {
        $claims = bookkeeper_check();
        require_method('PUT');
        rate_limit((int) $claims['user_id'], 'admin_write');
        if ( ! $this->assets->find((int) $id)) return json_error('That asset does not exist.', 404);

        $e = $this->assets->update((int) $id, $this->_input(), $claims);
        if ($e) return $this->_fail($e);
        $d = $this->assets->detail((int) $id, $claims);
        return json_response($d, 'Asset ' . $d['asset']['asset_no'] . ' saved.');
    }

    /** DELETE /api/v1/assets/{id} */
    public function delete($id)
    {
        $claims = bookkeeper_check();
        require_method('DELETE');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $a = $this->assets->find((int) $id);
        if ( ! $a) return json_error('That asset does not exist.', 404);

        $err = $this->assets->delete((int) $id, $claims);
        if ($err !== '') return json_error($err, 409);
        return json_response(['id' => (int) $id], 'Asset ' . $a['asset_no'] . ' deleted.');
    }

    /** POST /api/v1/assets/{id}/dispose */
    public function dispose($id)
    {
        $claims = accountant_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $a = $this->assets->find((int) $id);
        if ( ! $a) return json_error('That asset does not exist.', 404);

        $in = get_json_body();
        list($jid, $e) = $this->assets->dispose((int) $id, [
            'disposed_on'         => (string) ($in['disposed_on'] ?? ''),
            'proceeds_cents'      => $in['proceeds_cents'] ?? 0,
            'proceeds_account_id' => (int) ($in['proceeds_account_id'] ?? 0),
            'contact_id'          => (int) ($in['contact_id'] ?? 0),
            'sold_to'             => (string) ($in['sold_to'] ?? ''),
            'reason'              => (string) ($in['reason'] ?? ''),
        ], $claims);
        if ( ! $jid) return $this->_fail($e);

        $this->load->model('Journal_model', 'journals');
        $j = $this->journals->find($jid);
        return json_response($this->assets->detail((int) $id, $claims) + ['journal_id' => $jid],
            $a['asset_no'] . ' disposed of: posted as ' . $j['journal_no'] . '.');
    }

    /** POST /api/v1/assets/{id}/undo-disposal */
    public function undo_disposal($id)
    {
        $claims = accountant_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $a = $this->assets->find((int) $id);
        if ( ! $a) return json_error('That asset does not exist.', 404);

        $reason = trim((string) (get_json_body()['reason'] ?? ''));
        if ($reason === '') return json_invalid(['reason' => 'Say why the disposal is taken back.']);

        list($rid, $err) = $this->assets->undo_disposal((int) $id, $reason, $claims);
        if ( ! $rid) return json_error($err, 409);

        $this->load->model('Journal_model', 'journals');
        $r = $this->journals->find($rid);
        return json_response($this->assets->detail((int) $id, $claims) + ['reversal_id' => $rid],
            'Disposal of ' . $a['asset_no'] . ' taken back: reversed by ' . $r['journal_no'] . '.');
    }

    // =========================================================================

    /** Only the fields the request carries, so an update keeps the rest. */
    private function _input()
    {
        $in  = get_json_body();
        $out = [];
        foreach (self::FIELDS as $k) if (array_key_exists($k, $in)) $out[$k] = $in[$k];
        return $out;
    }

    /** Field errors → 422; a refusal of the whole request → 409 (404 when the asset is gone). */
    private function _fail(array $e)
    {
        if (isset($e['_']) && count($e) === 1) return json_error($e['_'], $e['_'] === 'No such asset.' ? 404 : 409);
        unset($e['_']);
        return json_invalid($e);
    }
}
