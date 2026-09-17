<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// Budgets and departments: departments, budgets and their lines, budget vs
// actual, income by department. Included by config/routes.php, above the SPA
// catch-all. Owned by that module.

// Departments (Departments.php) — reading: every role · changing: administrators
$route['api/v1/departments/(:num)/deactivate']['post'] = 'departments/deactivate/$1';
$route['api/v1/departments/(:num)/reactivate']['post'] = 'departments/reactivate/$1';
$route['api/v1/departments/(:num)']['put']             = 'departments/update/$1';
$route['api/v1/departments/(:num)']['delete']          = 'departments/delete/$1';
$route['api/v1/departments']['get']                    = 'departments/index';
$route['api/v1/departments']['post']                   = 'departments/create';

// Budgets (Budgets.php) — reading: every role · preparing, approving, deleting: accountants
$route['api/v1/budgets/summary']['get']         = 'budgets/summary';
$route['api/v1/budgets/(:num)/approve']['post'] = 'budgets/approve/$1';
$route['api/v1/budgets/(:num)/return']['post']  = 'budgets/return_to_draft/$1';
$route['api/v1/budgets/(:num)/primary']['post'] = 'budgets/primary/$1';
$route['api/v1/budgets/(:num)/export']['get']   = 'budgets/export/$1';
$route['api/v1/budgets/(:num)/import']['post']  = 'budgets/import/$1';
$route['api/v1/budgets/(:num)']['get']          = 'budgets/show/$1';
$route['api/v1/budgets/(:num)']['put']          = 'budgets/update/$1';
$route['api/v1/budgets/(:num)']['delete']       = 'budgets/delete/$1';
$route['api/v1/budgets']['get']                 = 'budgets/index';
$route['api/v1/budgets']['post']                = 'budgets/create';

// Reports (Budget_reports.php) — every role reads them
$route['api/v1/reports/budget-vs-actual']['get']  = 'budget_reports/budget_vs_actual';
$route['api/v1/reports/department-income']['get'] = 'budget_reports/department_income';
