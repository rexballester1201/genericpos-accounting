<?php
/**
 * router.php — the front controller for `php -S`, so the test suites can run
 * against a throwaway database without Apache.
 *
 *   GP_DB_NAME=acc_scratch php -S 127.0.0.1:8781 -t . tests/router.php
 *
 * Every request goes through CodeIgniter's index.php, the way Apache's rewrite
 * rules send it. The database is chosen by GP_DB_NAME in the environment
 * (gp_secret() reads the environment first), so nothing here can reach the
 * real one. Leave CI_ENV unset: in development CodeIgniter 3.1.9 prints PHP
 * 8.2 deprecation notices into the JSON.
 */
$root = dirname(__DIR__);

$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['PHP_SELF']        = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';

chdir($root);
require $root . '/index.php';
