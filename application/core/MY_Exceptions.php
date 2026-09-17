<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * MY_Exceptions.php — show_error() and show_404() in the API's envelope on /api/ paths
 *
 * GenericPOS Accounting
 *
 * CodeIgniter answers some problems itself with an HTML page: a database
 * error while db_debug is on, a URI with disallowed characters, show_404().
 * On an API path the front end can only read {status, data, message}, so
 * those become JSON with the same status code. A database error never shows
 * its SQL or its message — that stays in the log. Everywhere else (the SPA
 * shell, the command line) CodeIgniter's own pages are unchanged.
 *
 * See application/core/Api_errors.php for uncaught exceptions and fatal errors.
 */
class MY_Exceptions extends CI_Exceptions
{
    public function show_error($heading, $message, $template = 'error_general', $status_code = 500)
    {
        if (function_exists('gp_is_api_request') && gp_is_api_request()) {
            $status_code = (int) $status_code ?: 500;
            if ($template === 'error_db') {
                gp_api_fail($status_code >= 400 ? $status_code : 500);
            }
            if ($template === 'error_404') {
                gp_api_fail(404, 'There is nothing at this address.');
            }
            $text = trim(strip_tags(implode(' ', (array) $message)));
            gp_api_fail($status_code, $text !== '' ? $text : NULL);
        }
        return parent::show_error($heading, $message, $template, $status_code);
    }
}
