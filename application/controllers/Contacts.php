<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Contacts.php — customers and suppliers
 *
 * GenericPOS Accounting · receivables and payables module
 *
 *   GET    /api/v1/contacts/lookups        next free codes, default terms, accounts a default may point at, EWT rates
 *   GET    /api/v1/contacts                ?role=customer|supplier&q=&active=1|0|all&sort=name|code|balance&page=&per_page=
 *   GET    /api/v1/contacts/{id}           details, balances, open documents, unapplied money, drafts, recent activity
 *   POST   /api/v1/contacts                bookkeeper — add one
 *   PUT    /api/v1/contacts/{id}           bookkeeper — change one
 *   POST   /api/v1/contacts/{id}/active    bookkeeper — { active: true|false }
 *   DELETE /api/v1/contacts/{id}           bookkeeper — only one that was never used
 *
 * Every rule lives in Contact_model; this controller checks roles and shapes
 * the answers.
 */
class Contacts extends CI_Controller
{
    const EWT_RATES = [
        [0, 'None'], [100, '1 % — goods'], [200, '2 % — services'], [500, '5 % — rentals'],
        [1000, '10 % — professional fees'], [1500, '15 % — professional fees, higher bracket'],
    ];

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('Contact_model', 'contacts');
    }

    /** GET /api/v1/contacts/lookups */
    public function lookups()
    {
        viewer_check();
        require_method('GET');
        $accounts = array_map(function ($a) {
            return ['id' => (int) $a['id'], 'code' => $a['code'], 'name' => $a['name'], 'type' => $a['type'], 'active' => (bool) (int) $a['is_active']];
        }, $this->db->select('id, code, name, type, is_active')->where('is_header', 0)->where_in('type', ['income', 'expense', 'asset'])
                    ->group_start()->where('control IS NULL', NULL, FALSE)->or_where('control', '')->group_end()
                    ->order_by('sort_order', 'ASC')->order_by('code', 'ASC')->get('gp_accounts')->result_array());

        return json_response([
            'next_code' => ['customer' => $this->contacts->next_code('customer'), 'supplier' => $this->contacts->next_code('supplier')],
            'terms'     => ['customer' => $this->arap->terms_default('ar'), 'supplier' => $this->arap->terms_default('ap')],
            'accounts'  => $accounts,
            'defaults'  => ['sales' => $this->arap->account_id_for('acct_default_sales'), 'purchases' => $this->arap->account_id_for('acct_default_purchases')],
            'ewt_rates' => array_map(function ($r) { return ['bp' => $r[0], 'label' => $r[1]]; }, self::EWT_RATES),
            'today'     => company_today(),
        ], 'Lookups');
    }

    /** GET /api/v1/contacts */
    public function index()
    {
        $claims = viewer_check();
        require_method('GET');
        $p = get_pagination_params(25, 100);
        $role = (string) $this->input->get('role');
        if ( ! in_array($role, ['customer', 'supplier'], TRUE)) $role = '';
        $active = (string) $this->input->get('active');
        if ( ! in_array($active, ['1', '0', 'all'], TRUE)) $active = '1';

        list($rows, $total, $totals) = $this->contacts->search([
            'role' => $role, 'q' => (string) $this->input->get('q'), 'active' => $active,
            'sort' => (string) $this->input->get('sort'), 'today' => company_today(),
        ], $p['limit'], $p['offset']);

        $out = build_pagination_meta($rows, $total, $p['page'], $p['limit']);
        $out['totals']   = $totals;
        $out['role']     = $role;
        $out['side']     = $role === 'supplier' ? 'ap' : 'ar';
        $out['can_edit'] = role_rank($claims['role']) >= 2;
        $out['today']    = company_today();
        return json_response($out, $role === 'supplier' ? 'Suppliers' : ($role === 'customer' ? 'Customers' : 'Customers and suppliers'));
    }

    /** GET /api/v1/contacts/{id} */
    public function show($id)
    {
        $claims = viewer_check();
        require_method('GET');
        $d = $this->_detail((int) $id, $claims);
        if ( ! $d) return json_error('That customer or supplier does not exist.', 404);
        return json_response($d, $d['contact']['name']);
    }

    /** POST /api/v1/contacts */
    public function create()
    {
        $claims = bookkeeper_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        list($data, $e) = $this->contacts->validate(get_json_body());
        if ($e) return json_invalid($e);
        list($id, $err) = $this->contacts->create($data, $claims);
        if ( ! $id) return json_error($err, 409);
        return json_response($this->_detail($id, $claims), ($data['is_customer'] ? 'Customer ' : 'Supplier ') . $data['name'] . ' added.', 201);
    }

    /** PUT /api/v1/contacts/{id} */
    public function update($id)
    {
        $claims = bookkeeper_check();
        require_method('PUT');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $c = $this->contacts->find((int) $id);
        if ( ! $c) return json_error('That customer or supplier does not exist.', 404);
        list($data, $e) = $this->contacts->validate(get_json_body(), $c);
        if ($e) return json_invalid($e);
        $err = $this->contacts->update((int) $id, $data, $claims);
        if ($err !== '') return json_error($err, 409);
        return json_response($this->_detail((int) $id, $claims), $data['name'] . ' saved.');
    }

    /** POST /api/v1/contacts/{id}/active */
    public function set_active($id)
    {
        $claims = bookkeeper_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $in = get_json_body();
        if ( ! array_key_exists('active', $in)) return json_invalid(['active' => 'Say whether to activate or deactivate.']);
        $on = is_bool($in['active']) ? $in['active'] : ! in_array(strtolower(trim((string) $in['active'])), ['0', 'false', 'no', 'off', ''], TRUE);
        $c = $this->contacts->find((int) $id);
        if ( ! $c) return json_error('That customer or supplier does not exist.', 404);
        $err = $this->contacts->set_active((int) $id, $on, $claims);
        if ($err !== '') return json_error($err, 409);
        return json_response($this->_detail((int) $id, $claims), $c['name'] . ($on ? ' is active again.' : ' is now inactive.'));
    }

    /** DELETE /api/v1/contacts/{id} */
    public function delete($id)
    {
        $claims = bookkeeper_check();
        require_method('DELETE');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $c = $this->contacts->find((int) $id);
        if ( ! $c) return json_error('That customer or supplier does not exist.', 404);
        $err = $this->contacts->delete((int) $id, $claims);
        if ($err !== '') return json_error($err, 409);
        return json_response(['id' => (int) $id], $c['name'] . ' deleted.');
    }

    // =========================================================================

    private function _detail($id, array $claims)
    {
        $d = $this->contacts->detail($id, company_today());
        if ( ! $d) return NULL;
        $rank = role_rank($claims['role']);
        $c = $d['contact'];
        $d['can'] = [
            'edit'     => $rank >= 2,
            'delete'   => $rank >= 2 && $d['deletable'],
            'activate' => $rank >= 2,
            'invoice'  => $rank >= 2 && $c['is_customer'] && $c['is_active'],
            'bill'     => $rank >= 2 && $c['is_supplier'] && $c['is_active'],
            'receipt'  => $rank >= 2 && $c['is_customer'] && $c['is_active'],
            'payment'  => $rank >= 2 && $c['is_supplier'] && $c['is_active'],
        ];
        return $d;
    }
}
