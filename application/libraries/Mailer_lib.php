<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Mailer_lib.php — outbound email
 *
 * GenericPOS · CodeIgniter 3.1.9
 *
 * Wraps CI3's Email library with the project's configuration, TLS handling and
 * a development transport. Configured in app.php Section P; the SMTP
 * password comes from secrets.php via gp_secret().
 *
 * ─── THIS IS AN AUTHENTICATION CHANNEL ────────────────────────────────────
 * The mail this class sends carries password-reset links — bearer credentials
 * that set a password without knowing the old one. Two consequences shape the
 * whole class:
 *
 *   1. FAILURES ARE REPORTED, NEVER SWALLOWED. send() returns ok=FALSE with a
 *      reason. A mailer that logs quietly and returns void turns "SMTP is
 *      misconfigured" into "users cannot reset their password and nobody
 *      knows why" — and the reset endpoint could not void the token it had
 *      already issued.
 *
 *   2. THE ERROR TEXT IS FOR THE OPERATOR, NOT THE MEMBER. It names hosts and
 *      SMTP responses. Callers must not pass it through to an HTTP response;
 *      see Auth::forgot_password(), which reports the same sentence whatever
 *      happens.
 *
 * ─── ON TLS ───────────────────────────────────────────────────────────────
 * CI3's Email opens its socket with fsockopen() and passes no stream context,
 * so certificate options cannot be handed to it directly. fsockopen() does
 * consult PHP's DEFAULT stream context, so _with_tls_defaults() sets that
 * around the send and restores it afterwards. See mail_ca_bundle.
 */
class Mailer_lib
{
    /** Subdirectory of application/logs/ used by the 'log' transport. */
    const LOG_DIR = 'mail';

    protected $CI;

    protected $transport;
    protected $allow_log;
    protected $from_email;
    protected $from_name;
    protected $reply_to;
    protected $ca_bundle;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->CI->config->load('app', FALSE, TRUE);
        $this->CI->load->helper('secrets');

        $c = $this->CI->config;

        $this->transport  = strtolower(trim((string) $c->item('mail_transport'))) ?: 'log';
        $this->allow_log  = (bool) $c->item('mail_allow_log_transport');
        $this->from_email = trim((string) $c->item('mail_from_email'));
        $this->from_name  = trim((string) $c->item('mail_from_name'))
                         ?: (trim((string) $c->item('store_name')) ?: 'GenericPOS');
        $this->reply_to   = trim((string) $c->item('mail_reply_to'));
        $this->ca_bundle  = trim((string) $c->item('mail_ca_bundle'));
    }

    // =========================================================================
    // READINESS
    // =========================================================================

    /**
     * Can this instance actually deliver?
     *
     * Separated from send() so a caller — or an admin screen — can ask without
     * sending anything, and so the reset endpoint can decline to issue a token
     * it already knows cannot be delivered.
     *
     * @return array [bool ok, string reason]
     */
    public function readiness()
    {
        if ($this->from_email === '' || ! filter_var($this->from_email, FILTER_VALIDATE_EMAIL)) {
            return [FALSE, 'mail_from_email is not a valid address.'];
        }

        switch ($this->transport) {
            case 'smtp':
                if (trim((string) $this->CI->config->item('mail_smtp_host')) === '') {
                    return [FALSE, 'mail_transport is smtp but mail_smtp_host is empty.'];
                }
                /* NOTE: an empty password is NOT rejected. Some relays authenticate
                   by IP allow-list with no credentials at all, and refusing that
                   configuration would break a valid setup. CI3 skips AUTH entirely
                   when smtp_user is empty, which is the case that matters. */
                return [TRUE, ''];

            case 'mail':
                if ( ! function_exists('mail')) {
                    return [FALSE, 'mail_transport is mail but PHP mail() is disabled.'];
                }
                return [TRUE, ''];

            case 'log':
                /* NOTE: this is the guard that keeps a development transport out of
                   production. The log transport writes complete reset links to
                   disk; with mail_allow_log_transport FALSE it is treated as a
                   misconfiguration rather than silently writing credentials to a
                   file nobody is watching. */
                if ( ! $this->allow_log) {
                    return [FALSE, 'mail_transport is log but mail_allow_log_transport is FALSE. '
                                 . 'Set a real transport (smtp) for this environment.'];
                }
                return [TRUE, ''];
        }

        return [FALSE, 'Unknown mail_transport: ' . $this->transport];
    }

    /** Which transport this instance would use. Diagnostics only. */
    public function transport()
    {
        return $this->transport;
    }

    // =========================================================================
    // SEND
    // =========================================================================

    /**
     * @param  string $to      recipient address
     * @param  string $subject
     * @param  string $text    plain-text body (required — see below)
     * @param  string $html    optional HTML body
     * @return array           ['ok'=>bool, 'error'=>string, 'transport'=>string]
     *
     * NOTE: the plain-text body is REQUIRED, not a courtesy. A password-reset
     * mail that renders only as HTML is unreadable in a text-only client and,
     * more commonly, scores badly enough with spam filters to land in a folder
     * the user never checks — which presents as "the reset is broken".
     */
    public function send($to, $subject, $text, $html = NULL)
    {
        $to = trim((string) $to);

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->_result(FALSE, 'Recipient address is not valid.');
        }

        /* NOTE: header-injection guard. A subject containing CR or LF can close
           the Subject header and open another one — "Bcc: attacker@..." is the
           classic use, turning any transactional mail into an open relay. CI3's
           Email does not strip these for us. Rejected rather than sanitised: a
           legitimate subject never contains a newline, so one here means the
           caller built it from unvalidated input, and that is worth surfacing
           rather than quietly repairing. */
        if (preg_match('/[\r\n]/', (string) $subject)) {
            log_message('error', '[Mailer] Refused a subject containing CR/LF.');
            return $this->_result(FALSE, 'Subject contains a line break.');
        }
        if (preg_match('/[\r\n]/', $to)) {
            log_message('error', '[Mailer] Refused a recipient containing CR/LF.');
            return $this->_result(FALSE, 'Recipient contains a line break.');
        }

        list($ready, $why) = $this->readiness();
        if ( ! $ready) {
            log_message('error', '[Mailer] Not ready: ' . $why);
            return $this->_result(FALSE, $why);
        }

        if ($this->transport === 'log') {
            return $this->_send_to_log($to, $subject, $text, $html);
        }

        return $this->_send_real($to, $subject, $text, $html);
    }

    // =========================================================================
    // TRANSPORTS
    // =========================================================================

    protected function _send_real($to, $subject, $text, $html)
    {
        $c = $this->CI->config;

        $conf = [
            'useragent' => 'GenericPOS',
            'mailtype'  => ($html !== NULL && $html !== '') ? 'html' : 'text',
            'charset'   => 'utf-8',
            /* NOTE: CRLF, and BOTH keys. RFC 5321 requires CRLF line endings, and
               CI3 uses `newline` for headers but `crlf` for the SMTP body. Leaving
               either at "\n" produces mail that some servers accept, some mangle
               and some reject outright — an intermittent failure that reads like
               a network problem. */
            'newline'   => "\r\n",
            'crlf'      => "\r\n",
            'wordwrap'  => TRUE,
            'validate'  => TRUE,
        ];

        if ($this->transport === 'smtp') {
            $conf['protocol']     = 'smtp';
            $conf['smtp_host']    = trim((string) $c->item('mail_smtp_host'));
            $conf['smtp_port']    = (int) ($c->item('mail_smtp_port') ?: 587);
            $conf['smtp_user']    = trim((string) $c->item('mail_smtp_user'));
            $conf['smtp_pass']    = (string) gp_secret('GP_SMTP_PASSWORD', '');
            $conf['smtp_timeout'] = (int) ($c->item('mail_smtp_timeout_s') ?: 15);
            $conf['smtp_crypto']  = trim((string) $c->item('mail_smtp_crypto'));
        } else {
            $conf['protocol'] = 'mail';
        }

        /* Re-initialised and cleared on every send rather than kept warm. CI3's
           Email accumulates recipients, headers and errors across calls unless
           cleared, and a second send that quietly inherits the first one's To:
           is the kind of bug that only appears at production volume. */
        $this->CI->load->library('email', $conf, 'gp_email');
        $this->CI->gp_email->initialize($conf);
        $this->CI->gp_email->clear(TRUE);

        $this->CI->gp_email->from($this->from_email, $this->from_name);
        if ($this->reply_to !== '') {
            $this->CI->gp_email->reply_to($this->reply_to);
        }
        $this->CI->gp_email->to($to);
        $this->CI->gp_email->subject($subject);

        if ($html !== NULL && $html !== '') {
            $this->CI->gp_email->message($html);
            $this->CI->gp_email->set_alt_message($text);
        } else {
            $this->CI->gp_email->message($text);
        }

        $email = $this->CI->gp_email;

        $ok = $this->_with_tls_defaults(function () use ($email) {
            // FALSE: do not auto-clear, so print_debugger() still has the
            // conversation to report when the send failed.
            return $email->send(FALSE);
        });

        if ($ok) {
            return $this->_result(TRUE, '');
        }

        // Carries the SMTP conversation. Operator-facing only — never returned
        // to an HTTP caller.
        $debug = trim(strip_tags($this->CI->gp_email->print_debugger(['headers'])));

        /* Name the failure mode in the log rather than leaving "rejected" to
           stand for everything.

           A CONNECTION that never opened is not a rejection, and reading it as
           one sends the operator hunting for a bad password when the packets
           are not arriving at all. The case that produced this: the SMTP host
           was the site's own domain, which is on Cloudflare — HTTP and HTTPS
           are proxied, port 465 is not, so the socket sat until it timed out
           against an edge address with nothing listening. Hours can go into
           re-checking a mailbox that was configured correctly the whole time. */
        $hint = '';
        if ($this->transport === 'smtp'
            && preg_match('/Connection timed out|Connection refused|Unable to connect/i', $debug)) {
            $host = trim((string) $this->CI->config->item('mail_smtp_host'));
            $hint = ' [the connection to ' . $host . ':'
                  . (int) $this->CI->config->item('mail_smtp_port')
                  . ' never opened, so this is NOT an authentication or mailbox'
                  . ' problem. Check that ' . $host . ' resolves to the mail server'
                  . ' and not to a proxy: Cloudflare does not carry SMTP ports.'
                  . ' On a host where the mailbox is local, mail_transport=mail'
                  . ' avoids the hop entirely.]';
        }

        log_message('error', '[Mailer] Send failed via ' . $this->transport . ': ' . $debug . $hint);

        /* The caller's message stays generic — it reaches an HTTP response, and
           the shape of the mail infrastructure is not a user's business. */
        return $this->_result(FALSE, 'The mail server could not be reached.');
    }

    /**
     * Development transport: write the message to application/logs/mail/ and
     * send nothing.
     *
     * NOTE: gated by mail_allow_log_transport in readiness() before reaching
     * here, and it logs a warning on every send. The file contains a working
     * password-reset link, so the directory is treated as credential material:
     * application/.htaccess already denies the whole tree over HTTP, a second
     * guard is written alongside in case the directory is copied elsewhere, and
     * files are chmod 0600 where the platform honours it.
     */
    protected function _send_to_log($to, $subject, $text, $html)
    {
        $dir = rtrim(APPPATH, '/\\') . DIRECTORY_SEPARATOR . 'logs'
             . DIRECTORY_SEPARATOR . self::LOG_DIR;

        if ( ! is_dir($dir) && ! @mkdir($dir, 0750, TRUE) && ! is_dir($dir)) {
            return $this->_result(FALSE, 'Could not create the mail log directory: ' . $dir);
        }

        $guard = $dir . DIRECTORY_SEPARATOR . '.htaccess';
        if ( ! is_file($guard)) {
            @file_put_contents(
                $guard,
                "<IfModule authz_core_module>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !authz_core_module>\n    Deny from all\n</IfModule>\n"
            );
        }
        $idx = $dir . DIRECTORY_SEPARATOR . 'index.html';
        if ( ! is_file($idx)) {
            @file_put_contents($idx, '');
        }

        $name = date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.txt';
        $path = $dir . DIRECTORY_SEPARATOR . $name;

        $body = 'To: ' . $to . "\r\n"
              . 'From: ' . $this->from_name . ' <' . $this->from_email . ">\r\n"
              . ($this->reply_to !== '' ? 'Reply-To: ' . $this->reply_to . "\r\n" : '')
              . 'Subject: ' . $subject . "\r\n"
              . 'Date: ' . date('r') . "\r\n"
              . "X-GenericPOS-Transport: log (NOT DELIVERED)\r\n"
              . "\r\n"
              . $text
              . (($html !== NULL && $html !== '')
                    ? "\r\n\r\n--- HTML PART ---\r\n" . $html
                    : '');

        if (@file_put_contents($path, $body) === FALSE) {
            return $this->_result(FALSE, 'Could not write to the mail log directory: ' . $dir);
        }
        @chmod($path, 0600);

        log_message('error',
            '[Mailer] LOG TRANSPORT ACTIVE - no mail was sent. Message written to '
            . $path . '. This transport writes credentials to disk and must not be '
            . 'used in production (app.php: mail_transport).');

        return $this->_result(TRUE, '');
    }

    // =========================================================================
    // TRANSACTIONAL MESSAGES
    // =========================================================================
    // The two authentication messages live here rather than in a controller so
    // that Auth (reset) and Profile (change) send the SAME wording, and so the
    // link-building rule below is stated once.

    /**
     * The password-reset link.
     *
     * @param  string $to      recipient
     * @param  string $link    absolute reset URL, already carrying the token
     * @param  int    $ttl_s   token lifetime, for the "expires in" line
     * @return array           see send()
     */
    public function send_password_reset($to, $link, $ttl_s)
    {
        $mins = max(1, (int) round($ttl_s / 60));
        $app  = trim((string) $this->CI->config->item('store_name'))
            ?: (trim((string) $this->CI->config->item('app_name')) ?: 'our store');
        $sup  = $this->_support_address();

        $subject = 'Reset your ' . $app . ' password';

        $text = "Someone asked to reset the password for your {$app} account.\r\n\r\n"
              . "Open this link to choose a new password:\r\n"
              . "{$link}\r\n\r\n"
              . "The link expires in {$mins} minutes and can only be used once.\r\n\r\n"
              . "If you did not ask for this, you can ignore this message. Your\r\n"
              . "password will not change until the link above is used"
              . ($sup !== '' ? ", and you can\r\ntell us at {$sup}.\r\n" : ".\r\n")
              . "\r\n"
              . "We will never ask you for your password, and no one from {$app}\r\n"
              . "will ever ask you to forward this email.\r\n";

        /* NOTE: the link is rendered as visible text as well as an href. A
           reset mail styled like a marketing message — a bare "Reset password"
           button hiding its destination — is indistinguishable from the
           phishing it will be imitated by, and teaches users to click without
           looking. Showing the URL is what lets someone check it goes to the
           real domain. */
        $html = '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;'
              . 'font-size:15px;line-height:1.55;color:#2A2118;max-width:560px">'
              . '<p>Someone asked to reset the password for your ' . $this->_e($app) . ' account.</p>'
              . '<p>Open this link to choose a new password:</p>'
              . '<p style="margin:16px 0"><a href="' . $this->_e($link) . '" '
              . 'style="color:#8B6914;word-break:break-all">' . $this->_e($link) . '</a></p>'
              . '<p>The link expires in ' . $mins . ' minutes and can only be used once.</p>'
              . '<p>If you did not ask for this, you can ignore this message. Your password '
              . 'will not change until the link above is used'
              . ($sup !== '' ? ', and you can tell us at ' . $this->_e($sup) . '.' : '.')
              . '</p>'
              . '<p style="color:#6B5F52;font-size:13px">We will never ask you for your password, '
              . 'and no one from ' . $this->_e($app) . ' will ever ask you to forward this email.</p>'
              . '</div>';

        return $this->send($to, $subject, $text, $html);
    }

    /**
     * "Your password was changed."
     *
     * NOTE: THIS IS A SECURITY CONTROL, NOT A COURTESY. A takeover carried out
     * through a compromised mailbox is completely silent otherwise — the victim
     * finds out when they next fail to sign in, which can be weeks. This mail
     * is the one chance they get to notice on the day it happens, which is why
     * a failure to send it is logged rather than ignored.
     *
     * NOTE: it deliberately does NOT contain a link. A message that says "if
     * this was not you, click here" is the exact shape of the phishing that
     * follows a breach, and users who learn to click it are being trained
     * badly. It names the support address instead.
     */
    public function send_password_changed($to, $username, $when_utc, $ip = NULL)
    {
        $app = trim((string) $this->CI->config->item('store_name'))
            ?: (trim((string) $this->CI->config->item('app_name')) ?: 'our store');
        $sup = $this->_support_address();

        $subject = 'Your ' . $app . ' password was changed';

        $who  = ($username !== '' && $username !== NULL) ? ' (' . $username . ')' : '';
        $from = $ip ? "\r\nRequest address: {$ip}\r\n" : '';

        $text = "The password for your {$app} account{$who} was changed.\r\n\r\n"
              . "When: {$when_utc} UTC\r\n"
              . $from
              . "\r\nEvery signed-in device has been signed out.\r\n\r\n"
              . "If this was you, nothing further is needed.\r\n\r\n"
              . 'If it was NOT you, act now: someone else has access to your account'
              . ($sup !== '' ? "\r\nor your email. Contact {$sup} immediately.\r\n"
                             : ".\r\n");

        $html = '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;'
              . 'font-size:15px;line-height:1.55;color:#2A2118;max-width:560px">'
              . '<p>The password for your ' . $this->_e($app) . ' account'
              . $this->_e($who) . ' was changed.</p>'
              . '<p style="color:#6B5F52">When: ' . $this->_e($when_utc) . ' UTC'
              . ($ip ? '<br>Request address: ' . $this->_e($ip) : '') . '</p>'
              . '<p>Every signed-in device has been signed out.</p>'
              . '<p>If this was you, nothing further is needed.</p>'
              . '<p><strong>If it was not you, act now</strong> - someone else has access to '
              . 'your account or your email.'
              . ($sup !== '' ? ' Contact ' . $this->_e($sup) . ' immediately.' : '')
              . '</p>'
              . '</div>';

        return $this->send($to, $subject, $text, $html);
    }

    /**
     * "The email address on your account was changed" — sent to the OLD address.
     *
     * NOTE: A SECURITY CONTROL, like send_password_changed(): whoever moves an
     * account to their own address can reset its password from there, so the
     * owner hears about it at the address they still control. No link, for the
     * same reason; the new address is masked, so the notice itself leaks nothing.
     */
    public function send_email_changed($to, $username, $new_email, $when_utc, $ip = NULL)
    {
        $app = trim((string) $this->CI->config->item('store_name'))
            ?: (trim((string) $this->CI->config->item('app_name')) ?: 'our');
        $sup = $this->_support_address();
        $new = $this->_mask_email($new_email);
        $who = ($username !== '' && $username !== NULL) ? ' (' . $username . ')' : '';

        $subject = 'The email address on your ' . $app . ' account was changed';

        $text = "The email address on your {$app} account{$who} was changed to {$new}.\r\n\r\n"
              . "When: {$when_utc} UTC\r\n"
              . ($ip ? "Request address: {$ip}\r\n" : '')
              . "\r\nIf this was you, nothing further is needed.\r\n\r\n"
              . 'If it was NOT you, act now: someone else has access to your account'
              . ($sup !== '' ? ". Contact {$sup} immediately.\r\n" : ".\r\n");

        $html = '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;'
              . 'font-size:15px;line-height:1.55;color:#2A2118;max-width:560px">'
              . '<p>The email address on your ' . $this->_e($app) . ' account' . $this->_e($who)
              . ' was changed to ' . $this->_e($new) . '.</p>'
              . '<p style="color:#6B5F52">When: ' . $this->_e($when_utc) . ' UTC'
              . ($ip ? '<br>Request address: ' . $this->_e($ip) : '') . '</p>'
              . '<p>If this was you, nothing further is needed.</p>'
              . '<p><strong>If it was not you, act now</strong> - someone else has access to your account.'
              . ($sup !== '' ? ' Contact ' . $this->_e($sup) . ' immediately.' : '')
              . '</p>'
              . '</div>';

        return $this->send($to, $subject, $text, $html);
    }

    /** Where people are told to write: support_email when it is set, otherwise the company's own address. */
    private function _support_address()
    {
        return trim((string) $this->CI->config->item('support_email'))
            ?: trim((string) $this->CI->config->item('store_email'));
    }

    /** j•••@example.com — enough for the owner to recognise, nothing for anyone else. */
    private function _mask_email($email)
    {
        $email = (string) $email;
        $at    = strrpos($email, '@');
        if ($at === FALSE || $at < 1) return '•••';
        return mb_substr($email, 0, 1) . '•••' . substr($email, $at);
    }

    /**
     * Confirm an email address.
     *
     * NOTE: this link only CONFIRMS an address — it grants no access and sets
     * no password, which is why it lives for 24 hours where a reset link lives
     * for one. Losing it costs a user a click on "resend", not their account.
     */
    public function send_email_verify($to, $link, $ttl_s)
    {
        $hours = max(1, (int) round($ttl_s / 3600));
        $app   = trim((string) $this->CI->config->item('store_name'))
            ?: (trim((string) $this->CI->config->item('app_name')) ?: 'our store');
        $sup   = $this->_support_address();

        $subject = 'Confirm your ' . $app . ' email address';

        $text = "Confirm this address so {$app} can email you about your account —\r\n"
              . "deposits credited, replies to your inquiries, and account changes.\r\n\r\n"
              . "{$link}\r\n\r\n"
              . "The link expires in {$hours} hours.\r\n\r\n"
              . "Until you confirm, everything still appears in the app; you simply\r\n"
              . "will not receive it by email.\r\n\r\n"
              . "If you did not create a {$app} account, ignore this message"
              . ($sup !== '' ? " or tell us at {$sup}" : '') . ".\r\n";

        $html = '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;'
              . 'font-size:15px;line-height:1.55;color:#2A2118;max-width:560px">'
              . '<p>Confirm this address so ' . $this->_e($app) . ' can email you about your '
              . 'account &mdash; deposits credited, replies to your inquiries, and account changes.</p>'
              . '<p style="margin:16px 0"><a href="' . $this->_e($link) . '" '
              . 'style="color:#8B6914;word-break:break-all">' . $this->_e($link) . '</a></p>'
              . '<p>The link expires in ' . $hours . ' hours.</p>'
              . '<p style="color:#6B5F52;font-size:13px">Until you confirm, everything still '
              . 'appears in the app; you simply will not receive it by email.</p>'
              . '</div>';

        return $this->send($to, $subject, $text, $html);
    }

    /**
     * The email copy of an in-app notification.
     *
     * NOTE: it says the notification is also in the app. A user who reads
     * only the email otherwise has no reason to believe there is a record of
     * it anywhere, and support gets asked to confirm what was sent.
     */
    public function send_notification($to, $title, $body, $link = NULL)
    {
        $app = trim((string) $this->CI->config->item('store_name'))
            ?: (trim((string) $this->CI->config->item('app_name')) ?: 'our store');

        $text = $title . "\r\n\r\n"
              . ($body !== '' ? $body . "\r\n\r\n" : '')
              . ($link !== NULL ? "Open it here:\r\n{$link}\r\n\r\n" : '')
              . "This notification is also in your {$app} account.\r\n";

        $html = '<div style="font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;'
              . 'font-size:15px;line-height:1.55;color:#2A2118;max-width:560px">'
              . '<p style="font-weight:700;font-size:17px;margin:0 0 10px">' . $this->_e($title) . '</p>'
              . ($body !== '' ? '<p>' . $this->_e($body) . '</p>' : '')
              . ($link !== NULL
                  ? '<p style="margin:16px 0"><a href="' . $this->_e($link) . '" '
                    . 'style="color:#8B6914">Open it in ' . $this->_e($app) . '</a></p>'
                  : '')
              . '<p style="color:#6B5F52;font-size:13px">This notification is also in your '
              . $this->_e($app) . ' account.</p>'
              . '</div>';

        return $this->send($to, $title, $text, $html);
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    /** Escape for the HTML part. */
    protected function _e($s)
    {
        return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Run $fn with TLS verification forced on in PHP's default stream context,
     * then restore whatever was there before.
     *
     * NOTE: the restore is the point of the wrapper. The default stream context
     * is PROCESS-WIDE — leaving it changed would silently alter every later
     * fopen()/fsockopen() in the same request, including the chain RPC client,
     * which manages its own verification and must not inherit ours.
     */
    protected function _with_tls_defaults(callable $fn)
    {
        $opts = [
            'ssl' => [
                'verify_peer'       => TRUE,
                'verify_peer_name'  => TRUE,
                'allow_self_signed' => FALSE,
                /* SNI and peer-name matching both need the hostname, and the
                   STARTTLS path enables crypto on a socket that was opened
                   without one in the address. */
                'peer_name'         => trim((string) $this->CI->config->item('mail_smtp_host')),
            ],
        ];

        if ($this->ca_bundle !== '' && is_readable($this->ca_bundle)) {
            $opts['ssl']['cafile'] = $this->ca_bundle;
        } elseif ($this->ca_bundle !== '') {
            log_message('error',
                '[Mailer] mail_ca_bundle is set but not readable: ' . $this->ca_bundle
                . ' - falling back to php.ini openssl.cafile, which is empty on some installs.');
        }

        $previous = stream_context_get_options(stream_context_get_default());
        stream_context_set_default($opts);

        try {
            return $fn();
        } finally {
            // Restore verbatim, including the empty case.
            stream_context_set_default($previous);
        }
    }

    protected function _result($ok, $error)
    {
        return [
            'ok'        => (bool) $ok,
            'error'     => (string) $error,
            'transport' => $this->transport,
        ];
    }
}
