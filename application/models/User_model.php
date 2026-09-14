<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * User_model.php — accounts (customers AND staff), credentials, PINs,
 * sign-in throttle, refresh tokens
 *
 * GenericPOS · table gp_users
 *
 * Callers must have loaded the database. 'database' is deliberately not
 * autoloaded — see config/autoload.php.
 */
class User_model extends CI_Model
{
    const TABLE          = 'gp_users';
    const TABLE_ATTEMPTS = 'gp_login_attempts';
    const TABLE_REFRESH  = 'gp_refresh_tokens';
    const T_UNAME_HISTORY = 'gp_username_history';

    /** Lowest to highest; see role_rank() in api_helper. */
    const ROLES = ['viewer', 'bookkeeper', 'accountant', 'admin'];

    /**
     * Columns safe to return to the account holder. An ALLOW-list: a column
     * added to the table later is invisible until someone chooses to add it.
     */
    const PUBLIC_FIELDS = [
        'id', 'email', 'username', 'full_name', 'phone',
        'avatar_file', 'avatar_updated_at', 'username_changed_at',
        'password_changed_at', 'role', 'account_state', 'auth_method',
        'is_email_verified', 'is_phone_verified', 'marketing_opt_in', 'created_at',
    ];

    const AVATAR_URL_BASE = 'uploads/avatars/';

    protected function cfg($key, $default = NULL)
    {
        $this->config->load('app', FALSE, TRUE);
        $v = $this->config->item($key);
        return ($v === NULL) ? $default : $v;
    }

    // =========================================================================
    // NORMALISATION
    // =========================================================================

    /**
     * A mobile number in E.164 (+<country><subscriber>), or NULL.
     *
     *   +639171234567  kept     09171234567 → +639171234567     9171234567 → +639171234567
     *
     * International on purpose; the default country code (app.php) only fills in
     * national-format input. E.164 is what makes the UNIQUE index meaningful —
     * without it one person can hold three accounts under three spellings.
     */
    public function normalise_phone($raw, $default_cc = NULL)
    {
        if ( ! is_string($raw)) return NULL;
        $raw = trim($raw);
        if ($raw === '') return NULL;

        if ($default_cc === NULL) $default_cc = (string) $this->cfg('default_country_code', '+63');

        $had_plus = (strpos($raw, '+') === 0);
        $digits   = preg_replace('/\D/', '', $raw);
        if ($digits === '') return NULL;

        if ($had_plus) {
            if (strlen($digits) < 8 || strlen($digits) > 15) return NULL;
            return '+' . $digits;
        }

        $cc = preg_replace('/\D/', '', $default_cc);

        if ($cc !== '' && strpos($digits, $cc) === 0 && strlen($digits) > strlen($cc) + 6) {
            $digits = substr($digits, strlen($cc));
        } elseif (strpos($digits, '0') === 0) {
            $digits = ltrim($digits, '0');
        }

        if (strlen($digits) < 6 || strlen($digits) > 14) return NULL;
        $full = $cc . $digits;
        if (strlen($full) < 8 || strlen($full) > 15) return NULL;

        return '+' . $full;
    }

    /** 'email' | 'phone' | 'username' — decides which column a sign-in searches. */
    public function identifier_kind($identifier)
    {
        $identifier = trim((string) $identifier);
        if (strpos($identifier, '@') !== FALSE) return 'email';
        if (preg_match('/^\+?[\d\s\-().]{6,}$/', $identifier)) return 'phone';
        return 'username';
    }

    // =========================================================================
    // LOOKUPS
    // =========================================================================

    /** Full row (password_hash included) — use public_fields() before returning it. */
    public function find_by_identifier($identifier)
    {
        $identifier = trim((string) $identifier);
        if ($identifier === '') return NULL;

        switch ($this->identifier_kind($identifier)) {
            case 'email':
                $col = 'email';
                $val = mb_strtolower($identifier);
                break;
            case 'phone':
                $col = 'phone';
                $val = $this->normalise_phone($identifier);
                if ($val === NULL) return NULL;
                break;
            default:
                $col = 'username';
                $val = mb_strtolower($identifier);
        }

        $row = $this->db->where($col, $val)->limit(1)->get(self::TABLE)->row_array();
        return $row ?: NULL;
    }

    /** By email only — the password-reset path must not accept a username or phone. */
    public function find_by_email($email)
    {
        $email = mb_strtolower(trim((string) $email));
        if ($email === '') return NULL;
        $row = $this->db->where('email', $email)->limit(1)->get(self::TABLE)->row_array();
        return $row ?: NULL;
    }

    public function find_by_phone($phone_e164)
    {
        if ( ! $phone_e164) return NULL;
        $row = $this->db->where('phone', $phone_e164)->limit(1)->get(self::TABLE)->row_array();
        return $row ?: NULL;
    }

    public function find_by_id($id)
    {
        $row = $this->db->where('id', (int) $id)->limit(1)->get(self::TABLE)->row_array();
        return $row ?: NULL;
    }

    public function email_exists($email, $except_id = 0)
    {
        $this->db->where('email', mb_strtolower(trim((string) $email)));
        if ($except_id > 0) $this->db->where('id !=', (int) $except_id);
        return (bool) $this->db->count_all_results(self::TABLE);
    }

    public function username_exists($username, $except_id = 0)
    {
        $this->db->where('username', mb_strtolower(trim((string) $username)));
        if ($except_id > 0) $this->db->where('id !=', (int) $except_id);
        return (bool) $this->db->count_all_results(self::TABLE);
    }

    public function phone_exists($phone_e164, $except_id = 0)
    {
        if ( ! $phone_e164) return FALSE;
        $this->db->where('phone', $phone_e164);
        if ($except_id > 0) $this->db->where('id !=', (int) $except_id);
        return (bool) $this->db->count_all_results(self::TABLE);
    }

    /** Strip a row to the fields its owner may see. */
    public function public_fields($row)
    {
        if ( ! is_array($row)) return NULL;

        $out = [];
        foreach (self::PUBLIC_FIELDS as $f) {
            if (array_key_exists($f, $row)) $out[$f] = $row[$f];
        }

        $out['id']                = isset($out['id']) ? (int) $out['id'] : NULL;
        $out['is_email_verified'] = ! empty($out['is_email_verified']);
        $out['is_phone_verified'] = ! empty($out['is_phone_verified']);
        $out['marketing_opt_in']  = ! empty($out['marketing_opt_in']);
        $out['has_password']      = ! empty($row['password_hash']);
        $out['is_staff']          = in_array($row['role'] ?? '', self::ROLES, TRUE);

        $out['avatar_url'] = $this->avatar_url($row);
        unset($out['avatar_file']);

        return $out;
    }

    /** Relative web path (the client prefixes the app base) with a cache-bust. */
    public function avatar_url($row)
    {
        if (empty($row['avatar_file'])) return NULL;
        $v = ! empty($row['avatar_updated_at']) ? strtotime($row['avatar_updated_at']) : 0;
        return self::AVATAR_URL_BASE . rawurlencode($row['avatar_file']) . '?v=' . $v;
    }

    // =========================================================================
    // CREATE
    // =========================================================================

    /**
     * A free username derived from $seed ("maria.santos@x.com" → "mariasantos",
     * then "mariasantos2", then a random tail).
     *
     * NOTE: must start with a letter — that is what keeps a username from ever
     * being mistaken for a phone number by identifier_kind().
     */
    public function generate_username($seed)
    {
        $seed = mb_strtolower((string) $seed);
        if (strpos($seed, '@') !== FALSE) $seed = substr($seed, 0, strpos($seed, '@'));
        if (function_exists('iconv')) {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $seed);
            if ($t !== FALSE) $seed = strtolower($t);
        }
        $base = preg_replace('/[^a-z0-9_]/', '', $seed);
        $base = ltrim($base, '0123456789_');
        if (strlen($base) < 3) $base = 'user' . $base;
        $base = substr($base, 0, 24);

        if ( ! $this->username_exists($base)) return $base;

        for ($i = 2; $i < 30; $i++) {
            $cand = substr($base, 0, 24) . $i;
            if ( ! $this->username_exists($cand)) return $cand;
        }
        for ($i = 0; $i < 20; $i++) {
            $cand = substr($base, 0, 20) . random_int(1000, 99999);
            if ( ! $this->username_exists($cand)) return $cand;
        }
        return 'u' . bin2hex(random_bytes(6));
    }

    /**
     * Create an account. The caller validates and checks uniqueness for good
     * messages; the UNIQUE indexes remain the real guarantee (a lost race
     * throws, and Auth reports it as a taken field).
     *
     * @param  array $d  email?, username?, full_name?, phone? (E.164), password?,
     *                   role?, created_via?, marketing_opt_in?
     * @return int  new id
     */
    public function create(array $d)
    {
        $cost = (int) ($this->cfg('password_hash_cost', 12) ?: 12);
        $now  = date('Y-m-d H:i:s');

        $email = isset($d['email']) && trim((string) $d['email']) !== ''
               ? mb_strtolower(trim($d['email'])) : NULL;

        $username = isset($d['username']) && trim((string) $d['username']) !== ''
                  ? mb_strtolower(trim($d['username']))
                  : $this->generate_username($email ?: ($d['full_name'] ?? 'user'));

        $role = in_array($d['role'] ?? 'viewer', self::ROLES, TRUE) ? $d['role'] : 'viewer';

        $row = [
            'email'            => $email,
            'username'         => $username,
            'full_name'        => isset($d['full_name']) && trim((string) $d['full_name']) !== ''
                                  ? mb_substr(trim(preg_replace('/\s+/u', ' ', $d['full_name'])), 0, 120) : NULL,
            'phone'            => ! empty($d['phone']) ? $d['phone'] : NULL,
            'password_hash'    => ! empty($d['password'])
                                  ? password_hash($d['password'], PASSWORD_BCRYPT, ['cost' => $cost]) : NULL,
            'role'             => $role,
            'auth_method'      => 'password',
            'account_state'    => 'active',
            'created_via'      => substr((string) ($d['created_via'] ?? 'web'), 0, 16),
            'marketing_opt_in' => ! empty($d['marketing_opt_in']) ? 1 : 0,
            'created_at'       => $now,
            'updated_at'       => $now,
        ];

        $ok = $this->db->insert(self::TABLE, $row);

        if ($ok === FALSE) {
            $err = $this->db->error();
            throw new RuntimeException('User insert failed [' . ($err['code'] ?? 0) . ']: '
                . ($err['message'] ?? 'unknown'), (int) ($err['code'] ?? 0));
        }

        return (int) $this->db->insert_id();
    }

    // =========================================================================
    // PASSWORD
    // =========================================================================

    /** Constant-time verify. An account with no password never verifies. */
    public function verify_password($row, $password)
    {
        if ( ! is_array($row) || empty($row['password_hash'])) return FALSE;
        return password_verify((string) $password, $row['password_hash']);
    }

    /**
     * Burn a bcrypt for identifiers that do not exist, so "no such user" costs
     * the same time as a wrong password. NOT busywork — without it the timing
     * difference is an account-enumeration oracle.
     */
    public function dummy_verify()
    {
        password_verify('no-such-password', '$2y$12$C6UzMDM.H6dfI/f/IKcEe.7Sv0lFqvJDVQEVvJ0lZQZ0lZQZ0lZQZ');
    }

    public function touch_login($id, $ip = NULL)
    {
        $this->db->where('id', (int) $id)->update(self::TABLE, [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $ip ? substr((string) $ip, 0, 45) : NULL,
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);
    }

    public function password_policy_error($password)
    {
        $min = (int) ($this->cfg('password_min_length', 8) ?: 8);

        if ((string) $password === '')      return 'Choose a password.';
        if (mb_strlen($password) < $min)    return 'Password must be at least ' . $min . ' characters.';
        if ($this->cfg('password_require_mixed', TRUE)
            && ( ! preg_match('/[a-zA-Z]/', $password) || ! preg_match('/\d/', $password))) {
            return 'Password must contain at least one letter and one number.';
        }
        return '';
    }

    /**
     * Change a password knowing the current one. Revokes every refresh token —
     * a password change is what somebody does after "I think someone is in my
     * account", and it means nothing if the intruder's session can refresh.
     */
    public function change_password($id, $current, $new, $confirm)
    {
        $user = $this->find_by_id($id);
        if ( ! $user) return ['ok' => FALSE, 'errors' => ['_' => 'Account not found.']];

        if ( ! empty($user['password_hash'])) {
            if ((string) $current === '') {
                return ['ok' => FALSE, 'errors' => ['current_password' => 'Enter your current password.']];
            }
            if ( ! $this->verify_password($user, $current)) {
                return ['ok' => FALSE, 'errors' => ['current_password' => 'That password is not correct.']];
            }
        }

        $errors = [];
        if ($err = $this->password_policy_error($new)) {
            $errors['new_password'] = $err;
        } elseif ((string) $current !== '' && hash_equals((string) $current, (string) $new)) {
            $errors['new_password'] = 'Choose a password different from your current one.';
        }
        if ( ! hash_equals((string) $new, (string) $confirm)) {
            $errors['confirm_password'] = 'Passwords do not match.';
        }
        if ($errors) return ['ok' => FALSE, 'errors' => $errors];

        $this->_store_password($id, $new);
        $this->revoke_all_refresh_tokens($id);

        return ['ok' => TRUE, 'errors' => []];
    }

    /**
     * Set a password WITHOUT the previous one — the reset path, reached only
     * after a claimed single-use reset token. Nothing else may call this.
     */
    public function set_password($id, $new, $confirm)
    {
        $user = $this->find_by_id($id);
        if ( ! $user) return ['ok' => FALSE, 'errors' => ['_' => 'Account not found.']];

        $errors = [];
        if ($err = $this->password_policy_error($new)) {
            $errors['password'] = $err;
        } elseif ($this->verify_password($user, $new)) {
            $errors['password'] = 'That is already your password. Choose a different one.';
        }
        if ( ! hash_equals((string) $new, (string) $confirm)) {
            $errors['password_confirm'] = 'Passwords do not match.';
        }
        if ($errors) return ['ok' => FALSE, 'errors' => $errors];

        $this->_store_password($id, $new);
        $this->revoke_all_refresh_tokens($id);
        $this->clear_failures('acct:' . (int) $id);

        return ['ok' => TRUE, 'errors' => []];
    }

    /** Staff set a password for a staff account they manage (Admin → Staff). */
    public function admin_set_password($id, $new)
    {
        if ($err = $this->password_policy_error($new)) return $err;
        $this->_store_password($id, $new);
        $this->revoke_all_refresh_tokens($id);
        return '';
    }

    protected function _store_password($id, $new)
    {
        $cost = (int) ($this->cfg('password_hash_cost', 12) ?: 12);
        $now  = date('Y-m-d H:i:s');
        $this->db->where('id', (int) $id)->update(self::TABLE, [
            'password_hash'       => password_hash($new, PASSWORD_BCRYPT, ['cost' => $cost]),
            'password_changed_at' => $now,
            'updated_at'          => $now,
        ]);
    }

    // =========================================================================
    // PROFILE
    // =========================================================================

    /**
     * Update any subset of: full_name, username, phone, email, marketing_opt_in.
     * Only keys present in $in are touched.
     *
     * NOTE: a changed email is marked UNVERIFIED — verification proves control
     * of an address, and carrying it across to a new one would vouch for an
     * address nobody proved. Same rule for phone.
     *
     * @return array ['ok', 'errors', 'changed']
     */
    public function update_profile($id, array $in, $ip = NULL)
    {
        $id   = (int) $id;
        $user = $this->find_by_id($id);
        if ( ! $user) return ['ok' => FALSE, 'errors' => ['_' => 'Account not found.'], 'changed' => []];

        $errors = []; $set = []; $changed = [];

        if (array_key_exists('full_name', $in)) {
            $name = trim(preg_replace('/\s+/u', ' ', (string) $in['full_name']));
            if ($name === '')                             { $set['full_name'] = NULL; $changed[] = 'full_name'; }
            elseif (mb_strlen($name) > 120)               $errors['full_name'] = 'Keep your name under 120 characters.';
            elseif (preg_match('/[<>\x00-\x1F]/u', $name)) $errors['full_name'] = 'That name contains characters we cannot store.';
            else                                          { $set['full_name'] = $name; $changed[] = 'full_name'; }
        }

        if (array_key_exists('username', $in)) {
            $new = trim((string) $in['username']);
            if (mb_strtolower($new) !== mb_strtolower((string) $user['username'])) {
                if ( ! preg_match('/^[a-zA-Z][a-zA-Z0-9_]{2,31}$/', $new)) {
                    $errors['username'] = 'Use 3-32 characters: letters, numbers and underscore, starting with a letter.';
                } elseif (($left = $this->username_cooldown_left($user)) > 0) {
                    $days = (int) ceil($left / 86400);
                    $errors['username'] = 'You can change your username again in ' . $days . ' day' . ($days === 1 ? '' : 's') . '.';
                } elseif ($this->username_exists($new, $id)) {
                    $errors['username'] = 'That username is taken.';
                } else {
                    $set['username'] = mb_strtolower($new);
                    $set['username_changed_at'] = date('Y-m-d H:i:s');
                    $changed[] = 'username';
                }
            }
        }

        if (array_key_exists('phone', $in)) {
            $raw  = trim((string) $in['phone']);
            $e164 = $this->normalise_phone($raw);
            if ($raw === '') {
                if ($user['phone'] !== NULL) { $set['phone'] = NULL; $set['is_phone_verified'] = 0; $changed[] = 'phone'; }
            } elseif ($e164 === NULL) {
                $errors['phone'] = 'Enter a valid mobile number.';
            } elseif ($e164 !== (string) $user['phone']) {
                if ($this->phone_exists($e164, $id)) $errors['phone'] = 'That mobile number is already registered.';
                else { $set['phone'] = $e164; $set['is_phone_verified'] = 0; $changed[] = 'phone'; }
            }
        }

        if (array_key_exists('email', $in)) {
            $email = mb_strtolower(trim((string) $in['email']));
            if ($email === '') {
                /* Keep at least one way to recover the account: an email may only
                   be cleared by an account that has none to begin with. */
                if ( ! empty($user['email'])) $errors['email'] = 'Enter an email address.';
            } elseif ( ! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
                $errors['email'] = 'Enter a valid email address.';
            } elseif ($email !== (string) $user['email']) {
                if ($this->email_exists($email, $id)) $errors['email'] = 'That email is already registered.';
                else {
                    $set['email'] = $email; $set['is_email_verified'] = 0;
                    $set['email_verify_hash'] = NULL; $set['email_verify_expires'] = NULL;
                    $changed[] = 'email';
                }
            }
        }

        if (array_key_exists('marketing_opt_in', $in)) {
            $v = ! empty($in['marketing_opt_in']) ? 1 : 0;
            if ($v !== (int) $user['marketing_opt_in']) { $set['marketing_opt_in'] = $v; $changed[] = 'marketing_opt_in'; }
        }

        if ($errors) return ['ok' => FALSE, 'errors' => $errors, 'changed' => []];
        if ( ! $set) return ['ok' => TRUE, 'errors' => [], 'changed' => []];

        if (isset($set['username'])) {
            $this->db->insert(self::T_UNAME_HISTORY, [
                'user_id'    => $id,
                'username'   => $user['username'],
                'held_from'  => ! empty($user['username_changed_at']) ? $user['username_changed_at'] : $user['created_at'],
                'held_until' => date('Y-m-d H:i:s'),
                'changed_ip' => $ip ? substr((string) $ip, 0, 45) : NULL,
            ]);
        }

        $set['updated_at'] = date('Y-m-d H:i:s');

        try {
            $this->db->where('id', $id)->update(self::TABLE, $set);
        } catch (Throwable $e) {
            if ((int) $e->getCode() === 1062 || stripos($e->getMessage(), 'duplicate') !== FALSE) {
                return ['ok' => FALSE, 'changed' => [], 'errors' => ['_' => 'That email, username or number was just taken.']];
            }
            log_message('error', '[User] profile update failed: ' . $e->getMessage());
            return ['ok' => FALSE, 'changed' => [], 'errors' => ['_' => 'Could not save your changes.']];
        }

        return ['ok' => TRUE, 'errors' => [], 'changed' => $changed];
    }

    public function username_cooldown_left($user)
    {
        $v    = $this->cfg('username_change_cooldown_days', 30);
        $days = ($v === NULL || $v === '' || $v === FALSE) ? 30 : (int) $v;
        if ($days <= 0 || empty($user['username_changed_at'])) return 0;
        $left = strtotime($user['username_changed_at'] . ' UTC') + $days * 86400 - time();
        return $left > 0 ? $left : 0;
    }

    // =========================================================================
    // STAFF & CUSTOMER ADMINISTRATION
    // =========================================================================

    public function set_role($id, $role)
    {
        if ( ! in_array($role, self::ROLES, TRUE)) return FALSE;
        $this->db->where('id', (int) $id)->update(self::TABLE, ['role' => $role, 'updated_at' => date('Y-m-d H:i:s')]);
        return TRUE;
    }

    public function set_state($id, $state)
    {
        if ( ! in_array($state, ['active', 'suspended', 'closed'], TRUE)) return FALSE;
        $this->db->where('id', (int) $id)->update(self::TABLE, ['account_state' => $state, 'updated_at' => date('Y-m-d H:i:s')]);
        if ($state !== 'active') $this->revoke_all_refresh_tokens($id);
        return TRUE;
    }

    public function count_admins()
    {
        return (int) $this->db->where('role', 'admin')->where('account_state', 'active')->count_all_results(self::TABLE);
    }

    /**
     * Customer search for the POS and the back office: name, email, phone,
     * username. A phone-shaped query is also normalised to E.164.
     */
    public function search($q, $limit = 20, $roles = NULL)
    {
        $q = trim((string) $q);
        $this->db->select('id, email, username, full_name, phone, role, account_state, created_at')
                 ->from(self::TABLE);

        if ($roles) $this->db->where_in('role', (array) $roles);

        if ($q !== '') {
            $phone = $this->normalise_phone($q);
            $this->db->group_start()
                     ->like('full_name', $q)
                     ->or_like('email', mb_strtolower($q))
                     ->or_like('username', mb_strtolower($q))
                     ->or_like('phone', preg_replace('/[^\d+]/', '', $q));
            if ($phone) $this->db->or_where('phone', $phone);
            $this->db->group_end();
        }

        return $this->db->order_by('full_name', 'ASC')->limit(max(1, min(100, (int) $limit)))->get()->result_array();
    }

    // =========================================================================
    // EMAIL VERIFICATION
    // =========================================================================

    public function set_email_verify_token($id, $hash, $expires_ts)
    {
        $this->db->where('id', (int) $id)->update(self::TABLE, [
            'email_verify_hash'    => $hash,
            'email_verify_expires' => date('Y-m-d H:i:s', (int) $expires_ts),
            'updated_at'           => date('Y-m-d H:i:s'),
        ]);
    }

    public function clear_email_verify_token($id)
    {
        $this->db->where('id', (int) $id)->update(self::TABLE, [
            'email_verify_hash' => NULL, 'email_verify_expires' => NULL, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Redeem a verification token, atomically and single-use. The candidate id
     * is read FIRST because the UPDATE clears the hash that identifies the row.
     *
     * @return int the verified user's id, or 0
     */
    public function claim_email_verify($hash)
    {
        $now = date('Y-m-d H:i:s');

        $row = $this->db->select('id')
                        ->where('email_verify_hash', $hash)
                        ->where('email_verify_expires >', $now)
                        ->where('account_state', 'active')
                        ->limit(1)->get(self::TABLE)->row_array();
        if ( ! $row) return 0;

        $id = (int) $row['id'];
        $this->db->query(
            'UPDATE ' . self::TABLE . '
                SET is_email_verified = 1, email_verify_hash = NULL, email_verify_expires = NULL, updated_at = ?
              WHERE id = ? AND email_verify_hash = ? AND email_verify_expires > ? AND account_state = ?',
            [$now, $id, $hash, $now, 'active']
        );

        return ((int) $this->db->affected_rows() === 1) ? $id : 0;
    }

    // =========================================================================
    // AVATAR
    // =========================================================================

    public function set_avatar($id, $filename)
    {
        $this->db->where('id', (int) $id)->update(self::TABLE, [
            'avatar_file' => $filename, 'avatar_updated_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function clear_avatar($id)
    {
        $this->db->where('id', (int) $id)->update(self::TABLE, [
            'avatar_file' => NULL, 'avatar_updated_at' => NULL, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    // =========================================================================
    // SIGN-IN / PIN THROTTLE
    // =========================================================================

    public function lockout_remaining($scope_key)
    {
        $row = $this->db->select('locked_until')->where('scope_key', $scope_key)
                        ->limit(1)->get(self::TABLE_ATTEMPTS)->row_array();
        if ( ! $row || empty($row['locked_until'])) return 0;
        $remaining = strtotime($row['locked_until']) - time();
        return $remaining > 0 ? $remaining : 0;
    }

    /**
     * Count one failure for a scope; lock it once the threshold is crossed.
     * Atomic upsert — SELECT-then-UPDATE drops attempts under concurrency, which
     * is exactly the condition an attacker creates.
     */
    public function record_failure($scope_key, $max = NULL, $lock_s = NULL)
    {
        $max    = (int) ($max    ?: $this->cfg('login_max_attempts', 5));
        $lock_s = (int) ($lock_s ?: $this->cfg('login_lockout_s', 900));
        $now    = date('Y-m-d H:i:s');
        $cut    = date('Y-m-d H:i:s', time() - $lock_s);

        $this->db->query(
            'INSERT INTO ' . self::TABLE_ATTEMPTS . ' (scope_key, attempts, window_start, updated_at)
             VALUES (?, 1, ?, ?)
             ON DUPLICATE KEY UPDATE
               attempts     = IF(window_start < ?, 1, attempts + 1),
               window_start = IF(window_start < ?, ?, window_start),
               updated_at   = ?',
            [$scope_key, $now, $now, $cut, $cut, $now, $now]
        );

        $row = $this->db->select('attempts')->where('scope_key', $scope_key)
                        ->limit(1)->get(self::TABLE_ATTEMPTS)->row_array();

        if ($row && (int) $row['attempts'] >= $max) {
            $this->db->where('scope_key', $scope_key)->update(self::TABLE_ATTEMPTS, [
                'locked_until' => date('Y-m-d H:i:s', time() + $lock_s),
                'updated_at'   => date('Y-m-d H:i:s'),
            ]);
            return $lock_s;
        }
        return 0;
    }

    public function clear_failures($scope_key)
    {
        $this->db->where('scope_key', $scope_key)->delete(self::TABLE_ATTEMPTS);
    }

    // =========================================================================
    // REFRESH TOKENS
    // =========================================================================

    /** Mint one; only its SHA-256 is stored, the plaintext is returned once. */
    public function issue_refresh_token($user_id, $ip = NULL, $user_agent = NULL)
    {
        $ttl   = (int) ($this->cfg('refresh_token_expiry_s', 2592000) ?: 2592000);
        $plain = bin2hex(random_bytes(32));

        $this->db->insert(self::TABLE_REFRESH, [
            'user_id'    => (int) $user_id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => date('Y-m-d H:i:s', time() + $ttl),
            'user_agent' => $user_agent ? substr((string) $user_agent, 0, 255) : NULL,
            'created_ip' => $ip ? substr((string) $ip, 0, 45) : NULL,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $this->_prune_refresh_tokens($user_id);
        return $plain;
    }

    /** Expired rows, and rows revoked more than a week ago (kept briefly for reuse detection). */
    protected function _prune_refresh_tokens($user_id)
    {
        try {
            $this->db->query(
                'DELETE FROM ' . self::TABLE_REFRESH . '
                  WHERE user_id = ? AND (expires_at < ? OR (revoked_at IS NOT NULL AND revoked_at < ?))',
                [(int) $user_id, date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() - 7 * 86400)]
            );
        } catch (Throwable $e) {
            log_message('error', '[User_model] refresh-token prune failed: ' . $e->getMessage());
        }
    }

    public function user_for_refresh_token($plain)
    {
        if ( ! is_string($plain) || $plain === '') return NULL;
        $row = $this->db->where('token_hash', hash('sha256', $plain))
                        ->where('revoked_at IS NULL', NULL, FALSE)
                        ->where('expires_at >', date('Y-m-d H:i:s'))
                        ->limit(1)->get(self::TABLE_REFRESH)->row_array();
        return $row ? $this->find_by_id($row['user_id']) : NULL;
    }

    public function revoke_refresh_token($plain)
    {
        $this->db->where('token_hash', hash('sha256', (string) $plain))
                 ->update(self::TABLE_REFRESH, ['revoked_at' => date('Y-m-d H:i:s')]);
    }

    public function revoke_all_refresh_tokens($user_id)
    {
        $this->db->where('user_id', (int) $user_id)->where('revoked_at IS NULL', NULL, FALSE)
                 ->update(self::TABLE_REFRESH, ['revoked_at' => date('Y-m-d H:i:s')]);
    }

    // =========================================================================
    // RETENTION  (called from Chaincli::cron)
    // =========================================================================

    /**
     * Sweep the append-only auth tables in bounded batches. A live lockout is
     * never swept. Boundaries are PHP UTC — never NOW() in SQL.
     */
    public function prune_auth_tables($days = NULL)
    {
        $days   = max(1, (int) ($days ?: $this->cfg('retain_rate_limit_days', 7)));
        $now    = date('Y-m-d H:i:s');
        $cutoff = date('Y-m-d H:i:s', time() - $days * 86400);

        return [
            'rate_limits'    => $this->_delete_batched('DELETE FROM gp_rate_limits WHERE window_start < ? LIMIT 5000', [$cutoff], 'gp_rate_limits'),
            'login_attempts' => $this->_delete_batched('DELETE FROM ' . self::TABLE_ATTEMPTS . ' WHERE window_start < ? AND (locked_until IS NULL OR locked_until < ?) LIMIT 5000', [$cutoff, $now], 'gp_login_attempts'),
            'refresh_tokens' => $this->_delete_batched('DELETE FROM ' . self::TABLE_REFRESH . ' WHERE expires_at < ? LIMIT 5000', [$now], 'gp_refresh_tokens'),
        ];
    }

    protected function _delete_batched($sql, array $binds, $label, $max_passes = 20)
    {
        $removed = 0;
        try {
            for ($i = 0; $i < $max_passes; $i++) {
                $this->db->query($sql, $binds);
                $n = (int) $this->db->affected_rows();
                $removed += $n;
                if ($n < 5000) break;
            }
        } catch (Throwable $e) {
            log_message('error', '[User_model] retention sweep of ' . $label . ' failed after ' . $removed . ' rows: ' . $e->getMessage());
        }
        return $removed;
    }
}
