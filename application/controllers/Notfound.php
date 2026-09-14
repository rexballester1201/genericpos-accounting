<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Notfound.php — 404 handler
 *
 * GenericPOS · CodeIgniter 3.1.9
 *
 * Wired via `$route['404_override'] = 'notfound';` in routes.php.
 *
 * ─── WHY THIS EXISTS ──────────────────────────────────────────────────────
 * Without it, CI answers an unknown path with show_404(), which renders an
 * HTML error page. That is the right answer for a browser and the WRONG answer
 * for the API: a fetch() to a mistyped or not-yet-built /api/v1 endpoint gets
 * back HTML, `res.json()` throws "Unexpected token '<'", and the front end
 * reports a parse error. The real cause — one missing route — is nowhere in
 * that message, and it sends people looking in the client.
 *
 * So: JSON for API paths, the SPA shell for everything else.
 *
 * NOTE: CLASS NAME IS ONE WORD ON PURPOSE. CI only ucfirst()s the segment it
 * looks up, so `NotFound` would be sought as `Notfound.php` anyway — matching
 * on Windows and 404-ing on a case-sensitive Linux server, which is the worst
 * possible split because it only breaks in production.
 */
class Notfound extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->helper('api');
    }

    public function index()
    {
        $uri = (string) $this->uri->uri_string();

        // --- Command line: an unknown command is an ERROR, never the shell --
        // A crontab line left over from a removed module (`php index.php
        // chaincli cron`) would otherwise print the shell and exit 0 — a job
        // that reports success every five minutes while doing nothing.
        if (is_cli())
        {
            fwrite(STDERR, 'Unknown command: ' . $uri . PHP_EOL . 'See: php index.php tools' . PHP_EOL);
            exit(1);
        }

        // --- API: JSON, always -------------------------------------------
        if (strpos($uri, 'api/') === 0)
        {
            log_message('error', '[404] Unrouted API path: /' . $uri);
            return json_error('Endpoint not found: /' . $uri, 404);
        }

        // --- Anything else: the SPA shell --------------------------------
        // NOTE: 200, NOT 404. This path is reached for client-side routes the
        // server has never heard of, which is normal for a SPA — the router in
        // index.html decides whether the path is real. Returning 404 with the
        // shell attached would make every deep link look broken to crawlers
        // and to anything that checks the status code before the body.
        //
        // A genuinely unknown client route is handled by the shell's own
        // fallback, which renders the home fragment.
        // Injects the app's base path - see serve_spa_shell() in api_helper.
        serve_spa_shell();
    }
}
