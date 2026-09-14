<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Period_model.php — fiscal years and their monthly periods
 *
 * GenericPOS Accounting · tables gp_fiscal_years, gp_periods
 *
 * A fiscal year starts on the 1st of a month and has twelve monthly periods.
 * Period life: open → closed (month-end) ⇄ open → locked (final). A year
 * closes with its closing entry (phase C of PLAN.md).
 *
 * Every change is audited in the same transaction as the change itself.
 * Closing and reopening a month is an accountant's job; locking one for good
 * and opening a new fiscal year are an administrator's (the controllers check).
 *
 * NOTE: THE ONE QUESTION EVERY POSTING ASKS: open_period_for($date). It
 * answers with the period, or with WHY the date cannot take an entry —
 * "no period covers it" is a refusal, never a pass.
 *
 * NOTE: a change that fails inside a caller's own transaction THROWS rather
 * than returning its error, because CodeIgniter's nested rollback would change
 * nothing (see Journal_model).
 */
class Period_model extends CI_Model
{
    const FY = 'gp_fiscal_years';
    const P  = 'gp_periods';

    // =========================================================================
    // READ
    // =========================================================================

    public function year($id)
    {
        return $this->db->get_where(self::FY, ['id' => (int) $id], 1)->row_array() ?: NULL;
    }

    public function years()
    {
        return $this->db->order_by('start_date', 'DESC')->get(self::FY)->result_array();
    }

    public function year_for_date($date)
    {
        return $this->db->where('start_date <=', $date)->where('end_date >=', $date)
                        ->limit(1)->get(self::FY)->row_array() ?: NULL;
    }

    public function period($id)
    {
        return $this->db->get_where(self::P, ['id' => (int) $id], 1)->row_array() ?: NULL;
    }

    public function periods($fiscal_year_id)
    {
        return $this->db->where('fiscal_year_id', (int) $fiscal_year_id)->order_by('period_no', 'ASC')
                        ->get(self::P)->result_array();
    }

    /** The period covering $date with its year's status, or NULL. */
    public function period_for_date($date)
    {
        return $this->db->query(
            'SELECT p.*, fy.name AS fiscal_year_name, fy.status AS fiscal_year_status
               FROM gp_periods p JOIN gp_fiscal_years fy ON fy.id = p.fiscal_year_id
              WHERE p.start_date <= ? AND p.end_date >= ? LIMIT 1',
            [$date, $date]
        )->row_array() ?: NULL;
    }

    /**
     * May an entry dated $date post?
     * @return array [period row|NULL, error message '' when it may]
     */
    public function open_period_for($date)
    {
        if ( ! self::valid_date($date)) return [NULL, 'Enter a valid date.'];

        $p = $this->period_for_date($date);
        if ( ! $p)                                  return [NULL, 'No fiscal period covers ' . $date . '. Create the fiscal year first.'];
        if ($p['fiscal_year_status'] !== 'open')    return [NULL, $p['fiscal_year_name'] . ' is closed.'];
        if ($p['status'] === 'locked')              return [NULL, $p['name'] . ' is locked.'];
        if ($p['status'] !== 'open')                return [NULL, $p['name'] . ' is closed. An accountant can reopen it.'];
        return [$p, ''];
    }

    public static function valid_date($d)
    {
        if ( ! is_string($d) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return FALSE;
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** The date the next fiscal year must start on, or NULL before the first one exists. */
    public function next_year_start()
    {
        $last = $this->db->order_by('end_date', 'DESC')->limit(1)->get(self::FY)->row_array();
        return $last ? date('Y-m-d', strtotime($last['end_date'] . ' +1 day')) : NULL;
    }

    /** Periods an entry may be dated in right now. */
    public function open_periods()
    {
        return $this->db->query(
            "SELECT p.id, p.name, p.start_date, p.end_date, fy.name AS fiscal_year
               FROM gp_periods p JOIN gp_fiscal_years fy ON fy.id = p.fiscal_year_id
              WHERE p.status = 'open' AND fy.status = 'open'
              ORDER BY p.start_date"
        )->result_array();
    }

    /** Every fiscal year, newest first, each with its periods and what is in them. */
    public function overview()
    {
        $years = $this->years();
        if ( ! $years) return [];

        $periods = $this->db->order_by('start_date', 'ASC')->get(self::P)->result_array();
        $counts  = [];
        foreach ($this->db->query('SELECT period_id, status, COUNT(*) AS n FROM gp_journals GROUP BY period_id, status')->result_array() as $r) {
            $counts[(int) $r['period_id']][$r['status']] = (int) $r['n'];
        }

        $uids  = array_values(array_unique(array_filter(array_map('intval', array_merge(
            array_column($periods, 'closed_by'), array_column($years, 'closed_by'))))));
        $names = [];
        if ($uids) {
            foreach ($this->db->select('id, full_name, username')->where_in('id', $uids)->get('gp_users')->result_array() as $u) {
                $names[(int) $u['id']] = $u['full_name'] ?: $u['username'];
            }
        }
        $who = function ($uid) use ($names) { return $uid ? ($names[(int) $uid] ?? '#' . (int) $uid) : NULL; };

        $by_year = [];
        foreach ($periods as $p) {
            $c = $counts[(int) $p['id']] ?? [];
            $by_year[(int) $p['fiscal_year_id']][] = [
                'id'         => (int) $p['id'],
                'period_no'  => (int) $p['period_no'],
                'name'       => $p['name'],
                'start_date' => $p['start_date'],
                'end_date'   => $p['end_date'],
                'status'     => $p['status'],
                'closed_by'  => $who($p['closed_by']),
                'closed_at'  => $p['closed_at'],
                'posted'     => $c['posted'] ?? 0,
                'waiting'    => ($c['draft'] ?? 0) + ($c['submitted'] ?? 0),
                'rejected'   => $c['rejected'] ?? 0,
            ];
        }

        return array_map(function ($y) use ($by_year, $who) {
            return [
                'id'         => (int) $y['id'],
                'name'       => $y['name'],
                'start_date' => $y['start_date'],
                'end_date'   => $y['end_date'],
                'status'     => $y['status'],
                'closed_by'  => $who($y['closed_by']),
                'closed_at'  => $y['closed_at'],
                'periods'    => $by_year[(int) $y['id']] ?? [],
            ];
        }, $years);
    }

    // =========================================================================
    // WRITE
    // =========================================================================

    /**
     * Create the fiscal year that starts on $start (the 1st of a month) and its
     * twelve periods. Years must follow on from each other: the first one can
     * start anywhere, every later one starts the day after the last one ends.
     *
     * @return array [fiscal year id|0, error '']
     */
    public function create_year($start, $user_id = NULL)
    {
        $start = trim((string) $start);
        if ( ! self::valid_date($start) || substr($start, 8, 2) !== '01') return [0, 'A fiscal year starts on the first day of a month.'];

        $fy  = 0;
        $err = $this->_tx(function () use ($start, $user_id, &$fy) {
            $last = $this->db->query('SELECT * FROM gp_fiscal_years ORDER BY end_date DESC LIMIT 1 FOR UPDATE')->row_array();
            if ($last) {
                $expected = date('Y-m-d', strtotime($last['end_date'] . ' +1 day'));
                if ($start !== $expected) return 'The next fiscal year must start on ' . $expected . '.';
            }

            $end  = date('Y-m-d', strtotime($start . ' +12 months -1 day'));
            $y1   = substr($start, 0, 4);
            $y2   = substr($end, 0, 4);
            $name = $y1 === $y2 ? 'FY' . $y1 : 'FY' . $y1 . '-' . substr($y2, 2);
            $now  = date('Y-m-d H:i:s');

            $this->db->insert(self::FY, ['name' => $name, 'start_date' => $start, 'end_date' => $end, 'status' => 'open', 'created_at' => $now]);
            $fy = (int) $this->db->insert_id();
            for ($i = 0; $i < 12; $i++) {
                $ps = date('Y-m-d', strtotime($start . ' +' . $i . ' months'));
                $pe = date('Y-m-t', strtotime($ps));
                $this->db->insert(self::P, [
                    'fiscal_year_id' => $fy, 'period_no' => $i + 1, 'name' => date('M Y', strtotime($ps)),
                    'start_date' => $ps, 'end_date' => $pe, 'status' => 'open',
                ]);
            }
            return $this->_audit($user_id, 'fiscal_year.create', 'fiscal_year', $fy, ['name' => $name, 'start' => $start, 'end' => $end]);
        });

        return $err === '' ? [$fy, ''] : [0, $err];
    }

    /**
     * Change a period's status.
     *   open   → closed   month-end
     *   closed → open     reopen (the year must still be open)
     *   closed → locked   final; nothing reopens a locked period
     * @return string error, '' on success
     */
    public function set_status($period_id, $to, $user_id)
    {
        if ( ! in_array($to, ['open', 'closed', 'locked'], TRUE)) return 'Choose open, closed or locked.';

        return $this->_tx(function () use ($period_id, $to, $user_id) {
            $p = $this->db->query(
                'SELECT p.*, fy.status AS fy_status, fy.name AS fy_name
                   FROM gp_periods p JOIN gp_fiscal_years fy ON fy.id = p.fiscal_year_id
                  WHERE p.id = ? FOR UPDATE', [(int) $period_id]
            )->row_array();
            if ( ! $p) return 'No such period.';

            $from = $p['status'];
            if ($from === $to) return $p['name'] . ' is already ' . $to . '.';
            $ok = ($from === 'open' && $to === 'closed') || ($from === 'closed' && in_array($to, ['open', 'locked'], TRUE));
            if ( ! $ok) return ['open' => 'An open', 'closed' => 'A closed', 'locked' => 'A locked'][$from] . ' period cannot become ' . $to . '.';
            if ($to === 'open' && $p['fy_status'] !== 'open') return $p['fy_name'] . ' is closed; its periods stay closed.';

            if ($to === 'closed') {
                $pending = $this->db->where('period_id', (int) $period_id)->where_in('status', ['draft', 'submitted'])
                                    ->count_all_results('gp_journals');
                if ($pending > 0) {
                    return $pending . ' entr' . ($pending === 1 ? 'y is' : 'ies are') . ' still waiting in ' . $p['name'] . '. Post, reject or cancel them first.';
                }
            }

            /* Locking keeps the record of who CLOSED the month; the lock itself
               is in the audit log. */
            $upd = ['status' => $to];
            if ($to === 'closed') { $upd['closed_by'] = (int) $user_id; $upd['closed_at'] = date('Y-m-d H:i:s'); }
            if ($to === 'open')   { $upd['closed_by'] = NULL;           $upd['closed_at'] = NULL; }
            $this->db->where('id', (int) $period_id)->update(self::P, $upd);

            $action = ['closed' => 'period.close', 'open' => 'period.reopen', 'locked' => 'period.lock'][$to];
            return $this->_audit($user_id, $action, 'period', (int) $period_id, ['period' => $p['name'], 'from' => $from, 'to' => $to]);
        });
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    /** Run $fn in a transaction: '' commits, an error string or an exception rolls back. */
    private function _tx(callable $fn)
    {
        $nested = $this->_depth() > 0;
        $this->db->trans_begin();
        try {
            $err = (string) $fn();
        } catch (Throwable $t) {
            log_message('error', '[Period_model] ' . $t->getMessage());
            $err = 'The database refused the change.';
        }
        if ($err === '') {
            $this->db->trans_commit();
            return '';
        }
        $this->db->trans_rollback();
        if ($nested) throw new RuntimeException($err);
        return $err;
    }

    private function _depth()
    {
        $db = $this->db;
        return (int) (function () { return $this->_trans_depth; })->call($db);
    }

    /** '' when written (or when there is no person to record, as in the demo seed); otherwise an error. */
    private function _audit($user_id, $action, $type, $id, $detail = NULL)
    {
        if ( ! $user_id) return '';
        return log_admin_action(['user_id' => (int) $user_id], $action, $type, (int) $id, $detail)
            ? '' : 'The audit trail could not be written, so nothing was changed.';
    }
}
