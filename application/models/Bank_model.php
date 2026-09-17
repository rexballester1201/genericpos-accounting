<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Bank_model.php — bank accounts, statements, their lines, and the reconciliation figures
 *
 * GenericPOS Accounting · banking module · tables gp_bank_accounts, gp_bank_statements,
 * gp_bank_lines, gp_bank_matches (read-only over gp_ledger / gp_journal_lines)
 *
 * ─── THE MODEL ────────────────────────────────────────────────────────────
 *   · A bank account is linked to ONE ledger cash account (unique). Its "book
 *     balance" is the gp_ledger balance of that account.
 *   · A statement has a closing date, an opening and a closing balance, and
 *     lines: + deposit / − withdrawal, from the depositor's side.
 *   · A book line (a posted journal line on the ledger account) clears at most
 *     once; several book lines may clear one bank line. A line is matched when
 *     the book lines' net equals the bank amount.
 *
 * ─── WHERE RECONCILING STARTS ─────────────────────────────────────────────
 * A statement covers the days after the previous statement's date. The first
 * statement of a bank account covers the month ending on its date (or from
 * its earliest line, if that is earlier). Book lines dated before that — and
 * opening-balance entries — are taken as already in the first statement's
 * opening balance: they are never deposits in transit or outstanding cheques.
 * They can still be matched, so an old cheque that clears later is handled.
 *
 * ─── THE RECONCILIATION (as of a statement S, dated D) ───────────────────
 *   bank:  closing per statement
 *          + deposits in transit   book debits dated [start, D] not cleared by S or earlier
 *          − outstanding cheques   book credits likewise
 *          − bank errors           ignored lines on S and earlier statements (their net)
 *   books: balance at D
 *          + credit memos          unmatched, not ignored + lines of S
 *          − debit memos           unmatched, not ignored − lines of S
 *          + booked later          book lines matched to S but dated after D
 *   A reversed entry and its reversal, both dated by D and neither matched,
 *   cancel out and are left out of both lists.
 *
 * NOTE: every write runs in ONE transaction with its audit row, and locks the
 * statement (then the line) before re-checking its status: two tabs, one click
 * each, can never both succeed.
 */
class Bank_model extends CI_Model
{
    const A = 'gp_bank_accounts';
    const S = 'gp_bank_statements';
    const L = 'gp_bank_lines';
    const M = 'gp_bank_matches';

    /** A statement holds at most this many lines. */
    const MAX_LINES = 2000;

    public function __construct()
    {
        parent::__construct();
        $this->config->load('app', FALSE, TRUE);
    }

    // =========================================================================
    // SMALL HELPERS
    // =========================================================================

    /** Days either side of a bank line within which a book line may match it. Read per call (settings hook). */
    public function window_days()
    {
        return max(0, min(60, (int) shop_cfg('bank_match_window_days', 7)));
    }

    public static function add_days($ymd, $n)
    {
        return date('Y-m-d', strtotime($ymd . ' ' . ((int) $n >= 0 ? '+' : '') . (int) $n . ' days'));
    }

    /** Whole days from $a to $b (Y-m-d). */
    public static function days_between($a, $b)
    {
        return (int) round((strtotime($b . ' 00:00:00') - strtotime($a . ' 00:00:00')) / 86400);
    }

    /** The first day a monthly statement ending on $date covers. */
    public static function natural_start($date)
    {
        if ($date === date('Y-m-t', strtotime($date))) return substr($date, 0, 8) . '01';
        list($y, $m, $d) = array_map('intval', explode('-', $date));
        $m--;
        if ($m < 1) { $m = 12; $y--; }
        $d = min($d, (int) date('t', mktime(0, 0, 0, $m, 1, $y)));
        return self::add_days(sprintf('%04d-%02d-%02d', $y, $m, $d), 1);
    }

    /** Whole centavos from an int or a signed digit string; NULL otherwise. */
    public static function cents_in($v)
    {
        if (is_int($v)) return abs($v) < 1000000000000000 ? $v : NULL;
        if (is_string($v) && preg_match('/^-?\d{1,15}$/', trim($v))) return (int) trim($v);
        return NULL;
    }

    /** [user id => display name] */
    public function names(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ( ! $ids) return [];
        $out = [];
        foreach ($this->db->select('id, full_name, username')->where_in('id', $ids)->get('gp_users')->result_array() as $u) {
            $out[(int) $u['id']] = $u['full_name'] ?: $u['username'];
        }
        return $out;
    }

    // =========================================================================
    // TRANSACTIONS, LOCKS, AUDIT
    // =========================================================================

    /**
     * Run $fn in one transaction. $fn returns its result, or throws a
     * DomainException whose message is for the user.
     *
     * @return array [result|NULL, error '' on success]
     */
    public function tx(callable $fn)
    {
        $this->db->trans_begin();
        try {
            $out = $fn();
            if ($this->db->trans_status() === FALSE) throw new RuntimeException('The database refused the change.');
            $this->db->trans_commit();
            return [$out, ''];
        } catch (DomainException $e) {
            $this->db->trans_rollback();
            return [NULL, $e->getMessage()];
        } catch (mysqli_sql_exception $e) {
            $this->db->trans_rollback();
            log_message('error', '[Bank_model] ' . $e->getMessage());
            return [NULL, (int) $e->getCode() === 1062
                ? 'Someone changed this a moment ago. Reload the page and try again.'
                : 'The change could not be saved, so nothing was changed. Please try again.'];
        } catch (RuntimeException $e) {
            /* Journal_model, nested inside this transaction, throws these with a message fit for the user. */
            $this->db->trans_rollback();
            return [NULL, $e->getMessage()];
        } catch (Throwable $t) {
            $this->db->trans_rollback();
            log_message('error', '[Bank_model] ' . $t->getMessage());
            return [NULL, 'The change could not be saved, so nothing was changed. Please try again.'];
        }
    }

    /** The audit row, or the whole change rolls back ("no change without its record"). */
    public function audit(array $claims, $action, $type, $id, array $detail = [])
    {
        if ( ! log_admin_action($claims, $action, $type, (int) $id, $detail)) {
            throw new DomainException('The audit trail could not be written, so nothing was saved.');
        }
    }

    public function lock_statement($id)
    {
        $st = $this->db->query('SELECT * FROM gp_bank_statements WHERE id = ? FOR UPDATE', [(int) $id])->row_array();
        if ( ! $st) throw new DomainException('That statement no longer exists.');
        return $st;
    }

    public function lock_open_statement($id)
    {
        $st = $this->lock_statement($id);
        if ($st['status'] !== 'open') {
            throw new DomainException('This statement is reconciled, so it can no longer change. An accountant can reopen it if it is the latest reconciled statement.');
        }
        return $st;
    }

    /** Lock a line's statement (open) and then the line — always in that order. @return array [statement, line] */
    public function lock_line($line_id)
    {
        $row = $this->db->query('SELECT statement_id FROM gp_bank_lines WHERE id = ?', [(int) $line_id])->row_array();
        if ( ! $row) throw new DomainException('That bank line no longer exists.');
        $st = $this->lock_open_statement((int) $row['statement_id']);
        $l  = $this->db->query('SELECT * FROM gp_bank_lines WHERE id = ? FOR UPDATE', [(int) $line_id])->row_array();
        if ( ! $l || (int) $l['statement_id'] !== (int) $st['id']) throw new DomainException('That bank line no longer exists.');
        return [$st, $l];
    }

    // =========================================================================
    // BANK ACCOUNTS
    // =========================================================================

    /** One bank account with its ledger account's code and name, or NULL. */
    public function account($id)
    {
        return $this->db->query(
            'SELECT b.*, a.code AS ledger_code, a.name AS ledger_name, a.is_active AS ledger_active
               FROM gp_bank_accounts b JOIN gp_accounts a ON a.id = b.account_id WHERE b.id = ?',
            [(int) $id]
        )->row_array() ?: NULL;
    }

    public function shape_account(array $b)
    {
        return [
            'id'            => (int) $b['id'],
            'bank_name'     => $b['bank_name'],
            'account_name'  => $b['account_name'],
            'account_last4' => $b['account_last4'],
            'is_active'     => (bool) (int) $b['is_active'],
            'account_id'    => (int) $b['account_id'],
            'ledger_code'   => $b['ledger_code'] ?? NULL,
            'ledger_name'   => $b['ledger_name'] ?? NULL,
            'label'         => $b['bank_name'] . ($b['account_last4'] ? ' ••••' . $b['account_last4'] : ''),
        ];
    }

    /**
     * Every bank account with its book balance today, its statements (newest
     * first, with their line counts), its last statement and its unmatched lines.
     */
    public function accounts_overview()
    {
        $today = company_today();
        $banks = $this->db->query(
            'SELECT b.*, a.code AS ledger_code, a.name AS ledger_name, a.is_active AS ledger_active
               FROM gp_bank_accounts b JOIN gp_accounts a ON a.id = b.account_id
              ORDER BY b.is_active DESC, b.bank_name, b.id'
        )->result_array();
        if ( ! $banks) return [];

        $acct_ids = array_map(function ($b) { return (int) $b['account_id']; }, $banks);
        $bal = [];
        foreach ($this->db->query(
            'SELECT account_id, COALESCE(SUM(net_cents), 0) AS b FROM gp_ledger
              WHERE account_id IN (' . implode(',', $acct_ids) . ') AND entry_date <= ? GROUP BY account_id', [$today]
        )->result_array() as $r) $bal[(int) $r['account_id']] = (int) $r['b'];

        $sts = $this->db->query(
            'SELECT * FROM gp_bank_statements WHERE bank_account_id IN (' . implode(',', array_map(function ($b) { return (int) $b['id']; }, $banks)) . ')
              ORDER BY statement_date DESC, id DESC'
        )->result_array();
        $counts = $this->line_counts(array_map(function ($s) { return (int) $s['id']; }, $sts));
        $names  = $this->names(array_merge(array_column($sts, 'reconciled_by'), array_column($sts, 'created_by')));

        $by = [];
        foreach ($sts as $s) $by[(int) $s['bank_account_id']][] = $this->shape_statement($s, $counts[(int) $s['id']] ?? NULL, $names);

        $out = [];
        foreach ($banks as $b) {
            $list = $by[(int) $b['id']] ?? [];
            $out[] = $this->shape_account($b) + [
                'ledger_active'      => (bool) (int) $b['ledger_active'],
                'book_balance_cents' => $bal[(int) $b['account_id']] ?? 0,
                'unmatched_count'    => array_sum(array_map(function ($s) { return $s['unmatched_count']; }, $list)),
                'open_count'         => count(array_filter($list, function ($s) { return $s['status'] === 'open'; })),
                'last_statement'     => $list ? $list[0] : NULL,
                'statements'         => $list,
            ];
        }
        return $out;
    }

    /**
     * Ledger accounts a bank account may link to: postable, active assets whose
     * cash-flow class is 'cash' (inherited from the header), not linked yet.
     * $keep_id is the account the edited bank account already uses.
     */
    public function eligible_ledger_accounts($keep_id = 0)
    {
        $linked = [];
        foreach ($this->db->select('id, account_id')->get(self::A)->result_array() as $r) $linked[(int) $r['account_id']] = (int) $r['id'];
        $out = [];
        foreach ($this->_chart() as $a) {
            if ($a['is_header'] || $a['type'] !== 'asset' || $a['cf'] !== 'cash' || ! (int) $a['is_active']) continue;
            if (isset($linked[$a['id']]) && $a['id'] !== (int) $keep_id) continue;
            $out[] = ['id' => $a['id'], 'code' => $a['code'], 'name' => $a['name']];
        }
        return $out;
    }

    /** The chart with inherited cash-flow classes (Statement_lib::chart), loaded on first use. */
    private function _chart()
    {
        if ( ! isset($this->bank_chart_lib)) $this->load->library('Statement_lib', NULL, 'bank_chart_lib');
        return $this->bank_chart_lib->chart();
    }

    /** @return array [data, errors] */
    public function validate_account(array $in, $existing = NULL)
    {
        $e    = [];
        $pick = function ($k, $d = '') use ($in, $existing) {
            return array_key_exists($k, $in) ? $in[$k] : ($existing !== NULL && array_key_exists($k, $existing) ? $existing[$k] : $d);
        };

        $bank_name = clean_line($pick('bank_name'), 300);
        if ($bank_name === '')                $e['bank_name'] = 'Enter the bank\'s name.';
        elseif (mb_strlen($bank_name) > 120)  $e['bank_name'] = 'Keep the bank\'s name under 120 characters.';

        $acct_name = clean_line($pick('account_name'), 300);
        if (mb_strlen($acct_name) > 160)      $e['account_name'] = 'Keep the account name under 160 characters.';

        $raw4   = trim((string) $pick('account_last4'));
        $digits = preg_replace('/\D/', '', $raw4);
        if ($raw4 !== '') {
            if (strlen($digits) > 4)                    $e['account_last4'] = 'Enter only the last 4 digits. The full account number is never stored.';
            elseif ( ! preg_match('/^\d{4}$/', $digits)) $e['account_last4'] = 'Enter the last 4 digits of the account number.';
        }

        $aid    = (int) $pick('account_id', 0);
        $chart  = $this->_chart();
        $a      = $chart[$aid] ?? NULL;
        $linked = $this->db->select('id, bank_name')->get_where(self::A, ['account_id' => $aid], 1)->row_array();
        if ( ! $aid)                                                  $e['account_id'] = 'Choose the ledger account this bank account posts to.';
        elseif ( ! $a)                                                $e['account_id'] = 'That ledger account does not exist.';
        elseif ($a['is_header'])                                      $e['account_id'] = 'Choose a posting account, not a header.';
        elseif ($a['type'] !== 'asset' || $a['cf'] !== 'cash')        $e['account_id'] = 'Choose a cash account (one under Cash and Cash Equivalents in the chart).';
        elseif ($linked && ( ! $existing || (int) $linked['id'] !== (int) $existing['id'])) {
            $e['account_id'] = 'That ledger account is already linked to ' . $linked['bank_name'] . '.';
        }
        elseif ( ! (int) $a['is_active'] && ( ! $existing || (int) $existing['account_id'] !== $aid)) $e['account_id'] = 'That ledger account is inactive.';
        elseif ($existing && (int) $existing['account_id'] !== $aid
            && $this->db->where('bank_account_id', (int) $existing['id'])->count_all_results(self::S) > 0) {
            $e['account_id'] = 'This bank account already has statements, so its ledger account can no longer change.';
        }

        $active = $existing ? (bool) $pick('is_active', TRUE) : TRUE;
        if ($active && $a && ! (int) $a['is_active'] && ! isset($e['account_id'])) $e['is_active'] = 'Its ledger account is inactive, so the bank account cannot be active.';

        return [[
            'bank_name'     => $bank_name,
            'account_name'  => $acct_name !== '' ? $acct_name : NULL,
            'account_last4' => $digits !== '' ? $digits : NULL,
            'account_id'    => $aid,
            'is_active'     => $active ? 1 : 0,
        ], $e];
    }

    /** @return array [id, error] */
    public function create_account(array $data, array $claims)
    {
        list($id, $err) = $this->tx(function () use ($data, $claims) {
            $now = date('Y-m-d H:i:s');
            $this->db->insert(self::A, $data + ['created_at' => $now, 'updated_at' => $now]);
            $id = (int) $this->db->insert_id();
            $this->audit($claims, 'bank_account.create', 'bank_account', $id, [
                'bank_name' => $data['bank_name'], 'account_last4' => $data['account_last4'], 'account_id' => $data['account_id'],
            ]);
            return $id;
        });
        return [(int) $id, $err];
    }

    /** @return string error */
    public function update_account($id, array $data, array $claims)
    {
        list(, $err) = $this->tx(function () use ($id, $data, $claims) {
            $b = $this->db->query('SELECT * FROM gp_bank_accounts WHERE id = ? FOR UPDATE', [(int) $id])->row_array();
            if ( ! $b) throw new DomainException('That bank account no longer exists.');
            $changes = [];
            foreach ($data as $k => $v) if ((string) $b[$k] !== (string) $v) $changes[$k] = ['from' => $b[$k], 'to' => $v];
            if ( ! $changes) return TRUE;
            $this->db->where('id', (int) $id)->update(self::A, $data + ['updated_at' => date('Y-m-d H:i:s')]);
            $action = isset($changes['is_active']) && count($changes) === 1
                ? ($data['is_active'] ? 'bank_account.activate' : 'bank_account.deactivate') : 'bank_account.update';
            $this->audit($claims, $action, 'bank_account', (int) $id, $changes);
            return TRUE;
        });
        return $err;
    }

    // =========================================================================
    // STATEMENTS
    // =========================================================================

    public function statement($id)
    {
        return $this->db->get_where(self::S, ['id' => (int) $id], 1)->row_array() ?: NULL;
    }

    /** [statement id => [n, matched, unmatched, ignored, total_cents]] */
    public function line_counts(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ( ! $ids) return [];
        $out = [];
        foreach ($this->db->query(
            "SELECT statement_id, COUNT(*) AS n, SUM(status = 'matched') AS m, SUM(status = 'unmatched') AS u,
                    SUM(status = 'ignored') AS i, COALESCE(SUM(amount_cents), 0) AS t
               FROM gp_bank_lines WHERE statement_id IN (" . implode(',', $ids) . ') GROUP BY statement_id'
        )->result_array() as $r) {
            $out[(int) $r['statement_id']] = ['n' => (int) $r['n'], 'm' => (int) $r['m'], 'u' => (int) $r['u'], 'i' => (int) $r['i'], 't' => (int) $r['t']];
        }
        return $out;
    }

    public function shape_statement(array $s, $c = NULL, array $names = [])
    {
        $c = $c ?: ['n' => 0, 'm' => 0, 'u' => 0, 'i' => 0, 't' => 0];
        return [
            'id'                    => (int) $s['id'],
            'bank_account_id'       => (int) $s['bank_account_id'],
            'statement_date'        => $s['statement_date'],
            'opening_balance_cents' => (int) $s['opening_balance_cents'],
            'closing_balance_cents' => (int) $s['closing_balance_cents'],
            'status'                => $s['status'],
            'reconciled_by_name'    => $s['reconciled_by'] ? ($names[(int) $s['reconciled_by']] ?? '#' . (int) $s['reconciled_by']) : NULL,
            'reconciled_at'         => $s['reconciled_at'],
            'created_by_name'       => $names[(int) $s['created_by']] ?? NULL,
            'created_at'            => $s['created_at'],
            'line_count'            => $c['n'],
            'matched_count'         => $c['m'],
            'unmatched_count'       => $c['u'],
            'ignored_count'         => $c['i'],
            'lines_total_cents'     => $c['t'],
            'adds_up'               => (int) $s['opening_balance_cents'] + $c['t'] === (int) $s['closing_balance_cents'],
        ];
    }

    /** The statement of the same bank account dated just before $st, or NULL. */
    public function previous_statement(array $st)
    {
        return $this->db->query(
            'SELECT * FROM gp_bank_statements WHERE bank_account_id = ? AND statement_date < ? ORDER BY statement_date DESC LIMIT 1',
            [(int) $st['bank_account_id'], $st['statement_date']]
        )->row_array() ?: NULL;
    }

    /** The first day a statement covers: the day after the previous one, or — for the first — its month. */
    public function period_from(array $st)
    {
        $prev = $this->previous_statement($st);
        if ($prev) return self::add_days($prev['statement_date'], 1);
        $d = self::natural_start($st['statement_date']);
        if ( ! empty($st['id'])) {
            $first = $this->db->query('SELECT MIN(txn_date) AS d FROM gp_bank_lines WHERE statement_id = ?', [(int) $st['id']])->row_array();
            if ($first && $first['d'] && $first['d'] < $d) $d = $first['d'];
        }
        return $d;
    }

    /** Where reconciling this bank account starts: the first day its first statement covers. */
    public function recon_start($bank_account_id)
    {
        $first = $this->db->query('SELECT * FROM gp_bank_statements WHERE bank_account_id = ? ORDER BY statement_date ASC LIMIT 1',
                                  [(int) $bank_account_id])->row_array();
        return $first ? $this->period_from($first) : NULL;
    }

    /** Is $st the latest reconciled statement of its bank account? */
    public function is_latest_reconciled(array $st)
    {
        if ($st['status'] !== 'reconciled') return FALSE;
        return ! $this->db->query("SELECT 1 FROM gp_bank_statements WHERE bank_account_id = ? AND status = 'reconciled' AND statement_date > ? LIMIT 1",
                                  [(int) $st['bank_account_id'], $st['statement_date']])->row_array();
    }

    /** @return array [data, errors] */
    public function validate_statement(array $in, $existing = NULL)
    {
        $e = [];
        $bank_id = $existing ? (int) $existing['bank_account_id'] : (int) ($in['bank_account_id'] ?? 0);
        $bank    = $bank_id ? $this->account($bank_id) : NULL;
        if ( ! $existing) {
            if ( ! $bank)                          $e['bank_account_id'] = 'Choose the bank account.';
            elseif ( ! (int) $bank['is_active'])   $e['bank_account_id'] = 'This bank account is inactive. An accountant can activate it again.';
        }

        $date = trim((string) (array_key_exists('statement_date', $in) ? $in['statement_date'] : ($existing['statement_date'] ?? '')));
        if ( ! Period_model::valid_date($date))    $e['statement_date'] = 'Enter the statement date (its closing date).';
        elseif ($date > company_today())           $e['statement_date'] = 'The statement date cannot be after today.';
        elseif ($bank) {
            $sid = $existing ? (int) $existing['id'] : 0;
            if ($this->db->query('SELECT 1 FROM gp_bank_statements WHERE bank_account_id = ? AND statement_date = ? AND id <> ? LIMIT 1',
                                 [$bank_id, $date, $sid])->row_array()) {
                $e['statement_date'] = 'There is already a statement dated ' . $date . ' for this bank account.';
            } else {
                $rec = $this->db->query("SELECT MAX(statement_date) AS d FROM gp_bank_statements WHERE bank_account_id = ? AND status = 'reconciled' AND id <> ?",
                                        [$bank_id, $sid])->row()->d;
                if ($rec && $date <= $rec) $e['statement_date'] = 'The statement of ' . $rec . ' is reconciled; this statement must be dated after it.';
            }
            if ( ! isset($e['statement_date']) && $existing && $date !== $existing['statement_date']) {
                $c = $this->db->query("SELECT SUM(status <> 'unmatched') AS used, SUM(txn_date > ?) AS later FROM gp_bank_lines WHERE statement_id = ?",
                                      [$date, (int) $existing['id']])->row_array();
                if ((int) $c['used'] > 0)       $e['statement_date'] = 'Lines on this statement are matched or ignored, so its date can no longer change.';
                elseif ((int) $c['later'] > 0)  $e['statement_date'] = 'Some lines are dated after ' . $date . '. Change or delete them first.';
            }
        }

        $open  = self::cents_in(array_key_exists('opening_balance_cents', $in) ? $in['opening_balance_cents'] : ($existing['opening_balance_cents'] ?? NULL));
        $close = self::cents_in(array_key_exists('closing_balance_cents', $in) ? $in['closing_balance_cents'] : ($existing['closing_balance_cents'] ?? NULL));
        if ($open === NULL)  $e['opening_balance_cents'] = 'Enter the opening balance shown on the statement.';
        if ($close === NULL) $e['closing_balance_cents'] = 'Enter the closing balance shown on the statement.';

        return [['bank_account_id' => $bank_id, 'statement_date' => $date, 'opening_balance_cents' => $open, 'closing_balance_cents' => $close], $e];
    }

    /** @return array [id, error] */
    public function create_statement(array $data, array $claims)
    {
        list($id, $err) = $this->tx(function () use ($data, $claims) {
            $now = date('Y-m-d H:i:s');
            $this->db->insert(self::S, $data + ['status' => 'open', 'created_by' => (int) $claims['user_id'], 'created_at' => $now, 'updated_at' => $now]);
            $id = (int) $this->db->insert_id();
            $this->audit($claims, 'bank_statement.create', 'bank_statement', $id, [
                'bank_account_id' => $data['bank_account_id'], 'statement_date' => $data['statement_date'],
                'opening_cents' => $data['opening_balance_cents'], 'closing_cents' => $data['closing_balance_cents'],
            ]);
            return $id;
        });
        return [(int) $id, $err];
    }

    /** @return string error */
    public function update_statement($id, array $in, array $claims)
    {
        list(, $err) = $this->tx(function () use ($id, $in, $claims) {
            $st = $this->lock_open_statement($id);
            list($data, $e) = $this->validate_statement($in, $st);
            if ($e) throw new DomainException(reset($e));
            unset($data['bank_account_id']);
            $changes = [];
            foreach ($data as $k => $v) if ((string) $st[$k] !== (string) $v) $changes[$k] = ['from' => $st[$k], 'to' => $v];
            if ( ! $changes) return TRUE;
            $this->db->where('id', (int) $id)->update(self::S, $data + ['updated_at' => date('Y-m-d H:i:s')]);
            $this->audit($claims, 'bank_statement.update', 'bank_statement', (int) $id, $changes);
            return TRUE;
        });
        return $err;
    }

    /** Only an open statement with nothing matched. @return string error */
    public function delete_statement($id, array $claims)
    {
        list(, $err) = $this->tx(function () use ($id, $claims) {
            $st = $this->lock_open_statement($id);
            $c  = $this->db->query("SELECT COUNT(*) AS n, SUM(status = 'matched' OR journal_id IS NOT NULL) AS m FROM gp_bank_lines WHERE statement_id = ?",
                                   [(int) $id])->row_array();
            if ((int) $c['m'] > 0) {
                throw new DomainException('Lines on this statement are matched. Unmatch them (and undo any entry recorded from them) before deleting it.');
            }
            $this->db->where('id', (int) $id)->delete(self::S);
            $this->audit($claims, 'bank_statement.delete', 'bank_statement', (int) $id, [
                'bank_account_id' => (int) $st['bank_account_id'], 'statement_date' => $st['statement_date'], 'lines' => (int) $c['n'],
            ]);
            return TRUE;
        });
        return $err;
    }

    // =========================================================================
    // BANK LINES
    // =========================================================================

    /** [earliest, latest] date a line on this statement may carry. */
    public function line_date_range(array $st)
    {
        $max  = $st['statement_date'];
        $prev = $this->previous_statement($st);
        return [$prev ? self::add_days($prev['statement_date'], 1) : self::add_days($max, -366), $max];
    }

    /** @return array [data, errors] */
    public function validate_line(array $in, array $st)
    {
        $e = [];
        list($min, $max) = $this->line_date_range($st);
        $date = trim((string) ($in['txn_date'] ?? ''));
        if ( ! Period_model::valid_date($date)) $e['txn_date'] = 'Enter the date shown on the bank statement.';
        elseif ($date > $max)                   $e['txn_date'] = 'The line is dated after the statement date (' . $max . ').';
        elseif ($date < $min)                   $e['txn_date'] = 'The line is dated before this statement\'s period (' . $min . ' onwards).';

        $desc = clean_line($in['description'] ?? '', 255);
        $ref  = clean_line($in['reference'] ?? '', 300);
        if (mb_strlen($ref) > 60) $e['reference'] = 'Keep the reference under 60 characters.';

        $amt = self::cents_in($in['amount_cents'] ?? NULL);
        if ($amt === NULL)  $e['amount_cents'] = 'Enter the amount: a deposit or a withdrawal.';
        elseif ($amt === 0) $e['amount_cents'] = 'The amount cannot be zero.';

        return [['txn_date' => $date, 'description' => $desc, 'reference' => $ref !== '' ? $ref : NULL, 'amount_cents' => $amt], $e];
    }

    private static function dup_key($date, $amount, $ref)
    {
        return $date . '|' . (int) $amount . '|' . mb_strtoupper(trim((string) $ref));
    }

    /**
     * Add lines to an open statement: one typed by hand, or a batch from a file.
     * An imported line that repeats one already on the statement (same date,
     * amount and reference) is skipped; a file may still hold two identical
     * lines (two equal fees on one day), so each existing line skips one.
     *
     * @param  array $rows  clean rows: txn_date, description, reference, amount_cents (+ row)
     * @param  bool  $import
     * @return array [[ids added, rows skipped], error]
     */
    public function add_lines($statement_id, array $rows, array $claims, $import = FALSE, array $meta = [])
    {
        return $this->tx(function () use ($statement_id, $rows, $claims, $import, $meta) {
            $st = $this->lock_open_statement($statement_id);
            $existing = $this->db->select('txn_date, amount_cents, reference')->get_where(self::L, ['statement_id' => (int) $st['id']])->result_array();

            $have = [];
            if ($import) foreach ($existing as $x) { $k = self::dup_key($x['txn_date'], $x['amount_cents'], $x['reference']); $have[$k] = ($have[$k] ?? 0) + 1; }

            $add  = [];
            $skip = [];
            foreach ($rows as $r) {
                $k = self::dup_key($r['txn_date'], $r['amount_cents'], $r['reference']);
                if ($import && ! empty($have[$k])) { $have[$k]--; $skip[] = $r; continue; }
                $add[] = $r;
            }
            if (count($existing) + count($add) > self::MAX_LINES) {
                throw new DomainException('A statement holds at most ' . number_format(self::MAX_LINES) . ' lines; this one has '
                    . count($existing) . ' and the file adds ' . count($add) . '. Split the file into two statements.');
            }

            $ids = [];
            foreach ($add as $r) {
                $this->db->insert(self::L, ['statement_id' => (int) $st['id'], 'txn_date' => $r['txn_date'], 'description' => (string) $r['description'],
                                            'reference' => $r['reference'], 'amount_cents' => (int) $r['amount_cents'], 'status' => 'unmatched']);
                $ids[] = (int) $this->db->insert_id();
            }
            if ($import) {
                $this->audit($claims, 'bank_statement.import', 'bank_statement', (int) $st['id'], [
                    'added' => count($ids), 'skipped' => count($skip), 'date_format' => $meta['date_format'] ?? NULL,
                    'file' => $meta['file_name'] ?? NULL, 'total_cents' => array_sum(array_column($add, 'amount_cents')),
                ]);
            } else {
                foreach ($ids as $i => $lid) {
                    $this->audit($claims, 'bank_line.add', 'bank_line', $lid, ['statement_id' => (int) $st['id'], 'txn_date' => $add[$i]['txn_date'],
                        'amount_cents' => (int) $add[$i]['amount_cents'], 'reference' => $add[$i]['reference']]);
                }
            }
            if ($ids) $this->db->where('id', (int) $st['id'])->update(self::S, ['updated_at' => date('Y-m-d H:i:s')]);
            return [$ids, $skip];
        });
    }

    /** Change an unmatched line on an open statement. @return string error */
    public function update_line($line_id, array $in, array $claims)
    {
        list(, $err) = $this->tx(function () use ($line_id, $in, $claims) {
            list($st, $l) = $this->lock_line($line_id);
            if ($l['status'] !== 'unmatched' || $l['journal_id']) throw new DomainException('Only an unmatched line can be changed. Unmatch it first.');
            list($data, $e) = $this->validate_line($in + ['txn_date' => $l['txn_date'], 'description' => $l['description'],
                                                          'reference' => $l['reference'], 'amount_cents' => (int) $l['amount_cents']], $st);
            if ($e) throw new DomainException(reset($e));
            $changes = [];
            foreach ($data as $k => $v) if ((string) $l[$k] !== (string) $v) $changes[$k] = ['from' => $l[$k], 'to' => $v];
            if ( ! $changes) return TRUE;
            $this->db->where('id', (int) $line_id)->update(self::L, $data);
            $this->audit($claims, 'bank_line.update', 'bank_line', (int) $line_id, ['statement_id' => (int) $st['id']] + $changes);
            return TRUE;
        });
        return $err;
    }

    /** Delete an unmatched line from an open statement. @return string error */
    public function delete_line($line_id, array $claims)
    {
        list(, $err) = $this->tx(function () use ($line_id, $claims) {
            list($st, $l) = $this->lock_line($line_id);
            if ($l['status'] !== 'unmatched' || $l['journal_id']) throw new DomainException('Only an unmatched line can be deleted. Unmatch it first.');
            $this->db->where('id', (int) $line_id)->delete(self::L);
            $this->audit($claims, 'bank_line.delete', 'bank_line', (int) $line_id, ['statement_id' => (int) $st['id'], 'txn_date' => $l['txn_date'],
                'amount_cents' => (int) $l['amount_cents'], 'reference' => $l['reference'], 'description' => $l['description']]);
            return TRUE;
        });
        return $err;
    }

    // =========================================================================
    // THE BOOK SIDE
    // =========================================================================

    public function book_balance($account_id, $date)
    {
        return (int) $this->db->query('SELECT COALESCE(SUM(net_cents), 0) AS b FROM gp_ledger WHERE account_id = ? AND entry_date <= ?',
                                      [(int) $account_id, $date])->row()->b;
    }

    /**
     * Lines on $account_id belonging to a reversed entry and its reversal, both
     * dated on or before $date, where neither entry's line here is matched: the
     * pair cancels out and never reaches the bank. [line id => TRUE]
     */
    public function void_lines($account_id, $date = '9999-12-31')
    {
        $out = [];
        foreach ($this->db->query(
            "SELECT l.line_id
               FROM gp_ledger l
               JOIN gp_journals j ON j.id = l.journal_id
               JOIN gp_journals o ON o.id = COALESCE(j.reversal_of_id, j.id)
               JOIN gp_journals r ON r.id = o.reversed_by_id AND r.status = 'posted'
              WHERE l.account_id = ? AND o.entry_date <= ? AND r.entry_date <= ?
                AND NOT EXISTS (SELECT 1 FROM gp_journal_lines x JOIN gp_bank_matches bm ON bm.journal_line_id = x.id
                                 WHERE x.account_id = l.account_id AND x.journal_id IN (o.id, r.id))",
            [(int) $account_id, $date, $date]
        )->result_array() as $r) $out[(int) $r['line_id']] = TRUE;
        return $out;
    }

    public static function book_item(array $r)
    {
        return [
            'line_id'     => (int) $r['line_id'],
            'journal_id'  => (int) $r['journal_id'],
            'journal_no'  => $r['journal_no'],
            'date'        => $r['entry_date'],
            'book'        => $r['book'] ?? NULL,
            'source'      => $r['source'] ?? NULL,
            'reference'   => $r['reference'],
            'party'       => $r['party_name'] ?? NULL,
            'description' => $r['description'],
            'memo'        => $r['memo'],
            'net_cents'   => (int) $r['net_cents'],
        ];
    }

    /**
     * Book lines that can still be matched on this statement: posted lines on
     * the bank's ledger account, matched to no bank line, dated on or before
     * the statement date plus the matching window. Opening-balance entries and
     * reversed pairs are left out.
     */
    public function pool(array $st, array $bank)
    {
        $until = self::add_days($st['statement_date'], $this->window_days());
        $void  = $this->void_lines((int) $bank['account_id']);
        $out   = [];
        foreach ($this->db->query(
            "SELECT l.line_id, l.journal_id, l.journal_no, l.entry_date, l.book, l.source, l.reference, l.party_name, l.description, l.memo, l.net_cents
               FROM gp_ledger l
              WHERE l.account_id = ? AND l.entry_date <= ? AND l.book <> 'opening'
                AND NOT EXISTS (SELECT 1 FROM gp_bank_matches m WHERE m.journal_line_id = l.line_id)
              ORDER BY l.entry_date, l.journal_id, l.line_no",
            [(int) $bank['account_id'], $until]
        )->result_array() as $r) {
            if ( ! isset($void[(int) $r['line_id']])) $out[] = self::book_item($r);
        }
        return $out;
    }

    /** [bank line id => the reason given when it was last ignored] */
    public function ignore_reasons(array $line_ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $line_ids))));
        if ( ! $ids) return [];
        $out = [];
        foreach ($this->db->query(
            "SELECT target_id, detail FROM gp_admin_audit_log
              WHERE target_type = 'bank_line' AND action = 'bank_line.ignore' AND target_id IN (" . implode(',', $ids) . ') ORDER BY id'
        )->result_array() as $a) {
            $d = json_decode((string) $a['detail'], TRUE);
            $out[(int) $a['target_id']] = is_array($d) ? (string) ($d['reason'] ?? '') : '';
        }
        return $out;
    }

    /** The statement's lines, each with the book lines it is matched to, its recorded entry and why it was ignored. */
    public function lines(array $st)
    {
        $rows = $this->db->query('SELECT * FROM gp_bank_lines WHERE statement_id = ? ORDER BY txn_date, id', [(int) $st['id']])->result_array();
        if ( ! $rows) return [];
        $ids = array_map(function ($r) { return (int) $r['id']; }, $rows);

        $matches = [];
        $who     = [];
        foreach ($this->db->query(
            'SELECT m.bank_line_id, m.matched_by, m.matched_at, l.line_id, l.journal_id, l.journal_no, l.entry_date, l.book, l.source,
                    l.reference, l.party_name, l.description, l.memo, l.net_cents
               FROM gp_bank_matches m JOIN gp_ledger l ON l.line_id = m.journal_line_id
              WHERE m.bank_line_id IN (' . implode(',', $ids) . ')
              ORDER BY l.entry_date, l.journal_id, l.line_no'
        )->result_array() as $m) {
            $matches[(int) $m['bank_line_id']][] = self::book_item($m);
            $who[(int) $m['bank_line_id']] = [(int) $m['matched_by'], $m['matched_at']];
        }
        $names = $this->names(array_map(function ($w) { return $w[0]; }, $who));
        $jids  = array_values(array_filter(array_map(function ($r) { return (int) $r['journal_id']; }, $rows)));
        $jnos  = [];
        if ($jids) foreach ($this->db->select('id, journal_no')->where_in('id', $jids)->get('gp_journals')->result_array() as $j) $jnos[(int) $j['id']] = $j['journal_no'];
        $why = $this->ignore_reasons(array_map(function ($r) { return $r['status'] === 'ignored' ? (int) $r['id'] : 0; }, $rows));

        return array_map(function ($r) use ($matches, $who, $names, $jnos, $why) {
            $id = (int) $r['id'];
            $w  = $who[$id] ?? NULL;
            return [
                'id'              => $id,
                'txn_date'        => $r['txn_date'],
                'description'     => $r['description'],
                'reference'       => $r['reference'],
                'amount_cents'    => (int) $r['amount_cents'],
                'status'          => $r['status'],
                'journal_id'      => $r['journal_id'] !== NULL ? (int) $r['journal_id'] : NULL,
                'journal_no'      => $r['journal_id'] !== NULL ? ($jnos[(int) $r['journal_id']] ?? NULL) : NULL,
                'matches'         => $matches[$id] ?? [],
                'matched_by_name' => $w ? ($names[$w[0]] ?? NULL) : NULL,
                'matched_at'      => $w ? $w[1] : NULL,
                'ignore_reason'   => $r['status'] === 'ignored' ? ($why[$id] ?? '') : NULL,
            ];
        }, $rows);
    }

    // =========================================================================
    // THE RECONCILIATION
    // =========================================================================

    /**
     * The bank reconciliation as of statement $st (see the header). For a
     * reconciled statement it is computed as of that statement: book lines
     * matched on later statements count as outstanding.
     *
     * @param bool $items include the lists behind each figure
     */
    public function reconciliation(array $st, $items = TRUE)
    {
        $bank  = $this->account((int) $st['bank_account_id']);
        $acct  = (int) $bank['account_id'];
        $bid   = (int) $st['bank_account_id'];
        $D     = $st['statement_date'];
        $from  = $this->period_from($st);
        $start = $this->recon_start($bid) ?: $from;
        $prev  = $this->previous_statement($st);

        /* The statement's own lines: unmatched ones are the memos the books do not have yet. */
        $n = ['lines' => 0, 'matched' => 0, 'unmatched' => 0, 'ignored' => 0];
        $total = 0;
        $cm = [];
        $dm = [];
        foreach ($this->db->query('SELECT id, txn_date, description, reference, amount_cents, status FROM gp_bank_lines WHERE statement_id = ? ORDER BY txn_date, id',
                                  [(int) $st['id']])->result_array() as $l) {
            $a = (int) $l['amount_cents'];
            $total += $a;
            $n['lines']++;
            $n[$l['status']]++;
            if ($l['status'] !== 'unmatched') continue;
            $it = ['bank_line_id' => (int) $l['id'], 'date' => $l['txn_date'], 'reference' => $l['reference'], 'description' => $l['description'], 'amount_cents' => abs($a)];
            if ($a > 0) $cm[] = $it; else $dm[] = $it;
        }

        /* Bank errors: lines set aside on this and earlier statements, until the bank corrects them. */
        $errs = [];
        foreach ($this->db->query(
            "SELECT b.id, b.txn_date, b.description, b.reference, b.amount_cents, s.statement_date
               FROM gp_bank_lines b JOIN gp_bank_statements s ON s.id = b.statement_id
              WHERE s.bank_account_id = ? AND s.statement_date <= ? AND b.status = 'ignored'
              ORDER BY s.statement_date, b.txn_date, b.id", [$bid, $D]
        )->result_array() as $r) {
            $errs[] = ['bank_line_id' => (int) $r['id'], 'date' => $r['txn_date'], 'reference' => $r['reference'], 'description' => $r['description'],
                       'amount_cents' => -(int) $r['amount_cents'], 'statement_date' => $r['statement_date']];
        }
        $why = $this->ignore_reasons(array_column($errs, 'bank_line_id'));
        foreach ($errs as &$e) $e['reason'] = $why[$e['bank_line_id']] ?? '';
        unset($e);

        /* Book lines not cleared by this or an earlier statement: in transit, or outstanding. */
        $void = $this->void_lines($acct, $D);
        $dit  = [];
        $oc   = [];
        foreach ($this->db->query(
            "SELECT l.line_id, l.journal_id, l.journal_no, l.entry_date, l.book, l.source, l.reference, l.party_name, l.description, l.memo, l.net_cents
               FROM gp_ledger l
              WHERE l.account_id = ? AND l.entry_date BETWEEN ? AND ? AND l.book <> 'opening'
                AND NOT EXISTS (SELECT 1 FROM gp_bank_matches m
                                  JOIN gp_bank_lines b ON b.id = m.bank_line_id
                                  JOIN gp_bank_statements s ON s.id = b.statement_id
                                 WHERE m.journal_line_id = l.line_id AND s.bank_account_id = ? AND s.statement_date <= ?)
              ORDER BY l.entry_date, l.journal_id, l.line_no", [$acct, $start, $D, $bid, $D]
        )->result_array() as $r) {
            if (isset($void[(int) $r['line_id']])) continue;
            $it = self::book_item($r);
            $it['amount_cents'] = abs($it['net_cents']);
            if ($it['net_cents'] > 0) $dit[] = $it; else $oc[] = $it;
        }

        /* The bank has these by the statement date; the books date them later. */
        $late = [];
        foreach ($this->db->query(
            'SELECT l.line_id, l.journal_id, l.journal_no, l.entry_date, l.book, l.source, l.reference, l.party_name, l.description, l.memo, l.net_cents, m.bank_line_id
               FROM gp_bank_matches m JOIN gp_bank_lines b ON b.id = m.bank_line_id JOIN gp_ledger l ON l.line_id = m.journal_line_id
              WHERE b.statement_id = ? AND l.entry_date > ?
              ORDER BY l.entry_date, l.journal_id, l.line_no', [(int) $st['id'], $D]
        )->result_array() as $r) {
            $it = self::book_item($r);
            $it['amount_cents'] = $it['net_cents'];
            $it['bank_line_id'] = (int) $r['bank_line_id'];
            $late[] = $it;
        }

        $sum     = function (array $xs) { return array_sum(array_column($xs, 'amount_cents')); };
        $opening = (int) $st['opening_balance_cents'];
        $closing = (int) $st['closing_balance_cents'];
        $books   = $this->book_balance($acct, $D);
        $bank_side = [
            'closing_cents'             => $closing,
            'deposits_in_transit_cents' => $sum($dit),
            'outstanding_cheques_cents' => $sum($oc),
            'bank_errors_cents'         => $sum($errs),
        ];
        $bank_side['adjusted_cents'] = $closing + $bank_side['deposits_in_transit_cents'] - $bank_side['outstanding_cheques_cents'] + $bank_side['bank_errors_cents'];
        $book_side = [
            'balance_cents'      => $books,
            'credit_memos_cents' => $sum($cm),
            'debit_memos_cents'  => $sum($dm),
            'booked_later_cents' => $sum($late),
        ];
        $book_side['adjusted_cents'] = $books + $book_side['credit_memos_cents'] - $book_side['debit_memos_cents'] + $book_side['booked_later_cents'];

        $checks = [
            'lines_total_cents'      => $total,
            'expected_closing_cents' => $opening + $total,
            'adds_up'                => $opening + $total === $closing,
            'first'                  => ! $prev,
            'previous'               => $prev ? ['id' => (int) $prev['id'], 'statement_date' => $prev['statement_date'],
                                                 'closing_balance_cents' => (int) $prev['closing_balance_cents'], 'status' => $prev['status']] : NULL,
            'opening_agrees'         => $prev ? $opening === (int) $prev['closing_balance_cents'] : NULL,
        ];
        if ( ! $prev) {
            /* Where the first statement starts: the books the day before, plus any opening-balance entry in its period. */
            $before = $this->book_balance($acct, self::add_days($start, -1));
            $ob = (int) $this->db->query("SELECT COALESCE(SUM(net_cents), 0) AS b FROM gp_ledger WHERE account_id = ? AND book = 'opening' AND entry_date BETWEEN ? AND ?",
                                         [$acct, $start, $D])->row()->b;
            $checks['books_at_start_date']  = self::add_days($start, -1);
            $checks['books_at_start_cents'] = $before + $ob;
            $checks['opening_agrees_books'] = $opening === $before + $ob;
        }

        $out = [
            'as_of'            => $D,
            'period_from'      => $from,
            'recon_start'      => $start,
            'counts'           => $n,
            'bank_side'        => $bank_side,
            'book_side'        => $book_side,
            'difference_cents' => $bank_side['adjusted_cents'] - $book_side['adjusted_cents'],
            'checks'           => $checks,
        ];
        if ($items) {
            $out['items'] = ['deposits_in_transit' => $dit, 'outstanding_cheques' => $oc, 'bank_errors' => $errs,
                             'credit_memos' => $cm, 'debit_memos' => $dm, 'booked_later' => $late];
        }
        return $out;
    }

    /** Why this statement cannot be reconciled yet; empty when it can. */
    public function reconcile_blockers(array $st, array $r)
    {
        if ($st['status'] !== 'open') return ['This statement is already reconciled.'];
        $out = [];
        $earlier = $this->db->query("SELECT statement_date FROM gp_bank_statements WHERE bank_account_id = ? AND statement_date < ? AND status <> 'reconciled'
                                      ORDER BY statement_date LIMIT 1", [(int) $st['bank_account_id'], $st['statement_date']])->row_array();
        if ($earlier) $out[] = 'Reconcile the statement of ' . $earlier['statement_date'] . ' first: statements are reconciled in date order.';
        $u = (int) $r['counts']['unmatched'];
        if ($u > 0) {
            $out[] = $u . ' bank line' . ($u === 1 ? ' is' : 's are') . ' not matched or set aside yet. Match ' . ($u === 1 ? 'it' : 'them')
                   . ', record an entry for ' . ($u === 1 ? 'it' : 'them') . ', or ignore ' . ($u === 1 ? 'it' : 'them') . ' with a reason.';
        }
        if ( ! $r['checks']['adds_up']) {
            $out[] = 'The opening balance and the lines add up to ' . money_format_cents($r['checks']['expected_closing_cents'])
                   . ', but the statement says ' . money_format_cents((int) $st['closing_balance_cents']) . '. Add the missing lines or correct the closing balance.';
        } elseif ($r['difference_cents'] !== 0) {
            $out[] = 'The reconciliation is off by ' . money_format_cents(abs($r['difference_cents'])) . ': the adjusted bank balance is '
                   . money_format_cents($r['bank_side']['adjusted_cents']) . ' and the adjusted book balance is ' . money_format_cents($r['book_side']['adjusted_cents']) . '.';
        }
        return $out;
    }

    // =========================================================================
    // FOR THE SCREENS
    // =========================================================================

    /** The final tax withheld on bank interest, in basis points (Settings, else 20 %). */
    public function final_tax_bp()
    {
        $bp = pct_to_bp((string) shop_cfg('bank_final_tax_pct', '20'));
        return ($bp !== NULL && $bp > 0 && $bp < 10000) ? $bp : 2000;
    }

    /** An account id from a Settings key that holds an account code, if it is postable and active. */
    public function default_account_id($key)
    {
        $code = trim((string) shop_cfg($key, ''));
        if ($code === '') return NULL;
        $r = $this->db->select('id')->get_where('gp_accounts', ['code' => $code, 'is_header' => 0, 'is_active' => 1], 1)->row_array();
        return $r ? (int) $r['id'] : NULL;
    }

    /** What the "record an entry" form offers. */
    public function entry_lookups(array $bank)
    {
        $accounts = array_map(function ($a) {
            return ['id' => (int) $a['id'], 'code' => $a['code'], 'name' => $a['name'], 'type' => $a['type'], 'needs_department' => (bool) (int) $a['requires_department']];
        }, $this->db->query('SELECT id, code, name, type, requires_department FROM gp_accounts
                              WHERE is_header = 0 AND is_active = 1 AND control IS NULL AND id <> ? ORDER BY sort_order, code', [(int) $bank['account_id']])->result_array());
        $departments = array_map(function ($d) { return ['id' => (int) $d['id'], 'code' => $d['code'], 'name' => $d['name']]; },
            $this->db->select('id, code, name')->where('is_active', 1)->order_by('code')->get('gp_departments')->result_array());
        return [
            'accounts'            => $accounts,
            'departments'         => $departments,
            'default_charges_id'  => $this->default_account_id('acct_bank_charges'),
            'default_interest_id' => $this->default_account_id('acct_interest_income'),
            'final_tax_bp'        => $this->final_tax_bp(),
        ];
    }

    /** Everything the statement screen shows, and what this person may do with it. */
    public function detail($id, array $claims)
    {
        $st = $this->statement($id);
        if ( ! $st) return NULL;
        $bank   = $this->account((int) $st['bank_account_id']);
        $counts = $this->line_counts([(int) $st['id']]);
        $names  = $this->names([$st['created_by'], $st['reconciled_by']]);
        $r      = $this->reconciliation($st, FALSE);
        $rank   = role_rank($claims['role']);
        $open   = $st['status'] === 'open';
        $shaped = $this->shape_statement($st, $counts[(int) $st['id']] ?? NULL, $names);
        $recorded = (int) $this->db->query('SELECT COUNT(*) AS n FROM gp_bank_lines WHERE statement_id = ? AND journal_id IS NOT NULL', [(int) $st['id']])->row()->n;

        return [
            'statement'          => $shaped + ['period_from' => $r['period_from'], 'recon_start' => $r['recon_start']],
            'bank'               => $this->shape_account($bank),
            'lines'              => $this->lines($st),
            'book_lines'         => $open ? $this->pool($st, $bank) : [],
            'figures'            => $r,
            'reconcile_blockers' => $open ? $this->reconcile_blockers($st, $r) : [],
            'window_days'        => $this->window_days(),
            'date_range'         => $this->line_date_range($st),
            'max_lines'          => self::MAX_LINES,
            'entry'              => $open && $rank >= 3 ? $this->entry_lookups($bank) : NULL,
            'can'                => [
                'edit'      => $rank >= 2 && $open,
                'match'     => $rank >= 2 && $open,
                'record'    => $rank >= 3 && $open,
                'reconcile' => $rank >= 3 && $open,
                'reopen'    => $rank >= 3 && $this->is_latest_reconciled($st),
                'delete'    => $rank >= 2 && $open && $shaped['matched_count'] === 0 && $recorded === 0,
            ],
        ];
    }
}
