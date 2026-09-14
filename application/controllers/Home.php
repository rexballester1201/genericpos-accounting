<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Home.php — SPA shell for GET /
 *
 * GenericPOS · CodeIgniter 3.1.9
 *
 * The only job of this controller is to hand back index.html. Everything the
 * user sees after that is client-routed by the SPA router inside the shell,
 * and every piece of data it shows comes from /api/v1.
 *
 * Deep links (/markets, /portfolio, …) do NOT arrive here — they hit the
 * `(:any)` catch-all in routes.php and are served by Auth::spa(), which
 * returns the same shell. Two entry points, one file, deliberately: '/' is the
 * default_controller and cannot be routed to Auth without making Auth the
 * default for everything.
 */
class Home extends CI_Controller
{
    public function index()
    {
        $this->_serve_shell();
    }

    /**
     * Serve the SPA shell.
     *
     * NOTE: NOT `$this->load->view()`. index.html sits in the web root, not in
     * application/views/, and the view loader would look for
     * application/views/index.html and throw. It is also NOT a PHP template —
     * running it through the view parser would only invite someone to start
     * embedding server state in it, which is what the /api/v1 layer is for.
     *
     * NOTE: NO-STORE ON THE SHELL. The shell names the hashed asset files the app
     * loads; a cached copy keeps pointing at files a deploy has replaced, and
     * the user is stuck on the old build with no error to explain it. The
     * service worker handles offline availability — that is its job, not the
     * HTTP cache's.
     */
    private function _serve_shell()
    {
        /* NOTE: delegated to serve_spa_shell() in api_helper, which injects the
           application's base path into the markup. Reading index.html directly
           here would ship the unreplaced __RT_BASE__ placeholder and every API
           call from the shell would resolve against the wrong path. */
        $this->load->helper('api');
        serve_spa_shell();
    }
}
