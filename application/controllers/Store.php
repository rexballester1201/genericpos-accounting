<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Store.php — the company's public face: branding for the shell, the web
 * manifest and robots.txt
 *
 * GenericPOS Accounting (the class keeps GenericPOS's name, like the settings keys)
 *
 *   GET /api/v1/store              name, logo, colours, currency (public — the sign-in page needs them)
 *   GET /manifest.webmanifest      PWA manifest in the company's own name
 *   GET /robots.txt                a ledger has no public pages
 *
 * Everything here is readable by anonymous visitors, so nothing here may reveal
 * the books, the company's contact details or staff-only configuration. See
 * Store_model for the shape.
 */
class Store extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();          // so admin overrides apply (SettingsOverride hook)
        $this->load->model('Store_model', 'store');
    }

    /** GET /api/v1/store */
    public function index()
    {
        require_method('GET');

        $cfg = $this->store->public_config();

        /* The shell's cache is written by settings saves; this covers a fresh
           install where nothing has been saved yet. */
        $this->store->ensure_cache($cfg);

        /* no-cache, not no-store: the service worker serves the last copy while
           revalidating, so the sign-in page paints in the company's colours
           even offline. */
        $this->output->set_header('Cache-Control: no-cache');

        return json_response($cfg, 'Company');
    }

    /**
     * GET /manifest.webmanifest
     *
     * NOTE: served by the application, not as a static file, so an installed
     * app carries the COMPANY's name and colour rather than the software's.
     * start_url and scope come from SCRIPT_NAME, so the same code works at a
     * domain root and in a sub-directory.
     */
    public function manifest()
    {
        $cfg = $this->store->public_config();

        $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '/index.php';
        $base   = str_replace('\\', '/', dirname($script));
        $base   = ($base === '' || $base === '.' || $base === '/') ? '/' : rtrim($base, '/') . '/';

        $name  = $cfg['name'] !== '' ? $cfg['name'] : 'Accounting';
        $short = mb_strlen($name) > 12 ? mb_substr($name, 0, 12) : $name;

        $manifest = [
            'name'             => $name,
            'short_name'       => $short,
            'description'      => $cfg['tagline'],
            'id'               => $base,
            'start_url'        => $base,
            'scope'            => $base,
            'display'          => 'standalone',
            'display_override' => ['standalone', 'minimal-ui', 'browser'],
            'orientation'      => 'any',
            'theme_color'      => $cfg['brand']['primary'],
            'background_color' => '#ffffff',
            'lang'             => 'en',
            'categories'       => ['business', 'finance', 'productivity'],
            'icons' => [
                ['src' => $base . 'icons/icon-192.png',          'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $base . 'icons/icon-512.png',          'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $base . 'icons/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                ['name' => 'Journal entries', 'url' => $base . 'journals'],
                ['name' => 'New entry',       'url' => $base . 'journals/new'],
                ['name' => 'Trial balance',   'url' => $base . 'reports/trial-balance'],
            ],
        ];

        $this->output
            ->set_status_header(200)
            ->set_content_type('application/manifest+json', 'utf-8')
            ->set_header('Cache-Control: public, max-age=3600')
            ->set_output(json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    /**
     * GET /robots.txt
     *
     * NOTE: crawlers read robots.txt only at a domain's ROOT. Installed in a
     * sub-directory this file is right but unread — the noindex tag the shell
     * puts on every page (_shell_seo) still applies.
     */
    public function robots()
    {
        $this->output
            ->set_status_header(200)
            ->set_content_type('text/plain', 'utf-8')
            ->set_header('Cache-Control: public, max-age=3600')
            ->set_output("User-agent: *\nDisallow: /\n");
    }
}
