<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Journals.php — journal entries: the list, one entry, and its workflow
 *
 * GenericPOS Accounting
 *
 *   GET  /api/v1/journals/lookups          accounts, departments, contacts, books, open periods
 *   GET  /api/v1/journals                  ?status=&book=&from=&to=&q=&account=&period=&mine=1&page=&per_page=
 *   GET  /api/v1/journals/{id}             the entry, its lines, its trail, and what you may do with it
 *   POST /api/v1/journals                  { book, entry_date, reference, party_name, description, lines[], then }
 *   PUT  /api/v1/journals/{id}             the same, for a draft or a rejected entry (its preparer only)
 *   POST /api/v1/journals/{id}/submit      its preparer (bookkeeper and above)
 *   POST /api/v1/journals/{id}/approve     accountant — posts it
 *   POST /api/v1/journals/{id}/reject      accountant — { reason }
 *   POST /api/v1/journals/{id}/cancel      its preparer, or an accountant
 *   POST /api/v1/journals/{id}/reverse     accountant — { date, reason }
 *
 * `then` on a save: 'draft' (the default), 'submit', or 'post' — submit and,
 * for someone who may approve their own entries, post at once.
 *
 * Every accounting rule lives in Journal_model. This controller checks roles,
 * shapes input and output, and nothing else.
 */
class Journals extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('Journal_model', 'journals');
    }

    /** GET /api/v1/journals/lookups */
    public function lookups()
    {
        viewer_check();
        require_method('GET');

        $accounts = array_map(function ($a) {
            return ['id' => (int) $a['id'], 'code' => $a['code'], 'name' => $a['name'], 'type' => $a['type'],
                    'active' => (bool) (int) $a['is_active'], 'control' => $a['control'],
                    'needs_department' => (bool) (int) $a['requires_department']];
        }, $this->db->select('id, code, name, type, is_active, control, requires_department')->where('is_header', 0)
                    ->order_by('sort_order', 'ASC')->order_by('code', 'ASC')->get('gp_accounts')->result_array());

        $departments = array_map(function ($d) {
            return ['id' => (int) $d['id'], 'code' => $d['code'], 'name' => $d['name']];
        }, $this->db->select('id, code, name')->where('is_active', 1)->order_by('code', 'ASC')->get('gp_departments')->result_array());

        $contacts = array_map(function ($c) {
            return ['id' => (int) $c['id'], 'code' => $c['code'], 'name' => $c['name'],
                    'customer' => (bool) (int) $c['is_customer'], 'supplier' => (bool) (int) $c['is_supplier']];
        }, $this->db->select('id, code, name, is_customer, is_supplier')->where('is_active', 1)->order_by('name', 'ASC')->get('gp_contacts')->result_array());

        $books = [];
        foreach (Journal_model::BOOK_LABELS as $key => $label) {
            $books[] = ['key' => $key, 'label' => $label, 'manual' => in_array($key, Journal_model::MANUAL_BOOKS, TRUE)];
        }

        return json_response([
            'accounts'     => $accounts,
            'departments'  => $departments,
            'contacts'     => $contacts,
            'books'        => $books,
            'open_periods' => $this->periods->open_periods(),
            'today'        => company_today(),
            'policy'       => ['self_approve' => $this->journals->may_self_approve()],
        ], 'Lookups');
    }

    /** GET /api/v1/journals */
    public function index()
    {
        $claims = viewer_check();
        require_method('GET');

        $p = get_pagination_params(25, 100);
        $f = [
            'status'     => (string) $this->input->get('status'),
            'book'       => (string) $this->input->get('book'),
            'from'       => (string) $this->input->get('from'),
            'to'         => (string) $this->input->get('to'),
            'q'          => (string) $this->input->get('q'),
            'account_id' => (int) $this->input->get('account'),
            'period_id'  => (int) $this->input->get('period'),
            'created_by' => $this->input->get('mine') ? (int) $claims['user_id'] : 0,
        ];

        list($rows, $total) = $this->journals->search($f, $p['limit'], $p['offset']);
        $items = array_map(function ($r) {
            $r = $this->journals->shape($r);
            $r['created_by_name'] = $r['created_by_name'] ?: $r['created_by_username'];
            unset($r['created_by_username']);
            return $r;
        }, $rows);

        $out = build_pagination_meta($items, $total, $p['page'], $p['limit']);
        $out['counts'] = $this->journals->status_counts($f);
        if ($f['account_id']) {
            $a = $this->db->select('id, code, name')->get_where('gp_accounts', ['id' => $f['account_id']], 1)->row_array();
            $out['account'] = $a ? ['id' => (int) $a['id'], 'code' => $a['code'], 'name' => $a['name']] : NULL;
        }
        return json_response($out, 'Journal entries');
    }

    /** GET /api/v1/journals/{id} */
    public function show($id)
    {
        $claims = viewer_check();
        require_method('GET');
        $d = $this->_detail((int) $id, $claims);
        if ( ! $d) return json_error('That entry does not exist.', 404);
        return json_response($d, 'Journal entry');
    }

    /** POST /api/v1/journals */
    public function create()
    {
        $claims = bookkeeper_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $in = get_json_body();
        list($head, $lines) = $this->_input($in);
        list($id, $e) = $this->journals->create_draft($head, $lines, (int) $claims['user_id']);
        if ( ! $id) return json_invalid($e);

        list($msg, $warn) = $this->_then($id, (string) ($in['then'] ?? 'draft'), $claims);
        return json_response($this->_detail($id, $claims) + ['notice' => ['text' => $msg, 'warn' => $warn]], $msg, 201);
    }

    /** PUT /api/v1/journals/{id} */
    public function update($id)
    {
        $claims = bookkeeper_check();
        require_method('PUT');
        rate_limit((int) $claims['user_id'], 'admin_write');
        if ( ! $this->journals->find((int) $id)) return json_error('That entry does not exist.', 404);

        $in = get_json_body();
        list($head, $lines) = $this->_input($in);
        $e = $this->journals->update_draft((int) $id, $head, $lines, (int) $claims['user_id']);
        if ($e) return json_invalid($e);

        list($msg, $warn) = $this->_then((int) $id, (string) ($in['then'] ?? 'draft'), $claims);
        return json_response($this->_detail((int) $id, $claims) + ['notice' => ['text' => $msg, 'warn' => $warn]], $msg);
    }

    /** POST /api/v1/journals/{id}/submit */
    public function submit($id)
    {
        $claims = bookkeeper_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');
        return $this->_act((int) $id, $claims, $this->journals->submit((int) $id, (int) $claims['user_id']), 'Submitted for approval.');
    }

    /** POST /api/v1/journals/{id}/approve */
    public function approve($id)
    {
        $claims = accountant_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $err = $this->journals->approve((int) $id, (int) $claims['user_id']);
        $j   = $err === '' ? $this->journals->find((int) $id) : NULL;
        return $this->_act((int) $id, $claims, $err, $j ? 'Posted as ' . $j['journal_no'] . '.' : '');
    }

    /** POST /api/v1/journals/{id}/reject */
    public function reject($id)
    {
        $claims = accountant_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $reason = trim((string) (get_json_body()['reason'] ?? ''));
        if ($reason === '')           return json_invalid(['reason' => 'Say why the entry is rejected.']);
        if (mb_strlen($reason) > 300) return json_invalid(['reason' => 'Keep the reason under 300 characters.']);

        return $this->_act((int) $id, $claims, $this->journals->reject((int) $id, (int) $claims['user_id'], $reason), 'Returned to its preparer.');
    }

    /** POST /api/v1/journals/{id}/cancel */
    public function cancel($id)
    {
        $claims = bookkeeper_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $j = $this->journals->find((int) $id);
        if ( ! $j) return json_error('That entry does not exist.', 404);
        if ((int) $j['created_by'] !== (int) $claims['user_id'] && role_rank($claims['role']) < 3) {
            return json_error('Only its preparer or an accountant can cancel this entry.', 403);
        }
        return $this->_act((int) $id, $claims, $this->journals->cancel((int) $id, (int) $claims['user_id']), 'Cancelled.');
    }

    /** POST /api/v1/journals/{id}/reverse */
    public function reverse($id)
    {
        $claims = accountant_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $in     = get_json_body();
        $reason = trim((string) ($in['reason'] ?? ''));
        $date   = trim((string) ($in['date'] ?? ''));
        $e = [];
        if ($reason === '')                     $e['reason'] = 'Say why the entry is reversed.';
        if ( ! Period_model::valid_date($date)) $e['date'] = 'Enter the date of the reversal.';
        if ($e) return json_invalid($e);

        list($new, $err) = $this->journals->reverse((int) $id, (int) $claims['user_id'], $date, $reason);
        if ( ! $new) return json_error($err, 409);

        $n = $this->journals->find($new);
        return json_response(['reversal_id' => $new] + $this->_detail($new, $claims), 'Reversed by ' . $n['journal_no'] . '.');
    }

    // =========================================================================

    /** Header and lines from a request body. Amounts are integer centavos. */
    private function _input(array $in)
    {
        $head = [
            'book'        => (string) ($in['book'] ?? 'general'),
            'entry_date'  => (string) ($in['entry_date'] ?? ''),
            'reference'   => (string) ($in['reference'] ?? ''),
            'party_name'  => (string) ($in['party_name'] ?? ''),
            'description' => (string) ($in['description'] ?? ''),
        ];
        $lines = [];
        foreach ((isset($in['lines']) && is_array($in['lines'])) ? $in['lines'] : [] as $l) {
            if ( ! is_array($l)) continue;
            $lines[] = [
                'account_id'    => (int) ($l['account_id'] ?? 0),
                'debit_cents'   => $l['debit_cents'] ?? 0,
                'credit_cents'  => $l['credit_cents'] ?? 0,
                'memo'          => (string) ($l['memo'] ?? ''),
                'department_id' => (int) ($l['department_id'] ?? 0),
                'contact_id'    => (int) ($l['contact_id'] ?? 0),
            ];
        }
        return [$head, $lines];
    }

    /** After a save: stop at the draft, submit, or submit and post. @return array [message, is_warning] */
    private function _then($id, $then, array $claims)
    {
        $uid = (int) $claims['user_id'];
        if ($then !== 'submit' && $then !== 'post') return ['Saved as a draft.', FALSE];

        $err = $this->journals->submit($id, $uid);
        if ($err !== '') return ['Saved as a draft, but not submitted: ' . $err, TRUE];
        if ($then === 'submit') return ['Submitted for approval.', FALSE];

        if (role_rank($claims['role']) < 3 || ! $this->journals->may_self_approve()) {
            return ['Submitted for approval. Another accountant approves it.', FALSE];
        }
        $err = $this->journals->approve($id, $uid);
        if ($err !== '') return ['Submitted, but not posted: ' . $err, TRUE];
        $j = $this->journals->find($id);
        return ['Posted as ' . $j['journal_no'] . '.', FALSE];
    }

    private function _act($id, array $claims, $err, $ok)
    {
        if ($err !== '') return json_error($err, $err === 'No such entry.' ? 404 : 409);
        return json_response($this->_detail($id, $claims), $ok);
    }

    private function _detail($id, array $claims)
    {
        $d = $this->journals->detail($id);
        if ( ! $d) return NULL;
        $d['can'] = $this->journals->permissions($d['journal'], (int) $claims['user_id'], $claims['role']);
        return $d;
    }
}
