<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * secrets.example.php — TEMPLATE for application/config/secrets.php
 *
 * Copy to secrets.php ON THE SERVER and fill it in there. secrets.php is
 * gitignored and must never be committed, uploaded from a developer machine
 * over a server copy, or placed in any archive that leaves the server.
 *
 * Read only through gp_secret(). Resolution order for each key:
 *   1. getenv('GP_<NAME>')   2. getenv('<NAME>')   3. this file
 * so a value set in the environment always wins — which is how the test
 * suites point a throwaway database at the same code (GP_DB_NAME).
 *
 * Empty is safe — it means "not set". Never use a placeholder string.
 */
return array(

    /**
     * REQUIRED. Signs every access token. 32+ bytes of randomness:
     *   php -r "echo rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');"
     * or in phpMyAdmin:  SELECT TO_BASE64(RANDOM_BYTES(48));
     *
     * Whoever holds it can mint an administrator token. Rotating it signs
     * everyone out, which is the point after a suspected leak.
     *
     * IT MUST DIFFER FROM EVERY OTHER APPLICATION'S ON THIS HOST. Two systems
     * sharing a secret accept each other's tokens as the user with that id.
     */
    'GP_JWT_SECRET'         => '',

    /**
     * First-run setup. /setup creates the first administrator only while no
     * user exists AND this key is presented. Clear it afterwards.
     */
    'GP_SETUP_KEY'          => '',

    /** CodeIgniter's encryption key (config.php). Random, 32+ characters. */
    'GP_ENCRYPTION_KEY'     => '',

    /** The database. Leave empty to use application/config/database.php. */
    'GP_DB_HOST'            => '',
    'GP_DB_USER'            => '',
    'GP_DB_PASSWORD'        => '',
    'GP_DB_NAME'            => '',

    /** SMTP password for mail_smtp_user (app.php, the mail section). */
    'GP_SMTP_PASSWORD'      => '',

);
