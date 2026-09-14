<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Maildiag.php — is outbound mail working, and where does it stop?
 *
 * GenericPOS · CLI ONLY.
 *
 *     php index.php maildiag
 *     php index.php maildiag send you@example.com
 *
 * ─── WHY THIS EXISTS ──────────────────────────────────────────────────────
 * "The app says the link was sent and nothing arrived" has at least four
 * different causes, and the application log distinguishes none of them:
 *
 *   1. the mailer refused before trying      — logged, visible
 *   2. PHP mail() returned false             — logged, visible
 *   3. mail() returned TRUE and Exim queued it, then the receiving server
 *      rejected or silently discarded it     — INVISIBLE to PHP
 *   4. it was delivered and went to spam     — INVISIBLE to PHP
 *
 * PHP hands the message to the local MTA and the call returns the moment the
 * MTA accepts it. Everything after that happens minutes later in Exim, in a
 * log this application cannot read. So a green "sent" in the app proves
 * handoff and nothing more.
 *
 * This command settles 1 and 2 with certainty and tells you exactly where to
 * look for 3 and 4. It does not guess.
 *
 * ─── IT PRINTS NO SECRETS ─────────────────────────────────────────────────
 * The SMTP password is never read here and never displayed. Whether one is
 * SET is reported; the value is not.
 */
class Maildiag extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();

        /* CLI only. Over HTTP this would report the mail configuration to
           anyone who found the URL, and "which transport, which host, which
           from-address" is reconnaissance. */
        if ( ! is_cli()) show_404();

        $this->config->load('app', FALSE, TRUE);
        $this->load->helper('secrets');
    }

    private function out($s = '') { fwrite(STDOUT, $s . PHP_EOL); }

    private function row($label, $value)
    {
        $this->out('  ' . str_pad($label, 26) . $value);
    }

    /** php index.php maildiag */
    public function index()
    {
        $this->out('');
        $this->out('GenericPOS — mail diagnostics');
        $this->out(str_repeat('=', 62));
        $this->out('');

        // ── 1. What the app is configured to do ──────────────────────────
        $transport = strtolower(trim((string) $this->config->item('mail_transport')));
        $from      = trim((string) $this->config->item('mail_from_email'));

        $this->out('CONFIGURATION');
        $this->row('transport', $transport ?: '(unset)');
        $this->row('from address', $from ?: '(unset)');
        $this->row('reply-to', trim((string) $this->config->item('mail_reply_to')) ?: '(unset)');
        $this->row('allow log transport', $this->config->item('mail_allow_log_transport') ? 'TRUE' : 'FALSE');

        if ($transport === 'smtp') {
            $this->row('smtp host', trim((string) $this->config->item('mail_smtp_host')));
            $this->row('smtp port', (string) $this->config->item('mail_smtp_port'));
            $this->row('smtp crypto', trim((string) $this->config->item('mail_smtp_crypto')));
            /* SET or not — never the value. */
            $pw = (string) gp_secret('GP_SMTP_PASSWORD', '');
            $this->row('smtp password', $pw === '' ? 'NOT SET' : 'set (' . strlen($pw) . ' chars)');
        }
        $this->out('');

        // ── 2. Can PHP hand mail off at all? ─────────────────────────────
        $this->out('PHP');
        $this->row('mail() available', function_exists('mail') ? 'yes' : 'NO — disabled');
        $sp = @ini_get('sendmail_path');
        $this->row('sendmail_path', $sp !== FALSE && $sp !== '' ? $sp : '(empty)');
        $this->out('');

        // ── 3. Does the mailer consider itself usable? ───────────────────
        $this->load->library('Mailer_lib', NULL, 'mailer');
        list($ready, $why) = $this->mailer->readiness();

        $this->out('MAILER');
        $this->row('readiness', $ready ? 'READY' : 'NOT READY');
        if ( ! $ready) $this->row('reason', $why);
        $this->out('');

        // ── 4. The part PHP cannot answer ────────────────────────────────
        $this->out('WHAT THIS CANNOT TELL YOU');
        $this->out('  Whether the message was DELIVERED. PHP hands it to the local mail');
        $this->out('  server and returns as soon as that server accepts it. Acceptance is');
        $this->out('  not delivery — the receiving side may reject or silently discard it');
        $this->out('  minutes later, and none of that reaches this application.');
        $this->out('');
        $this->out('  For that, read the mail server\'s own record:');
        $this->out('    cPanel -> Email -> Track Delivery');
        $this->out('  It shows what Exim did with each message and, on a refusal, the');
        $this->out('  receiving server\'s reply verbatim.');
        $this->out('');

        $this->out('  Send a test:  php index.php maildiag send');
        $this->out('                (goes to the admin account; override with');
        $this->out('                 MAILDIAG_TO=you@example.com)');
        $this->out('');

        exit($ready ? 0 : 1);
    }

    /**
     * php index.php maildiag send
     *
     * Sends one plain message and reports what the handoff returned.
     *
     * ─── THE ADDRESS IS NOT AN ARGUMENT, AND CANNOT BE ────────────────────
     * CI3 filters every URI segment against permitted_uri_chars, which is
     * 'a-z 0-9~%.:_\-' — no '@'. On the command line the arguments ARE the
     * URI, so 'maildiag send you@example.com' is rejected during routing,
     * before this class is even constructed, with a message about URI
     * characters that says nothing about email.
     *
     * Widening that filter globally to let one diagnostic take an argument
     * would trade a real defence for a convenience. So the target is
     * resolved instead:
     *
     *   1. the MAILDIAG_TO environment variable, if set
     *   2. otherwise the first ADMIN account's address
     *
     * Which means the common case needs no argument at all — and testing
     * against a real account is what you actually want to know about.
     */
    public function send()
    {
        $to     = trim((string) getenv('MAILDIAG_TO'));
        $source = 'MAILDIAG_TO';

        if ($to === '') {
            $this->load->database();
            $row = $this->db->select('email, username')
                            ->where('role', 'admin')
                            ->where('email !=', '')
                            ->order_by('id', 'ASC')->limit(1)
                            ->get('gp_users')->row_array();
            if ($row) {
                $to     = trim((string) $row['email']);
                $source = 'admin account @' . $row['username'];
            }
        }

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->out('');
            $this->out('No usable address to send to.');
            $this->out('');
            $this->out('  Set one for this run:');
            $this->out('    MAILDIAG_TO=you@example.com php index.php maildiag send');
            $this->out('');
            $this->out('  Or give the admin account an email address and run it bare.');
            $this->out('');
            exit(2);
        }

        $this->load->library('Mailer_lib', NULL, 'mailer');
        list($ready, $why) = $this->mailer->readiness();

        $this->out('');
        if ( ! $ready) {
            $this->out('REFUSED before sending: ' . $why);
            $this->out('');
            $this->out('Nothing was handed to the mail server. Fix the configuration first:');
            $this->out('  php index.php maildiag');
            $this->out('');
            exit(1);
        }

        $store   = trim((string) $this->config->item('store_name')) ?: 'GenericPOS';
        $stamp   = gmdate('Y-m-d H:i:s') . ' UTC';
        $subject = $store . ' mail test ' . substr(bin2hex(random_bytes(3)), 0, 6);

        $text = "This is a delivery test from {$store}.\r\n\r\n"
              . "Sent: {$stamp}\r\n"
              . "Transport: " . $this->mailer->transport() . "\r\n\r\n"
              . "If you are reading this, outbound mail reaches this address.\r\n"
              . "If it landed in spam, the domain needs SPF and DKIM.\r\n";

        $t0  = microtime(TRUE);
        $res = $this->mailer->send($to, $subject, $text);
        $ms  = (int) round((microtime(TRUE) - $t0) * 1000);

        $this->out('  to         ' . $to . '  (from ' . $source . ')');
        $this->out('  subject    ' . $subject);
        $this->out('  transport  ' . $this->mailer->transport());
        $this->out('  took       ' . $ms . 'ms');
        $this->out('');

        if (empty($res['ok'])) {
            $this->out('HANDOFF FAILED — the mail server did NOT accept it.');
            $this->out('  ' . (string) ($res['error'] ?? 'no reason given'));
            $this->out('');
            $this->out('This is an application or server configuration problem, not a');
            $this->out('deliverability one. The message never left the machine.');
            $this->out('');
            exit(1);
        }

        $this->out('HANDOFF OK — the local mail server accepted the message.');
        $this->out('');
        $this->out('THAT IS NOT THE SAME AS DELIVERED. What happens next:');
        $this->out('');
        $this->out('  1. Check the inbox, then the SPAM folder.');
        $this->out('  2. cPanel -> Email -> Track Delivery, and find this subject:');
        $this->out('       ' . $subject);
        $this->out('     Its Result column is the authoritative answer.');
        $this->out('');
        $this->out('  If Track Delivery says delivered and it is not in the inbox, it was');
        $this->out('  filtered — the domain needs SPF and DKIM. If it says deferred or');
        $this->out('  bounced, the receiving server\'s own words are shown there.');
        $this->out('');

        exit(0);
    }
}
