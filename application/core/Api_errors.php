<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Api_errors.php — the errors that escape everything else, answered in the API's own envelope
 *
 * GenericPOS Accounting · required by index.php BEFORE CodeIgniter boots
 *
 * CodeIgniter defines its last-resort handlers in system/core/Common.php, each
 * inside function_exists(), so that an application can supply its own. These
 * do what CodeIgniter's do — log, answer 500, show the HTML error page where
 * errors are displayed — except on /api/ paths, where:
 *
 *   · an uncaught exception or a fatal error answers
 *     {"status": false, "data": null, "message": "…"} with status 500: never
 *     an HTML page the front end cannot read, never a file path or SQL;
 *   · a notice or a warning is logged but never PRINTED into the response,
 *     where it would sit in front of the JSON and break it.
 *
 * MY_Exceptions does the same for show_error() (a database error with
 * db_debug on, "disallowed characters" in a URI, show_404()).
 *
 * NOTE: nothing here may rely on the database or on a loaded library: it runs
 * when something has already gone wrong.
 */

if ( ! function_exists('gp_is_api_request'))
{
    function gp_is_api_request()
    {
        if (PHP_SAPI === 'cli') return FALSE;
        $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
        return is_string($path) && strpos($path, '/api/') !== FALSE;
    }
}

if ( ! function_exists('gp_api_fail'))
{
    /** Drop anything half-written and answer with the envelope. Ends the request. */
    function gp_api_fail($code = 500, $message = NULL)
    {
        while (ob_get_level() > 0) @ob_end_clean();
        if ( ! headers_sent()) {
            http_response_code((int) $code ?: 500);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode([
            'status'  => FALSE,
            'data'    => NULL,
            'message' => $message !== NULL && $message !== ''
                ? (string) $message
                : 'Something went wrong on the server. Try again; if it keeps happening, tell your administrator what you were doing and when.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit(1);
    }
}

if ( ! function_exists('_exception_handler'))
{
    function _exception_handler($exception)
    {
        $_error =& load_class('Exceptions', 'core');
        $_error->log_exception('error', 'Exception: ' . $exception->getMessage(), $exception->getFile(), $exception->getLine());

        if (gp_is_api_request()) gp_api_fail(500);

        is_cli() OR set_status_header(500);
        if (str_ireplace(['off', 'none', 'no', 'false', 'null'], '', ini_get('display_errors'))) {
            $_error->show_exception($exception);
        }
        exit(1);
    }
}

if ( ! function_exists('_error_handler'))
{
    function _error_handler($severity, $message, $filepath, $line)
    {
        $is_error = (((E_ERROR | E_PARSE | E_COMPILE_ERROR | E_CORE_ERROR | E_USER_ERROR) & $severity) === $severity);
        if ($is_error && ! gp_is_api_request()) set_status_header(500);

        if (($severity & error_reporting()) !== $severity) return;

        $_error =& load_class('Exceptions', 'core');
        $_error->log_exception($severity, $message, $filepath, $line);

        if (gp_is_api_request()) {
            if ($is_error) gp_api_fail(500);
            return;
        }

        if (str_ireplace(['off', 'none', 'no', 'false', 'null'], '', ini_get('display_errors'))) {
            $_error->show_php_error($severity, $message, $filepath, $line);
        }
        if ($is_error) exit(1);
    }
}

if ( ! function_exists('_shutdown_handler'))
{
    function _shutdown_handler()
    {
        $last = error_get_last();
        if (isset($last) && ($last['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_CORE_WARNING | E_COMPILE_ERROR | E_COMPILE_WARNING))) {
            _error_handler($last['type'], $last['message'], $last['file'], $last['line']);
        }
    }
}
