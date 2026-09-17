<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Attachments.php — scanned receipts, invoices and contracts on the records they support
 *
 * GenericPOS Accounting · table gp_attachments
 *
 *   GET    /api/v1/attachments?owner_type=&owner_id=   the files on one record (every role)
 *   POST   /api/v1/attachments                         multipart: file, owner_type, owner_id
 *                                                      (bookkeepers and above)
 *   GET    /api/v1/attachments/{id}/file               the file itself (every role; ?download=1 saves it)
 *   DELETE /api/v1/attachments/{id}                    its uploader on the day, or an accountant
 *
 * Owners: a journal entry, an invoice or bill (document), a receipt or payment
 * (settlement), a fixed asset.
 *
 * NOTE: THE WEB SERVER NEVER SERVES THESE FILES (audit E3). They live in
 * uploads/attachments/ behind a deny-all rule — written here if it is missing —
 * under random names, and reach a browser only through file() below, after
 * the sign-in check. Nothing a user typed is ever part of a path, and the type
 * is sniffed from the bytes, never taken from the file's name.
 */
class Attachments extends CI_Controller
{
    const DIR           = 'uploads/attachments/';
    const MAX_PER_OWNER = 30;
    const DEFAULT_MAX   = 10485760;   // 10 MB; config attachment_max_bytes overrides

    const OWNERS = ['journal' => 'gp_journals', 'document' => 'gp_documents', 'settlement' => 'gp_settlements', 'asset' => 'gp_assets'];

    const TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'image/gif'       => 'gif',
        'text/plain'      => 'txt',
        'text/csv'        => 'csv',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => 'xlsx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.ms-excel' => 'xls',
        'application/msword'       => 'doc',
    ];

    /** Shown in the browser; everything else is saved. */
    const INLINE = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
    }

    /** GET|POST /api/v1/attachments */
    public function index()
    {
        require_method(['GET', 'POST']);
        if (strtolower($this->input->method()) === 'post') return $this->_upload();

        $claims = viewer_check();
        list($type, $id) = $this->_owner($this->input->get('owner_type'), $this->input->get('owner_id'));
        return json_response(['items' => $this->_list($type, $id, $claims)], 'Attachments');
    }

    /** GET /api/v1/attachments/{id}/file */
    public function file($aid)
    {
        viewer_check();
        require_method('GET');

        $a = $this->db->get_where('gp_attachments', ['id' => (int) $aid], 1)->row_array();
        if ( ! $a) return json_error('That file does not exist.', 404);
        if ( ! preg_match('/^[0-9a-f]{32}\.[a-z0-9]{2,5}$/', (string) $a['file'])) {
            log_message('error', '[Attachments] unexpected stored name: ' . $a['file']);
            return json_error('That file cannot be opened.', 500);
        }
        $path = $this->_path() . $a['file'];
        if ( ! is_file($path)) return json_error('The file is missing from the server. Tell your administrator.', 410);

        $inline = in_array($a['mime'], self::INLINE, TRUE) && ! $this->input->get('download');
        $name   = str_replace(['"', '\\', "\r", "\n"], '', (string) $a['original_name']);
        $ascii  = preg_replace('/[^\x20-\x7E]/', '_', $name);

        while (ob_get_level() > 0) @ob_end_clean();
        header('Content-Type: ' . $a['mime']);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; sandbox");
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    /** DELETE /api/v1/attachments/{id} */
    public function remove($aid)
    {
        $claims = bookkeeper_check();
        require_method('DELETE');
        rate_limit((int) $claims['user_id'], 'admin_write');

        $a = $this->db->get_where('gp_attachments', ['id' => (int) $aid], 1)->row_array();
        if ( ! $a) return json_error('That file does not exist.', 404);
        if ( ! $this->_may_remove($a, $claims)) {
            return json_error('Only the person who attached it, on the same day, or an accountant can remove it.', 403);
        }

        $this->db->where('id', (int) $a['id'])->delete('gp_attachments');
        if (preg_match('/^[0-9a-f]{32}\.[a-z0-9]{2,5}$/', (string) $a['file'])) @unlink($this->_path() . $a['file']);
        log_admin_action($claims, 'attachment.remove', $a['owner_type'], (int) $a['owner_id'], ['attachment_id' => (int) $a['id'], 'name' => $a['original_name']]);

        return json_response(['items' => $this->_list($a['owner_type'], (int) $a['owner_id'], $claims)], $a['original_name'] . ' is removed.');
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    private function _upload()
    {
        $claims = bookkeeper_check();
        rate_limit((int) $claims['user_id'], 'admin_write');
        list($type, $id) = $this->_owner($this->input->post('owner_type'), $this->input->post('owner_id'));

        if ((int) $this->db->where(['owner_type' => $type, 'owner_id' => $id])->count_all_results('gp_attachments') >= self::MAX_PER_OWNER) {
            return json_error('This record already has ' . self::MAX_PER_OWNER . ' files. Remove one first.', 409);
        }

        $f = $_FILES['file'] ?? NULL;
        if ( ! is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return json_invalid(['file' => 'Choose a file to attach.']);
        if (in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], TRUE))     return json_invalid(['file' => 'That file is too large.']);
        if ($f['error'] !== UPLOAD_ERR_OK || ! is_uploaded_file($f['tmp_name']))          return json_invalid(['file' => 'The file did not arrive. Try again.']);

        $max = (int) ($this->config->item('attachment_max_bytes') ?: self::DEFAULT_MAX);
        if ((int) $f['size'] <= 0)   return json_invalid(['file' => 'That file is empty.']);
        if ((int) $f['size'] > $max) return json_invalid(['file' => 'Attach files of up to ' . round($max / 1048576) . ' MB.']);

        $mime = $this->_sniff($f['tmp_name'], (string) $f['name']);
        if ( ! isset(self::TYPES[$mime])) {
            return json_invalid(['file' => 'Attach a PDF, a picture (JPG, PNG, WebP or GIF), or a spreadsheet, document or text file.']);
        }

        $dir = $this->_dir();
        if ($dir === NULL) return json_error('Files cannot be stored on this server right now. Tell your administrator.', 503);

        $stored = bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime];
        if ( ! @move_uploaded_file($f['tmp_name'], $dir . $stored)) {
            log_message('error', '[Attachments] could not move an upload into ' . $dir);
            return json_error('The file could not be stored. Try again.', 503);
        }

        $original = $this->_clean_name((string) $f['name'], self::TYPES[$mime]);
        try {
            $this->db->insert('gp_attachments', [
                'owner_type' => $type, 'owner_id' => $id, 'file' => $stored, 'original_name' => $original, 'mime' => $mime,
                'bytes' => (int) $f['size'], 'uploaded_by' => (int) $claims['user_id'], 'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $t) {
            @unlink($dir . $stored);
            log_message('error', '[Attachments] insert failed: ' . $t->getMessage());
            return json_error('The file could not be recorded. Try again.', 500);
        }
        $aid = (int) $this->db->insert_id();
        log_admin_action($claims, 'attachment.add', $type, $id, ['attachment_id' => $aid, 'name' => $original, 'bytes' => (int) $f['size']]);

        return json_response(['items' => $this->_list($type, $id, $claims), 'id' => $aid], $original . ' is attached.', 201);
    }

    /** [type, id] of an existing owner, or the request ends with the reason. */
    private function _owner($type, $id)
    {
        $type = (string) $type;
        $id   = (int) $id;
        if ( ! isset(self::OWNERS[$type]) || $id <= 0) json_invalid(['owner' => 'Say which record the files belong to.']);
        if ( ! $this->db->where('id', $id)->count_all_results(self::OWNERS[$type])) json_error('That record does not exist.', 404);
        return [$type, $id];
    }

    private function _list($type, $id, array $claims)
    {
        $rows = $this->db->query(
            'SELECT a.*, u.full_name, u.username FROM gp_attachments a LEFT JOIN gp_users u ON u.id = a.uploaded_by
              WHERE a.owner_type = ? AND a.owner_id = ? ORDER BY a.id', [$type, (int) $id]
        )->result_array();
        return array_map(function ($a) use ($claims) {
            return [
                'id'          => (int) $a['id'],
                'name'        => $a['original_name'],
                'mime'        => $a['mime'],
                'bytes'       => (int) $a['bytes'],
                'inline'      => in_array($a['mime'], self::INLINE, TRUE),
                'uploaded_by' => $a['full_name'] ?: ($a['username'] ?: '#' . (int) $a['uploaded_by']),
                'created_at'  => $a['created_at'],
                'can_remove'  => $this->_may_remove($a, $claims),
            ];
        }, $rows);
    }

    private function _may_remove(array $a, array $claims)
    {
        if (role_rank($claims['role']) >= 3) return TRUE;
        return role_rank($claims['role']) >= 2 && (int) $a['uploaded_by'] === (int) $claims['user_id']
            && strtotime($a['created_at'] . ' UTC') > time() - 86400;
    }

    /** The type from the bytes. finfo when the server has it; the signatures otherwise. */
    private function _sniff($tmp, $name)
    {
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $head = (string) @file_get_contents($tmp, FALSE, NULL, 0, 16);
        $mime = '';
        if (function_exists('finfo_open')) {
            $fi = finfo_open(FILEINFO_MIME_TYPE);
            if ($fi) { $mime = (string) finfo_file($fi, $tmp); finfo_close($fi); }
        }
        if ($mime === '' || $mime === 'application/octet-stream') {
            if (strncmp($head, '%PDF-', 5) === 0)                          $mime = 'application/pdf';
            elseif (strncmp($head, "\xFF\xD8\xFF", 3) === 0)              $mime = 'image/jpeg';
            elseif (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0)         $mime = 'image/png';
            elseif (strncmp($head, 'GIF8', 4) === 0)                       $mime = 'image/gif';
            elseif (strncmp($head, 'RIFF', 4) === 0 && substr($head, 8, 4) === 'WEBP') $mime = 'image/webp';
        }
        /* Office files are zip archives (or the old OLE container); finfo often
           says only that. The name decides between them once the bytes agree. */
        if (in_array($mime, ['application/zip', 'application/octet-stream', 'application/x-zip-compressed'], TRUE) && strncmp($head, "PK\x03\x04", 4) === 0) {
            if ($ext === 'xlsx') $mime = self::_key('xlsx');
            if ($ext === 'docx') $mime = self::_key('docx');
        }
        if (in_array($mime, ['application/CDFV2', 'application/x-ole-storage', 'application/vnd.ms-office'], TRUE)) {
            if ($ext === 'xls') $mime = 'application/vnd.ms-excel';
            if ($ext === 'doc') $mime = 'application/msword';
        }
        if ($mime === 'text/plain' && $ext === 'csv') $mime = 'text/csv';
        if ($mime === 'application/csv') $mime = 'text/csv';
        return $mime;
    }

    private static function _key($ext)
    {
        return (string) array_search($ext, self::TYPES, TRUE);
    }

    /** The name the person knows it by, trimmed and with the extension its bytes deserve. */
    private function _clean_name($name, $ext)
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $base = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\:*?"<>|]+/u', ' ', (string) $base));
        $base = mb_substr(preg_replace('/\s+/u', ' ', $base), 0, 150);
        return ($base !== '' ? $base : 'attachment') . '.' . $ext;
    }

    private function _path()
    {
        return rtrim(FCPATH, '/\\') . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::DIR);
    }

    /** The folder, created with its deny-all rule when missing; NULL when it cannot be written. */
    private function _dir()
    {
        $dir = $this->_path();
        if ( ! is_dir($dir) && ! @mkdir($dir, 0750, TRUE)) return NULL;
        if ( ! is_file($dir . '.htaccess')) {
            @file_put_contents($dir . '.htaccess',
                "# Attachments are served only through the API (application/controllers/Attachments.php).\n"
                . "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n");
        }
        if ( ! is_file($dir . 'index.html')) @file_put_contents($dir . 'index.html', '');
        return is_writable($dir) ? $dir : NULL;
    }
}
