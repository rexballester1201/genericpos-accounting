<?php
defined('BASEPATH') OR exit('No direct script access allowed');

// The administrator's tools: imports (chart, contacts, opening balances,
// journal entries) and the integrity check. Included by config/routes.php,
// above the SPA catch-all.

// Imports (administrators): check a file, then import it in one transaction
$route['api/v1/imports']['get']            = 'imports/index';
$route['api/v1/imports/([a-z_]+)']['post'] = 'imports/run/$1';
