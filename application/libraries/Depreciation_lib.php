<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Depreciation_lib.php — the arithmetic of depreciation, and nothing else
 *
 * GenericPOS Accounting · fixed assets
 *
 * PURE FUNCTIONS: no database, no settings, no clock. The models pass in what
 * they read; $SCRATCH/fa-lib-test.php runs every function here with plain PHP.
 *
 * Amounts are integer centavos. Months are counted from an asset's
 * depreciation_start: month 1 is that month, month 2 the next, and so on
 * (month_index()). "Accumulated" always means everything charged so far:
 * the opening accumulated depreciation brought in at go-live plus every
 * depreciation entry.
 *
 * ─── STRAIGHT LINE, EXACT ─────────────────────────────────────────────────
 *   base       = cost − residual
 *   target(k)  = round(base × k ÷ life)        where the line stands after month k
 *                (= base from the last month of the life onwards)
 *   charge(k)  = target(k) − accumulated, never below 0, never more than is left
 *
 *   Each month charges the distance to the line, not a fixed monthly amount,
 *   so the months always add up to the base to the centavo (no rounding
 *   drift), a month that was never run is caught up by the next run, and an
 *   opening accumulated depreciation above or below the line is absorbed —
 *   months charge nothing until the line passes an opening balance that is
 *   ahead of it, and the first month catches up one that is behind.
 *
 *   The demo seed charges round(cost ÷ life) every month instead. For amounts
 *   that divide evenly the two agree exactly; where they do not (the demo's
 *   laptops: 150,000.00 over 36 months) the seed runs slightly ahead of the
 *   line and the next run here pulls it back (416,665 instead of 416,667).
 *
 * ─── DECLINING BALANCE (DOUBLE DECLINING) ─────────────────────────────────
 *   months left = life − k + 1                  (this month included)
 *   charge(k)   = max(round(NBV × 2 ÷ life), round((NBV − residual) ÷ months left)),
 *                 never more than NBV − residual
 *   The second term is the switch to straight line over the remaining months
 *   once it gives more. The last month of the life (and any month after it)
 *   takes exactly what is left, so the asset ends on its residual value.
 *
 * ─── ROUNDING ─────────────────────────────────────────────────────────────
 *   Half away from zero (div_round). Every quantity here is ≥ 0, so that is
 *   half up. Integer arithmetic throughout: a cost of up to 10^15 centavos
 *   over a life of up to MAX_LIFE_MONTHS never overflows a 64-bit integer.
 */
class Depreciation_lib
{
    const METHODS = ['straight_line', 'declining_balance'];

    const METHOD_LABELS = [
        'straight_line'     => 'Straight line',
        'declining_balance' => 'Declining balance (double)',
    ];

    /** A hundred years. Longer is a typing mistake, and it keeps base × k far from overflowing. */
    const MAX_LIFE_MONTHS = 1200;

    // =========================================================================
    // NUMBERS AND MONTHS
    // =========================================================================

    /** $a ÷ $b rounded half away from zero, in integers. */
    public static function div_round($a, $b)
    {
        $a = (int) $a;
        $b = (int) $b;
        if ($b === 0) throw new InvalidArgumentException('Division by zero.');
        $neg = ($a < 0) !== ($b < 0) && $a !== 0;
        $a = abs($a);
        $b = abs($b);
        $q = intdiv($a, $b);
        if (($a % $b) * 2 >= $b) $q++;
        return $neg ? -$q : $q;
    }

    /** 'Y-m-d' (or 'Y-m') → a running month number: year × 12 + month − 1. */
    public static function month_no($ymd)
    {
        $s = (string) $ymd;
        return (int) substr($s, 0, 4) * 12 + (int) substr($s, 5, 2) - 1;
    }

    /** The first day of running month number $n, 'Y-m-01'. */
    public static function month_start($n)
    {
        $n = (int) $n;
        return sprintf('%04d-%02d-01', intdiv($n, 12), $n % 12 + 1);
    }

    /** The last day of running month number $n. */
    public static function month_end($n)
    {
        $s = self::month_start($n);
        return $s === '' ? '' : date('Y-m-t', strtotime($s));
    }

    /**
     * Which month of an asset's depreciation the month holding $date is:
     * 1 in the depreciation_start month, 0 or less before it.
     */
    public static function month_index($depreciation_start, $date)
    {
        return self::month_no($date) - self::month_no($depreciation_start) + 1;
    }

    /**
     * The first month depreciated for an asset acquired on $acquired_on:
     * the 1st of that month ('same_month') or of the next ('next_month').
     */
    public static function default_start($acquired_on, $mode = 'next_month')
    {
        $n = self::month_no($acquired_on);
        return self::month_start($mode === 'same_month' ? $n : $n + 1);
    }

    /** The residual value a category suggests: cost × basis points ÷ 10,000, rounded. */
    public static function residual_for($cost_cents, $residual_bp)
    {
        return self::div_round((int) $cost_cents * (int) $residual_bp, 10000);
    }

    // =========================================================================
    // THE CHARGE FOR ONE MONTH
    // =========================================================================

    /** What is depreciated over the whole life: cost − residual, never below 0. */
    public static function base(array $a)
    {
        return max(0, (int) $a['cost_cents'] - (int) $a['residual_cents']);
    }

    /** Has the asset reached its depreciable base? (An asset with no base — land — never is.) */
    public static function is_fully_depreciated(array $a, $accum)
    {
        $base = self::base($a);
        return $base > 0 && (int) $accum >= $base;
    }

    /**
     * The depreciation for month $k of the asset's life.
     *
     * @param array $a      cost_cents, residual_cents, useful_life_months, method
     * @param int   $accum  accumulated so far (opening + every entry)
     * @param int   $k      month_index() of the month being depreciated
     * @return int centavos, ≥ 0
     */
    public static function charge(array $a, $accum, $k)
    {
        $cost  = (int) $a['cost_cents'];
        $life  = max(1, (int) $a['useful_life_months']);
        $base  = self::base($a);
        $accum = (int) $accum;
        $k     = (int) $k;
        if ($base <= 0 || $k < 1) return 0;

        $left = $base - $accum;
        if ($left <= 0) return 0;

        if (($a['method'] ?? 'straight_line') === 'declining_balance') {
            $months_left = $life - $k + 1;
            if ($months_left <= 1) return $left;
            $ddb = self::div_round(($cost - $accum) * 2, $life);
            $sl  = self::div_round($left, $months_left);
            return min(max($ddb, $sl), $left);
        }

        $target = $k >= $life ? $base : self::div_round($base * $k, $life);
        return max(0, min($target - $accum, $left));
    }

    /**
     * Month by month from month $k_from, with $accum already charged, until the
     * asset reaches its base (or $limit months). Months that charge nothing
     * inside the life are kept (an opening balance ahead of the line); after
     * the life, only a final catch-up month can appear.
     *
     * @return array [['k', 'month' => 'Y-m-01', 'amount_cents', 'accum_after_cents', 'nbv_after_cents']]
     */
    public static function project(array $a, $accum, $k_from, $start_date, $limit = self::MAX_LIFE_MONTHS)
    {
        $out  = [];
        $base = self::base($a);
        $cost = (int) $a['cost_cents'];
        $life = max(1, (int) $a['useful_life_months']);
        $s    = self::month_no($start_date);
        $acc  = (int) $accum;
        if ($base <= 0) return $out;

        for ($k = max(1, (int) $k_from), $n = 0; $n < $limit && $acc < $base; $k++, $n++) {
            $amt = self::charge($a, $acc, $k);
            if ($amt === 0 && $k > $life) break;
            $acc += $amt;
            $out[] = [
                'k'                 => $k,
                'month'             => self::month_start($s + $k - 1),
                'amount_cents'      => $amt,
                'accum_after_cents' => $acc,
                'nbv_after_cents'   => $cost - $acc,
            ];
        }
        return $out;
    }

    // =========================================================================
    // DISPOSAL
    // =========================================================================

    /**
     * How a disposal splits: the net book value, and the gain or the loss
     * against the proceeds (one of them is 0).
     *
     * @return array [nbv_cents, gain_cents, loss_cents]
     */
    public static function disposal_split($cost_cents, $accum_cents, $proceeds_cents)
    {
        $nbv = (int) $cost_cents - (int) $accum_cents;
        $p   = (int) $proceeds_cents;
        return [$nbv, max(0, $p - $nbv), max(0, $nbv - $p)];
    }
}
