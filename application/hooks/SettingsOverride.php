<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * SettingsOverride.php — push admin-set values into CI's config
 *
 * GenericPOS · hook point: post_controller_constructor
 *
 * ─── WHY THIS EXISTS ──────────────────────────────────────────────────────
 * gp_settings holds an administrator's overrides; application/config/
 * app.php holds the defaults. Without this hook the two never meet:
 * every consumer calls $this->config->item('deposit_min_amount') and gets the
 * FILE value, so a change made on the admin screen saves successfully, reports
 * success, and then does nothing at all.
 *
 * That was the observed behaviour before this hook existed — an admin changed
 * a setting and the endpoint that reads it kept reporting the old value.
 * Nothing errored. A settings screen that silently has no effect is worse than not
 * having one, because it is believed.
 *
 * The alternative was to change every call site to Settings_model::get(). This
 * is better: one place, no churn across controllers, and impossible to forget
 * when a new consumer is written.
 *
 * ─── WHY post_controller_constructor ──────────────────────────────────────
 * It fires AFTER the controller's __construct(), which is where controllers
 * that need the database load it. That timing is what lets this hook be free
 * for requests that have no database:
 *
 *   NOTE: this NEVER loads the database itself. The SPA shell (Home, Auth::spa)
 *   deliberately renders without one so the app still loads and can report a
 *   database outage from its own UI — see application/config/autoload.php.
 *   Loading a connection here would undo that and turn every outage into a
 *   blank page. If $CI->db is absent, this request reads no settings anyway,
 *   so there is nothing to override.
 */
class SettingsOverride
{
    public function apply()
    {
        $CI =& get_instance();

        // No database on this request: the shell path. Nothing to do, and
        // nothing to connect.
        if ( ! isset($CI->db) || ! is_object($CI->db)) return;

        try {
            /* One small query per database-using request. The result is not
               cached across requests on purpose: a settings change must take
               effect on the very next request, not after a cache expires — an
               operator lowering a limit during an incident should not have to
               wonder whether it has taken hold yet. */
            $rows = $CI->db->get('gp_settings')->result_array();
        } catch (Exception $e) {
            /* Never fatal. A missing table (before SCHEMA.sql is applied)
               or a transient failure leaves the config-file defaults in force,
               which is a working system. */
            log_message('error', '[SettingsOverride] could not read gp_settings: ' . $e->getMessage());
            return;
        }

        if ( ! $rows) return;

        $CI->load->model('Settings_model', 'settings_model');

        foreach ($rows as $r) {
            $key = $r['k'];

            /* Belt and braces. A row whose key is not in the registry is an
               override nothing can see or reset through the admin screen —
               most likely inserted by hand. Applying it would let a direct
               database edit change behaviour that the UI reports as default. */
            if ( ! isset($CI->settings_model->registry()[$key])) {
                log_message('error',
                    '[SettingsOverride] gp_settings holds "' . $key . '", which is not in '
                    . 'Settings_model::registry(). Ignored - it would be invisible to the '
                    . 'admin screen and impossible to reset there.');
                continue;
            }

            /* `chain` is owned by ChainRegistry_lib, which reads gp_settings
               itself — it has to, because it is built in a controller's
               __construct(), before this hook runs.

               Skipped here rather than merely redundant. This hook does not
               validate a value against chains.php, and the registry's
               constructor THROWS on an unknown chain key. Pushing an unknown
               value into config would therefore turn one bad database row into
               a fatal on every request, including the settings screen needed
               to remove it. The registry's own reader falls back to the file
               value for exactly that reason; letting this hook overwrite the
               file value first would defeat it. */
            if ($key === 'chain') continue;

            if ($CI->settings_model->is_forbidden($key)) {
                log_message('error',
                    '[SettingsOverride] REFUSED to apply "' . $key . '" from gp_settings: '
                    . 'it is on the forbidden list. Chain facts and secrets are code, not '
                    . 'settings. Remove the row.');
                continue;
            }

            $CI->config->set_item($key, $CI->settings_model->cast($r['value'], $r['value_type']));
        }
    }
}
