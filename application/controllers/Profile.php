<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Profile.php — the signed-in account's own details
 *
 * GenericPOS
 *
 *   GET    /api/v1/profile            profile + security state
 *   PUT    /api/v1/profile            full_name, username, phone, email, marketing_opt_in
 *   POST   /api/v1/profile/password   change password (needs the current one, if set)
 *   POST   /api/v1/profile/avatar     upload a picture (multipart "avatar")
 *   DELETE /api/v1/profile/avatar
 */
class Profile extends CI_Controller
{
    const AVATAR_DIR  = 'uploads/avatars/';
    const AVATAR_SIZE = 320;

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('User_model', 'users');
    }

    private function _abs_avatar_dir()
    {
        return rtrim(FCPATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::AVATAR_DIR);
    }

    /** GET|PUT /api/v1/profile */
    public function index()
    {
        require_method(['GET', 'PUT']);
        if (strtolower($this->input->method()) === 'put') return $this->update();

        $claims = auth_check();
        $user   = $this->users->find_by_id((int) $claims['user_id']);
        if ( ! $user) return json_error('Account not found.', 404);

        $out = $this->users->public_fields($user);
        $out['username_cooldown_s'] = $this->users->username_cooldown_left($user);
        $out['password_min_length'] = (int) $this->config->item('password_min_length');

        return json_response($out, 'Profile');
    }

    /** PUT /api/v1/profile */
    public function update()
    {
        require_method(['PUT', 'POST']);
        $claims  = auth_check();
        $user_id = (int) $claims['user_id'];

        rate_limit($user_id, 'profile_update');

        $in = get_json_body();

        $fields = [];
        foreach (['full_name', 'username', 'phone', 'email', 'marketing_opt_in'] as $k) {
            if (array_key_exists($k, $in)) $fields[$k] = $in[$k];
        }
        if ( ! $fields) return json_error('Nothing to update.', 400);

        $r = $this->users->update_profile($user_id, $fields, $this->input->ip_address());
        if ( ! $r['ok']) return json_invalid($r['errors']);

        return json_response([
            'user'    => $this->users->public_fields($this->users->find_by_id($user_id)),
            'changed' => $r['changed'],
        ], $r['changed'] ? 'Profile updated' : 'No changes to save');
    }

    /**
     * POST /api/v1/profile/password
     *
     * A wrong CURRENT password feeds the account lockout (it is somebody
     * guessing); form typos in the new one do not (they are typos). The caller
     * gets a fresh token pair: every other session ends, this one continues.
     */
    public function password()
    {
        require_method('POST');
        $claims  = auth_check();
        $user_id = (int) $claims['user_id'];

        $scope = 'acct:' . $user_id;
        if ($wait = $this->users->lockout_remaining($scope)) {
            $mins = max(1, (int) ceil($wait / 60));
            $this->output->set_header('Retry-After: ' . (int) $wait);
            return json_error('Too many incorrect password attempts. Try again in ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . '.', 429);
        }

        rate_limit($user_id, 'password_change');

        $in = get_json_body();
        $r  = $this->users->change_password(
            $user_id,
            (string) ($in['current_password'] ?? ''),
            (string) ($in['new_password'] ?? ''),
            (string) ($in['confirm_password'] ?? '')
        );

        if ( ! $r['ok']) {
            if (($r['errors']['current_password'] ?? '') === 'That password is not correct.') {
                $this->users->record_failure($scope);
            }
            return json_invalid($r['errors']);
        }

        $this->users->clear_failures($scope);

        $this->load->model('PasswordReset_model', 'resets');
        $this->resets->void_outstanding($user_id, 'password changed from account screen');

        $user   = $this->users->find_by_id($user_id);
        $tokens = $user ? issue_session_tokens($user) : ['access_token' => NULL, 'refresh_token' => NULL];

        json_response_then_continue([
            'signed_out_everywhere' => TRUE,
            'access_token'          => $tokens['access_token'],
            'refresh_token'         => $tokens['refresh_token'],
        ], 'Password changed. You have been signed out on your other devices.');

        if ($user && $this->config->item('password_change_notify') && ! empty($user['email'])) {
            $this->load->library('Mailer_lib', NULL, 'mailer');
            $this->mailer->send_password_changed($user['email'], $user['username'], date('Y-m-d H:i:s'), $this->input->ip_address());
        }
    }

    /** POST | DELETE /api/v1/profile/avatar */
    public function avatar()
    {
        require_method(['POST', 'DELETE']);
        $claims  = auth_check();
        $user_id = (int) $claims['user_id'];

        if (strtolower($this->input->method()) === 'delete') return $this->_avatar_remove($user_id);

        rate_limit($user_id, 'avatar_upload');

        if (empty($_FILES['avatar']) || ! is_array($_FILES['avatar'])) {
            return json_invalid(['avatar' => 'Choose an image to upload.']);
        }

        /* Every upload goes through Imaging_lib: header sniffing, size and
           pixel caps, re-encoding (when GD is present), random filenames. */
        $this->load->library('Imaging_lib', NULL, 'imaging');
        $res = $this->imaging->square_from_upload($_FILES['avatar'], $this->_abs_avatar_dir(), self::AVATAR_SIZE);

        if (empty($res['ok'])) {
            $code = $res['error'] ?? 'unknown';
            if (in_array($code, ['server_no_dir', 'write_failed'], TRUE)) {
                log_message('error', '[Profile] avatar write failed (' . $code . ')');
                return json_error('Could not save your picture. Please try again.', 503);
            }
            return json_invalid(['avatar' => $res['message'] ?? 'That image could not be used.']);
        }

        $prev = $this->users->find_by_id($user_id);
        $this->users->set_avatar($user_id, $res['file']);
        if ($prev && ! empty($prev['avatar_file'])) $this->_unlink_avatar($prev['avatar_file']);

        return json_response([
            'avatar_url' => $this->users->avatar_url($this->users->find_by_id($user_id)),
        ], 'Picture updated');
    }

    private function _avatar_remove($user_id)
    {
        $user = $this->users->find_by_id($user_id);
        if ($user && ! empty($user['avatar_file'])) {
            $this->users->clear_avatar($user_id);
            $this->_unlink_avatar($user['avatar_file']);
        }
        return json_response(['avatar_url' => NULL], 'Picture removed');
    }

    /**
     * Delete one avatar file. The name is re-validated against the exact shape
     * Imaging_lib produces before anything is unlinked — this turns a database
     * value into a filesystem deletion, which is not a place to assume.
     */
    private function _unlink_avatar($filename)
    {
        if ( ! preg_match('/^[0-9a-f]{16}\.(jpg|png|webp)$/', (string) $filename)) {
            log_message('error', '[Profile] refused to unlink unexpected avatar name: ' . $filename);
            return;
        }
        $path = $this->_abs_avatar_dir() . $filename;
        if (is_file($path)) @unlink($path);
    }
}
