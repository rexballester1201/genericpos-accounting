<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Auth.php — sign-in, session, password reset, email verification, and the
 * SPA shell fallback
 *
 * GenericPOS Accounting
 *
 *   POST /api/v1/auth/login               identifier (email | username | mobile) + password
 *   POST /api/v1/auth/refresh             rotate the refresh token, new access token
 *   POST /api/v1/auth/logout              revoke the refresh token
 *   GET  /api/v1/auth/me
 *   GET  /api/v1/auth                     capability probe
 *   POST /api/v1/auth/forgot-password     GET|POST /api/v1/auth/reset-password
 *   POST /api/v1/auth/verify-email/send   GET /api/v1/auth/verify-email
 *
 * There is no self sign-up: an administrator adds every user (admin/Staff.php),
 * and setup creates the first one (Setup.php).
 *
 * ─── THE ENUMERATION RULE ─────────────────────────────────────────────────
 * Sign-in must not reveal WHICH accounts exist: the same message, the same
 * status and roughly the same time on every failure path.
 */
class Auth extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->config->load('app', FALSE, TRUE);

        /* The database is opened HERE, before the SettingsOverride hook runs
           (post_controller_constructor), so sign-in obeys the lockout and
           password rules set in Settings. Opened later, inside each method,
           the hook finds no connection and the config-file values win. The
           shell (spa) is the one path that must render with the database
           down, so it is left out. */
        if ($this->router->method !== 'spa') $this->load->database();
    }

    private function pw_min()
    {
        return (int) ($this->config->item('password_min_length') ?: 8);
    }

    // =========================================================================
    // SPA SHELL FALLBACK   (target of the (.+) catch-all in routes.php)
    // =========================================================================

    /**
     * Serves index.html for any non-API, non-file path so client-side deep
     * links survive a refresh. No database on this path, deliberately.
     *
     * NOTE: A COMMAND LINE NEVER GETS THE SHELL. CI3 routes CLI invocations
     * through the same table, so a mistyped cron command falls through to here.
     * Printing 90KB of HTML and exiting 0 is the worst shape a cron failure can
     * take — cron reports success while nothing runs. Refused loudly, non-zero.
     */
    public function spa()
    {
        $uri = (string) $this->uri->uri_string();

        if (is_cli()) {
            fwrite(STDERR, "ERROR: '" . $uri . "' is not a command.\n"
                . "It fell through to the SPA catch-all. Run  php index.php tools  for the list.\n");
            exit(2);
        }

        if (strpos($uri, 'api/') === 0) {
            log_message('error', '[Auth::spa] Unrouted API path reached the SPA catch-all: /' . $uri);
            return json_error('Endpoint not found.', 404);
        }

        serve_spa_shell();
    }

    // =========================================================================
    // CAPABILITY PROBE
    // =========================================================================

    /** GET /api/v1/auth */
    public function index()
    {
        return json_response([
            'registration'         => (bool) $this->config->item('feature_registration'),
            'guest_checkout'       => (bool) $this->config->item('guest_checkout'),
            'password_min_length'  => $this->pw_min(),
            'default_country_code' => (string) $this->config->item('default_country_code'),
        ], 'Auth capabilities');
    }

    // =========================================================================
    // SIGN IN
    // =========================================================================

    /**
     * POST /api/v1/auth/login   { identifier | username, password }
     */
    public function username_login()
    {
        require_method('POST');
        $this->load->database();
        $this->load->model('User_model', 'users');

        $in         = get_json_body();
        $identifier = trim((string) ($in['identifier'] ?? $in['username'] ?? ''));
        $password   = (string) ($in['password'] ?? '');

        if ($identifier === '' || $password === '') {
            return json_error('Enter your email, username or mobile number, and your password.', 400);
        }

        $ip       = $this->input->ip_address();
        $ip_scope = 'ip:' . $ip;

        /* IP lockout BEFORE any lookup of the identifier. */
        if ($wait = $this->users->lockout_remaining($ip_scope)) return $this->_locked_out($wait);

        $user = $this->users->find_by_identifier($identifier);

        /* Unknown identifier — or an account with no password yet (created at
           the till). Same CPU, same answer as a wrong password. */
        if ($user === NULL || empty($user['password_hash'])) {
            $this->users->dummy_verify();
            $this->users->record_failure($ip_scope);
            return $this->_fail_login();
        }

        $acct_scope = 'acct:' . $user['id'];

        /* Account lockout: the answer depends on the password. The correct
           password learns how long to wait (and nothing else happens); a wrong
           one gets the identical 401 an unknown identifier gets. So an attacker
           cannot use lockout to learn which accounts exist, and cannot lock the
           real owner into believing their password is wrong. */
        if ($acct_wait = $this->users->lockout_remaining($acct_scope)) {
            if ($this->users->verify_password($user, $password)) return $this->_locked_out($acct_wait);
            $this->users->record_failure($ip_scope);
            return $this->_fail_login();
        }

        if ( ! $this->users->verify_password($user, $password)) {
            $this->users->record_failure($ip_scope);
            $this->users->record_failure($acct_scope);
            return $this->_fail_login();
        }

        /* State only after the password verifies — "suspended" to someone who
           did not prove ownership would confirm the account exists. */
        if ($user['account_state'] !== 'active') {
            return json_error('This account is not active. Please contact the store.', 403);
        }

        $this->users->clear_failures($ip_scope);
        $this->users->clear_failures($acct_scope);
        $this->users->touch_login($user['id'], $ip);

        return json_response($this->_session_payload($user, FALSE), 'Signed in');
    }

    // =========================================================================
    // SESSION
    // =========================================================================

    /** POST /api/v1/auth/refresh   { refresh_token } */
    public function token_refresh()
    {
        require_method('POST');
        $this->load->database();
        $this->load->model('User_model', 'users');

        $in    = get_json_body();
        $token = (string) ($in['refresh_token'] ?? '');
        if ($token === '') return json_error('Missing refresh token.', 400);

        /* Rotation: the presented token is spent here, atomically — two
           requests with the same token cannot both get a session. */
        $spent = $this->users->consume_refresh_token($token);
        $user  = $spent['user'];

        if ($spent['reuse']) {
            /* A token already spent, replayed: someone kept a copy of it. Every
               session of the account has just ended; the owner signs in again
               and the copy is worthless. */
            log_admin_action(GP_SYSTEM_ACTOR, 'auth.refresh_reuse', 'user', (int) $user['id'], ['ip' => $this->input->ip_address()]);
            return json_error('Your session was ended for safety because a sign-in token was used twice. Please sign in again.', 401);
        }
        if ($user === NULL || $user['account_state'] !== 'active') {
            return json_error('Session expired. Please sign in again.', 401);
        }

        /* Metered AFTER resolution, keyed on the user — metering garbage tokens
           by IP would let anyone burn a real customer's budget. */
        rate_limit((int) $user['id'], 'token_refresh');

        return json_response($this->_session_payload($user, FALSE), 'Session refreshed');
    }

    /** POST /api/v1/auth/logout   { refresh_token? } — always 200 */
    public function logout()
    {
        require_method('POST');
        $this->load->database();
        $this->load->model('User_model', 'users');

        $in    = get_json_body();
        $token = (string) ($in['refresh_token'] ?? '');
        if ($token !== '') $this->users->revoke_refresh_token($token);

        return json_response(NULL, 'Signed out');
    }

    /** GET /api/v1/auth/me */
    public function me()
    {
        require_method('GET');
        $this->load->database();
        $this->load->model('User_model', 'users');

        $claims = auth_check();
        $user   = $this->users->find_by_id($claims['user_id']);
        if ($user === NULL) return json_error('Account not found.', 404);

        return json_response(['user' => $this->users->public_fields($user), 'pos' => $claims['pos'] ?? NULL], 'Current user');
    }

    // =========================================================================
    // PASSWORD RESET
    // =========================================================================

    /**
     * POST /api/v1/auth/forgot-password   { email } — 200 always.
     *
     * The answer is sent BEFORE the account is looked up, so the elapsed time
     * cannot reveal whether it exists: everything account-dependent happens
     * after the client already has its reply.
     */
    public function forgot_password()
    {
        require_method('POST');
        $this->load->database();
        $this->load->model('User_model', 'users');
        $this->load->model('PasswordReset_model', 'resets');

        $in    = get_json_body();
        $email = trim((string) ($in['email'] ?? $in['identifier'] ?? ''));

        if ($email === '')                              return json_error('Enter the email address on your account.', 400);
        if ( ! filter_var($email, FILTER_VALIDATE_EMAIL)) return json_error('Enter a valid email address.', 400);

        rate_limit(ip_rate_key('pwreset'), 'password_reset_ip');

        json_response_then_continue(NULL,
            'If that matches an account, we have sent a password reset link to its email address.');

        // ── Invisible to the caller from here ─────────────────────────────
        $user = $this->users->find_by_email($email);
        if ($user === NULL || $user['account_state'] !== 'active') return;

        $max = (int) ($this->config->item('rate_password_reset') ?: 3);
        $win = (int) ($this->config->item('rate_limit_window_s') ?: 3600);
        if ($this->resets->requests_since($user['id'], time() - $win) >= $max) return;

        $this->load->library('Mailer_lib', NULL, 'mailer');
        list($ready, $why) = $this->mailer->readiness();
        if ( ! $ready) {
            log_message('error', '[Auth::forgot_password] mailer not ready, no token issued: ' . $why);
            return;
        }

        $issued = $this->resets->issue($user['id'], $this->input->ip_address(), $this->input->user_agent());
        $ttl    = (int) ($this->config->item('password_reset_expiry_s') ?: 3600);

        /* base_url() — resolved through the host allow-list — never the Host
           header, which would let a forged request mail a victim a link to the
           attacker's domain. */
        $link = rtrim(base_url(), '/') . '/reset-password?token=' . rawurlencode($issued['token']);

        $res = $this->mailer->send_password_reset($user['email'], $link, $ttl);
        if ( ! $res['ok']) {
            $this->resets->void_token($issued['token']);
            log_message('error', '[Auth::forgot_password] send failed for account ' . (int) $user['id'] . ': ' . $res['error']);
        }
    }

    /** GET /api/v1/auth/reset-password?token=… — is this link still good? */
    public function reset_password_check()
    {
        require_method('GET');
        $this->load->database();
        $this->load->model('PasswordReset_model', 'resets');

        $row = $this->resets->find_valid((string) $this->input->get('token'));

        return json_response(['valid' => (bool) $row, 'password_min_length' => $this->pw_min()],
            $row ? 'Link is valid' : 'Link is not valid');
    }

    /** POST /api/v1/auth/reset-password   { token, password, password_confirm } */
    public function reset_password()
    {
        require_method('POST');
        $this->load->database();
        $this->load->model('User_model', 'users');
        $this->load->model('PasswordReset_model', 'resets');

        $in      = get_json_body();
        $token   = (string) ($in['token'] ?? '');
        $pw      = (string) ($in['password'] ?? '');
        $confirm = (string) ($in['password_confirm'] ?? '');

        if ($token === '') return json_error('This reset link is not valid. Request a new one.', 400);

        rate_limit(ip_rate_key('pwreset'), 'password_reset_ip');

        /* Validate BEFORE claiming, so a typo does not burn a single-use link. */
        if ($err = $this->users->password_policy_error($pw)) return json_invalid(['password' => $err]);
        if ( ! hash_equals($pw, $confirm))                   return json_invalid(['password_confirm' => 'Passwords do not match.']);

        $row = $this->resets->claim($token, $this->input->ip_address());
        if ($row === NULL) {
            return json_error('This reset link is not valid or has already been used. Request a new one.', 400);
        }

        $user = $this->users->find_by_id($row['user_id']);
        if ($user === NULL)                      return json_error('This reset link is not valid. Request a new one.', 400);
        if ($user['account_state'] !== 'active') return json_error('This account is not active. Please contact the store.', 403);

        $res = $this->users->set_password($user['id'], $pw, $confirm);
        if ( ! $res['ok']) return json_invalid($res['errors']);

        $this->resets->void_outstanding($user['id'], 'password reset completed');

        json_response_then_continue(NULL, 'Your password has been changed. Sign in with your new password.');

        if ($this->config->item('password_change_notify') && ! empty($user['email'])) {
            $this->load->library('Mailer_lib', NULL, 'mailer');
            $this->mailer->send_password_changed($user['email'], $user['username'], date('Y-m-d H:i:s'), $this->input->ip_address());
        }
    }

    // =========================================================================
    // EMAIL VERIFICATION
    // =========================================================================

    /** POST /api/v1/auth/verify-email/send */
    public function verify_email_send()
    {
        require_method('POST');
        $this->load->database();
        $this->load->model('User_model', 'users');

        $claims  = auth_check();
        $user_id = (int) $claims['user_id'];
        $user    = $this->users->find_by_id($user_id);
        if ($user === NULL) return json_error('Account not found.', 404);

        if ((int) $user['is_email_verified']) {
            return json_response(['verified' => TRUE], 'That address is already confirmed.');
        }

        $email = trim((string) $user['email']);
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return json_error('Your account has no usable email address.', 422);
        }

        rate_limit($user_id, 'email_verify_send');

        $this->load->library('Mailer_lib', NULL, 'mailer');
        list($ready, $why) = $this->mailer->readiness();
        if ( ! $ready) {
            log_message('error', '[Auth::verify_email_send] mailer not ready: ' . $why);
            return json_error('Confirmation email cannot be sent right now. Try again later.', 503);
        }

        $ttl   = (int) ($this->config->item('email_verify_expiry_s') ?: 86400);
        $token = bin2hex(random_bytes(32));
        $this->users->set_email_verify_token($user_id, hash('sha256', $token), time() + $ttl);

        $link = rtrim(base_url(), '/') . '/verify-email?token=' . rawurlencode($token);
        $res  = $this->mailer->send_email_verify($email, $link, $ttl);

        if ( ! $res['ok']) {
            $this->users->clear_email_verify_token($user_id);
            log_message('error', '[Auth::verify_email_send] send failed for user ' . $user_id . ': ' . $res['error']);
            return json_error('Could not send the confirmation email. Try again shortly.', 503);
        }

        return json_response(['verified' => FALSE], 'Confirmation link sent. Check your email.');
    }

    /**
     * GET /api/v1/auth/verify-email?token=… — no session required (the link is
     * opened from a mailbox, often on another device). Notice emails go only
     * to a confirmed address (Notification_model).
     */
    public function email_verify()
    {
        require_method('GET');
        $this->load->database();
        $this->load->model('User_model', 'users');

        $token = (string) $this->input->get('token');
        if ($token === '' || ! preg_match('/^[0-9a-f]{64}$/', $token)) {
            return json_error('This confirmation link is not valid. Request a new one.', 400);
        }

        $user_id = $this->users->claim_email_verify(hash('sha256', $token));
        if ($user_id <= 0) {
            return json_error('This confirmation link is not valid or has already been used. Request a new one.', 400);
        }

        try {
            $this->load->model('Notification_model', 'notify');
            $this->notify->push($user_id, 'account.email_verified', 'Email address confirmed',
                'Notifications about your entries can now reach you by email as well as here.',
                '/account');
        } catch (Throwable $e) {
            log_message('error', '[Auth::email_verify] notify failed: ' . $e->getMessage());
        }

        return json_response(['verified' => TRUE], 'Your email address is confirmed.');
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    private function _session_payload($user, $is_new)
    {
        $tokens = issue_session_tokens($user);

        return [
            'access_token'  => $tokens['access_token'],
            'refresh_token' => $tokens['refresh_token'],
            'user'          => $this->users->public_fields($user),
            'is_new_user'   => (bool) $is_new,
        ];
    }

    /** One message, one status, for every sign-in failure. */
    private function _fail_login()
    {
        return json_error('Those credentials do not match an account.', 401, ['code' => 'BAD_CREDENTIALS']);
    }

    private function _locked_out($seconds)
    {
        $minutes = max(1, (int) ceil($seconds / 60));
        $this->output->set_header('Retry-After: ' . (int) $seconds);
        return json_error('Too many sign-in attempts. Try again in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.', 429);
    }
}
