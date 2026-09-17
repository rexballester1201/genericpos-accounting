<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Books_lib.php — the columnar books: cash receipts, cash disbursements, sales, purchases
 *
 * GenericPOS Accounting · read-only, over posted entries
 *
 * The books a Philippine business keeps by hand have a column for each
 * account that recurs on every line — cash, receivables, sales, output VAT —
 * and a "sundry" column for everything else. One posted entry is one row:
 * its amount on each recurring account goes in that account's column, every
 * other line goes to sundry with its account named.
 *
 * A special column holds a signed amount on its own side: a credit note in
 * the sales book shows in parentheses under Accounts receivable and Sales, so
 * the columns still add up to what the ledger holds. Each row therefore
 * balances: Σ debit-side columns + sundry debits = Σ credit-side columns +
 * sundry credits.
 *
 * Which accounts a column takes: 'cash' (cash-flow class cash, inherited from
 * the header), 'control:ar|ap', 'type:income', or 'acct:<setting>' (the
 * account Settings › Account defaults names). A line takes the first column
 * that fits.
 */
class Books_lib
{
    const LAYOUTS = [
        'cash_receipts' => [
            ['key' => 'cash',  'label' => 'Cash',                 'side' => 'dr', 'match' => 'cash'],
            ['key' => 'cwt',   'label' => 'Creditable w/tax',     'side' => 'dr', 'match' => 'acct:acct_cwt_receivable'],
            ['key' => 'ar',    'label' => 'Accounts receivable',  'side' => 'cr', 'match' => 'control:ar'],
            ['key' => 'sales', 'label' => 'Sales',                'side' => 'cr', 'match' => 'type:income'],
            ['key' => 'vat',   'label' => 'Output VAT',           'side' => 'cr', 'match' => 'acct:acct_output_vat'],
        ],
        'cash_disbursements' => [
            ['key' => 'cash',  'label' => 'Cash',                 'side' => 'cr', 'match' => 'cash'],
            ['key' => 'ap',    'label' => 'Accounts payable',     'side' => 'dr', 'match' => 'control:ap'],
            ['key' => 'vat',   'label' => 'Input VAT',            'side' => 'dr', 'match' => 'acct:acct_input_vat'],
            ['key' => 'ewt',   'label' => 'Withholding tax',      'side' => 'cr', 'match' => 'acct:acct_ewt_payable'],
        ],
        'sales' => [
            ['key' => 'ar',    'label' => 'Accounts receivable',  'side' => 'dr', 'match' => 'control:ar'],
            ['key' => 'sales', 'label' => 'Sales',                'side' => 'cr', 'match' => 'type:income'],
            ['key' => 'vat',   'label' => 'Output VAT',           'side' => 'cr', 'match' => 'acct:acct_output_vat'],
        ],
        'purchases' => [
            ['key' => 'ap',        'label' => 'Accounts payable', 'side' => 'cr', 'match' => 'control:ap'],
            ['key' => 'purchases', 'label' => 'Purchases',        'side' => 'dr', 'match' => 'acct:acct_default_purchases'],
            ['key' => 'vat',       'label' => 'Input VAT',        'side' => 'dr', 'match' => 'acct:acct_input_vat'],
        ],
    ];

    const TITLES = [
        'cash_receipts' => 'Cash Receipts Book', 'cash_disbursements' => 'Cash Disbursements Book',
        'sales' => 'Sales Book', 'purchases' => 'Purchase Book',
    ];

    /** @var CI_Controller */
    private $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
    }

    /**
     * @return array book, title, from, to, columns, rows, totals, count, truncated
     */
    public function build($book, $from, $to, $cap = 5000)
    {
        $db    = $this->CI->db;
        $accts = $this->_accounts();
        $cols  = self::LAYOUTS[$book];
        foreach ($cols as &$c) {
            if (strpos($c['match'], 'acct:') === 0) {
                $code = trim((string) shop_cfg(substr($c['match'], 5), ''));
                $c['account'] = NULL;
                foreach ($accts as $a) if ($a['code'] === $code) { $c['account'] = $a['id']; break; }
            }
        }
        unset($c);

        $count = (int) $db->query("SELECT COUNT(*) AS n FROM gp_journals WHERE status = 'posted' AND book = ? AND entry_date BETWEEN ? AND ?", [$book, $from, $to])->row()->n;
        $js = $db->query(
            "SELECT id, journal_no, entry_date, reference, party_name, description FROM gp_journals
              WHERE status = 'posted' AND book = ? AND entry_date BETWEEN ? AND ?
              ORDER BY entry_date, journal_no LIMIT " . (int) $cap, [$book, $from, $to]
        )->result_array();

        $rows = [];
        foreach ($js as $j) {
            $rows[(int) $j['id']] = [
                'id' => (int) $j['id'], 'date' => $j['entry_date'], 'journal_no' => $j['journal_no'], 'reference' => $j['reference'],
                'party' => $j['party_name'], 'description' => $j['description'],
                'cols' => array_fill_keys(array_column($cols, 'key'), 0), 'sundry' => [],
            ];
        }
        foreach (array_chunk(array_keys($rows), 1000) as $ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            foreach ($db->query('SELECT journal_id, account_id, debit_cents, credit_cents, memo FROM gp_journal_lines WHERE journal_id IN (' . $in . ') ORDER BY journal_id, line_no', $ids)->result_array() as $l) {
                $a = $accts[(int) $l['account_id']] ?? NULL;
                $d = (int) $l['debit_cents'];
                $cr = (int) $l['credit_cents'];
                $hit = NULL;
                if ($a) foreach ($cols as $c) { if ($this->_fits($c, $a)) { $hit = $c; break; } }
                $r =& $rows[(int) $l['journal_id']];
                if ($hit) {
                    $r['cols'][$hit['key']] += $hit['side'] === 'dr' ? $d - $cr : $cr - $d;
                } else {
                    $r['sundry'][] = ['code' => $a ? $a['code'] : '?', 'name' => $a ? $a['name'] : '', 'debit_cents' => $d, 'credit_cents' => $cr, 'memo' => $l['memo']];
                }
                unset($r);
            }
        }

        $tot = ['cols' => array_fill_keys(array_column($cols, 'key'), 0), 'sundry_debit_cents' => 0, 'sundry_credit_cents' => 0];
        foreach ($rows as $r) {
            foreach ($r['cols'] as $k => $v) $tot['cols'][$k] += $v;
            foreach ($r['sundry'] as $s) { $tot['sundry_debit_cents'] += $s['debit_cents']; $tot['sundry_credit_cents'] += $s['credit_cents']; }
        }
        $dr = $tot['sundry_debit_cents'];
        $crt = $tot['sundry_credit_cents'];
        foreach ($cols as $c) { if ($c['side'] === 'dr') $dr += $tot['cols'][$c['key']]; else $crt += $tot['cols'][$c['key']]; }
        $tot['debit_cents']  = $dr;
        $tot['credit_cents'] = $crt;
        $tot['balanced']     = $dr === $crt;

        return [
            'book'      => $book,
            'title'     => self::TITLES[$book],
            'from'      => $from,
            'to'        => $to,
            'columns'   => array_map(function ($c) { return ['key' => $c['key'], 'label' => $c['label'], 'side' => $c['side']]; }, $cols),
            'rows'      => array_values($rows),
            'totals'    => $tot,
            'count'     => $count,
            'truncated' => $count > count($rows),
        ];
    }

    private function _fits(array $c, array $a)
    {
        if ($c['match'] === 'cash')        return $a['cash'];
        if ($c['match'] === 'control:ar')  return $a['control'] === 'ar';
        if ($c['match'] === 'control:ap')  return $a['control'] === 'ap';
        if ($c['match'] === 'type:income') return $a['type'] === 'income';
        return isset($c['account']) && $c['account'] === $a['id'];
    }

    /** Every account with its inherited cash-flow class resolved. */
    private function _accounts()
    {
        $raw = [];
        foreach ($this->CI->db->select('id, code, name, type, control, parent_id, cash_flow')->get('gp_accounts')->result_array() as $a) $raw[(int) $a['id']] = $a;
        $out = [];
        foreach ($raw as $id => $a) {
            $cf = NULL;
            for ($x = $id, $i = 0; $x && $i < 12 && $cf === NULL; $x = (int) ($raw[$x]['parent_id'] ?? 0), $i++) {
                if ( ! empty($raw[$x]['cash_flow'])) $cf = $raw[$x]['cash_flow'];
            }
            $out[$id] = ['id' => $id, 'code' => $a['code'], 'name' => $a['name'], 'type' => $a['type'], 'control' => $a['control'], 'cash' => $cf === 'cash'];
        }
        return $out;
    }
}
