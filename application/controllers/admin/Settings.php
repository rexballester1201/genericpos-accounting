<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * admin/Settings.php — the company's settings, driven by the registry in Settings_model
 *
 * GenericPOS Accounting · administrators only
 *
 *   GET  /api/v1/admin/settings             { groups, settings }
 *   POST /api/v1/admin/settings             { settings: { key: value, … } } — each key saved or refused on its own
 *   POST /api/v1/admin/settings/reset       { key } — back to the value in config/app.php
 *   POST /api/v1/admin/settings/upload      multipart: file + kind (logo)
 *   POST /api/v1/admin/settings/mail-test   a real message to your own address
 *
 * NOTE: PARTIAL SUCCESS IS REPORTED, NOT ROLLED BACK. One bad value does not
 * discard the others an administrator just typed; the answer lists exactly
 * which keys saved and which did not. Every real change is audited with its
 * before and after, so "from what, to what" is answerable from the log alone.
 */
class Settings extends CI_Controller
{
    const IMAGES = ['logo' => ['store_logo', 800]];

    private $claims;

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->claims = admin_check();
        $this->load->model('Settings_model', 'settings');
    }

    private function uid() { return (int) $this->claims['user_id']; }

    private function _all()
    {
        return ['groups' => $this->settings->groups(), 'settings' => $this->settings->all_for_admin()];
    }

    /** GET /api/v1/admin/settings */
    public function index()
    {
        require_method('GET');
        return json_response($this->_all(), 'Settings');
    }

    /** POST /api/v1/admin/settings */
    public function save()
    {
        require_method('POST');
        rate_limit($this->uid(), 'admin_write');
        $in = get_json_body();
        $pairs = isset($in['settings']) && is_array($in['settings']) ? $in['settings'] : [];
        if ( ! $pairs) return json_error('Nothing to save.', 400);

        $r = $this->settings->set_many($pairs, $this->uid());
        if ($r['saved']) log_admin_action($this->claims, 'settings.update', 'settings', NULL, $r['saved']);
        if ($r['errors'] && ! $r['saved'] && count($r['errors']) === count($pairs)) return json_invalid($r['errors']);

        return json_response(['saved' => array_keys($r['saved']), 'errors' => $r['errors']] + $this->_all(),
            $r['errors'] ? 'Some settings could not be saved.' : 'Settings saved.');
    }

    /** POST /api/v1/admin/settings/reset */
    public function reset()
    {
        require_method('POST');
        rate_limit($this->uid(), 'admin_write');
        $key = (string) (get_json_body()['key'] ?? '');
        $r = $this->settings->reset($key);
        if (empty($r['ok'])) return json_invalid(['key' => $r['error']]);
        log_admin_action($this->claims, 'settings.reset', 'settings', NULL, [$key => ['from' => $r['from'], 'to' => $r['to']]]);
        return json_response(['key' => $key, 'value' => $r['to']] + $this->_all(), 'Back to the default.');
    }

    /** POST /api/v1/admin/settings/upload   (multipart: file, kind) */
    public function upload()
    {
        require_method('POST');
        rate_limit($this->uid(), 'admin_upload');
        $kind = (string) $this->input->post('kind');
        if ( ! isset(self::IMAGES[$kind])) return json_invalid(['kind' => 'Choose what the image is for.']);
        if (empty($_FILES['file']) || ! is_array($_FILES['file'])) return json_invalid(['file' => 'Choose an image.']);

        list($key, $edge) = self::IMAGES[$kind];
        $this->load->library('Imaging_lib', NULL, 'imaging');
        $r = $this->imaging->fit_from_upload($_FILES['file'], FCPATH . 'uploads/branding', $edge);
        if (empty($r['ok'])) return json_invalid(['file' => $r['message'] ?? 'That image could not be used.']);

        $file = (string) ($r['file'] ?? '');
        if ($file === '') return json_error('The image could not be saved. Please try again.', 500);
        $value = 'uploads/branding/' . $file;
        $s = $this->settings->set($key, $value, $this->uid());
        if (empty($s['ok'])) return json_invalid(['file' => $s['error']]);
        $this->settings->refresh_store_cache();

        log_admin_action($this->claims, 'settings.upload', 'settings', NULL, [$key => ['from' => $s['from'], 'to' => $value]]);
        return json_response(['key' => $key, 'value' => $value] + $this->_all(), 'Image saved');
    }

    /**
     * POST /api/v1/admin/settings/mail-test
     *
     * "Accepted by the mail server" is NOT "delivered", and the answer says so
     * in as many words: an operator who reads "sent" and stops looking is how
     * a delivery problem survives for a week.
     */
    public function mail_test()
    {
        require_method('POST');
        rate_limit($this->uid(), 'admin_mail_test');

        $row = $this->db->select('email')->get_where('gp_users', ['id' => $this->uid()], 1)->row_array();
        $to = $row ? trim((string) $row['email']) : '';
        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) return json_error('Your account has no email address to send to.', 422);

        $this->load->library('Mailer_lib', NULL, 'mailer');
        list($ready, $why) = $this->mailer->readiness();
        if ( ! $ready) {
            return json_response(['ok' => FALSE, 'stage' => 'config', 'transport' => $this->mailer->transport(), 'to' => $to,
                'detail' => $why, 'note' => 'The mailer refused before sending — nothing left the server.'], 'Mail check');
        }

        $name = (string) shop_cfg('store_name', 'The store');
        $subject = $name . ' mail test ' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 6));
        $text = "This is a delivery test from " . $name . ".\r\n\r\nSent: " . gmdate('Y-m-d H:i:s') . " UTC\r\nTransport: " . $this->mailer->transport()
              . "\r\n\r\nIf you are reading this, mail from the store reaches this address.\r\nIf it landed in spam, the domain still needs SPF or DKIM.\r\n";
        $t0 = microtime(TRUE);
        $res = $this->mailer->send($to, $subject, $text);
        $ms = (int) round((microtime(TRUE) - $t0) * 1000);
        log_admin_action($this->claims, 'mail.test', 'mail', NULL, ['to' => $to, 'subject' => $subject, 'ok' => ! empty($res['ok'])]);

        if (empty($res['ok'])) {
            return json_response(['ok' => FALSE, 'stage' => 'handoff', 'transport' => $this->mailer->transport(), 'to' => $to, 'subject' => $subject, 'ms' => $ms,
                'detail' => (string) ($res['error'] ?? ''), 'note' => 'The mail server did NOT accept it — the message never left the machine.'], 'Mail check');
        }
        return json_response(['ok' => TRUE, 'stage' => 'handoff', 'transport' => $this->mailer->transport(), 'to' => $to, 'subject' => $subject, 'ms' => $ms,
            'note' => 'Accepted by the mail server. That is not the same as delivered: check the inbox, then spam, for "' . $subject . '".'], 'Mail check');
    }
}
