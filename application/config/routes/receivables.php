<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Receivables and payables: customers and suppliers, invoices, credit notes,
// bills and debit notes, receipts and payments, aging and statements of account.
// Included by config/routes.php, above the SPA catch-all. Owned by that module.
//
// Every method behind these routes calls its own guard first (viewer_check,
// bookkeeper_check or accountant_check). Literal sub-paths come before their
// (:num) siblings.

// Customers and suppliers (Contacts.php)
$route['api/v1/contacts/lookups']['get']        = 'contacts/lookups';
$route['api/v1/contacts/(:num)/active']['post'] = 'contacts/set_active/$1';
$route['api/v1/contacts/(:num)']['get']         = 'contacts/show/$1';
$route['api/v1/contacts/(:num)']['put']         = 'contacts/update/$1';
$route['api/v1/contacts/(:num)']['delete']      = 'contacts/delete/$1';
$route['api/v1/contacts']['get']                = 'contacts/index';
$route['api/v1/contacts']['post']               = 'contacts/create';

// Invoices, credit notes, bills and debit notes (Documents.php)
$route['api/v1/documents/lookups']['get']        = 'documents/lookups';
$route['api/v1/documents/open-items']['get']     = 'documents/open_items';
$route['api/v1/documents/(:num)/post']['post']   = 'documents/post/$1';
$route['api/v1/documents/(:num)/cancel']['post'] = 'documents/cancel/$1';
$route['api/v1/documents/(:num)/apply']['post']  = 'documents/apply/$1';
$route['api/v1/documents/(:num)']['get']         = 'documents/show/$1';
$route['api/v1/documents/(:num)']['put']         = 'documents/update/$1';
$route['api/v1/documents/(:num)']['delete']      = 'documents/delete/$1';
$route['api/v1/documents']['get']                = 'documents/index';
$route['api/v1/documents']['post']               = 'documents/create';
$route['api/v1/allocations/(:num)/remove']['post'] = 'documents/remove_allocation/$1';

// Receipts and payments (Settlements.php)
$route['api/v1/settlements/lookups']['get']        = 'settlements/lookups';
$route['api/v1/settlements/(:num)/post']['post']   = 'settlements/post/$1';
$route['api/v1/settlements/(:num)/cancel']['post'] = 'settlements/cancel/$1';
$route['api/v1/settlements/(:num)/apply']['post']  = 'settlements/apply/$1';
$route['api/v1/settlements/(:num)']['get']         = 'settlements/show/$1';
$route['api/v1/settlements/(:num)']['put']         = 'settlements/update/$1';
$route['api/v1/settlements/(:num)']['delete']      = 'settlements/delete/$1';
$route['api/v1/settlements']['get']                = 'settlements/index';
$route['api/v1/settlements']['post']               = 'settlements/create';

// Reports (Ar_reports.php) — every role reads them; &format=csv for a spreadsheet file
$route['api/v1/reports/aging']['get']              = 'ar_reports/aging';
$route['api/v1/reports/customer-statement']['get'] = 'ar_reports/customer_statement';
$route['api/v1/reports/subsidiary-ledger']['get']  = 'ar_reports/subsidiary_ledger';
