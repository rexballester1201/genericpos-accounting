<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * shop_helper.php — money, tokens, slugs and sequences
 *
 * GenericPOS · autoloaded (config/autoload.php)
 *
 * ─── MONEY IS AN INTEGER NUMBER OF MINOR UNITS ────────────────────────────
 * Every amount this application stores or compares is an int of cents (or
 * centavos, or whatever the store currency's minor unit is). Major-unit
 * strings ("1,234.50") exist only at the edges: parsed on the way in by
 * money_cents(), rendered on the way out by money_major()/money_format().
 *
 * Parsing is exact — bcmath on the string, never a float multiply. As a float,
 * 19.99 * 100 is 1998.9999999999998 and (int) of it is 1998: a cent lost on
 * every such price, silently, forever.
 */

if ( ! function_exists('shop_cfg'))
{
    /**
     * A config value (after admin overrides) with an EXPLICIT null check.
     * Never `?:` — zero and FALSE are real settings here.
     */
    function shop_cfg($key, $default = NULL)
    {
        $CI =& get_instance();
        $CI->config->load('app', FALSE, TRUE);
        $v = $CI->config->item($key);
        return ($v === NULL || $v === '') && $default !== NULL ? $default : ($v === NULL ? $default : $v);
    }
}

if ( ! function_exists('shop_bool'))
{
    /** A boolean setting, tolerant of '0' / 'false' strings from overrides. */
    function shop_bool($key, $default = FALSE)
    {
        $v = shop_cfg($key, NULL);
        if ($v === NULL) return (bool) $default;
        if (is_bool($v)) return $v;
        return ! in_array(strtolower(trim((string) $v)), ['0', '', 'false', 'no', 'off'], TRUE);
    }
}

if ( ! function_exists('push_public_key'))
{
    /**
     * The VAPID public key when notifications on devices can really be sent,
     * else ''. The store must have switched feature_web_push on AND configured
     * the public key, the private key and the subject. One test, used by the
     * public store config, the subscribe endpoint and the sender, so the three
     * can never disagree about whether push is on.
     */
    function push_public_key()
    {
        if ( ! shop_bool('feature_web_push', FALSE)) return '';

        require_once APPPATH . 'helpers/secrets_helper.php';
        $CI =& get_instance();

        $pub  = gp_secret('GP_VAPID_PUBLIC_KEY')  ?: (string) $CI->config->item('vapid_public_key');
        $priv = gp_secret('GP_VAPID_PRIVATE_KEY') ?: (string) $CI->config->item('vapid_private_key');
        $subj = gp_secret('GP_VAPID_SUBJECT')     ?: (string) $CI->config->item('vapid_subject');

        return ($pub !== '' && $priv !== '' && $subj !== '') ? (string) $pub : '';
    }
}

if ( ! function_exists('currency_decimals'))
{
    function currency_decimals()
    {
        $d = (int) shop_cfg('currency_decimals', 2);
        return max(0, min(4, $d));
    }
}

if ( ! function_exists('currency_scale'))
{
    /** Minor units per major unit: 100 for two decimals, 1 for none. */
    function currency_scale()
    {
        return (int) bcpow('10', (string) currency_decimals(), 0);
    }
}

if ( ! function_exists('money_cents'))
{
    /**
     * Major-unit amount -> integer minor units, EXACTLY. NULL when invalid.
     *
     *   money_cents('1,234.5')  → 123450     (2 decimals)
     *   money_cents('19.99')    → 1999
     *   money_cents('1.999')    → NULL        more precision than the currency has
     *   money_cents(12.5)       → 1250        floats are formatted first, then parsed
     *
     * NOTE: a value with MORE fraction digits than the currency is REFUSED, not
     * rounded. Somebody typing a price of 12.345 has made a mistake worth
     * surfacing; quietly choosing 12.35 or 12.34 for them is how a price list
     * drifts from what the owner meant.
     *
     * @param  mixed $raw
     * @param  bool  $allow_negative
     * @return int|null
     */
    function money_cents($raw, $allow_negative = FALSE)
    {
        $dp = currency_decimals();

        if (is_int($raw)) {
            $raw = (string) $raw;
        } elseif (is_float($raw)) {
            if ( ! is_finite($raw)) return NULL;
            $raw = number_format($raw, $dp, '.', '');
        }

        $s = trim((string) $raw);
        $s = str_replace([',', ' ', '_'], '', $s);

        if ($s === '' || ! preg_match('/^(-?)(\d{1,13})(?:\.(\d*))?$/', $s, $m)) return NULL;

        $neg  = ($m[1] === '-');
        $frac = isset($m[3]) ? $m[3] : '';

        if ($neg && ! $allow_negative) return NULL;
        if (strlen($frac) > $dp) {
            /* Trailing zeros beyond the precision are harmless ("12.500"). */
            if (rtrim(substr($frac, $dp), '0') !== '') return NULL;
            $frac = substr($frac, 0, $dp);
        }

        $frac   = str_pad($frac, $dp, '0');
        $digits = ltrim($m[2] . $frac, '0');
        if ($digits === '') $digits = '0';

        if (strlen($digits) > 17) return NULL;   // beyond any sane amount, and near PHP_INT_MAX

        $v = (int) $digits;
        return $neg ? -$v : $v;
    }
}

if ( ! function_exists('money_major'))
{
    /** Integer minor units -> plain decimal string: 123450 → "1234.50". */
    function money_major($cents)
    {
        $c  = (int) $cents;
        $dp = currency_decimals();
        if ($dp === 0) return (string) $c;
        return bcdiv((string) $c, (string) currency_scale(), $dp);
    }
}

if ( ! function_exists('money_format_cents'))
{
    /**
     * Integer minor units -> display string with symbol and grouping:
     * 123450 → "₱1,234.50". For emails, receipts and log lines; the SPA formats
     * on its own from the store config.
     */
    function money_format_cents($cents)
    {
        $c   = (int) $cents;
        $neg = $c < 0;
        $s   = money_major(abs($c));

        $dec_sep = (string) shop_cfg('currency_decimal_sep', '.');
        $grp_sep = (string) shop_cfg('currency_thousands_sep', ',');

        $parts = explode('.', $s, 2);
        $int   = preg_replace('/\B(?=(\d{3})+(?!\d))/', $grp_sep, $parts[0]);
        $out   = isset($parts[1]) ? $int . $dec_sep . $parts[1] : $int;

        $sym = (string) shop_cfg('currency_symbol', '');
        $out = (shop_cfg('currency_symbol_position', 'before') === 'after')
             ? $out . ' ' . $sym
             : $sym . $out;

        return ($neg ? '-' : '') . trim($out);
    }
}

if ( ! function_exists('bp_of'))
{
    /**
     * Basis points of an amount, rounded half-up: bp_of(10000, 1250) → 1250.
     * 1 bp = 0.01%. Integer arithmetic throughout; amounts up to ~9e14 cents
     * are safe from overflow.
     */
    function bp_of($cents, $bp)
    {
        $c = (int) $cents; $b = (int) $bp;
        $neg = ($c < 0) !== ($b < 0) && $c !== 0 && $b !== 0;
        $v = intdiv(abs($c) * abs($b) + 5000, 10000);
        return $neg ? -$v : $v;
    }
}

if ( ! function_exists('pct_to_bp'))
{
    /** "12.5" / 12.5 → 1250 basis points, exactly (max 4 dp). NULL when invalid. */
    function pct_to_bp($pct)
    {
        $s = trim(is_float($pct) ? number_format($pct, 4, '.', '') : (string) $pct);
        if ( ! preg_match('/^\d{1,3}(\.\d{1,4})?$/', $s)) return NULL;
        return (int) bcmul($s, '100', 0);
    }
}

if ( ! function_exists('random_token'))
{
    /** Hex token from a CSPRNG. 16 bytes → 32 characters. */
    function random_token($bytes = 16)
    {
        return bin2hex(random_bytes(max(8, (int) $bytes)));
    }
}

if ( ! function_exists('slugify'))
{
    /** "Café Latte (Large)" → "cafe-latte-large". Never empty. */
    function slugify($text, $max = 120)
    {
        $s = (string) $text;
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
            if ($t !== FALSE) $s = $t;
        }
        $s = strtolower($s);
        $s = preg_replace('/[^a-z0-9]+/', '-', $s);
        $s = trim($s, '-');
        if (strlen($s) > $max) $s = rtrim(substr($s, 0, $max), '-');
        return $s !== '' ? $s : 'item-' . substr(random_token(4), 0, 6);
    }
}

if ( ! function_exists('next_sequence'))
{
    /**
     * Atomically take the next number from a named sequence (gp_counters).
     *
     * NOTE: ONE STATEMENT, NOT READ-THEN-WRITE. Two checkouts in the same
     * millisecond must receive different order numbers; LAST_INSERT_ID(expr) is
     * connection-scoped, so each caller reads back exactly the value its own
     * statement wrote, whatever else commits in between.
     *
     * @return int
     */
    function next_sequence($name)
    {
        $CI =& get_instance();
        $CI->load->database();

        $CI->db->query(
            'INSERT INTO gp_counters (name, value, updated_at) VALUES (?, LAST_INSERT_ID(1), ?)
             ON DUPLICATE KEY UPDATE value = LAST_INSERT_ID(value + 1), updated_at = VALUES(updated_at)',
            [(string) $name, date('Y-m-d H:i:s')]
        );

        $row = $CI->db->query('SELECT LAST_INSERT_ID() AS v')->row_array();
        return $row ? (int) $row['v'] : 0;
    }
}

if ( ! function_exists('store_day_utc'))
{
    /**
     * A calendar day in the STORE's time zone (display_timezone) as the UTC
     * bounds the database stores — "today" in Manila starts at 16:00 UTC the
     * day before. $ymd NULL = today. NULL for a malformed date.
     *
     * @return array|null ['Y-m-d H:i:s' start, 'Y-m-d H:i:s' end] in UTC
     */
    function store_day_utc($ymd = NULL)
    {
        try { $tz = new DateTimeZone((string) shop_cfg('display_timezone', 'UTC') ?: 'UTC'); }
        catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
        $utc = new DateTimeZone('UTC');
        if ($ymd === NULL) $ymd = (new DateTime('now', $tz))->format('Y-m-d');
        if ( ! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $ymd)) return NULL;
        $a = new DateTime($ymd . ' 00:00:00', $tz);
        $b = new DateTime($ymd . ' 23:59:59', $tz);
        return [$a->setTimezone($utc)->format('Y-m-d H:i:s'), $b->setTimezone($utc)->format('Y-m-d H:i:s')];
    }
}

if ( ! function_exists('company_today'))
{
    /** Today's date (Y-m-d) in the company's own time zone — the calendar entries are dated in. */
    function company_today()
    {
        try { $tz = new DateTimeZone((string) shop_cfg('display_timezone', 'UTC') ?: 'UTC'); }
        catch (Throwable $e) { $tz = new DateTimeZone('UTC'); }
        return (new DateTime('now', $tz))->format('Y-m-d');
    }
}

if ( ! function_exists('format_ref'))
{
    /** format_ref('SO', 123) → "SO-000123". */
    function format_ref($prefix, $n, $pad = 6)
    {
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $prefix));
        return ($prefix !== '' ? $prefix . '-' : '') . str_pad((string) (int) $n, $pad, '0', STR_PAD_LEFT);
    }
}

if ( ! function_exists('ip_rate_key'))
{
    /**
     * A stable NEGATIVE integer for the caller's IP, for rate_limit() on
     * endpoints with no user. Real user ids are positive, so the two ranges
     * can never collide. $salt separates unrelated limits sharing an address.
     */
    function ip_rate_key($salt = '')
    {
        $CI =& get_instance();
        $key = -abs(crc32((string) $salt . '|' . (string) $CI->input->ip_address()) % 2000000000);
        return $key === 0 ? -1 : $key;
    }
}

if ( ! function_exists('utc_now'))
{
    /** 'Y-m-d H:i:s' in UTC — the only clock this application stores. */
    function utc_now($offset_s = 0)
    {
        return date('Y-m-d H:i:s', time() + (int) $offset_s);
    }
}

if ( ! function_exists('decimal_string'))
{
    /**
     * Validate a plain non-negative decimal string with at most $max_dp
     * decimals ("58.25"). Returns it normalised, or NULL.
     */
    function decimal_string($raw, $max_dp = 8, $max_int_digits = 12)
    {
        $s = trim(is_float($raw) ? number_format($raw, $max_dp, '.', '') : (string) $raw);
        if ( ! preg_match('/^\d{1,' . (int) $max_int_digits . '}(\.\d{1,' . (int) $max_dp . '})?$/', $s)) return NULL;
        if (strpos($s, '.') !== FALSE) $s = rtrim(rtrim($s, '0'), '.');
        return $s === '' ? '0' : $s;
    }
}

if ( ! function_exists('clean_line'))
{
    /** One line of free text: control characters out, whitespace collapsed, bounded. */
    function clean_line($value, $max = 190)
    {
        $s = preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) ($value ?? ''));
        if ($s === NULL) $s = (string) $value;
        $s = trim(preg_replace('/\s+/u', ' ', $s));
        return mb_substr($s, 0, (int) $max);
    }
}

if ( ! function_exists('clean_text'))
{
    /** Multi-line free text: keep newlines, drop other control characters. */
    function clean_text($value, $max = 5000)
    {
        $s = str_replace(["\r\n", "\r"], "\n", (string) ($value ?? ''));
        $c = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
        if ($c === NULL) $c = $s;
        return mb_substr(trim($c), 0, (int) $max);
    }
}
