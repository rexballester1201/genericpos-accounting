<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Notifications.php — what the ledger has told you
 *
 * GenericPOS Accounting · every signed-in role
 *
 *   GET  /api/v1/notifications         ?page=&per_page=  newest first, with the unread count
 *   POST /api/v1/notifications/read    { id } one, or { all: true } every one
 *
 * Entries waiting for approval, entries rejected back to their preparer and
 * entries posted all arrive here (Journal_model). Scoped to the signed-in
 * user on every path (Notification_model).
 */
class Notifications extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->load->model('Notification_model', 'notify');
    }

    /** GET /api/v1/notifications */
    public function index()
    {
        $claims = viewer_check();
        require_method('GET');

        $uid   = (int) $claims['user_id'];
        $p     = get_pagination_params(20, 100);
        $items = array_map(function ($n) {
            return ['id' => (int) $n['id'], 'type' => $n['type'], 'title' => $n['title'], 'body' => $n['body'],
                    'url' => $n['url'], 'is_read' => (bool) (int) $n['is_read'], 'created_at' => $n['created_at']];
        }, $this->notify->listing($uid, $p['limit'], $p['offset']));

        $out = build_pagination_meta($items, $this->notify->count_all($uid), $p['page'], $p['limit']);
        $out['unread'] = $this->notify->unread_count($uid);
        return json_response($out, 'Notifications');
    }

    /** POST /api/v1/notifications/read */
    public function read()
    {
        $claims = viewer_check();
        require_method('POST');

        $in  = get_json_body();
        $uid = (int) $claims['user_id'];
        $all = ! empty($in['all']);
        $id  = (int) ($in['id'] ?? 0);
        if ( ! $all && $id <= 0) return json_invalid(['id' => 'Say which notification, or all of them.']);

        $n = $this->notify->mark_read($uid, $all ? 0 : $id);
        return json_response(['marked' => $n, 'unread' => $this->notify->unread_count($uid)], $all ? 'All marked as read.' : 'Marked as read.');
    }
}
