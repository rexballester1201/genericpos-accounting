<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * PasswordReset_model.php — issue, verify and consume password-reset tokens
 *
 * GenericPOS · CodeIgniter 3.1.9 · table gp_password_resets
 *
 * Every method assumes the caller has loaded the database — 'database' is not
 * autoloaded; see application/config/autoload.php.
 *
 * ─── WHAT A TOKEN IS ──────────────────────────────────────────────────────
 * A reset token sets a password WITHOUT knowing the old one. It is the
 * strongest credential this system issues — stronger than a refresh token,
 * which only extends a session that already exists. Treat it like a password
 * in every respect: hashed at rest, never logged, never echoed, short-lived,
 * and single-use.
 *
 * ─── THE FOUR RULES ───────────────────────────────────────────────────────
 *   1. Stored HASHED. The plaintext exists only in the email and the URL.
 *   2. SINGLE-USE, claimed with a conditional UPDATE — never SELECT-then-UPDATE.
 *   3. Issuing a new token VOIDS the outstanding ones for that account.
 *   4. Rows are kept after use. "Was this link issued, and was it used?" is the
 *      first question asked when a takeover is reported.
 */
class PasswordReset_model extends CI_Model
{
    const TABLE = 'gp_password_resets';

    /** Hex characters in a token: 32 random bytes. */
    const TOKEN_BYTES = 32;

    // =========================================================================
    // ISSUE
    // =========================================================================

    /**
     * Mint a reset token for a user and return the PLAINTEXT once.
     *
     * NOTE: the plaintext is returned here and is never recoverable again —
     * only its SHA-256 hash is stored. If the mail send fails, void the token
     * (see void_token) and issue a fresh one; there is no "look it up".
     *
     * @return array ['token' => string, 'id' => int, 'expires_at' => 'Y-m-d H:i:s']
     */
    public function issue($user_id, $ip = NULL, $user_agent = NULL)
    {
        $user_id = (int) $user_id;

        $this->config->load('app', FALSE, TRUE);
        $ttl = (int) ($this->config->item('password_reset_expiry_s') ?: 3600);

        /* NOTE: outstanding tokens are voided BEFORE the new one is inserted.
           Two live links for one account means a user who requests a reset
           twice (because the first mail was slow) leaves the older link
           working — and that older link is the one sitting in an inbox that may
           have been the reason they were resetting. Newest wins, always. */
        $this->void_outstanding($user_id, 'superseded');

        $plain = bin2hex(random_bytes(self::TOKEN_BYTES));
        $now   = date('Y-m-d H:i:s');
        $exp   = date('Y-m-d H:i:s', time() + $ttl);

        $this->db->insert(self::TABLE, [
            'user_id'      => $user_id,
            'token_hash'   => hash('sha256', $plain),
            'expires_at'   => $exp,
            'requested_ip' => $ip ? substr((string) $ip, 0, 45) : NULL,
            'requested_ua' => $user_agent ? substr((string) $user_agent, 0, 255) : NULL,
            'created_at'   => $now,
        ]);

        return [
            'token'      => $plain,
            'id'         => (int) $this->db->insert_id(),
            'expires_at' => $exp,
        ];
    }

    // =========================================================================
    // VERIFY
    // =========================================================================

    /**
     * Resolve a plaintext token to its row, or NULL.
     *
     * Returns NULL for unknown, expired, used and voided alike — the caller
     * gets one answer, "this link does not work", and cannot accidentally build
     * a more helpful message out of the difference. Distinguishing "already
     * used" from "never existed" tells an attacker holding a stolen mailbox
     * archive which links are still live.
     */
    public function find_valid($plain)
    {
        if ( ! is_string($plain) || $plain === '') return NULL;

        /* NOTE: the length and charset check happens BEFORE the query. A token
           is always 64 hex characters; anything else cannot match a CHAR(64)
           hash column and there is no reason to spend a round trip proving it.
           It also keeps junk out of the query log. */
        if ( ! preg_match('/^[0-9a-f]{64}$/', $plain)) return NULL;

        $row = $this->db
            ->where('token_hash', hash('sha256', $plain))
            ->where('used_at IS NULL', NULL, FALSE)
            ->where('voided_at IS NULL', NULL, FALSE)
            ->where('expires_at >', date('Y-m-d H:i:s'))
            ->limit(1)
            ->get(self::TABLE)
            ->row_array();

        return $row ?: NULL;
    }

    // =========================================================================
    // CONSUME
    // =========================================================================

    /**
     * Claim a token for use, atomically. Returns the row on success, NULL when
     * the token was already used, voided, expired or never existed.
     *
     * NOTE: THE CONDITIONAL UPDATE IS THE WHOLE POINT. The obvious shape —
     * find_valid() then update — has a window between the two in which a second
     * request can also pass find_valid(), and both then set a password. That is
     * not theoretical: a double-click submits twice, a retried request replays,
     * and some mail scanners fetch every URL in a message before the user
     * ever sees it. Here the UPDATE's own WHERE clause is the lock, and
     * affected_rows() reports which caller won.
     *
     * The row is re-read AFTER the claim so the caller gets the user_id from a
     * row it is now the sole owner of.
     */
    public function claim($plain, $ip = NULL)
    {
        if ( ! is_string($plain) || ! preg_match('/^[0-9a-f]{64}$/', $plain)) return NULL;

        $hash = hash('sha256', $plain);
        $now  = date('Y-m-d H:i:s');

        $this->db->query(
            'UPDATE ' . self::TABLE . '
                SET used_at = ?, used_ip = ?
              WHERE token_hash = ?
                AND used_at   IS NULL
                AND voided_at IS NULL
                AND expires_at > ?',
            [$now, $ip ? substr((string) $ip, 0, 45) : NULL, $hash, $now]
        );

        if ((int) $this->db->affected_rows() !== 1) return NULL;

        return $this->db->where('token_hash', $hash)->limit(1)
                        ->get(self::TABLE)->row_array() ?: NULL;
    }

    // =========================================================================
    // VOID
    // =========================================================================

    /**
     * Void every outstanding (unused, unvoided) token for a user.
     *
     * Called when a new token is issued, and — importantly — after ANY password
     * change, including one made from the account screen with the current
     * password. A user who changes their password because they suspect
     * someone else has access must not leave a live reset link behind that
     * hands that same someone the account back.
     *
     * @return int rows voided
     */
    public function void_outstanding($user_id, $reason = '')
    {
        $this->db->where('user_id', (int) $user_id)
                 ->where('used_at IS NULL', NULL, FALSE)
                 ->where('voided_at IS NULL', NULL, FALSE)
                 ->update(self::TABLE, ['voided_at' => date('Y-m-d H:i:s')]);

        $n = (int) $this->db->affected_rows();

        if ($n > 0 && $reason !== '') {
            // No token material in the log line, by design.
            log_message('info', '[PasswordReset] Voided ' . $n . ' outstanding token(s) for user '
                              . (int) $user_id . ' (' . $reason . ').');
        }

        return $n;
    }

    /**
     * Void one specific token by its plaintext. Used when a token was issued
     * but the mail could not be delivered — leaving it live would mean a
     * credential exists that nobody received and nobody can account for.
     */
    public function void_token($plain)
    {
        if ( ! is_string($plain) || ! preg_match('/^[0-9a-f]{64}$/', $plain)) return FALSE;

        $this->db->where('token_hash', hash('sha256', $plain))
                 ->where('used_at IS NULL', NULL, FALSE)
                 ->where('voided_at IS NULL', NULL, FALSE)
                 ->update(self::TABLE, ['voided_at' => date('Y-m-d H:i:s')]);

        return (int) $this->db->affected_rows() === 1;
    }

    // =========================================================================
    // HOUSEKEEPING
    // =========================================================================

    /**
     * How many reset requests this account has made inside the window. Read by
     * the per-account throttle.
     *
     * NOTE: counts REQUESTS, not live tokens. Counting live tokens would always
     * return at most 1, because issuing voids the previous ones — the throttle
     * would never trip and an attacker could mail-bomb one user indefinitely.
     */
    public function requests_since($user_id, $since_ts)
    {
        return (int) $this->db
            ->where('user_id', (int) $user_id)
            ->where('created_at >=', date('Y-m-d H:i:s', (int) $since_ts))
            ->count_all_results(self::TABLE);
    }

    /**
     * Delete rows older than retain_password_reset_days. For the retention
     * cron; nothing calls it on a request path.
     */
    public function prune()
    {
        $this->config->load('app', FALSE, TRUE);
        $days = (int) ($this->config->item('retain_password_reset_days') ?: 180);

        $this->db->where('created_at <', date('Y-m-d H:i:s', time() - ($days * 86400)))
                 ->delete(self::TABLE);

        return (int) $this->db->affected_rows();
    }
}
