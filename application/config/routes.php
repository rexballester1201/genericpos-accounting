<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| =============================================================================
| GenericPOS Accounting — URI routing
| =============================================================================
| CodeIgniter 3.1.9 · SPA shell (index.html) + PWA · JSON API at /api/v1
|
| NOTE: ORDER MATTERS. CI3 matches top to bottom and stops at the first hit:
|   1. Literal sub-paths BEFORE their (:num)/(:any) siblings.
|   2. The SPA catch-all (.+) is LAST, always — a route declared below it would
|      answer a JSON request with the HTML shell.
|
| NOTE: EXPLICIT ROUTES ONLY. An endpoint exists because it is listed here.
|
| NOTE: THE ROUTE TABLE DOES NOT AUTHENTICATE. Every method behind these calls
| its own guard as its first statement: viewer_check / bookkeeper_check /
| accountant_check / admin_check (api_helper.php). A route here whose method
| forgets that is public.
|
| NOTE: verb keys are LOWERCASE array entries: $route[path]['get'] = …
| (CI3 lowercases the verb before comparing).
|
| NOTE: (:any) in CI3 is ONE segment ([^/]+). (.+) crosses slashes.
*/

$route['default_controller']   = 'home';
$route['404_override']         = 'notfound';     // JSON for api/*, the shell otherwise, an error on the command line
$route['translate_uri_dashes'] = FALSE;

// =============================================================================
// SIGN-IN  (Auth.php) — there is no self sign-up: an administrator creates every account
// =============================================================================
$route['api/v1/auth/login']              = 'auth/username_login';
$route['api/v1/auth/refresh']            = 'auth/token_refresh';
$route['api/v1/auth/logout']             = 'auth/logout';
$route['api/v1/auth/me']                 = 'auth/me';
$route['api/v1/auth/forgot-password']    = 'auth/forgot_password';
$route['api/v1/auth/reset-password']['get']  = 'auth/reset_password_check';
$route['api/v1/auth/reset-password']['post'] = 'auth/reset_password';
$route['api/v1/auth/verify-email/send']  = 'auth/verify_email_send';
$route['api/v1/auth/verify-email']       = 'auth/email_verify';
$route['api/v1/auth']                    = 'auth/index';

// First-run setup (Setup.php) — refuses once an administrator exists.
$route['api/v1/setup']['get']  = 'setup/status';
$route['api/v1/setup']['post'] = 'setup/run';

// The company's public face: branding for the shell, the manifest, robots.txt (Store.php)
$route['api/v1/store']         = 'store/index';
$route['manifest.webmanifest'] = 'store/manifest';
$route['robots.txt']           = 'store/robots';

// =============================================================================
// THE SIGNED-IN USER
// =============================================================================
$route['api/v1/profile/password'] = 'profile/password';
$route['api/v1/profile/avatar']   = 'profile/avatar';
$route['api/v1/profile']          = 'profile/index';

$route['api/v1/notifications/read']['post'] = 'notifications/read';
$route['api/v1/notifications']['get']       = 'notifications/index';

$route['api/v1/dashboard/badges']['get'] = 'dashboard/badges';
$route['api/v1/dashboard']['get']        = 'dashboard/index';

// =============================================================================
// THE LEDGER
// =============================================================================
// Chart of accounts (Accounts.php) — reading: every role · changing: administrators
$route['api/v1/accounts/(:num)']['put']    = 'accounts/update/$1';
$route['api/v1/accounts/(:num)']['delete'] = 'accounts/delete/$1';
$route['api/v1/accounts']['get']           = 'accounts/index';
$route['api/v1/accounts']['post']          = 'accounts/create';

// Fiscal years and periods (Periods.php)
$route['api/v1/fiscal-years']['get']          = 'periods/index';
$route['api/v1/fiscal-years']['post']         = 'periods/create_year';
$route['api/v1/periods/(:num)/status']['post'] = 'periods/status/$1';

// Journal entries and their workflow (Journals.php)
$route['api/v1/journals/lookups']['get']         = 'journals/lookups';
$route['api/v1/journals/(:num)/submit']['post']  = 'journals/submit/$1';
$route['api/v1/journals/(:num)/approve']['post'] = 'journals/approve/$1';
$route['api/v1/journals/(:num)/reject']['post']  = 'journals/reject/$1';
$route['api/v1/journals/(:num)/cancel']['post']  = 'journals/cancel/$1';
$route['api/v1/journals/(:num)/reverse']['post'] = 'journals/reverse/$1';
$route['api/v1/journals/(:num)']['get']          = 'journals/show/$1';
$route['api/v1/journals/(:num)']['put']          = 'journals/update/$1';
$route['api/v1/journals']['get']                 = 'journals/index';
$route['api/v1/journals']['post']                = 'journals/create';

// Reports (Reports.php) — every role reads them
$route['api/v1/reports/balance-sheet']['get']     = 'reports/balance_sheet';
$route['api/v1/reports/income-statement']['get']  = 'reports/income_statement';
$route['api/v1/reports/changes-in-equity']['get'] = 'reports/changes_in_equity';
$route['api/v1/reports/cash-flows']['get']        = 'reports/cash_flows';
$route['api/v1/reports/trial-balance']['get']     = 'reports/trial_balance';
$route['api/v1/reports/general-ledger']['get']    = 'reports/general_ledger';
$route['api/v1/reports/books']['get']             = 'reports/books';
$route['api/v1/reports/analysis']['get']          = 'reports/analysis';

// =============================================================================
// ADMINISTRATION  (controllers/admin/*) — admin_check inside
// =============================================================================
$route['api/v1/admin/staff/(:num)']['put'] = 'admin/staff/update/$1';
$route['api/v1/admin/staff']['get']        = 'admin/staff/index';
$route['api/v1/admin/staff']['post']       = 'admin/staff/create';

$route['api/v1/admin/settings/upload']['post']    = 'admin/settings/upload';
$route['api/v1/admin/settings/reset']['post']     = 'admin/settings/reset';
$route['api/v1/admin/settings/mail-test']['post'] = 'admin/settings/mail_test';
$route['api/v1/admin/settings']['get']            = 'admin/settings/index';
$route['api/v1/admin/settings']['post']           = 'admin/settings/save';

$route['api/v1/admin/audit']['get'] = 'admin/audit/index';

// =============================================================================
// COMMAND LINE — MUST STAY ABOVE THE CATCH-ALL, matched case-INSENSITIVELY
// =============================================================================
// CI3 routes `php index.php tools …` through this same table. Below the (.+)
// catch-all it would print the SPA shell and exit 0. An unknown command lands
// in Notfound, which exits 1 on the command line.
$route['(?i)maildiag']      = 'maildiag/index';
$route['(?i)maildiag/(.+)'] = 'maildiag/$1';
$route['(?i)tools']         = 'tools/index';
$route['(?i)tools/(.+)']    = 'tools/$1';

// =============================================================================
// SPA SHELL FALLBACK — MUST BE THE LAST ROUTE IN THIS FILE
// =============================================================================
// Any other non-file path (/journals/412, /reports/trial-balance) gets
// index.html from Auth::spa, and the client router takes it from there.
$route['(.+)'] = 'auth/spa';
