<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Banking: bank accounts, statements, matching, bank adjustments and the
// reconciliation report. Included by config/routes.php, above the SPA
// catch-all. Owned by that module.

// Bank accounts and the overview (Banking.php) — reading: every role
$route['api/v1/banking/summary']['get']                    = 'banking/summary';
$route['api/v1/banking/accounts/(:num)']['put']            = 'banking/update_account/$1';
$route['api/v1/banking/accounts']['post']                  = 'banking/create_account';
$route['api/v1/banking']['get']                            = 'banking/index';

// Statements — bookkeepers prepare and match; accountants record entries, reconcile and reopen
$route['api/v1/banking/statements/(:num)/lines']['post']      = 'banking/add_line/$1';
$route['api/v1/banking/statements/(:num)/import']['post']     = 'banking/import_lines/$1';
$route['api/v1/banking/statements/(:num)/auto-match']['post'] = 'banking/auto_match/$1';
$route['api/v1/banking/statements/(:num)/reconcile']['post']  = 'banking/reconcile/$1';
$route['api/v1/banking/statements/(:num)/reopen']['post']     = 'banking/reopen/$1';
$route['api/v1/banking/statements/(:num)']['get']             = 'banking/statement/$1';
$route['api/v1/banking/statements/(:num)']['put']             = 'banking/update_statement/$1';
$route['api/v1/banking/statements/(:num)']['delete']          = 'banking/delete_statement/$1';
$route['api/v1/banking/statements']['post']                   = 'banking/create_statement';

// One bank line
$route['api/v1/banking/lines/(:num)/match']['post']       = 'banking/match/$1';
$route['api/v1/banking/lines/(:num)/unmatch']['post']     = 'banking/unmatch/$1';
$route['api/v1/banking/lines/(:num)/ignore']['post']      = 'banking/ignore/$1';
$route['api/v1/banking/lines/(:num)/unignore']['post']    = 'banking/unignore/$1';
$route['api/v1/banking/lines/(:num)/record']['post']      = 'banking/record/$1';
$route['api/v1/banking/lines/(:num)/undo-record']['post'] = 'banking/undo_record/$1';
$route['api/v1/banking/lines/(:num)']['put']              = 'banking/update_line/$1';
$route['api/v1/banking/lines/(:num)']['delete']           = 'banking/delete_line/$1';

// The bank reconciliation report (Bank_reports.php) — every role
$route['api/v1/reports/bank-reconciliation']['get'] = 'bank_reports/reconciliation';
