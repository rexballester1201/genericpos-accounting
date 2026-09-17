<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Asset_categories.php — fixed-asset categories
 *
 * GenericPOS Accounting · fixed assets
 *
 *   GET    /api/v1/asset-categories         every role: the categories, their accounts, and the account pickers
 *   POST   /api/v1/asset-categories         accountant: { name, asset_account_id, accum_account_id, expense_account_id,
 *                                                         method, useful_life_months, residual_bp }
 *   PUT    /api/v1/asset-categories/{id}    accountant: the same, and is_active
 *   DELETE /api/v1/asset-categories/{id}    accountant: only a category no asset has used
 *
 * A category suggests a method, life and residual value for new assets and
 * supplies the three accounts. Changing it never changes an existing asset,
 * which carries its own method, life and residual; once a category has
 * assets its accounts are fixed (Asset_model).
 */
class Asset_categories extends CI_Controller
{
    const FIELDS = ['name', 'asset_account_id', 'accum_account_id', 'expense_account_id', 'method', 'useful_life_months', 'residual_bp', 'is_active'];

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('Asset_model', 'assets');
    }

    /** GET /api/v1/asset-categories */
    public function index()
    {
        $claims = viewer_check();
        require_method('GET');
        return json_response([
            'items'    => $this->assets->categories(),
            'accounts' => $this->assets->category_accounts(),
            'methods'  => Depreciation_lib::METHOD_LABELS,
            'can_edit' => role_rank($claims['role']) >= 3,
        ], 'Asset categories');
    }

    /** POST /api/v1/asset-categories */
    public function create()
    {
        $claims = accountant_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        list($id, $e) = $this->assets->category_create($this->_input(), $claims);
        if ( ! $id) return $this->_fail($e);
        $c = $this->assets->category($id);
        return json_response(['category' => $c], 'Category ' . $c['name'] . ' added.', 201);
    }

    /** PUT /api/v1/asset-categories/{id} */
    public function update($id)
    {
        $claims = accountant_check();
        require_method('PUT');
        rate_limit((int) $claims['user_id'], 'admin_write');
        if ( ! $this->assets->category_row((int) $id)) return json_error('That category does not exist.', 404);

        $e = $this->assets->category_update((int) $id, $this->_input(), $claims);
        if ($e) return $this->_fail($e);
        $c = $this->assets->category((int) $id);
        return json_response(['category' => $c], 'Category ' . $c['name'] . ' saved.');
    }

    /** DELETE /api/v1/asset-categories/{id} */
    public function delete($id)
    {
        $claims = accountant_check();
        require_method('DELETE');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $c = $this->assets->category_row((int) $id);
        if ( ! $c) return json_error('That category does not exist.', 404);

        $err = $this->assets->category_delete((int) $id, $claims);
        if ($err !== '') return json_error($err, 409);
        return json_response(['id' => (int) $id], 'Category ' . $c['name'] . ' deleted.');
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

    private function _fail(array $e)
    {
        if (isset($e['_']) && count($e) === 1) return json_error($e['_'], $e['_'] === 'No such category.' ? 404 : 409);
        return json_invalid($e);
    }
}
