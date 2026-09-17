<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Year_end.php — closing a fiscal year, a co-op's net-surplus allocation, reopening
 *
 * GenericPOS Accounting · administrators only (PLAN.md §8)
 *
 *   GET  /api/v1/year-end?fiscal_year_id=          the checklist, the closing entry it would post,
 *                                                  the allocation, what can be done now
 *   POST /api/v1/year-end/{id}/close               { confirm: "FY2026", allocate?, percentages? }
 *   POST /api/v1/year-end/{id}/reopen              { confirm: "FY2026", reason }
 *   POST /api/v1/year-end/{id}/allocate            { date, percentages? }       co-ops, after closing
 *   POST /api/v1/year-end/{id}/allocation/undo     { reason, date? }
 *
 * Closing and reopening ask for the year's name typed back: an API call made
 * by mistake must not close a year. The work itself is Year_end_model's.
 */
class Year_end extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('Year_end_model', 'yearend');
        $this->load->model('Period_model', 'periods');
    }

    /** GET /api/v1/year-end */
    public function index()
    {
        admin_check();
        require_method('GET');
        $s = $this->yearend->status((int) $this->input->get('fiscal_year_id'));
        if ($s === NULL) return json_error('That fiscal year does not exist.', 404);
        return json_response($s, 'Year-end');
    }

    /** POST /api/v1/year-end/{id}/close */
    public function close($id)
    {
        $claims = admin_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $fy = $this->_year($id);
        $in = get_json_body();
        if ( ! $this->_confirmed($in, $fy)) return json_invalid(['confirm' => 'Type ' . $fy['name'] . ' to confirm.']);

        list($r, $err) = $this->yearend->close((int) $fy['id'], (int) $claims['user_id'], [
            'allocate'    => ! empty($in['allocate']),
            'percentages' => isset($in['percentages']) && is_array($in['percentages']) ? $in['percentages'] : NULL,
        ]);
        if ($r === NULL) return json_error($err, 409);

        return json_response($this->yearend->status((int) $fy['id']) + ['result' => $r], $fy['name'] . ' is closed.');
    }

    /** POST /api/v1/year-end/{id}/reopen */
    public function reopen($id)
    {
        $claims = admin_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $fy = $this->_year($id);
        $in = get_json_body();
        if ( ! $this->_confirmed($in, $fy)) return json_invalid(['confirm' => 'Type ' . $fy['name'] . ' to confirm.']);
        if (trim((string) ($in['reason'] ?? '')) === '') return json_invalid(['reason' => 'Say why the year is reopened.']);

        list($r, $err) = $this->yearend->reopen((int) $fy['id'], (int) $claims['user_id'], (string) $in['reason']);
        if ($r === NULL) return json_error($err, 409);

        return json_response($this->yearend->status((int) $fy['id']) + ['result' => ['reversal_ids' => $r]], $fy['name'] . ' is open again.');
    }

    /** POST /api/v1/year-end/{id}/allocate */
    public function allocate($id)
    {
        $claims = admin_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $fy = $this->_year($id);
        $in = get_json_body();
        list($jid, $err) = $this->yearend->allocate((int) $fy['id'], (int) $claims['user_id'], (string) ($in['date'] ?? ''),
            isset($in['percentages']) && is_array($in['percentages']) ? $in['percentages'] : NULL);
        if ($jid === NULL) return json_error($err, 409);

        return json_response($this->yearend->status((int) $fy['id']) + ['result' => ['journal_id' => $jid]], 'The net surplus of ' . $fy['name'] . ' is allocated.');
    }

    /** POST /api/v1/year-end/{id}/allocation/undo */
    public function undo_allocation($id)
    {
        $claims = admin_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $fy = $this->_year($id);
        $in = get_json_body();
        list($rid, $err) = $this->yearend->undo_allocation((int) $fy['id'], (int) $claims['user_id'], (string) ($in['date'] ?? ''), (string) ($in['reason'] ?? ''));
        if ($rid === NULL) return json_error($err, 409);

        return json_response($this->yearend->status((int) $fy['id']) + ['result' => ['reversal_id' => $rid]], 'The allocation is undone.');
    }

    private function _year($id)
    {
        $fy = $this->periods->year((int) $id);
        if ( ! $fy) json_error('That fiscal year does not exist.', 404);
        return $fy;
    }

    private function _confirmed(array $in, array $fy)
    {
        return strcasecmp(trim((string) ($in['confirm'] ?? '')), $fy['name']) === 0;
    }
}
