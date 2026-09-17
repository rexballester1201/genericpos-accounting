<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * app.php — GenericPOS Accounting configuration (the DEFAULTS)
 *
 * GenericPOS Accounting · a standalone general ledger
 * Stack: CodeIgniter 3.1.9 · SPA shell + PWA · JSON API at /api/v1
 *
 * ─── HOW THIS FILE IS LOADED ──────────────────────────────────────────────
 * Not autoloaded. Every consumer loads it explicitly and UNINDEXED:
 *
 *     $this->config->load('app', FALSE, TRUE);
 *     $this->config->item('tax_rate_pct');          // ONE argument
 *
 * NOTE: CI3's second argument to config->item() is the config FILE INDEX, not
 * a default. `config->item('key', 'app')` against an unindexed load returns
 * NULL silently and the caller's fallback wins instead of the value set here.
 *
 * ─── DEFAULTS HERE, OVERRIDES IN THE DATABASE ─────────────────────────────
 * Keys listed in Settings_model::registry() are editable from Settings. An
 * edit is stored in gp_settings and pushed over the value below by the
 * SettingsOverride hook on every database-using request. For any key in the
 * registry THE SETTINGS SCREEN IS THE SOURCE OF TRUTH, NOT THIS FILE.
 *
 * ─── THE `?:` TRAP ────────────────────────────────────────────────────────
 * Several keys legitimately take 0 or FALSE. Read them with an explicit
 * NULL/'' check, never `$x ?: default`.
 *
 * NOTE: THE COMPANY PROFILE KEEPS GENERICPOS'S KEY NAMES (store_name,
 * store_tin, store_logo, …). The screens call it "Company"; the keys were
 * kept so the mailer, the shell and the settings hook need no changes.
 *
 * ─── SECTIONS ─────────────────────────────────────────────────────────────
 *   A  Identity, environment & feature flags
 *   B  Authentication & sessions
 *   D  Rate limits
 *   S  Company profile & branding
 *   T  Currency & tax
 *   L  The ledger: approval, numbering, books
 *   G  Account defaults (codes, filled in by the chart template at setup)
 *   V  Receivables, payables, banking and fixed assets
 *   Q  Co-operative (net-surplus allocation)
 *   P  Printed reports
 *   M  Mail
 *   F  Notifications
 *   H  Cache TTLs (mirrored in sw.js)
 *   R  Retention & cron
 *   U  Uploads
 *   X  Debug switches
 */


// =============================================================================
// A — IDENTITY, ENVIRONMENT & FEATURE FLAGS
// =============================================================================

/** The SOFTWARE. The company's own name is store_name (Section S). */
$config['app_name']    = 'GenericPOS Accounting';
$config['app_version'] = '1.0.0';

/**
 * NOTE: NO SELF SIGN-UP. Every account is created by an administrator
 * (Settings → Users). A ledger has no public audience.
 */
$config['feature_registration'] = FALSE;

/** Safe mode: expensive endpoints degrade instead of erroring. */
$config['safe_mode'] = FALSE;

/**
 * ─── THE KIND OF ORGANISATION ─────────────────────────────────────────────
 * Chosen at setup, which seeds the matching chart of accounts. It decides the
 * statement titles and layouts, the closing account and (for a co-op) the
 * net-surplus allocation. NOT editable once anything has posted — the chart
 * and the closing entries depend on it.
 *
 *   entity_type    'business' | 'cooperative'
 *   business_form  'corporation' | 'sole_proprietorship'   (business only)
 */
$config['entity_type']   = 'business';
$config['business_form'] = 'corporation';

/** First month of the fiscal year (1 = January). Fixed once the first year exists. */
$config['fiscal_year_start_month'] = 1;


// =============================================================================
// B — AUTHENTICATION & SESSIONS
// =============================================================================

/**
 * Access-token lifetimes. Refresh tokens (30 days) renew them silently, so
 * these bound how long a STOLEN access token stays useful. Every account here
 * is staff; the customer lifetime is kept only for the shared token code.
 */
$config['jwt_expiry_customer_s'] = 43200;
$config['jwt_expiry_staff_s']    = 43200;    // 12 h

$config['refresh_token_expiry_s'] = 2592000; // 30 days

$config['password_min_length']    = 8;
$config['password_require_mixed'] = TRUE;    // at least one letter and one digit
$config['password_hash_algo']     = PASSWORD_BCRYPT;
$config['password_hash_cost']     = 12;

/**
 * Sign-in throttle, counted per ACCOUNT and per IP in separate rows.
 * NOTE: keep the lockout SHORT — an account lockout is itself a
 * denial-of-service button for anyone who knows the username.
 */
$config['login_max_attempts'] = 5;
$config['login_lockout_s']    = 900;

$config['email_verify_expiry_s']   = 86400;
$config['password_reset_expiry_s'] = 3600;
$config['password_change_notify']  = TRUE;

/** Days between username changes. 0 disables the cooldown. */
$config['username_change_cooldown_days'] = 30;

/** Prepended to national-format mobile numbers ("0917…" → "+63917…"). */
$config['default_country_code'] = '+63';

/**
 * First-run setup (/setup). Available only while NO administrator exists AND
 * the caller presents GP_SETUP_KEY from secrets.php. Empty key = disabled.
 */
$config['setup_enabled'] = TRUE;


// =============================================================================
// D — RATE LIMITS
// =============================================================================
/**
 * rate_limit($id, $action) reads 'rate_' . $action.
 *
 * NOTE: A MISSING KEY IS NO LIMIT, NOT A SMALL ONE. The helper fails OPEN on an
 * unknown action and logs one [WARN] line. Every action string passed to
 * rate_limit() anywhere in application/ must have an entry here.
 *
 * Per hour unless stated. Pre-auth actions key on a hashed IP (negative id).
 */
$config['rate_limit_window_s'] = 3600;

// Auth & account
$config['rate_password_reset']     = 3;     // per account (silently dropped, see Auth)
$config['rate_password_reset_ip']  = 12;    // per IP
$config['rate_username_check']     = 60;    // per IP
$config['rate_token_refresh']      = 120;   // per account
$config['rate_profile_update']     = 20;
$config['rate_password_change']    = 30;
$config['rate_avatar_upload']      = 12;
$config['rate_email_verify_send']  = 5;
$config['rate_setup']              = 10;    // per IP

// The ledger
$config['rate_admin_write']     = 5000;  // generic mutations, per user — month-end is busy
$config['rate_admin_upload']    = 600;
$config['rate_admin_mail_test'] = 6;
$config['rate_import']          = 60;    // CSV imports (chart, openings, journals, contacts, bank lines)
$config['rate_report_export']   = 1200;  // CSV downloads


// =============================================================================
// S — COMPANY PROFILE & BRANDING   (admin-editable)
// =============================================================================
$config['store_name']       = 'My Company';
$config['store_tagline']    = '';
$config['store_legal_name'] = '';
$config['store_email']      = '';
$config['store_phone']      = '';
$config['store_address']    = '';
$config['store_tin']        = '';           // TIN, printed on every report's letterhead

/** CDA registration number, printed on a co-operative's statements. */
$config['coop_cda_reg_no']  = '';

/** Uploaded through Settings → Branding; stored as a web path. */
$config['store_logo']       = '';

/** Brand colours become CSS custom properties; the app re-skins from two values. */
$config['brand_primary']    = '#1d4ed8';
$config['brand_accent']     = '#0f766e';
$config['theme_mode']       = 'auto';       // auto | light | dark
$config['ui_radius']        = 8;            // corner radius in px
$config['font_family']      = 'system';     // system | inter | poppins | nunito


// =============================================================================
// T — CURRENCY & TAX
// =============================================================================
/**
 * ─── THE UNIT OF ACCOUNT ──────────────────────────────────────────────────
 * Every money integer is MINOR UNITS of this currency (centavos) and every
 * column that holds one ends in _cents.
 *
 * NOTE: currency_decimals IS FIXED AT INSTALL. It is deliberately NOT in the
 * settings registry: changing it after the first posting would re-denominate
 * every stored amount by a factor of ten per digit, silently.
 */
$config['currency_code']            = 'PHP';
$config['currency_symbol']          = '₱';
$config['currency_decimals']        = 2;
$config['currency_symbol_position'] = 'before';   // before | after
$config['currency_thousands_sep']   = ',';
$config['currency_decimal_sep']     = '.';
$config['locale']                   = 'en-PH';
$config['display_timezone']         = 'Asia/Manila';   // DISPLAY only — storage is UTC

/**
 * VAT. A VAT-registered company splits invoices and bills into VATable
 * amount and VAT, posted to the output and input VAT accounts (Section G).
 * prices_include_tax decides what a typed line amount means by default.
 */
$config['vat_registered']     = TRUE;
$config['tax_enabled']        = TRUE;
$config['tax_label']          = 'VAT';
$config['tax_rate_pct']       = 12.0;
$config['prices_include_tax'] = TRUE;
$config['tax_id_label']       = 'TIN';


// =============================================================================
// L — THE LEDGER: APPROVAL, NUMBERING, BOOKS
// =============================================================================
/**
 * Maker-checker. With approval required, a bookkeeper's entry is a draft
 * until an accountant approves it, and only then does it post and take its
 * number. allow_self_approval lets the preparer approve their own entry —
 * for a one-person office, and nowhere else.
 */
$config['ledger_require_approval']    = TRUE;
$config['ledger_allow_self_approval'] = FALSE;

/**
 * Journal numbers: <prefix>-<fiscal year>-<zero-padded sequence>, gap-free
 * per book per fiscal year, assigned at posting. Prefixes are editable;
 * changing one affects entries posted afterwards only.
 */
$config['ledger_number_digits'] = 5;
$config['book_prefix_general']            = 'GJ';
$config['book_prefix_cash_receipts']      = 'CR';
$config['book_prefix_cash_disbursements'] = 'CD';
$config['book_prefix_sales']              = 'SJ';
$config['book_prefix_purchases']          = 'PJ';
$config['book_prefix_adjusting']          = 'AJ';
$config['book_prefix_closing']            = 'CJ';
$config['book_prefix_opening']            = 'OB';

/** Largest journal a screen or an import may create, in lines. */
$config['ledger_max_lines'] = 500;


// =============================================================================
// G — ACCOUNT DEFAULTS   (account CODES; the chart template fills them in)
// =============================================================================
/**
 * The accounts the modules post to by default. Stored as CODES so they read
 * the same in the settings screen and in an exported chart. An empty value
 * means the feature that needs it refuses to post until it is set, rather
 * than guessing.
 */
$config['acct_retained_earnings'] = '';   // closing target (a proprietor's capital; a co-op's undivided net surplus)
$config['acct_drawings']          = '';   // sole proprietorship: closed into capital at year end
$config['acct_ar_control']        = '';
$config['acct_ap_control']        = '';
$config['acct_output_vat']        = '';
$config['acct_input_vat']         = '';
$config['acct_ewt_payable']       = '';   // expanded withholding tax withheld from suppliers
$config['acct_cwt_receivable']    = '';   // creditable withholding tax withheld by customers
$config['acct_default_sales']     = '';
$config['acct_default_purchases'] = '';
$config['acct_bank_charges']      = '';
$config['acct_interest_income']   = '';
$config['acct_gain_on_disposal']  = '';
$config['acct_loss_on_disposal']  = '';


// =============================================================================
// V — RECEIVABLES, PAYABLES, BANKING AND FIXED ASSETS
// =============================================================================
/** Document numbers: <prefix>-<zero-padded sequence>, gap-free, assigned at posting. */
$config['doc_prefix_invoice']     = 'INV';
$config['doc_prefix_credit_note'] = 'CN';
$config['doc_prefix_bill']        = 'BL';
$config['doc_prefix_debit_note']  = 'DN';
$config['doc_prefix_receipt']     = 'RC';
$config['doc_prefix_payment']     = 'PV';
$config['doc_number_digits']      = 6;

$config['ar_default_terms_days'] = 30;
$config['ap_default_terms_days'] = 30;

/** Aging buckets in days, ascending; the last bucket is "over". */
$config['aging_buckets'] = '30,60,90';

/** Bank matching: a book line within this many days of a bank line may match it. */
$config['bank_match_window_days'] = 7;

/**
 * Depreciation starts in the month an asset is acquired ('same_month') or the
 * month after ('next_month').
 */
$config['fa_depreciation_start'] = 'next_month';
$config['fa_asset_prefix']       = 'FA';


// =============================================================================
// Q — CO-OPERATIVE: NET-SURPLUS ALLOCATION
// =============================================================================
/**
 * Percentages of net surplus, applied at year end by the allocation entry.
 * The statutory funds come first; what remains is split between interest on
 * share capital and patronage refund. The defaults follow the usual reading
 * of the Cooperative Code (reserve at least 10%, education and training up to
 * 10%, community development at least 3%, optional fund up to 7%) — CHECK
 * THEM AGAINST THE CO-OP'S BY-LAWS AND GENERAL ASSEMBLY RESOLUTION before
 * the first allocation. Stored as numbers with up to two decimals.
 */
$config['coop_reserve_fund_pct']   = 10;
$config['coop_cetf_pct']           = 10;
$config['coop_cdf_pct']            = 3;
$config['coop_optional_fund_pct']  = 7;
$config['coop_isc_pct']            = 30;   // of the REMAINDER; the patronage refund takes the rest

/* What the co-op measures its indicators against (Settings → Co-operative).
   These are the usual CDA figures; a co-op sets its own. 0 hides the mark. */
$config['coop_bench_par_max']           = 5;
$config['coop_bench_allowance_min']     = 35;
$config['coop_bench_share_capital_min'] = 35;
$config['coop_bench_statutory_min']     = 10;
$config['coop_bench_cost_max']          = 30;

$config['acct_coop_reserve_fund']      = '';
$config['acct_coop_cetf']              = '';
$config['acct_coop_cdf']               = '';
$config['acct_coop_optional_fund']     = '';
$config['acct_coop_isc_payable']       = '';
$config['acct_coop_patronage_payable'] = '';


// =============================================================================
// P — PRINTED REPORTS
// =============================================================================
/** Rows per printed page for the books, which print with page totals. */
$config['report_rows_per_page']  = 32;
$config['report_show_printed_by'] = TRUE;
/** A line under every report's title, e.g. "Unaudited". Empty prints nothing. */
$config['report_note']           = '';

/**
 * Signature blocks on vouchers and statements. "Prepared by" is the person
 * who prepared the entry or ran the report; these are the other three.
 */
$config['sign_checked_by']      = '';
$config['sign_checked_title']   = 'Bookkeeper';
$config['sign_approved_by']     = '';
$config['sign_approved_title']  = 'Manager';
$config['sign_noted_by']        = '';
$config['sign_noted_title']     = '';


// =============================================================================
// M — MAIL
// =============================================================================
/**
 * Transport: 'smtp' (production), 'mail' (local MTA), or 'log' (development:
 * writes messages to application/logs/mail/ and sends nothing).
 *
 * NOTE: 'log' WRITES COMPLETE PASSWORD-RESET LINKS TO DISK. It is refused
 * unless mail_allow_log_transport is TRUE, which is only the case in the
 * development environment.
 */
$config['mail_transport']           = (ENVIRONMENT === 'development') ? 'log' : 'mail';
$config['mail_allow_log_transport'] = (ENVIRONMENT === 'development');

$config['mail_from_email'] = getenv('GP_MAIL_FROM') ?: 'noreply@example.com';
$config['mail_from_name']  = '';    // empty = store_name

/** Where password and email notices tell people to write. Empty = the company's own address (Settings → Company). */
$config['support_email'] = '';
$config['mail_reply_to']   = '';    // empty = store_email

/** SMTP. The PASSWORD comes from GP_SMTP_PASSWORD in secrets.php. */
$config['mail_smtp_host']      = getenv('GP_SMTP_HOST') ?: '';
$config['mail_smtp_port']      = 465;
$config['mail_smtp_user']      = getenv('GP_SMTP_USER') ?: '';
$config['mail_smtp_crypto']    = 'ssl';   // 'ssl' with 465, 'tls' (STARTTLS) with 587
$config['mail_smtp_timeout_s'] = 15;

/** CA bundle for verifying the SMTP certificate. Verification is never disabled. */
$config['mail_ca_bundle'] = getenv('MAIL_CA_BUNDLE')
	?: (is_file('D:/xampp/apache/bin/curl-ca-bundle.crt')
		? 'D:/xampp/apache/bin/curl-ca-bundle.crt'
		: '');


// =============================================================================
// F — NOTIFICATIONS
// =============================================================================
/** In-app notifications are the record; email is a copy for VERIFIED addresses. */
$config['notify_email_enabled'] = TRUE;
$config['notify_page_size']     = 20;

/** Tell approvers when an entry is submitted, and preparers when it is decided. */
$config['notify_approvals'] = TRUE;



// =============================================================================
// R — RETENTION & CRON
// =============================================================================
/**
 * NOTE: boundaries are computed in PHP and bound as parameters. PHP writes
 * every timestamp in UTC (index.php) while a shared host's MySQL may run in
 * local time — NOW() in a sweep would be off by the offset.
 *
 * The audit log is kept for ten years: books of accounts and their trail
 * outlive the usual retention periods.
 */
$config['retain_rate_limit_days']     = 7;
$config['retain_audit_log_days']      = 3650;
$config['retain_password_reset_days'] = 180;
$config['retain_notification_days']   = 365;



// =============================================================================
// U — UPLOADS
// =============================================================================
/** Attachments on journals and documents: scanned receipts, invoices, contracts. */
$config['attachment_max_bytes'] = 10485760;    // 10 MB
$config['attachment_types']     = 'pdf,jpg,jpeg,png,webp';


// =============================================================================
// X — DEBUG SWITCHES   (never TRUE in production)
// =============================================================================
$config['debug_log_api_payloads'] = FALSE;   // logs request bodies — including credentials
