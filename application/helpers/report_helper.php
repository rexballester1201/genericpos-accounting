<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * report_helper.php — what every printed report and CSV download shares (autoloaded)
 *
 * GenericPOS Accounting
 *
 *   report_letterhead($claims)         the company block, the report note and the signatories
 *   report_csv_wanted($claims)         TRUE when ?format=csv was asked for (rate-limited)
 *   report_csv_head($title, $period)   the rows a CSV file opens with
 *   report_csv($filename, $rows)       send the rows as a CSV download
 *
 * Every report endpoint answers with `letterhead`, so a printed report carries
 * the company block and the signatories whoever prints it — a viewer cannot
 * read Settings. js/report-kit.js draws it: sheetHead() and sheetFoot().
 *
 * CSV files open straight in a spreadsheet: a UTF-8 byte-order mark, amounts
 * as plain numbers in major units (money_major), and any cell a spreadsheet
 * would read as a formula (=, +, -, @ first) defused with a leading apostrophe
 * unless it is a plain number.
 */

if ( ! function_exists('report_letterhead'))
{
    /**
     * "Prepared by" is whoever asked for the report; the other signatories come
     * from Settings → Printed reports, and one with no name is left out.
     */
    function report_letterhead(array $claims)
    {
        $CI =& get_instance();
        $titles = ['bookkeeper' => 'Bookkeeper', 'accountant' => 'Accountant', 'admin' => 'Administrator'];
        $u    = $CI->db->select('full_name, username, role')->get_where('gp_users', ['id' => (int) ($claims['user_id'] ?? 0)], 1)->row_array();
        $who  = $u ? (trim((string) $u['full_name']) ?: (string) $u['username']) : '';
        $sign = [['role' => 'Prepared by', 'name' => $who, 'title' => $u ? ($titles[$u['role']] ?? '') : '']];
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
}

if ( ! function_exists('amount_in_words'))
{
    /**
     * 123456 → "One thousand two hundred thirty-four pesos and 56/100", the way
     * a cheque, an official receipt or a voucher spells an amount. The unit
     * follows currency_code; centavos are written as a fraction of 100.
     */
    function amount_in_words($cents)
    {
        $cents = (int) $cents;
        $neg   = $cents < 0;
        $cents = abs($cents);
        $whole = intdiv($cents, 100);
        $frac  = $cents % 100;

        $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve',
                 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
        $three = function ($n) use ($ones, $tens) {
            $w = [];
            if ($n >= 100) { $w[] = $ones[intdiv($n, 100)] . ' hundred'; $n %= 100; }
            if ($n >= 20)  $w[] = $tens[intdiv($n, 10)] . ($n % 10 ? '-' . $ones[$n % 10] : '');
            elseif ($n > 0) $w[] = $ones[$n];
            return implode(' ', $w);
        };

        $words = 'zero';
        if ($whole > 0) {
            $scales = ['', ' thousand', ' million', ' billion', ' trillion', ' quadrillion'];
            $parts  = [];
            for ($n = $whole, $i = 0; $n > 0; $n = intdiv($n, 1000), $i++) {
                if ($n % 1000) array_unshift($parts, $three($n % 1000) . $scales[$i]);
            }
            $words = implode(' ', $parts);
        }

        $units = ['PHP' => ['peso', 'pesos'], 'USD' => ['dollar', 'dollars'], 'EUR' => ['euro', 'euros'], 'SGD' => ['dollar', 'dollars']];
        $unit  = $units[strtoupper((string) shop_cfg('currency_code', 'PHP'))] ?? NULL;
        $s = ucfirst($words) . ($unit ? ' ' . ($whole === 1 ? $unit[0] : $unit[1]) : '') . ' and ' . str_pad((string) $frac, 2, '0', STR_PAD_LEFT) . '/100';
        return $neg ? 'Minus ' . lcfirst($s) : $s;
    }
}

if ( ! function_exists('report_csv_wanted'))
{
    /** ?format=csv — counted against the caller's export allowance before any work is done. */
    function report_csv_wanted(array $claims)
    {
        $CI =& get_instance();
        if ((string) $CI->input->get('format') !== 'csv') return FALSE;
        rate_limit((int) $claims['user_id'], 'report_export');
        return TRUE;
    }
}

if ( ! function_exists('report_csv_head'))
{
    /** Company, registered name (when different), title, period and currency, then a blank row. */
    function report_csv_head($title, $period)
    {
        $name  = (string) shop_cfg('store_name', '');
        $rows  = [[$name]];
        $legal = trim((string) shop_cfg('store_legal_name', ''));
        if ($legal !== '' && $legal !== $name) $rows[] = [$legal];
        $rows[] = [(string) $title];
        $rows[] = [(string) $period];
        $rows[] = ['Amounts in ' . (string) shop_cfg('currency_code', 'PHP')];
        $rows[] = [];
        return $rows;
    }
}

if ( ! function_exists('report_csv'))
{
    /** Send $rows (arrays of cells) as a CSV download. The controller returns right after. */
    function report_csv($filename, array $rows)
    {
        $CI =& get_instance();
        $cell = function ($v) {
            $s = str_replace(["\r", "\n", "\t"], ' ', (string) $v);
            if ($s !== '' && strpos('=+-@', $s[0]) !== FALSE && ! preg_match('/^-?\d+(\.\d+)?$/', $s)) $s = "'" . $s;
            return '"' . str_replace('"', '""', $s) . '"';
        };
        $out = "\xEF\xBB\xBF";
        foreach ($rows as $r) $out .= implode(',', array_map($cell, $r)) . "\r\n";

        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', (string) $filename);
        $CI->output
            ->set_status_header(200)
            ->set_content_type('text/csv', 'utf-8')
            ->set_header('Content-Disposition: attachment; filename="' . $safe . '"')
            ->set_header('Cache-Control: no-store')
            ->set_output($out);
    }
}
