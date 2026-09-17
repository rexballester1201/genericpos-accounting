<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * admin/Audit.php — the audit log
 *
 * GenericPOS Accounting · administrators only
 *
 *   GET /api/v1/admin/audit?q=&action=&page=&per_page=   every recorded change, newest first
 *
 * The log is append-only: nothing here writes to it, and nothing anywhere
 * edits or deletes a row.
 */
class Audit extends CI_Controller
{
    private $claims;

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
        $this->claims = admin_check();
    }

    /** GET /api/v1/admin/audit */
    public function index()
    {
        require_method('GET');
        $p = get_pagination_params(50, 200);
        $w = ' WHERE 1=1';
        $b = [];

        $q = trim((string) $this->input->get('q'));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 80)) . '%';
            $w .= ' AND (a.action LIKE ? OR a.detail LIKE ? OR u.full_name LIKE ? OR u.username LIKE ?)';
            array_push($b, $like, $like, $like, $like);
        }
        $act = preg_replace('/[^a-z0-9_.]/', '', strtolower((string) $this->input->get('action')));
        if ($act !== '') { $w .= ' AND a.action LIKE ?'; $b[] = $act . '%'; }

        $from  = ' FROM gp_admin_audit_log a LEFT JOIN gp_users u ON u.id = a.admin_id';
        $total = (int) $this->db->query('SELECT COUNT(*) AS n' . $from . $w, $b)->row()->n;
        $rows  = $this->db->query('SELECT a.*, u.full_name, u.username, u.role' . $from . $w
                                . ' ORDER BY a.id DESC LIMIT ' . (int) $p['limit'] . ' OFFSET ' . (int) $p['offset'], $b)->result_array();

        $items = array_map(function ($r) {
            $j = $r['detail'] !== NULL ? json_decode($r['detail'], TRUE) : NULL;
            return [
                'id' => (int) $r['id'], 'at' => $r['occurred_at'], 'who' => (int) $r['admin_id'] === 0 ? 'System' : ($r['full_name'] ?: ($r['username'] ?: '#' . (int) $r['admin_id'])),
                'role' => $r['role'], 'action' => $r['action'], 'target_type' => $r['target_type'],
                'target_id' => $r['target_id'] !== NULL ? (int) $r['target_id'] : NULL,
                'detail' => is_array($j) ? $j : $r['detail'], 'ip' => $r['ip_address'],
            ];
        }, $rows);

        return json_response(build_pagination_meta($items, $total, $p['page'], $p['limit']), 'Audit log');
    }
}
