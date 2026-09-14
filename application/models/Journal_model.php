<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Journal_model.php — journals: drafts, approval, posting, reversal
 *
 * GenericPOS Accounting · tables gp_journals, gp_journal_lines
 *
 * ─── LIFE OF AN ENTRY ─────────────────────────────────────────────────────
 *   draft ──submit──▶ submitted ──approve──▶ posted ──reverse──▶ (a new posted entry)
 *     ▲                   │
 *     └─────reject────────┘            draft / submitted / rejected ──cancel──▶ cancelled
 *
 * Only posted entries reach the ledger (the gp_ledger view).
 *
 * ─── WHAT POSTING GUARANTEES (PLAN.md §2) ─────────────────────────────────
 *   · It happens in ONE transaction that locks the journal and its period.
 *   · The period and the year are open AT THAT MOMENT, not merely when the
 *     draft was saved — a month closed in between refuses the posting.
 *   · Every line rule is checked again from the stored rows — balance, active
 *     accounts, control accounts naming their customer or supplier,
 *     departments — so what posts is what the database holds, under the
 *     rules in force now (the chart may have changed since the draft).
 *   · The number is taken from gp_counters inside the same transaction, so a
 *     failed posting gives its number back and the book has no gaps.
 *   · The audit row is written in the same transaction: no posting without
 *     its record, and no record of a posting that rolled back.
 *
 * ─── MAKER-CHECKER ────────────────────────────────────────────────────────
 *   · Only the preparer edits or submits their entry. A reviewer who wants a
 *     change rejects it with a reason; they never rewrite it themselves.
 *   · Only a SUBMITTED entry is approved, and never by its preparer — unless
 *     approval is switched off or ledger_allow_self_approval is on (a
 *     one-person office).
 *
 * ─── SYSTEM ENTRIES ───────────────────────────────────────────────────────
 * post_system() validates and posts in one step. It is how the modules write
 * (invoices, bills, receipts, payments, depreciation, closing, opening
 * balances), and the only way into the closing and opening books. Those
 * entries are undone from their own module, never reversed from here, so a
 * document and its journal cannot drift apart.
 *
 * NOTE: A CALLER'S OWN TRANSACTION. CodeIgniter nests transactions by depth
 * and only the outermost commit or rollback reaches the database. When a
 * module calls in here inside its own transaction and the posting fails, the
 * inner rollback would do nothing — so instead of returning the error this
 * model THROWS, and the module's transaction must roll back.
 */
class Journal_model extends CI_Model
{
    const T = 'gp_journals';
    const L = 'gp_journal_lines';

    const BOOKS = ['general', 'cash_receipts', 'cash_disbursements', 'sales', 'purchases', 'adjusting', 'closing', 'opening'];

    /** Books a person may choose. Closing and opening entries are written by the system. */
    const MANUAL_BOOKS = ['general', 'cash_receipts', 'cash_disbursements', 'sales', 'purchases', 'adjusting'];

    const BOOK_LABELS = [
        'general'            => 'General journal',
        'cash_receipts'      => 'Cash receipts',
        'cash_disbursements' => 'Cash disbursements',
        'sales'              => 'Sales journal',
        'purchases'          => 'Purchase journal',
        'adjusting'          => 'Adjusting entries',
        'closing'            => 'Closing entries',
        'opening'            => 'Opening balances',
    ];

    const STATUSES = ['draft', 'submitted', 'posted', 'rejected', 'cancelled'];

    /** Where an entry came from. Anything but 'manual' is undone from its own module. */
    const SOURCE_LABELS = [
        'manual'       => 'a journal entry',
        'reversal'     => 'a reversal',
        'invoice'      => 'a customer invoice',
        'credit_note'  => 'a credit note',
        'bill'         => 'a supplier bill',
        'debit_note'   => 'a debit note',
        'receipt'      => 'a customer receipt',
        'payment'      => 'a supplier payment',
        'depreciation' => 'a depreciation run',
        'disposal'     => 'an asset disposal',
        'bank'         => 'a bank reconciliation',
        'closing'      => 'the year-end closing',
        'opening'      => 'the opening balances',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('Account_model', 'accounts');
        $this->load->model('Period_model', 'periods');
    }

    // =========================================================================
    // READ
    // =========================================================================

    public function find($id)
    {
        return $this->db->get_where(self::T, ['id' => (int) $id], 1)->row_array() ?: NULL;
    }

    public function find_by_no($journal_no)
    {
        return $this->db->get_where(self::T, ['journal_no' => (string) $journal_no], 1)->row_array() ?: NULL;
    }

    public function lines($journal_id)
    {
        return $this->db->query(
            'SELECT l.*, a.code AS account_code, a.name AS account_name, a.control AS account_control,
                    d.code AS department_code, d.name AS department_name, c.code AS contact_code, c.name AS contact_name
               FROM gp_journal_lines l
               JOIN gp_accounts a ON a.id = l.account_id
          LEFT JOIN gp_departments d ON d.id = l.department_id
          LEFT JOIN gp_contacts c ON c.id = l.contact_id
              WHERE l.journal_id = ?
              ORDER BY l.line_no',
            [(int) $journal_id]
        )->result_array();
    }

    /**
     * A page of journals for the list screen.
     *
     * @param array $f  status (one status, or 'waiting' = draft|submitted|rejected), book, from, to,
     *                  q, created_by, account_id, period_id
     * @return array [rows, total]
     */
    public function search(array $f, $limit = 25, $offset = 0)
    {
        list($where, $args) = $this->_filters($f);
        $total = (int) $this->db->query('SELECT COUNT(*) AS n FROM gp_journals j WHERE ' . $where, $args)->row()->n;
        $rows  = $this->db->query(
            'SELECT j.id, j.book, j.journal_no, j.entry_date, j.reference, j.party_name, j.description, j.total_cents,
                    j.status, j.source, j.reversal_of_id, j.reversed_by_id, j.created_by, j.created_at, j.submitted_at,
                    j.approved_at, j.reject_reason, u.full_name AS created_by_name, u.username AS created_by_username
               FROM gp_journals j LEFT JOIN gp_users u ON u.id = j.created_by
              WHERE ' . $where . '
              ORDER BY j.entry_date DESC, j.id DESC
              LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
            $args
        )->result_array();
        return [$rows, $total];
    }

    /** How many journals each status holds under the same filters (the list's tabs). */
    public function status_counts(array $f = [])
    {
        unset($f['status']);
        list($where, $args) = $this->_filters($f);
        $out = array_fill_keys(self::STATUSES, 0);
        foreach ($this->db->query('SELECT j.status, COUNT(*) AS n FROM gp_journals j WHERE ' . $where . ' GROUP BY j.status', $args)->result_array() as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        $out['waiting'] = $out['draft'] + $out['submitted'] + $out['rejected'];
        $out['all']     = array_sum(array_intersect_key($out, array_flip(self::STATUSES)));
        return $out;
    }

    private function _filters(array $f)
    {
        $w = ['1 = 1'];
        $a = [];

        $st = (string) ($f['status'] ?? '');
        if ($st === 'waiting')                       $w[] = "j.status IN ('draft', 'submitted', 'rejected')";
        elseif (in_array($st, self::STATUSES, TRUE)) { $w[] = 'j.status = ?'; $a[] = $st; }

        $book = (string) ($f['book'] ?? '');
        if (in_array($book, self::BOOKS, TRUE)) { $w[] = 'j.book = ?'; $a[] = $book; }

        if ( ! empty($f['from']) && Period_model::valid_date($f['from'])) { $w[] = 'j.entry_date >= ?'; $a[] = $f['from']; }
        if ( ! empty($f['to'])   && Period_model::valid_date($f['to']))   { $w[] = 'j.entry_date <= ?'; $a[] = $f['to']; }
        if ( ! empty($f['created_by'])) { $w[] = 'j.created_by = ?'; $a[] = (int) $f['created_by']; }
        if ( ! empty($f['period_id']))  { $w[] = 'j.period_id = ?';  $a[] = (int) $f['period_id']; }
        if ( ! empty($f['account_id'])) {
            $w[] = 'EXISTS (SELECT 1 FROM gp_journal_lines l WHERE l.journal_id = j.id AND l.account_id = ?)';
            $a[] = (int) $f['account_id'];
        }

        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 80)) . '%';
            $w[] = '(j.journal_no LIKE ? OR j.reference LIKE ? OR j.party_name LIKE ? OR j.description LIKE ?)';
            array_push($a, $like, $like, $like, $like);
        }
        return [implode(' AND ', $w), $a];
    }

    /** One journal with its lines, the people behind it, its links and its audit trail. */
    public function detail($id)
    {
        $j = $this->find($id);
        if ( ! $j) return NULL;

        $names = $this->_names([$j['created_by'], $j['submitted_by'], $j['approved_by'], $j['rejected_by'], $j['cancelled_by']]);
        $who   = function ($uid) use ($names) { return $uid ? ($names[(int) $uid] ?? '#' . (int) $uid) : NULL; };
        $link  = function ($jid) {
            if ( ! $jid) return NULL;
            $r = $this->db->select('id, journal_no, entry_date, status')->get_where(self::T, ['id' => (int) $jid], 1)->row_array();
            return $r ? ['id' => (int) $r['id'], 'journal_no' => $r['journal_no'], 'entry_date' => $r['entry_date'], 'status' => $r['status']] : NULL;
        };

        $period = $this->db->query(
            'SELECT p.name, p.status, fy.name AS fiscal_year, fy.status AS fiscal_year_status
               FROM gp_periods p JOIN gp_fiscal_years fy ON fy.id = p.fiscal_year_id WHERE p.id = ?',
            [(int) $j['period_id']]
        )->row_array();

        $trail = [];
        foreach ($this->db->query(
            'SELECT a.action, a.detail, a.occurred_at, a.admin_id, u.full_name, u.username
               FROM gp_admin_audit_log a LEFT JOIN gp_users u ON u.id = a.admin_id
              WHERE a.target_type = ? AND a.target_id = ? ORDER BY a.id',
            ['journal', (int) $id]
        )->result_array() as $r) {
            $d = $r['detail'] !== NULL ? json_decode($r['detail'], TRUE) : NULL;
            $trail[] = ['action' => $r['action'], 'at' => $r['occurred_at'],
                        'who' => $r['full_name'] ?: ($r['username'] ?: '#' . (int) $r['admin_id']),
                        'detail' => is_array($d) ? $d : NULL];
        }

        $lines = array_map(function ($l) {
            return [
                'line_no'         => (int) $l['line_no'],
                'account_id'      => (int) $l['account_id'],
                'account_code'    => $l['account_code'],
                'account_name'    => $l['account_name'],
                'account_control' => $l['account_control'],
                'debit_cents'     => (int) $l['debit_cents'],
                'credit_cents'    => (int) $l['credit_cents'],
                'memo'            => $l['memo'],
                'department_id'   => $l['department_id'] !== NULL ? (int) $l['department_id'] : NULL,
                'department'      => $l['department_code'] !== NULL ? $l['department_code'] . ' · ' . $l['department_name'] : NULL,
                'contact_id'      => $l['contact_id'] !== NULL ? (int) $l['contact_id'] : NULL,
                'contact'         => $l['contact_code'] !== NULL ? $l['contact_code'] . ' · ' . $l['contact_name'] : NULL,
            ];
        }, $this->lines($id));

        return [
            'journal' => $this->shape($j) + [
                'created_by_name'   => $who($j['created_by']),
                'submitted_by_name' => $who($j['submitted_by']),
                'approved_by_name'  => $who($j['approved_by']),
                'rejected_by_name'  => $who($j['rejected_by']),
                'cancelled_by_name' => $who($j['cancelled_by']),
            ],
            'lines'       => $lines,
            'period'      => $period ?: NULL,
            'reversal_of' => $link($j['reversal_of_id']),
            'reversed_by' => $link($j['reversed_by_id']),
            'trail'       => $trail,
        ];
    }

    /** A journal row with its ids as integers and its book and source spelled out. */
    public function shape(array $j)
    {
        foreach (['id', 'fiscal_year_id', 'period_id', 'total_cents', 'created_by'] as $k) {
            if (array_key_exists($k, $j)) $j[$k] = (int) $j[$k];
        }
        foreach (['source_id', 'reversal_of_id', 'reversed_by_id', 'submitted_by', 'approved_by', 'rejected_by', 'cancelled_by'] as $k) {
            if (array_key_exists($k, $j)) $j[$k] = $j[$k] !== NULL ? (int) $j[$k] : NULL;
        }
        $j['book_label']   = self::BOOK_LABELS[$j['book']] ?? $j['book'];
        $j['source_label'] = self::SOURCE_LABELS[$j['source']] ?? $j['source'];
        return $j;
    }

    /**
     * What a user may do with a journal right now. The screen shows exactly
     * these actions; every one is checked again when it is attempted.
     */
    public function permissions(array $j, $user_id, $role)
    {
        $rank    = role_rank($role);
        $mine    = (int) $j['created_by'] === (int) $user_id;
        $st      = $j['status'];
        $pending = in_array($st, ['draft', 'rejected'], TRUE);
        return [
            'edit'    => $rank >= 2 && $mine && $pending,
            'submit'  => $rank >= 2 && $mine && $pending,
            'approve' => $rank >= 3 && $st === 'submitted' && ( ! $mine || $this->may_self_approve()),
            'reject'  => $rank >= 3 && $st === 'submitted',
            'cancel'  => $rank >= 2 && in_array($st, ['draft', 'submitted', 'rejected'], TRUE) && ($mine || $rank >= 3),
            'reverse' => $rank >= 3 && $st === 'posted' && empty($j['reversed_by_id']) && empty($j['reversal_of_id'])
                         && $j['source'] === 'manual' && in_array($j['book'], self::MANUAL_BOOKS, TRUE),
            'copy'    => $rank >= 2 && in_array($j['book'], self::MANUAL_BOOKS, TRUE),
        ];
    }

    /** May a preparer approve their own entry? (Approval off, or a one-person office.) */
    public function may_self_approve()
    {
        return ! shop_bool('ledger_require_approval', TRUE) || shop_bool('ledger_allow_self_approval', FALSE);
    }

    // =========================================================================
    // VALIDATION
    // =========================================================================

    /**
     * Check a header and its lines. Amounts are integer centavos.
     *
     * @param  array $head   book, entry_date, reference, party_name, description
     * @param  array $lines  [account_id, debit_cents, credit_cents, memo, department_id, contact_id]
     * @param  bool  $system the closing and opening books are allowed
     * @return array [head, lines, errors, total_cents, period]
     */
    public function validate(array $head, array $lines, $system = FALSE)
    {
        $e = [];

        $book = (string) ($head['book'] ?? 'general');
        if ( ! in_array($book, $system ? self::BOOKS : self::MANUAL_BOOKS, TRUE)) $e['book'] = 'Choose a book.';

        $date = trim((string) ($head['entry_date'] ?? ''));
        list($period, $perr) = $this->periods->open_period_for($date);
        if ($perr !== '') $e['entry_date'] = $perr;

        $desc = trim(preg_replace('/\s+/u', ' ', (string) ($head['description'] ?? '')));
        if ($desc === '')                 $e['description'] = 'Describe the entry.';
        elseif (mb_strlen($desc) > 500)   $e['description'] = 'Keep the description under 500 characters.';

        $ref = trim((string) ($head['reference'] ?? ''));
        if (mb_strlen($ref) > 60)         $e['reference'] = 'Keep the reference under 60 characters.';

        $party = trim(preg_replace('/\s+/u', ' ', (string) ($head['party_name'] ?? '')));
        if (mb_strlen($party) > 160)      $e['party_name'] = 'Keep the name under 160 characters.';

        $lines = array_values($lines);
        $max   = (int) ($this->config->item('ledger_max_lines') ?: 500);
        if (count($lines) < 2)            $e['lines'] = 'An entry needs at least two lines.';
        elseif (count($lines) > $max)     $e['lines'] = 'An entry can have at most ' . $max . ' lines.';

        $accts    = $this->accounts->by_ids(array_column($lines, 'account_id'));
        $depts    = $this->_rows_by_id('gp_departments', array_column($lines, 'department_id'));
        $contacts = $this->_rows_by_id('gp_contacts', array_column($lines, 'contact_id'));

        $clean = [];
        $bad   = [];
        $dr = 0;
        $cr = 0;
        foreach ($lines as $i => $l) {
            $n    = $i + 1;
            $aid  = (int) ($l['account_id'] ?? 0);
            $d    = self::_cents($l['debit_cents'] ?? 0);
            $c    = self::_cents($l['credit_cents'] ?? 0);
            $did  = (int) ($l['department_id'] ?? 0);
            $cid  = (int) ($l['contact_id'] ?? 0);
            $memo = trim(preg_replace('/\s+/u', ' ', (string) ($l['memo'] ?? '')));
            $a    = $accts[$aid] ?? NULL;

            $msg = '';
            if ( ! $a)                                     $msg = 'choose an account';
            elseif ((int) $a['is_header'])                 $msg = $a['code'] . ' is a header account';
            elseif ( ! (int) $a['is_active'])              $msg = $a['code'] . ' is inactive';
            elseif ($d === NULL || $c === NULL)            $msg = 'amounts must be whole centavos';
            elseif (($d > 0) === ($c > 0))                 $msg = 'enter either a debit or a credit';
            elseif (mb_strlen($memo) > 255)                $msg = 'the memo is too long';
            elseif ($did && ( ! isset($depts[$did]) || ! (int) $depts[$did]['is_active'])) $msg = 'choose an active department';
            elseif ( ! $did && (int) $a['requires_department']) $msg = $a['code'] . ' needs a department';
            elseif ($cid && ! isset($contacts[$cid]))      $msg = 'that customer or supplier does not exist';
            elseif ($cid && ! (int) $contacts[$cid]['is_active']) $msg = $contacts[$cid]['name'] . ' is inactive';
            elseif ($a['control'] === 'ar' && ( ! $cid || ! (int) $contacts[$cid]['is_customer'])) $msg = $a['code'] . ' needs a customer';
            elseif ($a['control'] === 'ap' && ( ! $cid || ! (int) $contacts[$cid]['is_supplier'])) $msg = $a['code'] . ' needs a supplier';

            if ($msg !== '') { $bad[] = 'Line ' . $n . ': ' . $msg . '.'; continue; }

            $dr += $d;
            $cr += $c;
            $clean[] = [
                'account_id'    => $aid,
                'debit_cents'   => $d,
                'credit_cents'  => $c,
                'memo'          => $memo !== '' ? $memo : NULL,
                'department_id' => $did ?: NULL,
                'contact_id'    => $cid ?: NULL,
            ];
        }

        if ($bad) {
            $e['lines'] = implode(' ', array_slice($bad, 0, 5)) . (count($bad) > 5 ? ' (and ' . (count($bad) - 5) . ' more)' : '');
        } elseif ( ! isset($e['lines'])) {
            if ($dr !== $cr)  $e['lines'] = 'Debits (' . money_format_cents($dr) . ') and credits (' . money_format_cents($cr) . ') do not balance.';
            elseif ($dr <= 0) $e['lines'] = 'The entry has no amount.';
        }

        $h = [
            'book'        => $book,
            'entry_date'  => $date,
            'reference'   => $ref !== '' ? $ref : NULL,
            'party_name'  => $party !== '' ? $party : NULL,
            'description' => $desc,
        ];
        return [$h, $clean, $e, $dr, $period];
    }

    /** A whole number of centavos from an int or a digit string; NULL otherwise. */
    private static function _cents($v)
    {
        if (is_int($v)) return $v >= 0 ? $v : NULL;
        if (is_string($v) && preg_match('/^\d{1,15}$/', trim($v))) return (int) trim($v);
        if ($v === NULL || $v === '') return 0;
        return NULL;
    }

    private function _rows_by_id($table, array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ( ! $ids) return [];
        $out = [];
        foreach ($this->db->where_in('id', $ids)->get($table)->result_array() as $r) $out[(int) $r['id']] = $r;
        return $out;
    }

    // =========================================================================
    // DRAFTS
    // =========================================================================

    /** @return array [id, errors] */
    public function create_draft(array $head, array $lines, $user_id, $source = 'manual', $source_id = NULL)
    {
        list($h, $clean, $e, $total, $period) = $this->validate($head, $lines, FALSE);
        if ($e) return [0, $e];

        $id  = 0;
        $err = $this->_in_transaction(function () use ($h, $clean, $total, $period, $user_id, $source, $source_id, &$id) {
            $id = $this->_insert($h, $clean, $total, $period, $user_id, 'draft', $source, $source_id);
            return $this->_audit($user_id, 'journal.create', $id, ['book' => $h['book'], 'total_cents' => $total]);
        });
        return $err === '' ? [$id, []] : [0, ['_' => $err]];
    }

    /**
     * Replace a draft's (or a rejected entry's) header and lines. Only its
     * preparer may: a reviewer rejects with a reason instead of rewriting.
     *
     * @return array errors
     */
    public function update_draft($id, array $head, array $lines, $user_id)
    {
        list($h, $clean, $e, $total, $period) = $this->validate($head, $lines, FALSE);
        if ($e) return $e;

        $err = $this->_in_transaction(function () use ($id, $h, $clean, $total, $period, $user_id) {
            /* Locked, and the status checked under the lock: an approval that
               lands between reading and writing can never be undone by an edit. */
            $j = $this->_lock($id);
            if ( ! $j) return 'No such entry.';
            if ( ! in_array($j['status'], ['draft', 'rejected'], TRUE)) return 'Only a draft or a rejected entry can be edited; this one is ' . $j['status'] . '.';
            if ((int) $j['created_by'] !== (int) $user_id) return 'Only the person who prepared this entry can change it. Reject it with a reason instead.';

            $this->db->where('journal_id', (int) $id)->delete(self::L);
            $this->db->where('id', (int) $id)->where_in('status', ['draft', 'rejected'])->update(self::T, $h + [
                'fiscal_year_id' => (int) $period['fiscal_year_id'],
                'period_id'      => (int) $period['id'],
                'total_cents'    => $total,
                'status'         => 'draft',
                'rejected_by'    => NULL, 'rejected_at' => NULL, 'reject_reason' => NULL,
                'updated_at'     => date('Y-m-d H:i:s'),
            ]);
            $this->_insert_lines($id, $clean);
            return $this->_audit($user_id, 'journal.update', $id, ['total_cents' => $total]);
        });
        return $err === '' ? [] : ['_' => $err];
    }

    /** draft | rejected → submitted, by its preparer, after checking it still validates. @return string error */
    public function submit($id, $user_id)
    {
        $j = $this->find($id);
        if ( ! $j) return 'No such entry.';
        if ( ! in_array($j['status'], ['draft', 'rejected'], TRUE)) return 'This entry is already ' . $j['status'] . '.';
        if ((int) $j['created_by'] !== (int) $user_id) return 'Only the person who prepared this entry can submit it.';

        list(, , $e) = $this->validate($this->_stored_head($j), $this->_stored_lines($id), FALSE);
        if ($e) return reset($e);

        $err = $this->_in_transaction(function () use ($id, $user_id) {
            $now = date('Y-m-d H:i:s');
            $this->db->where('id', (int) $id)->where_in('status', ['draft', 'rejected'])->update(self::T, [
                'status' => 'submitted', 'submitted_by' => (int) $user_id, 'submitted_at' => $now, 'updated_at' => $now,
            ]);
            if ($this->db->affected_rows() !== 1) return 'The entry changed while you were looking at it.';
            return $this->_audit($user_id, 'journal.submit', $id);
        });
        if ($err === '') $this->_notify_approvers($id, $user_id);
        return $err;
    }

    /** submitted → rejected, with the reason the preparer will read. @return string error */
    public function reject($id, $user_id, $reason)
    {
        $reason = trim(preg_replace('/\s+/u', ' ', (string) $reason));
        if ($reason === '')              return 'Say why the entry is rejected.';
        if (mb_strlen($reason) > 300)    return 'Keep the reason under 300 characters.';

        $err = $this->_in_transaction(function () use ($id, $user_id, $reason) {
            $now = date('Y-m-d H:i:s');
            $this->db->where('id', (int) $id)->where('status', 'submitted')->update(self::T, [
                'status' => 'rejected', 'rejected_by' => (int) $user_id, 'rejected_at' => $now,
                'reject_reason' => $reason, 'updated_at' => $now,
            ]);
            if ($this->db->affected_rows() !== 1) return 'Only a submitted entry can be rejected.';
            return $this->_audit($user_id, 'journal.reject', $id, ['reason' => $reason]);
        });
        if ($err === '') {
            $this->_notify_preparer($id, $user_id, 'journal.rejected', 'Entry returned to you',
                $this->_name($user_id) . ' rejected it: ' . $reason, TRUE);
        }
        return $err;
    }

    /** Anything not yet posted → cancelled. It keeps its lines, for the record. @return string error */
    public function cancel($id, $user_id)
    {
        return $this->_in_transaction(function () use ($id, $user_id) {
            $now = date('Y-m-d H:i:s');
            $this->db->where('id', (int) $id)->where_in('status', ['draft', 'submitted', 'rejected'])->update(self::T, [
                'status' => 'cancelled', 'cancelled_by' => (int) $user_id, 'cancelled_at' => $now, 'updated_at' => $now,
            ]);
            if ($this->db->affected_rows() !== 1) return 'Only an entry that has not posted can be cancelled.';
            return $this->_audit($user_id, 'journal.cancel', $id);
        });
    }

    // =========================================================================
    // POSTING
    // =========================================================================

    /** Approve and post a submitted entry. @return string error, '' when posted */
    public function approve($id, $user_id)
    {
        $j = $this->find($id);
        if ( ! $j) return 'No such entry.';
        if (in_array($j['status'], ['draft', 'rejected'], TRUE)) return 'Submit the entry for approval first.';
        if ($j['status'] !== 'submitted') return 'This entry is already ' . $j['status'] . '.';
        if ((int) $j['created_by'] === (int) $user_id && ! $this->may_self_approve()) {
            return 'You prepared this entry, so another accountant must approve it.';
        }

        $err = $this->_in_transaction(function () use ($id, $user_id) {
            $e = $this->_post_locked($id, $user_id);
            if ($e !== '') return $e;
            $n = $this->find($id);
            return $this->_audit($user_id, 'journal.post', $id, ['journal_no' => $n['journal_no'], 'total_cents' => (int) $n['total_cents']]);
        });
        if ($err === '') {
            $n = $this->find($id);
            $this->_notify_preparer($id, $user_id, 'journal.posted', 'Entry posted',
                '“' . mb_substr($n['description'], 0, 120) . '” was approved and posted as ' . $n['journal_no'] . '.', FALSE);
        }
        return $err;
    }

    /**
     * Validate, insert and post in one transaction — the modules' way in, and
     * the only way into the closing and opening books.
     *
     * @return array [id, errors]
     */
    public function post_system(array $head, array $lines, $user_id, $source, $source_id = NULL)
    {
        list($h, $clean, $e, $total, $period) = $this->validate($head, $lines, TRUE);
        if ($e) return [0, $e];

        $id  = 0;
        $err = $this->_in_transaction(function () use ($h, $clean, $total, $period, $user_id, $source, $source_id, &$id) {
            $id = $this->_insert($h, $clean, $total, $period, $user_id, 'submitted', $source, $source_id);
            $e = $this->_post_locked($id, $user_id);
            if ($e !== '') return $e;
            $n = $this->find($id);
            return $this->_audit($user_id, 'journal.post', $id, ['journal_no' => $n['journal_no'], 'source' => $source, 'total_cents' => (int) $n['total_cents']]);
        });
        if ($err !== '') return [0, ['_' => $err]];
        return [$id, []];
    }

    /**
     * Reverse a posted entry: a new posted entry with every side swapped, dated
     * $date, linked both ways. A journal is reversed at most once, a reversal is
     * never itself reversed, and entries a module posted (an invoice, a
     * payment, a depreciation run…) are undone from that module, so its
     * document and the ledger stay in step.
     *
     * @return array [new id, error]
     */
    public function reverse($id, $user_id, $date, $reason)
    {
        $j = $this->find($id);
        $reason = trim(preg_replace('/\s+/u', ' ', (string) $reason));
        $date   = trim((string) $date);
        if ( ! $j)                                   return [0, 'No such entry.'];
        if ($j['status'] !== 'posted')               return [0, 'Only a posted entry can be reversed.'];
        if ($j['reversed_by_id'])                    return [0, 'This entry has already been reversed.'];
        if ($j['reversal_of_id'])                    return [0, 'This entry is itself a reversal. Post a corrected entry instead.'];
        if (in_array($j['book'], ['closing', 'opening'], TRUE)) return [0, 'Closing and opening entries are undone from their own screens.'];
        if ($j['source'] !== 'manual') {
            return [0, 'This entry was posted from ' . (self::SOURCE_LABELS[$j['source']] ?? 'another module') . '. Undo it there, so the document and the ledger stay in step.'];
        }
        if ($reason === '')                          return [0, 'Say why the entry is reversed.'];
        if (mb_strlen($reason) > 300)                return [0, 'Keep the reason under 300 characters.'];
        if ( ! Period_model::valid_date($date))      return [0, 'Enter the date of the reversal.'];
        if ($date < $j['entry_date'])                return [0, 'Date the reversal on or after ' . $j['entry_date'] . ', the date of the entry it reverses.'];

        $lines = [];
        foreach ($this->_stored_lines($id) as $l) {
            $lines[] = ['debit_cents' => $l['credit_cents'], 'credit_cents' => $l['debit_cents']] + $l;
        }
        $head = [
            'book'        => $j['book'],
            'entry_date'  => $date,
            'reference'   => $j['journal_no'],
            'party_name'  => $j['party_name'],
            'description' => mb_substr('Reversal of ' . $j['journal_no'] . ': ' . $reason, 0, 500),
        ];

        list($h, $clean, $e, $total, $period) = $this->validate($head, $lines, TRUE);
        if ($e) return [0, reset($e)];

        $new = 0;
        $err = $this->_in_transaction(function () use ($h, $clean, $total, $period, $user_id, $id, $reason, &$new) {
            $new = $this->_insert($h, $clean, $total, $period, $user_id, 'submitted', 'reversal', $id, $id);
            $e = $this->_post_locked($new, $user_id);
            if ($e !== '') return $e;
            $this->db->where('id', (int) $id)->where('reversed_by_id IS NULL', NULL, FALSE)
                     ->update(self::T, ['reversed_by_id' => $new, 'updated_at' => date('Y-m-d H:i:s')]);
            if ($this->db->affected_rows() !== 1) return 'This entry was reversed a moment ago.';
            $n = $this->find($new);
            $e = $this->_audit($user_id, 'journal.post', $new, ['journal_no' => $n['journal_no'], 'source' => 'reversal', 'total_cents' => (int) $n['total_cents']]);
            if ($e !== '') return $e;
            return $this->_audit($user_id, 'journal.reverse', $id, ['reversal_id' => $new, 'reversal_no' => $n['journal_no'], 'reason' => $reason]);
        });
        if ($err !== '') return [0, $err];
        return [$new, ''];
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    /**
     * Run $fn inside a transaction. $fn returns '' to commit or an error to roll
     * back; an exception rolls back too (PHP 8's mysqli throws on SQL errors).
     *
     * Inside a caller's transaction the rollback here would change nothing
     * (CodeIgniter only acts at the outermost level), so the error is THROWN
     * instead — the caller's own transaction has to roll back.
     */
    private function _in_transaction(callable $fn)
    {
        $nested = $this->_trans_depth() > 0;
        $this->db->trans_begin();
        try {
            $err = (string) $fn();
            if ($err === '' && $this->db->trans_status() === FALSE) $err = 'The database refused the entry.';
        } catch (Throwable $t) {
            log_message('error', '[Journal_model] ' . $t->getMessage());
            $err = 'The database refused the entry.';
        }
        if ($err === '') {
            $this->db->trans_commit();
            return '';
        }
        $this->db->trans_rollback();
        if ($nested) throw new RuntimeException($err);
        return $err;
    }

    /** CodeIgniter's transaction depth (a protected counter with no getter). */
    private function _trans_depth()
    {
        $db = $this->db;
        return (int) (function () { return $this->_trans_depth; })->call($db);
    }

    /** '' when the audit row is written; otherwise the error that rolls the change back. */
    private function _audit($user_id, $action, $id, $detail = NULL)
    {
        return log_admin_action(['user_id' => (int) $user_id], $action, 'journal', (int) $id, $detail)
            ? '' : 'The audit trail could not be written, so nothing was saved.';
    }

    private function _lock($id)
    {
        return $this->db->query('SELECT * FROM gp_journals WHERE id = ? FOR UPDATE', [(int) $id])->row_array() ?: NULL;
    }

    private function _insert(array $h, array $lines, $total, array $period, $user_id, $status, $source, $source_id, $reversal_of = NULL)
    {
        $now = date('Y-m-d H:i:s');
        $this->db->insert(self::T, $h + [
            'fiscal_year_id' => (int) $period['fiscal_year_id'],
            'period_id'      => (int) $period['id'],
            'total_cents'    => (int) $total,
            'status'         => $status,
            'source'         => substr((string) $source, 0, 20),
            'source_id'      => $source_id !== NULL ? (int) $source_id : NULL,
            'reversal_of_id' => $reversal_of !== NULL ? (int) $reversal_of : NULL,
            'created_by'     => (int) $user_id,
            'submitted_by'   => $status === 'submitted' ? (int) $user_id : NULL,
            'submitted_at'   => $status === 'submitted' ? $now : NULL,
            'created_at'     => $now,
            'updated_at'     => $now,
        ]);
        $id = (int) $this->db->insert_id();
        $this->_insert_lines($id, $lines);
        return $id;
    }

    private function _insert_lines($journal_id, array $lines)
    {
        $rows = [];
        foreach (array_values($lines) as $i => $l) {
            $rows[] = [
                'journal_id'    => (int) $journal_id,
                'line_no'       => $i + 1,
                'account_id'    => (int) $l['account_id'],
                'debit_cents'   => (int) $l['debit_cents'],
                'credit_cents'  => (int) $l['credit_cents'],
                'memo'          => $l['memo'] ?? NULL,
                'department_id' => ! empty($l['department_id']) ? (int) $l['department_id'] : NULL,
                'contact_id'    => ! empty($l['contact_id']) ? (int) $l['contact_id'] : NULL,
            ];
        }
        if ($rows) $this->db->insert_batch(self::L, $rows);
    }

    /**
     * Post journal $id. MUST run inside a transaction the caller owns: it
     * locks the journal and its period, re-checks everything from the stored
     * rows, then numbers the entry.
     */
    private function _post_locked($id, $user_id)
    {
        $j = $this->_lock($id);
        if ( ! $j) return 'No such entry.';
        if ($j['status'] !== 'submitted') return 'This entry is ' . $j['status'] . '.';

        $p = $this->db->query(
            'SELECT p.*, fy.status AS fy_status, fy.start_date AS fy_start, fy.name AS fy_name
               FROM gp_periods p JOIN gp_fiscal_years fy ON fy.id = p.fiscal_year_id
              WHERE p.id = ? FOR UPDATE', [(int) $j['period_id']]
        )->row_array();
        if ( ! $p)                              return 'The entry has no period.';
        if ($p['fy_status'] !== 'open')         return $p['fy_name'] . ' is closed.';
        if ($p['status'] !== 'open')            return $p['name'] . ' is ' . $p['status'] . '.';
        if ($j['entry_date'] < $p['start_date'] || $j['entry_date'] > $p['end_date']) return 'The entry date is outside its period.';

        /* Every line rule, again, from what is stored — the chart may have
           changed since the draft was saved (an account made a control
           account, a department made compulsory, a customer deactivated). */
        list(, , $e) = $this->validate($this->_stored_head($j), $this->_stored_lines($id), TRUE);
        if ($e) return reset($e);

        $t = $this->db->query(
            'SELECT COUNT(*) AS n, COALESCE(SUM(debit_cents), 0) AS dr, COALESCE(SUM(credit_cents), 0) AS cr
               FROM gp_journal_lines WHERE journal_id = ?', [(int) $id]
        )->row_array();
        if ((int) $t['n'] < 2 || (string) $t['dr'] !== (string) $t['cr'] || (int) $t['dr'] <= 0) return 'The entry does not balance.';

        $no  = $this->_next_number($j['book'], (int) $p['fiscal_year_id'], $p['fy_start']);
        $now = date('Y-m-d H:i:s');
        $this->db->where('id', (int) $id)->update(self::T, [
            'status'      => 'posted',
            'journal_no'  => $no,
            'total_cents' => (int) $t['dr'],
            'approved_by' => (int) $user_id,
            'approved_at' => $now,
            'updated_at'  => $now,
        ]);
        return '';
    }

    /** <prefix>-<year the fiscal year starts>-<gap-free sequence per book and year> */
    private function _next_number($book, $fy_id, $fy_start)
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $this->config->item('book_prefix_' . $book)));
        if ($prefix === '') $prefix = strtoupper(substr($book, 0, 2));

        $seq    = next_sequence('jn:' . $prefix . ':' . (int) $fy_id);
        $digits = (int) $this->config->item('ledger_number_digits');
        if ($digits < 3 || $digits > 8) $digits = 5;

        return $prefix . '-' . substr($fy_start, 0, 4) . '-' . str_pad((string) $seq, $digits, '0', STR_PAD_LEFT);
    }

    private function _stored_head(array $j)
    {
        return ['book' => $j['book'], 'entry_date' => $j['entry_date'], 'reference' => $j['reference'],
                'party_name' => $j['party_name'], 'description' => $j['description']];
    }

    private function _stored_lines($journal_id)
    {
        $out = [];
        foreach ($this->db->where('journal_id', (int) $journal_id)->order_by('line_no')->get(self::L)->result_array() as $l) {
            $out[] = [
                'account_id'    => (int) $l['account_id'],
                'debit_cents'   => (int) $l['debit_cents'],
                'credit_cents'  => (int) $l['credit_cents'],
                'memo'          => $l['memo'],
                'department_id' => $l['department_id'] !== NULL ? (int) $l['department_id'] : NULL,
                'contact_id'    => $l['contact_id'] !== NULL ? (int) $l['contact_id'] : NULL,
            ];
        }
        return $out;
    }

    /** [user id => display name] */
    private function _names(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ( ! $ids) return [];
        $out = [];
        foreach ($this->db->select('id, full_name, username')->where_in('id', $ids)->get('gp_users')->result_array() as $u) {
            $out[(int) $u['id']] = $u['full_name'] ?: $u['username'];
        }
        return $out;
    }

    private function _name($user_id)
    {
        return $this->_names([$user_id])[(int) $user_id] ?? 'Someone';
    }

    // =========================================================================
    // NOTIFICATIONS — never allowed to break the change that caused them
    // =========================================================================

    /** A submitted entry: tell everyone who can approve it (in the app only; it repeats). */
    private function _notify_approvers($id, $by)
    {
        if ( ! shop_bool('notify_approvals', TRUE)) return;
        try {
            $j = $this->find($id);
            $approvers = $this->db->select('id')->where_in('role', ['accountant', 'admin'])->where('account_state', 'active')
                                  ->where('id !=', (int) $by)->get('gp_users')->result_array();
            if ( ! $j || ! $approvers) return;
            $this->load->model('Notification_model', 'notify');
            $body = $this->_name($by) . ' submitted “' . mb_substr($j['description'], 0, 120) . '” for ' . money_format_cents((int) $j['total_cents']) . '.';
            foreach ($approvers as $a) {
                $this->notify->push((int) $a['id'], 'journal.submitted', 'Entry waiting for approval', $body, '/journals/' . (int) $id, FALSE);
            }
        } catch (Throwable $t) {
            log_message('error', '[Journal_model] approver notice failed: ' . $t->getMessage());
        }
    }

    /** Tell the preparer what happened to their entry — unless they did it themselves. */
    private function _notify_preparer($id, $by, $type, $title, $body, $email)
    {
        if ( ! shop_bool('notify_approvals', TRUE)) return;
        try {
            $j = $this->find($id);
            if ( ! $j || (int) $j['created_by'] === (int) $by) return;
            $this->load->model('Notification_model', 'notify');
            $this->notify->push((int) $j['created_by'], $type, $title, $body, '/journals/' . (int) $id, $email);
        } catch (Throwable $t) {
            log_message('error', '[Journal_model] preparer notice failed: ' . $t->getMessage());
        }
    }
}
