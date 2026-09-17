<?php
// fa-lib-test.php — unit checks of application/libraries/Depreciation_lib.php (pure maths, no database)
//
//   php fa-lib-test.php
//
// Exit code 1 when anything fails.
define('BASEPATH', __DIR__);
require 'D:/xampp/htdocs/dashboard/accounting/application/libraries/Depreciation_lib.php';

$passed = 0;
$failed = 0;
function check($name, $cond, $info = NULL)
{
    global $passed, $failed;
    if ($cond) $passed++; else $failed++;
    echo ($cond ? 'PASS  ' : 'FAIL  ') . $name . ( ! $cond && $info !== NULL ? "\n        " . (is_string($info) ? $info : json_encode($info)) : '') . "\n";
    return (bool) $cond;
}

$L = 'Depreciation_lib';
$asset = function ($cost, $life, $method = 'straight_line', $residual = 0) {
    return ['cost_cents' => $cost, 'residual_cents' => $residual, 'useful_life_months' => $life, 'method' => $method];
};
/** Run every month from $k0 to $k1 (skipping any in $skip) and return [total, accum, amounts by k]. */
$simulate = function (array $a, $accum, $k0, $k1, array $skip = []) use ($L) {
    $total = 0;
    $by = [];
    for ($k = $k0; $k <= $k1; $k++) {
        if (in_array($k, $skip, TRUE)) continue;
        $amt = $L::charge($a, $accum, $k);
        $by[$k] = $amt;
        $accum += $amt;
        $total += $amt;
    }
    return [$total, $accum, $by];
};

// ── rounding and months ─────────────────────────────────────────────────────
check('div_round rounds half away from zero', $L::div_round(5, 2) === 3 && $L::div_round(3, 2) === 2 && $L::div_round(1, 3) === 0
    && $L::div_round(2, 3) === 1 && $L::div_round(-5, 2) === -3 && $L::div_round(0, 7) === 0 && $L::div_round(14, 7) === 2);
check('month index: May 2024 start, January 2026 is month 21', $L::month_index('2024-05-01', '2026-01-31') === 21);
check('month index: the start month is month 1, the month before is 0', $L::month_index('2026-04-01', '2026-04-30') === 1 && $L::month_index('2026-04-01', '2026-03-31') === 0);
check('start: acquired 16 March, next month = 1 April', $L::default_start('2026-03-16', 'next_month') === '2026-04-01');
check('start: acquired 16 March, same month = 1 March', $L::default_start('2026-03-16', 'same_month') === '2026-03-01');
check('start: acquired 20 December, next month = 1 January next year', $L::default_start('2026-12-20', 'next_month') === '2027-01-01');
check('month start and end', $L::month_start($L::month_no('2028-02-15')) === '2028-02-01' && $L::month_end($L::month_no('2028-02-15')) === '2028-02-29');
check('residual from basis points, rounded half up', $L::residual_for(10000001, 500) === 500000 && $L::residual_for(10000010, 500) === 500001 && $L::residual_for(10000000, 0) === 0);

// ── straight line: the months add up to the base exactly ────────────────────
$bad = [];
$cases = 0;
foreach ([10000001, 10000000, 1, 99, 123456789, 999999999999999] as $cost) {
    foreach ([1, 7, 12, 36, 37, 60, 1200] as $life) {
        foreach ([0, 500, 1000] as $bp) {
            $res = $L::residual_for($cost, $bp);
            $a = $asset($cost, $life, 'straight_line', $res);
            $base = $cost - $res;
            $p = $L::project($a, 0, 1, '2026-01-01');
            $sum = array_sum(array_column($p, 'amount_cents'));
            $last = end($p);
            $lo = intdiv($base, $life);
            $hi = $lo + ($base % $life ? 1 : 0);
            $even = TRUE;
            foreach ($p as $m) if ($m['amount_cents'] < ($m['amount_cents'] === 0 ? 0 : $lo) || $m['amount_cents'] > $hi) $even = FALSE;
            $cases++;
            if ($sum !== $base || ! $last || $last['accum_after_cents'] !== $base || $last['k'] > $life || ! $even) {
                $bad[] = compact('cost', 'life', 'bp', 'sum', 'base') + ['last_k' => $last ? $last['k'] : NULL, 'even' => $even];
            }
        }
    }
}
check('straight line: ' . $cases . ' cost / life / residual combinations each add up to cost − residual, within the life, every month floor or ceiling of base ÷ life', ! $bad, array_slice($bad, 0, 3));

foreach ([7, 37, 60] as $life) {
    $a = $asset(10000001, $life);
    list($total, $acc, $by) = $simulate($a, 0, 1, $life);
    check('100,000.01 over ' . $life . ' months: ' . count($by) . ' months total 100,000.01 exactly (first ' . $by[1] . ', last ' . $by[$life] . ')',
        $total === 10000001 && $acc === 10000001 && count($by) === $life);
}
$a = $asset(10000001, 60);
check('nothing is charged after the base is reached, or before month 1', $L::charge($a, 10000001, 61) === 0 && $L::charge($a, 0, 0) === 0 && $L::charge($a, 0, -3) === 0);
check('the charge never exceeds what is left', $L::charge($a, 10000000, 60) === 1 && $L::charge($a, 10000000, 200) === 1);
check('an asset with no depreciable base (land) is never charged and never "fully depreciated"',
    $L::charge($asset(5000000, 60, 'straight_line', 5000000), 0, 5) === 0 && ! $L::is_fully_depreciated($asset(5000000, 60, 'straight_line', 5000000), 0)
    && $L::project($asset(5000000, 60, 'straight_line', 5000000), 0, 1, '2026-01-01') === []);

// ── declining balance ───────────────────────────────────────────────────────
$badDb = [];
$dbCases = 0;
foreach ([10000001, 12000000, 99999, 999999999999999] as $cost) {
    foreach ([7, 37, 60, 120] as $life) {
        foreach ([0, 500, 1000, 3000] as $bp) {
            $res = $L::residual_for($cost, $bp);
            $a = $asset($cost, $life, 'declining_balance', $res);
            $p = $L::project($a, 0, 1, '2026-01-01');
            $last = end($p);
            $floor = TRUE;
            $prevNbv = $cost;
            foreach ($p as $m) {
                if ($m['nbv_after_cents'] < $res || $m['amount_cents'] < 0 || $m['nbv_after_cents'] > $prevNbv) $floor = FALSE;
                $prevNbv = $m['nbv_after_cents'];
            }
            $dbCases++;
            if ( ! $last || $last['nbv_after_cents'] !== $res || $last['k'] > $life || ! $floor) {
                $badDb[] = compact('cost', 'life', 'bp', 'res') + ['last' => $last];
            }
        }
    }
}
check('declining balance: ' . $dbCases . ' combinations never go below the residual and end exactly on it within the life', ! $badDb, array_slice($badDb, 0, 2));
$a = $asset(12000000, 60, 'declining_balance');
check('declining balance: the first month is NBV × 2 ÷ life (12,000,000 over 60 → 400,000)', $L::charge($a, 0, 1) === 400000);
check('declining balance: month 2 works on the lower NBV (11,600,000 × 2 ÷ 60 → 386,667)', $L::charge($a, 400000, 2) === 386667);
$p = $L::project($a, 0, 1, '2026-01-01');
$switch = NULL;
foreach ($p as $m) {
    $nbv = $m['nbv_after_cents'] + $m['amount_cents'];
    if ($m['amount_cents'] > $L::div_round($nbv * 2, 60)) { $switch = $m['k']; break; }
}
check('declining balance switches to straight line over the remaining months (from month ' . $switch . ') and the last month takes the remainder',
    $switch !== NULL && $switch > 1 && end($p)['k'] === 60 && end($p)['nbv_after_cents'] === 0);
$a = $asset(10000001, 37, 'declining_balance', 1000000);
list($t1, $acc1) = $simulate($a, 0, 1, 37, [2, 3]);
check('declining balance: two skipped months still end exactly on the residual by the last month', $acc1 === 10000001 - 1000000, [$acc1]);
check('declining balance: after the life the next run takes everything left', $L::charge($a, 5000000, 40) === 10000001 - 1000000 - 5000000);

// ── opening accumulated depreciation (assets brought in at go-live) ─────────
$oe = $asset(45000000, 60);
check('opening on the line: FA-0001 (450,000 over 60, 150,000 opening, month 21) charges 750,000', $L::charge($oe, 15000000, 21) === 750000);
$p = $L::project($oe, 15000000, 21, '2024-05-01');
check('opening on the line: the rest of the life (months 21–60) adds exactly cost − opening', array_sum(array_column($p, 'amount_cents')) === 30000000 && count($p) === 40 && end($p)['k'] === 60 && $p[0]['month'] === '2026-01-01');
$a = $asset(12000000, 60);
list($tot, $acc, $by) = $simulate($a, 5000000, 21, 60);
check('opening ahead of the line: nothing is charged until the line passes it (months 21–25 zero), then 200,000 a month; total = cost − opening',
    $by[21] === 0 && $by[25] === 0 && $by[26] === 200000 && $tot === 7000000 && $acc === 12000000, $by);
list($tot, $acc, $by) = $simulate($a, 3000000, 21, 60);
check('opening behind the line: the first month catches up (1,200,000), then 200,000 a month; total = cost − opening',
    $by[21] === 1200000 && $by[22] === 200000 && $tot === 9000000 && $acc === 12000000, [$by[21], $by[22], $tot]);
check('opening at or above the base: never charged, and fully depreciated',
    $L::charge($a, 12000000, 30) === 0 && $L::project($a, 12000000, 30, '2024-01-01') === [] && $L::is_fully_depreciated($a, 12000000));
check('accumulated (opening + entries) never exceeds the base', max(array_column($L::project($a, 11999999, 59, '2024-01-01'), 'accum_after_cents')) === 12000000);

// ── catch-up after a skipped month ──────────────────────────────────────────
$a = $asset(10000001, 37);
$t = function ($k) use ($L) { return $L::div_round(10000001 * $k, 37); };
$m1 = $L::charge($a, 0, 1);
$m3 = $L::charge($a, $m1, 3);
check('a skipped month is caught up by the next run: month 3 after month 2 was missed charges two months (' . $m3 . ')', $m3 === $t(3) - $t(1));
check('and the month after is back to one month', $L::charge($a, $m1 + $m3, 4) === $t(4) - $t(3));
list($tot) = $simulate($a, 0, 1, 37, [2, 10, 11]);
check('three skipped months, the total over the life is still exactly the base', $tot === 10000001);
list($tot, $acc) = $simulate($a, 0, 1, 40, [36, 37, 38, 39]);
check('the last months skipped: the first run after the life takes everything left', $tot === 10000001 && $acc === 10000001);

// ── the demo seed's assets ──────────────────────────────────────────────────
$seed = [
    'FA-0001 office equipment' => [$asset(45000000, 60), '2024-05-01', 15000000, 750000],
    'FA-0002 furniture'        => [$asset(18000000, 60), '2024-07-01', 5400000, 300000],
    'FA-0003 truck'            => [$asset(120000000, 60), '2024-05-01', 40000000, 2000000],
];
foreach ($seed as $name => list($a, $start, $opening, $monthly)) {
    $k0 = $L::month_index($start, '2026-01-31');
    list($tot, $acc, $by) = $simulate($a, $opening, $k0, $k0 + 7);
    check('seed agreement, ' . $name . ': January to August 2026 charge ' . $monthly . ' every month, as the seed did',
        count(array_unique($by)) === 1 && reset($by) === $monthly && $tot === 8 * $monthly);
}
$lap = $asset(15000000, 36);
list($tot, $acc, $by) = $simulate($lap, 0, 1, 5);
check('seed difference, FA-0004 laptops (150,000 over 36): the exact line charges ' . implode(', ', $by) . ' (2,083,333) where the seed charged 416,667 × 5 = 2,083,335',
    $tot === 2083333 && $by[1] === 416667 && $by[2] === 416666);
check('continuing after the seed: September (month 6) charges 416,665, pulling the seed\'s extra 2 centavos back onto the line', $L::charge($lap, 2083335, 6) === 416665);
$p = $L::project($lap, 2083335, 6, '2026-04-01');
check('and the laptops still end exactly on 150,000.00 in March ' . substr(end($p)['month'], 0, 4) . ' (month 36)', array_sum(array_column($p, 'amount_cents')) + 2083335 === 15000000 && end($p)['k'] === 36 && end($p)['month'] === '2029-03-01');

// ── disposal ────────────────────────────────────────────────────────────────
list($nbv, $gain, $loss) = $L::disposal_split(120000000, 56000000, 70000000);
check('disposal with a gain: cost 1,200,000, accumulated 560,000, sold for 700,000 → NBV 640,000, gain 60,000', $nbv === 64000000 && $gain === 6000000 && $loss === 0);
list($nbv, $gain, $loss) = $L::disposal_split(18000000, 7800000, 5000001);
check('disposal with a loss: cost 180,000, accumulated 78,000, sold for 50,000.01 → NBV 102,000, loss 51,999.99', $nbv === 10200000 && $gain === 0 && $loss === 5199999);
list($nbv, $gain, $loss) = $L::disposal_split(15000000, 2500000, 0);
check('scrapped for nothing: the whole NBV is the loss', $nbv === 12500000 && $loss === 12500000 && $gain === 0);
$ok = TRUE;
foreach ([[100, 0, 0], [100, 100, 0], [100, 40, 60], [100, 40, 61], [100, 40, 59], [999999999999, 12345, 1]] as list($c, $acc, $pr)) {
    list($nbv, $gain, $loss) = $L::disposal_split($c, $acc, $pr);
    if ($acc + $pr + $loss !== $c + $gain) $ok = FALSE;
}
check('a disposal always balances: accumulated + proceeds + loss = cost + gain', $ok);

echo "\n" . $passed . ' passed, ' . $failed . " failed\n";
exit($failed ? 1 : 0);
