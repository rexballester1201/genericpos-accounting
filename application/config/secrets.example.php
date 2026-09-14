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
 * so a value set in the environment always wins.
 *
 * Empty is safe — it means "not set". Never use a placeholder string.
 */
return array(

    /**
     * REQUIRED. Signs every access token. 32+ bytes of randomness:
     *   php -r "echo rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');"
     * or in phpMyAdmin:  SELECT TO_BASE64(RANDOM_BYTES(48));
     * Whoever holds it can mint an administrator token. Rotating it signs
     * everyone out, which is the point after a suspected leak.
     */
    'GP_JWT_SECRET'         => '',

    /**
     * First-run setup. /setup creates the first administrator only while no
     * administrator exists AND this key is presented. Clear it afterwards.
     */
    'GP_SETUP_KEY'          => '',

    /** CodeIgniter's encryption key (config.php). Random, 32+ characters. */
    'GP_ENCRYPTION_KEY'     => '',

    /** Database. Leave empty to use application/config/database.php values. */
    'GP_DB_HOST'            => '',
    'GP_DB_USER'            => '',
    'GP_DB_PASSWORD'        => '',
    'GP_DB_NAME'            => '',

    /** SMTP password for mail_smtp_user (app.php Section M). */
    'GP_SMTP_PASSWORD'      => '',

    /**
     * Web push (with feature_web_push on). A P-256 pair, base64url — e.g.
     *   npx web-push generate-vapid-keys
     * The public half reaches browsers through /api/v1/store; nothing else
     * needs editing. Rotating the pair invalidates every stored subscription.
     */
    'GP_VAPID_PRIVATE_KEY'  => '',
    'GP_VAPID_PUBLIC_KEY'   => '',
    'GP_VAPID_SUBJECT'      => '',          // e.g. mailto:owner@your-shop.com

);
