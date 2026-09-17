<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Integrity_lib.php — does everything still add up? (PLAN.md §6, the integrity report)
 *
 * GenericPOS Accounting · read-only
 *
 * Every check answers ok, warn or fail, with a count and up to LIMIT examples,
 * each linking to the screen where it is looked into. The rules the posting
 * engine enforces are checked again from the stored rows: a failure here means
 * something reached the tables by another road (an import, a hand-edited row,
 * a bug), which is exactly what this report is for.
 *
 * NOTE: nothing here changes anything. Fixing is done on the screens it links to.
 */
class Integrity_lib
{
    const LIMIT = 20;

    /** @var CI_Controller */
    private $CI;
    private $checks = [];

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->load->model('Ledger_model', 'ledger');
    }

    /** @return array ['checks' => [...], 'summary' => ['ok' => n, 'warn' => n, 'fail' => n], 'as_of' => date] */
    public function run()
    {
        $this->checks = [];
        $today = company_today();

        $this->_entries_balance();
        $this->_entries_lines();
        $this->_numbers();
        $this->_gaps();
        $this->_periods();
        $this->_posted_while_closed();
        $this->_reversals();
        $this->_control_contacts();
        $this->_sources();
        $this->_documents();
        $this->_settlements();
        $this->_bank();
        $this->_assets();
        $this->_audit();
        $this->_trial_balance($today);
        $this->_years();

        $sum = ['ok' => 0, 'warn' => 0, 'fail' => 0];
        foreach ($this->checks as $c) $sum[$c['status']]++;
        return ['checks' => $this->checks, 'summary' => $sum, 'as_of' => $today];
    }

    // =========================================================================
    // THE CHECKS
    // =========================================================================

    private function _entries_balance()
    {
        $rows = $this->_q(
            "SELECT j.id, j.journal_no, j.total_cents, SUM(l.debit_cents) AS dr, SUM(l.credit_cents) AS cr
               FROM gp_journals j JOIN gp_journal_lines l ON l.journal_id = j.id
              WHERE j.status = 'posted'
              GROUP BY j.id, j.journal_no, j.total_cents
             HAVING SUM(l.debit_cents) <> SUM(l.credit_cents) OR SUM(l.debit_cents) <> j.total_cents"
        );
        $this->_add('balance', 'Every posted entry balances', 'Debits equal credits, and both equal the entry\'s total.', 'fail', $rows, function ($r) {
            return ['text' => $r['journal_no'] . ': debits ' . money_format_cents((int) $r['dr']) . ', credits ' . money_format_cents((int) $r['cr']) . ', total ' . money_format_cents((int) $r['total_cents']), 'link' => 'journals/' . $r['id']];
        });
    }

    private function _entries_lines()
    {
        $rows = $this->_q(
            "SELECT j.id, j.journal_no, COUNT(l.id) AS n FROM gp_journals j LEFT JOIN gp_journal_lines l ON l.journal_id = j.id
              WHERE j.status = 'posted' GROUP BY j.id, j.journal_no HAVING COUNT(l.id) < 2"
        );
        $this->_add('lines', 'Every posted entry has at least two lines', '', 'fail', $rows, function ($r) {
            return ['text' => ($r['journal_no'] ?: '#' . $r['id']) . ' has ' . (int) $r['n'] . ' line' . ((int) $r['n'] === 1 ? '' : 's'), 'link' => 'journals/' . $r['id']];
        });
    }

    private function _numbers()
    {
        $rows = $this->_q("SELECT id, book FROM gp_journals WHERE status = 'posted' AND (journal_no IS NULL OR journal_no = '')");
        $this->_add('numbers', 'Every posted entry has its number', 'Numbers are given when an entry posts.', 'fail', $rows, function ($r) {
            return ['text' => 'Entry #' . $r['id'] . ' (' . $r['book'] . ') posted without a number', 'link' => 'journals/' . $r['id']];
        });
    }

    /** Numbers run without gaps per prefix and fiscal year (PLAN §5). */
    private function _gaps()
    {
        $rows = $this->_q(
            "SELECT SUBSTRING_INDEX(journal_no, '-', 1) AS prefix, fiscal_year_id,
                    COUNT(*) AS n, MAX(CAST(SUBSTRING_INDEX(journal_no, '-', -1) AS UNSIGNED)) AS top,
                    MIN(CAST(SUBSTRING_INDEX(journal_no, '-', -1) AS UNSIGNED)) AS low
               FROM gp_journals WHERE status = 'posted' AND journal_no IS NOT NULL
              GROUP BY SUBSTRING_INDEX(journal_no, '-', 1), fiscal_year_id"
        );
        $bad = [];
        foreach ($rows as $r) {
            if ((int) $r['n'] === (int) $r['top'] && (int) $r['low'] === 1) continue;
            $have = array_map('intval', array_column($this->_q(
                "SELECT CAST(SUBSTRING_INDEX(journal_no, '-', -1) AS UNSIGNED) AS s FROM gp_journals
                  WHERE status = 'posted' AND fiscal_year_id = ? AND SUBSTRING_INDEX(journal_no, '-', 1) = ?", [(int) $r['fiscal_year_id'], $r['prefix']]
            ), 's'));
            $missing = array_slice(array_values(array_diff(range(1, (int) $r['top']), $have)), 0, 10);
            $fy = $this->CI->db->select('name')->get_where('gp_fiscal_years', ['id' => (int) $r['fiscal_year_id']], 1)->row_array();
            $bad[] = ['prefix' => $r['prefix'], 'fy' => $fy ? $fy['name'] : '#' . $r['fiscal_year_id'], 'missing' => $missing, 'n' => (int) $r['top'] - (int) $r['n']];
        }
        $this->_add('gaps', 'Entry numbers run without gaps', 'Per book prefix and fiscal year. A gap would mean an entry is missing from the books.', 'fail', $bad, function ($r) {
            return ['text' => $r['prefix'] . ' in ' . $r['fy'] . ': ' . $r['n'] . ' number' . ($r['n'] === 1 ? '' : 's') . ' missing (' . implode(', ', $r['missing']) . ($r['n'] > count($r['missing']) ? ', …' : '') . ')', 'link' => 'reports/books'];
        });
    }

    private function _periods()
    {
        $rows = $this->_q(
            "SELECT j.id, j.journal_no, j.entry_date, p.name AS period FROM gp_journals j JOIN gp_periods p ON p.id = j.period_id
              WHERE j.status IN ('draft', 'submitted', 'posted', 'rejected')
                AND (j.entry_date < p.start_date OR j.entry_date > p.end_date OR p.fiscal_year_id <> j.fiscal_year_id)"
        );
        $this->_add('periods', 'Every entry sits in the period of its date', '', 'fail', $rows, function ($r) {
            return ['text' => ($r['journal_no'] ?: '#' . $r['id']) . ' is dated ' . $r['entry_date'] . ' but filed in ' . $r['period'], 'link' => 'journals/' . $r['id']];
        });
    }

    private function _posted_while_closed()
    {
        $rows = $this->_q(
            "SELECT j.id, j.journal_no, j.approved_at, p.name AS period, p.closed_at FROM gp_journals j JOIN gp_periods p ON p.id = j.period_id
              WHERE j.status = 'posted' AND p.status IN ('closed', 'locked') AND p.closed_at IS NOT NULL AND j.approved_at > p.closed_at"
        );
        $this->_add('closed_periods', 'Nothing was posted into a month after it closed', '', 'fail', $rows, function ($r) {
            return ['text' => $r['journal_no'] . ' posted ' . $r['approved_at'] . ', after ' . $r['period'] . ' closed on ' . $r['closed_at'], 'link' => 'journals/' . $r['id']];
        });
    }

    private function _reversals()
    {
        $rows = $this->_q(
            "SELECT j.id, j.journal_no, 'reversed_by' AS side FROM gp_journals j LEFT JOIN gp_journals r ON r.id = j.reversed_by_id
              WHERE j.reversed_by_id IS NOT NULL AND (r.id IS NULL OR r.reversal_of_id <> j.id OR r.status <> 'posted')
             UNION ALL
             SELECT j.id, j.journal_no, 'reversal_of' FROM gp_journals j LEFT JOIN gp_journals o ON o.id = j.reversal_of_id
              WHERE j.reversal_of_id IS NOT NULL AND j.status = 'posted' AND (o.id IS NULL OR o.reversed_by_id <> j.id)"
        );
        $this->_add('reversals', 'Reversals and the entries they reverse point at each other', '', 'fail', $rows, function ($r) {
            return ['text' => ($r['journal_no'] ?: '#' . $r['id']) . ': its ' . ($r['side'] === 'reversed_by' ? 'reversal' : 'original') . ' does not point back', 'link' => 'journals/' . $r['id']];
        });
    }

    private function _control_contacts()
    {
        $rows = $this->_q(
            "SELECT l.journal_id, l.journal_no, a.code FROM gp_ledger l JOIN gp_accounts a ON a.id = l.account_id
              WHERE a.control IN ('ar', 'ap') AND l.contact_id IS NULL"
        );
        $this->_add('control', 'Every receivable and payable names its customer or supplier', 'So the subsidiary ledgers always add up to their control accounts.', 'fail', $rows, function ($r) {
            return ['text' => $r['journal_no'] . ' has a line on ' . $r['code'] . ' without a customer or supplier', 'link' => 'journals/' . $r['journal_id']];
        });
    }

    /** Entries a module posted point at their records, and the records at them. */
    private function _sources()
    {
        $bad = [];
        $map = [
            'invoice' => ['gp_documents', 'journal_id'], 'credit_note' => ['gp_documents', 'journal_id'], 'bill' => ['gp_documents', 'journal_id'],
            'debit_note' => ['gp_documents', 'journal_id'], 'receipt' => ['gp_settlements', 'journal_id'], 'payment' => ['gp_settlements', 'journal_id'],
            'depreciation' => ['gp_depreciation_runs', 'journal_id'], 'disposal' => ['gp_assets', 'disposal_journal_id'], 'bank' => ['gp_bank_lines', 'journal_id'],
        ];
        foreach ($map as $source => $t) {
            foreach ($this->_q(
                "SELECT j.id, j.journal_no, j.source_id FROM gp_journals j LEFT JOIN {$t[0]} x ON x.id = j.source_id
                  WHERE j.status = 'posted' AND j.source = ? AND (x.id IS NULL OR x.{$t[1]} IS NULL OR (x.{$t[1]} <> j.id AND j.reversed_by_id IS NULL))", [$source]
            ) as $r) {
                $bad[] = ['id' => $r['id'], 'text' => ($r['journal_no'] ?: '#' . $r['id']) . ' says it came from ' . str_replace('_', ' ', $source) . ' #' . $r['source_id'] . ', which does not point back to it'];
            }
        }
        foreach ($this->_q("SELECT d.id, d.doc_no, d.doc_type, d.journal_id FROM gp_documents d LEFT JOIN gp_journals j ON j.id = d.journal_id
                             WHERE d.status = 'posted' AND (j.id IS NULL OR j.status <> 'posted')") as $r) {
            $bad[] = ['id' => NULL, 'link' => 'documents/' . $r['id'], 'text' => ($r['doc_no'] ?: str_replace('_', ' ', $r['doc_type']) . ' #' . $r['id']) . ' is posted but its journal entry is missing'];
        }
        foreach ($this->_q("SELECT s.id, s.settle_no, s.kind FROM gp_settlements s LEFT JOIN gp_journals j ON j.id = s.journal_id
                             WHERE s.status = 'posted' AND (j.id IS NULL OR j.status <> 'posted')") as $r) {
            $bad[] = ['id' => NULL, 'link' => 'settlements/' . $r['id'], 'text' => ($r['settle_no'] ?: $r['kind'] . ' #' . $r['id']) . ' is posted but its journal entry is missing'];
        }
        $this->_add('sources', 'Module entries and their records point at each other', 'Invoices, bills, receipts, payments, depreciation runs, disposals and bank adjustments.', 'fail', $bad, function ($r) {
            return ['text' => $r['text'], 'link' => $r['link'] ?? ('journals/' . $r['id'])];
        });
    }

    private function _documents()
    {
        $bad = [];
        foreach ($this->_q(
            "SELECT d.id, d.doc_no, d.doc_type, d.total_cents, d.applied_cents,
                    COALESCE((SELECT SUM(a.amount_cents) FROM gp_allocations a WHERE a.document_id = d.id), 0)
                  + COALESCE((SELECT SUM(a.amount_cents) FROM gp_allocations a WHERE a.credit_document_id = d.id), 0) AS alloc
               FROM gp_documents d WHERE d.status <> 'draft'
             HAVING alloc <> d.applied_cents OR d.applied_cents > d.total_cents OR d.applied_cents < 0"
        ) as $r) {
            $bad[] = ['id' => $r['id'], 'text' => ($r['doc_no'] ?: '#' . $r['id']) . ': applied ' . money_format_cents((int) $r['applied_cents']) . ', allocations ' . money_format_cents((int) $r['alloc']) . ', total ' . money_format_cents((int) $r['total_cents'])];
        }
        /* Per entry and per customer or supplier: documents brought in at
           go-live share the one opening entry, which carries each contact's
           balance on the control account; every other document has its own. */
        foreach ($this->_q(
            "SELECT MIN(d.id) AS id, GROUP_CONCAT(d.doc_no ORDER BY d.id SEPARATOR ', ') AS nos, SUM(d.total_cents) AS docs,
                    (SELECT COALESCE(SUM(l.debit_cents + l.credit_cents), 0) FROM gp_journal_lines l JOIN gp_accounts a ON a.id = l.account_id
                      WHERE l.journal_id = d.journal_id AND l.contact_id = d.contact_id AND a.control IN ('ar', 'ap')) AS ctl
               FROM gp_documents d WHERE d.status = 'posted' AND d.journal_id IS NOT NULL
              GROUP BY d.journal_id, d.contact_id HAVING ctl <> docs"
        ) as $r) {
            $bad[] = ['id' => $r['id'], 'text' => ($r['nos'] ?: '#' . $r['id']) . ': ' . money_format_cents((int) $r['docs']) . ' in documents, but their entry puts '
                . money_format_cents((int) $r['ctl']) . ' on the control account for that customer or supplier'];
        }
        $this->_add('documents', 'Invoices and bills agree with their payments and their entries', 'What is applied to each equals its allocations and never exceeds its total; its entry carries its total.', 'fail', $bad, function ($r) {
            return ['text' => $r['text'], 'link' => 'documents/' . $r['id']];
        });
    }

    private function _settlements()
    {
        $rows = $this->_q(
            "SELECT s.id, s.settle_no, s.amount_cents + s.withholding_cents AS cap, COALESCE(SUM(a.amount_cents), 0) AS alloc
               FROM gp_settlements s LEFT JOIN gp_allocations a ON a.settlement_id = s.id
              WHERE s.status = 'posted' GROUP BY s.id, s.settle_no, s.amount_cents, s.withholding_cents HAVING alloc > cap"
        );
        $this->_add('settlements', 'No receipt or payment is applied beyond its amount', '', 'fail', $rows, function ($r) {
            return ['text' => ($r['settle_no'] ?: '#' . $r['id']) . ': applied ' . money_format_cents((int) $r['alloc']) . ' of ' . money_format_cents((int) $r['cap']), 'link' => 'settlements/' . $r['id']];
        });
    }

    private function _bank()
    {
        $bad = [];
        foreach ($this->_q(
            "SELECT b.id, b.statement_id, b.amount_cents, COALESCE(SUM(l.debit_cents - l.credit_cents), 0) AS book
               FROM gp_bank_lines b JOIN gp_bank_matches m ON m.bank_line_id = b.id JOIN gp_journal_lines l ON l.id = m.journal_line_id
              GROUP BY b.id, b.statement_id, b.amount_cents HAVING book <> b.amount_cents"
        ) as $r) {
            $bad[] = ['sid' => $r['statement_id'], 'text' => 'Bank line #' . $r['id'] . ' (' . money_format_cents((int) $r['amount_cents']) . ') is matched to book lines of ' . money_format_cents((int) $r['book'])];
        }
        foreach ($this->_q(
            "SELECT s.id, COUNT(b.id) AS n FROM gp_bank_statements s JOIN gp_bank_lines b ON b.statement_id = s.id AND b.status = 'unmatched'
              WHERE s.status = 'reconciled' GROUP BY s.id"
        ) as $r) {
            $bad[] = ['sid' => $r['id'], 'text' => 'Statement #' . $r['id'] . ' is reconciled but ' . (int) $r['n'] . ' of its lines are unmatched'];
        }
        foreach ($this->_q(
            "SELECT m.id, b.statement_id FROM gp_bank_matches m JOIN gp_bank_lines b ON b.id = m.bank_line_id
               JOIN gp_journal_lines l ON l.id = m.journal_line_id JOIN gp_journals j ON j.id = l.journal_id
               JOIN gp_bank_accounts ba ON ba.id = (SELECT st.bank_account_id FROM gp_bank_statements st WHERE st.id = b.statement_id)
              WHERE j.status <> 'posted' OR l.account_id <> ba.account_id"
        ) as $r) {
            $bad[] = ['sid' => $r['statement_id'], 'text' => 'A match on statement #' . $r['statement_id'] . ' points at a line that is not posted to that bank\'s account'];
        }
        $this->_add('bank', 'Bank matches add up', 'Matched book lines equal their bank line; a reconciled statement has nothing unmatched.', 'fail', $bad, function ($r) {
            return ['text' => $r['text'], 'link' => 'banking/statements/' . $r['sid']];
        });
    }

    private function _assets()
    {
        $bad = [];
        foreach ($this->_q(
            "SELECT a.id, a.asset_no, a.cost_cents - a.residual_cents AS base,
                    a.opening_accum_cents + COALESCE((SELECT SUM(e.amount_cents) FROM gp_depreciation_entries e WHERE e.asset_id = a.id), 0) AS accum
               FROM gp_assets a HAVING accum > base OR accum < 0"
        ) as $r) {
            $bad[] = ['link' => 'assets/' . $r['id'], 'text' => $r['asset_no'] . ': accumulated ' . money_format_cents((int) $r['accum']) . ' is more than its depreciable ' . money_format_cents((int) $r['base'])];
        }
        foreach ($this->_q(
            "SELECT r.id, r.total_cents, COALESCE((SELECT SUM(e.amount_cents) FROM gp_depreciation_entries e WHERE e.run_id = r.id), 0) AS entries,
                    j.total_cents AS journal, j.status
               FROM gp_depreciation_runs r LEFT JOIN gp_journals j ON j.id = r.journal_id
             HAVING entries <> r.total_cents OR (r.total_cents > 0 AND (journal IS NULL OR journal <> r.total_cents OR j.status <> 'posted'))"
        ) as $r) {
            $bad[] = ['link' => 'depreciation', 'text' => 'Depreciation run #' . $r['id'] . ': total ' . money_format_cents((int) $r['total_cents']) . ', its assets ' . money_format_cents((int) $r['entries']) . ', its entry ' . ($r['journal'] === NULL ? 'missing' : money_format_cents((int) $r['journal']))];
        }
        $this->_add('assets', 'Depreciation stays within cost and agrees with its entries', '', 'fail', $bad, function ($r) {
            return ['text' => $r['text'], 'link' => $r['link']];
        });
    }

    private function _audit()
    {
        $rows = $this->_q(
            "SELECT j.id, j.journal_no FROM gp_journals j
              WHERE j.status = 'posted' AND NOT EXISTS (SELECT 1 FROM gp_admin_audit_log x WHERE x.target_type = 'journal' AND x.target_id = j.id AND x.action = 'journal.post')"
        );
        /* The demo seed and imports may write without a person; that is a
           warning, not a broken ledger. */
        $this->_add('audit', 'Every posting is in the audit log', 'Who posted it and when.', 'warn', $rows, function ($r) {
            return ['text' => ($r['journal_no'] ?: '#' . $r['id']) . ' has no "posted" record in the audit log', 'link' => 'journals/' . $r['id']];
        });
    }

    private function _trial_balance($today)
    {
        $tb  = $this->CI->ledger->trial_balance($today, 'post_closing');
        $bad = $tb['balanced'] ? [] : [['text' => 'Debits ' . money_format_cents($tb['total_debit_cents']) . ', credits ' . money_format_cents($tb['total_credit_cents'])]];
        $this->_add('trial_balance', 'The trial balance balances today', '', 'fail', $bad, function ($r) {
            return ['text' => $r['text'], 'link' => 'reports/trial-balance'];
        });
    }

    private function _years()
    {
        $bad   = [];
        $years = $this->_q('SELECT * FROM gp_fiscal_years ORDER BY start_date');
        $prev  = NULL;
        foreach ($years as $y) {
            if ($prev && $y['start_date'] !== date('Y-m-d', strtotime($prev['end_date'] . ' +1 day'))) {
                $bad[] = ['text' => $y['name'] . ' does not start the day after ' . $prev['name'] . ' ends'];
            }
            $n = (int) $this->CI->db->where('fiscal_year_id', (int) $y['id'])->count_all_results('gp_periods');
            if ($n !== 12) $bad[] = ['text' => $y['name'] . ' has ' . $n . ' months instead of 12'];
            if ($y['status'] === 'closed') {
                $pl = (int) $this->CI->db->query(
                    "SELECT COALESCE(SUM(l.debit_cents) - SUM(l.credit_cents), 0) AS n FROM gp_ledger l JOIN gp_accounts a ON a.id = l.account_id
                      WHERE a.type IN ('income', 'expense') AND l.entry_date BETWEEN ? AND ?", [$y['start_date'], $y['end_date']]
                )->row()->n;
                if ($pl !== 0) $bad[] = ['text' => $y['name'] . ' is closed but its income and expenses still net to ' . money_format_cents($pl)];
                $open = (int) $this->CI->db->where('fiscal_year_id', (int) $y['id'])->where('status', 'open')->count_all_results('gp_periods');
                if ($open) $bad[] = ['text' => $y['name'] . ' is closed but ' . $open . ' of its months are open'];
            }
            $prev = $y;
        }
        $this->_add('years', 'Fiscal years follow on, with twelve months each; closed years are closed out', '', 'fail', $bad, function ($r) {
            return ['text' => $r['text'], 'link' => 'year-end'];
        });
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    private function _q($sql, array $args = [])
    {
        return $this->CI->db->query($sql, $args)->result_array();
    }

    private function _add($key, $title, $about, $level, array $rows, callable $fmt)
    {
        $n = count($rows);
        $this->checks[] = [
            'key'    => $key,
            'title'  => $title,
            'about'  => $about,
            'status' => $n === 0 ? 'ok' : $level,
            'count'  => $n,
            'items'  => array_map($fmt, array_slice($rows, 0, self::LIMIT)),
        ];
    }
}
