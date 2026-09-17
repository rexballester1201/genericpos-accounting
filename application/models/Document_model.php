<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Document_model.php — invoices and credit notes, bills and debit notes, and allocations
 *
 * GenericPOS Accounting · receivables and payables module
 * tables gp_documents, gp_document_lines, gp_allocations
 *
 * ─── LIFE OF A DOCUMENT ───────────────────────────────────────────────────
 *   draft ──post──▶ posted ──cancel──▶ cancelled (its journal reversed)
 *   draft ──delete──▶ gone (nothing was numbered or posted; the audit log keeps the record)
 *
 *   · A bookkeeper (or anyone above) prepares a draft; only its preparer edits it.
 *   · An accountant posts it — never their own draft unless self-approval is on
 *     (Journal_model::may_self_approve). The number is taken AT POSTING, inside
 *     the posting transaction, so a failed posting leaves no gap.
 *   · The server works out every figure from the lines (VAT per line, the
 *     document's totals as their sums); the browser's totals are never used.
 *   · Posting writes the journal through Journal_model::post_system — the only
 *     way into the ledger — with source = the document type and source_id = the
 *     document id. A credit or debit note naming the invoice or bill it
 *     corrects is applied to it in the same transaction.
 *   · A posted document is cancelled (accountant) only when nothing is applied
 *     to it and, for a note, it has applied nothing: its journal is reversed
 *     (reverse_system) and the document is marked cancelled, together.
 *
 * ─── ALLOCATIONS ──────────────────────────────────────────────────────────
 * gp_allocations says what settled what: a receipt, payment or note against an
 * invoice or bill. applied_cents on a document caches them — on the invoice or
 * bill it settles AND, for a note, on the note itself — so open = total −
 * applied. Only posted settlements and notes have allocations (a draft receipt
 * keeps its plan elsewhere, see Settlement_model), so applied_cents always
 * equals the sum of the allocations. Every allocation is written with its
 * documents locked FOR UPDATE and refused above the open amount at that moment.
 *
 * NOTE: nested inside our transaction, a posting or reversal that fails in
 * Journal_model THROWS a RuntimeException whose message is fit for the user;
 * mysqli_sql_exception is a RuntimeException too, and is never shown.
 */
class Document_model extends CI_Model
{
    const T = 'gp_documents';
    const L = 'gp_document_lines';
    const A = 'gp_allocations';
    const MAX_LINES = 200;
    const AUDIT_FAIL = 'The audit trail could not be written, so nothing was saved.';

    public function __construct()
    {
        parent::__construct();
        $this->load->library('Arap_lib', NULL, 'arap');
        $this->load->model('Journal_model', 'journals');
        $this->load->model('Contact_model', 'contacts');
    }

    // =========================================================================
    // READ
    // =========================================================================

    public function find($id)
    {
        return $this->db->get_where(self::T, ['id' => (int) $id], 1)->row_array() ?: NULL;
    }

    private function _lock($id)
    {
        return $this->db->query('SELECT * FROM gp_documents WHERE id = ? FOR UPDATE', [(int) $id])->row_array() ?: NULL;
    }

    /** "invoice", "credit note" — for sentences. */
    public static function word($type)
    {
        return strtolower(Arap_lib::TYPE_LABELS[$type] ?? 'document');
    }

    /** A row with its numbers as integers, and where it stands. */
    public function shape(array $d, $today = NULL)
    {
        $today = $today ?: company_today();
        foreach (['id', 'contact_id', 'net_cents', 'vat_cents', 'total_cents', 'applied_cents', 'created_by'] as $k) {
            if (array_key_exists($k, $d)) $d[$k] = (int) $d[$k];
        }
        foreach (['journal_id', 'related_document_id', 'posted_by'] as $k) {
            if (array_key_exists($k, $d)) $d[$k] = $d[$k] !== NULL ? (int) $d[$k] : NULL;
        }
        if (array_key_exists('prices_include_tax', $d)) $d['prices_include_tax'] = (bool) (int) $d['prices_include_tax'];
        $note = Arap_lib::is_note($d['doc_type']);
        $d['type_label']  = Arap_lib::TYPE_LABELS[$d['doc_type']];
        $d['side']        = Arap_lib::side($d['doc_type']);
        $d['ledger_side'] = Arap_lib::ledger_side($d['doc_type']);
        $d['is_note']     = $note;
        $d['open_cents']  = $d['status'] === 'posted' ? $d['total_cents'] - $d['applied_cents'] : 0;
        $d['days_overdue'] = 0;
        if ($d['status'] !== 'posted') {
            $d['state'] = $d['status'];
        } elseif ($note) {
            $d['state'] = $d['open_cents'] > 0 ? 'unapplied' : 'applied';
        } elseif ($d['open_cents'] <= 0) {
            $d['state'] = 'paid';
        } else {
            $days = $d['due_date'] ? Arap_lib::days_between($d['due_date'], $today) : 0;
            $d['days_overdue'] = max(0, $days);
            $d['state'] = $days > 0 ? 'overdue' : ($d['applied_cents'] > 0 ? 'partial' : ($days === 0 && $d['due_date'] ? 'due' : 'not_due'));
        }
        return $d;
    }

    public function lines($id)
    {
        return array_map(function ($l) {
            return [
                'line_no'          => (int) $l['line_no'],
                'account_id'       => (int) $l['account_id'],
                'account_code'     => $l['account_code'],
                'account_name'     => $l['account_name'],
                'description'      => $l['description'],
                'quantity'         => $l['quantity'],
                'unit_price_cents' => (int) $l['unit_price_cents'],
                'amount_cents'     => (int) $l['amount_cents'],
                'vat_mode'         => $l['vat_mode'],
                'net_cents'        => (int) $l['net_cents'],
                'vat_cents'        => (int) $l['vat_cents'],
                'department_id'    => $l['department_id'] !== NULL ? (int) $l['department_id'] : NULL,
                'department'       => $l['department_code'] !== NULL ? $l['department_code'] . ' · ' . $l['department_name'] : NULL,
            ];
        }, $this->db->query(
            'SELECT l.*, a.code AS account_code, a.name AS account_name, d.code AS department_code, d.name AS department_name
               FROM gp_document_lines l JOIN gp_accounts a ON a.id = l.account_id
          LEFT JOIN gp_departments d ON d.id = l.department_id
              WHERE l.document_id = ? ORDER BY l.line_no', [(int) $id]
        )->result_array());
    }

    /**
     * A page of documents for the lists.
     *
     * @param array $f side (sales|purchases), type, status (draft|posted|cancelled), state (open|overdue|paid),
     *                 contact_id, from, to, q, today
     * @return array [rows, total, totals, counts]
     */
    public function search(array $f, $limit, $offset)
    {
        $today = $f['today'] ?? company_today();
        list($where, $args) = $this->_filters($f, $today);

        $total = (int) $this->db->query('SELECT COUNT(*) AS n FROM gp_documents d JOIN gp_contacts c ON c.id = d.contact_id WHERE ' . $where, $args)->row()->n;
        $rows  = $this->db->query(
            'SELECT d.*, c.code AS contact_code, c.name AS contact_name, u.full_name AS created_by_name, u.username AS created_by_username
               FROM gp_documents d JOIN gp_contacts c ON c.id = d.contact_id LEFT JOIN gp_users u ON u.id = d.created_by
              WHERE ' . $where . ' ORDER BY d.doc_date DESC, d.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset, $args
        )->result_array();
        $rows = array_map(function ($r) use ($today) {
            $r['created_by_name'] = $r['created_by_name'] ?: $r['created_by_username'];
            unset($r['created_by_username']);
            return $this->shape($r, $today);
        }, $rows);

        /* Notes count against the totals: a credit note takes away from what customers owe. */
        $t = $this->db->query(
            "SELECT COALESCE(SUM(CASE WHEN d.doc_type IN ('credit_note', 'debit_note') THEN -d.total_cents ELSE d.total_cents END), 0) AS total,
                    COALESCE(SUM(CASE WHEN d.status <> 'posted' THEN 0 WHEN d.doc_type IN ('credit_note', 'debit_note') THEN -(d.total_cents - d.applied_cents)
                                      ELSE d.total_cents - d.applied_cents END), 0) AS open
               FROM gp_documents d JOIN gp_contacts c ON c.id = d.contact_id WHERE " . $where, $args
        )->row_array();

        $g = $f;
        unset($g['status']);
        list($w2, $a2) = $this->_filters($g, $today);
        $counts = ['all' => 0, 'draft' => 0, 'posted' => 0, 'cancelled' => 0];
        foreach ($this->db->query('SELECT d.status, COUNT(*) AS n FROM gp_documents d JOIN gp_contacts c ON c.id = d.contact_id WHERE ' . $w2 . ' GROUP BY d.status', $a2)->result_array() as $r) {
            $counts[$r['status']] = (int) $r['n'];
            $counts['all'] += (int) $r['n'];
        }
        return [$rows, $total, ['total_cents' => (int) $t['total'], 'open_cents' => (int) $t['open']], $counts];
    }

    private function _filters(array $f, $today)
    {
        $w = ['1 = 1'];
        $a = [];
        $side = (string) ($f['side'] ?? '');
        if ($side === 'sales')     $w[] = "d.doc_type IN ('invoice', 'credit_note')";
        if ($side === 'purchases') $w[] = "d.doc_type IN ('bill', 'debit_note')";
        $type = (string) ($f['type'] ?? '');
        if (in_array($type, Arap_lib::DOC_TYPES, TRUE)) { $w[] = 'd.doc_type = ?'; $a[] = $type; }
        $status = (string) ($f['status'] ?? '');
        if (in_array($status, ['draft', 'posted', 'cancelled'], TRUE)) { $w[] = 'd.status = ?'; $a[] = $status; }
        switch ((string) ($f['state'] ?? '')) {
            case 'open':    $w[] = "d.status = 'posted' AND d.total_cents > d.applied_cents"; break;
            case 'overdue': $w[] = "d.status = 'posted' AND d.total_cents > d.applied_cents AND d.doc_type IN ('invoice', 'bill') AND d.due_date < ?"; $a[] = $today; break;
            case 'paid':    $w[] = "d.status = 'posted' AND d.applied_cents >= d.total_cents"; break;
        }
        if ( ! empty($f['contact_id'])) { $w[] = 'd.contact_id = ?'; $a[] = (int) $f['contact_id']; }
        if ( ! empty($f['from']) && Period_model::valid_date($f['from'])) { $w[] = 'd.doc_date >= ?'; $a[] = $f['from']; }
        if ( ! empty($f['to']) && Period_model::valid_date($f['to']))     { $w[] = 'd.doc_date <= ?'; $a[] = $f['to']; }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 80)) . '%';
            $w[] = '(d.doc_no LIKE ? OR d.reference LIKE ? OR d.description LIKE ? OR c.name LIKE ? OR c.code LIKE ?)';
            array_push($a, $like, $like, $like, $like, $like);
        }
        return [implode(' AND ', $w), $a];
    }

    /**
     * What a contact owes or is owed on one side, open to settling: their posted
     * invoices (ar) or bills (ap) not yet paid, oldest first, and their notes
     * with something left to apply.
     */
    public function open_items($side, $contact_id, $today = NULL)
    {
        $today = $today ?: company_today();
        $pick = function ($type) use ($contact_id, $today) {
            return array_map(function ($d) use ($today) {
                $d = $this->shape($d, $today);
                return ['id' => $d['id'], 'doc_type' => $d['doc_type'], 'type_label' => $d['type_label'], 'doc_no' => $d['doc_no'],
                        'doc_date' => $d['doc_date'], 'due_date' => $d['due_date'], 'reference' => $d['reference'],
                        'net_cents' => $d['net_cents'], 'vat_cents' => $d['vat_cents'], 'total_cents' => $d['total_cents'],
                        'applied_cents' => $d['applied_cents'], 'open_cents' => $d['open_cents'], 'days_overdue' => $d['days_overdue'],
                        'state' => $d['state']];
            }, $this->db->query("SELECT * FROM gp_documents WHERE contact_id = ? AND doc_type = ? AND status = 'posted' AND total_cents > applied_cents
                                  ORDER BY doc_date, id", [(int) $contact_id, $type])->result_array());
        };
        return ['targets' => $pick(Arap_lib::target_type($side)), 'notes' => $pick(Arap_lib::note_type($side))];
    }

    // =========================================================================
    // VALIDATION — every figure worked out here
    // =========================================================================

    /**
     * Check a document and work out its figures.
     *
     * @param array      $in       doc_type, contact_id, doc_date, due_date, reference, description, prices_include_tax,
     *                             related_document_id, lines [account_id, description, quantity, unit_price_cents,
     *                             amount_cents, vat_mode, department_id]
     * @param array|NULL $existing the stored row (its type can never change)
     * @return array [head, lines, errors]
     */
    public function validate(array $in, $existing = NULL)
    {
        $e = [];
        $type = $existing ? $existing['doc_type'] : (string) ($in['doc_type'] ?? '');
        if ( ! in_array($type, Arap_lib::DOC_TYPES, TRUE)) return [NULL, [], ['doc_type' => 'Choose an invoice, a credit note, a bill or a debit note.']];
        $side = Arap_lib::ledger_side($type);
        $who  = $side === 'ar' ? 'customer' : 'supplier';
        $word = self::word($type);
        $note = Arap_lib::is_note($type);

        // ── the customer or supplier ─────────────────────────────────────────
        $cid = (int) ($in['contact_id'] ?? 0);
        $c   = $cid ? $this->contacts->find($cid) : NULL;
        if ( ! $c)                                                        $e['contact_id'] = 'Choose the ' . $who . '.';
        elseif ( ! (int) $c[$side === 'ar' ? 'is_customer' : 'is_supplier']) $e['contact_id'] = $c['name'] . ' is not set up as a ' . $who . '. Edit them and tick "' . ucfirst($who) . '" first.';
        elseif ( ! (int) $c['is_active'])                                 $e['contact_id'] = $c['name'] . ' is inactive. Reactivate them first.';

        // ── dates ────────────────────────────────────────────────────────────
        $date = trim((string) ($in['doc_date'] ?? ''));
        if ( ! Period_model::valid_date($date)) {
            $e['doc_date'] = 'Enter the ' . $word . ' date.';
        } else {
            list(, $perr) = $this->periods->open_period_for($date);
            if ($perr !== '') $e['doc_date'] = $perr;
        }

        $due = NULL;
        if ( ! $note) {
            $due = trim((string) ($in['due_date'] ?? ''));
            if ($due === '') {
                $terms = $c && $c['terms_days'] !== NULL ? (int) $c['terms_days'] : $this->arap->terms_default($side);
                $due = Period_model::valid_date($date) ? date('Y-m-d', strtotime($date . ' +' . $terms . ' days')) : NULL;
            } elseif ( ! Period_model::valid_date($due)) {
                $e['due_date'] = 'Enter a valid due date, or leave it empty to use the terms.';
            } elseif (Period_model::valid_date($date) && $due < $date) {
                $e['due_date'] = 'The due date is before the ' . $word . ' date.';
            }
        }

        $ref = clean_line($in['reference'] ?? '', 200);
        if (mb_strlen($ref) > 60) $e['reference'] = 'Keep the reference under 60 characters.';
        $desc = clean_line($in['description'] ?? '', 800);
        if (mb_strlen($desc) > 500) $e['description'] = 'Keep the description under 500 characters.';

        $inclusive = $existing ? (bool) (int) $existing['prices_include_tax'] : $this->arap->inclusive_default();
        if (array_key_exists('prices_include_tax', $in) && $in['prices_include_tax'] !== NULL && $in['prices_include_tax'] !== '') {
            $v = $in['prices_include_tax'];
            $inclusive = is_bool($v) ? $v : ! in_array(strtolower(trim((string) $v)), ['0', 'false', 'no', 'off'], TRUE);
        }

        // ── the invoice or bill a note corrects ──────────────────────────────
        $related = NULL;
        $rid = $note ? (int) ($in['related_document_id'] ?? 0) : 0;
        if ($rid) {
            $related = $this->find($rid);
            $want = Arap_lib::target_type($side);
            if ( ! $related || $related['doc_type'] !== $want)  $e['related_document_id'] = 'Choose the ' . self::word($want) . ' this ' . $word . ' corrects.';
            elseif ($related['status'] !== 'posted')           $e['related_document_id'] = ($related['doc_no'] ?: 'That ' . self::word($want)) . ' is ' . $related['status'] . '; a ' . $word . ' corrects a posted ' . self::word($want) . '.';
            elseif ((int) $related['contact_id'] !== $cid)     $e['related_document_id'] = $related['doc_no'] . ' belongs to another ' . $who . '.';
        }

        // ── the lines ────────────────────────────────────────────────────────
        $raw = [];
        foreach ((isset($in['lines']) && is_array($in['lines'])) ? $in['lines'] : [] as $l) {
            if ( ! is_array($l)) continue;
            $blank = ! (int) ($l['account_id'] ?? 0) && trim((string) ($l['description'] ?? '')) === ''
                  && ! (int) ($l['amount_cents'] ?? 0) && ! (int) ($l['unit_price_cents'] ?? 0);
            if ( ! $blank) $raw[] = $l;
        }
        if ( ! $raw)                        $e['lines'] = 'Add at least one line.';
        elseif (count($raw) > self::MAX_LINES) $e['lines'] = 'A document can have at most ' . self::MAX_LINES . ' lines.';

        $registered = $this->arap->vat_registered();
        $rate       = $this->arap->rate_bp();
        $accts = [];
        $ids = array_values(array_unique(array_filter(array_map(function ($l) { return (int) ($l['account_id'] ?? 0); }, $raw))));
        if ($ids) foreach ($this->db->where_in('id', $ids)->get('gp_accounts')->result_array() as $a) $accts[(int) $a['id']] = $a;
        $depts = [];
        $dids = array_values(array_unique(array_filter(array_map(function ($l) { return (int) ($l['department_id'] ?? 0); }, $raw))));
        if ($dids) foreach ($this->db->where_in('id', $dids)->get('gp_departments')->result_array() as $d) $depts[(int) $d['id']] = $d;

        $lines = [];
        $bad = [];
        $net = 0;
        $vat = 0;
        foreach (isset($e['lines']) ? [] : $raw as $i => $l) {
            $n    = $i + 1;
            $aid  = (int) ($l['account_id'] ?? 0);
            $a    = $accts[$aid] ?? NULL;
            $did  = (int) ($l['department_id'] ?? 0);
            $text = clean_line($l['description'] ?? '', 400);
            $qty  = (isset($l['quantity']) && trim((string) $l['quantity']) !== '') ? Arap_lib::parse_qty($l['quantity']) : ['1.0000', '10000'];
            $price  = Arap_lib::cents($l['unit_price_cents'] ?? 0);
            $amount = Arap_lib::cents($l['amount_cents'] ?? 0);
            $mode = in_array($l['vat_mode'] ?? '', Arap_lib::VAT_MODES, TRUE) ? $l['vat_mode'] : 'vatable';
            if ( ! $registered) $mode = 'exempt';

            $msg = '';
            if ( ! $a)                                   $msg = 'choose an account';
            elseif ((int) $a['is_header'])               $msg = $a['code'] . ' is a header; choose an account under it';
            elseif ( ! (int) $a['is_active'])            $msg = $a['code'] . ' is inactive';
            elseif ($a['control'] !== NULL && $a['control'] !== '') $msg = $a['code'] . ' is a control account; choose the ' . ($side === 'ar' ? 'sales' : 'expense or asset') . ' account';
            elseif (mb_strlen($text) > 255)              $msg = 'keep the description under 255 characters';
            elseif ($qty === NULL)                       $msg = 'enter a quantity above zero, with up to 4 decimals';
            elseif ($price === NULL)                     $msg = 'the unit price is not an amount';
            elseif ($amount === NULL)                    $msg = 'the amount is not an amount';
            elseif ($did && ( ! isset($depts[$did]) || ! (int) $depts[$did]['is_active'])) $msg = 'choose an active department';
            elseif ( ! $did && (int) $a['requires_department']) $msg = $a['code'] . ' needs a department';

            if ($msg === '') {
                if ($amount === 0) $amount = Arap_lib::qty_amount($qty[1], $price);
                if ($amount <= 0)  $msg = 'enter a unit price or an amount';
                elseif ($amount > 99999999999999) $msg = 'the amount is too large';
            }
            if ($msg !== '') { $bad[] = 'Line ' . $n . ': ' . $msg . '.'; continue; }

            if ($price === 0) $price = Arap_lib::round_div(bcmul((string) $amount, '10000'), $qty[1]);
            list($ln, $lv) = Arap_lib::vat_split($amount, $mode, $inclusive, $rate, $registered);
            $net += $ln;
            $vat += $lv;
            $lines[] = [
                'account_id'       => $aid,
                'description'      => $text,
                'quantity'         => $qty[0],
                'unit_price_cents' => $price,
                'amount_cents'     => $amount,
                'vat_mode'         => $mode,
                'net_cents'        => $ln,
                'vat_cents'        => $lv,
                'department_id'    => $did ?: NULL,
            ];
        }
        if ($bad) $e['lines'] = implode(' ', array_slice($bad, 0, 5)) . (count($bad) > 5 ? ' (and ' . (count($bad) - 5) . ' more)' : '');

        $total = $net + $vat;
        if ( ! isset($e['lines']) && $lines && $total <= 0) $e['lines'] = 'The ' . $word . ' has no amount.';
        if ($related && ! isset($e['related_document_id']) && ! isset($e['lines']) && $total > (int) $related['total_cents']) {
            $e['lines'] = 'This ' . $word . ' (' . money_format_cents($total) . ') is more than ' . $related['doc_no'] . ' itself (' . money_format_cents((int) $related['total_cents']) . ').';
        }

        $head = [
            'doc_type'            => $type,
            'contact_id'          => $cid,
            'doc_date'            => $date,
            'due_date'            => $due,
            'reference'           => $ref !== '' ? $ref : NULL,
            'description'         => $desc !== '' ? $desc : NULL,
            'prices_include_tax'  => $inclusive ? 1 : 0,
            'related_document_id' => $rid ?: NULL,
            'net_cents'           => $net,
            'vat_cents'           => $vat,
            'total_cents'         => $total,
        ];
        return [$head, $lines, $e];
    }

    /** A stored document as validate() input (to check it again at posting). */
    private function _stored_input(array $d)
    {
        $lines = [];
        foreach ($this->db->where('document_id', (int) $d['id'])->order_by('line_no')->get(self::L)->result_array() as $l) {
            $lines[] = [
                'account_id' => (int) $l['account_id'], 'description' => $l['description'], 'quantity' => $l['quantity'],
                'unit_price_cents' => (int) $l['unit_price_cents'], 'amount_cents' => (int) $l['amount_cents'],
                'vat_mode' => $l['vat_mode'], 'department_id' => (int) $l['department_id'],
            ];
        }
        return [
            'contact_id' => (int) $d['contact_id'], 'doc_date' => $d['doc_date'], 'due_date' => $d['due_date'] ?? '',
            'reference' => $d['reference'], 'description' => $d['description'], 'prices_include_tax' => (bool) (int) $d['prices_include_tax'],
            'related_document_id' => (int) $d['related_document_id'], 'lines' => $lines,
        ];
    }

    private function _insert_lines($doc_id, array $lines)
    {
        $rows = [];
        foreach (array_values($lines) as $i => $l) $rows[] = ['document_id' => (int) $doc_id, 'line_no' => $i + 1] + $l;
        if ($rows) $this->db->insert_batch(self::L, $rows);
    }

    // =========================================================================
    // DRAFTS
    // =========================================================================

    /** @return array [id, errors] */
    public function create_draft(array $in, array $claims)
    {
        list($h, $lines, $e) = $this->validate($in);
        if ($e) return [0, $e];

        $id = 0;
        $err = $this->_tx(function () use ($h, $lines, $claims, &$id) {
            $now = date('Y-m-d H:i:s');
            $this->db->insert(self::T, $h + ['status' => 'draft', 'applied_cents' => 0, 'created_by' => (int) $claims['user_id'],
                                            'created_at' => $now, 'updated_at' => $now]);
            $id = (int) $this->db->insert_id();
            $this->_insert_lines($id, $lines);
            return log_admin_action($claims, $h['doc_type'] . '.create', 'document', $id,
                ['contact_id' => $h['contact_id'], 'total_cents' => $h['total_cents']]) ? '' : self::AUDIT_FAIL;
        }, 'create');
        return $err === '' ? [$id, []] : [0, ['_' => $err]];
    }

    /** Replace a draft's header and lines — its preparer only. @return array errors */
    public function update_draft($id, array $in, array $claims)
    {
        $d = $this->find($id);
        if ( ! $d) return ['_' => 'That document does not exist.'];
        list($h, $lines, $e) = $this->validate($in, $d);
        if ($e) return $e;

        $err = $this->_tx(function () use ($id, $h, $lines, $claims) {
            $d = $this->_lock($id);
            if ( ! $d) return 'That document does not exist.';
            if ($d['status'] !== 'draft') return 'Only a draft can be changed; this ' . self::word($d['doc_type']) . ' is ' . $d['status'] . '.';
            if ((int) $d['created_by'] !== (int) $claims['user_id']) return 'Only the person who prepared this draft can change it.';
            $this->db->where('document_id', (int) $id)->delete(self::L);
            $this->db->where('id', (int) $id)->update(self::T, $h + ['updated_at' => date('Y-m-d H:i:s')]);
            $this->_insert_lines($id, $lines);
            return log_admin_action($claims, $d['doc_type'] . '.update', 'document', (int) $id, ['total_cents' => $h['total_cents']]) ? '' : self::AUDIT_FAIL;
        }, 'update');
        return $err === '' ? [] : ['_' => $err];
    }

    /**
     * Delete a draft: its preparer, or an accountant. Nothing was numbered or
     * posted, so nothing is lost; the audit log keeps the record.
     * @return string error
     */
    public function delete_draft($id, array $claims)
    {
        return $this->_tx(function () use ($id, $claims) {
            $d = $this->_lock($id);
            if ( ! $d) return 'That document does not exist.';
            if ($d['status'] !== 'draft') return 'Only a draft can be deleted. A posted ' . self::word($d['doc_type']) . ' is cancelled instead.';
            if ((int) $d['created_by'] !== (int) $claims['user_id'] && role_rank($claims['role']) < 3) return 'Only its preparer or an accountant can delete this draft.';
            $this->db->where('document_id', (int) $id)->delete(self::L);
            $this->db->where('id', (int) $id)->delete(self::T);
            return log_admin_action($claims, $d['doc_type'] . '.delete', 'document', (int) $id,
                ['contact_id' => (int) $d['contact_id'], 'total_cents' => (int) $d['total_cents']]) ? '' : self::AUDIT_FAIL;
        }, 'delete');
    }

    // =========================================================================
    // POSTING
    // =========================================================================

    /**
     * Post a draft: number it, write its journal, and — for a note naming the
     * invoice or bill it corrects — apply it there. One transaction.
     *
     * @return array [error '', document number]
     */
    public function post($id, array $claims)
    {
        $uid = (int) $claims['user_id'];
        $no  = NULL;
        $err = $this->_tx(function () use ($id, $claims, $uid, &$no) {
            $d = $this->_lock($id);
            if ( ! $d) return 'That document does not exist.';
            $word = self::word($d['doc_type']);
            if ($d['status'] !== 'draft') return 'This ' . $word . ' is already ' . $d['status'] . '.';
            if ((int) $d['created_by'] === $uid && ! $this->journals->may_self_approve()) {
                return 'You prepared this ' . $word . ', so another accountant must post it.';
            }

            /* Everything again, from what is stored and under the rules in force
               now: the contact, the chart, the period, the VAT settings. */
            list($h, $lines, $e) = $this->validate($this->_stored_input($d), $d);
            if ($e) return reset($e);

            $related = NULL;
            if ($h['related_document_id']) {
                $related = $this->_lock($h['related_document_id']);
                if ( ! $related || $related['status'] !== 'posted') return 'The ' . self::word(Arap_lib::target_type(Arap_lib::ledger_side($d['doc_type']))) . ' this ' . $word . ' corrects is no longer posted.';
            }

            $c  = $this->contacts->find($h['contact_id']);
            $no = $this->arap->number($d['doc_type']);
            list($head, $jl) = $this->_journal($d['doc_type'], $h, $lines, $c, $no, $related);

            list($jid, $errs) = $this->journals->post_system($head, $jl, $uid, $d['doc_type'], (int) $id);
            if ( ! $jid) return reset($errs);

            $now = date('Y-m-d H:i:s');
            $this->db->where('document_id', (int) $id)->delete(self::L);
            $this->_insert_lines($id, $lines);
            $this->db->where('id', (int) $id)->where('status', 'draft')->update(self::T, $h + [
                'status' => 'posted', 'doc_no' => $no, 'journal_id' => $jid, 'posted_by' => $uid, 'posted_at' => $now, 'updated_at' => $now,
            ]);
            if ($this->db->affected_rows() !== 1) return 'This ' . $word . ' changed while it was being posted. Try again.';

            $applied = 0;
            if ($related) {
                $open = (int) $related['total_cents'] - (int) $related['applied_cents'];
                $applied = min($h['total_cents'], $open);
                if ($applied > 0) $this->allocate_locked((int) $related['id'], $applied, NULL, (int) $id);
            }

            $detail = ['doc_no' => $no, 'journal_id' => $jid, 'total_cents' => $h['total_cents']];
            if ($related) $detail += ['applied_to' => $related['doc_no'], 'applied_cents' => $applied];
            return log_admin_action($claims, $d['doc_type'] . '.post', 'document', (int) $id, $detail) ? '' : self::AUDIT_FAIL;
        }, 'post');
        return [$err, $err === '' ? $no : NULL];
    }

    /** The journal a document posts: [head, lines]. */
    private function _journal($type, array $h, array $lines, array $c, $no, $related)
    {
        $side = Arap_lib::ledger_side($type);
        $ctl  = $this->arap->account_id_for($side === 'ar' ? 'acct_ar_control' : 'acct_ap_control');
        if ( ! $ctl) throw new DomainException('Choose the ' . ($side === 'ar' ? 'receivables' : 'payables') . ' control account in Settings → Account defaults first.');
        $vat_acct = 0;
        if ($h['vat_cents'] > 0) {
            $vat_acct = $this->arap->account_id_for($side === 'ar' ? 'acct_output_vat' : 'acct_input_vat');
            if ( ! $vat_acct) throw new DomainException('Choose the ' . ($side === 'ar' ? 'output' : 'input') . ' VAT account in Settings → Account defaults first.');
        }

        $cid   = (int) $c['id'];
        $total = (int) $h['total_cents'];
        $vat   = (int) $h['vat_cents'];
        $rel   = $related ? ' against ' . $related['doc_no'] : '';
        $ref   = $h['reference'];
        $item  = function ($l, $debit) {
            return ['account_id' => $l['account_id'], 'debit_cents' => $debit ? $l['net_cents'] : 0, 'credit_cents' => $debit ? 0 : $l['net_cents'],
                    'memo' => $l['description'] !== '' ? mb_substr($l['description'], 0, 255) : NULL, 'department_id' => $l['department_id']];
        };
        $jl = [];
        switch ($type) {
            case 'invoice':
                $jl[] = ['account_id' => $ctl, 'debit_cents' => $total, 'credit_cents' => 0, 'memo' => $no, 'contact_id' => $cid];
                foreach ($lines as $l) $jl[] = $item($l, FALSE);
                if ($vat > 0) $jl[] = ['account_id' => $vat_acct, 'debit_cents' => 0, 'credit_cents' => $vat, 'memo' => 'Output VAT'];
                $head = ['book' => 'sales', 'reference' => $no, 'description' => 'Invoice ' . $no . ' — ' . $c['name']];
                break;
            case 'credit_note':
                foreach ($lines as $l) $jl[] = $item($l, TRUE);
                if ($vat > 0) $jl[] = ['account_id' => $vat_acct, 'debit_cents' => $vat, 'credit_cents' => 0, 'memo' => 'Output VAT reversed'];
                $jl[] = ['account_id' => $ctl, 'debit_cents' => 0, 'credit_cents' => $total, 'memo' => $no . $rel, 'contact_id' => $cid];
                $head = ['book' => 'sales', 'reference' => $no, 'description' => 'Credit note ' . $no . ' — ' . $c['name'] . ($related ? ' (against ' . $related['doc_no'] . ')' : '')];
                break;
            case 'bill':
                foreach ($lines as $l) $jl[] = $item($l, TRUE);
                if ($vat > 0) $jl[] = ['account_id' => $vat_acct, 'debit_cents' => $vat, 'credit_cents' => 0, 'memo' => 'Input VAT'];
                $jl[] = ['account_id' => $ctl, 'debit_cents' => 0, 'credit_cents' => $total, 'memo' => $no . ($ref ? ' / ' . $ref : ''), 'contact_id' => $cid];
                $head = ['book' => 'purchases', 'reference' => $ref ?: $no, 'description' => 'Bill ' . $no . ' — ' . $c['name'] . ($ref ? ' (' . $ref . ')' : '')];
                break;
            default: // debit_note
                $jl[] = ['account_id' => $ctl, 'debit_cents' => $total, 'credit_cents' => 0, 'memo' => $no . $rel, 'contact_id' => $cid];
                foreach ($lines as $l) $jl[] = $item($l, FALSE);
                if ($vat > 0) $jl[] = ['account_id' => $vat_acct, 'debit_cents' => 0, 'credit_cents' => $vat, 'memo' => 'Input VAT reversed'];
                $head = ['book' => 'purchases', 'reference' => $no, 'description' => 'Debit note ' . $no . ' — ' . $c['name'] . ($related ? ' (against ' . $related['doc_no'] . ')' : '')];
        }
        $head['entry_date'] = $h['doc_date'];
        $head['party_name'] = mb_substr($c['name'], 0, 160);
        $head['description'] = mb_substr($head['description'], 0, 500);
        return [$head, $jl];
    }

    /**
     * Cancel a posted document: reverse its journal (dated $date, with the
     * reason) and mark it cancelled, together. Refused while anything is
     * applied to it, or — for a note — while it is applied to anything.
     * @return string error
     */
    public function cancel($id, $date, $reason, array $claims)
    {
        $reason = clean_line($reason, 400);
        if ($reason === '') return 'Say why the document is cancelled.';
        if (mb_strlen($reason) > 300) return 'Keep the reason under 300 characters.';

        return $this->_tx(function () use ($id, $date, $reason, $claims) {
            $d = $this->_lock($id);
            if ( ! $d) return 'That document does not exist.';
            $word = self::word($d['doc_type']);
            if ($d['status'] === 'draft') return 'This ' . $word . ' is still a draft: delete it instead.';
            if ($d['status'] !== 'posted') return 'This ' . $word . ' is already ' . $d['status'] . '.';
            $n = (int) $this->db->query('SELECT COUNT(*) AS n FROM gp_allocations WHERE document_id = ? OR credit_document_id = ?', [(int) $id, (int) $id])->row()->n;
            if ((int) $d['applied_cents'] !== 0 || $n > 0) {
                return Arap_lib::is_note($d['doc_type'])
                    ? 'This ' . $word . ' has been applied to ' . self::word(Arap_lib::target_type(Arap_lib::ledger_side($d['doc_type']))) . 's. Remove those allocations first.'
                    : 'Payments or credits are applied to this ' . $word . '. Remove them first, then cancel it.';
            }
            if ( ! $d['journal_id']) return 'This ' . $word . ' has no journal to reverse.';

            list($rid, $rerr) = $this->journals->reverse_system((int) $d['journal_id'], (int) $claims['user_id'], $date, $reason, $d['doc_type']);
            if ( ! $rid) return $rerr;

            $this->db->where('id', (int) $id)->where('status', 'posted')->update(self::T, ['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')]);
            if ($this->db->affected_rows() !== 1) return 'This ' . $word . ' changed while it was being cancelled. Try again.';
            $r = $this->journals->find($rid);
            return log_admin_action($claims, $d['doc_type'] . '.cancel', 'document', (int) $id,
                ['doc_no' => $d['doc_no'], 'reversal_id' => $rid, 'reversal_no' => $r['journal_no'], 'date' => $date, 'reason' => $reason]) ? '' : self::AUDIT_FAIL;
        }, 'cancel');
    }

    // =========================================================================
    // ALLOCATIONS
    // =========================================================================

    /**
     * Check allocations against a contact's documents on one side.
     *
     * @param string $side      ar | ap
     * @param array  $allocs    [{document_id, amount_cents}]
     * @param bool   $lock      lock the documents FOR UPDATE (inside a posting transaction)
     * @param bool   $notes_ok  notes may be used as well as paid (a receipt or payment); else only invoices or bills
     * @return array [targets [[row, cents]], notes [[row, cents]], error] — each list oldest first
     */
    public function check_allocations($side, $contact_id, array $allocs, $lock, $notes_ok)
    {
        $want = [];
        foreach ($allocs as $a) {
            if ( ! is_array($a)) continue;
            $did = (int) ($a['document_id'] ?? 0);
            $amt = Arap_lib::cents($a['amount_cents'] ?? 0);
            if ( ! $did) continue;
            if ($amt === NULL) return [[], [], 'An allocation amount is not a whole number of centavos.'];
            if ($amt === 0) continue;
            $want[$did] = ($want[$did] ?? 0) + $amt;
        }
        if ( ! $want) return [[], [], ''];

        $ids = array_keys($want);
        sort($ids);
        $rows = $this->db->query('SELECT * FROM gp_documents WHERE id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY id' . ($lock ? ' FOR UPDATE' : ''))->result_array();
        $by = [];
        foreach ($rows as $r) $by[(int) $r['id']] = $r;

        $target = Arap_lib::target_type($side);
        $note   = Arap_lib::note_type($side);
        $who    = $side === 'ar' ? 'customer' : 'supplier';
        $t = [];
        $n = [];
        foreach ($want as $did => $amt) {
            $d = $by[$did] ?? NULL;
            if ( ! $d) return [[], [], 'One of the documents no longer exists. Reload and try again.'];
            $label = $d['doc_no'] ?: ('draft #' . $did);
            if ((int) $d['contact_id'] !== (int) $contact_id) return [[], [], $label . ' belongs to another ' . $who . '.'];
            if ($d['status'] !== 'posted') return [[], [], $label . ' is ' . $d['status'] . '; only posted documents can be settled.'];
            $open = (int) $d['total_cents'] - (int) $d['applied_cents'];
            if ($d['doc_type'] === $target) {
                if ($amt > $open) return [[], [], $label . ' has only ' . money_format_cents($open) . ' left to pay; you applied ' . money_format_cents($amt) . '.'];
                $t[] = [$d, $amt];
            } elseif ($notes_ok && $d['doc_type'] === $note) {
                if ($amt > $open) return [[], [], $label . ' has only ' . money_format_cents($open) . ' left to apply; you used ' . money_format_cents($amt) . '.'];
                $n[] = [$d, $amt];
            } else {
                return [[], [], $label . ' cannot be settled here: choose ' . ($notes_ok ? self::word($target) . 's or ' . self::word($note) . 's' : self::word($target) . 's') . '.'];
            }
        }
        $old = function ($x, $y) { return [$x[0]['doc_date'], (int) $x[0]['id']] <=> [$y[0]['doc_date'], (int) $y[0]['id']]; };
        usort($t, $old);
        usort($n, $old);
        return [$t, $n, ''];
    }

    /**
     * Record one allocation and raise applied_cents on the document it settles
     * (and on the note it came from). The documents must already be locked and
     * checked; the guard in the UPDATE refuses an over-allocation all the same.
     */
    public function allocate_locked($target_id, $amount, $settlement_id = NULL, $credit_doc_id = NULL)
    {
        $amount = (int) $amount;
        $this->db->insert(self::A, [
            'document_id'        => (int) $target_id,
            'settlement_id'      => $settlement_id ? (int) $settlement_id : NULL,
            'credit_document_id' => $credit_doc_id ? (int) $credit_doc_id : NULL,
            'amount_cents'       => $amount,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);
        $aid = (int) $this->db->insert_id();
        foreach (array_filter([(int) $target_id, (int) $credit_doc_id]) as $did) {
            $this->db->set('applied_cents', 'applied_cents + ' . $amount, FALSE)->where('id', $did)
                     ->where('applied_cents + ' . $amount . ' <= total_cents', NULL, FALSE)->update(self::T);
            if ($this->db->affected_rows() !== 1) throw new DomainException('A document changed while it was being settled. Reload and try again.');
        }
        return $aid;
    }

    /**
     * Write the allocations of a receipt, payment or note: each invoice or bill
     * is settled from the notes first (oldest first), then from the settlement.
     *
     * @return array [[target doc_no, cents, source label]] — what was written
     */
    public function write_allocations(array $targets, array $notes, $settlement_id = NULL)
    {
        $done = [];
        $ni = 0;
        foreach ($targets as $t) {
            $need = (int) $t[1];
            while ($need > 0 && $ni < count($notes)) {
                $use = min($need, (int) $notes[$ni][1]);
                if ($use > 0) {
                    $this->allocate_locked((int) $t[0]['id'], $use, NULL, (int) $notes[$ni][0]['id']);
                    $done[] = [$t[0]['doc_no'], $use, $notes[$ni][0]['doc_no']];
                }
                $notes[$ni][1] -= $use;
                $need -= $use;
                if ($notes[$ni][1] <= 0) $ni++;
            }
            if ($need > 0) {
                if ( ! $settlement_id) throw new DomainException('The allocations are more than there is to apply.');
                $this->allocate_locked((int) $t[0]['id'], $need, (int) $settlement_id, NULL);
                $done[] = [$t[0]['doc_no'], $need, NULL];
            }
        }
        return $done;
    }

    /**
     * Apply a posted note's unapplied balance to the contact's open invoices
     * (credit note) or bills (debit note). No journal: the control account
     * already has both.
     * @return string error
     */
    public function apply($id, array $allocs, array $claims)
    {
        return $this->_tx(function () use ($id, $allocs, $claims) {
            $d = $this->_lock($id);
            if ( ! $d) return 'That document does not exist.';
            $word = self::word($d['doc_type']);
            if ( ! Arap_lib::is_note($d['doc_type'])) return 'Only a credit or debit note is applied from here. Record a receipt or payment for an invoice or bill.';
            if ($d['status'] !== 'posted') return 'Only a posted ' . $word . ' can be applied.';
            $side = Arap_lib::ledger_side($d['doc_type']);
            list($targets, , $err) = $this->check_allocations($side, (int) $d['contact_id'], $allocs, TRUE, FALSE);
            if ($err !== '') return $err;
            if ( ! $targets) return 'Enter how much to apply to at least one ' . self::word(Arap_lib::target_type($side)) . '.';
            $sum = array_sum(array_column($targets, 1));
            $left = (int) $d['total_cents'] - (int) $d['applied_cents'];
            if ($sum > $left) return 'This ' . $word . ' has only ' . money_format_cents($left) . ' left to apply; you applied ' . money_format_cents($sum) . '.';
            $done = $this->write_allocations($targets, [[$d, $sum]], NULL);
            return log_admin_action($claims, $d['doc_type'] . '.apply', 'document', (int) $id, ['doc_no' => $d['doc_no'], 'applied_cents' => $sum,
                'to' => implode(', ', array_map(function ($x) { return $x[0] . ' ' . money_major($x[1]); }, $done))]) ? '' : self::AUDIT_FAIL;
        }, 'apply');
    }

    /**
     * Remove one allocation (accountant, with a reason): the invoice or bill
     * opens again by that much, and the receipt, payment or note gets it back
     * as unapplied. No journal changes: the control account never held it
     * per document.
     * @return string error
     */
    public function remove_allocation($alloc_id, $reason, array $claims)
    {
        $reason = clean_line($reason, 400);
        if ($reason === '') return 'Say why the allocation is removed.';
        if (mb_strlen($reason) > 300) return 'Keep the reason under 300 characters.';

        return $this->_tx(function () use ($alloc_id, $reason, $claims) {
            $a = $this->db->query('SELECT * FROM gp_allocations WHERE id = ? FOR UPDATE', [(int) $alloc_id])->row_array();
            if ( ! $a) return 'That allocation no longer exists.';
            $ids = array_filter([(int) $a['document_id'], (int) $a['credit_document_id']]);
            sort($ids);
            $docs = [];
            foreach ($this->db->query('SELECT * FROM gp_documents WHERE id IN (' . implode(',', $ids) . ') ORDER BY id FOR UPDATE')->result_array() as $r) $docs[(int) $r['id']] = $r;
            $s = $a['settlement_id'] ? $this->db->query('SELECT * FROM gp_settlements WHERE id = ? FOR UPDATE', [(int) $a['settlement_id']])->row_array() : NULL;

            $amt = (int) $a['amount_cents'];
            $this->db->where('id', (int) $alloc_id)->delete(self::A);
            foreach ($ids as $did) {
                $this->db->set('applied_cents', 'applied_cents - ' . $amt, FALSE)->where('id', $did)->where('applied_cents >=', $amt)->update(self::T);
                if ($this->db->affected_rows() !== 1) return 'A document changed while the allocation was being removed. Reload and try again.';
            }

            $target = $docs[(int) $a['document_id']] ?? [];
            $from   = $s ? ($s['settle_no'] ?: 'draft #' . $s['id']) : (($docs[(int) $a['credit_document_id']] ?? [])['doc_no'] ?? '');
            $detail = ['allocation_id' => (int) $alloc_id, 'document' => $target['doc_no'] ?? NULL, 'from' => $from, 'amount_cents' => $amt, 'reason' => $reason];
            if ( ! log_admin_action($claims, 'allocation.remove', 'document', (int) $a['document_id'], $detail)) return self::AUDIT_FAIL;
            $ok = $s ? log_admin_action($claims, 'allocation.remove', 'settlement', (int) $s['id'], $detail)
                     : log_admin_action($claims, 'allocation.remove', 'document', (int) $a['credit_document_id'], $detail);
            return $ok ? '' : self::AUDIT_FAIL;
        }, 'remove_allocation');
    }

    // =========================================================================
    // ONE DOCUMENT
    // =========================================================================

    /** What this person may do with a document now. Every action is checked again when tried. */
    public function permissions(array $d, array $claims)
    {
        $rank  = role_rank($claims['role']);
        $mine  = (int) $d['created_by'] === (int) $claims['user_id'];
        $draft = $d['status'] === 'draft';
        $posted = $d['status'] === 'posted';
        $note  = Arap_lib::is_note($d['doc_type']);
        return [
            'edit'     => $rank >= 2 && $draft && $mine,
            'delete'   => $rank >= 2 && $draft && ($mine || $rank >= 3),
            'post'     => $rank >= 3 && $draft && ( ! $mine || $this->journals->may_self_approve()),
            'cancel'   => $rank >= 3 && $posted && (int) $d['applied_cents'] === 0,
            'apply'    => $rank >= 2 && $posted && $note && (int) $d['total_cents'] > (int) $d['applied_cents'],
            'note'     => $rank >= 2 && $posted && ! $note,
            'settle'   => $rank >= 2 && $posted && ! $note && (int) $d['total_cents'] > (int) $d['applied_cents'],
            'remove_allocation' => $rank >= 3,
        ];
    }

    /** One document with its lines, its customer or supplier, what settled it, its journal and its history. */
    public function detail($id, array $claims)
    {
        $row = $this->find($id);
        if ( ! $row) return NULL;
        $today = company_today();
        $d = $this->shape($row, $today);

        $names = [];
        $uids = array_values(array_unique(array_filter([(int) $row['created_by'], (int) $row['posted_by']])));
        if ($uids) foreach ($this->db->select('id, full_name, username')->where_in('id', $uids)->get('gp_users')->result_array() as $u) $names[(int) $u['id']] = $u['full_name'] ?: $u['username'];
        $d['created_by_name'] = $names[(int) $row['created_by']] ?? NULL;
        $d['posted_by_name']  = $row['posted_by'] ? ($names[(int) $row['posted_by']] ?? NULL) : NULL;

        $c = $this->contacts->shape($this->contacts->find($d['contact_id']));
        $lines = $this->lines($id);

        $bd = ['vatable_cents' => 0, 'exempt_cents' => 0, 'zero_rated_cents' => 0, 'vat_cents' => $d['vat_cents'], 'total_cents' => $d['total_cents']];
        foreach ($lines as $l) $bd[['vatable' => 'vatable_cents', 'exempt' => 'exempt_cents', 'zero_rated' => 'zero_rated_cents'][$l['vat_mode']]] += $l['net_cents'];
        $bd['rate_pct'] = rtrim(rtrim(number_format($this->arap->rate_bp() / 100, 2, '.', ''), '0'), '.');

        $journal = NULL;
        $reversal = NULL;
        if ($d['journal_id']) {
            $j = $this->db->select('id, journal_no, entry_date, status, reversed_by_id, book')->get_where('gp_journals', ['id' => $d['journal_id']], 1)->row_array();
            if ($j) {
                $journal = ['id' => (int) $j['id'], 'journal_no' => $j['journal_no'], 'entry_date' => $j['entry_date'], 'status' => $j['status'], 'book' => $j['book']];
                if ($j['reversed_by_id']) {
                    $r = $this->db->select('id, journal_no, entry_date')->get_where('gp_journals', ['id' => (int) $j['reversed_by_id']], 1)->row_array();
                    if ($r) $reversal = ['id' => (int) $r['id'], 'journal_no' => $r['journal_no'], 'entry_date' => $r['entry_date']];
                }
            }
        }

        $related = NULL;
        if ($d['related_document_id']) {
            $r = $this->find($d['related_document_id']);
            if ($r) { $r = $this->shape($r, $today); $related = ['id' => $r['id'], 'doc_type' => $r['doc_type'], 'type_label' => $r['type_label'], 'doc_no' => $r['doc_no'],
                                                                  'doc_date' => $r['doc_date'], 'total_cents' => $r['total_cents'], 'open_cents' => $r['open_cents'], 'status' => $r['status']]; }
        }
        $notes = array_map(function ($n) {
            return ['id' => (int) $n['id'], 'doc_type' => $n['doc_type'], 'type_label' => Arap_lib::TYPE_LABELS[$n['doc_type']], 'doc_no' => $n['doc_no'],
                    'doc_date' => $n['doc_date'], 'total_cents' => (int) $n['total_cents'], 'status' => $n['status']];
        }, $this->db->query('SELECT id, doc_type, doc_no, doc_date, total_cents, status FROM gp_documents WHERE related_document_id = ? ORDER BY doc_date, id', [(int) $id])->result_array());

        $allocations = array_map(function ($a) {
            $by_note = $a['credit_document_id'] !== NULL;
            return [
                'id'           => (int) $a['id'],
                'amount_cents' => (int) $a['amount_cents'],
                'source'       => $by_note ? 'note' : 'settlement',
                'source_id'    => (int) ($by_note ? $a['credit_document_id'] : $a['settlement_id']),
                'source_label' => $by_note ? (Arap_lib::TYPE_LABELS[$a['n_type']] ?? 'Note') : (Arap_lib::KIND_LABELS[$a['kind']] ?? 'Settlement'),
                'source_no'    => $by_note ? $a['n_no'] : $a['settle_no'],
                'date'         => $by_note ? $a['n_date'] : $a['settle_date'],
                'reference'    => $by_note ? NULL : $a['s_ref'],
                'created_at'   => $a['created_at'],
            ];
        }, $this->db->query(
            'SELECT a.*, s.kind, s.settle_no, s.settle_date, s.reference AS s_ref, n.doc_type AS n_type, n.doc_no AS n_no, n.doc_date AS n_date
               FROM gp_allocations a LEFT JOIN gp_settlements s ON s.id = a.settlement_id LEFT JOIN gp_documents n ON n.id = a.credit_document_id
              WHERE a.document_id = ? ORDER BY a.id', [(int) $id]
        )->result_array());

        $applied_to = array_map(function ($a) {
            return ['id' => (int) $a['id'], 'amount_cents' => (int) $a['amount_cents'], 'document_id' => (int) $a['document_id'],
                    'doc_no' => $a['doc_no'], 'doc_type' => $a['doc_type'], 'doc_date' => $a['doc_date'], 'created_at' => $a['created_at']];
        }, $this->db->query('SELECT a.*, t.doc_no, t.doc_type, t.doc_date FROM gp_allocations a JOIN gp_documents t ON t.id = a.document_id
                              WHERE a.credit_document_id = ? ORDER BY a.id', [(int) $id])->result_array());

        $credit = NULL;
        if ($d['doc_type'] === 'invoice' && $c['credit_limit_cents'] !== NULL) {
            $bal = $this->contacts->balances('ar', NULL, [$c['id']])[$c['id']] ?? 0;
            $after = $bal + ($d['status'] === 'draft' ? $d['total_cents'] : 0);
            $credit = ['limit_cents' => $c['credit_limit_cents'], 'balance_cents' => $bal, 'after_cents' => $after, 'over' => $after > $c['credit_limit_cents']];
        }

        $warnings = [];
        if ($d['doc_type'] === 'bill' && $d['reference'] !== NULL && $d['status'] !== 'cancelled') {
            $dup = $this->db->query("SELECT id, doc_no FROM gp_documents WHERE doc_type = 'bill' AND contact_id = ? AND id <> ? AND status <> 'cancelled'
                                      AND LOWER(TRIM(reference)) = LOWER(TRIM(?)) LIMIT 1", [$d['contact_id'], $d['id'], $d['reference']])->row_array();
            if ($dup) $warnings[] = 'Another bill from this supplier has the same reference ' . $d['reference'] . ': ' . ($dup['doc_no'] ?: 'draft #' . $dup['id']) . '. Check that it is not entered twice.';
        }

        $trail = [];
        foreach ($this->db->query(
            'SELECT a.action, a.detail, a.occurred_at, a.admin_id, u.full_name, u.username FROM gp_admin_audit_log a LEFT JOIN gp_users u ON u.id = a.admin_id
              WHERE a.target_type = ? AND a.target_id = ? ORDER BY a.id', ['document', (int) $id]
        )->result_array() as $t) {
            $x = $t['detail'] !== NULL ? json_decode($t['detail'], TRUE) : NULL;
            $trail[] = ['action' => $t['action'], 'at' => $t['occurred_at'], 'who' => $t['full_name'] ?: ($t['username'] ?: '#' . (int) $t['admin_id']),
                        'detail' => is_array($x) ? $x : NULL];
        }

        return [
            'document'    => $d,
            'contact'     => $c,
            'lines'       => $lines,
            'breakdown'   => $bd,
            'journal'     => $journal,
            'reversal'    => $reversal,
            'related'     => $related,
            'notes'       => $notes,
            'allocations' => $allocations,
            'applied_to'  => $applied_to,
            'credit'      => $credit,
            'warnings'    => $warnings,
            'trail'       => $trail,
            'can'         => $this->permissions($row, $claims),
            'policy'      => ['self_approve' => $this->journals->may_self_approve()],
            'today'       => $today,
        ];
    }

    // =========================================================================
    // AGING — open items at a date, tied to the control account
    // =========================================================================

    /**
     * Receivables (ar) or payables (ap) by age at $as_of, per contact.
     *
     * An item counts at a date when its journal is posted on or before it and
     * not reversed by then — so a cancelled invoice still shows at a date before
     * its cancellation, exactly as the ledger does. An allocation counts once
     * both its document and its source (receipt, payment or note) count. Open
     * at the date = total − the allocations that count. What a receipt, payment
     * or note has not settled by then is shown as unapplied. So each contact's
     * net equals their ledger balance, and the total equals the control
     * accounts — unless someone posted to a control account by hand; those
     * lines are listed as the reconciling difference.
     */
    public function aging($side, $as_of, $contact_id = 0)
    {
        $limits = Arap_lib::buckets();
        $labels = Arap_lib::bucket_labels($limits);
        $nb     = count($labels);
        $target = Arap_lib::target_type($side);
        $note   = Arap_lib::note_type($side);
        $kind   = Arap_lib::kind_for($side);
        $cw     = $contact_id ? ' AND x.contact_id = ' . (int) $contact_id : '';
        $on     = 'JOIN gp_journals j ON j.id = x.journal_id AND j.status = \'posted\' LEFT JOIN gp_journals r ON r.id = j.reversed_by_id AND r.status = \'posted\'';
        $when   = 'j.entry_date <= ? AND (r.id IS NULL OR r.entry_date > ?)';

        $docs = [];
        foreach ($this->db->query(
            "SELECT x.id, x.doc_type, x.doc_no, x.contact_id, x.doc_date, x.due_date, x.reference, x.total_cents
               FROM gp_documents x $on WHERE x.doc_type IN (?, ?) AND x.status IN ('posted', 'cancelled') AND $when $cw",
            [$target, $note, $as_of, $as_of]
        )->result_array() as $d) $docs[(int) $d['id']] = $d + ['applied' => 0];

        $sets = [];
        foreach ($this->db->query(
            "SELECT x.id, x.settle_no, x.contact_id, x.settle_date, x.reference, x.amount_cents + x.withholding_cents AS total_cents
               FROM gp_settlements x $on WHERE x.kind = ? AND x.status IN ('posted', 'cancelled') AND $when $cw",
            [$kind, $as_of, $as_of]
        )->result_array() as $s) $sets[(int) $s['id']] = $s + ['applied' => 0];

        foreach ($this->db->query(
            'SELECT a.document_id, a.settlement_id, a.credit_document_id, a.amount_cents FROM gp_allocations a JOIN gp_documents t ON t.id = a.document_id
              WHERE t.doc_type = ?' . ($contact_id ? ' AND t.contact_id = ' . (int) $contact_id : ''), [$target]
        )->result_array() as $a) {
            $tid = (int) $a['document_id'];
            if ( ! isset($docs[$tid])) continue;
            if ($a['settlement_id'] !== NULL && ! isset($sets[(int) $a['settlement_id']])) continue;
            if ($a['credit_document_id'] !== NULL && ! isset($docs[(int) $a['credit_document_id']])) continue;
            $amt = (int) $a['amount_cents'];
            $docs[$tid]['applied'] += $amt;
            if ($a['settlement_id'] !== NULL) $sets[(int) $a['settlement_id']]['applied'] += $amt;
            else $docs[(int) $a['credit_document_id']]['applied'] += $amt;
        }

        $rows = [];
        $row = function ($cid) use (&$rows, $nb) {
            if ( ! isset($rows[$cid])) $rows[$cid] = ['contact_id' => $cid, 'buckets' => array_fill(0, $nb, 0), 'open_cents' => 0, 'unapplied_cents' => 0, 'items' => []];
        };
        foreach ($docs as $id => $d) {
            $left = (int) $d['total_cents'] - $d['applied'];
            if ($left <= 0) continue;
            $cid = (int) $d['contact_id'];
            $row($cid);
            if ($d['doc_type'] === $target) {
                $due  = $d['due_date'] ?: $d['doc_date'];
                $days = Arap_lib::days_between($due, $as_of);
                $b    = Arap_lib::bucket_of($days, $limits);
                $rows[$cid]['buckets'][$b] += $left;
                $rows[$cid]['open_cents'] += $left;
                $rows[$cid]['items'][] = ['kind' => 'document', 'id' => $id, 'doc_type' => $d['doc_type'], 'number' => $d['doc_no'], 'date' => $d['doc_date'],
                                          'due_date' => $d['due_date'], 'reference' => $d['reference'], 'total_cents' => (int) $d['total_cents'],
                                          'open_cents' => $left, 'days_overdue' => max(0, $days), 'bucket' => $b];
            } else {
                $rows[$cid]['unapplied_cents'] += $left;
                $rows[$cid]['items'][] = ['kind' => 'document', 'id' => $id, 'doc_type' => $d['doc_type'], 'number' => $d['doc_no'], 'date' => $d['doc_date'],
                                          'due_date' => NULL, 'reference' => $d['reference'], 'total_cents' => (int) $d['total_cents'],
                                          'open_cents' => -$left, 'days_overdue' => 0, 'bucket' => NULL];
            }
        }
        foreach ($sets as $id => $s) {
            $left = (int) $s['total_cents'] - $s['applied'];
            if ($left <= 0) continue;
            $cid = (int) $s['contact_id'];
            $row($cid);
            $rows[$cid]['unapplied_cents'] += $left;
            $rows[$cid]['items'][] = ['kind' => 'settlement', 'id' => $id, 'doc_type' => $kind, 'number' => $s['settle_no'], 'date' => $s['settle_date'],
                                      'due_date' => NULL, 'reference' => $s['reference'], 'total_cents' => (int) $s['total_cents'],
                                      'open_cents' => -$left, 'days_overdue' => 0, 'bucket' => NULL];
        }

        /* The ledger: each contact's balance on the control accounts, and the accounts' total. */
        $ctl = $this->arap->control_ids($side);
        $ledger = $this->contacts->balances($side, $as_of, $contact_id ? [(int) $contact_id] : NULL);
        foreach ($ledger as $cid => $bal) if ($bal !== 0) $row($cid);
        $sign = $side === 'ar' ? 1 : -1;
        $control = 0;
        if ($ctl) {
            $control = $sign * (int) $this->db->query('SELECT COALESCE(SUM(net_cents), 0) AS n FROM gp_ledger WHERE account_id IN ('
                . implode(',', $ctl) . ') AND entry_date <= ?' . ($contact_id ? ' AND contact_id = ' . (int) $contact_id : ''), [$as_of])->row()->n;
        }

        $contacts = [];
        if ($rows) foreach ($this->db->where_in('id', array_keys($rows))->get('gp_contacts')->result_array() as $c) $contacts[(int) $c['id']] = $c;
        $tot = ['buckets' => array_fill(0, $nb, 0), 'open_cents' => 0, 'unapplied_cents' => 0, 'net_cents' => 0, 'ledger_cents' => 0];
        foreach ($rows as $cid => &$r) {
            $c = $contacts[$cid] ?? ['code' => '?', 'name' => 'Unknown #' . $cid, 'is_active' => 0];
            $r['code'] = $c['code'];
            $r['name'] = $c['name'];
            $r['is_active'] = (bool) (int) $c['is_active'];
            $r['net_cents'] = $r['open_cents'] - $r['unapplied_cents'];
            $r['ledger_cents'] = $ledger[$cid] ?? 0;
            $r['difference_cents'] = $r['ledger_cents'] - $r['net_cents'];
            usort($r['items'], function ($x, $y) { return [$x['date'], $x['number']] <=> [$y['date'], $y['number']]; });
            foreach ($r['buckets'] as $i => $v) $tot['buckets'][$i] += $v;
            $tot['open_cents'] += $r['open_cents'];
            $tot['unapplied_cents'] += $r['unapplied_cents'];
            $tot['net_cents'] += $r['net_cents'];
            $tot['ledger_cents'] += $r['ledger_cents'];
        }
        unset($r);
        $rows = array_values($rows);
        usort($rows, function ($x, $y) { return strcasecmp($x['name'], $y['name']) ?: strcmp($x['code'], $y['code']); });

        /* Lines on the control accounts that no document or settlement stands behind (entries made by hand). */
        $lines = [];
        $lines_total = 0;
        if ($ctl) {
            foreach ($this->db->query(
                "SELECT l.journal_id, l.journal_no, l.entry_date, l.description, l.memo, l.source, l.contact_id, c.name AS contact_name, l.debit_cents, l.credit_cents
                   FROM gp_ledger l LEFT JOIN gp_contacts c ON c.id = l.contact_id
                  WHERE l.account_id IN (" . implode(',', $ctl) . ") AND l.entry_date <= ?" . ($contact_id ? ' AND l.contact_id = ' . (int) $contact_id : '') . "
                    AND l.journal_id NOT IN (SELECT journal_id FROM gp_documents WHERE journal_id IS NOT NULL)
                    AND l.journal_id NOT IN (SELECT journal_id FROM gp_settlements WHERE journal_id IS NOT NULL)
                    AND NOT (l.source = 'reversal' AND l.source_id IN (SELECT journal_id FROM gp_documents WHERE journal_id IS NOT NULL
                                                                     UNION SELECT journal_id FROM gp_settlements WHERE journal_id IS NOT NULL))
                  ORDER BY l.entry_date, l.journal_id, l.line_no", [$as_of]
            )->result_array() as $l) {
                $amt = $sign * ((int) $l['debit_cents'] - (int) $l['credit_cents']);
                $lines_total += $amt;
                if (count($lines) < 200) {
                    $lines[] = ['journal_id' => (int) $l['journal_id'], 'journal_no' => $l['journal_no'], 'date' => $l['entry_date'],
                                'description' => $l['description'], 'memo' => $l['memo'], 'source' => $l['source'],
                                'contact_id' => $l['contact_id'] !== NULL ? (int) $l['contact_id'] : NULL, 'contact' => $l['contact_name'], 'amount_cents' => $amt];
                }
            }
        }
        $accounts = $ctl ? array_map(function ($a) { return ['id' => (int) $a['id'], 'code' => $a['code'], 'name' => $a['name']]; },
            $this->db->where_in('id', $ctl)->order_by('code')->get('gp_accounts')->result_array()) : [];

        return [
            'side'    => $side,
            'as_of'   => $as_of,
            'labels'  => $labels,
            'limits'  => $limits,
            'rows'    => $rows,
            'totals'  => $tot,
            'ledger'  => [
                'accounts'          => $accounts,
                'control_cents'     => $control,
                'difference_cents'  => $control - $tot['net_cents'],
                'lines'             => $lines,
                'lines_total_cents' => $lines_total,
            ],
        ];
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    /**
     * Run $fn in a transaction: '' commits; an error message, a DomainException
     * or any other exception rolls everything back (the posting too).
     */
    private function _tx(callable $fn, $what)
    {
        $this->db->trans_begin();
        try {
            $err = (string) $fn();
        } catch (DomainException $x) {
            $err = $x->getMessage();
        } catch (Throwable $t) {
            if ($t instanceof RuntimeException && ! ($t instanceof mysqli_sql_exception)) {
                $err = $t->getMessage();          // Journal_model's refusal, written for the user
            } else {
                log_message('error', '[Document_model] ' . $what . ': ' . $t->getMessage());
                $err = 'The database refused the change, so nothing was saved. Try again.';
            }
        }
        if ($err === '') {
            $this->db->trans_commit();
            return '';
        }
        $this->db->trans_rollback();
        return $err;
    }
}
