<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Account_model.php — the chart of accounts and its rules
 *
 * GenericPOS Accounting · table gp_accounts
 *
 * ─── THE RULES ────────────────────────────────────────────────────────────
 *   · Only ACTIVE, NON-HEADER accounts take journal lines. Headers group
 *     accounts and roll their children up on every report.
 *   · normal_side comes from the type (assets and expenses are debit accounts)
 *     and flips for a contra account. It is never typed in.
 *   · Once an account has POSTED lines, its type, contra flag and header flag
 *     are frozen: changing them would silently change the meaning of history.
 *     Once it has ANY line — a draft counts — its control flag is frozen too,
 *     so a line saved on a plain account can never post onto a control account
 *     without the customer or supplier its subsidiary ledger needs.
 *   · An account is deactivated only when nothing still depends on it: no
 *     balance (balance-sheet accounts), no activity in a fiscal year that is
 *     still open (income and expense — the year-end closing must reach them),
 *     no entry waiting to post, and no default in Settings pointing at it.
 *   · A used account is deactivated, never deleted. A parent can never be one
 *     of the account's own descendants (no cycles).
 */
class Account_model extends CI_Model
{
    const T = 'gp_accounts';

    const TYPES    = ['asset', 'liability', 'equity', 'income', 'expense'];
    const BS_TYPES = ['asset', 'liability', 'equity'];

    const TYPE_LABELS = ['asset' => 'Assets', 'liability' => 'Liabilities', 'equity' => 'Equity', 'income' => 'Income', 'expense' => 'Expenses'];

    const SUBTYPES = [
        'asset'     => ['current', 'non_current'],
        'liability' => ['current', 'non_current'],
        'equity'    => ['capital', 'retained', 'reserve', 'drawing', 'other'],
        'income'    => ['operating', 'other'],
        'expense'   => ['cost_of_sales', 'operating', 'finance', 'other', 'income_tax'],
    ];

    const CASH_FLOWS = ['operating', 'investing', 'financing', 'cash'];

    /** The analysis vocabulary (Analysis_lib). Unknown tags are refused. */
    const TAGS = [
        'cash', 'receivable', 'trade_receivable', 'inventory', 'input_vat', 'cwt', 'ppe', 'accum_depr',
        'payable', 'trade_payable', 'output_vat', 'ewt', 'debt', 'deposits',
        'share_capital', 'statutory', 'sales', 'cogs', 'interest_income', 'interest_expense',
        'depreciation', 'provision', 'disposal_gain', 'disposal_loss',
        'loans', 'loans_current', 'loans_past_due', 'loan_allowance', 'loan_interest',
    ];

    /** Settings that name an account by its code (Settings → Account defaults). */
    const DEFAULTS = [
        'acct_retained_earnings'      => 'closing (retained earnings)',
        'acct_drawings'               => "owner's drawings",
        'acct_ar_control'             => 'receivables control',
        'acct_ap_control'             => 'payables control',
        'acct_output_vat'             => 'output VAT',
        'acct_input_vat'              => 'input VAT',
        'acct_ewt_payable'            => 'expanded withholding tax payable',
        'acct_cwt_receivable'         => 'creditable withholding tax',
        'acct_default_sales'          => 'default sales',
        'acct_default_purchases'      => 'default purchases',
        'acct_bank_charges'           => 'bank charges',
        'acct_interest_income'        => 'interest income',
        'acct_gain_on_disposal'       => 'gain on disposal',
        'acct_loss_on_disposal'       => 'loss on disposal',
        'acct_coop_reserve_fund'      => 'reserve fund',
        'acct_coop_cetf'              => 'education and training fund',
        'acct_coop_cdf'               => 'community development fund',
        'acct_coop_optional_fund'     => 'optional fund',
        'acct_coop_isc_payable'       => 'interest on share capital payable',
        'acct_coop_patronage_payable' => 'patronage refund payable',
    ];

    public function __construct()
    {
        parent::__construct();
        $this->config->load('app', FALSE, TRUE);
    }

    public static function normal_side_for($type, $is_contra)
    {
        return (in_array($type, ['asset', 'expense'], TRUE) xor (bool) $is_contra) ? 'D' : 'C';
    }

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

    /** Every account in chart order (sort order, then code). */
    public function all($active_only = FALSE)
    {
        if ($active_only) $this->db->where('is_active', 1);
        return $this->db->order_by('sort_order', 'ASC')->order_by('code', 'ASC')->get(self::T)->result_array();
    }

    /** Every account in tree order — each header followed by its children — with its depth. */
    public function tree()
    {
        $kids = [];
        foreach ($this->all() as $r) $kids[(int) $r['parent_id']][] = $r;

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
        return $out;
    }

    public function count_all()
    {
        return (int) $this->db->count_all(self::T);
    }

    /** [code => id] for every account. */
    public function id_map()
    {
        $out = [];
        foreach ($this->db->select('id, code')->get(self::T)->result_array() as $r) $out[$r['code']] = (int) $r['id'];
        return $out;
    }

    /** Rows keyed by id, for validating many lines at once. */
    public function by_ids(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ( ! $ids) return [];
        $out = [];
        foreach ($this->db->where_in('id', $ids)->get(self::T)->result_array() as $r) $out[(int) $r['id']] = $r;
        return $out;
    }

    /** $id and every account below it. */
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

    /** Has any journal line — posted or not — ever used this account? */
    public function has_lines($id)
    {
        return (bool) $this->db->select('1', FALSE)->where('account_id', (int) $id)->limit(1)
                                ->get('gp_journal_lines')->row_array();
    }

    public function has_posted_lines($id)
    {
        return (bool) $this->db->query('SELECT 1 FROM gp_ledger WHERE account_id = ? LIMIT 1', [(int) $id])->row_array();
    }

    /** [ids with any line, ids with posted lines], each as a set. */
    public function usage()
    {
        $any    = [];
        $posted = [];
        foreach ($this->db->query('SELECT DISTINCT account_id FROM gp_journal_lines')->result_array() as $r) $any[(int) $r['account_id']] = TRUE;
        foreach ($this->db->query('SELECT DISTINCT account_id FROM gp_ledger')->result_array() as $r) $posted[(int) $r['account_id']] = TRUE;
        return [$any, $posted];
    }

    /**
     * Balances as of $date, debit-positive, per account: balance-sheet accounts
     * over all time, income and expense over the fiscal year containing $date,
     * so they read zero again once that year has been closed.
     *
     * @return array [account_id => net cents]
     */
    public function balances($date)
    {
        $fy   = $this->db->where('start_date <=', $date)->where('end_date >=', $date)->limit(1)->get('gp_fiscal_years')->row_array();
        $from = $fy ? $fy['start_date'] : '1000-01-01';
        $out  = [];
        foreach ($this->db->query(
            "SELECT l.account_id, SUM(l.net_cents) AS net
               FROM gp_ledger l JOIN gp_accounts a ON a.id = l.account_id
              WHERE l.entry_date <= ? AND (a.type IN ('asset', 'liability', 'equity') OR l.entry_date >= ?)
              GROUP BY l.account_id",
            [$date, $from]
        )->result_array() as $r) {
            $out[(int) $r['account_id']] = (int) $r['net'];
        }
        return $out;
    }

    /** The Settings default this code serves as ('receivables control'), or ''. */
    public function default_role($code)
    {
        $code = (string) $code;
        if ($code === '') return '';
        foreach (self::DEFAULTS as $key => $label) {
            if ((string) $this->config->item($key) === $code) return $label;
        }
        return '';
    }

    // =========================================================================
    // SEED FROM A TEMPLATE
    // =========================================================================

    /**
     * Insert a Chart_templates chart into an EMPTY chart. Parents come before
     * children in the template, so each row's parent id is already known.
     *
     * @return int accounts created
     */
    public function seed_template(array $accounts)
    {
        if ($this->count_all() > 0) throw new RuntimeException('The chart of accounts is not empty.');

        $now = date('Y-m-d H:i:s');
        $ids = [];
        $this->db->trans_start();
        foreach ($accounts as $a) {
            $this->db->insert(self::T, [
                'code'        => $a['code'],
                'name'        => $a['name'],
                'parent_id'   => $a['parent_code'] !== NULL ? $ids[$a['parent_code']] : NULL,
                'is_header'   => $a['is_header'] ? 1 : 0,
                'type'        => $a['type'],
                'subtype'     => $a['subtype'],
                'is_contra'   => $a['is_contra'] ? 1 : 0,
                'normal_side' => $a['normal_side'],
                'cash_flow'   => $a['cash_flow'],
                'control'     => $a['control'],
                'tags'        => $a['tags'],
                'sort_order'  => $a['sort_order'],
                'is_active'   => 1,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
            $ids[$a['code']] = (int) $this->db->insert_id();
        }
        $this->db->trans_complete();
        if ( ! $this->db->trans_status()) throw new RuntimeException('Could not seed the chart of accounts.');
        return count($ids);
    }

    // =========================================================================
    // WRITE
    // =========================================================================

    /**
     * Validate a create or an update.
     *
     * @param  array      $in        code, name, parent_id, is_header, type, subtype, is_contra,
     *                               cash_flow, control, requires_department, tags, description, is_active
     * @param  array|NULL $existing  the current row for an update
     * @return array [data, errors]
     */
    public function validate(array $in, $existing = NULL)
    {
        $e = [];
        $pick = function ($k, $d = NULL) use ($in, $existing) {
            return array_key_exists($k, $in) ? $in[$k] : ($existing !== NULL && array_key_exists($k, $existing) ? $existing[$k] : $d);
        };

        $code = trim((string) $pick('code', ''));
        if ($code === '')                                         $e['code'] = 'Enter an account code.';
        elseif ( ! preg_match('/^[A-Za-z0-9][A-Za-z0-9.\-]{0,19}$/', $code)) $e['code'] = 'Use up to 20 letters, digits, dots or dashes.';
        else {
            $dup = $this->find_by_code($code);
            if ($dup && ( ! $existing || (int) $dup['id'] !== (int) $existing['id'])) $e['code'] = 'Another account already uses that code.';
        }

        $name = trim(preg_replace('/\s+/u', ' ', (string) $pick('name', '')));
        if ($name === '')                 $e['name'] = 'Enter the account name.';
        elseif (mb_strlen($name) > 160)   $e['name'] = 'Keep the name under 160 characters.';

        $type = (string) $pick('type', '');
        if ( ! in_array($type, self::TYPES, TRUE)) $e['type'] = 'Choose the account type.';

        $subtype = $pick('subtype');
        $subtype = ($subtype === '' || $subtype === NULL) ? NULL : (string) $subtype;
        if ($subtype !== NULL && isset(self::SUBTYPES[$type]) && ! in_array($subtype, self::SUBTYPES[$type], TRUE)) {
            $e['subtype'] = 'That classification does not apply to this account type.';
        }

        $is_header = (bool) $pick('is_header', FALSE);
        $is_contra = (bool) $pick('is_contra', FALSE);
        $active    = (bool) $pick('is_active', TRUE);

        $cf = $pick('cash_flow');
        $cf = ($cf === '' || $cf === NULL) ? NULL : (string) $cf;
        if ($cf !== NULL && ! in_array($cf, self::CASH_FLOWS, TRUE)) $e['cash_flow'] = 'Choose a cash-flow class.';

        $control = $pick('control');
        $control = ($control === '' || $control === NULL) ? NULL : (string) $control;
        if ($control !== NULL) {
            if ( ! in_array($control, ['ar', 'ap'], TRUE))                 $e['control'] = 'A control account is receivables or payables.';
            elseif ($control === 'ar' && $type !== 'asset')                $e['control'] = 'A receivables control account must be an asset.';
            elseif ($control === 'ap' && $type !== 'liability')            $e['control'] = 'A payables control account must be a liability.';
            elseif ($is_header)                                            $e['control'] = 'A header cannot be a control account.';
        }

        $tags = [];
        $raw  = $pick('tags', '');
        foreach (array_filter(array_map('trim', is_array($raw) ? $raw : explode(',', (string) $raw))) as $t) {
            if ( ! in_array($t, self::TAGS, TRUE)) { $e['tags'] = 'Unknown analysis tag: ' . $t . '.'; break; }
            $tags[] = $t;
        }

        $parent_id = $pick('parent_id');
        $parent_id = ($parent_id === '' || $parent_id === NULL || (int) $parent_id === 0) ? NULL : (int) $parent_id;
        if ($parent_id !== NULL) {
            $p = $this->find($parent_id);
            if ( ! $p)                                  $e['parent_id'] = 'That parent account does not exist.';
            elseif ( ! (int) $p['is_header'])           $e['parent_id'] = 'The parent must be a header account.';
            elseif ($p['type'] !== $type)               $e['parent_id'] = 'The parent is a different type of account.';
            elseif ($existing && in_array($parent_id, $this->subtree_ids($existing['id']), TRUE)) {
                $e['parent_id'] = 'An account cannot sit under itself or its own sub-accounts.';
            }
        }

        if ($existing) {
            $posted = $this->has_posted_lines($existing['id']);
            $used   = $posted || $this->has_lines($existing['id']);
            if ($posted) {
                foreach (['type' => $type, 'is_contra' => (int) $is_contra, 'is_header' => (int) $is_header] as $k => $v) {
                    if ((string) $existing[$k] !== (string) $v) {
                        $e[$k] = 'This account has postings, so its type, contra flag and header flag can no longer change.';
                    }
                }
            }
            if ($used && (string) $existing['control'] !== (string) $control) {
                $e['control'] = 'This account already has entries, so it cannot become or stop being a control account.';
            }
            if ($is_header && ! (int) $existing['is_header'] && $used) {
                $e['is_header'] = 'An account with lines cannot become a header.';
            }
            if ( ! $is_header && (int) $existing['is_header']
                && $this->db->where('parent_id', (int) $existing['id'])->count_all_results(self::T) > 0) {
                $e['is_header'] = 'This header has sub-accounts; move them first.';
            }
            if ($code !== $existing['code'] && ($label = $this->default_role($existing['code'])) !== '') {
                $e['code'] = 'This is the ' . $label . ' account in Settings → Account defaults. Point that setting at another account before changing the code.';
            }
            if ((int) $existing['is_active'] === 1 && ! $active && ($why = $this->_deactivation_blocker($existing)) !== '') {
                $e['is_active'] = $why;
            }
        }

        $desc = trim((string) $pick('description', ''));

        $data = [
            'code'                => $code,
            'name'                => $name,
            'parent_id'           => $parent_id,
            'is_header'           => $is_header ? 1 : 0,
            'type'                => $type,
            'subtype'             => $subtype,
            'is_contra'           => $is_contra ? 1 : 0,
            'normal_side'         => in_array($type, self::TYPES, TRUE) ? self::normal_side_for($type, $is_contra) : 'D',
            'cash_flow'           => $cf,
            'control'             => $control,
            'requires_department' => $pick('requires_department', FALSE) ? 1 : 0,
            'tags'                => implode(',', array_values(array_unique($tags))),
            'description'         => $desc !== '' ? mb_substr($desc, 0, 500) : NULL,
            'is_active'           => $active ? 1 : 0,
        ];
        return [$data, $e];
    }

    /** Why this account cannot be deactivated yet, or '' when it can. */
    private function _deactivation_blocker(array $a)
    {
        $id = (int) $a['id'];

        if ((int) $a['is_header']) {
            $n = $this->db->where('parent_id', $id)->where('is_active', 1)->count_all_results(self::T);
            return $n > 0 ? 'Deactivate the ' . $n . ' active account' . ($n === 1 ? '' : 's') . ' under this header first.' : '';
        }

        if (in_array($a['type'], self::BS_TYPES, TRUE)) {
            $bal = (int) $this->db->query('SELECT COALESCE(SUM(net_cents), 0) AS b FROM gp_ledger WHERE account_id = ?', [$id])->row()->b;
            if ($bal !== 0) {
                return 'This account still has a balance of ' . money_format_cents(abs($bal)) . ($bal > 0 ? ' (debit)' : ' (credit)')
                     . '. Move it to another account before deactivating this one.';
            }
        } else {
            $bal = (int) $this->db->query(
                "SELECT COALESCE(SUM(l.net_cents), 0) AS b FROM gp_ledger l JOIN gp_fiscal_years fy ON fy.id = l.fiscal_year_id
                  WHERE l.account_id = ? AND fy.status = 'open'", [$id]
            )->row()->b;
            if ($bal !== 0) {
                return 'This account has ' . money_format_cents(abs($bal)) . ' of activity in a fiscal year that is still open, '
                     . 'which the year-end closing has to clear. Deactivate it once that year is closed.';
            }
        }

        $waiting = (int) $this->db->query(
            "SELECT COUNT(DISTINCT j.id) AS n FROM gp_journal_lines l JOIN gp_journals j ON j.id = l.journal_id
              WHERE l.account_id = ? AND j.status IN ('draft', 'submitted', 'rejected')", [$id]
        )->row()->n;
        if ($waiting > 0) {
            return 'This account is on ' . $waiting . ' entr' . ($waiting === 1 ? 'y' : 'ies') . ' waiting to post. Post, change or cancel '
                 . ($waiting === 1 ? 'it' : 'them') . ' first.';
        }

        if (($label = $this->default_role($a['code'])) !== '') {
            return 'This is the ' . $label . ' account in Settings → Account defaults. Point that setting at another account first.';
        }
        return '';
    }

    public function create(array $data)
    {
        $now = date('Y-m-d H:i:s');
        if ( ! isset($data['sort_order'])) {
            $max = $this->db->select_max('sort_order', 'm')->get(self::T)->row_array();
            $data['sort_order'] = (int) ($max['m'] ?? 0) + 10;
        }
        $data['created_at'] = $now;
        $data['updated_at'] = $now;
        $this->db->insert(self::T, $data);
        return (int) $this->db->insert_id();
    }

    public function update($id, array $data)
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        $this->db->where('id', (int) $id)->update(self::T, $data);
    }

    /** Delete an account that nothing has ever used; otherwise say why not. */
    public function delete($id)
    {
        $a = $this->find($id);
        if ( ! $a)                                                                   return 'That account no longer exists.';
        if ($this->has_lines($id))                                                   return 'This account has journal lines; deactivate it instead.';
        if ($this->db->where('parent_id', (int) $id)->count_all_results(self::T) > 0) return 'This header has sub-accounts; move or delete them first.';

        $refs = [
            ['gp_contacts',         'default_account_id', 'a customer or supplier'],
            ['gp_bank_accounts',    'account_id',         'a bank account'],
            ['gp_document_lines',   'account_id',         'an invoice or bill'],
            ['gp_budget_lines',     'account_id',         'a budget'],
            ['gp_settlements',      'cash_account_id',    'a receipt or payment'],
            ['gp_asset_categories', 'asset_account_id',   'a fixed-asset category'],
            ['gp_asset_categories', 'accum_account_id',   'a fixed-asset category'],
            ['gp_asset_categories', 'expense_account_id', 'a fixed-asset category'],
        ];
        foreach ($refs as $r) {
            if ($this->db->where($r[1], (int) $id)->count_all_results($r[0]) > 0) return 'This account is used by ' . $r[2] . '; deactivate it instead.';
        }
        if (($label = $this->default_role($a['code'])) !== '') {
            return 'This is the ' . $label . ' account in Settings → Account defaults. Point that setting at another account first.';
        }

        try {
            $this->db->where('id', (int) $id)->delete(self::T);
        } catch (Throwable $t) {
            log_message('error', '[Account_model] delete refused: ' . $t->getMessage());
            return 'This account is still used elsewhere; deactivate it instead.';
        }
        return '';
    }
}
