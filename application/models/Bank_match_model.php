<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Bank_match_model.php — bank lines against the books: matching, entries made
 * from bank lines, and reconciling a statement
 *
 * GenericPOS Accounting · banking module
 *
 *   match()        one bank line ↔ one or more book lines whose net equals it
 *   unmatch()      take a match apart (the statement must be open)
 *   ignore()       set a bank line aside with a reason (a bank error), unignore() brings it back
 *   auto_match()   every line with exactly one candidate of the same amount
 *   record()       post the entry the books do not have yet (charges, interest) and match it
 *   undo_record()  reverse that entry and unmatch the line
 *   reconcile(), reopen()
 *
 * ─── AUTO-MATCH ───────────────────────────────────────────────────────────
 * A bank line and a book line pair up when each is the other's ONLY candidate:
 * the same amount, dated at most bank_match_window_days apart. Where there are
 * several candidates, only those whose reference agrees (the same cheque or OR
 * number) are kept, and the pairing must still be unique both ways. Passes
 * repeat until nothing more pairs up. Then a deposit left over matches ALL the
 * unmatched book debits of one date when they add up to it (a deposit of
 * several receipts) — again only when that is the one way to read it.
 *
 * ─── ENTRIES FROM BANK LINES (source 'bank', source_id = the bank line) ──
 *   a charge (−)   cash_disbursements   Dr bank charges (or the chosen account)   Cr the bank
 *   a credit (+)   cash_receipts        Dr the bank                               Cr interest income (or chosen)
 *                  with final tax       Dr the bank (net) · Dr tax account (tax)  Cr income (gross)
 * Dated on the bank line's date; the entry's line on the bank account is
 * matched at once, in the same transaction as the posting.
 */
class Bank_match_model extends CI_Model
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Bank_model', 'bank');
    }

    // =========================================================================
    // MATCHING BY HAND
    // =========================================================================

    /** The chosen book lines, if every one of them may be matched on $st; otherwise a refusal. */
    private function _book_lines(array $st, array $bank, array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ( ! $ids)            throw new DomainException('Choose the book lines that make up this bank line.');
        if (count($ids) > 200)  throw new DomainException('Match at most 200 book lines to one bank line.');

        $found = [];
        foreach ($this->db->query(
            'SELECT l.line_id, l.journal_id, l.journal_no, l.entry_date, l.book, l.source, l.reference, l.party_name, l.description, l.memo,
                    l.net_cents, l.account_id,
                    (SELECT m.bank_line_id FROM gp_bank_matches m WHERE m.journal_line_id = l.line_id LIMIT 1) AS matched_to
               FROM gp_ledger l WHERE l.line_id IN (' . implode(',', $ids) . ')'
        )->result_array() as $r) $found[(int) $r['line_id']] = $r;

        $win   = $this->bank->window_days();
        $until = Bank_model::add_days($st['statement_date'], $win);
        $void  = $this->bank->void_lines((int) $bank['account_id']);
        $out   = [];
        foreach ($ids as $id) {
            $r = $found[$id] ?? NULL;
            if ( ! $r) throw new DomainException('A chosen book line is not part of a posted entry.');
            $what = 'The line of ' . ($r['journal_no'] ?: 'entry #' . $r['journal_id']);
            if ((int) $r['account_id'] !== (int) $bank['account_id']) {
                throw new DomainException($what . ' is not on ' . $bank['ledger_code'] . ' ' . $bank['ledger_name'] . ', this bank\'s ledger account.');
            }
            if ($r['matched_to'] !== NULL)  throw new DomainException($what . ' is already matched to a bank line. A book line clears only once.');
            if ($r['book'] === 'opening')   throw new DomainException($what . ' is an opening-balance entry, not a bank transaction.');
            if (isset($void[$id]))          throw new DomainException($what . ' belongs to a reversed entry, so it never reaches the bank.');
            if ($r['entry_date'] > $until)  throw new DomainException($what . ' is dated ' . $r['entry_date'] . ', more than ' . $win . ' days after the statement date.');
            $out[] = $r;
        }
        return $out;
    }

    private function _insert_matches($bank_line_id, array $journal_line_ids, $user_id)
    {
        $now = date('Y-m-d H:i:s');
        foreach ($journal_line_ids as $jl) {
            $this->db->insert('gp_bank_matches', ['bank_line_id' => (int) $bank_line_id, 'journal_line_id' => (int) $jl,
                                                  'matched_by' => (int) $user_id, 'matched_at' => $now]);
        }
    }

    /**
     * Match a bank line to book lines: a deposit to debits, a withdrawal to
     * credits, adding up exactly. @return array [message, error]
     */
    public function match($line_id, array $journal_line_ids, array $claims)
    {
        return $this->bank->tx(function () use ($line_id, $journal_line_ids, $claims) {
            list($st, $l) = $this->bank->lock_line($line_id);
            if ($l['status'] === 'matched') throw new DomainException('This bank line is already matched. Unmatch it first to change what it matches.');
            if ($l['status'] === 'ignored') throw new DomainException('This bank line is set aside. Bring it back before matching it.');

            $bank = $this->bank->account((int) $st['bank_account_id']);
            $rows = $this->_book_lines($st, $bank, $journal_line_ids);
            $amt  = (int) $l['amount_cents'];
            $sum  = 0;
            foreach ($rows as $r) {
                $net = (int) $r['net_cents'];
                if (($amt > 0) !== ($net > 0)) {
                    throw new DomainException($amt > 0
                        ? 'A deposit matches book debits (money into the bank); the line of ' . $r['journal_no'] . ' is a credit.'
                        : 'A withdrawal matches book credits (money out of the bank); the line of ' . $r['journal_no'] . ' is a debit.');
                }
                $sum += $net;
            }
            if ($sum !== $amt) {
                throw new DomainException('The book lines add up to ' . money_format_cents($sum) . ' but the bank line is ' . money_format_cents($amt)
                    . '. Choose lines that add up to exactly ' . money_format_cents($amt) . '.');
            }

            $ids = array_map(function ($r) { return (int) $r['line_id']; }, $rows);
            $this->_insert_matches((int) $l['id'], $ids, (int) $claims['user_id']);
            $this->db->where('id', (int) $l['id'])->update('gp_bank_lines', ['status' => 'matched']);
            $this->bank->audit($claims, 'bank_line.match', 'bank_line', (int) $l['id'], [
                'statement_id' => (int) $st['id'], 'amount_cents' => $amt, 'journal_line_ids' => $ids,
                'journals' => array_values(array_unique(array_column($rows, 'journal_no'))),
            ]);
            return count($rows) === 1 ? 'Matched to ' . $rows[0]['journal_no'] . '.' : 'Matched to ' . count($rows) . ' book lines.';
        });
    }

    /** @return array [message, error] */
    public function unmatch($line_id, array $claims)
    {
        return $this->bank->tx(function () use ($line_id, $claims) {
            list($st, $l) = $this->bank->lock_line($line_id);
            if ($l['status'] !== 'matched') throw new DomainException('This bank line is not matched.');
            if ($l['journal_id'])           throw new DomainException('This line\'s entry was recorded from the statement. Undo the entry instead; that also unmatches it.');
            $ids = array_map('intval', array_column($this->db->select('journal_line_id')->get_where('gp_bank_matches', ['bank_line_id' => (int) $l['id']])->result_array(), 'journal_line_id'));
            $this->db->where('bank_line_id', (int) $l['id'])->delete('gp_bank_matches');
            $this->db->where('id', (int) $l['id'])->update('gp_bank_lines', ['status' => 'unmatched']);
            $this->bank->audit($claims, 'bank_line.unmatch', 'bank_line', (int) $l['id'], ['statement_id' => (int) $st['id'], 'journal_line_ids' => $ids]);
            return 'Unmatched.';
        });
    }

    /** Set a line aside (a bank error), with the reason kept in the audit trail. @return array [message, error] */
    public function ignore($line_id, $reason, array $claims)
    {
        $reason = clean_line($reason, 400);
        if ($reason === '')           return [NULL, 'Say why this line is set aside (for example: a bank error the bank reversed).'];
        if (mb_strlen($reason) > 300) return [NULL, 'Keep the reason under 300 characters.'];
        return $this->bank->tx(function () use ($line_id, $reason, $claims) {
            list($st, $l) = $this->bank->lock_line($line_id);
            if ($l['status'] === 'ignored') throw new DomainException('This line is already set aside.');
            if ($l['status'] !== 'unmatched') throw new DomainException('Only an unmatched line can be set aside. Unmatch it first.');
            $this->db->where('id', (int) $l['id'])->update('gp_bank_lines', ['status' => 'ignored']);
            $this->bank->audit($claims, 'bank_line.ignore', 'bank_line', (int) $l['id'], [
                'statement_id' => (int) $st['id'], 'amount_cents' => (int) $l['amount_cents'], 'reason' => $reason,
            ]);
            return 'Line set aside.';
        });
    }

    /** @return array [message, error] */
    public function unignore($line_id, array $claims)
    {
        return $this->bank->tx(function () use ($line_id, $claims) {
            list($st, $l) = $this->bank->lock_line($line_id);
            if ($l['status'] !== 'ignored') throw new DomainException('This line is not set aside.');
            $this->db->where('id', (int) $l['id'])->update('gp_bank_lines', ['status' => 'unmatched']);
            $this->bank->audit($claims, 'bank_line.unignore', 'bank_line', (int) $l['id'], ['statement_id' => (int) $st['id']]);
            return 'Line brought back.';
        });
    }

    // =========================================================================
    // AUTO-MATCH
    // =========================================================================

    /** @return array [['matched' => n, 'of' => m, 'pairs' => [bank line id => journal line ids]], error] */
    public function auto_match($statement_id, array $claims)
    {
        return $this->bank->tx(function () use ($statement_id, $claims) {
            $st   = $this->bank->lock_open_statement($statement_id);
            $bank = $this->bank->account((int) $st['bank_account_id']);
            $win  = $this->bank->window_days();

            $lines = [];
            foreach ($this->db->query("SELECT id, txn_date, reference, amount_cents FROM gp_bank_lines
                                        WHERE statement_id = ? AND status = 'unmatched' ORDER BY txn_date, id FOR UPDATE", [(int) $st['id']])->result_array() as $r) {
                $lines[(int) $r['id']] = ['date' => $r['txn_date'], 'ref' => (string) $r['reference'], 'amt' => (int) $r['amount_cents']];
            }
            $pool = [];
            foreach ($this->bank->pool($st, $bank) as $p) $pool[$p['line_id']] = $p;

            $pairs = self::pair($lines, $pool, $win);
            foreach ($pairs as $bid => $ids) {
                $this->_insert_matches($bid, $ids, (int) $claims['user_id']);
                $this->db->where('id', (int) $bid)->update('gp_bank_lines', ['status' => 'matched']);
            }
            if ($pairs) {
                $this->bank->audit($claims, 'bank_statement.auto_match', 'bank_statement', (int) $st['id'], [
                    'matched' => count($pairs), 'of' => count($lines), 'window_days' => $win, 'bank_line_ids' => array_keys($pairs),
                ]);
            }
            return ['matched' => count($pairs), 'of' => count($lines), 'pairs' => $pairs];
        });
    }

    /**
     * The pairing itself, without side effects.
     *
     * @param array $bank  [bank line id => [date, ref, amt]]  unmatched bank lines
     * @param array $pool  [journal line id => book item]      unmatched book lines
     * @return array [bank line id => [journal line ids]]
     */
    public static function pair(array $bank, array $pool, $win)
    {
        $near  = function ($a, $b) use ($win) { return abs(Bank_model::days_between($a, $b)) <= $win; };
        $pairs = [];

        for ($pass = 0; $pass < 25 && $bank && $pool; $pass++) {
            $cb = [];
            $cl = [];
            foreach ($bank as $bid => $b) {
                foreach ($pool as $lid => $l) {
                    if ($l['net_cents'] !== $b['amt'] || ! $near($b['date'], $l['date'])) continue;
                    $cb[$bid][] = $lid;
                    $cl[$lid][] = $bid;
                }
            }
            $found = FALSE;
            foreach ($cb as $bid => $cands) {
                if ( ! isset($bank[$bid])) continue;
                $cands = array_values(array_filter($cands, function ($lid) use ($pool) { return isset($pool[$lid]); }));
                if (count($cands) > 1) {
                    $cands = array_values(array_filter($cands, function ($lid) use ($bank, $bid, $pool) { return self::refs_agree($bank[$bid]['ref'], $pool[$lid]); }));
                }
                if (count($cands) !== 1) continue;
                $lid  = $cands[0];
                $back = array_values(array_filter($cl[$lid], function ($x) use ($bank) { return isset($bank[$x]); }));
                if (count($back) > 1) {
                    $back = array_values(array_filter($back, function ($x) use ($bank, $lid, $pool) { return self::refs_agree($bank[$x]['ref'], $pool[$lid]); }));
                }
                if (count($back) !== 1 || $back[0] !== $bid) continue;
                $pairs[$bid] = [$lid];
                unset($bank[$bid], $pool[$lid]);
                $found = TRUE;
            }
            if ( ! $found) break;
        }

        /* A deposit of several receipts: all the unmatched book debits of one date, adding up to it. */
        $days = [];
        foreach ($pool as $lid => $l) if ($l['net_cents'] > 0) $days[$l['date']][] = $lid;
        $sums = [];
        foreach ($days as $d => $ids) {
            if (count($ids) < 2) continue;
            $sums[$d] = array_sum(array_map(function ($lid) use ($pool) { return $pool[$lid]['net_cents']; }, $ids));
        }
        foreach ($bank as $bid => $b) {
            if ($b['amt'] <= 0 || isset($pairs[$bid])) continue;
            $hits = [];
            foreach ($sums as $d => $s) if ($s === $b['amt'] && $near($b['date'], $d)) $hits[] = $d;
            if (count($hits) !== 1) continue;
            $d = $hits[0];
            $rivals = 0;
            foreach ($bank as $x => $o) if ($x !== $bid && ! isset($pairs[$x]) && $o['amt'] === $b['amt'] && $near($o['date'], $d)) $rivals++;
            if ($rivals) continue;
            $pairs[$bid] = $days[$d];
            unset($sums[$d]);
        }
        return $pairs;
    }

    /** Letters and digits only, in capitals: "CHK 500127" → "CHK500127". */
    public static function ref_key($s)
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $s));
    }

    /** The longest run of digits, when it is long enough to be a cheque number. */
    private static function _long_digits($s)
    {
        preg_match_all('/\d+/', (string) $s, $m);
        $best = '';
        foreach ($m[0] as $d) if (strlen($d) > strlen($best)) $best = $d;
        return strlen($best) >= 5 ? ltrim($best, '0') : '';
    }

    /** Does a bank line's reference agree with a book line's reference or memo (the same cheque or OR number)? */
    public static function refs_agree($bank_ref, array $book)
    {
        $b = self::ref_key($bank_ref);
        if ($b === '') return FALSE;
        $bd = self::_long_digits($bank_ref);
        foreach ([$book['reference'] ?? '', $book['memo'] ?? ''] as $x) {
            $k = self::ref_key($x);
            if ($k === '') continue;
            if ($k === $b) return TRUE;
            if ($bd !== '' && $bd === self::_long_digits($x)) return TRUE;
        }
        return FALSE;
    }

    // =========================================================================
    // ENTRIES FROM BANK LINES
    // =========================================================================

    /** $n / $d rounded half away from zero, in integers. */
    public static function div_round($n, $d)
    {
        $q = intdiv(abs($n) * 2 + abs($d), 2 * abs($d));
        return (($n < 0) xor ($d < 0)) ? -$q : $q;
    }

    /**
     * Post the entry for a bank line the books do not have yet, and match it.
     *
     * @param array $in  account_id, department_id, description, final_tax (bool),
     *                   final_tax_account_id, final_tax_cents (else the Settings rate grossed up)
     * @return array [['journal_id', 'journal_no'], error]
     */
    public function record($line_id, array $in, array $claims)
    {
        $this->load->model('Journal_model', 'journals');
        return $this->bank->tx(function () use ($line_id, $in, $claims) {
            list($st, $l) = $this->bank->lock_line($line_id);
            if ($l['journal_id'])             throw new DomainException('An entry has already been recorded for this line.');
            if ($l['status'] === 'matched')   throw new DomainException('This line is already matched to the books, so there is nothing to record.');
            if ($l['status'] !== 'unmatched') throw new DomainException('This line is set aside. Bring it back before recording an entry for it.');

            $bank = $this->bank->account((int) $st['bank_account_id']);
            $amt  = (int) $l['amount_cents'];
            $cash = (int) $bank['account_id'];
            $key  = $amt < 0 ? 'acct_bank_charges' : 'acct_interest_income';
            $other = (int) ($in['account_id'] ?? 0) ?: (int) $this->bank->default_account_id($key);
            if ( ! $other)        throw new DomainException($amt < 0 ? 'Choose the account to charge: Settings names no bank charges account.' : 'Choose the income account: Settings names no interest income account.');
            if ($other === $cash) throw new DomainException('Choose an account other than the bank\'s own ledger account.');
            $dept = (int) ($in['department_id'] ?? 0) ?: NULL;

            $tax = 0;
            $tax_acct = 0;
            if ( ! empty($in['final_tax'])) {
                if ($amt < 0) throw new DomainException('Final tax applies to interest credited (a deposit), not to a withdrawal.');
                $tax_acct = (int) ($in['final_tax_account_id'] ?? 0);
                if ( ! $tax_acct) throw new DomainException('Choose the account for the final tax withheld.');
                if ($tax_acct === $cash || $tax_acct === $other) throw new DomainException('Choose a separate account for the final tax withheld.');
                $given = $in['final_tax_cents'] ?? NULL;
                if ($given === NULL || $given === '') {
                    $bp  = $this->bank->final_tax_bp();
                    $tax = self::div_round($amt * 10000, 10000 - $bp) - $amt;   // the interest before tax, less what the bank paid
                } else {
                    $tax = Bank_model::cents_in($given);
                    if ($tax === NULL || $tax <= 0) throw new DomainException('Enter the final tax withheld as a positive amount.');
                }
            }

            $what = $l['description'] !== '' ? $l['description'] : ($amt < 0 ? 'Bank charge' : 'Bank credit');
            $memo = mb_substr($what . ($l['reference'] ? ' · ' . $l['reference'] : ''), 0, 255);
            $desc = clean_line($in['description'] ?? '', 600);
            if ($desc === '') $desc = ($amt < 0 ? 'Bank charge' : 'Bank credit') . ' per the ' . $bank['bank_name'] . ' statement of ' . $st['statement_date'] . ': ' . $what;
            $desc = mb_substr($desc, 0, 500);

            if ($amt < 0) {
                $book  = 'cash_disbursements';
                $lines = [
                    ['account_id' => $other, 'debit_cents' => -$amt, 'credit_cents' => 0, 'memo' => $memo, 'department_id' => $dept],
                    ['account_id' => $cash,  'debit_cents' => 0, 'credit_cents' => -$amt, 'memo' => $memo],
                ];
            } else {
                $book  = 'cash_receipts';
                $lines = [['account_id' => $cash, 'debit_cents' => $amt, 'credit_cents' => 0, 'memo' => $memo]];
                if ($tax > 0) $lines[] = ['account_id' => $tax_acct, 'debit_cents' => $tax, 'credit_cents' => 0, 'memo' => 'Final tax withheld by the bank', 'department_id' => $dept];
                $lines[] = ['account_id' => $other, 'debit_cents' => 0, 'credit_cents' => $amt + $tax, 'memo' => $tax > 0 ? 'Interest before final tax' : $memo, 'department_id' => $dept];
            }
            $head = ['book' => $book, 'entry_date' => $l['txn_date'], 'reference' => $l['reference'], 'party_name' => $bank['bank_name'], 'description' => $desc];

            list($jid, $errs) = $this->journals->post_system($head, $lines, (int) $claims['user_id'], 'bank', (int) $l['id']);
            if ( ! $jid) throw new DomainException((string) reset($errs));
            $cl = $this->db->select('id')->get_where('gp_journal_lines', ['journal_id' => (int) $jid, 'account_id' => $cash])->result_array();
            if (count($cl) !== 1) throw new RuntimeException('The entry was not posted: its bank line could not be found.');

            $this->_insert_matches((int) $l['id'], [(int) $cl[0]['id']], (int) $claims['user_id']);
            $this->db->where('id', (int) $l['id'])->update('gp_bank_lines', ['status' => 'matched', 'journal_id' => (int) $jid]);
            $j = $this->journals->find($jid);
            $this->bank->audit($claims, 'bank_line.record', 'bank_line', (int) $l['id'], [
                'statement_id' => (int) $st['id'], 'journal_id' => (int) $jid, 'journal_no' => $j['journal_no'], 'book' => $book,
                'amount_cents' => $amt, 'account_id' => $other, 'final_tax_cents' => $tax,
            ]);
            return ['journal_id' => (int) $jid, 'journal_no' => $j['journal_no']];
        });
    }

    /** Reverse the entry recorded from a line and unmatch the line. @return array [info, error] */
    public function undo_record($line_id, $reason, array $claims)
    {
        $reason = clean_line($reason, 400);
        if ($reason === '')           return [NULL, 'Say why the entry is undone.'];
        if (mb_strlen($reason) > 250) return [NULL, 'Keep the reason under 250 characters.'];
        $this->load->model('Journal_model', 'journals');
        return $this->bank->tx(function () use ($line_id, $reason, $claims) {
            list($st, $l) = $this->bank->lock_line($line_id);
            if ( ! $l['journal_id']) throw new DomainException('No entry was recorded from this line.');
            $j = $this->journals->find((int) $l['journal_id']);
            if ( ! $j) throw new DomainException('The entry recorded from this line no longer exists.');

            /* Dated like the entry itself, so the books at the statement date are as they were. */
            list($rid, $err) = $this->journals->reverse_system((int) $j['id'], (int) $claims['user_id'], $j['entry_date'], 'Undone from the bank statement: ' . $reason, 'bank');
            if ( ! $rid) throw new DomainException($err);

            $this->db->where('bank_line_id', (int) $l['id'])->delete('gp_bank_matches');
            $this->db->where('id', (int) $l['id'])->update('gp_bank_lines', ['status' => 'unmatched', 'journal_id' => NULL]);
            $r = $this->journals->find($rid);
            $this->bank->audit($claims, 'bank_line.record_undo', 'bank_line', (int) $l['id'], [
                'statement_id' => (int) $st['id'], 'journal_id' => (int) $j['id'], 'journal_no' => $j['journal_no'],
                'reversal_id' => (int) $rid, 'reversal_no' => $r['journal_no'], 'reason' => $reason,
            ]);
            return ['journal_no' => $j['journal_no'], 'reversal_id' => (int) $rid, 'reversal_no' => $r['journal_no']];
        });
    }

    // =========================================================================
    // RECONCILE AND REOPEN
    // =========================================================================

    /** Lock every statement of a bank account (in id order), so reconciling and reopening see each other. */
    private function _lock_account_statements($statement_id)
    {
        $row = $this->db->select('bank_account_id')->get_where('gp_bank_statements', ['id' => (int) $statement_id], 1)->row_array();
        if ( ! $row) throw new DomainException('That statement no longer exists.');
        $this->db->query('SELECT id FROM gp_bank_statements WHERE bank_account_id = ? ORDER BY id FOR UPDATE', [(int) $row['bank_account_id']])->result_array();
    }

    /** Only when every line is matched or set aside and the difference is zero. @return array [figures, error] */
    public function reconcile($statement_id, array $claims)
    {
        return $this->bank->tx(function () use ($statement_id, $claims) {
            $this->_lock_account_statements($statement_id);
            $st = $this->bank->lock_open_statement($statement_id);
            $r  = $this->bank->reconciliation($st, FALSE);
            $b  = $this->bank->reconcile_blockers($st, $r);
            if ($b) throw new DomainException(implode(' ', array_slice($b, 0, 2)));

            $now = date('Y-m-d H:i:s');
            $this->db->where('id', (int) $st['id'])->update('gp_bank_statements', [
                'status' => 'reconciled', 'reconciled_by' => (int) $claims['user_id'], 'reconciled_at' => $now, 'updated_at' => $now,
            ]);
            $this->bank->audit($claims, 'bank_statement.reconcile', 'bank_statement', (int) $st['id'], [
                'statement_date' => $st['statement_date'], 'closing_cents' => (int) $st['closing_balance_cents'],
                'book_balance_cents' => $r['book_side']['balance_cents'], 'adjusted_cents' => $r['bank_side']['adjusted_cents'],
                'deposits_in_transit_cents' => $r['bank_side']['deposits_in_transit_cents'],
                'outstanding_cheques_cents' => $r['bank_side']['outstanding_cheques_cents'],
                'lines' => $r['counts']['lines'], 'ignored' => $r['counts']['ignored'],
            ]);
            return $r;
        });
    }

    /** Only the latest reconciled statement of its bank account. @return array [TRUE, error] */
    public function reopen($statement_id, $reason, array $claims)
    {
        $reason = clean_line($reason, 400);
        if ($reason === '')           return [NULL, 'Say why the statement is reopened.'];
        if (mb_strlen($reason) > 300) return [NULL, 'Keep the reason under 300 characters.'];
        return $this->bank->tx(function () use ($statement_id, $reason, $claims) {
            $this->_lock_account_statements($statement_id);
            $st = $this->bank->lock_statement($statement_id);
            if ($st['status'] !== 'reconciled') throw new DomainException('This statement is not reconciled.');
            if ( ! $this->bank->is_latest_reconciled($st)) {
                throw new DomainException('Only the latest reconciled statement of a bank account can be reopened. Reopen the later statements first.');
            }
            $this->db->where('id', (int) $st['id'])->update('gp_bank_statements', [
                'status' => 'open', 'reconciled_by' => NULL, 'reconciled_at' => NULL, 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $this->bank->audit($claims, 'bank_statement.reopen', 'bank_statement', (int) $st['id'], [
                'statement_date' => $st['statement_date'], 'reason' => $reason,
                'was_reconciled_by' => (int) $st['reconciled_by'], 'was_reconciled_at' => $st['reconciled_at'],
            ]);
            return TRUE;
        });
    }
}
