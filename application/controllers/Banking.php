<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Banking.php — bank accounts, statements, matching and bank adjustments
 *
 * GenericPOS Accounting · banking module
 *
 *   GET    /api/v1/banking                              viewer      every bank account, its book balance and statements
 *   GET    /api/v1/banking/summary                      viewer      the dashboard card: open statements, book balances
 *   POST   /api/v1/banking/accounts                     accountant  { bank_name, account_name, account_last4, account_id }
 *   PUT    /api/v1/banking/accounts/{id}                accountant  the same, and is_active
 *   POST   /api/v1/banking/statements                   bookkeeper  { bank_account_id, statement_date, opening_balance_cents, closing_balance_cents }
 *   GET    /api/v1/banking/statements/{id}              viewer      the statement, its lines, the book lines to match, the figures
 *   PUT    /api/v1/banking/statements/{id}              bookkeeper  date and balances, while open
 *   DELETE /api/v1/banking/statements/{id}              bookkeeper  while open and nothing on it is matched
 *   POST   /api/v1/banking/statements/{id}/lines        bookkeeper  { txn_date, description, reference, amount_cents (+ deposit / − withdrawal) }
 *   POST   /api/v1/banking/statements/{id}/import       bookkeeper  { date_format, file_name, rows: [{ row, date, description, reference, amount | withdrawal + deposit }] }
 *   POST   /api/v1/banking/statements/{id}/auto-match   bookkeeper
 *   POST   /api/v1/banking/statements/{id}/reconcile    accountant
 *   POST   /api/v1/banking/statements/{id}/reopen       accountant  { reason } — the latest reconciled statement only
 *   PUT    /api/v1/banking/lines/{id}                   bookkeeper  an unmatched line on an open statement
 *   DELETE /api/v1/banking/lines/{id}                   bookkeeper  likewise
 *   POST   /api/v1/banking/lines/{id}/match             bookkeeper  { journal_line_ids: [] }
 *   POST   /api/v1/banking/lines/{id}/unmatch           bookkeeper
 *   POST   /api/v1/banking/lines/{id}/ignore            bookkeeper  { reason }
 *   POST   /api/v1/banking/lines/{id}/unignore          bookkeeper
 *   POST   /api/v1/banking/lines/{id}/record            accountant  { account_id, department_id, description, final_tax, final_tax_account_id, final_tax_cents }
 *   POST   /api/v1/banking/lines/{id}/undo-record       accountant  { reason }
 *
 * Every action on a statement answers with the statement as it now stands, so
 * the screen redraws from one request. The rules live in Bank_model and
 * Bank_match_model; this controller checks roles and shapes input and output.
 */
class Banking extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('Bank_model', 'bank');
    }

    private function _matcher()
    {
        $this->load->model('Bank_match_model', 'matcher');
        return $this->matcher;
    }

    // =========================================================================
    // BANK ACCOUNTS
    // =========================================================================

    /** GET /api/v1/banking */
    public function index()
    {
        $claims = viewer_check();
        require_method('GET');
        $rank = role_rank($claims['role']);
        return json_response([
            'accounts'        => $this->bank->accounts_overview(),
            'ledger_accounts' => $rank >= 3 ? $this->bank->eligible_ledger_accounts() : [],
            'today'           => company_today(),
            'window_days'     => $this->bank->window_days(),
            'can'             => ['manage' => $rank >= 3, 'statements' => $rank >= 2],
        ], 'Banking');
    }

    /** GET /api/v1/banking/summary */
    public function summary()
    {
        viewer_check();
        require_method('GET');
        $out = [];
        $open = 0;
        $unmatched = 0;
        foreach ($this->bank->accounts_overview() as $a) {
            $sts = array_values(array_filter($a['statements'], function ($s) { return $s['status'] === 'open'; }));
            if ( ! $a['is_active'] && ! $sts) continue;
            $rec = array_values(array_filter($a['statements'], function ($s) { return $s['status'] === 'reconciled'; }));
            $open += count($sts);
            $unmatched += $a['unmatched_count'];
            $out[] = [
                'id' => $a['id'], 'label' => $a['label'], 'bank_name' => $a['bank_name'], 'account_last4' => $a['account_last4'],
                'ledger_code' => $a['ledger_code'], 'ledger_name' => $a['ledger_name'], 'book_balance_cents' => $a['book_balance_cents'],
                'unmatched_count' => $a['unmatched_count'], 'last_reconciled' => $rec ? $rec[0]['statement_date'] : NULL,
                'open_statements' => array_map(function ($s) {
                    return ['id' => $s['id'], 'statement_date' => $s['statement_date'], 'line_count' => $s['line_count'], 'unmatched_count' => $s['unmatched_count']];
                }, $sts),
            ];
        }
        return json_response(['accounts' => $out, 'open_statements' => $open, 'unmatched' => $unmatched, 'today' => company_today()], 'Bank statements to reconcile');
    }

    /** POST /api/v1/banking/accounts */
    public function create_account()
    {
        $claims = accountant_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');
        list($data, $e) = $this->bank->validate_account(get_json_body());
        if ($e) return json_invalid($e);
        list($id, $err) = $this->bank->create_account($data, $claims);
        if ( ! $id) return json_error($err, 409);
        $a = $this->bank->account($id);
        return json_response(['account' => $this->bank->shape_account($a)], $a['bank_name'] . ' added.', 201);
    }

    /** PUT /api/v1/banking/accounts/{id} */
    public function update_account($id)
    {
        $claims = accountant_check();
        require_method('PUT');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $b = $this->bank->account((int) $id);
        if ( ! $b) return json_error('That bank account does not exist.', 404);
        list($data, $e) = $this->bank->validate_account(get_json_body(), $b);
        if ($e) return json_invalid($e);
        $err = $this->bank->update_account((int) $id, $data, $claims);
        if ($err !== '') return json_error($err, 409);
        $a = $this->bank->account((int) $id);
        $word = (int) $b['is_active'] === (int) $data['is_active'] ? 'saved' : ($data['is_active'] ? 'activated' : 'deactivated');
        return json_response(['account' => $this->bank->shape_account($a)], $a['bank_name'] . ' ' . $word . '.');
    }

    // =========================================================================
    // STATEMENTS
    // =========================================================================

    private function _statement_or_404($id)
    {
        $st = $this->bank->statement((int) $id);
        if ( ! $st) json_error('That statement does not exist.', 404);
        return $st;
    }

    private function _line_or_404($id)
    {
        $l = $this->db->get_where('gp_bank_lines', ['id' => (int) $id], 1)->row_array();
        if ( ! $l) json_error('That bank line does not exist.', 404);
        return $l;
    }

    /** Answer with the statement as it now stands, or with the refusal. */
    private function _after($statement_id, array $claims, $err, $message, array $extra = [], $code = 200)
    {
        if ($err !== '' && $err !== NULL) return json_error($err, 409);
        return json_response($extra + ['notice' => $message] + $this->bank->detail((int) $statement_id, $claims), $message, $code);
    }

    /** POST /api/v1/banking/statements */
    public function create_statement()
    {
        $claims = bookkeeper_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');
        list($data, $e) = $this->bank->validate_statement(get_json_body());
        if ($e) return json_invalid($e);
        list($id, $err) = $this->bank->create_statement($data, $claims);
        if ( ! $id) return json_error($err, 409);
        return json_response($this->bank->detail($id, $claims), 'Statement of ' . $data['statement_date'] . ' created.', 201);
    }

    /** GET /api/v1/banking/statements/{id} */
    public function statement($id)
    {
        $claims = viewer_check();
        require_method('GET');
        $d = $this->bank->detail((int) $id, $claims);
        if ( ! $d) return json_error('That statement does not exist.', 404);
        return json_response($d, 'Bank statement');
    }

    /** PUT /api/v1/banking/statements/{id} */
    public function update_statement($id)
    {
        $claims = bookkeeper_check();
        require_method('PUT');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $st = $this->_statement_or_404($id);
        $in = get_json_body();
        list(, $e) = $this->bank->validate_statement($in, $st);
        if ($e) return json_invalid($e);
        return $this->_after($st['id'], $claims, $this->bank->update_statement((int) $id, $in, $claims), 'Statement saved.');
    }

    /** DELETE /api/v1/banking/statements/{id} */
    public function delete_statement($id)
    {
        $claims = bookkeeper_check();
        require_method('DELETE');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $st  = $this->_statement_or_404($id);
        $err = $this->bank->delete_statement((int) $id, $claims);
        if ($err !== '') return json_error($err, 409);
        return json_response(['deleted' => (int) $id, 'bank_account_id' => (int) $st['bank_account_id']], 'Statement of ' . $st['statement_date'] . ' deleted.');
    }

    /** POST /api/v1/banking/statements/{id}/lines */
    public function add_line($id)
    {
        $claims = bookkeeper_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $st = $this->_statement_or_404($id);
        list($data, $e) = $this->bank->validate_line(get_json_body(), $st);
        if ($e) return json_invalid($e);
        list($res, $err) = $this->bank->add_lines((int) $id, [$data], $claims, FALSE);
        return $this->_after($st['id'], $claims, $err, 'Line added.', ['added' => $res ? $res[0] : []], 201);
    }

    /** POST /api/v1/banking/statements/{id}/import */
    public function import_lines($id)
    {
        $claims = bookkeeper_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'import');
        $st   = $this->_statement_or_404($id);
        $in   = get_json_body();
        $this->load->library('Bank_csv', NULL, 'bank_csv');

        $fmt  = (string) ($in['date_format'] ?? '');
        $rows = isset($in['rows']) && is_array($in['rows']) ? array_values($in['rows']) : NULL;
        $e = [];
        if ( ! isset(Bank_csv::FORMATS[$fmt])) $e['date_format'] = 'Choose how the file writes its dates.';
        if ($rows === NULL || ! $rows)         $e['rows'] = 'The file has no rows to import.';
        elseif (count($rows) > Bank_model::MAX_LINES) $e['rows'] = 'A file can hold at most ' . number_format(Bank_model::MAX_LINES) . ' lines; this one has ' . count($rows) . '.';
        if ($e) return json_invalid($e);

        list($min, $max) = $this->bank->line_date_range($st);
        list($clean, $bad) = $this->bank_csv->rows($rows, $fmt, $min, $max);
        if ($bad) {
            $n = count($bad);
            return json_error(($n === 1 ? 'One row cannot' : $n . ' rows cannot') . ' be read, so nothing was imported. ' . $bad[0], 422, ['data' => [
                'errors' => ['rows' => implode(' ', array_slice($bad, 0, 5)) . ($n > 5 ? ' (and ' . ($n - 5) . ' more)' : '')],
                'row_errors' => array_slice($bad, 0, 200),
            ]]);
        }

        $meta = ['date_format' => $fmt, 'file_name' => mb_substr(clean_line($in['file_name'] ?? '', 120), 0, 120)];
        list($res, $err) = $this->bank->add_lines((int) $id, $clean, $claims, TRUE, $meta);
        if ($err !== '') return json_error($err, 409);
        list($ids, $skip) = $res;
        $msg = 'Imported ' . count($ids) . ' line' . (count($ids) === 1 ? '' : 's') . '.';
        if ($skip) $msg .= ' ' . count($skip) . (count($skip) === 1 ? ' line was' : ' lines were') . ' already on the statement and skipped.';
        $skipped = array_map(function ($r) {
            return ['row' => $r['row'] ?? NULL, 'txn_date' => $r['txn_date'], 'reference' => $r['reference'], 'amount_cents' => (int) $r['amount_cents'], 'description' => $r['description']];
        }, $skip);
        return $this->_after($st['id'], $claims, '', $msg, ['imported' => count($ids), 'skipped' => $skipped], 201);
    }

    /** POST /api/v1/banking/statements/{id}/auto-match */
    public function auto_match($id)
    {
        $claims = bookkeeper_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $st = $this->_statement_or_404($id);
        list($r, $err) = $this->_matcher()->auto_match((int) $id, $claims);
        if ($err !== '') return json_error($err, 409);
        $msg = $r['of'] === 0 ? 'Every line is already matched or set aside.'
             : ($r['matched'] ? 'Matched ' . $r['matched'] . ' of ' . $r['of'] . ' line' . ($r['of'] === 1 ? '' : 's') . '.'
                              : 'Nothing could be matched automatically: no line has exactly one book line of the same amount within ' . $this->bank->window_days() . ' days.');
        return $this->_after($st['id'], $claims, '', $msg, ['auto' => ['matched' => $r['matched'], 'of' => $r['of']]]);
    }

    /** POST /api/v1/banking/statements/{id}/reconcile */
    public function reconcile($id)
    {
        $claims = accountant_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $st = $this->_statement_or_404($id);
        list(, $err) = $this->_matcher()->reconcile((int) $id, $claims);
        return $this->_after($st['id'], $claims, $err, 'Statement of ' . $st['statement_date'] . ' reconciled.');
    }

    /** POST /api/v1/banking/statements/{id}/reopen */
    public function reopen($id)
    {
        $claims = accountant_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $st = $this->_statement_or_404($id);
        $reason = trim((string) (get_json_body()['reason'] ?? ''));
        if ($reason === '') return json_invalid(['reason' => 'Say why the statement is reopened.']);
        list(, $err) = $this->_matcher()->reopen((int) $id, $reason, $claims);
        return $this->_after($st['id'], $claims, $err, 'Statement of ' . $st['statement_date'] . ' reopened.');
    }

    // =========================================================================
    // ONE BANK LINE
    // =========================================================================

    /** PUT /api/v1/banking/lines/{id} */
    public function update_line($id)
    {
        $claims = bookkeeper_check();
        require_method('PUT');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $l  = $this->_line_or_404($id);
        $st = $this->_statement_or_404($l['statement_id']);
        $in = get_json_body();
        list(, $e) = $this->bank->validate_line($in + ['txn_date' => $l['txn_date'], 'description' => $l['description'],
                                                       'reference' => $l['reference'], 'amount_cents' => (int) $l['amount_cents']], $st);
        if ($e) return json_invalid($e);
        return $this->_after($st['id'], $claims, $this->bank->update_line((int) $id, $in, $claims), 'Line saved.');
    }

    /** DELETE /api/v1/banking/lines/{id} */
    public function delete_line($id)
    {
        $claims = bookkeeper_check();
        require_method('DELETE');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $l = $this->_line_or_404($id);
        return $this->_after($l['statement_id'], $claims, $this->bank->delete_line((int) $id, $claims), 'Line deleted.');
    }

    /** POST /api/v1/banking/lines/{id}/match */
    public function match($id)
    {
        $claims = bookkeeper_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $l   = $this->_line_or_404($id);
        $ids = get_json_body()['journal_line_ids'] ?? NULL;
        if ( ! is_array($ids) || ! $ids) return json_invalid(['journal_line_ids' => 'Choose the book lines that make up this bank line.']);
        list($msg, $err) = $this->_matcher()->match((int) $id, $ids, $claims);
        return $this->_after($l['statement_id'], $claims, $err, (string) $msg);
    }

    /** POST /api/v1/banking/lines/{id}/unmatch */
    public function unmatch($id)
    {
        $claims = bookkeeper_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $l = $this->_line_or_404($id);
        list($msg, $err) = $this->_matcher()->unmatch((int) $id, $claims);
        return $this->_after($l['statement_id'], $claims, $err, (string) $msg);
    }

    /** POST /api/v1/banking/lines/{id}/ignore */
    public function ignore($id)
    {
        $claims = bookkeeper_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $l = $this->_line_or_404($id);
        $reason = trim((string) (get_json_body()['reason'] ?? ''));
        if ($reason === '') return json_invalid(['reason' => 'Say why this line is set aside (for example: a bank error the bank reversed).']);
        list($msg, $err) = $this->_matcher()->ignore((int) $id, $reason, $claims);
        return $this->_after($l['statement_id'], $claims, $err, (string) $msg);
    }

    /** POST /api/v1/banking/lines/{id}/unignore */
    public function unignore($id)
    {
        $claims = bookkeeper_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $l = $this->_line_or_404($id);
        list($msg, $err) = $this->_matcher()->unignore((int) $id, $claims);
        return $this->_after($l['statement_id'], $claims, $err, (string) $msg);
    }

    /** POST /api/v1/banking/lines/{id}/record */
    public function record($id)
    {
        $claims = accountant_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $l  = $this->_line_or_404($id);
        $in = get_json_body();
        list($r, $err) = $this->_matcher()->record((int) $id, is_array($in) ? $in : [], $claims);
        if ($err !== '') return json_error($err, 409);
        return $this->_after($l['statement_id'], $claims, '', 'Posted ' . $r['journal_no'] . ' and matched it to the bank line.', ['recorded' => $r], 201);
    }

    /** POST /api/v1/banking/lines/{id}/undo-record */
    public function undo_record($id)
    {
        $claims = accountant_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');
        $l = $this->_line_or_404($id);
        $reason = trim((string) (get_json_body()['reason'] ?? ''));
        if ($reason === '') return json_invalid(['reason' => 'Say why the entry is undone.']);
        list($r, $err) = $this->_matcher()->undo_record((int) $id, $reason, $claims);
        if ($err !== '') return json_error($err, 409);
        return $this->_after($l['statement_id'], $claims, '', $r['journal_no'] . ' reversed by ' . $r['reversal_no'] . '; the line is unmatched again.', ['undone' => $r]);
    }
}
