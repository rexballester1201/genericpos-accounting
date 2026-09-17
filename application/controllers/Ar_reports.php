<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Ar_reports.php — the receivables and payables reports (every role reads them)
 *
 * GenericPOS Accounting · receivables and payables module
 *
 *   GET /api/v1/reports/aging               ?side=ar|ap&as_of=&contact_id=&detail=1
 *   GET /api/v1/reports/customer-statement  ?contact_id=&from=&to=&side=ar|ap
 *   GET /api/v1/reports/subsidiary-ledger   ?side=ar|ap&as_of=&zero=1
 *
 *   &format=csv on any of them returns the same report as a spreadsheet file.
 *
 * Every figure ties to the posted ledger. The aging's total is set beside
 * the control accounts' balance at the same date, with any difference — lines
 * posted to a control account by hand — listed. The subsidiary ledger is the
 * control accounts split by contact, so the two are equal by construction
 * (Journal_model refuses a control-account line without its contact). A
 * statement of account closes on the contact's balance in that ledger.
 */
class Ar_reports extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('Document_model', 'documents');
    }

    /** GET /api/v1/reports/aging */
    public function aging()
    {
        $claims = viewer_check();
        require_method('GET');
        $side  = (string) $this->input->get('side') === 'ap' ? 'ap' : 'ar';
        $as_of = $this->_date('as_of', company_today());
        $cid   = (int) $this->input->get('contact_id');
        $a     = $this->documents->aging($side, $as_of, $cid);
        $title = $side === 'ar' ? 'Accounts Receivable Aging' : 'Accounts Payable Aging';

        if (report_csv_wanted($claims)) {
            $detail = (bool) $this->input->get('detail');
            $m = function ($c) { return $c ? money_major($c) : ''; };
            $rows = report_csv_head($title, 'As of ' . $as_of . ' · days past the due date');
            $rows[] = array_merge(['Code', $side === 'ar' ? 'Customer' : 'Supplier'], $a['labels'], ['Unapplied', 'Balance']);
            foreach ($a['rows'] as $r) {
                $rows[] = array_merge([$r['code'], $r['name'] . ($r['is_active'] ? '' : ' (inactive)')], array_map($m, $r['buckets']),
                                      [$r['unapplied_cents'] ? money_major(-$r['unapplied_cents']) : '', money_major($r['net_cents'])]);
                if ($detail) {
                    foreach ($r['items'] as $i) {
                        $cells = array_fill(0, count($a['labels']), '');
                        if ($i['bucket'] !== NULL) $cells[$i['bucket']] = money_major($i['open_cents']);
                        $what = $i['kind'] === 'settlement' ? ucfirst($i['doc_type']) : (Arap_lib::TYPE_LABELS[$i['doc_type']] ?? $i['doc_type']);
                        $rows[] = array_merge(['', '  ' . $what . ' ' . $i['number'] . ' · ' . $i['date'] . ($i['due_date'] ? ' · due ' . $i['due_date'] : '')],
                                              $cells, [$i['bucket'] === NULL ? money_major($i['open_cents']) : '', '']);
                    }
                }
            }
            $rows[] = array_merge(['', 'Total'], array_map('money_major', $a['totals']['buckets']),
                                  [money_major(-$a['totals']['unapplied_cents']), money_major($a['totals']['net_cents'])]);
            $rows[] = [];
            $rows[] = ['', 'Control account balance per the ledger', money_major($a['ledger']['control_cents'])];
            $rows[] = ['', 'Difference (entries on the control account made outside invoices and receipts)', money_major($a['ledger']['difference_cents'])];
            return report_csv(($side === 'ar' ? 'receivables' : 'payables') . '-aging-' . $as_of . '.csv', $rows);
        }

        return json_response($a + [
            'title'      => $title,
            'params'     => ['side' => $side, 'as_of' => $as_of, 'contact_id' => $cid ?: NULL],
            'letterhead' => report_letterhead($claims),
        ], $title);
    }

    /** GET /api/v1/reports/customer-statement */
    public function customer_statement()
    {
        $claims = viewer_check();
        require_method('GET');
        $cid = (int) $this->input->get('contact_id');
        if ($cid <= 0) return json_invalid(['contact_id' => 'Choose a customer or supplier.']);
        $c = $this->contacts->find($cid);
        if ( ! $c) return json_error('That customer or supplier does not exist.', 404);
        $c = $this->contacts->shape($c);

        $want = (string) $this->input->get('side');
        $side = $c['is_customer'] ? 'ar' : 'ap';
        if ($want === 'ap' && $c['is_supplier']) $side = 'ap';
        if ($want === 'ar' && $c['is_customer']) $side = 'ar';

        $to   = $this->_date('to', company_today());
        $from = (string) $this->input->get('from');
        if ( ! Period_model::valid_date($from)) {
            $fy = $this->periods->year_for_date($to);
            $from = $fy ? $fy['start_date'] : substr($to, 0, 4) . '-01-01';
        }
        if ($from > $to) return json_invalid(['from' => 'The start date is after the end date.']);

        $s = $this->_statement($c, $side, $from, $to);
        $title = 'Statement of Account';

        if (report_csv_wanted($claims)) {
            $cols = $s['columns'];
            $rows = report_csv_head($title . ' — ' . $c['code'] . ' ' . $c['name'], 'For ' . $from . ' to ' . $to);
            $rows[] = ['Date', 'Reference', 'Particulars', $cols[0], $cols[1], 'Balance'];
            $rows[] = ['', '', 'Balance brought forward', '', '', money_major($s['opening_cents'])];
            foreach ($s['lines'] as $l) {
                $rows[] = [$l['date'], (string) $l['number'], $l['particulars'], $l['debit_cents'] ? money_major($l['debit_cents']) : '',
                           $l['credit_cents'] ? money_major($l['credit_cents']) : '', money_major($l['balance_cents'])];
            }
            $rows[] = ['', '', 'Totals for the period', money_major($s['debit_cents']), money_major($s['credit_cents']), ''];
            $rows[] = ['', '', 'Balance as of ' . $to, '', '', money_major($s['closing_cents'])];
            $rows[] = [];
            $rows[] = array_merge(['', '', 'Aging as of ' . $to], $s['aging']['labels'], ['Unapplied', 'Balance']);
            $rows[] = array_merge(['', '', ''], array_map('money_major', $s['aging']['buckets']),
                                  [money_major(-$s['aging']['unapplied_cents']), money_major($s['aging']['net_cents'])]);
            return report_csv('statement-of-account-' . $c['code'] . '-' . $from . '-to-' . $to . '.csv', $rows);
        }

        return json_response($s + [
            'title'      => $title,
            'contact'    => $c,
            'params'     => ['contact_id' => $cid, 'side' => $side, 'from' => $from, 'to' => $to],
            'letterhead' => report_letterhead($claims),
        ], $title);
    }

    /** GET /api/v1/reports/subsidiary-ledger */
    public function subsidiary_ledger()
    {
        $claims = viewer_check();
        require_method('GET');
        $side  = (string) $this->input->get('side') === 'ap' ? 'ap' : 'ar';
        $as_of = $this->_date('as_of', company_today());
        $zero  = (bool) $this->input->get('zero');
        $title = $side === 'ar' ? 'Accounts Receivable Subsidiary Ledger' : 'Accounts Payable Subsidiary Ledger';
        $sign  = $side === 'ar' ? 1 : -1;

        $ctl = $this->arap->control_ids($side);
        $in  = $ctl ? implode(',', $ctl) : '0';
        $by = [];
        foreach ($this->db->query('SELECT contact_id, SUM(net_cents) AS n, SUM(debit_cents) AS dr, SUM(credit_cents) AS cr, MAX(entry_date) AS last
                                     FROM gp_ledger WHERE account_id IN (' . $in . ') AND entry_date <= ? GROUP BY contact_id', [$as_of])->result_array() as $r) {
            $by[$r['contact_id'] === NULL ? 0 : (int) $r['contact_id']] = $r;
        }
        $control = 0;
        foreach ($by as $r) $control += $sign * (int) $r['n'];

        $contacts = [];
        $ids = array_values(array_filter(array_keys($by)));
        $q = $this->db->select('id, code, name, is_active, is_customer, is_supplier');
        if ($zero) $q->where($side === 'ar' ? 'is_customer' : 'is_supplier', 1);
        if ($ids && $zero) $q->or_where_in('id', $ids);
        elseif ( ! $zero) $q->where_in('id', $ids ?: [0]);
        foreach ($q->order_by('name', 'ASC')->order_by('code', 'ASC')->get('gp_contacts')->result_array() as $c) $contacts[] = $c;

        $rows = [];
        $total = 0;
        foreach ($contacts as $c) {
            $r = $by[(int) $c['id']] ?? NULL;
            $bal = $r ? $sign * (int) $r['n'] : 0;
            if ($bal === 0 && ! $zero) continue;
            $total += $bal;
            $rows[] = ['contact_id' => (int) $c['id'], 'code' => $c['code'], 'name' => $c['name'], 'is_active' => (bool) (int) $c['is_active'],
                       'debit_cents' => $r ? (int) $r['dr'] : 0, 'credit_cents' => $r ? (int) $r['cr'] : 0,
                       'balance_cents' => $bal, 'last_date' => $r ? $r['last'] : NULL];
        }
        $unnamed = isset($by[0]) ? $sign * (int) $by[0]['n'] : 0;
        $accounts = $ctl ? array_map(function ($a) { return ['id' => (int) $a['id'], 'code' => $a['code'], 'name' => $a['name']]; },
            $this->db->where_in('id', $ctl)->order_by('code')->get('gp_accounts')->result_array()) : [];

        $out = [
            'title'            => $title,
            'side'             => $side,
            'as_of'            => $as_of,
            'accounts'         => $accounts,
            'rows'             => $rows,
            'total_cents'      => $total,
            'control_cents'    => $control,
            'unnamed_cents'    => $unnamed,
            'difference_cents' => $control - $total,
            'ties'             => $control === $total,
        ];

        if (report_csv_wanted($claims)) {
            $csv = report_csv_head($title, 'As of ' . $as_of);
            $csv[] = ['Code', $side === 'ar' ? 'Customer' : 'Supplier', 'Debits', 'Credits', 'Balance', 'Last entry'];
            foreach ($rows as $r) {
                $csv[] = [$r['code'], $r['name'] . ($r['is_active'] ? '' : ' (inactive)'), money_major($r['debit_cents']), money_major($r['credit_cents']),
                          money_major($r['balance_cents']), (string) $r['last_date']];
            }
            $csv[] = ['', 'Total of the subsidiary ledger', '', '', money_major($total), ''];
            $csv[] = ['', 'Control account balance per the general ledger', '', '', money_major($control), ''];
            $csv[] = ['', 'Difference', '', '', money_major($control - $total), ''];
            return report_csv(($side === 'ar' ? 'receivables' : 'payables') . '-subsidiary-ledger-' . $as_of . '.csv', $csv);
        }

        return json_response($out + ['params' => ['side' => $side, 'as_of' => $as_of, 'zero' => $zero], 'letterhead' => report_letterhead($claims)], $title);
    }

    // =========================================================================

    /**
     * A contact's lines on the control accounts of one side over [$from, $to],
     * with the balance brought forward, a running balance, the balance at the
     * end and the aging at the end.
     */
    private function _statement(array $c, $side, $from, $to)
    {
        $ctl  = $this->arap->control_ids($side);
        $in   = $ctl ? implode(',', $ctl) : '0';
        $sign = $side === 'ar' ? 1 : -1;
        $cid  = (int) $c['id'];

        $opening = $sign * (int) $this->db->query('SELECT COALESCE(SUM(net_cents), 0) AS n FROM gp_ledger WHERE contact_id = ? AND account_id IN (' . $in . ') AND entry_date < ?',
                                                  [$cid, $from])->row()->n;
        $raw = $this->db->query(
            'SELECT journal_id, journal_no, entry_date, book, reference, description, memo, source, source_id, debit_cents, credit_cents
               FROM gp_ledger WHERE contact_id = ? AND account_id IN (' . $in . ') AND entry_date BETWEEN ? AND ?
              ORDER BY entry_date, journal_id, line_no', [$cid, $from, $to]
        )->result_array();

        /* Name each line after what posted it: the document or settlement, or — for a reversal — what it cancelled. */
        $doc_src = ['invoice', 'credit_note', 'bill', 'debit_note'];
        $set_src = ['receipt', 'payment'];
        $jids = [];
        foreach ($raw as $l) if ($l['source'] === 'reversal' && $l['source_id']) $jids[] = (int) $l['source_id'];
        $docs = [];
        $sets = [];
        $doc_ids = array_values(array_unique(array_map(function ($l) { return (int) $l['source_id']; }, array_filter($raw, function ($l) use ($doc_src) { return in_array($l['source'], $doc_src, TRUE); }))));
        $set_ids = array_values(array_unique(array_map(function ($l) { return (int) $l['source_id']; }, array_filter($raw, function ($l) use ($set_src) { return in_array($l['source'], $set_src, TRUE); }))));
        $by_journal = [];
        if ($doc_ids || $jids) {
            $q = $this->db->select('id, doc_type, doc_no, doc_date, due_date, reference, related_document_id, journal_id');
            if ($doc_ids) $q->where_in('id', $doc_ids);
            if ($jids) { if ($doc_ids) $q->or_where_in('journal_id', $jids); else $q->where_in('journal_id', $jids); }
            foreach ($q->get('gp_documents')->result_array() as $d) {
                $docs[(int) $d['id']] = $d;
                if ($d['journal_id']) $by_journal[(int) $d['journal_id']] = ['document', (int) $d['id']];
            }
        }
        if ($set_ids || $jids) {
            $q = $this->db->select('id, kind, settle_no, reference, withholding_cents, journal_id');
            if ($set_ids) $q->where_in('id', $set_ids);
            if ($jids) { if ($set_ids) $q->or_where_in('journal_id', $jids); else $q->where_in('journal_id', $jids); }
            foreach ($q->get('gp_settlements')->result_array() as $s) {
                $sets[(int) $s['id']] = $s;
                if ($s['journal_id']) $by_journal[(int) $s['journal_id']] = ['settlement', (int) $s['id']];
            }
        }
        $related = [];
        $rel_ids = array_values(array_filter(array_map(function ($d) { return (int) $d['related_document_id']; }, $docs)));
        if ($rel_ids) foreach ($this->db->select('id, doc_no')->where_in('id', $rel_ids)->get('gp_documents')->result_array() as $r) $related[(int) $r['id']] = $r['doc_no'];

        $describe = function ($what, $id) use ($docs, $sets, $related) {
            if ($what === 'document' && isset($docs[$id])) {
                $d = $docs[$id];
                $t = Arap_lib::TYPE_LABELS[$d['doc_type']] . ' ' . $d['doc_no'];
                if ($d['doc_type'] === 'invoice' || $d['doc_type'] === 'bill') {
                    if ($d['reference']) $t .= ' (' . $d['reference'] . ')';
                    if ($d['due_date']) $t .= ', due ' . $d['due_date'];
                } elseif ($d['related_document_id'] && isset($related[(int) $d['related_document_id']])) {
                    $t .= ' against ' . $related[(int) $d['related_document_id']];
                }
                return [$t, $d['doc_no'], 'documents/' . $id];
            }
            if ($what === 'settlement' && isset($sets[$id])) {
                $s = $sets[$id];
                $t = $s['kind'] === 'receipt' ? 'Payment received' : 'Payment made';
                if ($s['reference']) $t .= ' — ' . $s['reference'];
                if ((int) $s['withholding_cents'] > 0) $t .= ', with ' . money_format_cents((int) $s['withholding_cents']) . ' tax withheld';
                return [$t, $s['settle_no'], 'settlements/' . $id];
            }
            return NULL;
        };

        $lines = [];
        $bal = $opening;
        $dr = 0;
        $cr = 0;
        foreach ($raw as $l) {
            $d = (int) $l['debit_cents'];
            $k = (int) $l['credit_cents'];
            $dr += $d;
            $cr += $k;
            $bal += $sign * ($d - $k);
            $info = NULL;
            if (in_array($l['source'], $doc_src, TRUE))      $info = $describe('document', (int) $l['source_id']);
            elseif (in_array($l['source'], $set_src, TRUE))  $info = $describe('settlement', (int) $l['source_id']);
            elseif ($l['source'] === 'reversal' && isset($by_journal[(int) $l['source_id']])) {
                $x = $by_journal[(int) $l['source_id']];
                $orig = $describe($x[0], $x[1]);
                if ($orig) $info = ['Cancelled: ' . $orig[0], $orig[1], $orig[2]];
            }
            if ( ! $info) {
                $text = $l['source'] === 'opening' ? ($l['memo'] ?: 'Balance brought forward from the previous system') : $l['description'];
                if ($l['source'] !== 'opening' && $l['memo'] && $l['memo'] !== $l['description']) $text .= ' — ' . $l['memo'];
                $info = [$text, $l['reference'] ?: $l['journal_no'], NULL];
            }
            $lines[] = [
                'date' => $l['entry_date'], 'journal_id' => (int) $l['journal_id'], 'journal_no' => $l['journal_no'],
                'number' => $info[1], 'particulars' => $info[0], 'href' => $info[2], 'source' => $l['source'],
                'debit_cents' => $d, 'credit_cents' => $k, 'balance_cents' => $bal,
            ];
        }

        $a = $this->documents->aging($side, $to, $cid);
        $row = $a['rows'][0] ?? ['buckets' => array_fill(0, count($a['labels']), 0), 'unapplied_cents' => 0, 'net_cents' => 0, 'items' => []];

        return [
            'side'          => $side,
            'from'          => $from,
            'to'            => $to,
            'columns'       => $side === 'ar' ? ['Charges', 'Payments and credits'] : ['Payments and debits', 'Bills'],
            'opening_cents' => $opening,
            'lines'         => $lines,
            'debit_cents'   => $dr,
            'credit_cents'  => $cr,
            'closing_cents' => $bal,
            'aging'         => ['labels' => $a['labels'], 'buckets' => $row['buckets'], 'unapplied_cents' => $row['unapplied_cents'],
                                'net_cents' => $row['net_cents'], 'items' => $row['items']],
            'ties'          => $bal === $row['net_cents'],
            'company'       => ['name' => (string) shop_cfg('store_name', ''), 'legal_name' => trim((string) shop_cfg('store_legal_name', '')) ?: (string) shop_cfg('store_name', '')],
        ];
    }

    private function _date($key, $default)
    {
        $v = (string) $this->input->get($key);
        return Period_model::valid_date($v) ? $v : $default;
    }
}
