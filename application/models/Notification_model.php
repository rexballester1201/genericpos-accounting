<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Notification_model.php — in-app notifications, and the email copy
 *
 * GenericPOS · table gp_notifications
 *
 * ─── THE IN-APP ROW IS THE RECORD; EMAIL IS A COPY ────────────────────────
 * push() always writes the row. It sends the email only when the address is
 * VERIFIED and email notifications are enabled — and a failure to send is
 * logged, never propagated.
 *
 * Never make the email the only place something is said. Delivery fails, gets
 * filtered, or is switched off entirely, and a user who never receives one
 * must still find the notification when they open the app.
 *
 * ─── $email = FALSE FOR ANYTHING THAT REPEATS ─────────────────────────────
 * The email copy is sent SYNCHRONOUSLY: one SMTP connection, inside the
 * request that called push(). That is fine for a single event. It is NOT
 * fine on a path that notifies several people at once, where every SMTP
 * round trip is added to the wait of whoever clicked. A stream of routine
 * emails is also the fastest way to teach somebody to filter everything this
 * system sends. Those pass $email = FALSE and stay in-app, where the user
 * sees them next time they open the app.
 *
 * ─── NOTIFYING NEVER BREAKS A WRITE ───────────────────────────────────────
 * Every call site sits immediately after a write that has already committed.
 * push() therefore catches everything and returns a bool;
 * it cannot throw. A notification that aborts the request that credited
 * somebody is far worse than a notification nobody received.
 */
class Notification_model extends CI_Model
{
    const T = 'gp_notifications';

    /** Keep a user's list bounded; older rows are pruned past this. */
    const KEEP_PER_USER = 200;

    public function __construct()
    {
        parent::__construct();
        $this->config->load('app', FALSE, TRUE);
    }

    protected function now() { return date('Y-m-d H:i:s'); }

    // =========================================================================
    // WRITE
    // =========================================================================

    /**
     * Notify one user. NEVER throws.
     *
     * @param  int    $user_id
     * @param  string $type   machine key, e.g. 'deposit.credited'
     * @param  string $title
     * @param  string $body
     * @param  string $url    an in-app ROUTE ('/wallet'), never an absolute URL
     * @param  bool   $email  send the copies outside the app as well: the email,
     *                        and a notification on the user's devices
     * @return bool           whether the in-app row was written
     */
    public function push($user_id, $type, $title, $body = '', $url = NULL, $email = TRUE)
    {
        $user_id = (int) $user_id;
        if ($user_id <= 0) return FALSE;

        /* Refused rather than trimmed: an absolute URL here is either a
           broken client-side navigation or, pointed off-site, a
           notification that redirects users away from the app. */
        $route = ($url !== NULL && strpos((string) $url, '/') === 0) ? substr((string) $url, 0, 120) : NULL;

        try {
            $this->db->insert(self::T, [
                'user_id'    => $user_id,
                'type'       => substr((string) $type, 0, 40),
                'title'      => substr((string) $title, 0, 120),
                'body'       => substr((string) $body, 0, 500),
                'url'        => $route,
                'created_at' => $this->now(),
            ]);
        } catch (Exception $e) {
            log_message('error', '[Notify] insert failed for user ' . $user_id . ': ' . $e->getMessage());
            return FALSE;
        }

        if ($email) {
            $this->_email_copy($user_id, $title, $body, $route);
        }
        $this->_prune($user_id);

        return TRUE;
    }

    /**
     * The email copy. Sent only to a VERIFIED address.
     *
     * NOTE: the verified gate is the point of the feature, not a nicety. An
     * unverified address is one nobody has proved they control — it may be
     * mistyped, or somebody else's. Mailing account activity to it is a
     * disclosure, and mailing enough of it makes this system a spam source
     * for whoever owns the real inbox.
     *
     * NOTE: nothing here can be reached until Auth::email_verify() marks an
     * account verified. Before that endpoint existed this gate was closed for
     * every account, so the feature would have sent zero
     * emails and looked broken.
     */
    protected function _email_copy($user_id, $title, $body, $url)
    {
        if ( ! $this->config->item('notify_email_enabled')) return;

        try {
            $this->load->model('User_model', 'users');
            $user = $this->users->find_by_id($user_id);

            if ( ! $user)                            return;
            if ( ! (int) $user['is_email_verified']) return;

            $email = trim((string) $user['email']);
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) return;

            $this->load->library('Mailer_lib', NULL, 'mailer');

            list($ready, $why) = $this->mailer->readiness();
            if ( ! $ready) {
                log_message('error', '[Notify] mailer not ready, in-app only: ' . $why);
                return;
            }

            $link = ($url !== NULL) ? rtrim(base_url(), '/') . $url : NULL;
            $res  = $this->mailer->send_notification($email, $title, $body, $link);

            if ( ! $res['ok']) {
                log_message('error', '[Notify] email copy failed for user ' . $user_id . ': ' . $res['error']);
            }
        } catch (Exception $e) {
            log_message('error', '[Notify] email copy threw for user ' . $user_id . ': ' . $e->getMessage());
        }
    }

    /**
     * Keep each user's list bounded.
     *
     * NOTE: deletes by ID, not by age. A user who has been quiet for a year
     * should still see their last few notifications; a busy one should not
     * accumulate thousands. Age-based pruning gets both of those wrong.
     */
    protected function _prune($user_id)
    {
        try {
            $row = $this->db->query(
                'SELECT id FROM ' . self::T . '
                  WHERE user_id = ? ORDER BY id DESC LIMIT 1 OFFSET ?',
                [(int) $user_id, self::KEEP_PER_USER]
            )->row_array();

            if ($row) {
                $this->db->where('user_id', (int) $user_id)
                         ->where('id <=', (int) $row['id'])
                         ->delete(self::T);
            }
        } catch (Exception $e) {
            // Housekeeping only. A failure here must not affect the push.
            log_message('error', '[Notify] prune failed: ' . $e->getMessage());
        }
    }

    // =========================================================================
    // READ
    // =========================================================================

    public function unread_count($user_id)
    {
        return (int) $this->db->where('user_id', (int) $user_id)
                              ->where('is_read', 0)
                              ->count_all_results(self::T);
    }

    public function listing($user_id, $limit = 20, $offset = 0)
    {
        return $this->db->where('user_id', (int) $user_id)
                        ->order_by('id', 'DESC')
                        ->limit((int) $limit, (int) $offset)
                        ->get(self::T)->result_array();
    }

    public function count_all($user_id)
    {
        return (int) $this->db->where('user_id', (int) $user_id)
                              ->count_all_results(self::T);
    }

    /**
     * Mark one notification read, or every one when $id is 0.
     *
     * NOTE: scoped to the user on BOTH paths. Without the user_id in the WHERE,
     * a user could mark somebody else's notification read by guessing an id —
     * harmless in itself, and exactly the shape of bug that turns out not to be
     * when the same pattern is copied to a table that matters.
     *
     * @return int rows affected
     */
    public function mark_read($user_id, $id = 0)
    {
        $this->db->where('user_id', (int) $user_id)->where('is_read', 0);

        if ((int) $id > 0) $this->db->where('id', (int) $id);

        $this->db->update(self::T, ['is_read' => 1, 'read_at' => $this->now()]);

        return (int) $this->db->affected_rows();
    }
}
