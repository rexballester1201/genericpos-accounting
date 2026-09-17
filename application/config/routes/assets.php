<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Fixed assets: categories, the register, depreciation runs, disposals and the
// lapsing schedule. Included by config/routes.php, above the SPA catch-all.
// Owned by that module.

// Categories (Asset_categories.php) — reading: every role · changing: accountants
$route['api/v1/asset-categories/(:num)']['put']    = 'asset_categories/update/$1';
$route['api/v1/asset-categories/(:num)']['delete'] = 'asset_categories/delete/$1';
$route['api/v1/asset-categories']['get']           = 'asset_categories/index';
$route['api/v1/asset-categories']['post']          = 'asset_categories/create';

// The register (Assets.php) — reading: every role · registering and changing: bookkeepers ·
// disposing and taking a disposal back: accountants
$route['api/v1/assets/lookups']['get']               = 'assets/lookups';
$route['api/v1/assets/acquisition-journals']['get']  = 'assets/acquisition_journals';
$route['api/v1/assets/(:num)/dispose']['post']       = 'assets/dispose/$1';
$route['api/v1/assets/(:num)/undo-disposal']['post'] = 'assets/undo_disposal/$1';
$route['api/v1/assets/(:num)']['get']                = 'assets/show/$1';
$route['api/v1/assets/(:num)']['put']                = 'assets/update/$1';
$route['api/v1/assets/(:num)']['delete']             = 'assets/delete/$1';
$route['api/v1/assets']['get']                       = 'assets/index';
$route['api/v1/assets']['post']                      = 'assets/create';

// Depreciation runs (Depreciation.php) — reading: every role · previewing, running and undoing: accountants
$route['api/v1/depreciation/preview']['get']           = 'depreciation/preview';
$route['api/v1/depreciation/runs/(:num)/undo']['post'] = 'depreciation/undo/$1';
$route['api/v1/depreciation/runs/(:num)']['get']       = 'depreciation/show/$1';
$route['api/v1/depreciation/runs']['get']              = 'depreciation/index';
$route['api/v1/depreciation/runs']['post']             = 'depreciation/run';

// The lapsing schedule (Asset_reports.php) — every role
$route['api/v1/reports/lapsing-schedule']['get'] = 'asset_reports/lapsing_schedule';
