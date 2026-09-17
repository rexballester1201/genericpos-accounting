<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Template_model.php — saved and recurring journal entries
 *
 * GenericPOS Accounting · table gp_journal_templates
 *
 * A saved entry is the shape of an entry the office makes again and again —
 * rent, payroll, the monthly accrual — kept by account CODE (SCHEMA.sql), so a
 * renumbered or deactivated account shows up as a problem instead of silently
 * pointing somewhere else. Lines may leave the amounts at zero for entries
 * whose figures change every time; the draft made from them is completed by hand.
 *
 * RECURRING: a saved entry with a day of the month (1–31; a day the month does
 * not have means its last day) becomes a DRAFT on that day, prepared for the
 * person who set it up — never a posting. `php index.php tools cron` makes
 * them (run_due()), catching up at most MAX_CATCH_UP months at once, and tells
 * the owner in their notifications either way. A recurring saved entry must
 * have its amounts and balance.
 *
 * In the description, {month} becomes the draft's month and year ("October
 * 2026") and {year} its year.
 */
class Template_model extends CI_Model
{
    const T = 'gp_journal_templates';
    const MAX_CATCH_UP = 12;

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Journal_model');
        $this->load->model('Period_model', 'periods');
    }

    public function find($id)
    {
        return $this->db->get_where(self::T, ['id' => (int) $id], 1)->row_array() ?: NULL;
    }

    /** Every saved entry (they are shared by the office), by name, with its lines resolved. */
    public function listing()
    {
        $rows  = $this->db->order_by('name', 'ASC')->get(self::T)->result_array();
        $maps  = $this->_maps();
        $names = [];
        $uids  = array_values(array_unique(array_map('intval', array_column($rows, 'created_by'))));
        if ($uids) foreach ($this->db->select('id, full_name, username')->where_in('id', $uids)->get('gp_users')->result_array() as $u) $names[(int) $u['id']] = $u['full_name'] ?: $u['username'];
        return array_map(function ($r) use ($maps, $names) { return $this->present($r, $maps, $names); }, $rows);
    }

    /** One saved entry as the screens show it. */
    public function present(array $r, array $maps = NULL, array $names = NULL)
    {
        $maps = $maps ?: $this->_maps();
        list($lines, $problems) = $this->_resolve($r, $maps);
        if ($names === NULL) {
            $u = $this->db->select('full_name, username')->get_where('gp_users', ['id' => (int) $r['created_by']], 1)->row_array();
            $names = [(int) $r['created_by'] => $u ? ($u['full_name'] ?: $u['username']) : ''];
        }
        return [
            'id'              => (int) $r['id'],
            'name'            => $r['name'],
            'book'            => $r['book'],
            'book_label'      => Journal_model::BOOK_LABELS[$r['book']] ?? $r['book'],
            'description'     => $r['description'],
            'reference'       => $r['reference'],
            'party_name'      => $r['party_name'],
            'recur_day'       => $r['recur_day'] !== NULL ? (int) $r['recur_day'] : NULL,
            'next_date'       => $r['next_date'],
            'is_active'       => (bool) (int) $r['is_active'],
            'created_by'      => (int) $r['created_by'],
            'created_by_name' => $names[(int) $r['created_by']] ?? '',
            'updated_at'      => $r['updated_at'],
            'lines'           => $lines,
            'total_cents'     => array_sum(array_column($lines, 'debit_cents')),
            'problems'        => $problems,
        ];
    }

    /**
     * Check what a form sent. Lines come in as the journal form has them (ids)
     * and are stored by code.
     *
     * @return array [row for the table, errors by field]
     */
    public function validate(array $in)
    {
        $e = [];
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($in['name'] ?? '')));
        if ($name === '')               $e['name'] = 'Name the saved entry.';
        elseif (mb_strlen($name) > 120) $e['name'] = 'Keep the name under 120 characters.';

        $book = (string) ($in['book'] ?? 'general');
        if ( ! in_array($book, Journal_model::MANUAL_BOOKS, TRUE)) $e['book'] = 'Choose a book.';

        $desc = trim(preg_replace('/\s+/u', ' ', (string) ($in['description'] ?? '')));
        if ($desc === '')               $e['description'] = 'Describe the entry. {month} becomes the month of each draft.';
        elseif (mb_strlen($desc) > 500) $e['description'] = 'Keep the description under 500 characters.';
        $ref   = trim((string) ($in['reference'] ?? ''));
        if (mb_strlen($ref) > 60)       $e['reference'] = 'Keep the reference under 60 characters.';
        $party = trim(preg_replace('/\s+/u', ' ', (string) ($in['party_name'] ?? '')));
        if (mb_strlen($party) > 160)    $e['party_name'] = 'Keep the name under 160 characters.';

        $day = $in['recur_day'] ?? NULL;
        $day = ($day === NULL || $day === '' || $day === 0 || $day === '0') ? NULL : (int) $day;
        if ($day !== NULL && ($day < 1 || $day > 31)) $e['recur_day'] = 'Choose a day from 1 to 31.';
        $next = trim((string) ($in['next_date'] ?? ''));
        if ($next !== '' && ! Period_model::valid_date($next)) $e['next_date'] = 'Enter a valid date.';

        $maps  = $this->_maps();
        $store = [];
        $bad   = [];
        $dr = 0;
        $cr = 0;
        $lines = array_values((array) ($in['lines'] ?? []));
        foreach ($lines as $i => $l) {
            if ( ! is_array($l)) continue;
            $aid = (int) ($l['account_id'] ?? 0);
            $d   = self::_cents($l['debit_cents'] ?? 0);
            $c   = self::_cents($l['credit_cents'] ?? 0);
            if ( ! $aid && ! $d && ! $c) continue;
            $a = $maps['acct'][$aid] ?? NULL;
            if ( ! $a)                        { $bad[] = 'Line ' . ($i + 1) . ': choose an account.'; continue; }
            if ((int) $a['is_header'])        { $bad[] = 'Line ' . ($i + 1) . ': ' . $a['code'] . ' is a header account.'; continue; }
            if ($d === NULL || $c === NULL)   { $bad[] = 'Line ' . ($i + 1) . ': amounts must be whole centavos.'; continue; }
            if ($d > 0 && $c > 0)             { $bad[] = 'Line ' . ($i + 1) . ': enter either a debit or a credit.'; continue; }
            $did = (int) ($l['department_id'] ?? 0);
            $cid = (int) ($l['contact_id'] ?? 0);
            $store[] = [
                'account_code'    => $a['code'],
                'debit_cents'     => $d,
                'credit_cents'    => $c,
                'memo'            => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($l['memo'] ?? ''))), 0, 255),
                'department_code' => $did && isset($maps['dept'][$did]) ? $maps['dept'][$did]['code'] : NULL,
                'contact_code'    => $cid && isset($maps['contact'][$cid]) ? $maps['contact'][$cid]['code'] : NULL,
            ];
            $dr += $d;
            $cr += $c;
        }
        if ($bad)                   $e['lines'] = implode(' ', array_slice($bad, 0, 4));
        elseif (count($store) < 2)  $e['lines'] = 'A saved entry needs at least two lines.';
        elseif ($dr !== $cr)        $e['lines'] = 'Debits (' . money_format_cents($dr) . ') and credits (' . money_format_cents($cr) . ') do not balance. Leave both at zero on lines whose amount changes every time.';
        elseif ($day !== NULL && ($dr === 0 || array_filter($store, function ($l) { return $l['debit_cents'] === 0 && $l['credit_cents'] === 0; }))) {
            $e['lines'] = 'A recurring entry is drafted on its own, so every line needs its amount.';
        }

        if ($day !== NULL && $next === '') $next = self::next_occurrence(company_today(), $day);

        return [[
            'name'        => $name,
            'book'        => $book,
            'description' => $desc,
            'reference'   => $ref !== '' ? $ref : NULL,
            'party_name'  => $party !== '' ? $party : NULL,
            'lines_json'  => json_encode($store, JSON_UNESCAPED_UNICODE),
            'recur_day'   => $day,
            'next_date'   => $day !== NULL ? $next : NULL,
            'is_active'   => array_key_exists('is_active', $in) ? ( ! empty($in['is_active']) ? 1 : 0) : 1,
        ], $e];
    }

    public function create(array $row, $user_id)
    {
        $now = date('Y-m-d H:i:s');
        $this->db->insert(self::T, $row + ['created_by' => (int) $user_id, 'created_at' => $now, 'updated_at' => $now]);
        return (int) $this->db->insert_id();
    }

    public function update($id, array $row)
    {
        $this->db->where('id', (int) $id)->update(self::T, $row + ['updated_at' => date('Y-m-d H:i:s')]);
    }

    public function delete($id)
    {
        $this->db->where('id', (int) $id)->delete(self::T);
    }

    /**
     * A draft journal from a saved entry, dated $date, prepared by $user_id.
     * @return array [journal id | 0, errors by field]
     */
    public function make_draft(array $tpl, $user_id, $date, $actor = NULL)
    {
        list($lines, $problems) = $this->_resolve($tpl, $this->_maps());
        if ($problems) return [0, ['lines' => 'The saved entry "' . $tpl['name'] . '" needs fixing: ' . implode('; ', $problems) . '.']];

        $ts   = strtotime($date);
        $desc = strtr((string) $tpl['description'], ['{month}' => $ts ? date('F Y', $ts) : '', '{year}' => $ts ? date('Y', $ts) : '']);
        return $this->Journal_model->create_draft([
            'book'        => $tpl['book'],
            'entry_date'  => $date,
            'reference'   => $tpl['reference'],
            'party_name'  => $tpl['party_name'],
            'description' => $desc,
        ], array_map(function ($l) {
            return ['account_id' => $l['account_id'], 'debit_cents' => $l['debit_cents'], 'credit_cents' => $l['credit_cents'],
                    'memo' => $l['memo'], 'department_id' => $l['department_id'], 'contact_id' => $l['contact_id']];
        }, $lines), (int) $user_id, 'manual', NULL, $actor, ['template_id' => (int) $tpl['id']]);
    }

    /**
     * The scheduled job: a draft for every recurring saved entry that is due,
     * prepared for its owner, who is told either way.
     *
     * @return array ['made' => n, 'failed' => n, 'lines' => [what happened]]
     */
    public function run_due($today)
    {
        $this->load->model('Notification_model', 'notifications');
        $out = ['made' => 0, 'failed' => 0, 'lines' => []];
        $due = $this->db->where('is_active', 1)->where('recur_day IS NOT NULL', NULL, FALSE)->where('next_date <=', $today)
                        ->order_by('next_date', 'ASC')->get(self::T)->result_array();
        foreach ($due as $t) {
            $n = 0;
            while ($t['next_date'] <= $today && $n < self::MAX_CATCH_UP) {
                list($jid, $errs) = $this->make_draft($t, (int) $t['created_by'], $t['next_date'], GP_SYSTEM_ACTOR);
                if ($jid) {
                    $out['made']++;
                    $out['lines'][] = 'Drafted "' . $t['name'] . '" for ' . $t['next_date'] . ' (#' . $jid . ').';
                    $this->_tell((int) $t['created_by'], 'Recurring entry ready: ' . $t['name'],
                        'A draft dated ' . $t['next_date'] . ' is waiting for you to check and submit.', '/journals/' . $jid);
                } else {
                    $out['failed']++;
                    $why = implode(' ', array_values($errs));
                    $out['lines'][] = 'Could not draft "' . $t['name'] . '" for ' . $t['next_date'] . ': ' . $why;
                    $this->_tell((int) $t['created_by'], 'Recurring entry not drafted: ' . $t['name'],
                        'The draft for ' . $t['next_date'] . ' could not be made. ' . $why, '/saved-entries');
                }
                $t['next_date'] = self::next_occurrence($t['next_date'], (int) $t['recur_day'], TRUE);
                $n++;
            }
            $this->db->where('id', (int) $t['id'])->update(self::T, ['next_date' => $t['next_date'], 'updated_at' => date('Y-m-d H:i:s')]);
        }
        return $out;
    }

    /**
     * The first date on (or, with $after, strictly after) $from whose day of the
     * month is $day — the month's last day when it has no such day.
     */
    public static function next_occurrence($from, $day, $after = FALSE)
    {
        $y = (int) substr($from, 0, 4);
        $m = (int) substr($from, 5, 2);
        for ($i = 0; $i < 3; $i++) {
            $last = (int) date('t', mktime(0, 0, 0, $m, 1, $y));
            $cand = sprintf('%04d-%02d-%02d', $y, $m, min($day, $last));
            if ($cand > $from || ( ! $after && $cand === $from)) return $cand;
            if (++$m > 12) { $m = 1; $y++; }
        }
        return $from;
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    /** Accounts, departments and contacts by id and by code. */
    private function _maps()
    {
        $m = ['acct' => [], 'acct_code' => [], 'dept' => [], 'dept_code' => [], 'contact' => [], 'contact_code' => []];
        foreach ($this->db->select('id, code, name, is_header, is_active, control, requires_department')->get('gp_accounts')->result_array() as $a) { $m['acct'][(int) $a['id']] = $a; $m['acct_code'][$a['code']] = $a; }
        foreach ($this->db->select('id, code, name, is_active')->get('gp_departments')->result_array() as $d) { $m['dept'][(int) $d['id']] = $d; $m['dept_code'][$d['code']] = $d; }
        foreach ($this->db->select('id, code, name, is_active')->get('gp_contacts')->result_array() as $c) { $m['contact'][(int) $c['id']] = $c; $m['contact_code'][$c['code']] = $c; }
        return $m;
    }

    /** Stored lines (by code) → lines with ids, and what no longer resolves. */
    private function _resolve(array $tpl, array $m)
    {
        $lines = [];
        $problems = [];
        foreach ((array) json_decode((string) $tpl['lines_json'], TRUE) as $i => $l) {
            $a = $m['acct_code'][(string) ($l['account_code'] ?? '')] ?? NULL;
            $d = ! empty($l['department_code']) ? ($m['dept_code'][$l['department_code']] ?? NULL) : NULL;
            $c = ! empty($l['contact_code']) ? ($m['contact_code'][$l['contact_code']] ?? NULL) : NULL;
            $p = NULL;
            if ( ! $a)                                               $p = 'account ' . ($l['account_code'] ?? '?') . ' no longer exists';
            elseif ((int) $a['is_header'])                           $p = $a['code'] . ' is now a header account';
            elseif ( ! (int) $a['is_active'])                        $p = $a['code'] . ' is inactive';
            elseif ( ! empty($l['department_code']) && ! $d)         $p = 'department ' . $l['department_code'] . ' no longer exists';
            elseif ( ! empty($l['contact_code']) && ! $c)            $p = 'customer or supplier ' . $l['contact_code'] . ' no longer exists';
            if ($p) $problems[] = 'line ' . ($i + 1) . ': ' . $p;
            $lines[] = [
                'account_id'      => $a ? (int) $a['id'] : 0,
                'code'            => $a ? $a['code'] : (string) ($l['account_code'] ?? ''),
                'name'            => $a ? $a['name'] : '',
                'debit_cents'     => (int) ($l['debit_cents'] ?? 0),
                'credit_cents'    => (int) ($l['credit_cents'] ?? 0),
                'memo'            => (string) ($l['memo'] ?? ''),
                'department_id'   => $d ? (int) $d['id'] : NULL,
                'department_code' => $d ? $d['code'] : NULL,
                'contact_id'      => $c ? (int) $c['id'] : NULL,
                'contact_name'    => $c ? $c['name'] : NULL,
                'problem'         => $p,
            ];
        }
        return [$lines, $problems];
    }

    private function _tell($user_id, $title, $body, $url)
    {
        try {
            $this->notifications->push($user_id, 'recurring', $title, $body, $url);
        } catch (Throwable $t) {
            log_message('error', '[Template_model] notify failed: ' . $t->getMessage());
        }
    }

    private static function _cents($v)
    {
        if (is_int($v)) return $v >= 0 ? $v : NULL;
        if (is_string($v) && preg_match('/^\d{1,15}$/', trim($v))) return (int) trim($v);
        if ($v === NULL || $v === '') return 0;
        return NULL;
    }
}
