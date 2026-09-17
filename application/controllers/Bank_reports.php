<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Bank_reports.php — the bank reconciliation report (every role reads it)
 *
 * GenericPOS Accounting · banking module
 *
 *   GET /api/v1/reports/bank-reconciliation?statement_id=     the report; without statement_id,
 *                                                             the statements to choose from
 *   …&format=csv                                              the same, as a spreadsheet file
 *
 * The standard layout (Bank_model::reconciliation):
 *   balance per bank statement + deposits in transit − outstanding cheques
 *   (± bank errors set aside) = adjusted bank balance;
 *   balance per books + credit memos − debit memos (± items the books date after
 *   the statement) = adjusted book balance; the difference must be zero.
 * A reconciled statement is computed as of that statement: book lines matched
 * on later statements count as outstanding.
 */
class Bank_reports extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('Bank_model', 'bank');
    }

    /** GET /api/v1/reports/bank-reconciliation */
    public function reconciliation()
    {
        $claims = viewer_check();
        require_method('GET');

        $choices = array_map(function ($a) {
            return ['id' => $a['id'], 'label' => $a['label'], 'ledger_code' => $a['ledger_code'], 'ledger_name' => $a['ledger_name'],
                    'statements' => array_map(function ($s) { return ['id' => $s['id'], 'statement_date' => $s['statement_date'], 'status' => $s['status']]; }, $a['statements'])];
        }, $this->bank->accounts_overview());

        $sid = (int) $this->input->get('statement_id');
        if ($sid <= 0) {
            if ((string) $this->input->get('format') === 'csv') return json_invalid(['statement_id' => 'Choose a bank statement.']);
            return json_response(['statement' => NULL, 'choices' => $choices, 'letterhead' => report_letterhead($claims)], 'Choose a bank statement');
        }
        $st = $this->bank->statement($sid);
        if ( ! $st) return json_error('That statement does not exist.', 404);

        $bank   = $this->bank->shape_account($this->bank->account((int) $st['bank_account_id']));
        $counts = $this->bank->line_counts([$sid]);
        $names  = $this->bank->names([$st['created_by'], $st['reconciled_by']]);
        $r      = $this->bank->reconciliation($st, TRUE);

        if (report_csv_wanted($claims)) return $this->_csv($st, $bank, $r);

        return json_response([
            'statement'  => $this->bank->shape_statement($st, $counts[$sid] ?? NULL, $names),
            'bank'       => $bank,
            'report'     => $r,
            'choices'    => $choices,
            'params'     => ['statement_id' => $sid],
            'letterhead' => report_letterhead($claims),
        ], 'Bank reconciliation');
    }

    private function _csv(array $st, array $bank, array $r)
    {
        $D    = $st['statement_date'];
        $m    = function ($c) { return money_major((int) $c); };
        $rows = report_csv_head('Bank reconciliation — ' . $bank['bank_name'] . ($bank['account_last4'] ? ' account ending ' . $bank['account_last4'] : '')
                                . ' (' . $bank['ledger_code'] . ' ' . $bank['ledger_name'] . ')', 'As of ' . $D);
        $rows[] = ['', 'Date', 'Reference', 'Particulars', 'Amount'];
        $list = function ($title, array $items, $total_label, $total, $book) use (&$rows, $m) {
            if ( ! $items) return;
            $rows[] = [$title];
            foreach ($items as $it) {
                $rows[] = ['', $it['date'], (string) $it['reference'], $book
                    ? trim(($it['journal_no'] ? $it['journal_no'] . ' · ' : '') . $it['description'])
                    : trim($it['description'] . ( ! empty($it['reason']) ? ' — ' . $it['reason'] : '')), $m($it['amount_cents'])];
            }
            $rows[] = ['', '', '', $total_label, $m($total)];
        };

        $b = $r['bank_side'];
        $k = $r['book_side'];
        $i = $r['items'];
        $rows[] = ['Balance per bank statement', $D, '', '', $m($b['closing_cents'])];
        $list('Add: deposits in transit', $i['deposits_in_transit'], 'Total deposits in transit', $b['deposits_in_transit_cents'], TRUE);
        $list('Less: outstanding cheques', $i['outstanding_cheques'], 'Total outstanding cheques', $b['outstanding_cheques_cents'], TRUE);
        $list('Add (deduct): bank errors set aside', $i['bank_errors'], 'Total bank errors', $b['bank_errors_cents'], FALSE);
        $rows[] = ['Adjusted bank balance', '', '', '', $m($b['adjusted_cents'])];
        $rows[] = [];
        $rows[] = ['Balance per books', $D, '', $bank['ledger_code'] . ' ' . $bank['ledger_name'], $m($k['balance_cents'])];
        $list('Add: credit memos not yet in the books', $i['credit_memos'], 'Total credit memos', $k['credit_memos_cents'], FALSE);
        $list('Less: debit memos not yet in the books', $i['debit_memos'], 'Total debit memos', $k['debit_memos_cents'], FALSE);
        $list('Add (deduct): entered in the books after ' . $D, $i['booked_later'], 'Total entered later', $k['booked_later_cents'], TRUE);
        $rows[] = ['Adjusted book balance', '', '', '', $m($k['adjusted_cents'])];
        $rows[] = [];
        $rows[] = ['Difference', '', '', $r['difference_cents'] === 0 ? 'None' : 'Must be zero to reconcile', $m($r['difference_cents'])];
        $rows[] = ['Status', '', '', $st['status'] === 'reconciled' ? 'Reconciled' . ($st['reconciled_at'] ? ' on ' . substr($st['reconciled_at'], 0, 10) : '') : 'Open', ''];
        return report_csv('bank-reconciliation-' . preg_replace('/[^A-Za-z0-9]+/', '-', $bank['bank_name']) . '-' . $D . '.csv', $rows);
    }
}
