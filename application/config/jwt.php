<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
| ─────────────────────────────────────────────────────────────────────
| JWT configuration — GenericPOS
| ─────────────────────────────────────────────────────────────────────
|
| The signing secret is read ONLY from GP_JWT_SECRET (environment first,
| then application/config/secrets.php — see secrets_helper.php).
|
| NOTE: THERE IS NO FALLBACK VALUE, ON PURPOSE. A committed development
| secret shipped "to avoid an outage on deploy" ends up running production,
| and then anyone holding a copy of the codebase can sign
| {"user_id": <anyone>, "role": "admin"}. With no fallback, a missing secret
| is a loud configuration error (JWT_lib answers 503 with a message naming
| the key) instead of a silent forgery hole.
|
| Generate one:
|   php -r "echo rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');"
|
| NOTE: never reuse a secret from another project — two apps sharing a secret
| accept each other's tokens.
|
| Per-audience lifetimes live in app.php Section B (jwt_expiry_*), NOT here:
| both files load into one flat namespace, and a key defined twice is decided
| by load order.
*/
require_once APPPATH . 'helpers/secrets_helper.php';

$config['jwt_secret'] = gp_secret('GP_JWT_SECRET');

/* Clock-skew tolerance in seconds. Keep 0 unless drift is known. */
$config['jwt_leeway_s'] = 0;
