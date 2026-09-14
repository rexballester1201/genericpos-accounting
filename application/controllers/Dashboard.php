<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Dashboard.php — the front page, for every role
 *
 * GenericPOS Accounting
 *
 *   GET /api/v1/dashboard          the position today, the year so far, and the work waiting
 *   GET /api/v1/dashboard/badges   the small counts in the navigation
 *
 * Every figure is a sum over posted lines (the gp_ledger view) as of today in
 * the company's time zone. Revenue and expenses leave out closing entries, so
 * the year's result still shows after the year has been closed.
 */
class Dashboard extends CI_Controller
{
    private $claims;

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->claims = viewer_check();
        $this->load->model('Period_model', 'periods');
    }

    private function uid() { return (int) $this->claims['user_id']; }

    private function one($sql, array $args = [])
    {
        $r = $this->db->query($sql, $args)->row_array();
        return $r ? (int) reset($r) : 0;
    }

    /** GET /api/v1/dashboard */
    public function index()
    {
        require_method('GET');

        $today  = company_today();
        $fy     = $this->periods->year_for_date($today);
        $period = $this->periods->period_for_date($today);
        $from   = $fy ? $fy['start_date'] : substr($today, 0, 4) . '-01-01';

        $cash = $this->one("SELECT COALESCE(SUM(l.net_cents), 0) FROM gp_ledger l JOIN gp_accounts a ON a.id = l.account_id
                             WHERE FIND_IN_SET('cash', a.tags) AND l.entry_date <= ?", [$today]);
        $ar   = $this->one("SELECT COALESCE(SUM(l.net_cents), 0) FROM gp_ledger l JOIN gp_accounts a ON a.id = l.account_id
                             WHERE a.control = 'ar' AND l.entry_date <= ?", [$today]);
        $ap   = -$this->one("SELECT COALESCE(SUM(l.net_cents), 0) FROM gp_ledger l JOIN gp_accounts a ON a.id = l.account_id
                             WHERE a.control = 'ap' AND l.entry_date <= ?", [$today]);

        $revenue = 0;
        $expense = 0;
        foreach ($this->db->query(
            "SELECT a.type, COALESCE(SUM(l.net_cents), 0) AS net FROM gp_ledger l JOIN gp_accounts a ON a.id = l.account_id
              WHERE a.type IN ('income', 'expense') AND l.book <> 'closing' AND l.entry_date BETWEEN ? AND ?
              GROUP BY a.type", [$from, $today]
        )->result_array() as $r) {
            if ($r['type'] === 'income') $revenue = -(int) $r['net'];
            else                         $expense = (int) $r['net'];
        }

        $trend = [];
        if ($fy) {
            $by = [];
            foreach ($this->db->query(
                "SELECT l.period_id, a.type, SUM(l.net_cents) AS net FROM gp_ledger l JOIN gp_accounts a ON a.id = l.account_id
                  WHERE l.fiscal_year_id = ? AND a.type IN ('income', 'expense') AND l.book <> 'closing'
                  GROUP BY l.period_id, a.type", [(int) $fy['id']]
            )->result_array() as $r) {
                $by[(int) $r['period_id']][$r['type']] = (int) $r['net'];
            }
            foreach ($this->periods->periods($fy['id']) as $p) {
                $trend[] = [
                    'name'          => $p['name'],
                    'start_date'    => $p['start_date'],
                    'status'        => $p['status'],
                    'future'        => $p['start_date'] > $today,
                    'revenue_cents' => -($by[(int) $p['id']]['income'] ?? 0),
                    'expense_cents' => $by[(int) $p['id']]['expense'] ?? 0,
                ];
            }
        }

        $overdue = $this->db->query(
            "SELECT COUNT(*) AS n, COALESCE(SUM(total_cents - applied_cents), 0) AS s FROM gp_documents
              WHERE doc_type = 'invoice' AND status = 'posted' AND total_cents > applied_cents AND due_date < ?", [$today]
        )->row_array();

        $count = function ($where, array $args = []) {
            return (int) $this->db->query('SELECT COUNT(*) AS n FROM gp_journals WHERE ' . $where, $args)->row()->n;
        };
        $uid = $this->uid();

        $recent = array_map(function ($r) {
            return ['id' => (int) $r['id'], 'journal_no' => $r['journal_no'], 'entry_date' => $r['entry_date'], 'book' => $r['book'],
                    'description' => $r['description'], 'total_cents' => (int) $r['total_cents']];
        }, $this->db->query(
            "SELECT id, journal_no, entry_date, book, description, total_cents FROM gp_journals
              WHERE status = 'posted' ORDER BY approved_at DESC, id DESC LIMIT 8"
        )->result_array());

        return json_response([
            'today'       => $today,
            'fiscal_year' => $fy ? ['name' => $fy['name'], 'start_date' => $fy['start_date'], 'end_date' => $fy['end_date'], 'status' => $fy['status']] : NULL,
            'period'      => $period ? ['name' => $period['name'], 'status' => $period['status']] : NULL,
            'position'    => [
                'cash_cents'        => $cash,
                'receivables_cents' => $ar,
                'payables_cents'    => $ap,
                'overdue_cents'     => (int) $overdue['s'],
                'overdue_count'     => (int) $overdue['n'],
            ],
            'year_to_date' => [
                'from'          => $from,
                'revenue_cents' => $revenue,
                'expense_cents' => $expense,
                'net_cents'     => $revenue - $expense,
            ],
            'trend'  => $trend,
            'queues' => [
                'awaiting_approval' => $count("status = 'submitted'"),
                'my_submitted'      => $count("status = 'submitted' AND created_by = ?", [$uid]),
                'my_rejected'       => $count("status = 'rejected' AND created_by = ?", [$uid]),
                'my_drafts'         => $count("status = 'draft' AND created_by = ?", [$uid]),
                'periods_to_close'  => array_map(function ($p) { return ['id' => (int) $p['id'], 'name' => $p['name'], 'end_date' => $p['end_date']]; },
                    $this->db->query("SELECT id, name, end_date FROM gp_periods WHERE status = 'open' AND end_date < ? ORDER BY start_date", [$today])->result_array()),
            ],
            'recent' => $recent,
        ], 'Dashboard');
    }

    /** GET /api/v1/dashboard/badges */
    public function badges()
    {
        require_method('GET');
        $this->load->model('Notification_model', 'notify');
        $uid = $this->uid();
        return json_response([
            'unread'            => $this->notify->unread_count($uid),
            'awaiting_approval' => role_rank($this->claims['role']) >= 3
                ? (int) $this->db->where('status', 'submitted')->count_all_results('gp_journals') : 0,
            'my_rejected'       => (int) $this->db->where('status', 'rejected')->where('created_by', $uid)->count_all_results('gp_journals'),
        ], 'Badges');
    }
}
