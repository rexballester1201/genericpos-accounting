<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Setup.php — first run: the company, its chart of accounts, its first fiscal
 * year, and the first administrator
 *
 * GenericPOS Accounting
 *
 *   GET  /api/v1/setup   { needed, enabled, kinds, start_month, today, currency_code, currency_symbol }
 *   POST /api/v1/setup   { setup_key, company_name, kind, fy_start, currency_code, currency_symbol,
 *                          admin: { full_name, email, password, password_confirm } }
 *
 *   kind      business_corporation | business_sole_proprietorship | cooperative
 *             (Chart_templates). It picks the starting chart and the statements,
 *             and cannot be changed afterwards.
 *   fy_start  YYYY-MM-01, the first day of the first fiscal year. Its twelve
 *             months open at once; every later year follows on from it.
 *
 * ─── WHY A KEY, AND WHY IT REFUSES ONCE AN ADMIN EXISTS ───────────────────
 * A fresh deployment with an open "create the first administrator" form is
 * owned by whoever finds it first — and search engines and scanners find new
 * sites within hours. So setup needs BOTH: no administrator may exist yet, AND
 * the caller must present GP_SETUP_KEY from secrets.php (a value only someone
 * with access to the server can read). An empty key disables setup entirely.
 *
 * The check-and-create runs under a named lock so two simultaneous submissions
 * cannot both see "no admin yet" and both create one.
 *
 * NOTE: ALL OR NOTHING. The administrator, the chart, the settings and the
 * first year are written in one transaction. A failure half-way must not
 * leave an administrator behind without a chart: setup would then refuse to
 * run again (an administrator exists) while there was nothing to post to.
 */
class Setup extends CI_Controller
{
    const KIND_KEYS = ['business_corporation', 'business_sole_proprietorship', 'cooperative'];

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('User_model', 'users');
        require_once APPPATH . 'libraries/Chart_templates.php';
    }

    private function _key()
    {
        return gp_secret('GP_SETUP_KEY');
    }

    private function _enabled()
    {
        return (bool) $this->config->item('setup_enabled') && strlen($this->_key()) >= 12;
    }

    /** GET /api/v1/setup */
    public function status()
    {
        $needed = $this->users->count_admins() === 0;

        return json_response([
            'needed'          => $needed,
            'enabled'         => $needed && $this->_enabled(),
            'kinds'           => Chart_templates::labels(),
            'start_month'     => (int) $this->config->item('fiscal_year_start_month') ?: 1,
            'today'           => company_today(),
            'currency_code'   => (string) $this->config->item('currency_code'),
            'currency_symbol' => (string) $this->config->item('currency_symbol'),
        ], $needed ? 'Setup required' : 'Already set up');
    }

    /** POST /api/v1/setup */
    public function run()
    {
        rate_limit(ip_rate_key('setup'), 'setup');

        if ( ! $this->_enabled()) {
            return json_error('Setup is disabled. Set GP_SETUP_KEY in application/config/secrets.php on the server.', 403);
        }

        $in    = get_json_body();
        $admin = is_array($in['admin'] ?? NULL) ? $in['admin'] : [];

        /* One answer for a missing and a wrong key. */
        if ( ! hash_equals($this->_key(), (string) ($in['setup_key'] ?? ''))) {
            return json_invalid(['setup_key' => 'That setup key is not correct.']);
        }

        $this->load->model('Account_model', 'accounts');
        $this->load->model('Period_model', 'periods');
        $this->load->model('Settings_model', 'settings');

        $company = clean_line($in['company_name'] ?? '', 120);
        $kind    = (string) ($in['kind'] ?? '');
        $start   = trim((string) ($in['fy_start'] ?? ''));
        $year    = (int) substr($start, 0, 4);
        $name    = trim(preg_replace('/\s+/u', ' ', (string) ($admin['full_name'] ?? '')));
        $email   = mb_strtolower(trim((string) ($admin['email'] ?? '')));
        $pw      = (string) ($admin['password'] ?? '');
        $pw2     = (string) ($admin['password_confirm'] ?? '');

        $errors = [];
        if ($company === '')                                    $errors['company_name'] = 'Enter the company name.';
        if ( ! in_array($kind, self::KIND_KEYS, TRUE))          $errors['kind'] = 'Choose the kind of organisation.';
        if ( ! Period_model::valid_date($start) || substr($start, 8, 2) !== '01' || $year < 2000 || $year > 2100) {
            $errors['fy_start'] = 'Choose the month and year the first fiscal year starts.';
        }
        if ($name === '')                                       $errors['full_name'] = 'Enter your name.';
        if ( ! filter_var($email, FILTER_VALIDATE_EMAIL))       $errors['email'] = 'Enter a valid email address.';
        if ($err = $this->users->password_policy_error($pw))    $errors['password'] = $err;
        if ( ! hash_equals($pw, $pw2))                          $errors['password_confirm'] = 'Passwords do not match.';
        if ($errors) return json_invalid($errors);

        $got = $this->db->query('SELECT GET_LOCK(?, 5) AS g', ['gp_setup'])->row_array();
        if ( ! $got || (int) $got['g'] !== 1) return json_error('Setup is busy; try again.', 503);

        try {
            if ($this->users->count_admins() > 0) {
                return json_error('This ledger is already set up. Sign in instead.', 409);
            }
            if ($this->users->email_exists($email)) {
                return json_invalid(['email' => 'That email already has an account.']);
            }

            $tpl     = Chart_templates::get($kind);
            $user_id = 0;
            $seeded  = FALSE;

            $this->db->trans_begin();
            try {
                $user_id = $this->users->create([
                    'full_name'   => $name,
                    'email'       => $email,
                    'password'    => $pw,
                    'role'        => 'admin',
                    'created_via' => 'setup',
                ]);

                /* The owner proved control of the server, not of the mailbox — but
                   an owner locked out of password reset on day one helps nobody,
                   and the address is theirs by assertion of the server key. */
                $this->db->where('id', $user_id)->update('gp_users', ['is_email_verified' => 1]);

                /* A chart loaded some other way (a restore, the command line) is kept. */
                if ($this->accounts->count_all() === 0) {
                    $this->accounts->seed_template($tpl['accounts']);
                    $seeded = TRUE;
                }

                $values = ['store_name' => $company, 'store_email' => $email, 'fiscal_year_start_month' => (string) (int) substr($start, 5, 2)]
                        + $tpl['settings'];
                if ($seeded) {
                    foreach ($tpl['defaults'] as $k => $code) if ($code !== '') $values[$k] = $code;
                }
                $code = strtoupper(trim((string) ($in['currency_code'] ?? '')));
                if (preg_match('/^[A-Z]{3}$/', $code)) $values['currency_code'] = $code;
                $sym = trim((string) ($in['currency_symbol'] ?? ''));
                if ($sym !== '' && mb_strlen($sym) <= 6) $values['currency_symbol'] = $sym;

                $r = $this->settings->set_many($values, $user_id, TRUE);
                if ($r['errors']) throw new RuntimeException('settings refused: ' . json_encode($r['errors']));

                if ((int) $this->db->count_all('gp_fiscal_years') === 0) {
                    list(, $err) = $this->periods->create_year($start, $user_id);
                    if ($err !== '') throw new RuntimeException('first fiscal year: ' . $err);
                }

                if ( ! log_admin_action(['user_id' => $user_id], 'setup.complete', 'user', $user_id,
                    ['company' => $company, 'kind' => $kind, 'fy_start' => $start, 'chart' => $seeded ? 'seeded' : 'kept'])) {
                    throw new RuntimeException('the audit log refused the setup record');
                }
                $this->db->trans_commit();
            } catch (Throwable $t) {
                $this->db->trans_rollback();
                log_message('error', '[Setup] ' . $t->getMessage());
                $this->settings->refresh_store_cache();
                return json_error('Setup could not finish, and nothing was saved. The server log has the reason.', 500);
            }
            $this->settings->refresh_store_cache();

            $user   = $this->users->find_by_id($user_id);
            $tokens = issue_session_tokens($user);

            return json_response([
                'access_token'  => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'user'          => $this->users->public_fields($user),
            ], 'Your books are ready.', 201);
        } finally {
            try { $this->db->query('SELECT RELEASE_LOCK(?)', ['gp_setup']); } catch (Throwable $e) {}
        }
    }
}
