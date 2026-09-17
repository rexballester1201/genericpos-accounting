<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Asset_reports.php — the fixed-asset reports
 *
 * GenericPOS Accounting · fixed assets · every role reads them
 *
 *   GET /api/v1/reports/lapsing-schedule   ?from=&to= or ?fiscal_year_id= · &format=csv
 *
 * The lapsing schedule: every asset held during the period, by category,
 * from cost and accumulated depreciation at the start, through additions,
 * depreciation and disposals, to the net book value at the end and the
 * months of life left — then tied to the ledger balances of each category's
 * asset and accumulated-depreciation accounts at the end date. It defaults to
 * the fiscal year to date; a fiscal year means the whole year.
 */
class Asset_reports extends CI_Controller
{
    const TITLE = 'Lapsing Schedule of Property and Equipment';

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('Asset_model', 'assets');
        $this->load->model('Period_model', 'periods');
    }

    /** GET /api/v1/reports/lapsing-schedule */
    public function lapsing_schedule()
    {
        $claims = viewer_check();
        require_method('GET');
        list($from, $to, $fy_id) = $this->_range();
        $d = $this->assets->lapsing($from, $to);

        if (report_csv_wanted($claims)) {
            $m = function ($c) { return money_major((int) $c); };
            $rows = report_csv_head(self::TITLE, 'For ' . $from . ' to ' . $to);
            $rows[] = ['Category', 'Asset no.', 'Asset', 'Acquired', 'Life (months)', 'Cost at start', 'Additions', 'Disposals', 'Cost at end',
                       'Accumulated at start', 'Depreciation', 'Accumulated on disposals', 'Accumulated at end', 'Net book value', 'Months remaining'];
            $line = function ($a, $b, $c, $acq, $life, array $x, $left) use ($m) {
                return [$a, $b, $c, $acq, $life, $m($x['cost_start_cents']), $m($x['additions_cents']), $m($x['disposals_cents']), $m($x['cost_end_cents']),
                        $m($x['accum_start_cents']), $m($x['depreciation_cents']), $m($x['accum_disposals_cents']), $m($x['accum_end_cents']),
                        $m($x['nbv_end_cents']), $left];
            };
            foreach ($d['categories'] as $c) {
                foreach ($c['rows'] as $r) {
                    $rows[] = $line($c['name'], $r['asset_no'], $r['name'] . ($r['disposed_on'] ? ' (disposed ' . $r['disposed_on'] . ')' : ''),
                                    $r['acquired_on'], $r['life_months'], $r, $r['months_remaining'] === NULL ? '' : $r['months_remaining']);
                }
                $rows[] = $line($c['name'], '', 'Total ' . $c['name'], '', '', $c['subtotal'], '');
            }
            $rows[] = $line('', '', 'Grand total', '', '', $d['total'], '');
            $rows[] = [];
            $rows[] = ['Tie-out to the ledger as of ' . $to];
            $rows[] = ['Account code', 'Account', 'What', 'Categories', 'Register', 'Ledger', 'Difference'];
            foreach ($d['tie_out'] as $t) {
                $rows[] = [$t['code'], $t['name'], $t['kind'] === 'cost' ? 'Cost' : 'Accumulated depreciation', implode('; ', $t['categories']),
                           $m($t['register_cents']), $m($t['ledger_cents']), $m($t['difference_cents'])];
            }
            foreach ($d['others'] as $o) {
                $rows[] = [$o['code'], $o['name'], 'Not in any category', '', '0.00', $m($o['balance_cents']), $m(-$o['balance_cents'])];
            }
            return report_csv('lapsing-schedule-' . $from . '-to-' . $to . '.csv', $rows);
        }

        $years = array_map(function ($y) {
            return ['id' => (int) $y['id'], 'name' => $y['name'], 'start_date' => $y['start_date'], 'end_date' => $y['end_date'], 'status' => $y['status']];
        }, $this->periods->years());
        return json_response($d + [
            'title'        => self::TITLE,
            'params'       => ['from' => $from, 'to' => $to, 'fiscal_year_id' => $fy_id],
            'fiscal_years' => $years,
            'letterhead'   => report_letterhead($claims),
        ], self::TITLE);
    }

    /** [from, to, fiscal year id|NULL]: a whole fiscal year, a range, or the fiscal year to date. */
    private function _range()
    {
        $fy_id = (int) $this->input->get('fiscal_year_id');
        if ($fy_id > 0) {
            $fy = $this->periods->year($fy_id);
            if ( ! $fy) json_error('That fiscal year does not exist.', 404);
            return [$fy['start_date'], $fy['end_date'], $fy_id];
        }
        $to = (string) $this->input->get('to');
        if ( ! Period_model::valid_date($to)) $to = company_today();
        $from = (string) $this->input->get('from');
        if ( ! Period_model::valid_date($from)) {
            $fy   = $this->periods->year_for_date($to);
            $from = $fy ? $fy['start_date'] : substr($to, 0, 4) . '-01-01';
        }
        if ($from > $to) json_invalid(['from' => 'The start date is after the end date.']);
        return [$from, $to, NULL];
    }
}
