<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Store_model.php — the PUBLIC face of the company's configuration
 *
 * GenericPOS Accounting (the name and the store_* setting keys are kept from
 * GenericPOS so the mailer, the shell and the settings hook need no changes)
 *
 * ─── ONE SHAPE, THREE READERS ─────────────────────────────────────────────
 *   · GET /api/v1/store     the SPA reads it on boot (and the SW caches it)
 *   · serve_spa_shell()     injects branding into index.html BEFORE first paint
 *   · the web manifest      name and theme colour for "Add to home screen"
 *
 * ─── WHY A CACHE FILE ─────────────────────────────────────────────────────
 * The shell is served WITHOUT a database on purpose (autoload.php): an app
 * that cannot render while MySQL is down turns every blip into a blank page
 * instead of an app that can say what is wrong. But the shell still wants the
 * company's name and colours in the very first paint. So every settings save
 * writes the public config to application/cache/store_public.json, and the
 * shell reads that file — no connection, no query. When the file is missing
 * the shell falls back to the config-file defaults.
 *
 * NOTE: EVERYTHING HERE IS PUBLIC — it is served to anyone and injected into
 * every page before sign-in. Branding and number formats only: no TIN, no
 * address, no figures, no staff settings. The letterhead that printed reports
 * need comes from a signed-in endpoint instead.
 */
class Store_model extends CI_Model
{
    const CACHE_FILE = 'store_public.json';

    public function __construct()
    {
        parent::__construct();
        $this->config->load('app', FALSE, TRUE);
    }

    protected function c($key, $default = NULL)
    {
        $v = $this->config->item($key);
        return ($v === NULL) ? $default : $v;
    }

    protected function b($key, $default = FALSE)
    {
        $v = $this->config->item($key);
        if ($v === NULL) return (bool) $default;
        if (is_bool($v)) return $v;
        return ! in_array(strtolower(trim((string) $v)), ['0', '', 'false', 'no', 'off'], TRUE);
    }

    /** A #rrggbb colour or the fallback — values reach CSS and must be inert. */
    public static function safe_color($v, $fallback)
    {
        $v = trim((string) $v);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $v) ? strtolower($v) : $fallback;
    }

    /**
     * The public configuration, reflecting admin overrides.
     *
     * Callers must have loaded the database (so the SettingsOverride hook has
     * run) for overrides to apply; without it this returns the file defaults.
     */
    public function public_config()
    {
        $logo = trim((string) $this->c('store_logo', ''));

        return [
            'name'        => (string) $this->c('store_name', 'My Company'),
            'tagline'     => (string) $this->c('store_tagline', ''),
            'legal_name'  => (string) $this->c('store_legal_name', ''),
            'logo'        => $logo !== '' ? ltrim($logo, '/') : NULL,
            'entity_type' => $this->c('entity_type') === 'cooperative' ? 'cooperative' : 'business',

            'brand' => [
                'primary' => self::safe_color($this->c('brand_primary'), '#1d4ed8'),
                'accent'  => self::safe_color($this->c('brand_accent'),  '#0f766e'),
                'theme'   => in_array($this->c('theme_mode'), ['auto', 'light', 'dark'], TRUE)
                             ? $this->c('theme_mode') : 'auto',
                'radius'  => max(0, min(24, (int) $this->c('ui_radius', 8))),
                'font'    => in_array($this->c('font_family'), ['system', 'inter', 'poppins', 'nunito'], TRUE)
                             ? $this->c('font_family') : 'system',
            ],

            'currency' => [
                'code'      => strtoupper((string) $this->c('currency_code', 'PHP')),
                'symbol'    => (string) $this->c('currency_symbol', '₱'),
                'decimals'  => max(0, min(4, (int) $this->c('currency_decimals', 2))),
                'position'  => $this->c('currency_symbol_position') === 'after' ? 'after' : 'before',
                'thousands' => (string) $this->c('currency_thousands_sep', ','),
                'decimal'   => (string) $this->c('currency_decimal_sep', '.'),
                'locale'    => (string) $this->c('locale', 'en-PH'),
                'timezone'  => (string) $this->c('display_timezone', 'Asia/Manila'),
            ],

            'tax' => [
                'label'      => (string) $this->c('tax_label', 'VAT'),
                'rate'       => (float) $this->c('tax_rate_pct', 12),
                'inclusive'  => $this->b('prices_include_tax', TRUE),
                'registered' => $this->b('vat_registered', TRUE),
                'id_label'   => (string) $this->c('tax_id_label', 'TIN'),
            ],

            /* No self sign-up: an administrator creates every account. */
            'features' => ['registration' => FALSE],

            'software' => [
                'name'    => (string) $this->c('app_name', 'GenericPOS Accounting'),
                'version' => (string) $this->c('app_version', '1.0.0'),
            ],
        ];
    }

    // =========================================================================
    // THE SHELL'S CACHE
    // =========================================================================

    public static function cache_path()
    {
        return APPPATH . 'cache' . DIRECTORY_SEPARATOR . self::CACHE_FILE;
    }

    /**
     * Write the public config for the DB-free shell.
     *
     * NOTE: tmp file + rename, so a request reading the cache mid-write sees
     * either the old file or the new one — never half a JSON document, which
     * would make the shell fall back to defaults and flash the wrong brand.
     *
     * Best-effort: an unwritable cache directory costs first-paint branding,
     * not correctness, so it logs and returns FALSE rather than failing a
     * settings save.
     */
    public function refresh_public_cache(array $cfg = NULL)
    {
        $cfg  = $cfg ?: $this->public_config();
        $json = json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === FALSE) return FALSE;

        $path = self::cache_path();
        $tmp  = $path . '.' . getmypid() . '.tmp';

        if (@file_put_contents($tmp, $json, LOCK_EX) === FALSE) {
            log_message('error', '[Store_model] cannot write ' . $tmp . ' — shell branding will use defaults.');
            return FALSE;
        }
        if ( ! @rename($tmp, $path)) {
            /* Windows refuses rename() over an existing file in some PHP builds. */
            @unlink($path);
            if ( ! @rename($tmp, $path)) {
                @unlink($tmp);
                log_message('error', '[Store_model] cannot move ' . $tmp . ' into place.');
                return FALSE;
            }
        }
        return TRUE;
    }

    /** Make sure a cache exists (first request after install writes it). */
    public function ensure_cache(array $cfg = NULL)
    {
        if ( ! is_file(self::cache_path())) return $this->refresh_public_cache($cfg);
        return TRUE;
    }
}
