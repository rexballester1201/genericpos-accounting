<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Contact_model.php — customers and suppliers
 *
 * GenericPOS Accounting · receivables and payables module · table gp_contacts
 *
 * One contact may be both a customer and a supplier. Balances are never
 * stored: they are sums over the posted ledger (gp_ledger) on the receivables
 * or payables control accounts, where every line names its contact
 * (Journal_model::validate refuses a control-account line without one).
 *
 *   AR balances are debit-positive  — what the customer owes us
 *   AP balances are credit-positive — what we owe the supplier
 *
 * A contact that has ever been used (a document, a receipt or payment, a
 * journal line, a fixed asset's supplier) is deactivated, never deleted.
 */
class Contact_model extends CI_Model
{
    const T = 'gp_contacts';
    const AUDIT_FAIL = 'The audit trail could not be written, so nothing was saved.';

    public function __construct()
    {
        parent::__construct();
        $this->load->library('Arap_lib', NULL, 'arap');
    }

    // =========================================================================
    // READ
    // =========================================================================

    public function find($id)
    {
        return $this->db->get_where(self::T, ['id' => (int) $id], 1)->row_array() ?: NULL;
    }

    /** The row with its flags as booleans and its numbers as integers. */
    public function shape(array $c)
    {
        foreach (['id', 'ewt_rate_bp'] as $k) if (array_key_exists($k, $c)) $c[$k] = (int) $c[$k];
        foreach (['terms_days', 'credit_limit_cents', 'default_account_id'] as $k) {
            if (array_key_exists($k, $c)) $c[$k] = $c[$k] !== NULL ? (int) $c[$k] : NULL;
        }
        foreach (['is_customer', 'is_supplier', 'vat_registered', 'is_active'] as $k) {
            if (array_key_exists($k, $c)) $c[$k] = (bool) (int) $c[$k];
        }
        return $c;
    }

    /** The next free code in the C001 / S001 series. */
    public function next_code($role)
    {
        $p = $role === 'supplier' ? 'S' : 'C';
        $max = 0;
        $width = 3;
        foreach ($this->db->select('code')->like('code', $p, 'after')->get(self::T)->result_array() as $r) {
            if (preg_match('/^' . $p . '(\d+)$/', $r['code'], $m)) {
                $max = max($max, (int) $m[1]);
                $width = max($width, strlen($m[1]));
            }
        }
        return $p . str_pad((string) ($max + 1), $width, '0', STR_PAD_LEFT);
    }

    /**
     * Balances on the control accounts of one side, per contact.
     *
     * @param string     $side   ar | ap
     * @param string|NULL $as_of lines dated on or before; NULL = every posted line
     * @param int[]|NULL $ids    only these contacts
     * @return array [contact id => cents on the side's own sign]
     */
    public function balances($side, $as_of = NULL, array $ids = NULL)
    {
        $ctl = $this->arap->control_ids($side);
        if ( ! $ctl || ($ids !== NULL && ! $ids)) return [];
        $w = 'account_id IN (' . implode(',', array_map('intval', $ctl)) . ') AND contact_id IS NOT NULL';
        $a = [];
        if ($as_of !== NULL) { $w .= ' AND entry_date <= ?'; $a[] = $as_of; }
        if ($ids !== NULL)   $w .= ' AND contact_id IN (' . implode(',', array_map('intval', $ids)) . ')';
        $sign = $side === 'ar' ? 1 : -1;
        $out = [];
        foreach ($this->db->query('SELECT contact_id, SUM(net_cents) AS n FROM gp_ledger WHERE ' . $w . ' GROUP BY contact_id', $a)->result_array() as $r) {
            $out[(int) $r['contact_id']] = $sign * (int) $r['n'];
        }
        return $out;
    }

    /**
     * Open and overdue amounts of posted invoices (ar) or bills (ap), per contact.
     * @return array [contact id => ['open' => cents, 'overdue' => cents, 'count' => n]]
     */
    public function open_amounts($side, $today, array $ids = NULL)
    {
        if ($ids !== NULL && ! $ids) return [];
        $w = "doc_type = ? AND status = 'posted' AND total_cents > applied_cents";
        $a = [$today, Arap_lib::target_type($side)];
        if ($ids !== NULL) $w .= ' AND contact_id IN (' . implode(',', array_map('intval', $ids)) . ')';
        $out = [];
        foreach ($this->db->query(
            'SELECT contact_id, COUNT(*) AS n, SUM(total_cents - applied_cents) AS open,
                    SUM(CASE WHEN due_date < ? THEN total_cents - applied_cents ELSE 0 END) AS overdue
               FROM gp_documents WHERE ' . $w . ' GROUP BY contact_id', $a
        )->result_array() as $r) {
            $out[(int) $r['contact_id']] = ['open' => (int) $r['open'], 'overdue' => (int) $r['overdue'], 'count' => (int) $r['n']];
        }
        return $out;
    }

    /**
     * A page of contacts with their balance, open and overdue amounts on the
     * side the list is for.
     *
     * @param array $f role (customer|supplier|''), q, active ('1' default, '0', 'all'), sort (name|code|balance), today
     * @return array [rows, total, totals]
     */
    public function search(array $f, $limit, $offset)
    {
        $role  = in_array($f['role'] ?? '', ['customer', 'supplier'], TRUE) ? $f['role'] : '';
        $side  = $role === 'supplier' ? 'ap' : 'ar';
        $today = $f['today'] ?? company_today();

        $w = ['1 = 1'];
        $a = [];
        if ($role === 'customer') $w[] = 'is_customer = 1';
        if ($role === 'supplier') $w[] = 'is_supplier = 1';
        $active = (string) ($f['active'] ?? '1');
        if ($active === '1') $w[] = 'is_active = 1';
        elseif ($active === '0') $w[] = 'is_active = 0';

        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_substr($q, 0, 80)) . '%';
            $digits = preg_replace('/\D/', '', $q);
            $cond = '(code LIKE ? OR name LIKE ? OR tin LIKE ? OR contact_person LIKE ?';
            array_push($a, $like, $like, $like, $like);
            if (strlen($digits) >= 3) { $cond .= " OR REPLACE(tin, '-', '') LIKE ?"; $a[] = '%' . $digits . '%'; }
            $w[] = $cond . ')';
        }
        $where = implode(' AND ', $w);

        $ids = array_map('intval', array_column($this->db->query('SELECT id FROM gp_contacts WHERE ' . $where, $a)->result_array(), 'id'));
        $bal  = $ids ? $this->balances($side, $today, $ids) : [];
        $open = $ids ? $this->open_amounts($side, $today, $ids) : [];

        $totals = ['balance_cents' => array_sum($bal), 'open_cents' => 0, 'overdue_cents' => 0];
        foreach ($open as $o) { $totals['open_cents'] += $o['open']; $totals['overdue_cents'] += $o['overdue']; }

        $sort = (string) ($f['sort'] ?? 'name');
        if ($sort === 'balance') {
            usort($ids, function ($x, $y) use ($bal) { return ($bal[$y] ?? 0) <=> ($bal[$x] ?? 0) ?: $x <=> $y; });
            $page = array_slice($ids, (int) $offset, (int) $limit);
            $rows = [];
            if ($page) {
                $by = [];
                foreach ($this->db->where_in('id', $page)->get(self::T)->result_array() as $r) $by[(int) $r['id']] = $r;
                foreach ($page as $id) if (isset($by[$id])) $rows[] = $by[$id];
            }
        } else {
            $order = $sort === 'code' ? 'code ASC' : 'name ASC, code ASC';
            $rows = $this->db->query('SELECT * FROM gp_contacts WHERE ' . $where . ' ORDER BY ' . $order
                . ' LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset, $a)->result_array();
        }

        $rows = array_map(function ($r) use ($bal, $open, $side) {
            $id = (int) $r['id'];
            return $this->shape($r) + [
                'side'          => $side,
                'balance_cents' => $bal[$id] ?? 0,
                'open_cents'    => $open[$id]['open'] ?? 0,
                'overdue_cents' => $open[$id]['overdue'] ?? 0,
                'open_count'    => $open[$id]['count'] ?? 0,
            ];
        }, $rows);

        return [$rows, count($ids), $totals];
    }

    /** What has used a contact, for deleting (nothing may have) and for changing its roles. */
    public function usage($id)
    {
        $id = (int) $id;
        $n = function ($sql) use ($id) { return (int) $this->db->query($sql, [$id])->row()->n; };
        return [
            'documents'   => $n('SELECT COUNT(*) AS n FROM gp_documents WHERE contact_id = ?'),
            'settlements' => $n('SELECT COUNT(*) AS n FROM gp_settlements WHERE contact_id = ?'),
            'lines'       => $n('SELECT COUNT(*) AS n FROM gp_journal_lines WHERE contact_id = ?'),
            'assets'      => $n('SELECT COUNT(*) AS n FROM gp_assets WHERE supplier_id = ?'),
        ];
    }

    /** Has the contact anything on one side: documents, receipts or payments, or control-account lines? */
    private function _used_on($id, $side)
    {
        $types = $side === 'ar' ? "'invoice', 'credit_note'" : "'bill', 'debit_note'";
        $kind  = $side === 'ar' ? 'receipt' : 'payment';
        if ((int) $this->db->query('SELECT COUNT(*) AS n FROM gp_documents WHERE contact_id = ? AND doc_type IN (' . $types . ')', [(int) $id])->row()->n) return TRUE;
        if ((int) $this->db->query('SELECT COUNT(*) AS n FROM gp_settlements WHERE contact_id = ? AND kind = ?', [(int) $id, $kind])->row()->n) return TRUE;
        $ctl = $this->arap->control_ids($side);
        if ( ! $ctl) return FALSE;
        return (bool) (int) $this->db->query('SELECT COUNT(*) AS n FROM gp_journal_lines WHERE contact_id = ? AND account_id IN ('
            . implode(',', array_map('intval', $ctl)) . ')', [(int) $id])->row()->n;
    }

    // =========================================================================
    // VALIDATION
    // =========================================================================

    /**
     * @param array      $in       code, name, is_customer, is_supplier, tin, address, contact_person, email, phone,
     *                             terms_days, credit_limit_cents, default_account_id, ewt_rate_bp, vat_registered, notes
     * @param array|NULL $existing the stored row, for an update
     * @return array [data, errors]
     */
    public function validate(array $in, $existing = NULL)
    {
        $e = [];
        $pick = function ($k, $d = NULL) use ($in, $existing) {
            return array_key_exists($k, $in) ? $in[$k] : ($existing !== NULL && array_key_exists($k, $existing) ? $existing[$k] : $d);
        };
        $bool = function ($v) {
            if (is_bool($v)) return $v;
            return ! in_array(strtolower(trim((string) $v)), ['', '0', 'false', 'no', 'off'], TRUE);
        };

        $is_c = $bool($pick('is_customer', FALSE));
        $is_s = $bool($pick('is_supplier', FALSE));
        if ( ! $is_c && ! $is_s) $e['is_customer'] = 'Mark it as a customer, a supplier, or both.';
        if ($existing) {
            if ((int) $existing['is_customer'] && ! $is_c && $this->_used_on($existing['id'], 'ar')) {
                $e['is_customer'] = 'This customer already has invoices, receipts or a receivable balance, so it stays a customer.';
            }
            if ((int) $existing['is_supplier'] && ! $is_s && $this->_used_on($existing['id'], 'ap')) {
                $e['is_supplier'] = 'This supplier already has bills, payments or a payable balance, so it stays a supplier.';
            }
        }

        $code = strtoupper(trim((string) $pick('code', '')));
        if ($code === '')                                               $e['code'] = 'Enter a code, for example ' . $this->next_code($is_s && ! $is_c ? 'supplier' : 'customer') . '.';
        elseif ( ! preg_match('/^[A-Z0-9][A-Z0-9\-]{0,19}$/', $code))  $e['code'] = 'Use up to 20 letters, digits or dashes.';
        else {
            $dup = $this->db->select('id, name')->get_where(self::T, ['code' => $code], 1)->row_array();
            if ($dup && ( ! $existing || (int) $dup['id'] !== (int) $existing['id'])) $e['code'] = 'Code ' . $code . ' is already used by ' . $dup['name'] . '.';
        }

        $name = clean_line($pick('name', ''), 200);
        if ($name === '')              $e['name'] = 'Enter the name.';
        elseif (mb_strlen($name) > 160) $e['name'] = 'Keep the name under 160 characters.';

        $tin = trim((string) $pick('tin', ''));
        if ($tin !== '') {
            $digits = preg_replace('/\D/', '', $tin);
            if ( ! preg_match('/^[0-9][0-9\-]*$/', $tin) || strlen($tin) > 20) $e['tin'] = 'Write the TIN with digits and dashes only, for example 123-456-789-00000.';
            elseif (strlen($digits) < 9 || strlen($digits) > 15)           $e['tin'] = 'A TIN has 9 to 15 digits, for example 123-456-789-00000.';
        }

        $address = clean_text($pick('address', ''), 600);
        if (mb_strlen($address) > 500) $e['address'] = 'Keep the address under 500 characters.';
        $person = clean_line($pick('contact_person', ''), 200);
        if (mb_strlen($person) > 120)  $e['contact_person'] = 'Keep the name under 120 characters.';

        $email = strtolower(trim((string) $pick('email', '')));
        if ($email !== '' && (mb_strlen($email) > 190 || ! filter_var($email, FILTER_VALIDATE_EMAIL))) $e['email'] = 'Enter a valid email address, or leave it empty.';

        $phone = clean_line($pick('phone', ''), 60);
        if ($phone !== '' && ( ! preg_match('/^[0-9+()\/ .\-]{3,40}$/', $phone))) $e['phone'] = 'Use digits, spaces, +, dashes and brackets only (at most 40).';

        $terms = $pick('terms_days', NULL);
        if ($terms === NULL || $terms === '') {
            $terms = $this->arap->terms_default($is_c ? 'ar' : 'ap');
        } elseif ( ! preg_match('/^\d{1,3}$/', trim((string) $terms)) || (int) $terms > 365) {
            $e['terms_days'] = 'Enter the terms in days, from 0 (cash) to 365.';
        }

        $limit = $pick('credit_limit_cents', NULL);
        if ($limit === '' || $limit === NULL) {
            $limit = NULL;
        } else {
            $limit = Arap_lib::cents($limit);
            if ($limit === NULL) $e['credit_limit_cents'] = 'Enter the credit limit as an amount, or leave it empty for no limit.';
        }

        $acct_id = (int) $pick('default_account_id', 0);
        if ($acct_id) {
            $a = $this->db->get_where('gp_accounts', ['id' => $acct_id], 1)->row_array();
            $ok = $is_c && $is_s ? ['income', 'expense', 'asset'] : ($is_c ? ['income'] : ['expense', 'asset']);
            if ( ! $a)                               $e['default_account_id'] = 'That account does not exist.';
            elseif ((int) $a['is_header'])           $e['default_account_id'] = $a['code'] . ' is a header; choose an account under it.';
            elseif ( ! (int) $a['is_active'])        $e['default_account_id'] = $a['code'] . ' is inactive.';
            elseif ($a['control'] !== NULL && $a['control'] !== '') $e['default_account_id'] = $a['code'] . ' is a control account; choose a sales, expense or asset account.';
            elseif ( ! in_array($a['type'], $ok, TRUE)) {
                $e['default_account_id'] = $is_c && ! $is_s ? 'A customer\'s default account is an income account.' : 'A supplier\'s default account is an expense or asset account.';
            }
        }

        $ewt = $pick('ewt_rate_bp', 0);
        if ($ewt === '' || $ewt === NULL) $ewt = 0;
        if ( ! preg_match('/^\d{1,4}$/', trim((string) $ewt)) || (int) $ewt > 3200) $e['ewt_rate_bp'] = 'Enter a withholding rate from 0 to 32 %.';
        $ewt = $is_s ? (int) $ewt : 0;

        $notes = clean_text($pick('notes', ''), 600);
        if (mb_strlen($notes) > 500) $e['notes'] = 'Keep the notes under 500 characters.';

        $data = [
            'code'               => $code,
            'name'               => $name,
            'is_customer'        => $is_c ? 1 : 0,
            'is_supplier'        => $is_s ? 1 : 0,
            'tin'                => $tin !== '' ? $tin : NULL,
            'address'            => $address !== '' ? $address : NULL,
            'contact_person'     => $person !== '' ? $person : NULL,
            'email'              => $email !== '' ? $email : NULL,
            'phone'              => $phone !== '' ? $phone : NULL,
            'terms_days'         => is_numeric($terms) ? (int) $terms : NULL,
            'credit_limit_cents' => $limit,
            'default_account_id' => $acct_id ?: NULL,
            'ewt_rate_bp'        => (int) $ewt,
            'vat_registered'     => $bool($pick('vat_registered', TRUE)) ? 1 : 0,
            'notes'              => $notes !== '' ? $notes : NULL,
        ];
        return [$data, $e];
    }

    // =========================================================================
    // WRITE — each change and its audit row in one transaction
    // =========================================================================

    /** @return array [id, error] */
    public function create(array $data, array $claims)
    {
        $id  = 0;
        $err = $this->_tx(function () use ($data, $claims, &$id) {
            $now = date('Y-m-d H:i:s');
            $this->db->insert(self::T, $data + ['is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $id = (int) $this->db->insert_id();
            return log_admin_action($claims, 'contact.create', 'contact', $id, ['code' => $data['code'], 'name' => $data['name']]) ? '' : self::AUDIT_FAIL;
        }, 'create');
        return [$err === '' ? $id : 0, $err];
    }

    /** @return string error */
    public function update($id, array $data, array $claims)
    {
        return $this->_tx(function () use ($id, $data, $claims) {
            $old = $this->db->query('SELECT * FROM gp_contacts WHERE id = ? FOR UPDATE', [(int) $id])->row_array();
            if ( ! $old) return 'That customer or supplier no longer exists.';
            $this->db->where('id', (int) $id)->update(self::T, $data + ['updated_at' => date('Y-m-d H:i:s')]);
            $changed = [];
            foreach ($data as $k => $v) if ((string) $old[$k] !== (string) $v) $changed[] = $k;
            return log_admin_action($claims, 'contact.update', 'contact', (int) $id, ['code' => $data['code'], 'changed' => $changed]) ? '' : self::AUDIT_FAIL;
        }, 'update');
    }

    /** Deactivate or reactivate. An inactive contact takes no new documents, receipts or payments. @return string error */
    public function set_active($id, $on, array $claims)
    {
        return $this->_tx(function () use ($id, $on, $claims) {
            $c = $this->db->query('SELECT * FROM gp_contacts WHERE id = ? FOR UPDATE', [(int) $id])->row_array();
            if ( ! $c) return 'That customer or supplier no longer exists.';
            if ((bool) (int) $c['is_active'] === (bool) $on) return $c['name'] . ' is already ' . ($on ? 'active' : 'inactive') . '.';
            $this->db->where('id', (int) $id)->update(self::T, ['is_active' => $on ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s')]);
            return log_admin_action($claims, $on ? 'contact.activate' : 'contact.deactivate', 'contact', (int) $id, ['code' => $c['code']]) ? '' : self::AUDIT_FAIL;
        }, 'set_active');
    }

    /** Delete a contact nothing has ever used; otherwise say why not. @return string error */
    public function delete($id, array $claims)
    {
        return $this->_tx(function () use ($id, $claims) {
            $c = $this->db->query('SELECT * FROM gp_contacts WHERE id = ? FOR UPDATE', [(int) $id])->row_array();
            if ( ! $c) return 'That customer or supplier no longer exists.';
            $u = $this->usage($id);
            if ($u['documents'] || $u['settlements'] || $u['lines'] || $u['assets']) {
                return $c['name'] . ' has already been used on invoices, bills, receipts, payments or journal entries, so it cannot be deleted. Deactivate it instead.';
            }
            $this->db->where('id', (int) $id)->delete(self::T);
            return log_admin_action($claims, 'contact.delete', 'contact', (int) $id, ['code' => $c['code'], 'name' => $c['name']]) ? '' : self::AUDIT_FAIL;
        }, 'delete');
    }

    /** Run $fn in a transaction: '' commits, an error message or an exception rolls back. */
    private function _tx(callable $fn, $what)
    {
        $this->db->trans_begin();
        try {
            $err = (string) $fn();
        } catch (Throwable $t) {
            log_message('error', '[Contact_model] ' . $what . ': ' . $t->getMessage());
            $err = ($t instanceof mysqli_sql_exception && (int) $t->getCode() === 1062)
                ? 'Another customer or supplier already uses that code.'
                : 'The change could not be saved. Try again.';
        }
        if ($err === '') { $this->db->trans_commit(); return ''; }
        $this->db->trans_rollback();
        return $err;
    }

    // =========================================================================
    // ONE CONTACT
    // =========================================================================

    /**
     * The contact page: details, balances, open documents with days overdue,
     * unapplied credits, drafts waiting and recent activity.
     */
    public function detail($id, $today)
    {
        $c = $this->find($id);
        if ( ! $c) return NULL;
        $c = $this->shape($c);

        $acct = $c['default_account_id'] ? $this->db->select('id, code, name, type')->get_where('gp_accounts', ['id' => $c['default_account_id']], 1)->row_array() : NULL;

        $sides = [];
        foreach (['ar' => 'is_customer', 'ap' => 'is_supplier'] as $side => $flag) {
            if ( ! $c[$flag] && ! $this->_used_on($c['id'], $side)) continue;
            $bal  = $this->balances($side, $today, [$c['id']]);
            $open = $this->open_amounts($side, $today, [$c['id']]);
            $sides[$side] = [
                'balance_cents' => $bal[$c['id']] ?? 0,
                'open_cents'    => $open[$c['id']]['open'] ?? 0,
                'overdue_cents' => $open[$c['id']]['overdue'] ?? 0,
                'open_count'    => $open[$c['id']]['count'] ?? 0,
            ];
        }

        $open_docs = [];
        foreach ($this->db->query(
            "SELECT id, doc_type, doc_no, doc_date, due_date, reference, total_cents, applied_cents
               FROM gp_documents WHERE contact_id = ? AND status = 'posted' AND total_cents > applied_cents
              ORDER BY doc_date, id", [(int) $id]
        )->result_array() as $d) {
            $note = Arap_lib::is_note($d['doc_type']);
            $days = ( ! $note && $d['due_date']) ? Arap_lib::days_between($d['due_date'], $today) : 0;
            $open_docs[] = [
                'id' => (int) $d['id'], 'doc_type' => $d['doc_type'], 'type_label' => Arap_lib::TYPE_LABELS[$d['doc_type']],
                'doc_no' => $d['doc_no'], 'doc_date' => $d['doc_date'], 'due_date' => $d['due_date'], 'reference' => $d['reference'],
                'total_cents' => (int) $d['total_cents'], 'open_cents' => (int) $d['total_cents'] - (int) $d['applied_cents'],
                'days_overdue' => max(0, $days), 'is_note' => $note, 'side' => Arap_lib::ledger_side($d['doc_type']),
            ];
        }

        $unapplied = [];
        foreach ($this->db->query(
            "SELECT s.id, s.kind, s.settle_no, s.settle_date, s.reference, s.amount_cents + s.withholding_cents AS total,
                    COALESCE((SELECT SUM(a.amount_cents) FROM gp_allocations a WHERE a.settlement_id = s.id), 0) AS applied
               FROM gp_settlements s WHERE s.contact_id = ? AND s.status = 'posted'
             HAVING total > applied ORDER BY s.settle_date, s.id", [(int) $id]
        )->result_array() as $s) {
            $unapplied[] = ['id' => (int) $s['id'], 'kind' => $s['kind'], 'settle_no' => $s['settle_no'], 'settle_date' => $s['settle_date'],
                            'reference' => $s['reference'], 'total_cents' => (int) $s['total'], 'unapplied_cents' => (int) $s['total'] - (int) $s['applied'],
                            'side' => Arap_lib::ledger_side($s['kind'])];
        }

        $drafts = [];
        foreach ($this->db->query("SELECT id, doc_type AS type, doc_date AS date, total_cents, 'document' AS what FROM gp_documents WHERE contact_id = ? AND status = 'draft'
                                   UNION ALL
                                   SELECT id, kind AS type, settle_date AS date, amount_cents AS total_cents, 'settlement' AS what FROM gp_settlements WHERE contact_id = ? AND status = 'draft'
                                   ORDER BY date DESC, id DESC LIMIT 20", [(int) $id, (int) $id])->result_array() as $r) {
            $drafts[] = ['id' => (int) $r['id'], 'what' => $r['what'], 'type' => $r['type'],
                         'type_label' => Arap_lib::TYPE_LABELS[$r['type']] ?? Arap_lib::KIND_LABELS[$r['type']] ?? $r['type'],
                         'date' => $r['date'], 'total_cents' => (int) $r['total_cents']];
        }

        $activity = [];
        $ctl = array_merge($this->arap->control_ids('ar'), $this->arap->control_ids('ap'));
        if ($ctl) {
            foreach ($this->db->query(
                'SELECT l.journal_id, l.journal_no, l.entry_date, l.description, l.memo, l.reference, l.source, l.source_id,
                        l.debit_cents, l.credit_cents, a.control
                   FROM gp_ledger l JOIN gp_accounts a ON a.id = l.account_id
                  WHERE l.contact_id = ? AND l.account_id IN (' . implode(',', array_map('intval', $ctl)) . ')
                  ORDER BY l.entry_date DESC, l.journal_id DESC, l.line_no DESC LIMIT 20', [(int) $id]
            )->result_array() as $l) {
                $activity[] = [
                    'journal_id' => (int) $l['journal_id'], 'journal_no' => $l['journal_no'], 'date' => $l['entry_date'],
                    'description' => $l['description'], 'reference' => $l['reference'], 'source' => $l['source'],
                    'source_id' => $l['source_id'] !== NULL ? (int) $l['source_id'] : NULL, 'side' => $l['control'],
                    'debit_cents' => (int) $l['debit_cents'], 'credit_cents' => (int) $l['credit_cents'],
                ];
            }
        }

        $u = $this->usage($c['id']);
        return [
            'contact'         => $c,
            'default_account' => $acct ? ['id' => (int) $acct['id'], 'code' => $acct['code'], 'name' => $acct['name'], 'type' => $acct['type']] : NULL,
            'sides'           => $sides,
            'open_documents'  => $open_docs,
            'unapplied'       => $unapplied,
            'drafts'          => $drafts,
            'activity'        => $activity,
            'usage'           => $u,
            'deletable'       => ! ($u['documents'] || $u['settlements'] || $u['lines'] || $u['assets']),
            'today'           => $today,
        ];
    }
}
