<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Settlements.php — receipts from customers and payments to suppliers
 *
 * GenericPOS Accounting · receivables and payables module
 *
 *   GET    /api/v1/settlements/lookups       ?kind=receipt|payment — customers or suppliers, cash and bank accounts,
 *                                            open periods, today, whether the withholding accounts are set
 *   GET    /api/v1/settlements               ?kind=&status=&state=unapplied&contact_id=&from=&to=&q=&page=&per_page=
 *   GET    /api/v1/settlements/{id}          the receipt or payment, what it settled (or plans to), its journal,
 *                                            the amount in words, history, letterhead, can
 *   POST   /api/v1/settlements               bookkeeper — a draft { kind, contact_id, settle_date, cash_account_id,
 *                                            reference, amount_cents, withholding_cents, description,
 *                                            allocations: [{document_id, amount_cents}], then }
 *   PUT    /api/v1/settlements/{id}          its preparer — the same, for a draft
 *   DELETE /api/v1/settlements/{id}          its preparer or an accountant — a draft
 *   POST   /api/v1/settlements/{id}/post     accountant — number it, post its journal, write its allocations
 *   POST   /api/v1/settlements/{id}/cancel   accountant — { date, reason }: remove its allocations, reverse its journal
 *   POST   /api/v1/settlements/{id}/apply    bookkeeper — apply what is still unapplied: { allocations }
 */
class Settlements extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('Settlement_model', 'settlements');
    }

    /** GET /api/v1/settlements/lookups */
    public function lookups()
    {
        viewer_check();
        require_method('GET');
        $kind = (string) $this->input->get('kind') === 'payment' ? 'payment' : 'receipt';
        $contacts = array_map(function ($c) {
            return ['id' => (int) $c['id'], 'code' => $c['code'], 'name' => $c['name'], 'ewt_rate_bp' => (int) $c['ewt_rate_bp'], 'tin' => $c['tin']];
        }, $this->db->where($kind === 'receipt' ? 'is_customer' : 'is_supplier', 1)->where('is_active', 1)
                    ->order_by('name', 'ASC')->get('gp_contacts')->result_array());

        return json_response([
            'kind'          => $kind,
            'contacts'      => $contacts,
            'cash_accounts' => $this->arap->cash_accounts(),
            'withholding'   => [
                'account_set'  => $this->arap->account_id_for($kind === 'receipt' ? 'acct_cwt_receivable' : 'acct_ewt_payable') > 0,
                'cwt_rate_bp'  => 100,
            ],
            'open_periods'  => $this->periods->open_periods(),
            'today'         => company_today(),
            'policy'        => ['self_approve' => $this->journals->may_self_approve()],
        ], 'Lookups');
    }

    /** GET /api/v1/settlements */
    public function index()
    {
        viewer_check();
        require_method('GET');
        $p = get_pagination_params(25, 100);
        $f = [
            'kind'       => (string) $this->input->get('kind'),
            'status'     => (string) $this->input->get('status'),
            'state'      => (string) $this->input->get('state'),
            'contact_id' => (int) $this->input->get('contact_id'),
            'from'       => (string) $this->input->get('from'),
            'to'         => (string) $this->input->get('to'),
            'q'          => (string) $this->input->get('q'),
        ];
        list($rows, $total, $totals, $counts) = $this->settlements->search($f, $p['limit'], $p['offset']);
        $out = build_pagination_meta($rows, $total, $p['page'], $p['limit']);
        $out['totals'] = $totals;
        $out['counts'] = $counts;
        if ($f['contact_id']) {
            $c = $this->contacts->find($f['contact_id']);
            $out['contact'] = $c ? ['id' => (int) $c['id'], 'code' => $c['code'], 'name' => $c['name']] : NULL;
        }
        return json_response($out, $f['kind'] === 'payment' ? 'Payments' : 'Receipts');
    }

    /** GET /api/v1/settlements/{id} */
    public function show($id)
    {
        $claims = viewer_check();
        require_method('GET');
        $d = $this->_detail((int) $id, $claims);
        if ( ! $d) return json_error('That receipt or payment does not exist.', 404);
        return json_response($d, $d['settlement']['kind_label']);
    }

    /** POST /api/v1/settlements */
    public function create()
    {
        $claims = bookkeeper_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $in = get_json_body();
        list($id, $e) = $this->settlements->create_draft($in, $claims);
        if ( ! $id) return json_invalid($e);
        list($msg, $warn) = $this->_then($id, (string) ($in['then'] ?? 'draft'), $claims);
        return json_response($this->_detail($id, $claims) + ['notice' => ['text' => $msg, 'warn' => $warn]], $msg, 201);
    }

    /** PUT /api/v1/settlements/{id} */
    public function update($id)
    {
        $claims = bookkeeper_check();
        require_method('PUT');
        rate_limit((int) $claims['user_id'], 'admin_write');
        if ( ! $this->settlements->find((int) $id)) return json_error('That receipt or payment does not exist.', 404);

        $in = get_json_body();
        $e = $this->settlements->update_draft((int) $id, $in, $claims);
        if ($e) return json_invalid($e);
        list($msg, $warn) = $this->_then((int) $id, (string) ($in['then'] ?? 'draft'), $claims);
        return json_response($this->_detail((int) $id, $claims) + ['notice' => ['text' => $msg, 'warn' => $warn]], $msg);
    }

    /** DELETE /api/v1/settlements/{id} */
    public function delete($id)
    {
        $claims = bookkeeper_check();
        require_method('DELETE');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $s = $this->settlements->find((int) $id);
        if ( ! $s) return json_error('That receipt or payment does not exist.', 404);
        $err = $this->settlements->delete_draft((int) $id, $claims);
        if ($err !== '') return json_error($err, strpos($err, 'Only its preparer') === 0 ? 403 : 409);
        return json_response(['id' => (int) $id, 'kind' => $s['kind']], 'Draft ' . $s['kind'] . ' deleted.');
    }

    /** POST /api/v1/settlements/{id}/post */
    public function post($id)
    {
        $claims = accountant_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $s = $this->settlements->find((int) $id);
        if ( ! $s) return json_error('That receipt or payment does not exist.', 404);

        list($err, $no) = $this->settlements->post((int) $id, $claims);
        if ($err !== '') return json_error($err, 409);
        return json_response($this->_detail((int) $id, $claims), Arap_lib::KIND_LABELS[$s['kind']] . ' ' . $no . ' posted.');
    }

    /** POST /api/v1/settlements/{id}/cancel */
    public function cancel($id)
    {
        $claims = accountant_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $s = $this->settlements->find((int) $id);
        if ( ! $s) return json_error('That receipt or payment does not exist.', 404);

        $in = get_json_body();
        $reason = trim((string) ($in['reason'] ?? ''));
        $date   = trim((string) ($in['date'] ?? '')) ?: company_today();
        $e = [];
        if ($reason === '')                     $e['reason'] = 'Say why it is cancelled.';
        elseif (mb_strlen($reason) > 300)       $e['reason'] = 'Keep the reason under 300 characters.';
        if ( ! Period_model::valid_date($date)) $e['date'] = 'Enter the date of the reversal.';
        if ($e) return json_invalid($e);

        $err = $this->settlements->cancel((int) $id, $date, $reason, $claims);
        if ($err !== '') return json_error($err, 409);
        $out = $this->_detail((int) $id, $claims);
        return json_response($out, Arap_lib::KIND_LABELS[$s['kind']] . ' ' . $s['settle_no'] . ' cancelled'
            . ($out['reversal'] ? '; its journal was reversed by ' . $out['reversal']['journal_no'] : '') . '.');
    }

    /** POST /api/v1/settlements/{id}/apply */
    public function apply($id)
    {
        $claims = bookkeeper_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $s = $this->settlements->find((int) $id);
        if ( ! $s) return json_error('That receipt or payment does not exist.', 404);

        $in = get_json_body();
        $err = $this->settlements->apply((int) $id, (isset($in['allocations']) && is_array($in['allocations'])) ? $in['allocations'] : [], $claims);
        if ($err !== '') return json_error($err, 409);
        return json_response($this->_detail((int) $id, $claims), Arap_lib::KIND_LABELS[$s['kind']] . ' ' . $s['settle_no'] . ' applied.');
    }

    // =========================================================================

    private function _then($id, $then, array $claims)
    {
        $s = $this->settlements->find($id);
        if ($then !== 'post') return ['Draft ' . $s['kind'] . ' saved.', FALSE];
        if (role_rank($claims['role']) < 3 || ! $this->journals->may_self_approve()) {
            return ['Draft saved. An accountant other than you posts it.', FALSE];
        }
        list($err, $no) = $this->settlements->post($id, $claims);
        if ($err !== '') return ['Saved as a draft, but not posted: ' . $err, TRUE];
        return [Arap_lib::KIND_LABELS[$s['kind']] . ' ' . $no . ' posted.', FALSE];
    }

    private function _detail($id, array $claims)
    {
        $d = $this->settlements->detail($id, $claims);
        if ( ! $d) return NULL;
        $d['letterhead'] = report_letterhead($claims);
        return $d;
    }
}
