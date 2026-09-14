<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * api_helper.php — GenericPOS Accounting HTTP / API helpers (autoloaded)
 *
 *   1. Responses        json_response()  json_error()  json_invalid()
 *                       json_response_then_continue()
 *   2. Auth guards      auth_check()  auth_optional()  role_check()  role_rank()
 *                       viewer_check()  bookkeeper_check()  accountant_check()  admin_check()
 *   3. Tokens           issue_session_tokens()
 *   4. Rate limiting    rate_limit()
 *   5. Requests         get_json_body()  get_pagination_params()
 *                       build_pagination_meta()  require_method()
 *   6. Sanitising       sanitise_string()  sanitise_text()  sanitise_int()
 *   7. Audit            log_admin_action()
 *   8. Internals        _extract_bearer_token()  _load_jwt()  _account_is_active()
 *   9. The shell        serve_spa_shell()
 *
 * ─── RESPONSE ENVELOPE ────────────────────────────────────────────────────
 *   success   { "status": true,  "data": <payload>, "message": "..." }
 *   error     { "status": false, "data": null|{...}, "message": "...", "code"?: "..." }
 *   422       { "status": false, "data": { "errors": { field: msg } }, "message": "..." }
 *
 * ─── ROLES ────────────────────────────────────────────────────────────────
 *   viewer < bookkeeper < accountant < admin — each can do everything the
 *   ones before it can (PLAN.md §8). The first statement of every endpoint is
 *   one of the *_check() guards.
 *
 * NOTE: THE ROLE ALWAYS COMES FROM THE DATABASE, never from the token. A token
 * is signed, so it cannot be forged — but it is a snapshot, and demoting an
 * accountant must take effect on their very next request, not when their
 * token expires. auth_check() overwrites the claim from the row it reads.
 */


// =============================================================================
// SECTION 1 — RESPONSES
// =============================================================================

if ( ! function_exists('json_response'))
{
    /**
     * Success envelope, sent, and exit.
     *
     * NOTE: $CI->output->_display() before exit, so headers set earlier with
     * set_header() (Retry-After, Cache-Control) are actually transmitted.
     */
    function json_response($data = [], $message = 'Success', $code = 200)
    {
        $CI =& get_instance();
        $CI->output
            ->set_status_header($code)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode(
                ['status' => TRUE, 'data' => $data, 'message' => $message],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));
        $CI->output->_display();
        exit;
    }
}

if ( ! function_exists('json_error'))
{
    /**
     * Error envelope, sent, and exit.
     *
     * @param string     $message  human-readable, safe to show the caller
     * @param int        $code     HTTP status
     * @param array|null $extra    merged into the envelope: ['code' => 'OUT_OF_STOCK',
     *                             'data' => [...]] — a machine code lets the client
     *                             react without parsing prose that will be reworded
     */
    function json_error($message = 'Error', $code = 400, $extra = NULL)
    {
        $CI  =& get_instance();
        $out = ['status' => FALSE, 'data' => NULL, 'message' => $message];
        if (is_array($extra)) $out = array_merge($out, $extra);

        $CI->output
            ->set_status_header($code)
            ->set_content_type('application/json', 'utf-8')
            ->set_output(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $CI->output->_display();
        exit;
    }
}

if ( ! function_exists('json_invalid'))
{
    /**
     * 422 with a per-field error map the forms render inline, so one request
     * tells the user everything that is wrong rather than one thing per submit.
     */
    function json_invalid(array $errors, $message = 'Please correct the highlighted fields.', $code = 422)
    {
        json_error($message, $code, ['data' => ['errors' => $errors]]);
    }
}

if ( ! function_exists('json_response_then_continue'))
{
    /**
     * Send the success envelope, flush it, and RETURN so the caller can keep
     * working (send mail after answering).
     *
     * WHY: forgot-password must take the same time whether or not the account
     * exists. Answering first and doing the account-dependent work afterwards
     * removes the timing difference at the source.
     *
     * NOTE: emit NOTHING after calling this — Content-Length is already sent.
     */
    function json_response_then_continue($data = [], $message = 'Success', $code = 200)
    {
        $CI =& get_instance();

        $payload = json_encode(
            ['status' => TRUE, 'data' => $data, 'message' => $message],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $CI->output
            ->set_status_header($code)
            ->set_content_type('application/json', 'utf-8')
            ->set_header('Content-Length: ' . strlen($payload))
            ->set_header('Connection: close')
            ->set_output($payload);

        $CI->output->_display();

        @ignore_user_abort(TRUE);

        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
            return;
        }
        while (ob_get_level() > 0) @ob_end_flush();
        @flush();
    }
}


// =============================================================================
// SECTION 2 — AUTH GUARDS
// =============================================================================

if ( ! function_exists('role_rank'))
{
    /**
     * viewer < bookkeeper < accountant < admin. Each role can do everything
     * the ones below it can (PLAN.md §8).
     */
    function role_rank($role)
    {
        $ranks = ['viewer' => 1, 'bookkeeper' => 2, 'accountant' => 3, 'admin' => 4];
        return isset($ranks[$role]) ? $ranks[$role] : -1;
    }
}

if ( ! function_exists('is_staff_role'))
{
    /** Any signed-in role. (Every account in a ledger is staff.) */
    function is_staff_role($role)
    {
        return role_rank($role) >= 1;
    }
}

if ( ! function_exists('auth_check'))
{
    /**
     * Validate the Bearer token and return its claims (role re-read from the DB).
     *
     *   401  missing / invalid / expired token, or minted before the last
     *        password change
     *   403  account suspended or closed
     *
     * @return array claims: user_id, role, account_state, auth_method, iat, exp
     */
    function auth_check()
    {
        $CI =& get_instance();

        $token = _extract_bearer_token();
        if ( ! $token) json_error('Unauthorized', 401);

        return _claims_from_token($token, TRUE);
    }
}

if ( ! function_exists('auth_optional'))
{
    /**
     * Claims when a valid token is presented, NULL when none is.
     *
     * NOTE: a token that is PRESENT BUT INVALID still answers 401 rather than
     * quietly treating the caller as signed out. The client refreshes on 401
     * and retries; downgrading silently would hide an expired session behind
     * a page that simply shows less.
     */
    function auth_optional()
    {
        $token = _extract_bearer_token();
        if ( ! $token) return NULL;
        return _claims_from_token($token, TRUE);
    }
}

if ( ! function_exists('_claims_from_token'))
{
    function _claims_from_token($token, $exit_on_fail = TRUE)
    {
        $CI =& get_instance();
        _load_jwt($CI);

        $payload = $CI->jwt_lib->validate($token);
        if ( ! $payload) {
            if ($exit_on_fail) json_error('Invalid or expired token', 401);
            return NULL;
        }

        $claims  = (array) $payload;

        /* A token minted for one narrow purpose carries `typ` and is never a
           session, whoever presents it. */
        if (isset($claims['typ'])) {
            if ($exit_on_fail) json_error('Invalid or expired token', 401);
            return NULL;
        }

        $user_id = isset($claims['user_id']) ? (int) $claims['user_id'] : 0;
        $account = _account_is_active($CI, $user_id);

        if ( ! $account) {
            if ($exit_on_fail) json_error('Account suspended', 403);
            return NULL;
        }

        /* A password change ends every older session. Strict `<`, so the pair
           minted in the same second by the change itself survives. PHP clocks
           on both sides — the host's MySQL may not be on UTC. */
        $iat = isset($claims['iat']) ? (int) $claims['iat'] : 0;

        if ( ! empty($account['password_changed_at'])
            && $iat < strtotime($account['password_changed_at'] . ' UTC')) {
            if ($exit_on_fail) json_error('Session ended. Sign in again.', 401);
            return NULL;
        }

        $claims['role'] = $account['role'];
        return $claims;
    }
}

if ( ! function_exists('role_check'))
{
    /**
     * auth_check() + a role of at least $min_role (viewer < bookkeeper <
     * accountant < admin). The first statement of every endpoint.
     */
    function role_check($min_role = 'viewer')
    {
        $claims = auth_check();

        if (role_rank($claims['role']) < role_rank($min_role)) {
            json_error('You do not have access to this area.', 403, ['code' => 'FORBIDDEN']);
        }
        return $claims;
    }
}

if ( ! function_exists('viewer_check'))
{
    /** Any signed-in user: reports, ledgers, analysis. */
    function viewer_check()     { return role_check('viewer'); }
}

if ( ! function_exists('bookkeeper_check'))
{
    /** Prepare entries, documents and bank matches. */
    function bookkeeper_check() { return role_check('bookkeeper'); }
}

if ( ! function_exists('accountant_check'))
{
    /** Approve and post, reverse, close months, run depreciation, budgets. */
    function accountant_check() { return role_check('accountant'); }
}

if ( ! function_exists('admin_check'))
{
    /** The chart of accounts, fiscal years, year-end close, users, settings, audit. */
    function admin_check()      { return role_check('admin'); }
}


// =============================================================================
// SECTION 3 — TOKENS
// =============================================================================

if ( ! function_exists('issue_session_tokens'))
{
    /**
     * Access + refresh token for a full gp_users row. ONE place builds the
     * claims, so sign-in, sign-up, password change and setup cannot drift.
     */
    function issue_session_tokens(array $user)
    {
        $CI =& get_instance();
        $CI->load->database();
        $CI->load->model('User_model', 'users');
        _load_jwt($CI);

        $access = $CI->jwt_lib->generate([
            'user_id'       => (int)    $user['id'],
            'role'          => (string) $user['role'],
            'account_state' => (string) $user['account_state'],
            'auth_method'   => (string) $user['auth_method'],
        ]);

        $refresh = $CI->users->issue_refresh_token(
            $user['id'], $CI->input->ip_address(), $CI->input->user_agent()
        );

        return ['access_token' => $access, 'refresh_token' => $refresh];
    }
}


// =============================================================================
// SECTION 4 — RATE LIMITING
// =============================================================================

if ( ! function_exists('rate_limit'))
{
    /**
     * Combined check-and-increment on gp_rate_limits, one atomic upsert.
     *
     * $key is a user id, or ip_rate_key() (a negative integer) for callers with
     * no account. The limit is config 'rate_<action>' per rate_limit_window_s.
     *
     * NOTE: AN UNKNOWN ACTION FAILS OPEN, and says so in the log at ERROR (CI3
     * has no WARNING level — a 'warning' line is written nowhere). A renamed
     * action must never lock an endpoint; a MISSING config key is therefore NO
     * limit at all. Every action string used anywhere must have a key in
     * app.php Section D.
     *
     * @return true  exits 429 with Retry-After when exceeded
     */
    function rate_limit($key, $action, $unused = NULL)
    {
        $CI =& get_instance();
        $CI->load->database();
        $CI->config->load('app', FALSE, TRUE);

        $limit = (int) $CI->config->item('rate_' . $action);

        if ($limit <= 0) {
            log_message('error', '[WARN] rate_limit(): no config key rate_' . $action
                . ' in app.php — limit NOT enforced.');
            return TRUE;
        }

        $key          = (int) $key;
        $window_s     = (int) ($CI->config->item('rate_limit_window_s') ?: 3600);
        $window_start = date('Y-m-d H:i:s', time() - $window_s);
        $now          = date('Y-m-d H:i:s');

        $CI->db->query(
            'INSERT INTO gp_rate_limits (user_id, action, count, window_start, updated_at)
             VALUES (?, ?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE
               count        = IF(window_start < ?, 1, count + 1),
               window_start = IF(window_start < ?, ?, window_start),
               updated_at   = ?',
            [$key, $action, $now, $now, $window_start, $window_start, $now, $now]
        );

        $row = $CI->db->select('count, window_start')
                      ->where('user_id', $key)->where('action', $action)
                      ->limit(1)->get('gp_rate_limits')->row();

        if ($row && (int) $row->count > $limit) {
            $retry = max(1, (strtotime($row->window_start) + $window_s) - time());
            $CI->output->set_header('Retry-After: ' . $retry);
            json_error('Too many requests. Please try again later.', 429, ['code' => 'RATE_LIMITED']);
        }

        return TRUE;
    }
}


// =============================================================================
// SECTION 5 — REQUESTS
// =============================================================================

if ( ! function_exists('get_json_body'))
{
    /**
     * The decoded JSON body, or the form fields for a multipart request.
     *
     * NOTE: never XSS-filtered on the way in. What keeps a value safe in the
     * database is bound parameters; what keeps it safe on screen is escaping
     * where it is rendered (textContent / esc() in the client, Mailer_lib::_e()
     * in email). Stripping on input destroys data and secures nothing.
     */
    function get_json_body()
    {
        static $cache = NULL;
        if ($cache !== NULL) return $cache;

        $CI  =& get_instance();
        $raw = file_get_contents('php://input');

        if ( ! empty($raw)) {
            $decoded = json_decode($raw, TRUE);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $cache = $decoded;
            }
            $t = ltrim($raw);
            if (isset($t[0]) && ($t[0] === '{' || $t[0] === '[')) {
                log_message('error', '[WARN] get_json_body(): malformed JSON: ' . json_last_error_msg());
            }
        }

        $post = $CI->input->post(NULL, FALSE);
        return $cache = (is_array($post) ? $post : []);
    }
}

if ( ! function_exists('get_pagination_params'))
{
    /** ?page=N&per_page=N → ['page', 'limit', 'offset'] */
    function get_pagination_params($default_limit = 20, $max_limit = 100)
    {
        $CI =& get_instance();
        $page  = max(1, (int) ($CI->input->get('page') ?: 1));
        $limit = (int) ($CI->input->get('per_page') ?: $default_limit);
        $limit = max(1, min($limit, $max_limit));
        return ['page' => $page, 'limit' => $limit, 'offset' => ($page - 1) * $limit];
    }
}

if ( ! function_exists('build_pagination_meta'))
{
    function build_pagination_meta(array $items, $total, $page, $limit)
    {
        return [
            'items' => $items,
            'total' => (int) $total,
            'page'  => (int) $page,
            'limit' => (int) $limit,
            'pages' => (int) max(1, ceil(((int) $total) / max(1, (int) $limit))),
        ];
    }
}

if ( ! function_exists('require_method'))
{
    /** 405 with an Allow header unless the request method is one of $allowed. */
    function require_method($allowed)
    {
        $CI     =& get_instance();
        $method = strtolower($CI->input->method());
        $list   = array_map('strtolower', (array) $allowed);

        if ( ! in_array($method, $list, TRUE)) {
            $CI->output->set_header('Allow: ' . strtoupper(implode(', ', $list)));
            json_error('Method not allowed', 405);
        }
        return TRUE;
    }
}


// =============================================================================
// SECTION 6 — SANITISING
// =============================================================================

if ( ! function_exists('sanitise_string'))
{
    /**
     * Trim + strip tags. FOR IDENTIFIERS AND CODE-SUPPLIED LITERALS ONLY:
     * strip_tags() deletes from an unmatched "<" to the end of the string, so
     * on free text it truncates a sentence ("Refund <50%" → "Refund").
     */
    function sanitise_string($value)
    {
        return trim(strip_tags((string) ($value ?? '')));
    }
}

if ( ! function_exists('sanitise_text'))
{
    /** Free text a person typed: control characters out, words kept. */
    function sanitise_text($value)
    {
        $s = trim((string) ($value ?? ''));
        $c = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
        return ($c === NULL) ? $s : $c;
    }
}

if ( ! function_exists('sanitise_int'))
{
    function sanitise_int($value, $default = 0)
    {
        return is_numeric($value) ? (int) $value : (int) $default;
    }
}


// =============================================================================
// SECTION 7 — AUDIT
// =============================================================================

if ( ! function_exists('log_admin_action'))
{
    /**
     * One immutable row in gp_admin_audit_log. Every staff mutation calls it.
     *
     * @param array             $actor        claims from a *_check() guard, or ['user_id' => id]
     * @param string            $action       'journal.post', 'period.close' …
     * @param string|null       $target_type
     * @param int|null          $target_id
     * @param string|array|null $detail       arrays are JSON-encoded (as an object)
     */
    function log_admin_action(array $actor, $action, $target_type = NULL, $target_id = NULL, $detail = NULL)
    {
        $CI =& get_instance();
        $CI->load->database();

        $admin_id = isset($actor['user_id']) ? (int) $actor['user_id'] : 0;
        if ($admin_id === 0) {
            log_message('error', '[audit] no user_id for action ' . $action . ' — not written.');
            return FALSE;
        }

        if (is_array($detail)) {
            $detail = json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_FORCE_OBJECT);
        }

        $ip = $CI->input->ip_address();

        try {
            $CI->db->insert('gp_admin_audit_log', [
                'admin_id'    => $admin_id,
                'action'      => substr(sanitise_string($action), 0, 64),
                'target_type' => $target_type !== NULL ? substr(sanitise_string($target_type), 0, 32) : NULL,
                'target_id'   => $target_id !== NULL ? (int) $target_id : NULL,
                'detail'      => $detail,
                'ip_address'  => ($ip && $ip !== '0.0.0.0') ? $ip : NULL,
                'occurred_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            /* The action it records has already happened; losing the audit row
               must not turn it into a reported failure. Loud in the log. */
            log_message('error', '[audit] insert failed for ' . $action . ': ' . $e->getMessage());
            return FALSE;
        }

        return TRUE;
    }
}


// =============================================================================
// SECTION 8 — INTERNALS
// =============================================================================

if ( ! function_exists('_extract_bearer_token'))
{
    /**
     * The raw JWT from Authorization (or X-Authorization, which some Android
     * WebView proxies preserve when they strip Authorization). The header must
     * START with "Bearer ".
     *
     * NOTE: request_headers() WITHOUT xss_clean — cleaning a bearer credential
     * can only corrupt it (CI3 rewrites '.innerHTML' inside a header).
     */
    function _extract_bearer_token()
    {
        $CI =& get_instance();
        $h  = $CI->input->request_headers();

        $auth = $h['Authorization'] ?? $h['authorization'] ?? NULL;
        if ( ! $auth && ! empty($_SERVER['HTTP_AUTHORIZATION']))          $auth = $_SERVER['HTTP_AUTHORIZATION'];
        if ( ! $auth && ! empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) $auth = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        if ( ! $auth) {
            $auth = $h['X-Authorization'] ?? $h['x-authorization'] ?? ($_SERVER['HTTP_X_AUTHORIZATION'] ?? NULL);
        }

        if (empty($auth) || stripos($auth, 'Bearer ') !== 0) return NULL;

        $token = trim(substr($auth, 7));
        return $token !== '' ? $token : NULL;
    }
}

if ( ! function_exists('_load_jwt'))
{
    function _load_jwt(&$CI)
    {
        if ( ! isset($CI->jwt_lib)) $CI->load->library('JWT_lib');
    }
}

if ( ! function_exists('_account_is_active'))
{
    /**
     * The fields every authenticated request needs from gp_users, in one
     * indexed lookup, or FALSE when the account is missing or not active.
     *
     * @return array|false ['role', 'password_changed_at']
     */
    function _account_is_active(&$CI, $user_id)
    {
        if ((int) $user_id <= 0) return FALSE;

        $CI->load->database();

        $row = $CI->db->select('role, password_changed_at')
                      ->where('id', (int) $user_id)
                      ->where('account_state', 'active')
                      ->get('gp_users', 1)->row_array();

        if (empty($row)) return FALSE;

        /* An unknown role ranks -1 (role_rank) and every guard refuses it. */
        return [
            'role'                => (string) $row['role'],
            'password_changed_at' => $row['password_changed_at'] ?? NULL,
        ];
    }
}


// =============================================================================
// SECTION 9 — THE SHELL
// =============================================================================

if ( ! function_exists('_shell_branding'))
{
    /**
     * The public store config for the shell WITHOUT a database: the cache file
     * written on every settings save, else the config-file defaults.
     */
    function _shell_branding()
    {
        $path = APPPATH . 'cache' . DIRECTORY_SEPARATOR . 'store_public.json';

        if (is_file($path)) {
            $cfg = json_decode((string) @file_get_contents($path), TRUE);
            if (is_array($cfg) && isset($cfg['name'])) return $cfg;
        }

        /* Fresh install, or an unwritable cache directory: the file defaults. */
        $config = [];
        $file   = APPPATH . 'config/app.php';
        if (is_file($file)) include $file;

        return [
            'name'    => (string) ($config['store_name'] ?? 'My Store'),
            'tagline' => (string) ($config['store_tagline'] ?? ''),
            'brand'   => [
                'primary' => (string) ($config['brand_primary'] ?? '#0f766e'),
                'accent'  => (string) ($config['brand_accent'] ?? '#f59e0b'),
                'theme'   => (string) ($config['theme_mode'] ?? 'auto'),
                'radius'  => (int) ($config['ui_radius'] ?? 10),
                'font'    => (string) ($config['font_family'] ?? 'system'),
            ],
        ];
    }
}

if ( ! function_exists('_contrast_ink'))
{
    /** '#ffffff' or '#0b1120' — whichever reads better on the given colour. */
    function _contrast_ink($hex)
    {
        $hex = ltrim((string) $hex, '#');
        if (strlen($hex) !== 6) return '#ffffff';
        $c = [];
        foreach ([0, 2, 4] as $i) {
            $v = hexdec(substr($hex, $i, 2)) / 255;
            $c[] = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
        }
        $lum = 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
        return $lum > 0.45 ? '#0b1120' : '#ffffff';
    }
}

if ( ! function_exists('_shell_seo'))
{
    /**
     * What the first response says about the page: its title and description,
     * and that it is not for search engines. A ledger is private: every page is
     * noindex, nofollow, and nothing is looked up — the shell never touches the
     * database.
     *
     * @return array ['title' => text, 'desc' => text, 'head' => HTML]
     */
    function _shell_seo($uri, array $b)
    {
        $site = trim((string) ($b['name'] ?? '')) !== '' ? (string) $b['name'] : 'Accounting';
        $desc = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($b['tagline'] ?? ''))), 0, 200);
        return ['title' => $site, 'desc' => $desc, 'head' => '<meta name="robots" content="noindex, nofollow" />'];
    }
}

if ( ! function_exists('serve_spa_shell'))
{
    /**
     * Send index.html with the mount path and the store's branding injected.
     *
     * NOTE: THE BASE PATH COMES FROM SCRIPT_NAME (set by the web server from its
     * own filesystem mapping), never from the URL the browser is on — deep
     * links would otherwise compute /admin/orders/api/v1 and every call from
     * that screen would go nowhere.
     *
     * NOTE: NO DATABASE. The shell must render when MySQL is down. Branding
     * comes from the cache file Store_model writes on every settings save. The
     * one exception is _shell_seo()'s read for product and category links,
     * which falls back to the store's defaults when the server is down.
     *
     * NOTE: every injected value is escaped for its context: colours are
     * validated to #rrggbb before reaching CSS, text is HTML-escaped, and the
     * boot JSON is encoded with JSON_HEX_TAG so a store name containing
     * "</script>" cannot end the script block.
     *
     * Every route that serves the shell — Home::index, Auth::spa,
     * Notfound::index — goes through here.
     */
    function serve_spa_shell()
    {
        $CI   =& get_instance();
        $path = FCPATH . 'index.html';

        if ( ! is_file($path)) {
            log_message('error', '[serve_spa_shell] index.html missing at ' . $path);
            show_error('Application shell not found.', 500);
            return;
        }

        $html = file_get_contents($path);

        $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '/index.php';
        $base   = str_replace('\\', '/', dirname($script));
        if ($base === '' || $base === '.' || $base === '/') $base = '/';
        elseif (substr($base, -1) !== '/')                  $base .= '/';

        $b       = _shell_branding();
        $brand   = isset($b['brand']) && is_array($b['brand']) ? $b['brand'] : [];
        $primary = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($brand['primary'] ?? '')) ? strtolower($brand['primary']) : '#0f766e';
        $accent  = preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($brand['accent'] ?? ''))  ? strtolower($brand['accent'])  : '#f59e0b';
        $radius  = max(0, min(24, (int) ($brand['radius'] ?? 10)));
        $theme   = in_array(($brand['theme'] ?? 'auto'), ['auto', 'light', 'dark'], TRUE) ? $brand['theme'] : 'auto';
        $font    = (string) ($brand['font'] ?? 'system');

        $fonts = [
            'inter'   => ['Inter',   '"Inter", system-ui, sans-serif'],
            'poppins' => ['Poppins', '"Poppins", system-ui, sans-serif'],
            'nunito'  => ['Nunito',  '"Nunito", system-ui, sans-serif'],
        ];
        $font_link = '';
        $font_css  = '';
        if (isset($fonts[$font])) {
            $font_link = '<link rel="preconnect" href="https://fonts.googleapis.com" />'
                       . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />'
                       . '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family='
                       . rawurlencode($fonts[$font][0]) . ':wght@400;500;600;700;800&amp;display=swap" />';
            $font_css  = '--font:' . $fonts[$font][1] . ';';
        }

        $brand_css = ':root{--brand:' . $primary . ';--brand-ink:' . _contrast_ink($primary)
                   . ';--accent:' . $accent . ';--accent-ink:' . _contrast_ink($accent)
                   . ';--radius:' . $radius . 'px;' . $font_css . '}';

        $name = htmlspecialchars((string) ($b['name'] ?? 'My Store'), ENT_QUOTES, 'UTF-8');
        $seo  = _shell_seo((string) $CI->uri->uri_string(), $b);

        $boot = json_encode($b, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                              | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

        $html = strtr($html, [
            '__GP_BASE__'             => $base,
            '__GP_TITLE__'            => $name,       // the app's name (home-screen title)
            '__GP_PAGE_TITLE__'       => htmlspecialchars($seo['title'], ENT_QUOTES, 'UTF-8'),
            '__GP_DESC__'             => htmlspecialchars($seo['desc'], ENT_QUOTES, 'UTF-8'),
            '<!--__GP_SEO__-->'       => $seo['head'],
            '__GP_THEME_COLOR__'      => $primary,
            '__GP_THEME_MODE__'       => $theme,
            '/*__GP_BRAND_CSS__*/'    => $brand_css,
            '<!--__GP_FONT__-->'      => $font_link,
            '/*__GP_BOOT__*/null'     => ($boot !== FALSE ? $boot : 'null'),
        ]);

        $CI->output
            ->set_status_header(200)
            ->set_content_type('text/html', 'utf-8')
            ->set_header('Cache-Control: no-store, must-revalidate')
            /* Marks the response as THE SHELL. Every unknown path answers 200
               with this document, so the service worker and the page router
               use the marker to tell "a fragment" from "no such fragment". */
            ->set_header('X-GP-Shell: 1')
            ->set_output($html);
    }
}
