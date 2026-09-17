<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Departments.php — departments, branches and cost centres
 *
 * GenericPOS Accounting · budgets and departments module
 *
 *   GET    /api/v1/departments                   the tree, with this fiscal year's income, expenses and net (every role)
 *   POST   /api/v1/departments                   { code, name, parent_id } (administrators)
 *   PUT    /api/v1/departments/{id}              { code, name, parent_id } (administrators)
 *   POST   /api/v1/departments/{id}/deactivate   (administrators)
 *   POST   /api/v1/departments/{id}/reactivate   (administrators)
 *   DELETE /api/v1/departments/{id}              only one nothing has ever used (administrators)
 *
 * The rules — codes, nesting, when one may be deactivated or deleted — live
 * in Department_model and answer with a reason.
 */
class Departments extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('Department_model', 'departments');
        $this->load->model('Period_model', 'periods');
    }

    /** GET /api/v1/departments */
    public function index()
    {
        $claims = viewer_check();
        require_method('GET');

        $today = company_today();
        $fy    = $this->periods->year_for_date($today);
        $from  = $fy ? $fy['start_date'] : substr($today, 0, 4) . '-01-01';
        $act   = $this->departments->activity($from, $today);
        $usage = $this->departments->usage();
        $tree  = $this->departments->tree();

        $kids = [];
        $roll = [];
        foreach ($tree as $d) {
            $id = (int) $d['id'];
            $roll[$id] = $act[$id] ?? Department_model::zero();
            if ($d['parent_id']) $kids[(int) $d['parent_id']] = ($kids[(int) $d['parent_id']] ?? 0) + 1;
        }
        /* Tree order puts every department before its sub-departments, so a
           backwards pass adds each one (with its own below it) into its parent. */
        for ($i = count($tree) - 1; $i >= 0; $i--) {
            $id  = (int) $tree[$i]['id'];
            $pid = (int) $tree[$i]['parent_id'];
            if ($pid && isset($roll[$pid])) foreach ($roll[$id] as $k => $v) $roll[$pid][$k] += $v;
        }

        $total = Department_model::zero();
        foreach ($act as $a) foreach ($a as $k => $v) $total[$k] += $v;

        $items = array_map(function ($d) use ($act, $usage, $kids, $roll) {
            $id   = (int) $d['id'];
            $used = Department_model::usage_text($usage[$id] ?? []);
            return $this->_shape($d) + [
                'depth'             => (int) $d['depth'],
                'children'          => $kids[$id] ?? 0,
                'ytd'               => $act[$id] ?? Department_model::zero(),
                'ytd_with_children' => ! empty($kids[$id]) ? $roll[$id] : NULL,
                'used_by'           => $used,
                'can_delete'        => $used === '' && empty($kids[$id]),
            ];
        }, $tree);

        return json_response([
            'items'         => $items,
            'no_department' => $act[0] ?? Department_model::zero(),
            'total'         => $total,
            'period'        => ['from' => $from, 'to' => $today, 'fiscal_year' => $fy ? $fy['name'] : NULL],
            'can_edit'      => role_rank($claims['role']) >= 4,
        ], 'Departments');
    }

    /** POST /api/v1/departments */
    public function create()
    {
        $claims = admin_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        list($data, $e) = $this->departments->validate(get_json_body(), NULL);
        if ($e) return json_invalid($e);

        list($id, $err) = $this->departments->create($data, $claims);
        if ( ! $id) return json_error($err, 409);

        return json_response(['department' => $this->_shape($this->departments->find($id))],
            'Department ' . $data['code'] . ' · ' . $data['name'] . ' added.', 201);
    }

    /** PUT /api/v1/departments/{id} */
    public function update($id)
    {
        $claims = admin_check();
        require_method('PUT');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $row = $this->departments->find((int) $id);
        if ( ! $row) return json_error('That department no longer exists.', 404);

        list($data, $e) = $this->departments->validate(get_json_body(), $row);
        if ($e) return json_invalid($e);

        list($changes, $err) = $this->departments->update((int) $id, $data, $claims);
        if ($changes === NULL) return json_error($err, 409);

        return json_response(['department' => $this->_shape($this->departments->find((int) $id)), 'changed' => array_keys($changes)],
            $changes ? 'Department ' . $data['code'] . ' saved.' : 'Nothing changed.');
    }

    /** POST /api/v1/departments/{id}/deactivate */
    public function deactivate($id)
    {
        return $this->_set_active((int) $id, FALSE);
    }

    /** POST /api/v1/departments/{id}/reactivate */
    public function reactivate($id)
    {
        return $this->_set_active((int) $id, TRUE);
    }

    /** DELETE /api/v1/departments/{id} */
    public function delete($id)
    {
        $claims = admin_check();
        require_method('DELETE');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $row = $this->departments->find((int) $id);
        if ( ! $row) return json_error('That department no longer exists.', 404);

        $err = $this->departments->delete((int) $id, $claims);
        if ($err !== '') return json_error($err, 409);

        return json_response(['id' => (int) $id], 'Department ' . $row['code'] . ' · ' . $row['name'] . ' deleted.');
    }

    // =========================================================================

    private function _set_active($id, $active)
    {
        $claims = admin_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $row = $this->departments->find($id);
        if ( ! $row) return json_error('That department no longer exists.', 404);

        $err = $this->departments->set_active($id, $active, $claims);
        if ($err !== '') return json_error($err, 409);

        return json_response(['department' => $this->_shape($this->departments->find($id))], $active
            ? 'Department ' . $row['code'] . ' is active again.'
            : 'Department ' . $row['code'] . ' is inactive. It no longer appears on new entries; its history stays.');
    }

    private function _shape(array $d)
    {
        return [
            'id'         => (int) $d['id'],
            'code'       => $d['code'],
            'name'       => $d['name'],
            'parent_id'  => $d['parent_id'] !== NULL ? (int) $d['parent_id'] : NULL,
            'is_active'  => (bool) (int) $d['is_active'],
            'updated_at' => $d['updated_at'],
        ];
    }
}
