<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Opening_model.php — the balances the books start from
 *
 * GenericPOS Accounting · gp_app_state ('opening_draft') and the opening book
 *
 * An administrator types (or imports) the trial balance at go-live into a
 * working draft, balances it, and posts it: one entry in the opening book,
 * source 'opening', source_id = the fiscal year it is dated in. Receivables and
 * payables are entered per customer and supplier, so the subsidiary ledgers
 * start equal to their control accounts; an account that requires a
 * department takes one per line.
 *
 * The opening book is system-only: Journal_model refuses it for manual entries,
 * so the draft lives here rather than as a draft journal. A wrong opening entry
 * is undone (reversed, dated the same day) and posted again corrected; one
 * active opening entry per fiscal year.
 */
class Opening_model extends CI_Model
{
    const STATE_KEY = 'opening_draft';
    const MAX_LINES = 2000;

    public function __construct()
    {
        parent::__construct();
        $this->load->model('Period_model', 'periods');
        $this->load->model('Account_model', 'accounts');
        $this->load->model('Journal_model');
    }

    /** Everything the screen needs. */
    public function status()
    {
        $years = $this->periods->years();
        $open  = array_values(array_filter($years, function ($y) { return $y['status'] === 'open'; }));
        $first = $open ? $open[count($open) - 1] : ($years ? $years[count($years) - 1] : NULL);

        $draft = $this->draft();
        $date  = $draft['entry_date'] ?: ($first ? $first['start_date'] : company_today());
        $fy    = $this->periods->year_for_date($date);

        return [
            'draft'        => $draft,
            'default_date' => $first ? $first['start_date'] : NULL,
            'entries'      => $this->entries(),
            'active'       => $fy ? $this->active_entry((int) $fy['id']) : NULL,
            'fiscal_year'  => $fy ? ['id' => (int) $fy['id'], 'name' => $fy['name'], 'status' => $fy['status']] : NULL,
            'accounts'     => array_map(function ($a) {
                return ['id' => (int) $a['id'], 'code' => $a['code'], 'name' => $a['name'], 'type' => $a['type'],
                        'control' => $a['control'], 'needs_department' => (bool) (int) $a['requires_department'],
                        'normal_side' => $a['normal_side'], 'active' => (bool) (int) $a['is_active']];
            }, $this->db->where('is_header', 0)->order_by('sort_order')->order_by('code')->get('gp_accounts')->result_array()),
            'contacts'     => array_map(function ($c) {
                return ['id' => (int) $c['id'], 'code' => $c['code'], 'name' => $c['name'],
                        'customer' => (bool) (int) $c['is_customer'], 'supplier' => (bool) (int) $c['is_supplier']];
            }, $this->db->where('is_active', 1)->order_by('name')->get('gp_contacts')->result_array()),
            'departments'  => array_map(function ($d) {
                return ['id' => (int) $d['id'], 'code' => $d['code'], 'name' => $d['name']];
            }, $this->db->where('is_active', 1)->order_by('code')->get('gp_departments')->result_array()),
            'today'        => company_today(),
        ];
    }

    /** The working draft: entry_date, lines, updated_at. */
    public function draft()
    {
        $row = $this->db->get_where('gp_app_state', ['k' => self::STATE_KEY], 1)->row_array();
        $d   = $row ? json_decode($row['v'], TRUE) : NULL;
        if ( ! is_array($d)) $d = [];
        return [
            'entry_date' => $d['entry_date'] ?? NULL,
            'lines'      => array_values((array) ($d['lines'] ?? [])),
            'updated_at' => $row['updated_at'] ?? NULL,
        ];
    }

    /**
     * Store the draft. Rows are checked for shape only — the full rules run
     * when it is posted, against the chart as it is then.
     *
     * @return array errors by field ([] when saved)
     */
    public function save_draft(array $in, $user_id)
    {
        $date = trim((string) ($in['entry_date'] ?? ''));
        if ($date !== '' && ! Period_model::valid_date($date)) return ['entry_date' => 'Enter a valid date.'];

        $lines = [];
        $bad   = [];
        foreach (array_values((array) ($in['lines'] ?? [])) as $i => $l) {
            if ( ! is_array($l)) continue;
            $aid = (int) ($l['account_id'] ?? 0);
            $dr  = self::_cents($l['debit_cents'] ?? 0);
            $cr  = self::_cents($l['credit_cents'] ?? 0);
            if ( ! $aid && ! $dr && ! $cr) continue;
            if ($dr === NULL || $cr === NULL) { $bad[] = 'Line ' . ($i + 1) . ': amounts must be whole centavos.'; continue; }
            if ($dr > 0 && $cr > 0)          { $bad[] = 'Line ' . ($i + 1) . ': enter either a debit or a credit.'; continue; }
            $lines[] = [
                'account_id'    => $aid,
                'contact_id'    => (int) ($l['contact_id'] ?? 0) ?: NULL,
                'department_id' => (int) ($l['department_id'] ?? 0) ?: NULL,
                'debit_cents'   => $dr,
                'credit_cents'  => $cr,
                'memo'          => mb_substr(trim(preg_replace('/\s+/u', ' ', (string) ($l['memo'] ?? ''))), 0, 255),
            ];
        }
        if ($bad)                              return ['lines' => implode(' ', array_slice($bad, 0, 5))];
        if (count($lines) > self::MAX_LINES)   return ['lines' => 'Keep the opening balances to ' . self::MAX_LINES . ' lines.'];

        $this->db->query(
            'INSERT INTO gp_app_state (k, v, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)',
            [self::STATE_KEY, json_encode(['entry_date' => $date ?: NULL, 'lines' => $lines, 'saved_by' => (int) $user_id], JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')]
        );
        return [];
    }

    /** Opening entries, newest first, with what reversed them. */
    public function entries()
    {
        return array_map(function ($r) {
            return ['id' => (int) $r['id'], 'journal_no' => $r['journal_no'], 'entry_date' => $r['entry_date'],
                    'total_cents' => (int) $r['total_cents'], 'description' => $r['description'],
                    'reversed_by_id' => $r['reversed_by_id'] ? (int) $r['reversed_by_id'] : NULL, 'reversed_by_no' => $r['reversed_by_no'],
                    'is_reversal' => (bool) $r['reversal_of_id']];
        }, $this->db->query(
            "SELECT j.*, r.journal_no AS reversed_by_no FROM gp_journals j LEFT JOIN gp_journals r ON r.id = j.reversed_by_id
              WHERE j.book = 'opening' AND j.status = 'posted' ORDER BY j.id DESC"
        )->result_array());
    }

    /** The opening entry in force for a fiscal year (posted, not reversed), or NULL. */
    public function active_entry($fy_id)
    {
        $r = $this->db->query(
            "SELECT id, journal_no, entry_date, total_cents FROM gp_journals
              WHERE book = 'opening' AND status = 'posted' AND fiscal_year_id = ?
                AND reversed_by_id IS NULL AND reversal_of_id IS NULL
              ORDER BY id DESC LIMIT 1", [(int) $fy_id]
        )->row_array();
        return $r ? ['id' => (int) $r['id'], 'journal_no' => $r['journal_no'], 'entry_date' => $r['entry_date'], 'total_cents' => (int) $r['total_cents']] : NULL;
    }

    /** Post the draft. @return array [journal id | 0, errors by field] */
    public function post($user_id)
    {
        $d = $this->draft();
        if ( ! $d['entry_date']) return [0, ['entry_date' => 'Choose the date the books start from (go-live).']];
        if ( ! $d['lines'])      return [0, ['lines' => 'Enter the opening balances first.']];

        $fy = $this->periods->year_for_date($d['entry_date']);
        if ( ! $fy) return [0, ['entry_date' => 'No fiscal year covers ' . $d['entry_date'] . '. Open it first.']];
        if ($a = $this->active_entry((int) $fy['id'])) {
            return [0, ['_' => 'The opening balances for ' . $fy['name'] . ' are already posted as ' . $a['journal_no'] . '. Undo them first, then post the corrected ones.']];
        }

        $jid = 0;
        $this->db->trans_begin();
        try {
            $this->db->query('SELECT k FROM gp_app_state WHERE k = ? FOR UPDATE', [self::STATE_KEY]);
            if ($this->active_entry((int) $fy['id'])) throw new DomainException('The opening balances were posted a moment ago.');

            list($jid, $errs) = $this->Journal_model->post_system([
                'book'        => 'opening',
                'entry_date'  => $d['entry_date'],
                'reference'   => 'Go-live',
                'description' => 'Opening balances at go-live, ' . $d['entry_date'],
            ], $d['lines'], (int) $user_id, 'opening', (int) $fy['id']);
            if ( ! $jid) {
                $this->db->trans_rollback();
                return [0, $errs];
            }

            $j = $this->Journal_model->find($jid);
            if ( ! log_admin_action(['user_id' => (int) $user_id], 'opening.post', 'journal', $jid,
                    ['journal_no' => $j['journal_no'], 'entry_date' => $d['entry_date'], 'lines' => count($d['lines']), 'total_cents' => (int) $j['total_cents']])) {
                throw new DomainException('The audit trail could not be written, so nothing was saved.');
            }
            $this->db->trans_commit();
            return [$jid, []];
        } catch (Throwable $t) {
            $this->db->trans_rollback();
            if ($t instanceof DomainException) return [0, ['_' => $t->getMessage()]];
            if ($t instanceof RuntimeException && ! ($t instanceof mysqli_sql_exception)) return [0, ['_' => $t->getMessage()]];
            log_message('error', '[Opening_model] post: ' . $t->getMessage());
            return [0, ['_' => 'The database refused the opening balances, and nothing was saved.']];
        }
    }

    /**
     * Reverse a posted opening entry (dated the same day) and put its lines
     * back into the draft, ready to be corrected.
     *
     * @return array [reversal id | 0, error]
     */
    public function undo($journal_id, $user_id, $reason)
    {
        $j = $this->Journal_model->find((int) $journal_id);
        if ( ! $j || $j['book'] !== 'opening' || $j['status'] !== 'posted') return [0, 'That is not a posted opening entry.'];

        $this->db->trans_begin();
        try {
            list($rid, $err) = $this->Journal_model->reverse_system((int) $j['id'], (int) $user_id, $j['entry_date'], $reason, 'opening');
            if ( ! $rid) throw new DomainException($err);
            $this->_copy_to_draft($j);
            if ( ! log_admin_action(['user_id' => (int) $user_id], 'opening.undo', 'journal', (int) $j['id'], ['journal_no' => $j['journal_no'], 'reversal_id' => $rid, 'reason' => $reason])) {
                throw new DomainException('The audit trail could not be written, so nothing was saved.');
            }
            $this->db->trans_commit();
            return [$rid, ''];
        } catch (Throwable $t) {
            $this->db->trans_rollback();
            if ($t instanceof DomainException) return [0, $t->getMessage()];
            if ($t instanceof RuntimeException && ! ($t instanceof mysqli_sql_exception)) return [0, $t->getMessage()];
            log_message('error', '[Opening_model] undo: ' . $t->getMessage());
            return [0, 'The database refused the change, and nothing was saved.'];
        }
    }

    /** Load a posted opening entry's lines into the draft. */
    public function copy_to_draft($journal_id)
    {
        $j = $this->Journal_model->find((int) $journal_id);
        if ( ! $j || $j['book'] !== 'opening') return 'That is not an opening entry.';
        $this->_copy_to_draft($j);
        return '';
    }

    private function _copy_to_draft(array $j)
    {
        $lines = array_map(function ($l) {
            return ['account_id' => (int) $l['account_id'], 'contact_id' => $l['contact_id'] ? (int) $l['contact_id'] : NULL,
                    'department_id' => $l['department_id'] ? (int) $l['department_id'] : NULL,
                    'debit_cents' => (int) $l['debit_cents'], 'credit_cents' => (int) $l['credit_cents'], 'memo' => (string) $l['memo']];
        }, $this->Journal_model->lines((int) $j['id']));
        $this->db->query(
            'INSERT INTO gp_app_state (k, v, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)',
            [self::STATE_KEY, json_encode(['entry_date' => $j['entry_date'], 'lines' => $lines], JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s')]
        );
    }

    private static function _cents($v)
    {
        if (is_int($v)) return $v >= 0 ? $v : NULL;
        if (is_string($v) && preg_match('/^\d{1,15}$/', trim($v))) return (int) trim($v);
        if ($v === NULL || $v === '' || $v === 0) return 0;
        return NULL;
    }
}
