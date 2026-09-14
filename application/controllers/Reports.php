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
    const ROLE_TITLES = ['bookkeeper' => 'Bookkeeper', 'accountant' => 'Accountant', 'admin' => 'Administrator'];

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
        if ($this->_csv_wanted($claims)) {
            return $this->_statement_csv('balance-sheet-' . $as_of . '.csv', $s, 'As of ' . $as_of,
                array_map(function ($c) { return 'As of ' . $c['as_of']; }, $s['columns']));
        }
        return json_response($s + [
            'params'     => ['as_of' => $as_of, 'compare' => $compare, 'levels' => $levels, 'zero' => $zero],
            'letterhead' => $this->_letterhead($claims),
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
        if ($this->_csv_wanted($claims)) {
            $period = 'For ' . $from . ' to ' . $to;
            if ($dept) foreach ($departments as $d) if ($d['id'] === $dept) $period .= ' · department ' . $d['code'] . ' ' . $d['name'];
            return $this->_statement_csv('income-statement-' . $from . '-to-' . $to . '.csv', $s, $period,
                array_map([$this, '_range_label'], $s['columns']));
        }
        return json_response($s + [
            'params'      => ['from' => $from, 'to' => $to, 'compare' => $compare, 'department' => $dept ?: NULL, 'levels' => $levels, 'zero' => $zero],
            'departments' => $departments,
            'letterhead'  => $this->_letterhead($claims),
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
        if ($this->_csv_wanted($claims)) {
            return $this->_statement_csv('changes-in-equity-' . $from . '-to-' . $to . '.csv', $s, 'For ' . $from . ' to ' . $to,
                ['Balance, ' . $from, $S->is_coop() ? 'Net surplus' : 'Net income', 'Other changes', 'Balance, ' . $to]);
        }
        return json_response($s + [
            'params'     => ['from' => $from, 'to' => $to, 'levels' => $levels],
            'letterhead' => $this->_letterhead($claims),
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
        if ($this->_csv_wanted($claims)) {
            return $this->_statement_csv('cash-flows-' . $from . '-to-' . $to . '.csv', $s, 'For ' . $from . ' to ' . $to, ['Amount']);
        }
        return json_response($s + [
            'params'     => ['from' => $from, 'to' => $to, 'levels' => $levels],
            'letterhead' => $this->_letterhead($claims),
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

        if ($this->_csv_wanted($claims)) {
            $m    = function ($c) { return $c ? money_major($c) : ''; };
            $rows = $this->_csv_head('Trial balance (' . strtolower(self::KIND_LABELS[$kind]) . ')', 'As of ' . $as_of);
            $rows[] = ['Code', 'Account', 'Debit', 'Credit'];
            foreach ($tb['rows'] as $r) $rows[] = [$r['code'], $r['name'], $m($r['debit_cents']), $m($r['credit_cents'])];
            if ($tb['unclosed_prior_cents'] !== 0) {
                $p = $tb['unclosed_prior_cents'];
                $rows[] = ['', 'Net income of earlier years not yet closed', $m(max($p, 0)), $m(max(-$p, 0))];
            }
            $rows[] = ['', 'Total', money_major($tb['total_debit_cents']), money_major($tb['total_credit_cents'])];
            return $this->_csv('trial-balance-' . $as_of . '.csv', $rows);
        }

        $fy = $tb['fiscal_year'];
        $tb['fiscal_year'] = $fy ? ['name' => $fy['name'], 'start_date' => $fy['start_date'], 'end_date' => $fy['end_date'], 'status' => $fy['status']] : NULL;
        $tb['kind_label']  = self::KIND_LABELS[$kind];
        $tb['letterhead']  = $this->_letterhead($claims);
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
        $csv = $this->_csv_wanted($claims);

        $g = $this->ledger->account_ledger($id, $from, $to, $csv ? 100000 : 3000);
        if ( ! $g) return json_error('That account does not exist.', 404);

        if ($csv) {
            $a    = $g['account'];
            $rows = $this->_csv_head('General ledger — ' . $a['code'] . ' ' . $a['name'], 'For ' . $from . ' to ' . $to);
            $rows[] = ['Date', 'Entry', 'Book', 'Particulars', 'Memo', 'Customer or supplier', 'Debit', 'Credit', 'Balance'];
            $rows[] = ['', '', '', 'Balance brought forward', '', '', '', '', money_major($g['opening_cents'])];
            foreach ($g['lines'] as $l) {
                $rows[] = [$l['date'], $l['journal_no'], $l['book'], ($l['account'] ? $l['account'] . ' · ' : '') . $l['description'], (string) $l['memo'],
                           (string) $l['contact'], $l['debit_cents'] ? money_major($l['debit_cents']) : '',
                           $l['credit_cents'] ? money_major($l['credit_cents']) : '', money_major($l['balance_cents'])];
            }
            $rows[] = ['', '', '', 'Totals for the period', '', '', money_major($g['debit_cents']), money_major($g['credit_cents']), ''];
            $rows[] = ['', '', '', 'Balance carried forward', '', '', '', '', money_major($g['closing_cents'])];
            return $this->_csv('general-ledger-' . $a['code'] . '-' . $from . '-to-' . $to . '.csv', $rows);
        }
        return json_response($g + [
            'params'     => ['account' => $id, 'from' => $from, 'to' => $to],
            'letterhead' => $this->_letterhead($claims),
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
        $csv = $this->_csv_wanted($claims);
        $p   = get_pagination_params(50, 500);

        list($js, $n, $total) = $this->ledger->book_register($book, $from, $to, $csv ? 20000 : $p['limit'], $csv ? 0 : $p['offset']);
        $label = $book !== '' ? Journal_model::BOOK_LABELS[$book] : 'All books';

        if ($csv) {
            $rows = $this->_csv_head($label, 'For ' . $from . ' to ' . $to);
            $rows[] = ['Date', 'Entry', 'Book', 'Reference', 'Particulars', 'Account code', 'Account', 'Debit', 'Credit'];
            foreach ($js as $j) {
                foreach ($j['lines'] as $i => $l) {
                    $rows[] = [$i ? '' : $j['date'], $i ? '' : $j['journal_no'], $i ? '' : $j['book'], $i ? '' : (string) $j['reference'],
                               $i ? (string) $l['memo'] : $j['description'], $l['code'], $l['name'],
                               $l['debit_cents'] ? money_major($l['debit_cents']) : '', $l['credit_cents'] ? money_major($l['credit_cents']) : ''];
                }
            }
            $rows[] = ['', '', '', '', 'Total', '', '', money_major($total), money_major($total)];
            return $this->_csv(($book !== '' ? $book : 'all-books') . '-' . $from . '-to-' . $to . '.csv', $rows);
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
            'letterhead'  => $this->_letterhead($claims),
        ];
        return json_response($out, 'Books of accounts');
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

        if ($this->_csv_wanted($claims)) {
            $rows = $this->_csv_head('Financial analysis', 'As of ' . $as_of . ' (income and turnover from ' . $a['from'] . ')');
            $rows[] = ['Group', 'Measure', 'Value', 'Same date last year', 'Formula'];
            $fmt = function ($v, $unit) {
                if ($v === NULL) return '';
                if ($unit === 'money') return money_major((int) $v);
                if ($unit === 'pct') return number_format($v * 100, 1, '.', '') . '%';
                return number_format($v, $unit === 'days' ? 0 : 2, '.', '');
            };
            foreach ($a['ratios'] as $r) $rows[] = [$r['group'], $r['label'], $fmt($r['value'], $r['unit']), $fmt($r['prior'], $r['unit']), $r['formula']];
            return $this->_csv('financial-analysis-' . $as_of . '.csv', $rows);
        }
        return json_response($a + [
            'params'     => ['as_of' => $as_of],
            'letterhead' => $this->_letterhead($claims),
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

    /**
     * The company block, note and signatories a printed report carries.
     * "Prepared by" is whoever asked for the report; the others come from
     * Settings → Printed reports, and an empty name leaves its block out.
     */
    private function _letterhead(array $claims)
    {
        $u    = $this->db->select('full_name, username, role')->get_where('gp_users', ['id' => (int) $claims['user_id']], 1)->row_array();
        $who  = $u ? (trim((string) $u['full_name']) ?: (string) $u['username']) : '';
        $sign = [['role' => 'Prepared by', 'name' => $who, 'title' => $u ? (self::ROLE_TITLES[$u['role']] ?? '') : '']];
        foreach (['checked' => 'Checked by', 'approved' => 'Approved by', 'noted' => 'Noted by'] as $k => $label) {
            $name = trim((string) shop_cfg('sign_' . $k . '_by', ''));
            if ($name !== '') $sign[] = ['role' => $label, 'name' => $name, 'title' => trim((string) shop_cfg('sign_' . $k . '_title', ''))];
        }
        $coop = shop_cfg('entity_type') === 'cooperative';
        return [
            'company'     => (string) shop_cfg('store_name', ''),
            'legal_name'  => trim((string) shop_cfg('store_legal_name', '')),
            'tagline'     => trim((string) shop_cfg('store_tagline', '')),
            'address'     => trim((string) shop_cfg('store_address', '')),
            'tin'         => trim((string) shop_cfg('store_tin', '')),
            'tin_label'   => (string) shop_cfg('tax_id_label', 'TIN') ?: 'TIN',
            'cda_reg_no'  => $coop ? trim((string) shop_cfg('coop_cda_reg_no', '')) : '',
            'logo'        => shop_cfg('store_logo') ?: NULL,
            'note'        => trim((string) shop_cfg('report_note', '')),
            'signatories' => $sign,
            'printed_by'  => shop_bool('report_show_printed_by', TRUE) ? $who : '',
            'printed_at'  => gmdate('Y-m-d H:i:s'),
            'currency'    => (string) shop_cfg('currency_code', 'PHP'),
            'entity_type' => $coop ? 'cooperative' : 'business',
        ];
    }

    private function _csv_wanted(array $claims)
    {
        if ((string) $this->input->get('format') !== 'csv') return FALSE;
        rate_limit((int) $claims['user_id'], 'report_export');
        return TRUE;
    }

    private function _csv_head($title, $period)
    {
        $name  = (string) shop_cfg('store_name', '');
        $rows  = [[$name]];
        $legal = trim((string) shop_cfg('store_legal_name', ''));
        if ($legal !== '' && $legal !== $name) $rows[] = [$legal];
        $rows[] = [$title];
        $rows[] = [$period];
        $rows[] = ['Amounts in ' . (string) shop_cfg('currency_code', 'PHP')];
        $rows[] = [];
        return $rows;
    }

    private function _statement_csv($filename, array $s, $period, array $labels)
    {
        $rows = $this->_csv_head($s['title'], $period);
        $rows[] = array_merge(['Code', 'Particulars'], $labels);
        foreach ($s['lines'] as $l) {
            $code  = in_array($l['type'], ['account', 'group'], TRUE) ? (string) ($l['code'] ?? '') : '';
            $label = str_repeat('  ', (int) ($l['indent'] ?? 0)) . ($l['type'] === 'section' ? mb_strtoupper($l['label']) : $l['label']);
            $row   = [$code, $label];
            if ( ! empty($l['amounts'])) foreach ($l['amounts'] as $c) $row[] = money_major((int) $c);
            $rows[] = $row;
        }
        return $this->_csv($filename, $rows);
    }

    private function _csv($filename, array $rows)
    {
        $cell = function ($v) {
            $s = str_replace(["\r", "\n", "\t"], ' ', (string) $v);
            if ($s !== '' && strpos('=+-@', $s[0]) !== FALSE && ! preg_match('/^-?\d+(\.\d+)?$/', $s)) $s = "'" . $s;
            return '"' . str_replace('"', '""', $s) . '"';
        };
        $out = "\xEF\xBB\xBF";
        foreach ($rows as $r) $out .= implode(',', array_map($cell, $r)) . "\r\n";

        $this->output
            ->set_status_header(200)
            ->set_content_type('text/csv', 'utf-8')
            ->set_header('Content-Disposition: attachment; filename="' . $filename . '"')
            ->set_header('Cache-Control: no-store')
            ->set_output($out);
    }
}
