<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Documents.php — invoices and credit notes, bills and debit notes
 *
 * GenericPOS Accounting · receivables and payables module
 *
 *   GET    /api/v1/documents/lookups         ?side=sales|purchases — customers or suppliers, accounts, departments,
 *                                            VAT settings, default accounts, open periods, today
 *   GET    /api/v1/documents/open-items      ?side=ar|ap&contact_id= — open invoices or bills, unapplied notes
 *   GET    /api/v1/documents                 ?side=&type=&status=&state=open|overdue|paid&contact_id=&from=&to=&q=&page=&per_page=
 *   GET    /api/v1/documents/{id}            the document, its lines, what settled it, its journal, history, letterhead, can
 *   POST   /api/v1/documents                 bookkeeper — a draft { doc_type, contact_id, doc_date, due_date, reference,
 *                                            description, prices_include_tax, related_document_id, lines[], then }
 *   PUT    /api/v1/documents/{id}            its preparer — the same, for a draft
 *   DELETE /api/v1/documents/{id}            its preparer or an accountant — a draft
 *   POST   /api/v1/documents/{id}/post       accountant — number it and post its journal
 *   POST   /api/v1/documents/{id}/cancel     accountant — { date, reason }: reverse its journal, mark it cancelled
 *   POST   /api/v1/documents/{id}/apply      bookkeeper — a posted note: { allocations: [{document_id, amount_cents}] }
 *   POST   /api/v1/allocations/{id}/remove   accountant — { reason }
 *
 * `then: 'post'` on a save posts at once when the person may post their own
 * draft (an accountant, with self-approval on). Amounts are integer centavos.
 */
class Documents extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('Document_model', 'documents');
    }

    /** GET /api/v1/documents/lookups */
    public function lookups()
    {
        viewer_check();
        require_method('GET');
        $side = (string) $this->input->get('side') === 'purchases' ? 'purchases' : 'sales';
        $ls   = $side === 'sales' ? 'ar' : 'ap';

        $contacts = array_map(function ($c) {
            return ['id' => (int) $c['id'], 'code' => $c['code'], 'name' => $c['name'], 'tin' => $c['tin'],
                    'terms_days' => $c['terms_days'] !== NULL ? (int) $c['terms_days'] : NULL,
                    'default_account_id' => $c['default_account_id'] !== NULL ? (int) $c['default_account_id'] : NULL,
                    'vat_registered' => (bool) (int) $c['vat_registered'], 'ewt_rate_bp' => (int) $c['ewt_rate_bp'],
                    'credit_limit_cents' => $c['credit_limit_cents'] !== NULL ? (int) $c['credit_limit_cents'] : NULL];
        }, $this->db->where($ls === 'ar' ? 'is_customer' : 'is_supplier', 1)->where('is_active', 1)
                    ->order_by('name', 'ASC')->get('gp_contacts')->result_array());

        $accounts = array_map(function ($a) {
            return ['id' => (int) $a['id'], 'code' => $a['code'], 'name' => $a['name'], 'type' => $a['type'],
                    'active' => (bool) (int) $a['is_active'], 'needs_department' => (bool) (int) $a['requires_department']];
        }, $this->db->select('id, code, name, type, is_active, requires_department')->where('is_header', 0)
                    ->group_start()->where('control IS NULL', NULL, FALSE)->or_where('control', '')->group_end()
                    ->order_by('sort_order', 'ASC')->order_by('code', 'ASC')->get('gp_accounts')->result_array());

        $departments = array_map(function ($d) {
            return ['id' => (int) $d['id'], 'code' => $d['code'], 'name' => $d['name']];
        }, $this->db->select('id, code, name')->where('is_active', 1)->order_by('code', 'ASC')->get('gp_departments')->result_array());

        $rate = $this->arap->rate_bp();
        return json_response([
            'side'        => $side,
            'contacts'    => $contacts,
            'accounts'    => $accounts,
            'departments' => $departments,
            'tax'         => ['registered' => $this->arap->vat_registered(), 'rate_bp' => $rate,
                              'rate_pct' => rtrim(rtrim(number_format($rate / 100, 2, '.', ''), '0'), '.'),
                              'inclusive' => $this->arap->inclusive_default(), 'label' => (string) shop_cfg('tax_label', 'VAT')],
            'defaults'    => ['account_id' => $this->arap->account_id_for($side === 'sales' ? 'acct_default_sales' : 'acct_default_purchases'),
                              'terms_days' => $this->arap->terms_default($ls)],
            'open_periods' => $this->periods->open_periods(),
            'today'       => company_today(),
            'policy'      => ['self_approve' => $this->journals->may_self_approve()],
        ], 'Lookups');
    }

    /** GET /api/v1/documents/open-items */
    public function open_items()
    {
        viewer_check();
        require_method('GET');
        $side = (string) $this->input->get('side') === 'ap' ? 'ap' : 'ar';
        $c = $this->contacts->find((int) $this->input->get('contact_id'));
        if ( ! $c) return json_error('That customer or supplier does not exist.', 404);
        $items = $this->documents->open_items($side, (int) $c['id']);
        return json_response(['contact' => $this->contacts->shape($c), 'side' => $side] + $items, 'Open items');
    }

    /** GET /api/v1/documents */
    public function index()
    {
        viewer_check();
        require_method('GET');
        $p = get_pagination_params(25, 100);
        $f = [
            'side'       => (string) $this->input->get('side'),
            'type'       => (string) $this->input->get('type'),
            'status'     => (string) $this->input->get('status'),
            'state'      => (string) $this->input->get('state'),
            'contact_id' => (int) $this->input->get('contact_id'),
            'from'       => (string) $this->input->get('from'),
            'to'         => (string) $this->input->get('to'),
            'q'          => (string) $this->input->get('q'),
            'today'      => company_today(),
        ];
        list($rows, $total, $totals, $counts) = $this->documents->search($f, $p['limit'], $p['offset']);
        $out = build_pagination_meta($rows, $total, $p['page'], $p['limit']);
        $out['totals'] = $totals;
        $out['counts'] = $counts;
        $out['today']  = $f['today'];
        if ($f['contact_id']) {
            $c = $this->contacts->find($f['contact_id']);
            $out['contact'] = $c ? ['id' => (int) $c['id'], 'code' => $c['code'], 'name' => $c['name']] : NULL;
        }
        return json_response($out, 'Documents');
    }

    /** GET /api/v1/documents/{id} */
    public function show($id)
    {
        $claims = viewer_check();
        require_method('GET');
        $d = $this->_detail((int) $id, $claims);
        if ( ! $d) return json_error('That document does not exist.', 404);
        return json_response($d, $d['document']['type_label']);
    }

    /** POST /api/v1/documents */
    public function create()
    {
        $claims = bookkeeper_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $in = get_json_body();
        list($id, $e) = $this->documents->create_draft($in, $claims);
        if ( ! $id) return json_invalid($e);
        list($msg, $warn) = $this->_then($id, (string) ($in['then'] ?? 'draft'), $claims);
        return json_response($this->_detail($id, $claims) + ['notice' => ['text' => $msg, 'warn' => $warn]], $msg, 201);
    }

    /** PUT /api/v1/documents/{id} */
    public function update($id)
    {
        $claims = bookkeeper_check();
        require_method('PUT');
        rate_limit((int) $claims['user_id'], 'admin_write');
        if ( ! $this->documents->find((int) $id)) return json_error('That document does not exist.', 404);

        $in = get_json_body();
        $e = $this->documents->update_draft((int) $id, $in, $claims);
        if ($e) return json_invalid($e);
        list($msg, $warn) = $this->_then((int) $id, (string) ($in['then'] ?? 'draft'), $claims);
        return json_response($this->_detail((int) $id, $claims) + ['notice' => ['text' => $msg, 'warn' => $warn]], $msg);
    }

    /** DELETE /api/v1/documents/{id} */
    public function delete($id)
    {
        $claims = bookkeeper_check();
        require_method('DELETE');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $d = $this->documents->find((int) $id);
        if ( ! $d) return json_error('That document does not exist.', 404);
        $err = $this->documents->delete_draft((int) $id, $claims);
        if ($err !== '') return json_error($err, strpos($err, 'Only its preparer') === 0 ? 403 : 409);
        return json_response(['id' => (int) $id, 'doc_type' => $d['doc_type']], 'Draft ' . Document_model::word($d['doc_type']) . ' deleted.');
    }

    /** POST /api/v1/documents/{id}/post */
    public function post($id)
    {
        $claims = accountant_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $d = $this->documents->find((int) $id);
        if ( ! $d) return json_error('That document does not exist.', 404);

        list($err, $no) = $this->documents->post((int) $id, $claims);
        if ($err !== '') return json_error($err, 409);
        return json_response($this->_detail((int) $id, $claims), Arap_lib::TYPE_LABELS[$d['doc_type']] . ' ' . $no . ' posted.');
    }

    /** POST /api/v1/documents/{id}/cancel */
    public function cancel($id)
    {
        $claims = accountant_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $d = $this->documents->find((int) $id);
        if ( ! $d) return json_error('That document does not exist.', 404);

        $in = get_json_body();
        $reason = trim((string) ($in['reason'] ?? ''));
        $date   = trim((string) ($in['date'] ?? '')) ?: company_today();
        $e = [];
        if ($reason === '')                     $e['reason'] = 'Say why it is cancelled.';
        elseif (mb_strlen($reason) > 300)       $e['reason'] = 'Keep the reason under 300 characters.';
        if ( ! Period_model::valid_date($date)) $e['date'] = 'Enter the date of the reversal.';
        if ($e) return json_invalid($e);

        $err = $this->documents->cancel((int) $id, $date, $reason, $claims);
        if ($err !== '') return json_error($err, 409);
        $out = $this->_detail((int) $id, $claims);
        return json_response($out, Arap_lib::TYPE_LABELS[$d['doc_type']] . ' ' . $d['doc_no'] . ' cancelled'
            . ($out['reversal'] ? '; its journal was reversed by ' . $out['reversal']['journal_no'] : '') . '.');
    }

    /** POST /api/v1/documents/{id}/apply */
    public function apply($id)
    {
        $claims = bookkeeper_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $d = $this->documents->find((int) $id);
        if ( ! $d) return json_error('That document does not exist.', 404);

        $in = get_json_body();
        $allocs = (isset($in['allocations']) && is_array($in['allocations'])) ? $in['allocations'] : [];
        $err = $this->documents->apply((int) $id, $allocs, $claims);
        if ($err !== '') return json_error($err, 409);
        return json_response($this->_detail((int) $id, $claims), Arap_lib::TYPE_LABELS[$d['doc_type']] . ' ' . $d['doc_no'] . ' applied.');
    }

    /** POST /api/v1/allocations/{id}/remove */
    public function remove_allocation($id)
    {
        $claims = accountant_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $reason = trim((string) (get_json_body()['reason'] ?? ''));
        if ($reason === '')               return json_invalid(['reason' => 'Say why the allocation is removed.']);
        if (mb_strlen($reason) > 300)     return json_invalid(['reason' => 'Keep the reason under 300 characters.']);

        $a = $this->db->get_where('gp_allocations', ['id' => (int) $id], 1)->row_array();
        if ( ! $a) return json_error('That allocation no longer exists.', 404);
        $err = $this->documents->remove_allocation((int) $id, $reason, $claims);
        if ($err !== '') return json_error($err, 409);
        return json_response(['document_id' => (int) $a['document_id'], 'settlement_id' => $a['settlement_id'] !== NULL ? (int) $a['settlement_id'] : NULL,
                              'credit_document_id' => $a['credit_document_id'] !== NULL ? (int) $a['credit_document_id'] : NULL],
                             'Allocation of ' . money_format_cents((int) $a['amount_cents']) . ' removed.');
    }

    // =========================================================================

    /** After a save: stop at the draft, or post it when this person may post their own. @return array [message, is_warning] */
    private function _then($id, $then, array $claims)
    {
        $d = $this->documents->find($id);
        $word = Document_model::word($d['doc_type']);
        if ($then !== 'post') return ['Draft ' . $word . ' saved.', FALSE];
        if (role_rank($claims['role']) < 3 || ! $this->journals->may_self_approve()) {
            return ['Draft saved. An accountant other than you posts it.', FALSE];
        }
        list($err, $no) = $this->documents->post($id, $claims);
        if ($err !== '') return ['Saved as a draft, but not posted: ' . $err, TRUE];
        return [Arap_lib::TYPE_LABELS[$d['doc_type']] . ' ' . $no . ' posted.', FALSE];
    }

    private function _detail($id, array $claims)
    {
        $d = $this->documents->detail($id, $claims);
        if ( ! $d) return NULL;
        $d['letterhead'] = report_letterhead($claims);
        return $d;
    }
}
