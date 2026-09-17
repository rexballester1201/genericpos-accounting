<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Year_end_model.php — closing a fiscal year, a co-op's net-surplus allocation, reopening
 *
 * GenericPOS Accounting · gp_fiscal_years, gp_periods, gp_surplus_allocations, and journals
 *
 * ─── CLOSING A YEAR ───────────────────────────────────────────────────────
 * One entry in the closing book, dated the year's last day, source 'closing',
 * source_id = the fiscal year:
 *   · every income and expense account's balance for the year is reversed
 *     (per department where the account requires one);
 *   · the difference — net income, or a co-op's net surplus — goes to the
 *     closing account (Settings › Account defaults: retained earnings, the
 *     owner's capital, or undivided net surplus);
 *   · a sole proprietorship's drawings are closed into capital in the same entry.
 * Then every open month of the year is closed and the year is marked closed,
 * so nothing more can be dated in it. All of that is one transaction.
 *
 * The income statement leaves the closing book out, so a closed year still
 * reports its income; the balance sheet counts it, so retained earnings carry
 * the year's result and the income accounts start the next year at zero.
 *
 * ─── A CO-OP'S NET SURPLUS ────────────────────────────────────────────────
 * A second closing-book entry (same source and source_id) moves the net
 * surplus out of undivided net surplus into the statutory funds (reserve,
 * education and training, community development, optional) and what is owed to
 * members (interest on share capital, patronage refund), at the percentages in
 * Settings › Co-operative. It is posted with the closing (dated the year end)
 * or later, once the general assembly has approved it (dated then, in an open
 * month of a later year). Each fund is rounded to the centavo and the
 * patronage refund takes what is left, so the parts always add up.
 *
 * ─── REOPENING ────────────────────────────────────────────────────────────
 * Only the latest closed year, with a reason. The year and its last month open
 * again and the closing entries are reversed, dated the year end, so the
 * income accounts show the year's balances again. An allocation posted in a
 * later year must be undone first.
 *
 * NOTE: every refusal is a DomainException inside the transaction and comes
 * back as [NULL, message]. A posting refused inside Journal_model THROWS a
 * RuntimeException whose message is fit for the user; SQL errors throw
 * mysqli_sql_exception (also a RuntimeException), which is logged and never
 * shown — so that catch comes first.
 */
class Year_end_model extends CI_Model
{
    const FUNDS = [
        'reserve'   => ['setting' => 'coop_reserve_fund_pct',  'account' => 'acct_coop_reserve_fund',      'label' => 'Reserve fund'],
        'cetf'      => ['setting' => 'coop_cetf_pct',          'account' => 'acct_coop_cetf',              'label' => 'Co-operative education and training fund'],
        'cdf'       => ['setting' => 'coop_cdf_pct',           'account' => 'acct_coop_cdf',               'label' => 'Community development fund'],
        'optional'  => ['setting' => 'coop_optional_fund_pct', 'account' => 'acct_coop_optional_fund',     'label' => 'Optional fund'],
        'isc'       => ['setting' => 'coop_isc_pct',           'account' => 'acct_coop_isc_payable',       'label' => 'Interest on share capital'],
        'patronage' => ['setting' => NULL,                     'account' => 'acct_coop_patronage_payable', 'label' => 'Patronage refund'],
    ];

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Period_model', 'periods');
        $this->load->model('Account_model', 'accounts');
        $this->load->model('Journal_model');
        $this->load->model('Ledger_model', 'ledger');
    }

    // =========================================================================
    // READ
    // =========================================================================

    /**
     * Everything the year-end screen shows for one fiscal year — by default the
     * one that is due: the oldest open year, or the latest year when all are closed.
     *
     * @return array|NULL NULL when $fy_id names no year
     */
    public function status($fy_id = 0)
    {
        $years = $this->periods->years();
        if ( ! $years) return ['years' => [], 'year' => NULL];

        $fy = $fy_id ? $this->periods->year((int) $fy_id) : $this->_due_year($years);
        if ( ! $fy) return NULL;

        $coop    = $this->_coop();
        $closing = $this->_closing_account();
        $plan    = $this->_plan($fy, $closing);
        $checks  = $this->_checks($fy, $plan, $closing);
        $alloc   = $this->_allocation_row((int) $fy['id']);
        $blocked = (bool) array_filter($checks, function ($c) { return ! $c['ok'] && $c['level'] === 'block'; });

        $later_closed = (int) $this->db->where('start_date >', $fy['start_date'])->where('status', 'closed')->count_all_results('gp_fiscal_years');
        $alloc_later  = $alloc && $alloc['entry_date'] !== NULL && $alloc['entry_date'] > $fy['end_date'];

        return [
            'years' => array_map(function ($y) {
                return ['id' => (int) $y['id'], 'name' => $y['name'], 'start_date' => $y['start_date'], 'end_date' => $y['end_date'], 'status' => $y['status']];
            }, $years),
            'year' => [
                'id'         => (int) $fy['id'],
                'name'       => $fy['name'],
                'start_date' => $fy['start_date'],
                'end_date'   => $fy['end_date'],
                'status'     => $fy['status'],
                'closed_by'  => $this->_user_name($fy['closed_by']),
                'closed_at'  => $fy['closed_at'],
                'periods'    => array_map(function ($p) {
                    return ['id' => (int) $p['id'], 'name' => $p['name'], 'status' => $p['status'], 'end_date' => $p['end_date']];
                }, $this->periods->periods((int) $fy['id'])),
            ],
            'entity_type'   => $coop ? 'cooperative' : 'business',
            'business_form' => (string) shop_cfg('business_form', 'corporation'),
            'today'         => company_today(),
            'checks'        => $checks,
            'plan'          => $plan,
            'journals'      => $this->_journals((int) $fy['id']),
            'allocation'    => $coop ? $this->_allocation_view($fy, $plan, $alloc) : NULL,
            'can'           => [
                'close'           => $fy['status'] === 'open' && ! $blocked,
                'reopen'          => $fy['status'] === 'closed' && $later_closed === 0 && ! $alloc_later,
                'allocate'        => $coop && $fy['status'] === 'closed' && ! $alloc && $plan['net_income_cents'] > 0,
                'undo_allocation' => $coop && $alloc_later,
            ],
            'reopen_note' => $fy['status'] !== 'closed' ? NULL
                : ($later_closed ? 'A later year is closed. Reopen the latest closed year first.'
                : ($alloc_later ? 'The net-surplus allocation was posted in a later year. Undo it first.' : NULL)),
        ];
    }

    /**
     * Split a net surplus among the funds. Each fund is rounded to the centavo;
     * interest on share capital takes its share of what is left, and the
     * patronage refund the rest, so the parts add up exactly.
     *
     * @param int   $ns   net surplus, centavos (> 0)
     * @param array $pct  reserve, cetf, cdf, optional, isc — percentages (isc: of the remainder)
     * @return array reserve, cetf, cdf, optional, isc, patronage — centavos
     */
    public static function split($ns, array $pct)
    {
        $ns   = max(0, (int) $ns);
        $part = function ($base, $p) { return intdiv($base * (int) round(((float) $p) * 100) + 5000, 10000); };

        $out = [];
        foreach (['reserve', 'cetf', 'cdf', 'optional'] as $k) $out[$k] = $part($ns, $pct[$k] ?? 0);
        $rest = $ns - array_sum($out);
        $out['isc']       = $part($rest, $pct['isc'] ?? 0);
        $out['patronage'] = $rest - $out['isc'];
        return $out;
    }

    // =========================================================================
    // WRITE
    // =========================================================================

    /**
     * Close $fy_id. $opts: allocate (a co-op's net surplus, dated the year end),
     * percentages (override Settings for this allocation).
     *
     * @return array [['closing_journal_id', 'allocation_journal_id'] | NULL, error]
     */
    public function close($fy_id, $user_id, array $opts = [])
    {
        $this->db->trans_begin();
        try {
            $fy = $this->_lock_year($fy_id);
            if ($fy['status'] !== 'open') throw new DomainException($fy['name'] . ' is already closed.');

            $closing = $this->_closing_account();
            $plan    = $this->_plan($fy, $closing);
            foreach ($this->_checks($fy, $plan, $closing) as $c) {
                if ( ! $c['ok'] && $c['level'] === 'block') throw new DomainException($c['text']);
            }

            $periods = $this->db->query('SELECT * FROM gp_periods WHERE fiscal_year_id = ? ORDER BY period_no FOR UPDATE', [(int) $fy['id']])->result_array();
            $last    = end($periods);
            if ($last['status'] === 'closed') {
                /* The closing entry is dated in the last month; the month closes
                   again below with the rest of the year. */
                $this->db->where('id', (int) $last['id'])->update('gp_periods', ['status' => 'open', 'closed_by' => NULL, 'closed_at' => NULL]);
            }

            $jid = 0;
            if ($plan['lines']) {
                $w = $this->_coop() ? 'net surplus' : ($plan['net_income_cents'] < 0 ? 'net loss' : 'net income');
                list($jid, $errs) = $this->Journal_model->post_system([
                    'book'        => 'closing',
                    'entry_date'  => $fy['end_date'],
                    'reference'   => $fy['name'],
                    'description' => 'Closing entries for ' . $fy['name'] . ': ' . $w . ' of ' . money_format_cents(abs($plan['net_income_cents']))
                                   . ' closed to ' . $closing['code'] . ' ' . $closing['name'],
                ], $this->_journal_lines($plan), (int) $user_id, 'closing', (int) $fy['id']);
                if ( ! $jid) throw new DomainException(reset($errs));
            }

            $alloc_jid = 0;
            if ( ! empty($opts['allocate'])) {
                if ( ! $this->_coop()) throw new DomainException('Only a co-operative allocates a net surplus.');
                if ($plan['net_income_cents'] <= 0) throw new DomainException('There is no net surplus to allocate.');
                $alloc_jid = $this->_post_allocation($fy, $plan['net_income_cents'], $fy['end_date'], (int) $user_id, $opts['percentages'] ?? NULL);
            }

            $now    = date('Y-m-d H:i:s');
            $closed = array_values(array_map(function ($p) { return $p['name']; },
                      array_filter($periods, function ($p) { return $p['status'] !== 'locked'; })));
            $this->db->where('fiscal_year_id', (int) $fy['id'])->where('status', 'open')
                     ->update('gp_periods', ['status' => 'closed', 'closed_by' => (int) $user_id, 'closed_at' => $now]);
            $this->db->where('id', (int) $fy['id'])
                     ->update('gp_fiscal_years', ['status' => 'closed', 'closing_journal_id' => $jid ?: NULL, 'closed_by' => (int) $user_id, 'closed_at' => $now]);

            $this->_audit($user_id, 'fiscal_year.close', (int) $fy['id'], [
                'name'                  => $fy['name'],
                'net_income_cents'      => $plan['net_income_cents'],
                'closing_journal_id'    => $jid ?: NULL,
                'allocation_journal_id' => $alloc_jid ?: NULL,
                'months_closed'         => $closed,
            ]);

            $this->db->trans_commit();
            return [['closing_journal_id' => $jid ?: NULL, 'allocation_journal_id' => $alloc_jid ?: NULL], ''];
        } catch (Throwable $t) {
            return $this->_fail($t, 'close');
        }
    }

    /** @return array [reversal journal ids | NULL, error] */
    public function reopen($fy_id, $user_id, $reason)
    {
        $reason = trim(preg_replace('/\s+/u', ' ', (string) $reason));
        if ($reason === '')             return [NULL, 'Say why the year is reopened.'];
        if (mb_strlen($reason) > 300)   return [NULL, 'Keep the reason under 300 characters.'];

        $this->db->trans_begin();
        try {
            $fy = $this->_lock_year($fy_id);
            if ($fy['status'] !== 'closed') throw new DomainException($fy['name'] . ' is open.');
            $later = $this->db->where('start_date >', $fy['start_date'])->where('status', 'closed')->order_by('start_date', 'DESC')->limit(1)->get('gp_fiscal_years')->row_array();
            if ($later) throw new DomainException($later['name'] . ' is closed too. Only the latest closed year can be reopened, so reopen ' . $later['name'] . ' first.');

            $alloc = $this->_allocation_row((int) $fy['id']);
            if ($alloc && $alloc['entry_date'] !== NULL && $alloc['entry_date'] > $fy['end_date']) {
                throw new DomainException('The net-surplus allocation for ' . $fy['name'] . ' was posted on ' . $alloc['entry_date'] . ', in a later year. Undo it first.');
            }

            $periods = $this->db->query('SELECT * FROM gp_periods WHERE fiscal_year_id = ? ORDER BY period_no FOR UPDATE', [(int) $fy['id']])->result_array();
            $last    = end($periods);
            if ($last['status'] === 'locked') throw new DomainException($last['name'] . ' is locked for good, and the closing entries are dated in it, so ' . $fy['name'] . ' cannot be reopened.');

            $this->db->where('id', (int) $fy['id'])->update('gp_fiscal_years', ['status' => 'open', 'closed_by' => NULL, 'closed_at' => NULL]);
            $this->db->where('id', (int) $last['id'])->update('gp_periods', ['status' => 'open', 'closed_by' => NULL, 'closed_at' => NULL]);

            /* Newest first, so the allocation is undone before the closing
               entry it drew on. */
            $reversed = [];
            $open = $this->db->query(
                "SELECT id, journal_no FROM gp_journals
                  WHERE source = 'closing' AND source_id = ? AND status = 'posted'
                    AND reversed_by_id IS NULL AND reversal_of_id IS NULL AND entry_date <= ?
                  ORDER BY id DESC", [(int) $fy['id'], $fy['end_date']]
            )->result_array();
            foreach ($open as $j) {
                list($rid, $err) = $this->Journal_model->reverse_system((int) $j['id'], (int) $user_id, $fy['end_date'], 'Reopening ' . $fy['name'] . ': ' . $reason, 'closing');
                if ( ! $rid) throw new DomainException($err);
                $reversed[] = $rid;
            }

            if ($alloc) $this->db->where('id', (int) $alloc['id'])->delete('gp_surplus_allocations');
            $this->db->where('id', (int) $fy['id'])->update('gp_fiscal_years', ['closing_journal_id' => NULL]);

            $this->_audit($user_id, 'fiscal_year.reopen', (int) $fy['id'], [
                'name' => $fy['name'], 'reason' => $reason, 'reversal_ids' => $reversed, 'month_reopened' => $last['name'],
            ]);

            $this->db->trans_commit();
            return [$reversed, ''];
        } catch (Throwable $t) {
            return $this->_fail($t, 'reopen');
        }
    }

    /**
     * A co-op's allocation after the year is closed: dated when the general
     * assembly approved it, in an open month after the year.
     *
     * @return array [journal id | NULL, error]
     */
    public function allocate($fy_id, $user_id, $date, $percentages = NULL)
    {
        if ( ! $this->_coop()) return [NULL, 'Only a co-operative allocates a net surplus.'];
        $date = trim((string) $date);
        if ( ! Period_model::valid_date($date)) return [NULL, 'Enter the date of the allocation.'];

        $this->db->trans_begin();
        try {
            $fy = $this->_lock_year($fy_id);
            if ($fy['status'] !== 'closed') throw new DomainException('Close ' . $fy['name'] . ' first. To allocate on the year end, tick the allocation when you close it.');
            if ($this->_allocation_row((int) $fy['id'])) throw new DomainException('The net surplus of ' . $fy['name'] . ' is already allocated.');
            if ($date <= $fy['end_date']) throw new DomainException('Date the allocation after ' . $fy['end_date'] . ', when ' . $fy['name'] . ' ended.');

            $ns = $this->_net_income($fy);
            if ($ns <= 0) throw new DomainException($fy['name'] . ' has no net surplus to allocate.');

            $jid = $this->_post_allocation($fy, $ns, $date, (int) $user_id, $percentages);
            $this->_audit($user_id, 'surplus.allocate', (int) $fy['id'], ['name' => $fy['name'], 'journal_id' => $jid, 'date' => $date, 'net_surplus_cents' => $ns]);

            $this->db->trans_commit();
            return [$jid, ''];
        } catch (Throwable $t) {
            return $this->_fail($t, 'allocate');
        }
    }

    /** Undo an allocation posted after the year end. @return array [reversal id | NULL, error] */
    public function undo_allocation($fy_id, $user_id, $date, $reason)
    {
        $reason = trim(preg_replace('/\s+/u', ' ', (string) $reason));
        $date   = trim((string) $date);
        if ($reason === '') return [NULL, 'Say why the allocation is undone.'];

        $this->db->trans_begin();
        try {
            $fy    = $this->_lock_year($fy_id);
            $alloc = $this->_allocation_row((int) $fy['id']);
            if ( ! $alloc) throw new DomainException($fy['name'] . ' has no allocation to undo.');
            if ($alloc['entry_date'] === NULL || $alloc['entry_date'] <= $fy['end_date']) {
                throw new DomainException('This allocation is part of the closing of ' . $fy['name'] . '. Reopen the year to undo it.');
            }
            if ($date === '') $date = max(company_today(), $alloc['entry_date']);

            list($rid, $err) = $this->Journal_model->reverse_system((int) $alloc['journal_id'], (int) $user_id, $date, $reason, 'closing');
            if ( ! $rid) throw new DomainException($err);
            $this->db->where('id', (int) $alloc['id'])->delete('gp_surplus_allocations');

            $this->_audit($user_id, 'surplus.undo', (int) $fy['id'], ['name' => $fy['name'], 'reversal_id' => $rid, 'reason' => $reason]);
            $this->db->trans_commit();
            return [$rid, ''];
        } catch (Throwable $t) {
            return $this->_fail($t, 'undo allocation');
        }
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    private function _coop()
    {
        return shop_cfg('entity_type') === 'cooperative';
    }

    private function _due_year(array $years)
    {
        $open = array_values(array_filter($years, function ($y) { return $y['status'] === 'open'; }));
        return $open ? $open[count($open) - 1] : $years[0];   // years() is newest first
    }

    private function _lock_year($fy_id)
    {
        $fy = $this->db->query('SELECT * FROM gp_fiscal_years WHERE id = ? FOR UPDATE', [(int) $fy_id])->row_array();
        if ( ! $fy) throw new DomainException('That fiscal year does not exist.');
        return $fy;
    }

    /** The closing account row from Settings, or NULL when unset or unusable. */
    private function _closing_account()
    {
        $code = trim((string) shop_cfg('acct_retained_earnings', ''));
        if ($code === '') return NULL;
        $a = $this->accounts->find_by_code($code);
        return ($a && ! (int) $a['is_header'] && (int) $a['is_active'] && $a['type'] === 'equity') ? $a : NULL;
    }

    /** The year's income: −Σ income and expense for the year, the closing book left out (credit-positive). */
    private function _net_income(array $fy)
    {
        $r = $this->db->query(
            "SELECT COALESCE(SUM(l.debit_cents) - SUM(l.credit_cents), 0) AS n
               FROM gp_ledger l JOIN gp_accounts a ON a.id = l.account_id
              WHERE a.type IN ('income', 'expense') AND l.entry_date >= ? AND l.entry_date <= ? AND l.book <> 'closing'",
            [$fy['start_date'], $fy['end_date']]
        )->row_array();
        return -(int) $r['n'];
    }

    /**
     * What the closing entry would be: one line per income or expense account
     * with a balance for the year (per department where the account requires
     * one), the owner's drawings for a sole proprietorship, and the result to
     * the closing account.
     */
    private function _plan(array $fy, $closing)
    {
        $rows = $this->db->query(
            "SELECT l.account_id, IF(a.requires_department = 1, l.department_id, NULL) AS dept,
                    SUM(l.debit_cents) - SUM(l.credit_cents) AS net
               FROM gp_ledger l JOIN gp_accounts a ON a.id = l.account_id
              WHERE a.type IN ('income', 'expense') AND l.entry_date >= ? AND l.entry_date <= ? AND l.book <> 'closing'
              GROUP BY l.account_id, IF(a.requires_department = 1, l.department_id, NULL)",
            [$fy['start_date'], $fy['end_date']]
        )->result_array();

        $accts = $this->accounts->by_ids(array_column($rows, 'account_id'));
        $depts = [];
        $dids  = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'dept')))));
        if ($dids) foreach ($this->db->where_in('id', $dids)->get('gp_departments')->result_array() as $d) $depts[(int) $d['id']] = $d;

        $to    = $closing ? $closing['code'] . ' ' . $closing['name'] : 'the closing account';
        $lines = [];
        $pl    = 0;
        foreach ($rows as $r) {
            $net = (int) $r['net'];
            if ($net === 0) continue;
            $a   = $accts[(int) $r['account_id']];
            $did = $r['dept'] !== NULL ? (int) $r['dept'] : NULL;
            $lines[] = [
                'account_id'    => (int) $a['id'],
                'code'          => $a['code'],
                'name'          => $a['name'],
                'type'          => $a['type'],
                'sort'          => (int) $a['sort_order'],
                'department_id' => $did,
                'department'    => $did && isset($depts[$did]) ? $depts[$did]['code'] : NULL,
                'debit_cents'   => $net < 0 ? -$net : 0,
                'credit_cents'  => $net > 0 ? $net : 0,
                'memo'          => 'Closed to ' . $to,
                'problem'       => ! (int) $a['is_active'] ? $a['code'] . ' is inactive'
                                 : ($did && isset($depts[$did]) && ! (int) $depts[$did]['is_active'] ? 'department ' . $depts[$did]['code'] . ' is inactive' : NULL),
            ];
            $pl += $net;
        }
        usort($lines, function ($x, $y) {
            return [$x['type'] === 'income' ? 0 : 1, $x['sort'], $x['code'], (int) $x['department_id']]
               <=> [$y['type'] === 'income' ? 0 : 1, $y['sort'], $y['code'], (int) $y['department_id']];
        });
        $ni = -$pl;

        $drawings = 0;
        $dcode    = trim((string) shop_cfg('acct_drawings', ''));
        $draw_acc = NULL;
        if (shop_cfg('business_form') === 'sole_proprietorship' && $dcode !== '' && ( ! $closing || $dcode !== $closing['code'])) {
            $draw_acc = $this->accounts->find_by_code($dcode);
            if ($draw_acc) {
                $drawings = (int) $this->db->query(
                    'SELECT COALESCE(SUM(debit_cents) - SUM(credit_cents), 0) AS b FROM gp_ledger WHERE account_id = ? AND entry_date <= ?',
                    [(int) $draw_acc['id'], $fy['end_date']]
                )->row()->b;
            }
        }

        $result = [];
        if ($closing) {
            if ($ni !== 0) {
                $result[] = [
                    'account_id' => (int) $closing['id'], 'code' => $closing['code'], 'name' => $closing['name'], 'type' => 'equity',
                    'department_id' => NULL, 'department' => NULL,
                    'debit_cents' => $ni < 0 ? -$ni : 0, 'credit_cents' => $ni > 0 ? $ni : 0,
                    'memo' => ($this->_coop() ? 'Net surplus' : ($ni < 0 ? 'Net loss' : 'Net income')) . ' for ' . $fy['name'], 'problem' => NULL,
                ];
            }
            if ($drawings > 0 && $draw_acc) {
                $result[] = [
                    'account_id' => (int) $closing['id'], 'code' => $closing['code'], 'name' => $closing['name'], 'type' => 'equity',
                    'department_id' => NULL, 'department' => NULL, 'debit_cents' => $drawings, 'credit_cents' => 0,
                    'memo' => 'Drawings for ' . $fy['name'] . ' closed into capital', 'problem' => NULL,
                ];
                $result[] = [
                    'account_id' => (int) $draw_acc['id'], 'code' => $draw_acc['code'], 'name' => $draw_acc['name'], 'type' => 'equity',
                    'department_id' => NULL, 'department' => NULL, 'debit_cents' => 0, 'credit_cents' => $drawings,
                    'memo' => 'Closed into ' . $closing['code'] . ' ' . $closing['name'], 'problem' => NULL,
                ];
            }
        }

        $all = array_merge($lines, $result);
        foreach ($all as &$l) unset($l['sort']);
        unset($l);

        return [
            'lines'            => $all,
            'net_income_cents' => $ni,
            'drawings_cents'   => $drawings,
            'total_cents'      => array_sum(array_column($all, 'debit_cents')),
            'closing_account'  => $closing ? ['code' => $closing['code'], 'name' => $closing['name']] : NULL,
        ];
    }

    private function _journal_lines(array $plan)
    {
        return array_map(function ($l) {
            return ['account_id' => $l['account_id'], 'debit_cents' => $l['debit_cents'], 'credit_cents' => $l['credit_cents'],
                    'memo' => $l['memo'], 'department_id' => $l['department_id']];
        }, $plan['lines']);
    }

    /** What must be true before closing ('block') and what the closer should know ('warn'). */
    private function _checks(array $fy, array $plan, $closing)
    {
        $c   = [];
        $add = function ($key, $ok, $level, $text, $link = NULL) use (&$c) {
            $c[] = ['key' => $key, 'ok' => (bool) $ok, 'level' => $level, 'text' => $text, 'link' => $link];
        };
        $s = $fy['start_date'];
        $e = $fy['end_date'];

        $earlier = $this->db->where('end_date <', $s)->where('status', 'open')->order_by('start_date', 'ASC')->limit(1)->get('gp_fiscal_years')->row_array();
        $add('order', ! $earlier, 'block', $earlier
            ? $earlier['name'] . ' is still open. Close the years in order, oldest first.'
            : 'Every earlier year is closed.');

        $n = (int) $this->db->where('entry_date >=', $s)->where('entry_date <=', $e)->where_in('status', ['draft', 'submitted'])->count_all_results('gp_journals');
        $add('waiting', $n === 0, 'block', $n
            ? $n . ' journal entr' . ($n === 1 ? 'y is' : 'ies are') . ' still waiting in ' . $fy['name'] . '. Post, reject or cancel ' . ($n === 1 ? 'it' : 'them') . ' first.'
            : 'No journal entries are waiting for approval.', 'journals?status=waiting&from=' . $s . '&to=' . $e);

        $last = $this->db->where('fiscal_year_id', (int) $fy['id'])->order_by('period_no', 'DESC')->limit(1)->get('gp_periods')->row_array();
        if ($last && $last['status'] === 'locked') {
            $add('last_month', FALSE, 'block', $last['name'] . ' is locked for good, and the closing entry is dated in it.');
        }

        $add('closing_account', $closing !== NULL, 'block', $closing
            ? ($this->_coop() ? 'The net surplus' : 'Net income') . ' goes to ' . $closing['code'] . ' ' . $closing['name'] . '.'
            : 'Choose an active equity account as the closing account in Settings › Account defaults.', 'settings');

        $bad = array_values(array_filter(array_column($plan['lines'], 'problem')));
        if ($bad) {
            $add('inactive', FALSE, 'block', 'The closing entry needs ' . implode('; ', array_unique($bad)) . '. Make ' . (count($bad) === 1 ? 'it' : 'them') . ' active again, close the year, then deactivate.', 'accounts');
        }

        $tb = $this->ledger->trial_balance($e, 'adjusted');
        $add('balanced', $tb['balanced'], 'block', $tb['balanced']
            ? 'The trial balance at ' . $e . ' balances.'
            : 'The trial balance at ' . $e . ' does not balance. Run the integrity check before closing.', 'reports/trial-balance?as_of=' . $e);

        $today = company_today();
        if ($today <= $e) {
            $add('ended', FALSE, 'warn', $fy['name'] . ' ends on ' . $e . '. Close it only when every entry for it is in: once it is closed, nothing more can be dated in it.');
        }

        $rej = (int) $this->db->where('entry_date >=', $s)->where('entry_date <=', $e)->where('status', 'rejected')->count_all_results('gp_journals');
        if ($rej) $add('rejected', FALSE, 'warn', $rej . ' rejected entr' . ($rej === 1 ? 'y is' : 'ies are') . ' dated in ' . $fy['name'] . '. Once it is closed they can no longer be posted.', 'journals?status=rejected&from=' . $s . '&to=' . $e);

        if ($this->db->table_exists('gp_documents')) {
            $d = (int) $this->db->where('doc_date >=', $s)->where('doc_date <=', $e)->where('status', 'draft')->count_all_results('gp_documents');
            if ($d) $add('documents', FALSE, 'warn', $d . ' invoice' . ($d === 1 ? ' or bill is' : 's or bills are') . ' still drafts dated in ' . $fy['name'] . '.');
            $st = (int) $this->db->where('settle_date >=', $s)->where('settle_date <=', $e)->where('status', 'draft')->count_all_results('gp_settlements');
            if ($st) $add('settlements', FALSE, 'warn', $st . ' receipt' . ($st === 1 ? ' or payment is' : 's or payments are') . ' still drafts dated in ' . $fy['name'] . '.');
        }

        $bank = (int) $this->db->where('statement_date >=', $s)->where('statement_date <=', $e)->where('status', 'open')->count_all_results('gp_bank_statements');
        if ($bank) $add('bank', FALSE, 'warn', $bank . ' bank statement' . ($bank === 1 ? ' is' : 's are') . ' not reconciled yet.', 'banking');

        $assets = (int) $this->db->where('status !=', 'disposed')->where('depreciation_start <=', $e)->count_all_results('gp_assets');
        if ($assets) {
            $missing = $this->db->query(
                'SELECT p.name FROM gp_periods p LEFT JOIN gp_depreciation_runs r ON r.period_id = p.id
                  WHERE p.fiscal_year_id = ? AND r.id IS NULL AND p.end_date >= (SELECT MIN(depreciation_start) FROM gp_assets)
                  ORDER BY p.period_no', [(int) $fy['id']]
            )->result_array();
            if ($missing) $add('depreciation', FALSE, 'warn', 'Depreciation has not been run for ' . implode(', ', array_column($missing, 'name')) . '.', 'depreciation');
        }

        $next = $this->db->where('start_date >', $e)->limit(1)->get('gp_fiscal_years')->row_array();
        $add('next_year', (bool) $next, 'warn', $next
            ? $next['name'] . ' is open for the entries that follow.'
            : 'No fiscal year follows ' . $fy['name'] . ' yet. Open the next one so work can continue after the closing.', 'periods');

        if ($this->_coop()) {
            $p = $this->_percentages(NULL);
            $odd = [];
            if ($p['reserve'] < 10) $odd[] = 'the reserve fund is under 10 %';
            if ($p['cetf'] > 10)    $odd[] = 'the education and training fund is over 10 %';
            if ($p['cdf'] < 3)      $odd[] = 'the community development fund is under 3 %';
            if ($p['optional'] > 7) $odd[] = 'the optional fund is over 7 %';
            $add('percentages', ! $odd, 'warn', $odd
                ? 'Check the allocation percentages against your by-laws: ' . implode(', ', $odd) . '.'
                : 'The allocation percentages are within the usual CDA limits.', 'settings');
        }

        return $c;
    }

    /** Settings' percentages, overridden by $override where given. */
    private function _percentages($override)
    {
        $p = [];
        foreach (self::FUNDS as $k => $f) {
            if ($f['setting'] === NULL) continue;
            $v = is_array($override) && isset($override[$k]) && is_numeric($override[$k]) ? (float) $override[$k] : (float) shop_cfg($f['setting'], 0);
            $p[$k] = round($v, 2);
        }
        return $p;
    }

    /** Post the allocation entry and its record. @return int journal id */
    private function _post_allocation(array $fy, $ns, $date, $user_id, $override)
    {
        $pct = $this->_percentages($override);
        foreach ($pct as $k => $v) {
            if ($v < 0 || $v > 100) throw new DomainException('Each percentage must be between 0 and 100.');
        }
        if ($pct['reserve'] + $pct['cetf'] + $pct['cdf'] + $pct['optional'] > 100) {
            throw new DomainException('The funds add up to more than 100 % of the net surplus.');
        }

        $closing = $this->_closing_account();
        if ( ! $closing) throw new DomainException('Choose the closing account (undivided net surplus) in Settings › Account defaults.');

        $parts = self::split($ns, $pct);
        $lines = [['account_id' => (int) $closing['id'], 'debit_cents' => $ns, 'credit_cents' => 0, 'memo' => 'Net surplus of ' . $fy['name'] . ' allocated']];
        foreach (self::FUNDS as $k => $f) {
            if ($parts[$k] <= 0) continue;
            $code = trim((string) shop_cfg($f['account'], ''));
            $a    = $code !== '' ? $this->accounts->find_by_code($code) : NULL;
            if ( ! $a) throw new DomainException('Choose the account for the ' . lcfirst($f['label']) . ' in Settings › Co-operative.');
            $lines[] = ['account_id' => (int) $a['id'], 'debit_cents' => 0, 'credit_cents' => $parts[$k],
                        'memo' => $f['label'] . (isset($pct[$k]) ? ' (' . rtrim(rtrim(number_format($pct[$k], 2), '0'), '.') . ' %' . ($k === 'isc' ? ' of the remainder' : '') . ')' : '')];
        }

        list($jid, $errs) = $this->Journal_model->post_system([
            'book'        => 'closing',
            'entry_date'  => $date,
            'reference'   => $fy['name'],
            'description' => 'Allocation of the net surplus of ' . $fy['name'] . ' (' . money_format_cents($ns) . ')',
        ], $lines, $user_id, 'closing', (int) $fy['id']);
        if ( ! $jid) throw new DomainException(reset($errs));

        $this->db->insert('gp_surplus_allocations', [
            'fiscal_year_id'    => (int) $fy['id'],
            'net_surplus_cents' => $ns,
            'reserve_cents'     => $parts['reserve'],
            'cetf_cents'        => $parts['cetf'],
            'cdf_cents'         => $parts['cdf'],
            'optional_cents'    => $parts['optional'],
            'isc_cents'         => $parts['isc'],
            'patronage_cents'   => $parts['patronage'],
            'percentages_json'  => json_encode($pct),
            'journal_id'        => $jid,
            'created_by'        => (int) $user_id,
            'created_at'        => date('Y-m-d H:i:s'),
        ]);
        return $jid;
    }

    /** The allocation row with its journal's number and date, or NULL. */
    private function _allocation_row($fy_id)
    {
        return $this->db->query(
            'SELECT s.*, j.journal_no, j.entry_date FROM gp_surplus_allocations s
               LEFT JOIN gp_journals j ON j.id = s.journal_id WHERE s.fiscal_year_id = ? LIMIT 1', [(int) $fy_id]
        )->row_array() ?: NULL;
    }

    private function _allocation_view(array $fy, array $plan, $alloc)
    {
        $pct = $alloc ? (json_decode($alloc['percentages_json'], TRUE) ?: []) : $this->_percentages(NULL);
        $ns  = $alloc ? (int) $alloc['net_surplus_cents'] : max(0, $plan['net_income_cents']);
        $amt = $alloc ? [
            'reserve' => (int) $alloc['reserve_cents'], 'cetf' => (int) $alloc['cetf_cents'], 'cdf' => (int) $alloc['cdf_cents'],
            'optional' => (int) $alloc['optional_cents'], 'isc' => (int) $alloc['isc_cents'], 'patronage' => (int) $alloc['patronage_cents'],
        ] : self::split($ns, $pct);

        $parts = [];
        foreach (self::FUNDS as $k => $f) {
            $code = trim((string) shop_cfg($f['account'], ''));
            $a    = $code !== '' ? $this->accounts->find_by_code($code) : NULL;
            $parts[] = ['key' => $k, 'label' => $f['label'], 'pct' => $pct[$k] ?? NULL, 'amount_cents' => $amt[$k],
                        'account' => $a ? ['code' => $a['code'], 'name' => $a['name']] : NULL];
        }
        return [
            'net_surplus_cents' => $ns,
            'parts'             => $parts,
            'done'              => $alloc ? ['journal_id' => (int) $alloc['journal_id'], 'journal_no' => $alloc['journal_no'], 'date' => $alloc['entry_date']] : NULL,
        ];
    }

    /** The year's closing-book entries and their reversals. */
    private function _journals($fy_id)
    {
        $rows = $this->db->query(
            "SELECT j.id, j.journal_no, j.entry_date, j.description, j.total_cents, j.reversed_by_id, r.journal_no AS reversed_by_no
               FROM gp_journals j LEFT JOIN gp_journals r ON r.id = j.reversed_by_id
              WHERE j.source = 'closing' AND j.source_id = ? AND j.status = 'posted'
              ORDER BY j.id", [(int) $fy_id]
        )->result_array();
        return array_map(function ($r) {
            return ['id' => (int) $r['id'], 'journal_no' => $r['journal_no'], 'entry_date' => $r['entry_date'], 'description' => $r['description'],
                    'total_cents' => (int) $r['total_cents'], 'reversed_by_id' => $r['reversed_by_id'] ? (int) $r['reversed_by_id'] : NULL,
                    'reversed_by_no' => $r['reversed_by_no']];
        }, $rows);
    }

    private function _user_name($id)
    {
        if ( ! $id) return NULL;
        $u = $this->db->select('full_name, username')->get_where('gp_users', ['id' => (int) $id], 1)->row_array();
        return $u ? ($u['full_name'] ?: $u['username']) : '#' . (int) $id;
    }

    private function _audit($user_id, $action, $fy_id, array $detail)
    {
        if ( ! log_admin_action(['user_id' => (int) $user_id], $action, 'fiscal_year', (int) $fy_id, $detail)) {
            throw new DomainException('The audit trail could not be written, so nothing was changed.');
        }
    }

    /** Roll back and turn what went wrong into [NULL, message]. */
    private function _fail(Throwable $t, $what)
    {
        $this->db->trans_rollback();
        if ($t instanceof DomainException) return [NULL, $t->getMessage()];
        if ($t instanceof mysqli_sql_exception || ! ($t instanceof RuntimeException)) {
            log_message('error', '[Year_end_model] ' . $what . ': ' . $t->getMessage());
            return [NULL, 'The database refused the change, and nothing was saved.'];
        }
        return [NULL, $t->getMessage()];      // a posting refused inside Journal_model, with its reason
    }
}
