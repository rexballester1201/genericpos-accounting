<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Tools.php — command-line administration. CLI ONLY.
 *
 * GenericPOS Accounting
 *
 *   php index.php tools                                   usage
 *   php index.php tools create_admin <email> [password]   create (or promote) an administrator;
 *                                                         prints a generated password once
 *   php index.php tools create_user <email> <role> [password]
 *   php index.php tools cache                             rebuild the shell's branding cache
 *   php index.php tools cron                              the scheduled job: recurring entries and
 *                                                         housekeeping (run it every 15 minutes)
 *   php index.php tools seed_demo                         demo company and books (development only)
 *   php index.php tools trial_balance [date] [kind]       print a trial balance (kind: unadjusted |
 *                                                         adjusted | post_closing)
 *
 * NOTE: refuses over HTTP. Everything here bypasses the checks the web path
 * enforces, which is only acceptable for someone who already has a shell on
 * the server.
 */
class Tools extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        if ( ! is_cli()) {
            show_404();
            exit;
        }
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('User_model', 'users');
    }

    private function out($s = '') { fwrite(STDOUT, $s . PHP_EOL); }
    private function fail($s)     { fwrite(STDERR, 'ERROR: ' . $s . PHP_EOL); exit(1); }

    public function index()
    {
        $this->out('GenericPOS Accounting tools');
        $this->out('  php index.php tools create_admin <email> [password]');
        $this->out('  php index.php tools create_user <email> <viewer|bookkeeper|accountant|admin> [password]');
        $this->out('  php index.php tools cache');
        $this->out('  php index.php tools cron                 (the scheduled job; every 15 minutes)');
        $this->out('  php index.php tools seed_demo            (development only, empty database)');
        $this->out('  php index.php tools trial_balance [YYYY-MM-DD] [unadjusted|adjusted|post_closing]');
    }

    public function create_admin($email = '', $password = '')
    {
        $this->create_user($email, 'admin', $password);
    }

    public function create_user($email = '', $role = '', $password = '')
    {
        $email = mb_strtolower(trim(urldecode((string) $email)));
        $role  = (string) $role;
        if ( ! filter_var($email, FILTER_VALIDATE_EMAIL) || ! in_array($role, User_model::ROLES, TRUE)) {
            $this->fail('usage: tools create_user <email> <' . implode('|', User_model::ROLES) . '> [password]');
        }

        $password  = urldecode((string) $password);
        $generated = FALSE;
        if ($password === '') {
            $password  = substr(strtr(base64_encode(random_bytes(12)), '+/', 'Kx'), 0, 14) . '7a';
            $generated = TRUE;
        }
        if ($err = $this->users->password_policy_error($password)) $this->fail($err);

        $user = $this->users->find_by_email($email);
        if ($user) {
            $this->users->set_role($user['id'], $role);
            $this->users->admin_set_password($user['id'], $password);
            $this->users->set_state($user['id'], 'active');
            $this->out('Updated account #' . $user['id'] . ' <' . $email . '>: role ' . $role . ', password set.');
            $id = (int) $user['id'];
        } else {
            $id = $this->users->create([
                'email' => $email, 'full_name' => ucfirst($role), 'password' => $password,
                'role' => $role, 'created_via' => 'cli',
            ]);
            $this->db->where('id', $id)->update('gp_users', ['is_email_verified' => 1]);
            $this->out('Created ' . $role . ' #' . $id . ' <' . $email . '>.');
        }

        /* Nobody signed in did this: it is recorded as the system, not as the
           account it created. */
        log_admin_action(GP_SYSTEM_ACTOR, 'cli.create_user', 'user', $id, ['email' => $email, 'role' => $role, 'via' => 'command line']);
        if ($generated) $this->out('Password (shown once — store it now): ' . $password);
    }

    public function cache()
    {
        $this->load->model('Store_model', 'store');
        $ok = $this->store->refresh_public_cache();
        $this->out($ok ? 'Branding cache written: ' . Store_model::cache_path() : 'Could not write the cache.');
    }

    /**
     * The scheduled job (README → "The scheduled job"):
     *   · recurring saved entries that are due become DRAFTS for their owners,
     *     who are told in their notifications (Template_model::run_due);
     *   · housekeeping: rate-limit windows, old login attempts and expired
     *     refresh tokens, spent password-reset links, and notifications read
     *     more than retain_notification_days ago. The audit log is never pruned.
     * One run at a time: a second one finds the named lock taken and stops.
     * Exits 1 when a part failed, so the scheduler's log shows it.
     */
    public function cron()
    {
        if ((int) $this->db->query("SELECT GET_LOCK('acc_cron', 0) AS l")->row()->l !== 1) {
            $this->out('Another run is still going; nothing done.');
            return;
        }

        $failed = FALSE;
        $today  = company_today();
        $this->out('Scheduled job, ' . gmdate('Y-m-d H:i:s') . ' UTC (company date ' . $today . ')');

        try {
            $this->load->model('Template_model', 'templates');
            $r = $this->templates->run_due($today);
            foreach ($r['lines'] as $l) $this->out('  ' . $l);
            $this->out('Recurring entries: ' . $r['made'] . ' drafted' . ($r['failed'] ? ', ' . $r['failed'] . ' could not be' : '') . '.');
        } catch (Throwable $t) {
            $failed = TRUE;
            log_message('error', '[cron] recurring entries: ' . $t->getMessage());
            fwrite(STDERR, 'ERROR: recurring entries: ' . $t->getMessage() . PHP_EOL);
        }

        try {
            $auth = $this->users->prune_auth_tables();
            $this->load->model('PasswordReset_model', 'resets');
            $resets = $this->resets->prune();
            $days = max(30, (int) ($this->config->item('retain_notification_days') ?: 365));
            $this->db->query('DELETE FROM gp_notifications WHERE is_read = 1 AND created_at < ? LIMIT 5000', [date('Y-m-d H:i:s', time() - $days * 86400)]);
            $read = $this->db->affected_rows();
            $this->out('Housekeeping: ' . json_encode(['auth' => $auth, 'password_resets' => $resets, 'read_notifications' => $read]));
        } catch (Throwable $t) {
            $failed = TRUE;
            log_message('error', '[cron] housekeeping: ' . $t->getMessage());
            fwrite(STDERR, 'ERROR: housekeeping: ' . $t->getMessage() . PHP_EOL);
        }

        $this->db->query("SELECT RELEASE_LOCK('acc_cron')");
        if ($failed) exit(1);
    }

    /**
     * A demo company with a year of books (Demo_seed). Development only, and
     * only into an EMPTY ledger: it never mixes invented entries with real ones.
     */
    public function seed_demo()
    {
        if (ENVIRONMENT !== 'development') {
            $this->fail('seed_demo only runs in development (set CI_ENV=development).');
        }
        $this->load->library('Demo_seed', NULL, 'demo');
        try {
            foreach ($this->demo->run() as $line) $this->out($line);
        } catch (Throwable $t) {
            $this->fail($t->getMessage());
        }
    }

    public function trial_balance($date = '', $kind = 'adjusted')
    {
        $date = $date !== '' ? (string) $date : date('Y-m-d');
        $this->load->model('Ledger_model', 'ledger');
        $tb = $this->ledger->trial_balance($date, (string) $kind);

        $m = function ($c) { return $c ? number_format($c / 100, 2) : ''; };
        $this->out('Trial balance (' . $tb['kind'] . ') as of ' . $tb['as_of'] . ($tb['fiscal_year'] ? ' — ' . $tb['fiscal_year']['name'] : ''));
        $this->out(str_repeat('-', 96));
        foreach ($tb['rows'] as $r) {
            $this->out(sprintf('%-8s %-52s %16s %16s', $r['code'], mb_substr($r['name'], 0, 52), $m($r['debit_cents']), $m($r['credit_cents'])));
        }
        if ($tb['unclosed_prior_cents'] !== 0) {
            $p = $tb['unclosed_prior_cents'];
            $this->out(sprintf('%-8s %-52s %16s %16s', '', 'Net income of earlier years not yet closed', $m(max($p, 0)), $m(max(-$p, 0))));
        }
        $this->out(str_repeat('-', 96));
        $this->out(sprintf('%-61s %16s %16s', 'TOTAL', $m($tb['total_debit_cents']), $m($tb['total_credit_cents'])));
        $this->out($tb['balanced'] ? 'Balanced.' : 'NOT BALANCED — difference ' . $m(abs($tb['total_debit_cents'] - $tb['total_credit_cents'])));
    }
}
