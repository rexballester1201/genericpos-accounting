<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// The ledger's extras: saved and recurring entries, opening balances, year-end
// closing, vouchers, attachments, the worksheet and the columnar books.
// Included by config/routes.php, above the SPA catch-all.

// The worksheet, the columnar books, the integrity check
$route['api/v1/reports/worksheet']['get']                 = 'reports/worksheet';
$route['api/v1/reports/columnar-book']['get']             = 'reports/columnar_book';
$route['api/v1/reports/integrity']['get']                 = 'reports/integrity';

// Vouchers and attachments
$route['api/v1/journals/(:num)/voucher']['get']           = 'journals/voucher/$1';
$route['api/v1/attachments']['get']                       = 'attachments/index';
$route['api/v1/attachments']['post']                      = 'attachments/index';
$route['api/v1/attachments/(:num)/file']['get']           = 'attachments/file/$1';
$route['api/v1/attachments/(:num)']['delete']             = 'attachments/remove/$1';

// Saved and recurring entries (bookkeepers and above)
$route['api/v1/journal-templates']['get']                 = 'templates/index';
$route['api/v1/journal-templates']['post']                = 'templates/create';
$route['api/v1/journal-templates/(:num)/draft']['post']   = 'templates/draft/$1';
$route['api/v1/journal-templates/(:num)']['get']          = 'templates/show/$1';
$route['api/v1/journal-templates/(:num)']['put']          = 'templates/update/$1';
$route['api/v1/journal-templates/(:num)']['delete']       = 'templates/delete/$1';

// Year-end closing and a co-op's net-surplus allocation (administrators)
$route['api/v1/year-end']['get']                          = 'year_end/index';
$route['api/v1/year-end/(:num)/close']['post']            = 'year_end/close/$1';
$route['api/v1/year-end/(:num)/reopen']['post']           = 'year_end/reopen/$1';
$route['api/v1/year-end/(:num)/allocate']['post']         = 'year_end/allocate/$1';
$route['api/v1/year-end/(:num)/allocation/undo']['post']  = 'year_end/undo_allocation/$1';

// Opening balances (administrators)
$route['api/v1/opening-balances']['get']                  = 'opening/index';
$route['api/v1/opening-balances']['put']                  = 'opening/index';
$route['api/v1/opening-balances/post']['post']            = 'opening/post';
$route['api/v1/opening-balances/(:num)/undo']['post']     = 'opening/undo/$1';
$route['api/v1/opening-balances/(:num)/copy']['post']     = 'opening/copy/$1';
