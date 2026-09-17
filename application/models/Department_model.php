<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Department_model.php — departments, branches and cost centres
 *
 * GenericPOS Accounting · table gp_departments (budgets and departments module)
 *
 * ─── THE RULES ────────────────────────────────────────────────────────────
 *   · A code is unique: capital letters, digits, dashes and underscores, up to
 *     20 characters. Journal lines keep the department's id, so recoding one
 *     never leaves a stale copy behind — except saved entries, which name a
 *     department by its code: a code a saved entry uses cannot change.
 *   · Departments nest (parent_id). A department can never sit under itself or
 *     one of its own sub-departments, and a new or moved one goes under an
 *     active parent.
 *   · A used department retires by being deactivated. An inactive department
 *     takes no new lines (Journal_model refuses it), so it cannot be
 *     deactivated while something still has to post to it: an entry waiting
 *     to post, a draft invoice or bill, a fixed asset in use, an active saved
 *     entry — or while it has an active sub-department.
 *   · Deleting is only for a department nothing has ever used: no journal
 *     line, invoice or bill line, fixed asset, budget line or saved entry, and
 *     no sub-departments.
 *
 * Every change is audited inside its own transaction — no change without its record.
 */
class Department_model extends CI_Model
{
    const T       = 'gp_departments';
    const CODE_RE = '/^[A-Z0-9][A-Z0-9_-]{0,19}$/';

    // =========================================================================
    // READ
    // =========================================================================

    public function find($id)
    {
        return $this->db->get_where(self::T, ['id' => (int) $id], 1)->row_array() ?: NULL;
    }

    public function find_by_code($code)
    {
        return $this->db->get_where(self::T, ['code' => (string) $code], 1)->row_array() ?: NULL;
    }

    /** Every department in tree order — each followed by its sub-departments, by code — with its depth. */
    public function tree()
    {
        $rows = $this->db->order_by('code', 'ASC')->get(self::T)->result_array();
        $kids = [];
        foreach ($rows as $r) $kids[(int) $r['parent_id']][] = $r;

        $out  = [];
        $seen = [];
        $walk = function ($pid, $depth) use (&$walk, &$kids, &$out, &$seen) {
            foreach ($kids[$pid] ?? [] as $r) {
                if (isset($seen[(int) $r['id']])) continue;
                $seen[(int) $r['id']] = TRUE;
                $r['depth'] = $depth;
                $out[] = $r;
                $walk((int) $r['id'], $depth + 1);
            }
        };
        $walk(0, 0);
        /* Nothing can form a cycle through this model; a row that did anyway
           still shows, at the top level, rather than vanishing. */
        foreach ($rows as $r) {
            if ( ! isset($seen[(int) $r['id']])) { $r['depth'] = 0; $out[] = $r; }
        }
        return $out;
    }

    /** $id and every department below it. */
    public function subtree_ids($id)
    {
        $children = [];
        foreach ($this->db->select('id, parent_id')->get(self::T)->result_array() as $r) {
            $children[(int) $r['parent_id']][] = (int) $r['id'];
        }
        $out = [(int) $id];
        for ($i = 0; $i < count($out); $i++) {
            foreach ($children[$out[$i]] ?? [] as $c) if ( ! in_array($c, $out, TRUE)) $out[] = $c;
        }
        return $out;
    }

    /**
     * What uses each department.
     *
     * @param  int|NULL $id  one department, or every one
     * @return array [id => [journal_lines, document_lines, assets, budget_lines => int, templates => [name => active]]]
     *               (for one department, just its own array)
     */
    public function usage($id = NULL)
    {
        $out = [];
        $one = $id !== NULL ? ' AND department_id = ' . (int) $id : '';
        $count = function ($sql, $key) use (&$out) {
            foreach ($this->db->query($sql)->result_array() as $r) $out[(int) $r['d']][$key] = (int) $r['n'];
        };
        $count('SELECT department_id AS d, COUNT(*) AS n FROM gp_journal_lines WHERE department_id IS NOT NULL' . $one . ' GROUP BY department_id', 'journal_lines');
        $count('SELECT department_id AS d, COUNT(*) AS n FROM gp_document_lines WHERE department_id IS NOT NULL' . $one . ' GROUP BY department_id', 'document_lines');
        $count('SELECT department_id AS d, COUNT(*) AS n FROM gp_assets WHERE department_id IS NOT NULL' . $one . ' GROUP BY department_id', 'assets');
        $count('SELECT department_id AS d, COUNT(*) AS n FROM gp_budget_lines WHERE department_id > 0' . $one . ' GROUP BY department_id', 'budget_lines');

        $ids = [];
        foreach ($this->db->select('id, code')->get(self::T)->result_array() as $r) $ids[$r['code']] = (int) $r['id'];
        foreach ($this->_template_uses() as $code => $names) {
            if (isset($ids[$code]) && ($id === NULL || $ids[$code] === (int) $id)) $out[$ids[$code]]['templates'] = $names;
        }
        return $id !== NULL ? ($out[(int) $id] ?? []) : $out;
    }

    /** "12 journal lines, 1 fixed asset and 24 budget lines", or '' when nothing uses it. */
    public static function usage_text(array $u)
    {
        $parts = [];
        foreach ([['journal_lines', 'journal line', 'journal lines'], ['document_lines', 'invoice or bill line', 'invoice and bill lines'],
                  ['assets', 'fixed asset', 'fixed assets'], ['budget_lines', 'budget line', 'budget lines']] as $k) {
            $n = (int) ($u[$k[0]] ?? 0);
            if ($n > 0) $parts[] = $n . ' ' . ($n === 1 ? $k[1] : $k[2]);
        }
        $t = count($u['templates'] ?? []);
        if ($t > 0) $parts[] = $t . ' saved entr' . ($t === 1 ? 'y' : 'ies');
        if (count($parts) > 1) {
            $last = array_pop($parts);
            return implode(', ', $parts) . ' and ' . $last;
        }
        return $parts ? $parts[0] : '';
    }

    /**
     * Income, expenses and net per department over [$from, $to], from posted
     * lines, closing entries left out (the income statement's own rule).
     *
     * @return array [department id (0 = lines with no department) => [income_cents, expense_cents, net_cents]]
     */
    public function activity($from, $to)
    {
        $out = [];
        foreach ($this->db->query(
            "SELECT l.department_id, a.type, SUM(l.debit_cents) AS dr, SUM(l.credit_cents) AS cr
               FROM gp_ledger l JOIN gp_accounts a ON a.id = l.account_id
              WHERE a.type IN ('income', 'expense') AND l.book <> 'closing' AND l.entry_date BETWEEN ? AND ?
              GROUP BY l.department_id, a.type", [$from, $to]
        )->result_array() as $r) {
            $k = (int) $r['department_id'];
            if ( ! isset($out[$k])) $out[$k] = self::zero();
            if ($r['type'] === 'income') $out[$k]['income_cents'] += (int) $r['cr'] - (int) $r['dr'];
            else                         $out[$k]['expense_cents'] += (int) $r['dr'] - (int) $r['cr'];
            $out[$k]['net_cents'] = $out[$k]['income_cents'] - $out[$k]['expense_cents'];
        }
        return $out;
    }

    public static function zero()
    {
        return ['income_cents' => 0, 'expense_cents' => 0, 'net_cents' => 0];
    }

    // =========================================================================
    // RULES
    // =========================================================================

    /**
     * Validate a create or an update.
     *
     * @param  array      $in        code, name, parent_id
     * @param  array|NULL $existing  the current row for an update
     * @return array [data (code, name, parent_id), errors by field]
     */
    public function validate(array $in, $existing = NULL)
    {
        $e = [];
        $pick = function ($k, $d = NULL) use ($in, $existing) {
            return array_key_exists($k, $in) ? $in[$k] : ($existing !== NULL && array_key_exists($k, $existing) ? $existing[$k] : $d);
        };

        $code = strtoupper(trim((string) $pick('code', '')));
        if ($code === '') {
            $e['code'] = 'Enter a department code.';
        } elseif ( ! preg_match(self::CODE_RE, $code)) {
            $e['code'] = 'Use up to 20 capital letters, digits, dashes (-) or underscores (_), starting with a letter or a digit.';
        } else {
            $dup = $this->find_by_code($code);
            if ($dup && ( ! $existing || (int) $dup['id'] !== (int) $existing['id'])) {
                $e['code'] = 'Department ' . $dup['name'] . ' already uses code ' . $code . '.';
            } elseif ($existing && $code !== $existing['code']) {
                $names = array_keys($this->_template_uses()[$existing['code']] ?? []);
                if ($names) {
                    $one = count($names) === 1;
                    $e['code'] = 'The saved entr' . ($one ? 'y' : 'ies') . ' "' . implode('", "', $names) . '" name' . ($one ? 's' : '')
                               . ' this department by its code ' . $existing['code'] . '. Change ' . ($one ? 'it' : 'them') . ' first, or keep the code.';
                }
            }
        }

        $name = trim(preg_replace('/\s+/u', ' ', (string) $pick('name', '')));
        if ($name === '')               $e['name'] = 'Enter the department name.';
        elseif (mb_strlen($name) > 120) $e['name'] = 'Keep the name under 120 characters.';

        $parent = $pick('parent_id');
        $parent = ($parent === '' || $parent === NULL || (int) $parent === 0) ? NULL : (int) $parent;
        if ($parent !== NULL) {
            $p = $this->find($parent);
            $moved = ! $existing || (int) $existing['parent_id'] !== $parent;
            if ( ! $p) {
                $e['parent_id'] = 'That parent department does not exist.';
            } elseif ($existing && in_array($parent, $this->subtree_ids((int) $existing['id']), TRUE)) {
                $e['parent_id'] = 'A department cannot sit under itself or one of its own sub-departments.';
            } elseif ($moved && ! (int) $p['is_active']) {
                $e['parent_id'] = $p['name'] . ' is inactive. Choose an active parent department.';
            }
        }

        return [['code' => $code, 'name' => $name, 'parent_id' => $parent], $e];
    }

    /** Why this department cannot be deactivated yet, or '' when it can. */
    public function deactivation_blocker(array $d)
    {
        $id = (int) $d['id'];
        $plural = function ($n, $one, $many) { return $n . ' ' . ($n === 1 ? $one : $many); };

        $kids = (int) $this->db->where('parent_id', $id)->where('is_active', 1)->count_all_results(self::T);
        if ($kids > 0) return 'Deactivate its ' . $plural($kids, 'active sub-department', 'active sub-departments') . ' first.';

        $waiting = (int) $this->db->query(
            "SELECT COUNT(DISTINCT j.id) AS n FROM gp_journal_lines l JOIN gp_journals j ON j.id = l.journal_id
              WHERE l.department_id = ? AND j.status IN ('draft', 'submitted', 'rejected')", [$id]
        )->row()->n;
        if ($waiting > 0) {
            return 'It is on ' . $plural($waiting, 'entry', 'entries') . ' waiting to post, and an inactive department cannot be posted to. '
                 . 'Post, change or cancel ' . ($waiting === 1 ? 'it' : 'them') . ' first.';
        }

        $docs = (int) $this->db->query(
            "SELECT COUNT(DISTINCT d.id) AS n FROM gp_document_lines dl JOIN gp_documents d ON d.id = dl.document_id
              WHERE dl.department_id = ? AND d.status = 'draft'", [$id]
        )->row()->n;
        if ($docs > 0) return 'It is on ' . $plural($docs, 'draft invoice or bill', 'draft invoices and bills') . '. Post, change or delete ' . ($docs === 1 ? 'it' : 'them') . ' first.';

        $assets = (int) $this->db->where('department_id', $id)->where_in('status', ['active', 'fully_depreciated'])->count_all_results('gp_assets');
        if ($assets > 0) {
            return $plural($assets, 'fixed asset still in use belongs', 'fixed assets still in use belong') . ' to it, and '
                 . ($assets === 1 ? 'its' : 'their') . ' depreciation and disposal post to it. Move ' . ($assets === 1 ? 'it' : 'them') . ' to another department first.';
        }

        $names = array_keys(array_filter($this->_template_uses()[$d['code']] ?? []));
        if ($names) {
            $one = count($names) === 1;
            return 'The saved entr' . ($one ? 'y' : 'ies') . ' "' . implode('", "', $names) . '" put' . ($one ? 's' : '') . ' lines on it. '
                 . 'Change or deactivate ' . ($one ? 'it' : 'them') . ' first.';
        }
        return '';
    }

    /** Why this department cannot be made active again, or '' when it can. */
    public function reactivation_blocker(array $d)
    {
        if ( ! $d['parent_id']) return '';
        $p = $this->find((int) $d['parent_id']);
        return ($p && ! (int) $p['is_active']) ? 'Its parent department, ' . $p['code'] . ' · ' . $p['name'] . ', is inactive. Reactivate it first.' : '';
    }

    // =========================================================================
    // WRITE — each in its own transaction, with its audit row
    // =========================================================================

    /** @return array [id|NULL, error ''] */
    public function create(array $data, array $claims)
    {
        return $this->_tx(function () use ($data, $claims) {
            if ($data['parent_id']) {
                $p = $this->db->query('SELECT * FROM gp_departments WHERE id = ? FOR UPDATE', [(int) $data['parent_id']])->row_array();
                if ( ! $p || ! (int) $p['is_active']) throw new DomainException('The parent department is no longer active. Choose another.');
            }
            $now = date('Y-m-d H:i:s');
            $this->db->insert(self::T, $data + ['is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $id = (int) $this->db->insert_id();
            $this->_audit($claims, 'department.create', $id, ['code' => $data['code'], 'name' => $data['name'], 'parent_id' => $data['parent_id']]);
            return $id;
        }, [1062 => 'Another department already uses code ' . $data['code'] . '.']);
    }

    /** @return array [changes (field => [from, to]) | NULL, error ''] */
    public function update($id, array $data, array $claims)
    {
        return $this->_tx(function () use ($id, $data, $claims) {
            $row = $this->db->query('SELECT * FROM gp_departments WHERE id = ? FOR UPDATE', [(int) $id])->row_array();
            if ( ! $row) throw new DomainException('That department no longer exists.');
            if ($data['parent_id'] && in_array((int) $data['parent_id'], $this->subtree_ids((int) $id), TRUE)) {
                throw new DomainException('A department cannot sit under itself or one of its own sub-departments.');
            }

            $changes = [];
            foreach (['code', 'name', 'parent_id'] as $k) {
                $old = $row[$k] === NULL ? NULL : (string) $row[$k];
                $new = $data[$k] === NULL ? NULL : (string) $data[$k];
                if ($old !== $new) $changes[$k] = ['from' => $k === 'parent_id' && $old !== NULL ? (int) $old : $row[$k], 'to' => $data[$k]];
            }
            if ( ! $changes) return [];

            $this->db->where('id', (int) $id)->update(self::T, $data + ['updated_at' => date('Y-m-d H:i:s')]);
            $this->_audit($claims, 'department.update', (int) $id, ['code' => $data['code']] + $changes);
            return $changes;
        }, [1062 => 'Another department already uses code ' . $data['code'] . '.']);
    }

    /** @return string error, '' on success */
    public function set_active($id, $active, array $claims)
    {
        list(, $err) = $this->_tx(function () use ($id, $active, $claims) {
            $row = $this->db->query('SELECT * FROM gp_departments WHERE id = ? FOR UPDATE', [(int) $id])->row_array();
            if ( ! $row) throw new DomainException('That department no longer exists.');
            if ((int) $row['is_active'] === ($active ? 1 : 0)) {
                throw new DomainException($row['code'] . ' is already ' . ($active ? 'active' : 'inactive') . '.');
            }
            $why = $active ? $this->reactivation_blocker($row) : $this->deactivation_blocker($row);
            if ($why !== '') throw new DomainException($why);

            $this->db->where('id', (int) $id)->update(self::T, ['is_active' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')]);
            $this->_audit($claims, $active ? 'department.reactivate' : 'department.deactivate', (int) $id, ['code' => $row['code'], 'name' => $row['name']]);
            return TRUE;
        });
        return $err;
    }

    /** @return string error, '' on success */
    public function delete($id, array $claims)
    {
        list(, $err) = $this->_tx(function () use ($id, $claims) {
            $row = $this->db->query('SELECT * FROM gp_departments WHERE id = ? FOR UPDATE', [(int) $id])->row_array();
            if ( ! $row) throw new DomainException('That department no longer exists.');

            $kids = (int) $this->db->where('parent_id', (int) $id)->count_all_results(self::T);
            if ($kids > 0) {
                throw new DomainException('It has ' . $kids . ' sub-department' . ($kids === 1 ? '' : 's') . '. Move or delete ' . ($kids === 1 ? 'it' : 'them') . ' first.');
            }
            $used = self::usage_text($this->usage((int) $id));
            if ($used !== '') throw new DomainException($row['code'] . ' is used by ' . $used . ', so it cannot be deleted. Deactivate it instead.');

            $this->db->where('id', (int) $id)->delete(self::T);
            $this->_audit($claims, 'department.delete', (int) $id, ['code' => $row['code'], 'name' => $row['name']]);
            return TRUE;
        }, [1451 => 'This department is still used elsewhere, so it cannot be deleted. Deactivate it instead.']);
        return $err;
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    /**
     * Saved entries name departments by CODE in their lines_json.
     * @return array [code => [template name => is active]]
     */
    private function _template_uses()
    {
        $out = [];
        try {
            foreach ($this->db->select('name, lines_json, is_active')->get('gp_journal_templates')->result_array() as $t) {
                $lines = json_decode((string) $t['lines_json'], TRUE);
                if ( ! is_array($lines)) continue;
                foreach ($lines as $l) {
                    $c = is_array($l) ? strtoupper(trim((string) ($l['department_code'] ?? ''))) : '';
                    if ($c !== '') $out[$c][(string) $t['name']] = (bool) (int) $t['is_active'];
                }
            }
        } catch (Throwable $t) {
            log_message('error', '[Department_model] reading saved entries: ' . $t->getMessage());
        }
        return $out;
    }

    /**
     * Run $fn in a transaction. A DomainException is a refusal the user can
     * act on; anything else is logged and answered plainly.
     *
     * @param array $sql_messages  MySQL error number => message (1062 duplicate, 1451 still referenced)
     * @return array [what $fn returned | NULL, error '']
     */
    private function _tx(callable $fn, array $sql_messages = [])
    {
        $this->db->trans_begin();
        try {
            $out = $fn();
            $this->db->trans_commit();
            return [$out, ''];
        } catch (DomainException $e) {
            $this->db->trans_rollback();
            return [NULL, $e->getMessage()];
        } catch (Throwable $t) {
            $this->db->trans_rollback();
            if (isset($sql_messages[(int) $t->getCode()])) return [NULL, $sql_messages[(int) $t->getCode()]];
            log_message('error', '[Department_model] ' . $t->getMessage());
            return [NULL, 'The database refused the change, so nothing was saved. Try again.'];
        }
    }

    private function _audit(array $claims, $action, $id, array $detail)
    {
        if ( ! log_admin_action($claims, $action, 'department', (int) $id, $detail)) {
            throw new DomainException('The audit trail could not be written, so nothing was saved.');
        }
    }
}
