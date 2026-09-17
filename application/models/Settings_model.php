<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Settings_model.php — admin-editable configuration (gp_settings)
 *
 * GenericPOS Accounting
 *
 * config/app.php holds the DEFAULTS; this table holds an administrator's
 * OVERRIDES; get() merges them, database winning. The SettingsOverride hook
 * pushes the overrides into CI's config at the start of every database-using
 * request, so ordinary config->item() calls see them.
 *
 * ─── THE REGISTRY IS THE ALLOW-LIST ───────────────────────────────────────
 * A key is editable only if it appears in registry(). A new config key is
 * invisible until somebody deliberately lists it.
 *
 * ─── NEVER EDITABLE ───────────────────────────────────────────────────────
 *   · currency_decimals — changing it after the first posting re-denominates
 *     every stored amount.
 *   · SECRETS — a settings screen is a read surface, and a key in a database
 *     is a key in every backup of it.
 *   · entity_type, business_form, fiscal_year_start_month are READONLY: setup
 *     writes them (with $force); the screen shows them and cannot change them.
 *
 * ─── TYPES ────────────────────────────────────────────────────────────────
 *   string · text (multi-line) · int · float · bool · enum · color (#rrggbb)
 *   money (stored as integer CENTS, typed in major units) · email · list
 *   (comma-separated) · account (an account CODE; must be an active postable
 *   account, optionally of given types / control)
 */
class Settings_model extends CI_Model
{
    const T = 'gp_settings';

    protected $cache = NULL;

    const FORBIDDEN = [
        'currency_decimals', 'jwt_', 'vapid_private', 'encryption_key', 'db_',
        'mail_smtp_pass', 'setup_',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->config->load('app', FALSE, TRUE);
    }

    // =========================================================================
    // THE REGISTRY
    // =========================================================================

    public function registry()
    {
        $fonts = ['system' => 'System default', 'inter' => 'Inter', 'poppins' => 'Poppins', 'nunito' => 'Nunito'];
        $months = [];
        for ($m = 1; $m <= 12; $m++) $months[(string) $m] = date('F', mktime(0, 0, 0, $m, 1, 2000));

        $book = function ($label) { return ['type' => 'string', 'group' => 'ledger', 'max_len' => 6, 'pattern' => '/^[A-Z0-9]{1,6}$/', 'label' => $label . ' prefix', 'hint' => '']; };
        $acct = function ($label, $hint, array $types = [], $control = NULL, $group = 'accounts') {
            return ['type' => 'account', 'group' => $group, 'label' => $label, 'hint' => $hint, 'account_types' => $types, 'control' => $control];
        };
        $pct = function ($label, $hint, $max = 100) { return ['type' => 'float', 'group' => 'coop', 'min' => 0, 'max' => $max, 'label' => $label, 'hint' => $hint]; };

        return [
            // ── Company ───────────────────────────────────────────────────
            'store_name'       => ['type' => 'string', 'group' => 'company', 'max_len' => 120, 'label' => 'Company name', 'hint' => 'Printed at the top of every report.'],
            'store_legal_name' => ['type' => 'string', 'group' => 'company', 'max_len' => 160, 'label' => 'Registered name', 'hint' => 'As registered with the SEC, DTI or CDA, when different.'],
            'store_tagline'    => ['type' => 'string', 'group' => 'company', 'max_len' => 160, 'label' => 'Business style', 'hint' => 'Shown under the name on the letterhead.'],
            'store_tin'        => ['type' => 'string', 'group' => 'company', 'max_len' => 40,  'label' => 'TIN', 'hint' => 'Printed on every report.'],
            'coop_cda_reg_no'  => ['type' => 'string', 'group' => 'company', 'max_len' => 40,  'label' => 'CDA registration number', 'hint' => 'Co-operatives only.'],
            'store_address'    => ['type' => 'text',   'group' => 'company', 'max_len' => 500, 'label' => 'Address', 'hint' => 'Printed on the letterhead.'],
            'store_email'      => ['type' => 'email',  'group' => 'company', 'label' => 'Email', 'hint' => 'Also the reply-to address on system email.'],
            'store_phone'      => ['type' => 'string', 'group' => 'company', 'max_len' => 40,  'label' => 'Phone', 'hint' => ''],
            'entity_type'      => ['type' => 'enum',   'group' => 'company', 'readonly' => TRUE, 'options' => ['business' => 'Business', 'cooperative' => 'Co-operative'], 'label' => 'Kind of organisation', 'hint' => 'Chosen at setup; it decided the chart and the statements.'],
            'business_form'    => ['type' => 'enum',   'group' => 'company', 'readonly' => TRUE, 'options' => ['corporation' => 'Corporation', 'sole_proprietorship' => 'Sole proprietorship'], 'label' => 'Business form', 'hint' => 'Chosen at setup.'],
            'fiscal_year_start_month' => ['type' => 'enum', 'group' => 'company', 'readonly' => TRUE, 'options' => $months, 'label' => 'Fiscal year starts in', 'hint' => 'Chosen at setup.'],

            // ── Branding ──────────────────────────────────────────────────
            'brand_primary' => ['type' => 'color', 'group' => 'branding', 'label' => 'Brand colour', 'hint' => 'Buttons, links and highlights.'],
            'brand_accent'  => ['type' => 'color', 'group' => 'branding', 'label' => 'Accent colour', 'hint' => ''],
            'theme_mode'    => ['type' => 'enum',  'group' => 'branding', 'options' => ['auto' => 'Follow the device', 'light' => 'Always light', 'dark' => 'Always dark'], 'label' => 'Light / dark', 'hint' => 'Printed reports are always light.'],
            'ui_radius'     => ['type' => 'int',   'group' => 'branding', 'min' => 0, 'max' => 24, 'label' => 'Corner roundness (px)', 'hint' => ''],
            'font_family'   => ['type' => 'enum',  'group' => 'branding', 'options' => $fonts, 'label' => 'Typeface', 'hint' => 'Web fonts load from Google Fonts; "System default" loads nothing.'],
            'store_logo'    => ['type' => 'string', 'group' => 'branding', 'max_len' => 255, 'label' => 'Logo', 'hint' => 'Upload with the button above. Printed on the letterhead.', 'upload' => 'logo'],

            // ── Currency ──────────────────────────────────────────────────
            'currency_code'            => ['type' => 'string', 'group' => 'currency', 'max_len' => 3, 'pattern' => '/^[A-Z]{3}$/', 'label' => 'Currency code', 'hint' => 'ISO 4217, e.g. PHP. Display only — decimals are fixed at install.'],
            'currency_symbol'          => ['type' => 'string', 'group' => 'currency', 'max_len' => 6, 'label' => 'Currency symbol', 'hint' => ''],
            'currency_symbol_position' => ['type' => 'enum', 'group' => 'currency', 'options' => ['before' => 'Before the amount (₱100)', 'after' => 'After the amount (100 kr)'], 'label' => 'Symbol position', 'hint' => ''],
            'currency_thousands_sep'   => ['type' => 'enum', 'group' => 'currency', 'options' => [',' => 'Comma (1,000)', '.' => 'Period (1.000)', ' ' => 'Space (1 000)', '' => 'None (1000)'], 'label' => 'Thousands separator', 'hint' => ''],
            'currency_decimal_sep'     => ['type' => 'enum', 'group' => 'currency', 'options' => ['.' => 'Period (0.50)', ',' => 'Comma (0,50)'], 'label' => 'Decimal separator', 'hint' => ''],
            'locale'                   => ['type' => 'string', 'group' => 'currency', 'max_len' => 12, 'label' => 'Locale', 'hint' => 'For dates, e.g. en-PH.'],
            'display_timezone'         => ['type' => 'string', 'group' => 'currency', 'max_len' => 40, 'label' => 'Time zone', 'hint' => 'For times, e.g. Asia/Manila. Timestamps are stored in UTC.'],

            // ── Tax ───────────────────────────────────────────────────────
            'vat_registered'     => ['type' => 'bool',   'group' => 'tax', 'label' => 'VAT-registered', 'hint' => 'Invoices and bills split VAT to the output and input VAT accounts.'],
            'tax_label'          => ['type' => 'string', 'group' => 'tax', 'max_len' => 12, 'label' => 'Tax name', 'hint' => ''],
            'tax_rate_pct'       => ['type' => 'float',  'group' => 'tax', 'min' => 0, 'max' => 50, 'label' => 'VAT rate (%)', 'hint' => '12 in the Philippines.'],
            'prices_include_tax' => ['type' => 'bool',   'group' => 'tax', 'label' => 'Amounts include VAT', 'hint' => 'How a typed invoice or bill line amount is read by default.'],
            'tax_id_label'       => ['type' => 'string', 'group' => 'tax', 'max_len' => 12, 'label' => 'Tax ID label', 'hint' => ''],

            // ── The ledger ────────────────────────────────────────────────
            'ledger_require_approval'    => ['type' => 'bool', 'group' => 'ledger', 'label' => 'A second person approves every entry', 'hint' => 'On: an accountant or administrator approves each entry, invoice, bill, receipt and payment, and never one they prepared. Off: they may approve their own. A bookkeeper\'s work always waits for an accountant.'],
            'ledger_allow_self_approval' => ['type' => 'bool', 'group' => 'ledger', 'label' => 'Preparers may approve their own entries', 'hint' => 'Lets an accountant or administrator approve what they prepared even while the setting above is on. For a one-person office only — it switches off maker-checker.'],
            'ledger_number_digits'       => ['type' => 'int',  'group' => 'ledger', 'min' => 3, 'max' => 8, 'label' => 'Digits in journal numbers', 'hint' => 'CD-2026-00042 has five.'],
            'ledger_max_lines'           => ['type' => 'int',  'group' => 'ledger', 'min' => 2, 'max' => 2000, 'label' => 'Most lines in one entry', 'hint' => ''],
            'book_prefix_general'            => $book('General journal'),
            'book_prefix_cash_receipts'      => $book('Cash receipts'),
            'book_prefix_cash_disbursements' => $book('Cash disbursements'),
            'book_prefix_sales'              => $book('Sales journal'),
            'book_prefix_purchases'          => $book('Purchase journal'),
            'book_prefix_adjusting'          => $book('Adjusting entries'),
            'book_prefix_closing'            => $book('Closing entries'),
            'book_prefix_opening'            => $book('Opening balances'),

            // ── Account defaults ──────────────────────────────────────────
            'acct_retained_earnings' => $acct('Closing account', 'Where net income goes at year end: retained earnings, owner\'s capital, or a co-op\'s undivided net surplus.', ['equity']),
            'acct_drawings'          => $acct('Owner\'s drawings', 'Sole proprietorship: closed into capital at year end.', ['equity']),
            'acct_ar_control'        => $acct('Receivables control', 'Customer invoices and receipts post here.', ['asset'], 'ar'),
            'acct_ap_control'        => $acct('Payables control', 'Supplier bills and payments post here.', ['liability'], 'ap'),
            'acct_output_vat'        => $acct('Output VAT', 'VAT on sales.', ['liability']),
            'acct_input_vat'         => $acct('Input VAT', 'VAT on purchases.', ['asset']),
            'acct_ewt_payable'       => $acct('Withholding tax payable (expanded)', 'Tax withheld from supplier payments.', ['liability']),
            'acct_cwt_receivable'    => $acct('Creditable withholding tax', 'Tax customers withheld from their payments.', ['asset']),
            'acct_default_sales'     => $acct('Default sales account', 'Suggested on new invoice lines.', ['income']),
            'acct_default_purchases' => $acct('Default purchases account', 'Suggested on new bill lines.', ['asset', 'expense']),
            'acct_bank_charges'      => $acct('Bank charges', 'For charges found while reconciling.', ['expense']),
            'acct_interest_income'   => $acct('Interest income', 'For interest found while reconciling.', ['income']),
            'acct_gain_on_disposal'  => $acct('Gain on disposal', 'Fixed assets sold above book value.', ['income']),
            'acct_loss_on_disposal'  => $acct('Loss on disposal', 'Fixed assets sold below book value.', ['expense']),

            // ── Documents ─────────────────────────────────────────────────
            'doc_prefix_invoice'     => ['type' => 'string', 'group' => 'documents', 'max_len' => 6, 'pattern' => '/^[A-Z0-9]{1,6}$/', 'label' => 'Invoice prefix', 'hint' => ''],
            'doc_prefix_credit_note' => ['type' => 'string', 'group' => 'documents', 'max_len' => 6, 'pattern' => '/^[A-Z0-9]{1,6}$/', 'label' => 'Credit note prefix', 'hint' => ''],
            'doc_prefix_bill'        => ['type' => 'string', 'group' => 'documents', 'max_len' => 6, 'pattern' => '/^[A-Z0-9]{1,6}$/', 'label' => 'Bill prefix', 'hint' => ''],
            'doc_prefix_debit_note'  => ['type' => 'string', 'group' => 'documents', 'max_len' => 6, 'pattern' => '/^[A-Z0-9]{1,6}$/', 'label' => 'Debit note prefix', 'hint' => ''],
            'doc_prefix_receipt'     => ['type' => 'string', 'group' => 'documents', 'max_len' => 6, 'pattern' => '/^[A-Z0-9]{1,6}$/', 'label' => 'Receipt prefix', 'hint' => ''],
            'doc_prefix_payment'     => ['type' => 'string', 'group' => 'documents', 'max_len' => 6, 'pattern' => '/^[A-Z0-9]{1,6}$/', 'label' => 'Payment voucher prefix', 'hint' => ''],
            'doc_number_digits'      => ['type' => 'int',  'group' => 'documents', 'min' => 3, 'max' => 10, 'label' => 'Digits in document numbers', 'hint' => ''],
            'ar_default_terms_days'  => ['type' => 'int',  'group' => 'documents', 'min' => 0, 'max' => 365, 'label' => 'Default customer terms (days)', 'hint' => ''],
            'ap_default_terms_days'  => ['type' => 'int',  'group' => 'documents', 'min' => 0, 'max' => 365, 'label' => 'Default supplier terms (days)', 'hint' => ''],
            'aging_buckets'          => ['type' => 'list', 'group' => 'documents', 'max_len' => 40, 'label' => 'Aging buckets (days)', 'hint' => 'Ascending, comma-separated, e.g. 30,60,90.'],

            // ── Banking & fixed assets ────────────────────────────────────
            'bank_match_window_days' => ['type' => 'int',  'group' => 'banking', 'min' => 0, 'max' => 60, 'label' => 'Match bank lines within (days)', 'hint' => ''],
            'fa_depreciation_start'  => ['type' => 'enum', 'group' => 'banking', 'options' => ['next_month' => 'The month after acquisition', 'same_month' => 'The month of acquisition'], 'label' => 'Depreciation starts', 'hint' => ''],
            'fa_asset_prefix'        => ['type' => 'string', 'group' => 'banking', 'max_len' => 6, 'pattern' => '/^[A-Z0-9]{1,6}$/', 'label' => 'Asset number prefix', 'hint' => ''],

            // ── Co-operative ──────────────────────────────────────────────
            'coop_reserve_fund_pct'  => $pct('Reserve fund (%)', 'Of net surplus. Check the by-laws.'),
            'coop_cetf_pct'          => $pct('Education and training fund (%)', 'Of net surplus.'),
            'coop_cdf_pct'           => $pct('Community development fund (%)', 'Of net surplus.'),
            'coop_optional_fund_pct' => $pct('Optional fund (%)', 'Of net surplus.'),
            'coop_isc_pct'           => $pct('Interest on share capital (% of the remainder)', 'The patronage refund takes the rest.'),
            'coop_bench_par_max'           => $pct('Portfolio at risk — at most (%)', 'The share of the loan portfolio past due that the co-operative will accept. CDA guidance: 5 % or less.'),
            'coop_bench_allowance_min'     => $pct('Allowance cover — at least (%)', 'Allowance for probable losses against loans past due. CDA guidance: 35 % of loans 1 to 12 months past due, 100 % of older ones.', 500),
            'coop_bench_share_capital_min' => $pct('Share capital to total assets — at least (%)', 'CDA guidance: between 35 % and 45 %.'),
            'coop_bench_statutory_min'     => $pct('Statutory funds to total assets — at least (%)', 'The reserve, education and training, community development and optional funds together.'),
            'coop_bench_cost_max'          => $pct('Operating cost ratio — at most (%)', 'Operating and financing costs against revenue.'),
            'acct_coop_reserve_fund'      => $acct('Reserve fund', '', ['equity'], NULL, 'coop'),
            'acct_coop_cetf'              => $acct('Education and training fund', '', ['equity'], NULL, 'coop'),
            'acct_coop_cdf'               => $acct('Community development fund', '', ['equity'], NULL, 'coop'),
            'acct_coop_optional_fund'     => $acct('Optional fund', '', ['equity'], NULL, 'coop'),
            'acct_coop_isc_payable'       => $acct('Interest on share capital payable', '', ['liability'], NULL, 'coop'),
            'acct_coop_patronage_payable' => $acct('Patronage refund payable', '', ['liability'], NULL, 'coop'),

            // ── Printed reports ───────────────────────────────────────────
            'report_rows_per_page'   => ['type' => 'int',    'group' => 'reports', 'min' => 10, 'max' => 80, 'label' => 'Rows per printed page (books)', 'hint' => 'The books print with page totals and "brought forward".'],
            'report_show_printed_by' => ['type' => 'bool',   'group' => 'reports', 'label' => 'Print "printed by" and the time', 'hint' => ''],
            'report_note'            => ['type' => 'string', 'group' => 'reports', 'max_len' => 120, 'label' => 'Note under report titles', 'hint' => 'e.g. "Unaudited". Empty prints nothing.'],
            'sign_checked_by'        => ['type' => 'string', 'group' => 'reports', 'max_len' => 80, 'label' => 'Checked by — name', 'hint' => ''],
            'sign_checked_title'     => ['type' => 'string', 'group' => 'reports', 'max_len' => 80, 'label' => 'Checked by — title', 'hint' => ''],
            'sign_approved_by'       => ['type' => 'string', 'group' => 'reports', 'max_len' => 80, 'label' => 'Approved by — name', 'hint' => ''],
            'sign_approved_title'    => ['type' => 'string', 'group' => 'reports', 'max_len' => 80, 'label' => 'Approved by — title', 'hint' => ''],
            'sign_noted_by'          => ['type' => 'string', 'group' => 'reports', 'max_len' => 80, 'label' => 'Noted by — name', 'hint' => 'e.g. the chairperson of a co-op.'],
            'sign_noted_title'       => ['type' => 'string', 'group' => 'reports', 'max_len' => 80, 'label' => 'Noted by — title', 'hint' => ''],

            // ── Notifications ─────────────────────────────────────────────
            'notify_email_enabled' => ['type' => 'bool',   'group' => 'notifications', 'label' => 'Email copies of notifications', 'hint' => 'Only ever sent to confirmed addresses.'],
            'notify_approvals'     => ['type' => 'bool',   'group' => 'notifications', 'label' => 'Tell approvers about submitted entries', 'hint' => 'And tell preparers when an entry is rejected.'],
            'mail_from_name'       => ['type' => 'string', 'group' => 'notifications', 'max_len' => 80, 'label' => 'Email sender name', 'hint' => 'Empty = the company name.'],

            // ── Security ──────────────────────────────────────────────────
            'login_max_attempts'            => ['type' => 'int', 'group' => 'security', 'min' => 3, 'max' => 20, 'label' => 'Failed sign-ins before lockout', 'hint' => 'Counted per account AND per IP.'],
            'login_lockout_s'               => ['type' => 'int', 'group' => 'security', 'min' => 60, 'max' => 86400, 'label' => 'Lockout (seconds)', 'hint' => ''],
            'password_min_length'           => ['type' => 'int', 'group' => 'security', 'min' => 8, 'max' => 64, 'label' => 'Minimum password length', 'hint' => ''],
            'username_change_cooldown_days' => ['type' => 'int', 'group' => 'security', 'min' => 0, 'max' => 365, 'label' => 'Username change cooldown (days)', 'hint' => ''],

            // ── Features ──────────────────────────────────────────────────
            'safe_mode' => ['type' => 'bool', 'group' => 'features', 'label' => 'Safe mode', 'hint' => 'Expensive endpoints degrade instead of erroring.'],
        ];
    }

    public function groups()
    {
        return [
            'company'       => ['label' => 'Company',          'icon' => 'buildings'],
            'branding'      => ['label' => 'Branding',         'icon' => 'palette'],
            'currency'      => ['label' => 'Currency',         'icon' => 'coins'],
            'tax'           => ['label' => 'Tax',              'icon' => 'percent'],
            'ledger'        => ['label' => 'Ledger',           'icon' => 'book-open'],
            'accounts'      => ['label' => 'Account defaults', 'icon' => 'list-numbers'],
            'documents'     => ['label' => 'Invoices & bills', 'icon' => 'receipt'],
            'banking'       => ['label' => 'Banking & assets', 'icon' => 'bank'],
            'coop'          => ['label' => 'Co-operative',     'icon' => 'users-three'],
            'reports'       => ['label' => 'Printed reports',  'icon' => 'printer'],
            'notifications' => ['label' => 'Notifications',    'icon' => 'bell'],
            'security'      => ['label' => 'Security',         'icon' => 'shield-check'],
            'features'      => ['label' => 'Features',         'icon' => 'gear'],
        ];
    }

    // =========================================================================
    // READ
    // =========================================================================

    protected function overrides()
    {
        if ($this->cache !== NULL) return $this->cache;
        $this->cache = [];
        foreach ($this->db->get(self::T)->result_array() as $r) {
            $this->cache[$r['k']] = $this->cast($r['value'], $r['value_type']);
        }
        return $this->cache;
    }

    public function cast($value, $type)
    {
        switch ($type) {
            case 'int':   return (int) $value;
            case 'float': return (float) $value;
            case 'bool':  return ! in_array(strtolower((string) $value), ['0', '', 'false', 'no'], TRUE);
            case 'json':  $d = json_decode((string) $value, TRUE); return is_array($d) ? $d : [];
            default:      return (string) $value;
        }
    }

    /** Storage type for a registry type. */
    protected function _storage_type($type)
    {
        switch ($type) {
            case 'int': case 'money': return 'int';
            case 'float':             return 'float';
            case 'bool':              return 'bool';
            default:                  return 'string';
        }
    }

    /** Effective value — override if one exists, else the config-FILE default. */
    public function get($key, $default = NULL)
    {
        $o = $this->overrides();
        if (array_key_exists($key, $o)) return $o[$key];
        $cfg = $this->default_for($key);
        return ($cfg === NULL) ? $default : $cfg;
    }

    protected static $file_defaults = NULL;

    /**
     * The config-FILE default, ignoring overrides. Read from the file in an
     * isolated scope, NOT from $this->config — by now the SettingsOverride hook
     * has already written the overrides into CI's config.
     */
    public function default_for($key)
    {
        if (self::$file_defaults === NULL) {
            $config = [];
            $path   = APPPATH . 'config/app.php';
            if (is_file($path)) include $path;
            self::$file_defaults = is_array($config) ? $config : [];
        }
        return array_key_exists($key, self::$file_defaults) ? self::$file_defaults[$key] : NULL;
    }

    /** The full editable set, grouped for the settings screen. */
    public function all_for_admin()
    {
        $o   = $this->overrides();
        $out = [];

        foreach ($this->registry() as $key => $meta) {
            $st         = $this->_storage_type($meta['type']);
            $default    = $this->cast($this->default_for($key), $st);
            $overridden = array_key_exists($key, $o);

            $out[] = [
                'key'        => $key,
                'group'      => $meta['group'],
                'label'      => $meta['label'],
                'hint'       => $meta['hint'],
                'type'       => $meta['type'],
                'min'        => $meta['min'] ?? NULL,
                'max'        => $meta['max'] ?? NULL,
                'max_len'    => $meta['max_len'] ?? NULL,
                'options'    => $meta['options'] ?? NULL,
                'upload'     => $meta['upload'] ?? NULL,
                'readonly'   => ! empty($meta['readonly']),
                'account_types' => $meta['account_types'] ?? NULL,
                'value'      => $overridden ? $o[$key] : $default,
                'default'    => $default,
                'overridden' => $overridden,
            ];
        }
        return $out;
    }

    // =========================================================================
    // WRITE
    // =========================================================================

    public function is_forbidden($key)
    {
        $k = strtolower((string) $key);
        foreach (self::FORBIDDEN as $bad) {
            if (strpos($k, $bad) === 0) return TRUE;
        }
        return FALSE;
    }

    /**
     * Validate and store one override.
     *
     * @param mixed $raw    what the form sent; money in MAJOR units ("1,500.00")
     * @param bool  $force  setup and the seed may write READONLY keys
     * @return array ['ok', 'error', 'from', 'to']
     */
    public function set($key, $raw, $admin_id = NULL, $force = FALSE)
    {
        $registry = $this->registry();

        if ( ! isset($registry[$key])) return $this->_err('Unknown setting.', NULL);

        if ($this->is_forbidden($key)) {
            log_message('error', '[Settings] registry lists forbidden key "' . $key . '"; refusing to write it.');
            return $this->_err('That setting cannot be changed here.', NULL);
        }

        $meta = $registry[$key];
        if ( ! empty($meta['readonly']) && ! $force) return $this->_err('This was chosen at setup and cannot be changed.', $this->get($key));

        $from  = $this->get($key);
        $raw_s = is_bool($raw) ? ($raw ? '1' : '0') : trim((string) $raw);

        switch ($meta['type']) {
            case 'int':
                if ( ! preg_match('/^-?\d+$/', $raw_s)) return $this->_err('Enter a whole number.', $from);
                $to = (int) $raw_s;
                break;

            case 'float':
                if ( ! preg_match('/^-?\d+(\.\d+)?$/', $raw_s)) return $this->_err('Enter a number.', $from);
                $to = (float) $raw_s;
                break;

            case 'money':
                $c = money_cents($raw_s);
                if ($c === NULL) return $this->_err('Enter an amount, e.g. 1500 or 1500.50.', $from);
                $to = $c;
                break;

            case 'bool':
                $to = ! in_array(strtolower($raw_s), ['0', '', 'false', 'no', 'off'], TRUE);
                break;

            case 'color':
                if ( ! preg_match('/^#[0-9a-fA-F]{6}$/', $raw_s)) return $this->_err('Enter a colour like #1d4ed8.', $from);
                $to = strtolower($raw_s);
                break;

            case 'email':
                if ($raw_s !== '' && ! filter_var($raw_s, FILTER_VALIDATE_EMAIL)) return $this->_err('Enter a valid email address.', $from);
                $to = mb_strtolower($raw_s);
                break;

            case 'list':
                $parts = array_filter(array_map('trim', explode(',', $raw_s)), 'strlen');
                $to    = implode(',', $parts);
                if (mb_strlen($to) > (int) ($meta['max_len'] ?? 500)) return $this->_err('That list is too long.', $from);
                break;

            case 'enum':
                $opts = (array) ($meta['options'] ?? []);
                if ( ! array_key_exists($raw_s, $opts)) return $this->_err('Choose one of the listed options.', $from);
                $to = $raw_s;
                break;

            case 'account':
                $to = $raw_s;
                if ($to !== '' && ($why = $this->_account_problem($to, $meta)) !== '') return $this->_err($why, $from);
                break;

            case 'text':
                $to = function_exists('clean_text') ? clean_text($raw, 100000) : $raw_s;
                if (mb_strlen($to) > (int) ($meta['max_len'] ?? 5000)) return $this->_err('That text is too long.', $from);
                break;

            default: // string
                $to = function_exists('clean_line') ? clean_line($raw, 100000) : $raw_s;
                if (mb_strlen($to) > (int) ($meta['max_len'] ?? 2000)) return $this->_err('That value is too long.', $from);
                if (isset($meta['pattern']) && $to !== '' && ! preg_match($meta['pattern'], $to)) return $this->_err('That value is not in the expected format.', $from);
        }

        /* Number prefixes: never blank, never shared. Two books with one prefix
           would share one counter ("CA" for both cash books), and two document
           types with one prefix would print the same number twice. */
        if (($why = $this->_prefix_problem($key, $to, $registry)) !== '') return $this->_err($why, $from);

        /* Bounds on the SERVER — a form's min/max attributes do not cover a
           direct API call. */
        if (in_array($meta['type'], ['int', 'float', 'money'], TRUE)) {
            if (isset($meta['min']) && $to < $meta['min']) return $this->_err('Minimum is ' . $meta['min'] . '.', $from);
            if (isset($meta['max']) && $to > $meta['max']) return $this->_err('Maximum is ' . $meta['max'] . '.', $from);
        }

        $st      = $this->_storage_type($meta['type']);
        $default = $this->cast($this->default_for($key), $st);

        /* A value equal to the FILE DEFAULT deletes the override, so the config
           file keeps governing everything nobody changed. */
        if ($to === $default) {
            $this->db->where('k', $key)->delete(self::T);
        } else {
            if ($st === 'bool')       $stored = $to ? '1' : '0';
            elseif ($st === 'float')  { $stored = rtrim(rtrim(number_format((float) $to, 12, '.', ''), '0'), '.'); if ($stored === '' || $stored === '-') $stored = '0'; }
            else                      $stored = (string) $to;

            $this->db->query(
                'INSERT INTO ' . self::T . ' (k, value, value_type, group_key, label, hint, min_value, max_value, updated_by, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE value = VALUES(value), value_type = VALUES(value_type), group_key = VALUES(group_key),
                    label = VALUES(label), hint = VALUES(hint), min_value = VALUES(min_value), max_value = VALUES(max_value),
                    updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
                [
                    $key, $stored, $st, $meta['group'], $meta['label'], mb_substr((string) $meta['hint'], 0, 500),
                    isset($meta['min']) && is_numeric($meta['min']) ? $meta['min'] : NULL,
                    isset($meta['max']) && is_numeric($meta['max']) ? $meta['max'] : NULL,
                    $admin_id !== NULL ? (int) $admin_id : NULL,
                    date('Y-m-d H:i:s'),
                ]
            );
        }

        $this->cache = NULL;

        /* This request's config already went through the override hook; push
           the new value so anything read later in the SAME request sees it. */
        $this->config->set_item($key, $to);

        return ['ok' => TRUE, 'error' => '', 'from' => $from, 'to' => $to];
    }

    /** '' unless $key is a number prefix and $to is blank or already used by its family. */
    protected function _prefix_problem($key, $to, array $registry)
    {
        foreach (['book_prefix_', 'doc_prefix_'] as $family) {
            if (strpos($key, $family) !== 0) continue;
            if ((string) $to === '') return 'Enter a prefix. Every ' . ($family === 'book_prefix_' ? 'book' : 'kind of document') . ' needs its own.';
            foreach ($registry as $k => $m) {
                if ($k === $key || strpos($k, $family) !== 0) continue;
                if (strtoupper((string) $this->get($k)) === strtoupper((string) $to)) {
                    return $to . ' is already the ' . lcfirst(preg_replace('/ prefix$/', '', $m['label'])) . ' prefix. Choose another, so their numbers stay apart.';
                }
            }
        }
        if ($key === 'fa_asset_prefix' && (string) $to === '') return 'Enter a prefix for asset numbers.';
        return '';
    }

    /** '' when $code names an active postable account that fits $meta; otherwise why not. */
    protected function _account_problem($code, array $meta)
    {
        $a = $this->db->get_where('gp_accounts', ['code' => $code], 1)->row_array();
        if ( ! $a)                        return 'No account has the code ' . $code . '.';
        if ((int) $a['is_header'])        return $code . ' is a header account.';
        if ( ! (int) $a['is_active'])     return $code . ' is inactive.';
        if ( ! empty($meta['account_types']) && ! in_array($a['type'], $meta['account_types'], TRUE)) {
            return $code . ' is ' . $a['type'] . '; this needs ' . implode(' or ', $meta['account_types']) . '.';
        }
        if ( ! empty($meta['control']) && $a['control'] !== $meta['control']) {
            return $code . ' is not a ' . ($meta['control'] === 'ar' ? 'receivables' : 'payables') . ' control account.';
        }
        return '';
    }

    /**
     * Save several keys, reporting per key, and refresh the shell's branding
     * cache ONCE at the end.
     *
     * @return array ['saved' => [key => [from, to]], 'errors' => [key => message]]
     */
    public function set_many(array $values, $admin_id = NULL, $force = FALSE)
    {
        $saved = []; $errors = [];
        foreach ($values as $k => $v) {
            $r = $this->set($k, $v, $admin_id, $force);
            if ($r['ok']) {
                if ($r['from'] !== $r['to']) $saved[$k] = ['from' => $r['from'], 'to' => $r['to']];
            } else {
                $errors[$k] = $r['error'];
            }
        }
        $this->refresh_store_cache();
        return ['saved' => $saved, 'errors' => $errors];
    }

    public function reset($key)
    {
        $reg = $this->registry();
        if ( ! isset($reg[$key]))          return $this->_err('Unknown setting.', NULL);
        if ( ! empty($reg[$key]['readonly'])) return $this->_err('This was chosen at setup and cannot be changed.', $this->get($key));

        $from = $this->get($key);
        $this->db->where('k', $key)->delete(self::T);
        $this->cache = NULL;

        $to = $this->cast($this->default_for($key), $this->_storage_type($reg[$key]['type']));
        $this->config->set_item($key, $to);
        $this->refresh_store_cache();

        return ['ok' => TRUE, 'error' => '', 'from' => $from, 'to' => $to];
    }

    public function refresh_store_cache()
    {
        try {
            $this->load->model('Store_model', 'store_model');
            $this->store_model->refresh_public_cache();
        } catch (Throwable $e) {
            log_message('error', '[Settings] store cache refresh failed: ' . $e->getMessage());
        }
    }

    private function _err($msg, $from)
    {
        return ['ok' => FALSE, 'error' => $msg, 'from' => $from, 'to' => NULL];
    }
}
