<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * admin/Staff.php — the people who use the ledger, and their roles
 *
 * GenericPOS Accounting · administrators only
 *
 *   GET  /api/v1/admin/staff        every account
 *   POST /api/v1/admin/staff        { full_name, email, phone?, role, password }
 *   PUT  /api/v1/admin/staff/{id}   { full_name?, email?, phone?, role?, account_state?, password? }
 *
 * Roles, lowest to highest (PLAN.md §8): a viewer reads reports; a bookkeeper
 * also prepares entries; an accountant also approves and posts them and
 * closes months; an administrator also runs the chart, fiscal years, users,
 * settings and the audit log.
 *
 * NOTE: THE LAST ACTIVE ADMINISTRATOR CANNOT BE DEMOTED OR SUSPENDED — the
 * company would be left with nobody who can reach Settings or Users.
 */
class Staff extends CI_Controller
{
    private $claims;

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->claims = admin_check();
        $this->load->model('User_model', 'users');
    }

    private function uid() { return (int) $this->claims['user_id']; }

    private function _row($id)
    {
        $r = $this->db->get_where('gp_users', ['id' => (int) $id], 1)->row_array();
        return $r ?: NULL;
    }

    private function _shape(array $u)
    {
        return [
            'id' => (int) $u['id'], 'full_name' => $u['full_name'], 'username' => $u['username'],
            'email' => $u['email'], 'phone' => $u['phone'], 'role' => $u['role'], 'account_state' => $u['account_state'],
            'has_password' => ! empty($u['password_hash']), 'is_email_verified' => (bool) (int) $u['is_email_verified'],
            'last_login_at' => $u['last_login_at'], 'created_at' => $u['created_at'],
        ];
    }

    /** GET /api/v1/admin/staff */
    public function index()
    {
        require_method('GET');
        $rows = $this->db->order_by("FIELD(role, 'admin', 'accountant', 'bookkeeper', 'viewer')", '', FALSE)
                         ->order_by('full_name', 'ASC')->get('gp_users')->result_array();
        return json_response([
            'items' => array_map(function ($u) { return $this->_shape($u); }, $rows),
            'roles' => User_model::ROLES,
        ], 'Users');
    }

    /** POST /api/v1/admin/staff */
    public function create()
    {
        require_method('POST');
        rate_limit($this->uid(), 'admin_write');

        list($d, $errors) = $this->_validate(get_json_body(), NULL);
        if ($errors) return json_invalid($errors);

        try {
            $id = $this->users->create([
                'full_name' => $d['full_name'], 'email' => $d['email'], 'phone' => $d['phone'],
                'password' => $d['password'], 'role' => $d['role'], 'created_via' => 'admin',
            ]);
        } catch (Throwable $e) {
            return json_invalid(['email' => 'Another account already uses this email or mobile number.']);
        }

        log_admin_action($this->claims, 'staff.create', 'user', $id, ['role' => $d['role'], 'email' => $d['email']]);
        return json_response($this->_shape($this->_row($id)), $d['full_name'] . ' can now sign in.', 201);
    }

    /** PUT /api/v1/admin/staff/{id} */
    public function update($id)
    {
        require_method('PUT');
        rate_limit($this->uid(), 'admin_write');

        $row = $this->_row((int) $id);
        if ( ! $row) return json_error('That account no longer exists.', 404);

        list($d, $errors) = $this->_validate(get_json_body(), $row);
        if ($errors) return json_invalid($errors);

        $leaving_admin = $row['role'] === 'admin' && $row['account_state'] === 'active'
            && ((isset($d['role']) && $d['role'] !== 'admin') || (isset($d['account_state']) && $d['account_state'] !== 'active'));
        if ($leaving_admin && $this->users->count_admins() <= 1) {
            return json_invalid(['role' => 'This is the only administrator. Make someone else an administrator first.']);
        }
        if ((int) $row['id'] === $this->uid() && isset($d['account_state']) && $d['account_state'] !== 'active') {
            return json_invalid(['account_state' => 'You cannot suspend your own account.']);
        }

        $upd = array_intersect_key($d, array_flip(['full_name', 'email', 'phone']));
        /* A new address is unproven, whoever typed it. */
        if (array_key_exists('email', $upd) && $upd['email'] !== $row['email']) $upd['is_email_verified'] = 0;
        if (array_key_exists('phone', $upd) && $upd['phone'] !== $row['phone']) $upd['is_phone_verified'] = 0;
        if ($upd) {
            $upd['updated_at'] = date('Y-m-d H:i:s');
            $this->db->where('id', (int) $row['id'])->update('gp_users', $upd);
        }
        if (isset($d['role']) && $d['role'] !== $row['role'] && ! $this->users->set_role((int) $row['id'], $d['role'])) {
            return json_error('That role could not be saved.', 500);
        }
        if (isset($d['account_state']) && $d['account_state'] !== $row['account_state']) $this->users->set_state((int) $row['id'], $d['account_state']);
        if ($d['password'] !== '') $this->users->admin_set_password((int) $row['id'], $d['password']);

        log_admin_action($this->claims, 'staff.update', 'user', (int) $row['id'], array_filter([
            'role'          => (isset($d['role']) && $d['role'] !== $row['role']) ? ['from' => $row['role'], 'to' => $d['role']] : NULL,
            'account_state' => (isset($d['account_state']) && $d['account_state'] !== $row['account_state']) ? ['from' => $row['account_state'], 'to' => $d['account_state']] : NULL,
            'password'      => $d['password'] !== '' ? 'set' : NULL,
        ]));
        return json_response($this->_shape($this->_row((int) $row['id'])), 'Saved.');
    }

    /** @return array [data, errors] — only the keys present are validated on an update. */
    private function _validate(array $in, $row)
    {
        $new  = $row === NULL;
        $self = $row ? (int) $row['id'] : 0;
        $d = [];
        $e = [];

        if ($new || array_key_exists('full_name', $in)) {
            $d['full_name'] = clean_line($in['full_name'] ?? '', 120);
            if ($d['full_name'] === '') $e['full_name'] = 'Enter a name.';
        }
        if ($new || array_key_exists('email', $in)) {
            $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
            if ($email === '' && ($new || ! empty($row['email']))) $e['email'] = 'Enter their email address — it is how they reset a forgotten password.';
            elseif ($email !== '' && ( ! filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190)) $e['email'] = 'Enter a valid email address.';
            elseif ($email !== '' && $this->users->email_exists($email, $self)) $e['email'] = 'Another account already uses this email.';
            $d['email'] = $email !== '' ? $email : NULL;
        }
        if ($new || array_key_exists('phone', $in)) {
            $raw   = trim((string) ($in['phone'] ?? ''));
            $phone = NULL;
            if ($raw !== '') {
                $phone = $this->users->normalise_phone($raw);
                if ($phone === NULL) $e['phone'] = 'Enter a valid mobile number.';
                elseif ($this->users->phone_exists($phone, $self)) $e['phone'] = 'Another account already uses this number.';
            }
            $d['phone'] = $phone;
        }
        if ($new || array_key_exists('role', $in)) {
            $d['role'] = (string) ($in['role'] ?? '');
            if ( ! in_array($d['role'], User_model::ROLES, TRUE)) $e['role'] = 'Choose viewer, bookkeeper, accountant or administrator.';
        }
        if (array_key_exists('account_state', $in)) {
            $d['account_state'] = (string) $in['account_state'];
            if ( ! in_array($d['account_state'], ['active', 'suspended'], TRUE)) $e['account_state'] = 'Choose active or suspended.';
        }

        $d['password'] = (string) ($in['password'] ?? '');
        if ($new && $d['password'] === '') $e['password'] = 'Give them a password to sign in with.';
        elseif ($d['password'] !== '' && ($err = $this->users->password_policy_error($d['password']))) $e['password'] = $err;

        return [$d, $e];
    }
}
