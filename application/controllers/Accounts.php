<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Accounts.php — the chart of accounts
 *
 * GenericPOS Accounting
 *
 *   GET    /api/v1/accounts        the whole chart in tree order, with balances today (every role)
 *   POST   /api/v1/accounts        add an account (administrators)
 *   PUT    /api/v1/accounts/{id}   change one (administrators)
 *   DELETE /api/v1/accounts/{id}   delete one that nothing has used (administrators)
 *
 * The rules — what freezes once an account has entries, when one may be
 * deactivated — live in Account_model and answer with a reason.
 */
class Accounts extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('Account_model', 'accounts');
    }

    /** GET /api/v1/accounts */
    public function index()
    {
        $claims = viewer_check();
        require_method('GET');

        $today = company_today();
        $tree  = $this->accounts->tree();
        list($any, $posted) = $this->accounts->usage();
        $net   = $this->accounts->balances($today);

        /* Headers roll their children up: walking the tree bottom-up (children
           come after their parent in tree order) adds each account's
           debit-positive balance into its parent's. */
        $roll = [];
        foreach ($tree as $a) $roll[(int) $a['id']] = $net[(int) $a['id']] ?? 0;
        for ($i = count($tree) - 1; $i >= 0; $i--) {
            $pid = (int) $tree[$i]['parent_id'];
            if ($pid && isset($roll[$pid])) $roll[$pid] += $roll[(int) $tree[$i]['id']];
        }

        $items = array_map(function ($a) use ($any, $posted, $roll) {
            $id   = (int) $a['id'];
            $side = (int) $a['is_header'] ? Account_model::normal_side_for($a['type'], FALSE) : $a['normal_side'];
            return $this->_shape($a) + [
                'depth'         => (int) $a['depth'],
                'has_lines'     => isset($any[$id]),
                'has_postings'  => isset($posted[$id]),
                'default_role'  => $this->accounts->default_role($a['code']) ?: NULL,
                'balance_cents' => $side === 'D' ? $roll[$id] : -$roll[$id],
            ];
        }, $tree);

        return json_response([
            'items'    => $items,
            'as_of'    => $today,
            'can_edit' => role_rank($claims['role']) >= 4,
            'meta'     => [
                'types'       => Account_model::TYPE_LABELS,
                'subtypes'    => Account_model::SUBTYPES,
                'cash_flows'  => Account_model::CASH_FLOWS,
                'tags'        => Account_model::TAGS,
            ],
        ], 'Chart of accounts');
    }

    /** POST /api/v1/accounts */
    public function create()
    {
        $claims = admin_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        list($data, $e) = $this->accounts->validate(get_json_body(), NULL);
        if ($e) return json_invalid($e);

        $id = $this->accounts->create($data);
        log_admin_action($claims, 'account.create', 'account', $id, ['code' => $data['code'], 'name' => $data['name'], 'type' => $data['type']]);
        return json_response(['account' => $this->_shape($this->accounts->find($id))], 'Account ' . $data['code'] . ' added.', 201);
    }

    /** PUT /api/v1/accounts/{id} */
    public function update($id)
    {
        $claims = admin_check();
        require_method('PUT');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $row = $this->accounts->find((int) $id);
        if ( ! $row) return json_error('That account no longer exists.', 404);

        list($data, $e) = $this->accounts->validate(get_json_body(), $row);
        if ($e) return json_invalid($e);

        $changes = [];
        foreach ($data as $k => $v) {
            if ((string) $row[$k] !== (string) $v) $changes[$k] = ['from' => $row[$k], 'to' => $v];
        }
        if ($changes) {
            $this->accounts->update((int) $id, $data);
            log_admin_action($claims, 'account.update', 'account', (int) $id, ['code' => $data['code']] + $changes);
        }
        return json_response(['account' => $this->_shape($this->accounts->find((int) $id)), 'changed' => array_keys($changes)],
            $changes ? 'Account ' . $data['code'] . ' saved.' : 'Nothing changed.');
    }

    /** DELETE /api/v1/accounts/{id} */
    public function delete($id)
    {
        $claims = admin_check();
        require_method('DELETE');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $row = $this->accounts->find((int) $id);
        if ( ! $row) return json_error('That account no longer exists.', 404);

        $err = $this->accounts->delete((int) $id);
        if ($err !== '') return json_error($err, 409);

        log_admin_action($claims, 'account.delete', 'account', (int) $id, ['code' => $row['code'], 'name' => $row['name']]);
        return json_response(['id' => (int) $id], 'Account ' . $row['code'] . ' deleted.');
    }

    private function _shape(array $a)
    {
        return [
            'id'                  => (int) $a['id'],
            'code'                => $a['code'],
            'name'                => $a['name'],
            'parent_id'           => $a['parent_id'] !== NULL ? (int) $a['parent_id'] : NULL,
            'is_header'           => (bool) (int) $a['is_header'],
            'type'                => $a['type'],
            'subtype'             => $a['subtype'],
            'is_contra'           => (bool) (int) $a['is_contra'],
            'normal_side'         => $a['normal_side'],
            'cash_flow'           => $a['cash_flow'],
            'control'             => $a['control'],
            'requires_department' => (bool) (int) $a['requires_department'],
            'tags'                => $a['tags'] !== '' ? explode(',', $a['tags']) : [],
            'description'         => $a['description'],
            'is_active'           => (bool) (int) $a['is_active'],
        ];
    }
}
