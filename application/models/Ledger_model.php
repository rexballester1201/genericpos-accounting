<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Ledger_model.php — balances from the posted ledger
 *
 * GenericPOS Accounting · reads the gp_ledger view only
 *
 * Every figure here is a SUM over posted lines; nothing is cached or stored,
 * so a back-dated posting is reflected everywhere at once.
 *
 * ─── WHICH LINES COUNT (PLAN.md §2.6) ─────────────────────────────────────
 *   Balance-sheet accounts (asset, liability, equity) are cumulative from the
 *   first entry ever posted. Income and expense accounts run from the start of
 *   the fiscal year that contains the report date.
 *
 *   The trial balance comes in three kinds:
 *     unadjusted    without the current year's adjusting and closing entries
 *     adjusted      without the current year's closing entries
 *     post_closing  everything
 *   Earlier years' adjusting and closing entries always count — they are history.
 *
 *   NOTE: if an earlier year was never closed, its net income is still sitting
 *   in the income and expense accounts. It is shown on its own line, "net
 *   income of earlier years not yet closed", so the trial balance still
 *   balances AND says why, instead of quietly mixing two years' income.
 */
class Ledger_model extends CI_Model
{
    const PL_TYPES = ['income', 'expense'];
    const BS_TYPES = ['asset', 'liability', 'equity'];

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Period_model', 'periods');
    }

    /**
     * Debits and credits per account over [$from, $to] (either end open with NULL).
     *
     * @param array $opts  types (account types), exclude_books + exclude_from (the
     *                     exclusion applies to lines dated on or after exclude_from),
     *                     department_id, contact_id
     * @return array [account_id => ['dr' => int, 'cr' => int]]
     */
    public function movements($from, $to, array $opts = [])
    {
        $where = ['1 = 1'];
        $args  = [];
        if ($from !== NULL) { $where[] = 'l.entry_date >= ?'; $args[] = $from; }
        if ($to !== NULL)   { $where[] = 'l.entry_date <= ?'; $args[] = $to; }
        if ( ! empty($opts['types'])) {
            $where[] = 'a.type IN (' . implode(',', array_fill(0, count($opts['types']), '?')) . ')';
            $args    = array_merge($args, array_values($opts['types']));
        }
        if ( ! empty($opts['exclude_books'])) {
            $in = implode(',', array_fill(0, count($opts['exclude_books']), '?'));
            if ( ! empty($opts['exclude_from'])) {
                $where[] = 'NOT (l.book IN (' . $in . ') AND l.entry_date >= ?)';
                $args    = array_merge($args, array_values($opts['exclude_books']), [$opts['exclude_from']]);
            } else {
                $where[] = 'l.book NOT IN (' . $in . ')';
                $args    = array_merge($args, array_values($opts['exclude_books']));
            }
        }
        if ( ! empty($opts['department_id'])) { $where[] = 'l.department_id = ?'; $args[] = (int) $opts['department_id']; }
        if ( ! empty($opts['no_department'])) $where[] = 'l.department_id IS NULL';   // lines no department was put on
        if ( ! empty($opts['contact_id']))    { $where[] = 'l.contact_id = ?';    $args[] = (int) $opts['contact_id']; }

        $rows = $this->db->query(
            'SELECT l.account_id, SUM(l.debit_cents) AS dr, SUM(l.credit_cents) AS cr
               FROM gp_ledger l JOIN gp_accounts a ON a.id = l.account_id
              WHERE ' . implode(' AND ', $where) . '
              GROUP BY l.account_id',
            $args
        )->result_array();

        $out = [];
        foreach ($rows as $r) $out[(int) $r['account_id']] = ['dr' => (int) $r['dr'], 'cr' => (int) $r['cr']];
        return $out;
    }

    /**
     * The trial balance as of $as_of.
     *
     * @return array as_of, kind, fiscal_year (row|NULL), rows [account_id, code, name, type,
     *               debit_cents, credit_cents], unclosed_prior_cents (debit-positive),
     *               total_debit_cents, total_credit_cents, balanced
     */
    public function trial_balance($as_of, $kind = 'adjusted', $include_zero = FALSE)
    {
        if ( ! in_array($kind, ['unadjusted', 'adjusted', 'post_closing'], TRUE)) $kind = 'adjusted';

        $fy       = $this->periods->year_for_date($as_of);
        $fy_start = $fy ? $fy['start_date'] : NULL;
        $excl     = $kind === 'post_closing' ? [] : ($kind === 'unadjusted' ? ['adjusting', 'closing'] : ['closing']);
        $x        = $excl ? ['exclude_books' => $excl, 'exclude_from' => $fy_start ?: '0000-01-01'] : [];

        $bs = $this->movements(NULL, $as_of, ['types' => self::BS_TYPES] + $x);
        $pl = $fy_start !== NULL ? $this->movements($fy_start, $as_of, ['types' => self::PL_TYPES] + $x) : $this->movements(NULL, $as_of, ['types' => self::PL_TYPES] + $x);

        $prior = 0;
        if ($fy_start !== NULL) {
            foreach ($this->movements(NULL, date('Y-m-d', strtotime($fy_start . ' -1 day')), ['types' => self::PL_TYPES]) as $m) {
                $prior += $m['dr'] - $m['cr'];
            }
        }

        $mov  = $bs + $pl;
        $rows = [];
        $tdr  = 0;
        $tcr  = 0;
        foreach ($this->db->where('is_header', 0)->order_by('sort_order')->order_by('code')->get('gp_accounts')->result_array() as $a) {
            $m   = $mov[(int) $a['id']] ?? ['dr' => 0, 'cr' => 0];
            $net = $m['dr'] - $m['cr'];
            if ($net === 0 && ! $include_zero) continue;
            $rows[] = [
                'account_id'   => (int) $a['id'],
                'code'         => $a['code'],
                'name'         => $a['name'],
                'type'         => $a['type'],
                'debit_cents'  => $net > 0 ? $net : 0,
                'credit_cents' => $net < 0 ? -$net : 0,
            ];
            if ($net > 0) $tdr += $net; else $tcr += -$net;
        }
        if ($prior > 0) $tdr += $prior; elseif ($prior < 0) $tcr += -$prior;

        return [
            'as_of'                => $as_of,
            'kind'                 => $kind,
            'fiscal_year'          => $fy,
            'rows'                 => $rows,
            'unclosed_prior_cents' => $prior,
            'total_debit_cents'    => $tdr,
            'total_credit_cents'   => $tcr,
            'balanced'             => $tdr === $tcr,
        ];
    }

    // =========================================================================
    // ACTIVITY: THE GENERAL LEDGER AND THE BOOKS
    // =========================================================================

    /**
     * One account's ledger over [$from, $to] — or a header's, taking in every
     * account under it: the balance brought forward, each posted line with
     * its running balance, and the balance carried forward.
     *
     * Balance-sheet accounts carry their balance from the first entry; income
     * and expense accounts start from the first day of the fiscal year that
     * contains $from, the way the trial balance counts them.
     *
     * @return array|NULL NULL when there is no such account
     */
    public function account_ledger($account_id, $from, $to, $limit = 3000)
    {
        $this->load->model('Account_model', 'accounts');
        $a = $this->accounts->find((int) $account_id);
        if ( ! $a) return NULL;

        $ids   = array_map('intval', $this->accounts->subtree_ids((int) $a['id']));
        $in    = implode(',', array_fill(0, count($ids), '?'));
        $pl    = in_array($a['type'], self::PL_TYPES, TRUE);
        $side  = (int) $a['is_header'] ? Account_model::normal_side_for($a['type'], FALSE) : $a['normal_side'];
        $sign  = $side === 'D' ? 1 : -1;
        $until = date('Y-m-d', strtotime($from . ' -1 day'));

        $start = NULL;
        if ($pl) {
            $fy = $this->periods->year_for_date($from);
            $start = $fy ? $fy['start_date'] : NULL;
        }
        $opening = 0;
        if ($start === NULL || $start <= $until) {
            $w    = 'account_id IN (' . $in . ') AND entry_date <= ?';
            $args = array_merge($ids, [$until]);
            if ($start !== NULL) { $w .= ' AND entry_date >= ?'; $args[] = $start; }
            $opening = (int) $this->db->query('SELECT COALESCE(SUM(net_cents), 0) AS n FROM gp_ledger WHERE ' . $w, $args)->row()->n;
        }

        $tot = $this->db->query(
            'SELECT COALESCE(SUM(debit_cents), 0) AS dr, COALESCE(SUM(credit_cents), 0) AS cr, COUNT(*) AS n
               FROM gp_ledger WHERE account_id IN (' . $in . ') AND entry_date BETWEEN ? AND ?',
            array_merge($ids, [$from, $to])
        )->row_array();

        $rows = $this->db->query(
            'SELECT l.journal_id, l.journal_no, l.entry_date, l.book, l.reference, l.party_name, l.description, l.memo,
                    l.debit_cents, l.credit_cents, a.code AS account_code, a.name AS account_name,
                    c.name AS contact_name, d.code AS department_code
               FROM gp_ledger l
               JOIN gp_accounts a ON a.id = l.account_id
               LEFT JOIN gp_contacts c ON c.id = l.contact_id
               LEFT JOIN gp_departments d ON d.id = l.department_id
              WHERE l.account_id IN (' . $in . ') AND l.entry_date BETWEEN ? AND ?
              ORDER BY l.entry_date, l.journal_id, l.line_no
              LIMIT ' . ((int) $limit + 1),
            array_merge($ids, [$from, $to])
        )->result_array();
        $truncated = count($rows) > $limit;
        if ($truncated) array_pop($rows);

        $header = (bool) (int) $a['is_header'];
        $bal    = $opening;
        $lines  = [];
        foreach ($rows as $r) {
            $bal += (int) $r['debit_cents'] - (int) $r['credit_cents'];
            $lines[] = [
                'journal_id'    => (int) $r['journal_id'],
                'journal_no'    => $r['journal_no'],
                'date'          => $r['entry_date'],
                'book'          => $r['book'],
                'description'   => $r['description'],
                'memo'          => $r['memo'],
                'reference'     => $r['reference'],
                'party'         => $r['party_name'],
                'contact'       => $r['contact_name'],
                'department'    => $r['department_code'],
                'account'       => $header ? $r['account_code'] . ' ' . $r['account_name'] : NULL,
                'debit_cents'   => (int) $r['debit_cents'],
                'credit_cents'  => (int) $r['credit_cents'],
                'balance_cents' => $sign * $bal,
            ];
        }

        $dr = (int) $tot['dr'];
        $cr = (int) $tot['cr'];
        return [
            'account'       => ['id' => (int) $a['id'], 'code' => $a['code'], 'name' => $a['name'], 'type' => $a['type'],
                                'is_header' => $header, 'normal_side' => $side],
            'from'          => $from,
            'to'            => $to,
            'counts_from'   => $pl ? $start : NULL,
            'opening_cents' => $sign * $opening,
            'lines'         => $lines,
            'line_count'    => (int) $tot['n'],
            'truncated'     => $truncated,
            'debit_cents'   => $dr,
            'credit_cents'  => $cr,
            'closing_cents' => $sign * ($opening + $dr - $cr),
        ];
    }

    /**
     * The posted journals of one book (or of every book, $book = '') over
     * [$from, $to], each with its lines, in date and number order: the books
     * of accounts.
     *
     * @return array [journals with their lines, how many in the whole range, their total]
     */
    public function book_register($book, $from, $to, $limit, $offset)
    {
        $w    = "status = 'posted' AND entry_date BETWEEN ? AND ?";
        $args = [$from, $to];
        if ($book !== '') { $w .= ' AND book = ?'; $args[] = $book; }

        $agg = $this->db->query('SELECT COUNT(*) AS n, COALESCE(SUM(total_cents), 0) AS t FROM gp_journals WHERE ' . $w, $args)->row_array();
        $js  = $this->db->query(
            'SELECT id, book, journal_no, entry_date, reference, party_name, description, total_cents, source
               FROM gp_journals WHERE ' . $w . '
              ORDER BY entry_date, book, journal_no
              LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
            $args
        )->result_array();

        $by = [];
        foreach ($js as $j) {
            $by[(int) $j['id']] = [
                'id' => (int) $j['id'], 'book' => $j['book'], 'journal_no' => $j['journal_no'], 'date' => $j['entry_date'],
                'reference' => $j['reference'], 'party' => $j['party_name'], 'description' => $j['description'],
                'total_cents' => (int) $j['total_cents'], 'source' => $j['source'], 'lines' => [],
            ];
        }
        if ($by) {
            $in = implode(',', array_fill(0, count($by), '?'));
            foreach ($this->db->query(
                'SELECT l.journal_id, l.debit_cents, l.credit_cents, l.memo, a.code, a.name, c.name AS contact
                   FROM gp_journal_lines l
                   JOIN gp_accounts a ON a.id = l.account_id
                   LEFT JOIN gp_contacts c ON c.id = l.contact_id
                  WHERE l.journal_id IN (' . $in . ')
                  ORDER BY l.journal_id, l.line_no',
                array_keys($by)
            )->result_array() as $l) {
                $by[(int) $l['journal_id']]['lines'][] = [
                    'code' => $l['code'], 'name' => $l['name'], 'memo' => $l['memo'], 'contact' => $l['contact'],
                    'debit_cents' => (int) $l['debit_cents'], 'credit_cents' => (int) $l['credit_cents'],
                ];
            }
        }
        return [array_values($by), (int) $agg['n'], (int) $agg['t']];
    }
}
