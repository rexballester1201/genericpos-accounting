<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Arap_lib.php — what receivables and payables share
 *
 * GenericPOS Accounting · receivables and payables module
 *
 *   VAT          vat_split(), rate_bp(), vat_registered(), inclusive_default()
 *   money        round_div() (half away from zero), qty_amount(), parse_qty(), cents()
 *   numbers      number('invoice' | … | 'receipt' | 'payment') — INSIDE the posting transaction
 *   the chart    account_id_for('acct_ar_control'), control_ids('ar'), cash_accounts()
 *   aging        buckets(), bucket_labels(), bucket_of()
 *   words        amount_in_words(123450) → "One thousand two hundred thirty-four pesos and 50/100"
 *
 * NOTE: SETTINGS ARE READ INSIDE THE METHODS, never in the constructor: the
 * settings hook runs after the controller (and so this library) is built.
 *
 * NOTE: VAT IS INTEGER ARITHMETIC. The rate is in basis points (12 % = 1200):
 *   amounts include VAT   vat = round(amount × r / (10000 + r)),  net = amount − vat
 *   amounts exclude VAT   vat = round(amount × r / 10000),         net = amount
 * rounded half away from zero, one line at a time; a document's net, VAT and
 * total are the sums of its lines.
 */
class Arap_lib
{
    const DOC_TYPES   = ['invoice', 'credit_note', 'bill', 'debit_note'];
    const KINDS       = ['receipt', 'payment'];
    const VAT_MODES   = ['vatable', 'exempt', 'zero_rated'];
    const TYPE_LABELS = ['invoice' => 'Invoice', 'credit_note' => 'Credit note', 'bill' => 'Bill', 'debit_note' => 'Debit note'];
    const KIND_LABELS = ['receipt' => 'Receipt', 'payment' => 'Payment'];

    private $CI;

    public function __construct()
    {
        $this->CI =& get_instance();
    }

    // =========================================================================
    // WHAT GOES WITH WHAT
    // =========================================================================

    /** 'sales' for invoices and credit notes, 'purchases' for bills and debit notes. */
    public static function side($type)
    {
        return in_array($type, ['invoice', 'credit_note'], TRUE) ? 'sales' : 'purchases';
    }

    /** 'ar' for the customer side (invoices, credit notes, receipts), 'ap' for the supplier side. */
    public static function ledger_side($type_or_kind)
    {
        return in_array($type_or_kind, ['invoice', 'credit_note', 'receipt', 'sales', 'ar'], TRUE) ? 'ar' : 'ap';
    }

    public static function is_note($type)
    {
        return $type === 'credit_note' || $type === 'debit_note';
    }

    /** The document a settlement or note settles on this side: invoices (ar) or bills (ap). */
    public static function target_type($ledger_side)
    {
        return $ledger_side === 'ar' ? 'invoice' : 'bill';
    }

    /** The note that credits this side: credit notes (ar) or debit notes (ap). */
    public static function note_type($ledger_side)
    {
        return $ledger_side === 'ar' ? 'credit_note' : 'debit_note';
    }

    public static function kind_for($ledger_side)
    {
        return $ledger_side === 'ar' ? 'receipt' : 'payment';
    }

    /** The book each kind of document posts to. */
    public static function book($type_or_kind)
    {
        $books = ['invoice' => 'sales', 'credit_note' => 'sales', 'bill' => 'purchases', 'debit_note' => 'purchases',
                  'receipt' => 'cash_receipts', 'payment' => 'cash_disbursements'];
        return $books[$type_or_kind] ?? 'general';
    }

    // =========================================================================
    // MONEY
    // =========================================================================

    /**
     * $num / $den rounded half away from zero, exactly (bcmath, so a large
     * amount times a rate never overflows). Integers or digit strings.
     */
    public static function round_div($num, $den)
    {
        $num = (string) $num;
        $den = (string) $den;
        if (bccomp($den, '0') === 0) return 0;
        $neg = (bccomp($num, '0') < 0) !== (bccomp($den, '0') < 0);
        $n = ltrim($num, '-');
        $d = ltrim($den, '-');
        /* floor((2n + d) / 2d) = n/d rounded half up, for n, d ≥ 0 */
        $q = bcdiv(bcadd(bcmul($n, '2'), $d), bcmul($d, '2'), 0);
        return (int) (($neg && $q !== '0') ? '-' . $q : $q);
    }

    /**
     * [net, vat] of one line amount.
     *
     * @param int    $amount     the amount as typed, in centavos (> 0)
     * @param string $mode       vatable | exempt | zero_rated
     * @param bool   $inclusive  the amount includes VAT
     * @param int    $rate_bp    the VAT rate in basis points
     * @param bool   $registered the company is VAT-registered (else no VAT at all)
     */
    public static function vat_split($amount, $mode, $inclusive, $rate_bp, $registered)
    {
        $amount = (int) $amount;
        if ( ! $registered || $mode !== 'vatable' || (int) $rate_bp <= 0) return [$amount, 0];
        if ($inclusive) {
            $vat = self::round_div(bcmul((string) $amount, (string) (int) $rate_bp), 10000 + (int) $rate_bp);
            return [$amount - $vat, $vat];
        }
        return [$amount, self::round_div(bcmul((string) $amount, (string) (int) $rate_bp), 10000)];
    }

    /**
     * A quantity: up to 14 digits and 4 decimals, more than zero.
     * @return array|NULL [normalised string for DECIMAL(18,4), the quantity × 10000 as a digit string]
     */
    public static function parse_qty($raw)
    {
        if (is_int($raw)) $raw = (string) $raw;
        elseif (is_float($raw)) $raw = rtrim(rtrim(number_format($raw, 4, '.', ''), '0'), '.');
        $s = str_replace([',', ' '], '', trim((string) $raw));
        if ($s === '') return NULL;
        if ( ! preg_match('/^(\d{1,14})(?:\.(\d{1,4}))?$/', $s, $m)) return NULL;
        $frac   = str_pad($m[2] ?? '', 4, '0');
        $scaled = ltrim($m[1] . $frac, '0');
        if ($scaled === '') return NULL;                            // zero
        return [$m[1] . '.' . $frac, $scaled];
    }

    /** Quantity × unit price, rounded to the centavo. $qty_scaled is the quantity × 10000. */
    public static function qty_amount($qty_scaled, $price_cents)
    {
        return self::round_div(bcmul((string) $qty_scaled, (string) (int) $price_cents), 10000);
    }

    /** A whole, non-negative number of centavos from an int or a digit string; NULL otherwise. */
    public static function cents($v)
    {
        if (is_int($v)) return $v >= 0 && $v <= 99999999999999 ? $v : NULL;
        if (is_float($v) && floor($v) === $v && $v >= 0 && $v <= 99999999999999) return (int) $v;
        if (is_string($v) && preg_match('/^\d{1,14}$/', trim($v))) return (int) trim($v);
        if ($v === NULL || $v === '') return 0;
        return NULL;
    }

    // =========================================================================
    // SETTINGS
    // =========================================================================

    /** The VAT rate in basis points (12 % → 1200). */
    public function rate_bp()
    {
        $bp = pct_to_bp(shop_cfg('tax_rate_pct', 12));
        return $bp === NULL ? 1200 : max(0, min(5000, (int) $bp));
    }

    public function vat_registered()
    {
        return shop_bool('vat_registered', TRUE);
    }

    public function inclusive_default()
    {
        return shop_bool('prices_include_tax', TRUE);
    }

    /** Default payment terms for a customer ('ar') or a supplier ('ap'). */
    public function terms_default($ledger_side)
    {
        $d = (int) shop_cfg($ledger_side === 'ar' ? 'ar_default_terms_days' : 'ap_default_terms_days', 30);
        return max(0, min(365, $d));
    }

    /** The id of the account a Settings key names by its code (acct_ar_control …); 0 when unset or missing. */
    public function account_id_for($key)
    {
        $code = trim((string) shop_cfg($key, ''));
        if ($code === '') return 0;
        $r = $this->CI->db->select('id')->get_where('gp_accounts', ['code' => $code], 1)->row_array();
        return $r ? (int) $r['id'] : 0;
    }

    /** Every account flagged as a receivables ('ar') or payables ('ap') control account. */
    public function control_ids($ledger_side)
    {
        $ids = [];
        foreach ($this->CI->db->select('id')->where('control', $ledger_side === 'ar' ? 'ar' : 'ap')->get('gp_accounts')->result_array() as $r) {
            $ids[] = (int) $r['id'];
        }
        return $ids;
    }

    /**
     * The next document or settlement number: prefix, a dash, the gap-free
     * sequence zero-padded (INV-000124). Call it INSIDE the posting
     * transaction: a rolled-back posting gives the number back.
     */
    public function number($type_or_kind)
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) shop_cfg('doc_prefix_' . $type_or_kind, '')));
        if ($prefix === '') {
            $fallback = ['invoice' => 'INV', 'credit_note' => 'CN', 'bill' => 'BL', 'debit_note' => 'DN', 'receipt' => 'RC', 'payment' => 'PV'];
            $prefix = $fallback[$type_or_kind] ?? 'DOC';
        }
        $digits = (int) shop_cfg('doc_number_digits', 6);
        if ($digits < 3 || $digits > 10) $digits = 6;
        return $prefix . '-' . str_pad((string) next_sequence('doc:' . $type_or_kind), $digits, '0', STR_PAD_LEFT);
    }

    // =========================================================================
    // THE CHART
    // =========================================================================

    /**
     * Accounts a receipt or payment may move cash through: postable and active,
     * with the cash-flow class 'cash' — its own or inherited from a header above
     * it. With no account classed that way, accounts tagged 'cash' serve.
     *
     * @return array [id, code, name, bank] in chart order
     */
    public function cash_accounts()
    {
        $rows = $this->CI->db->select('id, code, name, parent_id, is_header, is_active, cash_flow, tags, control')
                             ->order_by('sort_order', 'ASC')->order_by('code', 'ASC')->get('gp_accounts')->result_array();
        $by = [];
        foreach ($rows as $r) $by[(int) $r['id']] = $r;

        $inherited = function ($id, $field) use (&$by) {
            $seen = 0;
            while ($id && isset($by[$id]) && $seen++ < 50) {
                $a = $by[$id];
                if ($field === 'cf' && $a['cash_flow'] !== NULL && $a['cash_flow'] !== '') return $a['cash_flow'];
                if ($field === 'tag' && in_array('cash', explode(',', (string) $a['tags']), TRUE)) return 'cash';
                $id = (int) $a['parent_id'];
            }
            return NULL;
        };

        $banks = [];
        foreach ($this->CI->db->select('account_id, bank_name, account_last4')->get('gp_bank_accounts')->result_array() as $b) {
            $banks[(int) $b['account_id']] = trim($b['bank_name'] . ($b['account_last4'] ? ' ···' . $b['account_last4'] : ''));
        }

        $pick = function ($field) use ($rows, $inherited, $banks) {
            $out = [];
            foreach ($rows as $r) {
                if ((int) $r['is_header'] || ! (int) $r['is_active'] || ($r['control'] !== NULL && $r['control'] !== '')) continue;
                if ($inherited((int) $r['id'], $field) !== 'cash') continue;
                $out[] = ['id' => (int) $r['id'], 'code' => $r['code'], 'name' => $r['name'], 'bank' => $banks[(int) $r['id']] ?? NULL];
            }
            return $out;
        };
        $list = $pick('cf');
        return $list ?: $pick('tag');
    }

    // =========================================================================
    // AGING
    // =========================================================================

    /** The aging bucket limits from Settings ("30,60,90" → [30, 60, 90]), ascending. */
    public static function buckets()
    {
        $raw = shop_cfg('aging_buckets', '30,60,90');
        $parts = is_array($raw) ? $raw : explode(',', (string) $raw);
        $out = [];
        foreach ($parts as $p) {
            $n = (int) trim((string) $p);
            if ($n > 0 && ( ! $out || $n > end($out))) $out[] = $n;
        }
        return $out ?: [30, 60, 90];
    }

    /** ['Not yet due', '1–30', '31–60', '61–90', 'Over 90'] for [30, 60, 90]. */
    public static function bucket_labels(array $b)
    {
        $labels = ['Not yet due'];
        $from = 1;
        foreach ($b as $to) {
            $labels[] = $from . '–' . $to;
            $from = $to + 1;
        }
        $labels[] = 'Over ' . end($b);
        return $labels;
    }

    /** The bucket an item falls in: 0 not yet due, 1 … n past due, n+1 beyond the last limit. */
    public static function bucket_of($days_past_due, array $b)
    {
        if ($days_past_due <= 0) return 0;
        foreach ($b as $i => $to) if ($days_past_due <= $to) return $i + 1;
        return count($b) + 1;
    }

    /** Whole days from $from to $to (Y-m-d), negative when $to is earlier. */
    public static function days_between($from, $to)
    {
        return (int) round((strtotime($to . ' 00:00:00 UTC') - strtotime($from . ' 00:00:00 UTC')) / 86400);
    }

    // =========================================================================
    // AMOUNTS IN WORDS (vouchers and cheques)
    // =========================================================================

    /**
     * "Twelve thousand three hundred forty-five pesos and 60/100" — the way a
     * Philippine voucher or cheque writes an amount. Other currencies use their
     * code as the unit.
     */
    public static function amount_in_words($cents, $code = 'PHP')
    {
        $cents = abs((int) $cents);
        $whole = intdiv($cents, 100);
        $frac  = $cents % 100;
        $unit  = strtoupper((string) $code) === 'PHP' ? ($whole === 1 ? 'peso' : 'pesos') : strtoupper((string) $code);
        $text  = self::_words($whole) . ' ' . $unit . ' and ' . str_pad((string) $frac, 2, '0', STR_PAD_LEFT) . '/100';
        return ucfirst($text);
    }

    private static function _words($n)
    {
        $ones = ['zero', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve',
                 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
        if ($n < 20) return $ones[$n];
        if ($n < 100) return $tens[intdiv($n, 10)] . ($n % 10 ? '-' . $ones[$n % 10] : '');
        if ($n < 1000) return $ones[intdiv($n, 100)] . ' hundred' . ($n % 100 ? ' ' . self::_words($n % 100) : '');
        foreach ([1000000000000 => 'trillion', 1000000000 => 'billion', 1000000 => 'million', 1000 => 'thousand'] as $scale => $name) {
            if ($n >= $scale) {
                return self::_words(intdiv($n, $scale)) . ' ' . $name . ($n % $scale ? ' ' . self::_words($n % $scale) : '');
            }
        }
        return (string) $n;
    }
}
