<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Reports.php — the ledger's reports (every role reads them)
 *
 * GenericPOS Accounting
 *
 *   GET /api/v1/reports/balance-sheet       ?as_of=&compare=prior_year_end|prior_year&levels=&zero=1
 *   GET /api/v1/reports/income-statement    ?from=&to=&compare=prior_period|prior_year|monthly&department=&levels=&zero=1
 *   GET /api/v1/reports/changes-in-equity   ?from=&to=&levels=
 *   GET /api/v1/reports/cash-flows          ?from=&to=&levels=
 *   GET /api/v1/reports/trial-balance       ?as_of=&kind=unadjusted|adjusted|post_closing&zero=1
 *   GET /api/v1/reports/general-ledger      ?account=&from=&to=
 *   GET /api/v1/reports/books               ?book=&from=&to=&page=&per_page=
 *   GET /api/v1/reports/analysis            ?as_of=
 *
 *   &format=csv on any of them returns the same report as a spreadsheet file.
 *
 * Periods default to the fiscal year to date (the books: this month). Every
 * answer carries `letterhead` — the company block, the report note and the
 * signatories from Settings — which a printed report needs and which a
 * non-administrator has no other way to read. `levels` is how deep the chart
 * is shown: 0 every account, 1 the main sections, 2 their line items.
 *
 * CSV files open straight in a spreadsheet: a UTF-8 byte-order mark, amounts
 * as plain numbers in major units, and any cell a spreadsheet would read as a
 * formula (=, +, -, @ at the start) defused with a leading apostrophe.
 */
class Reports extends CI_Controller
{
    const KIND_LABELS = ['unadjusted' => 'Unadjusted', 'adjusted' => 'Adjusted', 'post_closing' => 'Post-closing'];

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('Ledger_model', 'ledger');
    }

    /* Settings reach this request only once the constructor has run (the
       SettingsOverride hook comes after it), and the statements read the
       kind of organisation from Settings — so they load on first use. */
    private function _lib()
    {
        if ( ! isset($this->statements)) $this->load->library('Statement_lib', NULL, 'statements');
        return $this->statements;
    }

    // =========================================================================
    // FINANCIAL STATEMENTS
    // =========================================================================

    /** GET /api/v1/reports/balance-sheet */
    public function balance_sheet()
    {
        $claims = viewer_check();
        require_method('GET');
        $S = $this->_lib();

        $as_of   = $this->_date('as_of', company_today());
        $compare = (string) $this->input->get('compare');
        $dates   = [$as_of];
        if ($compare === 'prior_year_end') {
            $fy = $this->periods->year_for_date($as_of);
            $dates[] = $fy ? Statement_lib::day_before($fy['start_date']) : ((int) substr($as_of, 0, 4) - 1) . '-12-31';
        } elseif ($compare === 'prior_year') {
            $dates[] = Statement_lib::minus_year($as_of);
        } else {
            $compare = '';
        }
        $levels = $this->_levels(2);
        $zero   = (bool) $this->input->get('zero');

        $s = $S->balance_sheet($dates, $levels, $zero);
        if (report_csv_wanted($claims)) {
            return $this->_statement_csv('balance-sheet-' . $as_of . '.csv', $s, 'As of ' . $as_of,
                array_map(function ($c) { return 'As of ' . $c['as_of']; }, $s['columns']));
        }
        return json_response($s + [
            'params'     => ['as_of' => $as_of, 'compare' => $compare, 'levels' => $levels, 'zero' => $zero],
            'letterhead' => report_letterhead($claims),
        ], $s['title']);
    }

    /** GET /api/v1/reports/income-statement */
    public function income_statement()
    {
        $claims = viewer_check();
        require_method('GET');
        $S = $this->_lib();

        list($from, $to) = $this->_range('year');
        $compare = (string) $this->input->get('compare');
        $ranges  = [['from' => $from, 'to' => $to]];
        if ($compare === 'prior_year') {
            $ranges[] = ['from' => Statement_lib::minus_year($from), 'to' => Statement_lib::minus_year($to)];
        } elseif ($compare === 'prior_period') {
            $ranges[] = Statement_lib::prior_period($from, $to);
        } elseif ($compare === 'monthly') {
            $months = Statement_lib::months($from, $to);
            if (count($months) > 12) return json_invalid(['to' => 'Month by month covers at most twelve months. Choose a shorter period.']);
            $ranges = $months;
            if (count($months) > 1) $ranges[] = ['from' => $from, 'to' => $to, 'total' => TRUE];
        } else {
            $compare = '';
        }

        $departments = array_map(function ($d) {
            return ['id' => (int) $d['id'], 'code' => $d['code'], 'name' => $d['name']];
        }, $this->db->select('id, code, name')->order_by('code', 'ASC')->get('gp_departments')->result_array());
        $dept = (int) $this->input->get('department');
        if ($dept && ! in_array($dept, array_column($departments, 'id'), TRUE)) $dept = 0;
        $levels = $this->_levels(2);
        $zero   = (bool) $this->input->get('zero');

        $s = $S->income_statement($ranges, $levels, $zero, $dept);
        if (report_csv_wanted($claims)) {
            $period = 'For ' . $from . ' to ' . $to;
            if ($dept) foreach ($departments as $d) if ($d['id'] === $dept) $period .= ' · department ' . $d['code'] . ' ' . $d['name'];
            return $this->_statement_csv('income-statement-' . $from . '-to-' . $to . '.csv', $s, $period,
                array_map([$this, '_range_label'], $s['columns']));
        }
        return json_response($s + [
            'params'      => ['from' => $from, 'to' => $to, 'compare' => $compare, 'department' => $dept ?: NULL, 'levels' => $levels, 'zero' => $zero],
            'departments' => $departments,
            'letterhead'  => report_letterhead($claims),
        ], $s['title']);
    }

    /** GET /api/v1/reports/changes-in-equity */
    public function changes_in_equity()
    {
        $claims = viewer_check();
        require_method('GET');
        $S = $this->_lib();

        list($from, $to) = $this->_range('year');
        $levels = $this->_levels(0);
        $s = $S->changes_in_equity($from, $to, $levels);
        if (report_csv_wanted($claims)) {
            return $this->_statement_csv('changes-in-equity-' . $from . '-to-' . $to . '.csv', $s, 'For ' . $from . ' to ' . $to,
                ['Balance, ' . $from, $S->is_coop() ? 'Net surplus' : 'Net income', 'Other changes', 'Balance, ' . $to]);
        }
        return json_response($s + [
            'params'     => ['from' => $from, 'to' => $to, 'levels' => $levels],
            'letterhead' => report_letterhead($claims),
        ], $s['title']);
    }

    /** GET /api/v1/reports/cash-flows */
    public function cash_flows()
    {
        $claims = viewer_check();
        require_method('GET');
        $S = $this->_lib();

        list($from, $to) = $this->_range('year');
        $levels = $this->_levels(2);
        $s = $S->cash_flows($from, $to, $levels);
        if (report_csv_wanted($claims)) {
            return $this->_statement_csv('cash-flows-' . $from . '-to-' . $to . '.csv', $s, 'For ' . $from . ' to ' . $to, ['Amount']);
        }
        return json_response($s + [
            'params'     => ['from' => $from, 'to' => $to, 'levels' => $levels],
            'letterhead' => report_letterhead($claims),
        ], $s['title']);
    }

    // =========================================================================
    // THE TRIAL BALANCE, THE LEDGER AND THE BOOKS
    // =========================================================================

    /** GET /api/v1/reports/trial-balance */
    public function trial_balance()
    {
        $claims = viewer_check();
        require_method('GET');

        $as_of = $this->_date('as_of', company_today());
        $kind  = (string) $this->input->get('kind');
        if ( ! isset(self::KIND_LABELS[$kind])) $kind = 'adjusted';

        $tb = $this->ledger->trial_balance($as_of, $kind, (bool) $this->input->get('zero'));

        if (report_csv_wanted($claims)) {
            $m    = function ($c) { return $c ? money_major($c) : ''; };
            $rows = report_csv_head('Trial balance (' . strtolower(self::KIND_LABELS[$kind]) . ')', 'As of ' . $as_of);
            $rows[] = ['Code', 'Account', 'Debit', 'Credit'];
            foreach ($tb['rows'] as $r) $rows[] = [$r['code'], $r['name'], $m($r['debit_cents']), $m($r['credit_cents'])];
            if ($tb['unclosed_prior_cents'] !== 0) {
                $p = $tb['unclosed_prior_cents'];
                $rows[] = ['', 'Net income of earlier years not yet closed', $m(max($p, 0)), $m(max(-$p, 0))];
            }
            $rows[] = ['', 'Total', money_major($tb['total_debit_cents']), money_major($tb['total_credit_cents'])];
            return report_csv('trial-balance-' . $as_of . '.csv', $rows);
        }

        $fy = $tb['fiscal_year'];
        $tb['fiscal_year'] = $fy ? ['name' => $fy['name'], 'start_date' => $fy['start_date'], 'end_date' => $fy['end_date'], 'status' => $fy['status']] : NULL;
        $tb['kind_label']  = self::KIND_LABELS[$kind];
        $tb['letterhead']  = report_letterhead($claims);
        return json_response($tb, 'Trial balance');
    }

    /** GET /api/v1/reports/general-ledger */
    public function general_ledger()
    {
        $claims = viewer_check();
        require_method('GET');

        $id = (int) $this->input->get('account');
        if ($id <= 0) return json_invalid(['account' => 'Choose an account.']);
        list($from, $to) = $this->_range('year');
        $csv = report_csv_wanted($claims);

        $g = $this->ledger->account_ledger($id, $from, $to, $csv ? 100000 : 3000);
        if ( ! $g) return json_error('That account does not exist.', 404);

        if ($csv) {
            $a    = $g['account'];
            $rows = report_csv_head('General ledger — ' . $a['code'] . ' ' . $a['name'], 'For ' . $from . ' to ' . $to);
            $rows[] = ['Date', 'Entry', 'Book', 'Particulars', 'Memo', 'Customer or supplier', 'Debit', 'Credit', 'Balance'];
            $rows[] = ['', '', '', 'Balance brought forward', '', '', '', '', money_major($g['opening_cents'])];
            foreach ($g['lines'] as $l) {
                $rows[] = [$l['date'], $l['journal_no'], $l['book'], ($l['account'] ? $l['account'] . ' · ' : '') . $l['description'], (string) $l['memo'],
                           (string) $l['contact'], $l['debit_cents'] ? money_major($l['debit_cents']) : '',
                           $l['credit_cents'] ? money_major($l['credit_cents']) : '', money_major($l['balance_cents'])];
            }
            $rows[] = ['', '', '', 'Totals for the period', '', '', money_major($g['debit_cents']), money_major($g['credit_cents']), ''];
            $rows[] = ['', '', '', 'Balance carried forward', '', '', '', '', money_major($g['closing_cents'])];
            return report_csv('general-ledger-' . $a['code'] . '-' . $from . '-to-' . $to . '.csv', $rows);
        }
        return json_response($g + [
            'params'     => ['account' => $id, 'from' => $from, 'to' => $to],
            'letterhead' => report_letterhead($claims),
        ], 'General ledger');
    }

    /** GET /api/v1/reports/books */
    public function books()
    {
        $claims = viewer_check();
        require_method('GET');
        $this->load->model('Journal_model', 'journals');

        $book = (string) $this->input->get('book');
        if ( ! isset(Journal_model::BOOK_LABELS[$book])) $book = '';
        list($from, $to) = $this->_range('month');
        $csv = report_csv_wanted($claims);
        $p   = get_pagination_params(50, 500);

        list($js, $n, $total) = $this->ledger->book_register($book, $from, $to, $csv ? 20000 : $p['limit'], $csv ? 0 : $p['offset']);
        $label = $book !== '' ? Journal_model::BOOK_LABELS[$book] : 'All books';

        if ($csv) {
            $rows = report_csv_head($label, 'For ' . $from . ' to ' . $to);
            $rows[] = ['Date', 'Entry', 'Book', 'Reference', 'Particulars', 'Account code', 'Account', 'Debit', 'Credit'];
            foreach ($js as $j) {
                foreach ($j['lines'] as $i => $l) {
                    $rows[] = [$i ? '' : $j['date'], $i ? '' : $j['journal_no'], $i ? '' : $j['book'], $i ? '' : (string) $j['reference'],
                               $i ? (string) $l['memo'] : $j['description'], $l['code'], $l['name'],
                               $l['debit_cents'] ? money_major($l['debit_cents']) : '', $l['credit_cents'] ? money_major($l['credit_cents']) : ''];
                }
            }
            $rows[] = ['', '', '', '', 'Total', '', '', money_major($total), money_major($total)];
            return report_csv(($book !== '' ? $book : 'all-books') . '-' . $from . '-to-' . $to . '.csv', $rows);
        }

        $out = build_pagination_meta($js, $n, $p['page'], $p['limit']);
        $out += [
            'book'        => $book,
            'book_label'  => $label,
            'books'       => Journal_model::BOOK_LABELS,
            'from'        => $from,
            'to'          => $to,
            'total_cents' => $total,
            'params'      => ['book' => $book, 'from' => $from, 'to' => $to],
            'letterhead'  => report_letterhead($claims),
        ];
        return json_response($out, 'Books of accounts');
    }

    /**
     * GET /api/v1/reports/columnar-book?book=&from=&to=
     *
     * The cash receipts, cash disbursements, sales or purchase book in columns
     * (Books_lib), the whole period at once so the printout can carry page
     * totals and "brought forward" from page to page.
     */
    public function columnar_book()
    {
        $claims = viewer_check();
        require_method('GET');
        $this->load->library('Books_lib', NULL, 'books_lib');

        $book = (string) $this->input->get('book');
        if ( ! isset(Books_lib::LAYOUTS[$book])) return json_invalid(['book' => 'Choose the cash receipts, cash disbursements, sales or purchase book.']);
        list($from, $to) = $this->_range('month');
        $csv = report_csv_wanted($claims);
        $b   = $this->books_lib->build($book, $from, $to, $csv ? 20000 : 5000);

        if ($csv) {
            $m    = function ($c) { return $c ? money_major($c) : ''; };
            $rows = report_csv_head($b['title'], 'For ' . $from . ' to ' . $to);
            $rows[] = array_merge(['Date', 'Number', 'Reference', 'Name', 'Particulars'],
                array_map(function ($c) { return $c['label'] . ' ' . ($c['side'] === 'dr' ? 'Dr' : 'Cr'); }, $b['columns']),
                ['Sundry account', 'Sundry Dr', 'Sundry Cr']);
            foreach ($b['rows'] as $r) {
                $n = max(1, count($r['sundry']));
                for ($i = 0; $i < $n; $i++) {
                    $s = $r['sundry'][$i] ?? NULL;
                    $rows[] = array_merge(
                        $i ? ['', '', '', '', ''] : [$r['date'], $r['journal_no'], (string) $r['reference'], (string) $r['party'], $r['description']],
                        array_map(function ($c) use ($r, $i, $m) { return $i ? '' : $m($r['cols'][$c['key']]); }, $b['columns']),
                        $s ? [$s['code'] . ' ' . $s['name'], $m($s['debit_cents']), $m($s['credit_cents'])] : ['', '', '']
                    );
                }
            }
            $rows[] = array_merge(['', '', '', '', 'Total'], array_map(function ($c) use ($b) { return money_major($b['totals']['cols'][$c['key']]); }, $b['columns']),
                ['', money_major($b['totals']['sundry_debit_cents']), money_major($b['totals']['sundry_credit_cents'])]);
            return report_csv($book . '-columnar-' . $from . '-to-' . $to . '.csv', $rows);
        }

        return json_response($b + [
            'rows_per_page' => max(10, min(80, (int) (shop_cfg('report_rows_per_page', 40) ?: 40))),
            'letterhead'    => report_letterhead($claims),
        ], $b['title']);
    }

    /**
     * GET /api/v1/reports/worksheet?as_of=
     *
     * The ten-column worksheet: the unadjusted trial balance, the adjusting
     * entries, the adjusted trial balance, and the adjusted balances carried
     * into the income statement and balance sheet columns, with net income
     * balancing the last two pairs. Income and expenses count from the start
     * of the fiscal year, as the trial balance does.
     */
    public function worksheet()
    {
        $claims = viewer_check();
        require_method('GET');

        $as_of = $this->_date('as_of', company_today());
        $u = $this->ledger->trial_balance($as_of, 'unadjusted', FALSE);
        $a = $this->ledger->trial_balance($as_of, 'adjusted', FALSE);
        $un = [];
        $ad = [];
        foreach ($u['rows'] as $r) $un[$r['account_id']] = $r['debit_cents'] - $r['credit_cents'];
        foreach ($a['rows'] as $r) $ad[$r['account_id']] = $r['debit_cents'] - $r['credit_cents'];

        $rows = [];
        $t = array_fill_keys(['ub_dr', 'ub_cr', 'adj_dr', 'adj_cr', 'ab_dr', 'ab_cr', 'is_dr', 'is_cr', 'bs_dr', 'bs_cr'], 0);
        $split = function ($n) { return [$n > 0 ? $n : 0, $n < 0 ? -$n : 0]; };
        foreach ($this->db->where('is_header', 0)->order_by('sort_order')->order_by('code')->get('gp_accounts')->result_array() as $acc) {
            $id = (int) $acc['id'];
            if ( ! isset($un[$id]) && ! isset($ad[$id])) continue;
            $ub  = $un[$id] ?? 0;
            $ab  = $ad[$id] ?? 0;
            $adj = $ab - $ub;
            $is  = in_array($acc['type'], ['income', 'expense'], TRUE);
            $row = ['account_id' => $id, 'code' => $acc['code'], 'name' => $acc['name'], 'type' => $acc['type'], 'section' => $is ? 'is' : 'bs'];
            list($row['ub_dr'], $row['ub_cr'])   = $split($ub);
            list($row['adj_dr'], $row['adj_cr']) = $split($adj);
            list($row['ab_dr'], $row['ab_cr'])   = $split($ab);
            list($row[$is ? 'is_dr' : 'bs_dr'], $row[$is ? 'is_cr' : 'bs_cr']) = $split($ab);
            $row += ['is_dr' => 0, 'is_cr' => 0, 'bs_dr' => 0, 'bs_cr' => 0];
            foreach ($t as $k => $v) $t[$k] += $row[$k];
            $rows[] = $row;
        }

        /* Income of earlier years not yet closed sits in equity: in both trial
           balances and the balance sheet columns. */
        $prior = (int) $a['unclosed_prior_cents'];
        if ($prior !== 0) {
            list($pd, $pc) = $split($prior);
            foreach (['ub', 'ab', 'bs'] as $k) { $t[$k . '_dr'] += $pd; $t[$k . '_cr'] += $pc; }
        }
        $ni = $t['is_cr'] - $t['is_dr'];

        $out = [
            'as_of'        => $as_of,
            'fiscal_year'  => $a['fiscal_year'] ? ['name' => $a['fiscal_year']['name'], 'start_date' => $a['fiscal_year']['start_date']] : NULL,
            'rows'         => $rows,
            'prior_cents'  => $prior,
            'totals'       => $t,
            'net_income_cents' => $ni,
            'balanced'     => $t['ub_dr'] === $t['ub_cr'] && $t['adj_dr'] === $t['adj_cr'] && $t['ab_dr'] === $t['ab_cr']
                              && $t['is_dr'] + max($ni, 0) === $t['is_cr'] + max(-$ni, 0) && $t['bs_dr'] + max(-$ni, 0) === $t['bs_cr'] + max($ni, 0),
        ];

        if (report_csv_wanted($claims)) {
            $m    = function ($c) { return $c ? money_major($c) : ''; };
            $csv  = report_csv_head('Worksheet', 'As of ' . $as_of);
            $csv[] = ['Code', 'Account', 'Unadjusted Dr', 'Unadjusted Cr', 'Adjustments Dr', 'Adjustments Cr', 'Adjusted Dr', 'Adjusted Cr', 'Income statement Dr', 'Income statement Cr', 'Balance sheet Dr', 'Balance sheet Cr'];
            foreach ($rows as $r) $csv[] = [$r['code'], $r['name'], $m($r['ub_dr']), $m($r['ub_cr']), $m($r['adj_dr']), $m($r['adj_cr']), $m($r['ab_dr']), $m($r['ab_cr']), $m($r['is_dr']), $m($r['is_cr']), $m($r['bs_dr']), $m($r['bs_cr'])];
            if ($prior !== 0) {
                list($pd, $pc) = $split($prior);
                $csv[] = ['', 'Net income of earlier years not yet closed', $m($pd), $m($pc), '', '', $m($pd), $m($pc), '', '', $m($pd), $m($pc)];
            }
            $csv[] = ['', 'Totals', money_major($t['ub_dr']), money_major($t['ub_cr']), money_major($t['adj_dr']), money_major($t['adj_cr']), money_major($t['ab_dr']), money_major($t['ab_cr']), money_major($t['is_dr']), money_major($t['is_cr']), money_major($t['bs_dr']), money_major($t['bs_cr'])];
            $csv[] = ['', $ni >= 0 ? 'Net income' : 'Net loss', '', '', '', '', '', '', $m(max($ni, 0)), $m(max(-$ni, 0)), $m(max(-$ni, 0)), $m(max($ni, 0))];
            return report_csv('worksheet-' . $as_of . '.csv', $csv);
        }
        return json_response($out + ['letterhead' => report_letterhead($claims)], 'Worksheet');
    }

    /** GET /api/v1/reports/integrity — accountants and administrators */
    public function integrity()
    {
        $claims = accountant_check();
        require_method('GET');
        $this->load->library('Integrity_lib', NULL, 'integrity');
        $r = $this->integrity->run();

        if (report_csv_wanted($claims)) {
            $rows = report_csv_head('Integrity check', 'As of ' . $r['as_of']);
            $rows[] = ['Check', 'Result', 'Count', 'Example'];
            foreach ($r['checks'] as $c) {
                $rows[] = [$c['title'], strtoupper($c['status']), $c['count'], $c['items'] ? $c['items'][0]['text'] : ''];
                foreach (array_slice($c['items'], 1) as $i) $rows[] = ['', '', '', $i['text']];
            }
            return report_csv('integrity-' . $r['as_of'] . '.csv', $rows);
        }
        return json_response($r + ['letterhead' => report_letterhead($claims)], 'Integrity check');
    }

    // =========================================================================
    // ANALYSIS
    // =========================================================================

    /** GET /api/v1/reports/analysis */
    public function analysis()
    {
        $claims = viewer_check();
        require_method('GET');
        $this->_lib();
        $this->load->library('Analysis_lib', NULL, 'analysis');

        $as_of = $this->_date('as_of', company_today());
        $a = $this->analysis->ratios($as_of);

        if (report_csv_wanted($claims)) {
            $rows = report_csv_head('Financial analysis', 'As of ' . $as_of . ' (income and turnover from ' . $a['from'] . ')');
            $rows[] = ['Group', 'Measure', 'Value', 'Same date last year', 'Formula'];
            $fmt = function ($v, $unit) {
                if ($v === NULL) return '';
                if ($unit === 'money') return money_major((int) $v);
                if ($unit === 'pct') return number_format($v * 100, 1, '.', '') . '%';
                return number_format($v, $unit === 'days' ? 0 : 2, '.', '');
            };
            foreach ($a['ratios'] as $r) $rows[] = [$r['group'], $r['label'], $fmt($r['value'], $r['unit']), $fmt($r['prior'], $r['unit']), $r['formula']];
            return report_csv('financial-analysis-' . $as_of . '.csv', $rows);
        }
        return json_response($a + [
            'params'     => ['as_of' => $as_of],
            'letterhead' => report_letterhead($claims),
        ], 'Financial analysis');
    }

    // =========================================================================
    // SHARED
    // =========================================================================

    private function _date($key, $default)
    {
        $v = (string) $this->input->get($key);
        return Period_model::valid_date($v) ? $v : $default;
    }

    /** [from, to]; the start defaults to the first day of the fiscal year (or of the month) that holds the end. */
    private function _range($default = 'year')
    {
        $to   = $this->_date('to', company_today());
        $from = (string) $this->input->get('from');
        if ( ! Period_model::valid_date($from)) {
            if ($default === 'month') {
                $from = substr($to, 0, 8) . '01';
            } else {
                $fy   = $this->periods->year_for_date($to);
                $from = $fy ? $fy['start_date'] : substr($to, 0, 4) . '-01-01';
            }
        }
        if ($from > $to) json_invalid(['from' => 'The start date is after the end date.']);
        return [$from, $to];
    }

    private function _levels($default)
    {
        $v = $this->input->get('levels');
        return ($v !== NULL && $v !== '' && in_array((int) $v, [0, 1, 2, 3], TRUE)) ? (int) $v : $default;
    }

    private function _range_label(array $c)
    {
        if ( ! empty($c['total'])) return 'Total';
        if (substr($c['from'], 8, 2) === '01' && $c['to'] === date('Y-m-t', strtotime($c['from']))) return date('M Y', strtotime($c['from']));
        return $c['from'] . ' to ' . $c['to'];
    }

    private function _statement_csv($filename, array $s, $period, array $labels)
    {
        $rows = report_csv_head($s['title'], $period);
        $rows[] = array_merge(['Code', 'Particulars'], $labels);
        foreach ($s['lines'] as $l) {
            $code  = in_array($l['type'], ['account', 'group'], TRUE) ? (string) ($l['code'] ?? '') : '';
            $label = str_repeat('  ', (int) ($l['indent'] ?? 0)) . ($l['type'] === 'section' ? mb_strtoupper($l['label']) : $l['label']);
            $row   = [$code, $label];
            if ( ! empty($l['amounts'])) foreach ($l['amounts'] as $c) $row[] = money_major((int) $c);
            $rows[] = $row;
        }
        return report_csv($filename, $rows);
    }
}
