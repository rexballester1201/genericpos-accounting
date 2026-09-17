<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Settlement_model.php — receipts from customers and payments to suppliers
 *
 * GenericPOS Accounting · receivables and payables module · table gp_settlements
 *
 * ─── LIFE OF A RECEIPT OR PAYMENT ─────────────────────────────────────────
 *   draft ──post──▶ posted ──cancel──▶ cancelled (its journal reversed, its allocations removed)
 *   draft ──delete──▶ gone
 *
 *   amount_cents is the cash that moved; withholding_cents is the tax withheld
 *   on top of it (CWT a customer withheld on a receipt, EWT we withheld on a
 *   payment). Together they settle the invoices or bills they are applied to;
 *   whatever is not applied stays on the contact's account as unapplied, and
 *   can be applied later.
 *
 * ─── THE PLAN OF A DRAFT ──────────────────────────────────────────────────
 * gp_allocations holds only what has really been settled (posted), so every
 * document's applied_cents equals the sum of its allocations. A DRAFT's
 * intended allocations — [{document_id, amount_cents}] — are kept in
 * gp_app_state under 'arap.plan.<settlement id>' until the draft posts (they
 * become gp_allocations rows in the posting transaction) or is deleted.
 * A plan may also use the contact's unapplied credit notes (receipts) or
 * debit notes (payments): at posting each invoice or bill is settled from
 * those notes first, then from the receipt or payment.
 *
 * ─── POSTING ──────────────────────────────────────────────────────────────
 *   receipt → cash_receipts:     Dr cash (amount) · Dr CWT (withholding) · Cr AR control (both, the customer)
 *   payment → cash_disbursements: Dr AP control (both, the supplier) · Cr cash (amount) · Cr EWT (withholding)
 * The number (RC-/PV-), the journal and the allocations are written in one
 * transaction, with the documents locked FOR UPDATE and every allocation
 * refused above the document's open amount at that moment.
 */
class Settlement_model extends CI_Model
{
    const T = 'gp_settlements';
    const PLAN = 'arap.plan.';
    const AUDIT_FAIL = 'The audit trail could not be written, so nothing was saved.';

    public function __construct()
    {
        parent::__construct();
        $this->load->library('Arap_lib', NULL, 'arap');
        $this->load->model('Journal_model', 'journals');
        $this->load->model('Contact_model', 'contacts');
        $this->load->model('Document_model', 'documents');
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
        return $this->db->query('SELECT * FROM gp_settlements WHERE id = ? FOR UPDATE', [(int) $id])->row_array() ?: NULL;
    }

    public function shape(array $s)
    {
        foreach (['id', 'contact_id', 'cash_account_id', 'amount_cents', 'withholding_cents', 'created_by'] as $k) {
            if (array_key_exists($k, $s)) $s[$k] = (int) $s[$k];
        }
        foreach (['journal_id', 'posted_by'] as $k) if (array_key_exists($k, $s)) $s[$k] = $s[$k] !== NULL ? (int) $s[$k] : NULL;
        $s['kind_label']  = Arap_lib::KIND_LABELS[$s['kind']];
        $s['ledger_side'] = Arap_lib::ledger_side($s['kind']);
        $s['total_cents'] = $s['amount_cents'] + $s['withholding_cents'];
        if (array_key_exists('applied_cents', $s)) {
            $s['applied_cents']   = (int) $s['applied_cents'];
            $s['unapplied_cents'] = $s['status'] === 'posted' ? $s['total_cents'] - $s['applied_cents'] : 0;
        }
        return $s;
    }

    /** What a posted receipt or payment has settled so far. */
    public function applied($id)
    {
        return (int) $this->db->query('SELECT COALESCE(SUM(amount_cents), 0) AS n FROM gp_allocations WHERE settlement_id = ?', [(int) $id])->row()->n;
    }

    /** A draft's intended allocations: [{document_id, amount_cents}]. */
    public function plan($id)
    {
        $r = $this->db->select('v')->get_where('gp_app_state', ['k' => self::PLAN . (int) $id], 1)->row_array();
        $p = $r ? json_decode($r['v'], TRUE) : NULL;
        return is_array($p) ? array_values(array_filter($p, 'is_array')) : [];
    }

    private function _save_plan($id, array $allocs)
    {
        if ( ! $allocs) return $this->_drop_plan($id);
        $this->db->query('INSERT INTO gp_app_state (k, v, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)',
            [self::PLAN . (int) $id, json_encode(array_values($allocs)), date('Y-m-d H:i:s')]);
    }

    private function _drop_plan($id)
    {
        $this->db->where('k', self::PLAN . (int) $id)->delete('gp_app_state');
    }

    /**
     * A page of receipts or payments.
     * @param array $f kind, status, state ('unapplied'), contact_id, from, to, q
     * @return array [rows, total, totals, counts]
     */
    public function search(array $f, $limit, $offset)
    {
        list($where, $args) = $this->_filters($f);
        $applied = '(SELECT COALESCE(SUM(a.amount_cents), 0) FROM gp_allocations a WHERE a.settlement_id = s.id)';
        $from    = ' FROM gp_settlements s JOIN gp_contacts c ON c.id = s.contact_id ';

        $total = (int) $this->db->query('SELECT COUNT(*) AS n' . $from . 'WHERE ' . $where, $args)->row()->n;
        $rows = $this->db->query(
            'SELECT s.*, c.code AS contact_code, c.name AS contact_name, a.code AS cash_code, a.name AS cash_name, ' . $applied . ' AS applied_cents,
                    u.full_name AS created_by_name, u.username AS created_by_username'
            . $from . 'JOIN gp_accounts a ON a.id = s.cash_account_id LEFT JOIN gp_users u ON u.id = s.created_by
              WHERE ' . $where . ' ORDER BY s.settle_date DESC, s.id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset, $args
        )->result_array();
        $rows = array_map(function ($r) {
            $r['created_by_name'] = $r['created_by_name'] ?: $r['created_by_username'];
            unset($r['created_by_username']);
            return $this->shape($r);
        }, $rows);

        $t = $this->db->query(
            "SELECT COALESCE(SUM(s.amount_cents), 0) AS amount, COALESCE(SUM(s.withholding_cents), 0) AS wh,
                    COALESCE(SUM(CASE WHEN s.status = 'posted' THEN s.amount_cents + s.withholding_cents - " . $applied . " ELSE 0 END), 0) AS unapplied"
            . $from . "WHERE " . $where . " AND s.status <> 'cancelled'", $args
        )->row_array();

        $g = $f;
        unset($g['status']);
        list($w2, $a2) = $this->_filters($g);
        $counts = ['all' => 0, 'draft' => 0, 'posted' => 0, 'cancelled' => 0];
        foreach ($this->db->query('SELECT s.status, COUNT(*) AS n' . $from . 'WHERE ' . $w2 . ' GROUP BY s.status', $a2)->result_array() as $r) {
            $counts[$r['status']] = (int) $r['n'];
            $counts['all'] += (int) $r['n'];
        }
        return [$rows, $total, ['amount_cents' => (int) $t['amount'], 'withholding_cents' => (int) $t['wh'], 'unapplied_cents' => (int) $t['unapplied']], $counts];
    }

    private function _filters(array $f)
    {
        $w = ['1 = 1'];
        $a = [];
        $kind = (string) ($f['kind'] ?? '');
        if (in_array($kind, Arap_lib::KINDS, TRUE)) { $w[] = 's.kind = ?'; $a[] = $kind; }
        $status = (string) ($f['status'] ?? '');
        if (in_array($status, ['draft', 'posted', 'cancelled'], TRUE)) { $w[] = 's.status = ?'; $a[] = $status; }
        if (($f['state'] ?? '') === 'unapplied') {
            $w[] = "s.status = 'posted' AND s.amount_cents + s.withholding_cents > (SELECT COALESCE(SUM(a.amount_cents), 0) FROM gp_allocations a WHERE a.settlement_id = s.id)";
        }
        if ( ! empty($f['contact_id'])) { $w[] = 's.contact_id = ?'; $a[] = (int) $f['contact_id']; }
        if ( ! empty($f['from']) && Period_model::valid_date($f['from'])) { $w[] = 's.settle_date >= ?'; $a[] = $f['from']; }
        if ( ! empty($f['to']) && Period_model::valid_date($f['to']))     { $w[] = 's.settle_date <= ?'; $a[] = $f['to']; }
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 80)) . '%';
            $w[] = '(s.settle_no LIKE ? OR s.reference LIKE ? OR s.description LIKE ? OR c.name LIKE ? OR c.code LIKE ?)';
            array_push($a, $like, $like, $like, $like, $like);
        }
        return [implode(' AND ', $w), $a];
    }

    // =========================================================================
    // VALIDATION
    // =========================================================================

    /**
     * @param array      $in       kind, contact_id, settle_date, cash_account_id, reference, amount_cents,
     *                             withholding_cents, description, allocations [{document_id, amount_cents}]
     * @param array|NULL $existing the stored row (its kind never changes)
     * @return array [head, allocations, errors]
     */
    public function validate(array $in, $existing = NULL)
    {
        $e = [];
        $kind = $existing ? $existing['kind'] : (string) ($in['kind'] ?? '');
        if ( ! in_array($kind, Arap_lib::KINDS, TRUE)) return [NULL, [], ['kind' => 'Choose a receipt or a payment.']];
        $side = Arap_lib::ledger_side($kind);
        $who  = $side === 'ar' ? 'customer' : 'supplier';
        $rcpt = $kind === 'receipt';

        $cid = (int) ($in['contact_id'] ?? 0);
        $c   = $cid ? $this->contacts->find($cid) : NULL;
        if ( ! $c)                                                        $e['contact_id'] = 'Choose the ' . $who . '.';
        elseif ( ! (int) $c[$side === 'ar' ? 'is_customer' : 'is_supplier']) $e['contact_id'] = $c['name'] . ' is not set up as a ' . $who . '.';
        elseif ( ! (int) $c['is_active'])                                 $e['contact_id'] = $c['name'] . ' is inactive. Reactivate them first.';

        $date = trim((string) ($in['settle_date'] ?? ''));
        if ( ! Period_model::valid_date($date)) {
            $e['settle_date'] = 'Enter the date the money was ' . ($rcpt ? 'received' : 'paid') . '.';
        } else {
            list(, $perr) = $this->periods->open_period_for($date);
            if ($perr !== '') $e['settle_date'] = $perr;
        }

        $cash = (int) ($in['cash_account_id'] ?? 0);
        if ( ! in_array($cash, array_column($this->arap->cash_accounts(), 'id'), TRUE)) {
            $e['cash_account_id'] = 'Choose the cash or bank account the money ' . ($rcpt ? 'went into' : 'came from') . '.';
        }

        $ref = clean_line($in['reference'] ?? '', 200);
        if (mb_strlen($ref) > 60) $e['reference'] = 'Keep the ' . ($rcpt ? 'OR' : 'cheque') . ' number under 60 characters.';

        $amount = Arap_lib::cents($in['amount_cents'] ?? 0);
        if ($amount === NULL || $amount <= 0) $e['amount_cents'] = 'Enter the amount ' . ($rcpt ? 'received' : 'paid') . '.';
        $wh = Arap_lib::cents($in['withholding_cents'] ?? 0);
        if ($wh === NULL) $e['withholding_cents'] = 'Enter the tax withheld as an amount, or leave it at zero.';

        $desc = clean_line($in['description'] ?? '', 800);
        if (mb_strlen($desc) > 500) $e['description'] = 'Keep the description under 500 characters.';

        $allocs = [];
        if ( ! isset($e['contact_id'])) {
            list($t, $n, $err) = $this->documents->check_allocations($side, $cid, (isset($in['allocations']) && is_array($in['allocations'])) ? $in['allocations'] : [], FALSE, TRUE);
            if ($err !== '') {
                $e['allocations'] = $err;
            } else {
                $err = $this->_sums($kind, $t, $n, (int) $amount + (int) $wh);
                if ($err !== '') $e['allocations'] = $err;
                foreach (array_merge($t, $n) as $x) $allocs[] = ['document_id' => (int) $x[0]['id'], 'amount_cents' => (int) $x[1]];
            }
        }

        $head = [
            'kind'              => $kind,
            'contact_id'        => $cid,
            'settle_date'       => $date,
            'cash_account_id'   => $cash,
            'reference'         => $ref !== '' ? $ref : NULL,
            'amount_cents'      => (int) $amount,
            'withholding_cents' => (int) $wh,
            'description'       => $desc !== '' ? $desc : NULL,
        ];
        return [$head, $allocs, $e];
    }

    /** '' when the invoices applied, less the notes used, fit in what there is to apply. */
    private function _sums($kind, array $t, array $n, $available)
    {
        $st = array_sum(array_column($t, 1));
        $sn = array_sum(array_column($n, 1));
        $docs  = $kind === 'receipt' ? 'invoices' : 'bills';
        $notes = $kind === 'receipt' ? 'credit notes' : 'debit notes';
        if ($sn > 0 && ! $t) return 'Apply the ' . $notes . ' to ' . $docs . ': choose at least one of the ' . $docs . ' they settle.';
        if ($sn > $st) return 'The ' . $notes . ' you used (' . money_format_cents($sn) . ') are more than the ' . $docs . ' they settle (' . money_format_cents($st) . ').';
        if ($st - $sn > $available) {
            return 'The ' . $docs . ' you applied' . ($sn ? ', less the ' . $notes . ',' : '') . ' come to ' . money_format_cents($st - $sn)
                 . ', more than this ' . $kind . ' and the tax withheld (' . money_format_cents($available) . '). Lower an allocation or the amount.';
        }
        return '';
    }

    private function _stored_input(array $s, array $plan)
    {
        return ['contact_id' => (int) $s['contact_id'], 'settle_date' => $s['settle_date'], 'cash_account_id' => (int) $s['cash_account_id'],
                'reference' => $s['reference'], 'amount_cents' => (int) $s['amount_cents'], 'withholding_cents' => (int) $s['withholding_cents'],
                'description' => $s['description'], 'allocations' => $plan];
    }

    // =========================================================================
    // DRAFTS
    // =========================================================================

    /** @return array [id, errors] */
    public function create_draft(array $in, array $claims)
    {
        list($h, $allocs, $e) = $this->validate($in);
        if ($e) return [0, $e];
        $id = 0;
        $err = $this->_tx(function () use ($h, $allocs, $claims, &$id) {
            $now = date('Y-m-d H:i:s');
            $this->db->insert(self::T, $h + ['status' => 'draft', 'created_by' => (int) $claims['user_id'], 'created_at' => $now, 'updated_at' => $now]);
            $id = (int) $this->db->insert_id();
            $this->_save_plan($id, $allocs);
            return log_admin_action($claims, $h['kind'] . '.create', 'settlement', $id,
                ['contact_id' => $h['contact_id'], 'amount_cents' => $h['amount_cents'], 'withholding_cents' => $h['withholding_cents']]) ? '' : self::AUDIT_FAIL;
        }, 'create');
        return $err === '' ? [$id, []] : [0, ['_' => $err]];
    }

    /** @return array errors */
    public function update_draft($id, array $in, array $claims)
    {
        $s = $this->find($id);
        if ( ! $s) return ['_' => 'That receipt or payment does not exist.'];
        list($h, $allocs, $e) = $this->validate($in, $s);
        if ($e) return $e;
        $err = $this->_tx(function () use ($id, $h, $allocs, $claims) {
            $s = $this->_lock($id);
            if ( ! $s) return 'That receipt or payment does not exist.';
            if ($s['status'] !== 'draft') return 'Only a draft can be changed; this ' . $s['kind'] . ' is ' . $s['status'] . '.';
            if ((int) $s['created_by'] !== (int) $claims['user_id']) return 'Only the person who prepared this draft can change it.';
            $this->db->where('id', (int) $id)->update(self::T, $h + ['updated_at' => date('Y-m-d H:i:s')]);
            $this->_save_plan($id, $allocs);
            return log_admin_action($claims, $s['kind'] . '.update', 'settlement', (int) $id,
                ['amount_cents' => $h['amount_cents'], 'withholding_cents' => $h['withholding_cents']]) ? '' : self::AUDIT_FAIL;
        }, 'update');
        return $err === '' ? [] : ['_' => $err];
    }

    /** Delete a draft: its preparer, or an accountant. @return string error */
    public function delete_draft($id, array $claims)
    {
        return $this->_tx(function () use ($id, $claims) {
            $s = $this->_lock($id);
            if ( ! $s) return 'That receipt or payment does not exist.';
            if ($s['status'] !== 'draft') return 'Only a draft can be deleted. A posted ' . $s['kind'] . ' is cancelled instead.';
            if ((int) $s['created_by'] !== (int) $claims['user_id'] && role_rank($claims['role']) < 3) return 'Only its preparer or an accountant can delete this draft.';
            $this->db->where('id', (int) $id)->delete(self::T);
            $this->_drop_plan($id);
            return log_admin_action($claims, $s['kind'] . '.delete', 'settlement', (int) $id,
                ['contact_id' => (int) $s['contact_id'], 'amount_cents' => (int) $s['amount_cents']]) ? '' : self::AUDIT_FAIL;
        }, 'delete');
    }

    // =========================================================================
    // POSTING, CANCELLING, APPLYING
    // =========================================================================

    /** @return array [error '', number] */
    public function post($id, array $claims)
    {
        $uid = (int) $claims['user_id'];
        $no  = NULL;
        $err = $this->_tx(function () use ($id, $claims, $uid, &$no) {
            $s = $this->_lock($id);
            if ( ! $s) return 'That receipt or payment does not exist.';
            $kind = $s['kind'];
            if ($s['status'] !== 'draft') return 'This ' . $kind . ' is already ' . $s['status'] . '.';
            if ((int) $s['created_by'] === $uid && ! $this->journals->may_self_approve()) {
                return 'You prepared this ' . $kind . ', so another accountant must post it.';
            }

            list($h, $allocs, $e) = $this->validate($this->_stored_input($s, $this->plan($id)), $s);
            if ($e) return reset($e);

            /* The documents again, locked, at this moment: another receipt may
               have paid one of them since the draft was saved. */
            $side = Arap_lib::ledger_side($kind);
            list($t, $n, $aerr) = $this->documents->check_allocations($side, $h['contact_id'], $allocs, TRUE, TRUE);
            if ($aerr !== '') return $aerr;
            $serr = $this->_sums($kind, $t, $n, $h['amount_cents'] + $h['withholding_cents']);
            if ($serr !== '') return $serr;

            $c  = $this->contacts->find($h['contact_id']);
            $no = $this->arap->number($kind);
            list($head, $jl) = $this->_journal($kind, $h, $c, $no, $t);
            list($jid, $errs) = $this->journals->post_system($head, $jl, $uid, $kind, (int) $id);
            if ( ! $jid) return reset($errs);

            $now = date('Y-m-d H:i:s');
            $this->db->where('id', (int) $id)->where('status', 'draft')->update(self::T, $h + [
                'status' => 'posted', 'settle_no' => $no, 'journal_id' => $jid, 'posted_by' => $uid, 'posted_at' => $now, 'updated_at' => $now,
            ]);
            if ($this->db->affected_rows() !== 1) return 'This ' . $kind . ' changed while it was being posted. Try again.';

            $done = $this->documents->write_allocations($t, $n, (int) $id);
            $this->_drop_plan($id);
            return log_admin_action($claims, $kind . '.post', 'settlement', (int) $id, [
                'settle_no' => $no, 'journal_id' => $jid, 'amount_cents' => $h['amount_cents'], 'withholding_cents' => $h['withholding_cents'],
                'applied' => implode(', ', array_map(function ($x) { return $x[0] . ' ' . money_major($x[1]) . ($x[2] ? ' (' . $x[2] . ')' : ''); }, $done)),
            ]) ? '' : self::AUDIT_FAIL;
        }, 'post');
        return [$err, $err === '' ? $no : NULL];
    }

    /** The journal a receipt or payment posts: [head, lines]. */
    private function _journal($kind, array $h, array $c, $no, array $targets)
    {
        $side = Arap_lib::ledger_side($kind);
        $ctl  = $this->arap->account_id_for($side === 'ar' ? 'acct_ar_control' : 'acct_ap_control');
        if ( ! $ctl) throw new DomainException('Choose the ' . ($side === 'ar' ? 'receivables' : 'payables') . ' control account in Settings → Account defaults first.');
        $wh = (int) $h['withholding_cents'];
        $wacct = 0;
        if ($wh > 0) {
            $wacct = $this->arap->account_id_for($kind === 'receipt' ? 'acct_cwt_receivable' : 'acct_ewt_payable');
            if ( ! $wacct) throw new DomainException('Choose the ' . ($kind === 'receipt' ? 'creditable withholding tax' : 'expanded withholding tax payable') . ' account in Settings → Account defaults first.');
        }
        $amount = (int) $h['amount_cents'];
        $cid    = (int) $c['id'];
        $ref    = $h['reference'];
        $nos    = array_values(array_unique(array_map(function ($x) { return $x[0]['doc_no']; }, $targets)));
        $memo   = mb_substr($no . ($nos ? ': ' . implode(', ', $nos) : ''), 0, 255);

        $jl = [];
        if ($kind === 'receipt') {
            $jl[] = ['account_id' => $h['cash_account_id'], 'debit_cents' => $amount, 'credit_cents' => 0, 'memo' => $ref ?: $no];
            if ($wh > 0) $jl[] = ['account_id' => $wacct, 'debit_cents' => $wh, 'credit_cents' => 0, 'memo' => 'Creditable withholding tax'];
            $jl[] = ['account_id' => $ctl, 'debit_cents' => 0, 'credit_cents' => $amount + $wh, 'memo' => $memo, 'contact_id' => $cid];
            $head = ['book' => 'cash_receipts', 'description' => 'Receipt ' . $no . ' — ' . $c['name']];
        } else {
            $jl[] = ['account_id' => $ctl, 'debit_cents' => $amount + $wh, 'credit_cents' => 0, 'memo' => $memo, 'contact_id' => $cid];
            $jl[] = ['account_id' => $h['cash_account_id'], 'debit_cents' => 0, 'credit_cents' => $amount, 'memo' => $ref ?: $no];
            if ($wh > 0) $jl[] = ['account_id' => $wacct, 'debit_cents' => 0, 'credit_cents' => $wh, 'memo' => 'Expanded withholding tax'];
            $head = ['book' => 'cash_disbursements', 'description' => 'Payment ' . $no . ' — ' . $c['name']];
        }
        $head += ['entry_date' => $h['settle_date'], 'reference' => $ref ?: $no, 'party_name' => mb_substr($c['name'], 0, 160)];
        $head['description'] = mb_substr($head['description'] . ($h['description'] ? ' · ' . $h['description'] : ''), 0, 500);
        return [$head, $jl];
    }

    /**
     * Cancel a posted receipt or payment: refused while any line of its journal
     * is matched to a bank statement line; otherwise its allocations are
     * removed (the invoices or bills open again), its journal is reversed and it
     * is marked cancelled — together.
     * @return string error
     */
    public function cancel($id, $date, $reason, array $claims)
    {
        $reason = clean_line($reason, 400);
        if ($reason === '') return 'Say why it is cancelled.';
        if (mb_strlen($reason) > 300) return 'Keep the reason under 300 characters.';

        return $this->_tx(function () use ($id, $date, $reason, $claims) {
            $s = $this->_lock($id);
            if ( ! $s) return 'That receipt or payment does not exist.';
            $kind = $s['kind'];
            if ($s['status'] === 'draft') return 'This ' . $kind . ' is still a draft: delete it instead.';
            if ($s['status'] !== 'posted') return 'This ' . $kind . ' is already ' . $s['status'] . '.';
            if ( ! $s['journal_id']) return 'This ' . $kind . ' has no journal to reverse.';

            $matched = (int) $this->db->query('SELECT COUNT(*) AS n FROM gp_bank_matches m JOIN gp_journal_lines l ON l.id = m.journal_line_id WHERE l.journal_id = ?',
                                              [(int) $s['journal_id']])->row()->n;
            if ($matched > 0) return 'This ' . $kind . ' is matched to a bank statement line. Unmatch it in Banking first.';

            $allocs = $this->db->query('SELECT * FROM gp_allocations WHERE settlement_id = ? ORDER BY id FOR UPDATE', [(int) $id])->result_array();
            $removed = [];
            if ($allocs) {
                $ids = array_values(array_unique(array_map(function ($a) { return (int) $a['document_id']; }, $allocs)));
                sort($ids);
                $docs = [];
                foreach ($this->db->query('SELECT id, doc_no FROM gp_documents WHERE id IN (' . implode(',', $ids) . ') ORDER BY id FOR UPDATE')->result_array() as $d) $docs[(int) $d['id']] = $d['doc_no'];
                foreach ($allocs as $a) {
                    $amt = (int) $a['amount_cents'];
                    $this->db->where('id', (int) $a['id'])->delete('gp_allocations');
                    $this->db->set('applied_cents', 'applied_cents - ' . $amt, FALSE)->where('id', (int) $a['document_id'])->where('applied_cents >=', $amt)->update('gp_documents');
                    if ($this->db->affected_rows() !== 1) return 'A document changed while this ' . $kind . ' was being cancelled. Reload and try again.';
                    $removed[] = ($docs[(int) $a['document_id']] ?? '#' . $a['document_id']) . ' ' . money_major($amt);
                }
            }

            list($rid, $rerr) = $this->journals->reverse_system((int) $s['journal_id'], (int) $claims['user_id'], $date, $reason, $kind);
            if ( ! $rid) return $rerr;

            $this->db->where('id', (int) $id)->where('status', 'posted')->update(self::T, ['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')]);
            if ($this->db->affected_rows() !== 1) return 'This ' . $kind . ' changed while it was being cancelled. Try again.';
            $r = $this->journals->find($rid);
            return log_admin_action($claims, $kind . '.cancel', 'settlement', (int) $id, [
                'settle_no' => $s['settle_no'], 'reversal_id' => $rid, 'reversal_no' => $r['journal_no'], 'date' => $date, 'reason' => $reason,
                'allocations_removed' => implode(', ', $removed),
            ]) ? '' : self::AUDIT_FAIL;
        }, 'cancel');
    }

    /**
     * Apply what a posted receipt or payment has not yet settled (bookkeeper
     * and above). No journal: the control account already has it.
     * @return string error
     */
    public function apply($id, array $allocs, array $claims)
    {
        return $this->_tx(function () use ($id, $allocs, $claims) {
            $s = $this->_lock($id);
            if ( ! $s) return 'That receipt or payment does not exist.';
            $kind = $s['kind'];
            if ($s['status'] !== 'posted') return 'Only a posted ' . $kind . ' can be applied. Change the draft instead.';
            $side = Arap_lib::ledger_side($kind);
            list($t, $n, $err) = $this->documents->check_allocations($side, (int) $s['contact_id'], $allocs, TRUE, TRUE);
            if ($err !== '') return $err;
            if ( ! $t) return 'Enter how much to apply to at least one ' . ($kind === 'receipt' ? 'invoice' : 'bill') . '.';
            $left = (int) $s['amount_cents'] + (int) $s['withholding_cents'] - $this->applied($id);
            $err = $this->_sums($kind, $t, $n, $left);
            if ($err !== '') return str_replace('this ' . $kind . ' and the tax withheld', 'what is left of this ' . $kind, $err);
            $done = $this->documents->write_allocations($t, $n, (int) $id);
            return log_admin_action($claims, $kind . '.apply', 'settlement', (int) $id, [
                'settle_no' => $s['settle_no'],
                'applied' => implode(', ', array_map(function ($x) { return $x[0] . ' ' . money_major($x[1]) . ($x[2] ? ' (' . $x[2] . ')' : ''); }, $done)),
            ]) ? '' : self::AUDIT_FAIL;
        }, 'apply');
    }

    // =========================================================================
    // ONE RECEIPT OR PAYMENT
    // =========================================================================

    public function permissions(array $s, array $claims, $unapplied)
    {
        $rank  = role_rank($claims['role']);
        $mine  = (int) $s['created_by'] === (int) $claims['user_id'];
        $draft = $s['status'] === 'draft';
        $posted = $s['status'] === 'posted';
        return [
            'edit'   => $rank >= 2 && $draft && $mine,
            'delete' => $rank >= 2 && $draft && ($mine || $rank >= 3),
            'post'   => $rank >= 3 && $draft && ( ! $mine || $this->journals->may_self_approve()),
            'cancel' => $rank >= 3 && $posted,
            'apply'  => $rank >= 2 && $posted && $unapplied > 0,
            'remove_allocation' => $rank >= 3 && $posted,
        ];
    }

    public function detail($id, array $claims)
    {
        $row = $this->find($id);
        if ( ! $row) return NULL;
        $applied = $this->applied($id);
        $s = $this->shape($row + ['applied_cents' => $applied]);

        $names = [];
        $uids = array_values(array_unique(array_filter([(int) $row['created_by'], (int) $row['posted_by']])));
        if ($uids) foreach ($this->db->select('id, full_name, username')->where_in('id', $uids)->get('gp_users')->result_array() as $u) $names[(int) $u['id']] = $u['full_name'] ?: $u['username'];
        $s['created_by_name'] = $names[(int) $row['created_by']] ?? NULL;
        $s['posted_by_name']  = $row['posted_by'] ? ($names[(int) $row['posted_by']] ?? NULL) : NULL;

        $cash = $this->db->select('id, code, name')->get_where('gp_accounts', ['id' => $s['cash_account_id']], 1)->row_array();
        $bank = $this->db->select('bank_name, account_last4')->get_where('gp_bank_accounts', ['account_id' => $s['cash_account_id']], 1)->row_array();

        $allocations = array_map(function ($a) {
            return ['id' => (int) $a['id'], 'document_id' => (int) $a['document_id'], 'doc_type' => $a['doc_type'], 'type_label' => Arap_lib::TYPE_LABELS[$a['doc_type']],
                    'doc_no' => $a['doc_no'], 'doc_date' => $a['doc_date'], 'due_date' => $a['due_date'], 'total_cents' => (int) $a['total_cents'],
                    'amount_cents' => (int) $a['amount_cents'], 'created_at' => $a['created_at']];
        }, $this->db->query('SELECT a.*, d.doc_type, d.doc_no, d.doc_date, d.due_date, d.total_cents FROM gp_allocations a JOIN gp_documents d ON d.id = a.document_id
                              WHERE a.settlement_id = ? ORDER BY d.doc_date, a.id', [(int) $id])->result_array());

        $plan = [];
        if ($s['status'] === 'draft') {
            $p = $this->plan($id);
            $by = [];
            $ids = array_values(array_filter(array_map(function ($x) { return (int) ($x['document_id'] ?? 0); }, $p)));
            if ($ids) foreach ($this->db->where_in('id', $ids)->get('gp_documents')->result_array() as $d) $by[(int) $d['id']] = $d;
            foreach ($p as $x) {
                $d = $by[(int) ($x['document_id'] ?? 0)] ?? NULL;
                if ( ! $d) continue;
                $plan[] = ['document_id' => (int) $d['id'], 'doc_type' => $d['doc_type'], 'type_label' => Arap_lib::TYPE_LABELS[$d['doc_type']], 'doc_no' => $d['doc_no'],
                           'doc_date' => $d['doc_date'], 'due_date' => $d['due_date'], 'total_cents' => (int) $d['total_cents'], 'net_cents' => (int) $d['net_cents'],
                           'open_cents' => $d['status'] === 'posted' ? (int) $d['total_cents'] - (int) $d['applied_cents'] : 0, 'status' => $d['status'],
                           'amount_cents' => (int) $x['amount_cents'], 'is_note' => Arap_lib::is_note($d['doc_type'])];
            }
        }

        $journal = NULL;
        $reversal = NULL;
        $matched = FALSE;
        if ($s['journal_id']) {
            $j = $this->db->select('id, journal_no, entry_date, status, reversed_by_id')->get_where('gp_journals', ['id' => $s['journal_id']], 1)->row_array();
            if ($j) {
                $journal = ['id' => (int) $j['id'], 'journal_no' => $j['journal_no'], 'entry_date' => $j['entry_date'], 'status' => $j['status']];
                if ($j['reversed_by_id']) {
                    $r = $this->db->select('id, journal_no, entry_date')->get_where('gp_journals', ['id' => (int) $j['reversed_by_id']], 1)->row_array();
                    if ($r) $reversal = ['id' => (int) $r['id'], 'journal_no' => $r['journal_no'], 'entry_date' => $r['entry_date']];
                }
                $matched = (bool) (int) $this->db->query('SELECT COUNT(*) AS n FROM gp_bank_matches m JOIN gp_journal_lines l ON l.id = m.journal_line_id WHERE l.journal_id = ?',
                                                         [(int) $j['id']])->row()->n;
            }
        }

        $trail = [];
        foreach ($this->db->query(
            'SELECT a.action, a.detail, a.occurred_at, a.admin_id, u.full_name, u.username FROM gp_admin_audit_log a LEFT JOIN gp_users u ON u.id = a.admin_id
              WHERE a.target_type = ? AND a.target_id = ? ORDER BY a.id', ['settlement', (int) $id]
        )->result_array() as $t) {
            $x = $t['detail'] !== NULL ? json_decode($t['detail'], TRUE) : NULL;
            $trail[] = ['action' => $t['action'], 'at' => $t['occurred_at'], 'who' => $t['full_name'] ?: ($t['username'] ?: '#' . (int) $t['admin_id']),
                        'detail' => is_array($x) ? $x : NULL];
        }

        return [
            'settlement'   => $s,
            'contact'      => $this->contacts->shape($this->contacts->find($s['contact_id'])),
            'cash_account' => $cash ? ['id' => (int) $cash['id'], 'code' => $cash['code'], 'name' => $cash['name'],
                                       'bank' => $bank ? trim($bank['bank_name'] . ($bank['account_last4'] ? ' ···' . $bank['account_last4'] : '')) : NULL] : NULL,
            'allocations'  => $allocations,
            'plan'         => $plan,
            'journal'      => $journal,
            'reversal'     => $reversal,
            'bank_matched' => $matched,
            'amount_in_words' => Arap_lib::amount_in_words($s['amount_cents'], shop_cfg('currency_code', 'PHP')),
            'trail'        => $trail,
            'can'          => $this->permissions($row, $claims, $s['unapplied_cents']),
            'policy'       => ['self_approve' => $this->journals->may_self_approve()],
            'today'        => company_today(),
        ];
    }

    // =========================================================================

    /** '' commits; an error message or an exception rolls everything back. */
    private function _tx(callable $fn, $what)
    {
        $this->db->trans_begin();
        try {
            $err = (string) $fn();
        } catch (DomainException $x) {
            $err = $x->getMessage();
        } catch (Throwable $t) {
            if ($t instanceof RuntimeException && ! ($t instanceof mysqli_sql_exception)) {
                $err = $t->getMessage();
            } else {
                log_message('error', '[Settlement_model] ' . $what . ': ' . $t->getMessage());
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
