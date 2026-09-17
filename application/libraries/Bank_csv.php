<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Bank_csv.php — reading the rows of a bank statement file
 *
 * GenericPOS Accounting · banking module
 *
 * The browser reads the CSV file, lets the person map its columns and pick the
 * date format, and sends the rows as JSON strings. Every row is checked again
 * here, with the same rules the browser preview used:
 *
 *   date      in the chosen format: Y-m-d, m/d/Y or d/m/Y (a time part is ignored)
 *   amount    "1,234.56", "(1,234.56)", "-1234.56", "1,234.56-", "₱1,234.56";
 *             either one signed column, or a withdrawal (debit) column and a
 *             deposit (credit) column — the bank's own point of view
 *
 * NOTE: EXACT MONEY. Amounts go through money_cents() (string arithmetic, no
 * floats). Thousands separators must be real ones: "12,34.56" is refused
 * rather than guessed at, because it is usually a decimal comma misread.
 */
class Bank_csv
{
    const FORMATS = [
        'Y-m-d' => 'year-month-day (2026-08-31)',
        'm/d/Y' => 'month/day/year (08/31/2026)',
        'd/m/Y' => 'day/month/year (31/08/2026)',
    ];

    /** A date string in one of FORMATS → 'Y-m-d', or NULL. */
    public static function date($raw, $format)
    {
        $s = trim((string) $raw);
        /* "2026-08-31 00:00:00", "8/31/2026 12:00 AM" — the time says nothing here. */
        $s = preg_replace('/(?:[T ]+)\d{1,2}:\d{2}(?::\d{2})?(?:\.\d+)?(?:\s*[AaPp][Mm])?$/', '', $s);
        $m = [];
        switch ((string) $format) {
            case 'Y-m-d':
                if ( ! preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $s, $m)) return NULL;
                list($y, $mo, $d) = [(int) $m[1], (int) $m[2], (int) $m[3]];
                break;
            case 'm/d/Y':
                if ( ! preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $s, $m)) return NULL;
                list($mo, $d, $y) = [(int) $m[1], (int) $m[2], (int) $m[3]];
                break;
            case 'd/m/Y':
                if ( ! preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $s, $m)) return NULL;
                list($d, $mo, $y) = [(int) $m[1], (int) $m[2], (int) $m[3]];
                break;
            default:
                return NULL;
        }
        if ($y < 1900 || ! checkdate($mo, $d, $y)) return NULL;
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    /**
     * An amount as a bank file writes it → signed centavos. NULL when it is
     * not an amount; '' (an empty cell) also gives NULL — the caller decides
     * whether an empty cell is allowed.
     */
    public static function amount($raw)
    {
        if (is_int($raw)) return $raw;
        $s = trim(str_replace("\xC2\xA0", ' ', (string) $raw));
        if ($s === '') return NULL;

        $neg = 0;
        if (preg_match('/^\((.*)\)$/u', $s, $m)) { $neg++; $s = trim($m[1]); }
        if ($s !== '' && ($s[0] === '-' || $s[0] === '+')) { if ($s[0] === '-') $neg++; $s = trim(substr($s, 1)); }
        $s = trim(preg_replace('/^(?:PHP|₱)\s*/iu', '', $s));
        if ($s !== '' && $s[0] === '-') { $neg++; $s = trim(substr($s, 1)); }
        if ($s !== '' && substr($s, -1) === '-') { $neg++; $s = trim(substr($s, 0, -1)); }
        if ($neg > 1) return NULL;

        $s = str_replace(' ', '', $s);
        if ( ! preg_match('/^(?:\d{1,3}(?:,\d{3})+|\d+)(?:\.\d+)?$/', $s) && ! preg_match('/^\.\d+$/', $s)) return NULL;

        $c = money_cents($s, FALSE);
        if ($c === NULL) return NULL;
        return $neg ? -$c : $c;
    }

    /**
     * Check a batch of rows.
     *
     * @param array  $rows    each: date, description, reference, and either amount
     *                        or withdrawal + deposit; an optional row (its number in the file)
     * @param string $format  one of FORMATS
     * @param string $min     earliest date allowed (Y-m-d)
     * @param string $max     latest date allowed (Y-m-d) — the statement date
     * @return array [clean rows [txn_date, description, reference, amount_cents], errors (strings)]
     */
    public function rows(array $rows, $format, $min, $max)
    {
        $clean  = [];
        $errors = [];
        $label  = self::FORMATS[$format] ?? '';

        foreach (array_values($rows) as $i => $r) {
            if ( ! is_array($r)) { $errors[] = 'Row ' . ($i + 1) . ': not a row.'; continue; }
            $n   = isset($r['row']) && (int) $r['row'] > 0 ? (int) $r['row'] : $i + 1;
            $bad = [];

            $raw_date = trim((string) ($r['date'] ?? ''));
            $date = self::date($raw_date, $format);
            if ($raw_date === '')      $bad[] = 'the date is empty';
            elseif ($date === NULL)    $bad[] = '“' . mb_substr($raw_date, 0, 30) . '” is not a date in the ' . $label . ' format';
            elseif ($date > $max)      $bad[] = $date . ' is after the statement date (' . $max . ')';
            elseif ($date < $min)      $bad[] = $date . ' is before this statement\'s period (' . $min . ' onwards)';

            $desc = clean_line($r['description'] ?? '', 255);
            $ref  = clean_line($r['reference'] ?? '', 200);
            if (mb_strlen($ref) > 60) $bad[] = 'the reference is longer than 60 characters';

            $amount = NULL;
            if (array_key_exists('amount', $r)) {
                $raw = trim((string) $r['amount']);
                $amount = self::amount($raw);
                if ($raw === '')          $bad[] = 'the amount is empty';
                elseif ($amount === NULL) $bad[] = '“' . mb_substr($raw, 0, 30) . '” is not an amount';
                elseif ($amount === 0)    $bad[] = 'the amount is zero';
            } else {
                $rw = trim((string) ($r['withdrawal'] ?? ''));
                $rd = trim((string) ($r['deposit'] ?? ''));
                $w  = $rw === '' ? 0 : self::amount($rw);
                $d  = $rd === '' ? 0 : self::amount($rd);
                if ($w === NULL)           $bad[] = '“' . mb_substr($rw, 0, 30) . '” is not an amount';
                elseif ($d === NULL)       $bad[] = '“' . mb_substr($rd, 0, 30) . '” is not an amount';
                elseif ($w !== 0 && $d !== 0) $bad[] = 'it has both a withdrawal and a deposit';
                elseif ($w === 0 && $d === 0) $bad[] = 'it has no amount';
                else $amount = abs($d) - abs($w);
            }

            if ($bad) { $errors[] = 'Row ' . $n . ': ' . implode('; ', $bad) . '.'; continue; }
            $clean[] = ['txn_date' => $date, 'description' => $desc, 'reference' => $ref !== '' ? $ref : NULL, 'amount_cents' => $amount, 'row' => $n];
        }
        return [$clean, $errors];
    }
}
