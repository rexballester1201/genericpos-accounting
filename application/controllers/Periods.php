<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Periods.php — fiscal years and their months
 *
 * GenericPOS Accounting
 *
 *   GET  /api/v1/fiscal-years             every year with its periods (every role)
 *   POST /api/v1/fiscal-years             open the next year, or the first { start } (administrators)
 *   POST /api/v1/periods/{id}/status      { status: closed | open | locked }
 *                                         close and reopen: accountants · lock: administrators
 *
 * Years follow on from each other: once one exists, the next always starts
 * the day after it ends, whatever the request says.
 */
class Periods extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('Period_model', 'periods');
    }

    /** GET /api/v1/fiscal-years */
    public function index()
    {
        $claims = viewer_check();
        require_method('GET');
        return json_response($this->_state($claims), 'Fiscal years');
    }

    /** POST /api/v1/fiscal-years */
    public function create_year()
    {
        $claims = admin_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $next  = $this->periods->next_year_start();
        $start = $next ?: trim((string) (get_json_body()['start'] ?? ''));
        list($id, $err) = $this->periods->create_year($start, (int) $claims['user_id']);
        if ( ! $id) return json_invalid(['start' => $err]);

        $fy = $this->periods->year($id);
        return json_response($this->_state($claims), $fy['name'] . ' is open, ' . $fy['start_date'] . ' to ' . $fy['end_date'] . '.', 201);
    }

    /** POST /api/v1/periods/{id}/status */
    public function status($id)
    {
        $claims = accountant_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $to = (string) (get_json_body()['status'] ?? '');
        if ( ! in_array($to, ['open', 'closed', 'locked'], TRUE)) return json_invalid(['status' => 'Choose open, closed or locked.']);
        if ($to === 'locked' && role_rank($claims['role']) < 4) return json_error('Only an administrator can lock a period.', 403);

        $p = $this->periods->period((int) $id);
        if ( ! $p) return json_error('That period does not exist.', 404);

        $err = $this->periods->set_status((int) $id, $to, (int) $claims['user_id']);
        if ($err !== '') return json_error($err, 409);

        $done = ['closed' => ' is closed.', 'open' => ' is open again.', 'locked' => ' is locked for good.'][$to];
        return json_response($this->_state($claims), $p['name'] . $done);
    }

    private function _state(array $claims)
    {
        $rank = role_rank($claims['role']);
        return [
            'years'       => $this->periods->overview(),
            'next_start'  => $this->periods->next_year_start(),
            'start_month' => (int) ($this->config->item('fiscal_year_start_month') ?: 1),
            'today'       => company_today(),
            'can'         => ['close' => $rank >= 3, 'lock' => $rank >= 4, 'create_year' => $rank >= 4],
        ];
    }
}
