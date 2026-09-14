<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * secrets_helper.php — the ONE way this project resolves a secret
 *
 * GenericPOS · application/helpers/secrets_helper.php
 *
 * ─── WHY THIS EXISTS ──────────────────────────────────────────────────────
 * `.htaccess` SetEnv is Apache configuration and PHP-CLI never reads it. Cron
 * runs the scheduled job and its mail, so a secret supplied only by SetEnv
 * works over the web and is silently EMPTY in every scheduled job.
 *
 * ─── RESOLUTION ORDER (deliberate) ────────────────────────────────────────
 *   1. getenv('GP_<NAME>')       the environment, canonical name
 *   2. getenv('<NAME>')          the environment, unprefixed
 *   3. application/config/secrets.php (a .php so it executes rather than
 *      being served, readable by web AND cron)
 *
 * An empty value at any step falls through to the next — '' means "not set".
 */

if ( ! function_exists('gp_secret'))
{
    /**
     * @param  string $name    canonical GP_-prefixed name, e.g. 'GP_JWT_SECRET'
     * @param  string $default returned when nothing supplies a non-empty value
     * @return string
     */
    function gp_secret($name, $default = '')
    {
        $name = (string) $name;

        $env = getenv($name);
        if (is_string($env) && $env !== '') return $env;

        /* NOTE: the offset is strlen() of the prefix, never a literal. The
           original helper hard-coded the length of a SHORTER prefix from the
           project before it and stripped the wrong number of characters, so
           the documented unprefixed fallback never resolved. */
        $prefix = 'GP_';
        if (strncmp($name, $prefix, strlen($prefix)) === 0)
        {
            $env = getenv(substr($name, strlen($prefix)));
            if (is_string($env) && $env !== '') return $env;
        }

        /* Loaded at most once per process; "have we tried" is what is cached,
           so a missing file is not re-required on every lookup. */
        static $file = NULL;

        if ($file === NULL)
        {
            $file = array();
            $path = APPPATH . 'config/secrets.php';

            if (is_file($path) && is_readable($path))
            {
                $loaded = require $path;

                if (is_array($loaded))
                {
                    $file = $loaded;
                }
                else
                {
                    log_message('error', 'gp_secret(): ' . $path . ' did not return an array — '
                        . 'every secret it was meant to supply will resolve empty.');
                }
            }
        }

        return (isset($file[$name]) && is_string($file[$name]) && $file[$name] !== '')
            ? $file[$name]
            : $default;
    }
}
