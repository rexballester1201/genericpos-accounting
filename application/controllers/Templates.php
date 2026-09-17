<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Templates.php — saved and recurring journal entries
 *
 * GenericPOS Accounting · bookkeepers and above
 *
 *   GET    /api/v1/journal-templates              every saved entry, with its lines resolved
 *   POST   /api/v1/journal-templates              save one { name, book, description, reference,
 *                                                 party_name, lines, recur_day?, next_date?, is_active? }
 *   GET    /api/v1/journal-templates/{id}
 *   PUT    /api/v1/journal-templates/{id}         change it — its creator, or an accountant or administrator
 *   DELETE /api/v1/journal-templates/{id}         the same people
 *   POST   /api/v1/journal-templates/{id}/draft   make a draft from it now { entry_date? }
 *
 * Saved entries are shared by the office: anyone who prepares entries can use
 * any of them. Recurring ones are drafted by `php index.php tools cron`.
 */
class Templates extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('Template_model', 'templates');
    }

    /** GET /api/v1/journal-templates */
    public function index()
    {
        bookkeeper_check();
        require_method('GET');
        return json_response(['items' => $this->templates->listing(), 'today' => company_today()], 'Saved entries');
    }

    /** GET /api/v1/journal-templates/{id} */
    public function show($id)
    {
        bookkeeper_check();
        require_method('GET');
        $t = $this->_find($id);
        return json_response($this->templates->present($t), $t['name']);
    }

    /** POST /api/v1/journal-templates */
    public function create()
    {
        $claims = bookkeeper_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        list($row, $e) = $this->templates->validate(get_json_body());
        if ($e) return json_invalid($e);
        $id = $this->templates->create($row, (int) $claims['user_id']);
        log_admin_action($claims, 'template.create', 'journal_template', $id, ['name' => $row['name'], 'recur_day' => $row['recur_day']]);
        return json_response($this->templates->present($this->templates->find($id)), 'Saved as "' . $row['name'] . '".', 201);
    }

    /** PUT /api/v1/journal-templates/{id} */
    public function update($id)
    {
        $claims = bookkeeper_check();
        require_method('PUT');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $t = $this->_find($id);
        $this->_may_change($t, $claims);
        list($row, $e) = $this->templates->validate(get_json_body());
        if ($e) return json_invalid($e);
        $this->templates->update((int) $t['id'], $row);
        log_admin_action($claims, 'template.update', 'journal_template', (int) $t['id'], ['name' => $row['name'], 'recur_day' => $row['recur_day'], 'is_active' => $row['is_active']]);
        return json_response($this->templates->present($this->templates->find((int) $t['id'])), '"' . $row['name'] . '" is saved.');
    }

    /** DELETE /api/v1/journal-templates/{id} */
    public function delete($id)
    {
        $claims = bookkeeper_check();
        require_method('DELETE');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $t = $this->_find($id);
        $this->_may_change($t, $claims);
        $this->templates->delete((int) $t['id']);
        log_admin_action($claims, 'template.delete', 'journal_template', (int) $t['id'], ['name' => $t['name']]);
        return json_response(NULL, '"' . $t['name'] . '" is deleted. Entries made from it are not affected.');
    }

    /** POST /api/v1/journal-templates/{id}/draft */
    public function draft($id)
    {
        $claims = bookkeeper_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $t    = $this->_find($id);
        $date = trim((string) (get_json_body()['entry_date'] ?? '')) ?: company_today();
        list($jid, $e) = $this->templates->make_draft($t, (int) $claims['user_id'], $date);
        if ( ! $jid) return json_invalid($e);
        return json_response(['journal_id' => $jid], 'A draft is ready from "' . $t['name'] . '".', 201);
    }

    private function _find($id)
    {
        $t = $this->templates->find((int) $id);
        if ( ! $t) json_error('That saved entry does not exist.', 404);
        return $t;
    }

    private function _may_change(array $t, array $claims)
    {
        if ((int) $t['created_by'] !== (int) $claims['user_id'] && role_rank($claims['role']) < 3) {
            json_error('Only the person who saved it, or an accountant, can change or delete "' . $t['name'] . '".', 403);
        }
    }
}
