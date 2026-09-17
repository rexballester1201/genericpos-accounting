<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Imports.php — bringing a chart, contacts, opening balances and entries in from a spreadsheet
 *
 * GenericPOS Accounting · administrators only (PLAN.md §8)
 *
 *   GET  /api/v1/imports                what can be imported, with the columns of each
 *   POST /api/v1/imports/{kind}         { rows: [ {column: value} … ], commit: false|true }
 *
 * The browser reads the CSV and sends its rows; this checks every one of them
 * and answers row by row. Nothing is written until `commit` is true, and then
 * the whole file goes in ONE transaction: an import either lands completely or
 * not at all, so a half-imported chart can never exist.
 *
 * Imports never post to the ledger. Opening balances land in the opening-balance
 * draft, and entries land as DRAFTS for someone to check and submit — the
 * ordinary approval road (Journal_model), not a way around it.
 */
class Imports extends CI_Controller
{
    const MAX_ROWS = 2000;

    const KINDS = [
        'accounts' => [
            'label'   => 'Chart of accounts',
            'about'   => 'Adds accounts. An account that already has the code is left alone.',
            'columns' => [
                ['key' => 'code', 'label' => 'Code', 'required' => TRUE, 'help' => 'Up to 20 letters, digits, dots or dashes'],
                ['key' => 'name', 'label' => 'Name', 'required' => TRUE],
                ['key' => 'type', 'label' => 'Type', 'required' => TRUE, 'help' => 'asset, liability, equity, income or expense'],
                ['key' => 'parent_code', 'label' => 'Parent code', 'help' => 'The header it sits under'],
                ['key' => 'is_header', 'label' => 'Header', 'help' => 'yes for a heading that takes no entries'],
                ['key' => 'subtype', 'label' => 'Subtype', 'help' => 'assets and liabilities: current or non_current · equity: capital, retained, reserve, drawing, other · income: operating, other · expenses: cost_of_sales, operating, finance, other, income_tax'],
                ['key' => 'cash_flow', 'label' => 'Cash flow', 'help' => 'operating, investing, financing or cash'],
                ['key' => 'control', 'label' => 'Control', 'help' => 'ar or ap'],
                ['key' => 'requires_department', 'label' => 'Needs a department', 'help' => 'yes or no'],
                ['key' => 'description', 'label' => 'Description'],
            ],
        ],
        'contacts' => [
            'label'   => 'Customers and suppliers',
            'about'   => 'Adds contacts. One that already has the code is left alone.',
            'columns' => [
                ['key' => 'code', 'label' => 'Code', 'required' => TRUE],
                ['key' => 'name', 'label' => 'Name', 'required' => TRUE],
                ['key' => 'role', 'label' => 'Role', 'required' => TRUE, 'help' => 'customer, supplier or both'],
                ['key' => 'tin', 'label' => 'TIN'],
                ['key' => 'address', 'label' => 'Address'],
                ['key' => 'contact_person', 'label' => 'Contact person'],
                ['key' => 'email', 'label' => 'Email'],
                ['key' => 'phone', 'label' => 'Phone'],
                ['key' => 'terms_days', 'label' => 'Terms (days)'],
                ['key' => 'credit_limit', 'label' => 'Credit limit'],
                ['key' => 'ewt_rate_pct', 'label' => 'Withholding rate (%)', 'help' => 'Suppliers only, e.g. 2'],
                ['key' => 'vat_registered', 'label' => 'VAT-registered', 'help' => 'yes or no'],
                ['key' => 'notes', 'label' => 'Notes'],
            ],
        ],
        'opening' => [
            'label'   => 'Opening balances',
            'about'   => 'Fills the opening-balance draft, replacing what is in it. Nothing posts until you post the draft on the Opening balances screen.',
            'columns' => [
                ['key' => 'code', 'label' => 'Account code', 'required' => TRUE],
                ['key' => 'debit', 'label' => 'Debit'],
                ['key' => 'credit', 'label' => 'Credit'],
                ['key' => 'contact_code', 'label' => 'Customer or supplier', 'help' => 'Required on a receivables or payables account'],
                ['key' => 'department_code', 'label' => 'Department'],
                ['key' => 'memo', 'label' => 'Memo'],
            ],
        ],
        'journals' => [
            'label'   => 'Journal entries',
            'about'   => 'Creates DRAFT entries, one per entry key, for someone to check and submit. Lines of one entry share its key.',
            'columns' => [
                ['key' => 'entry', 'label' => 'Entry key', 'required' => TRUE, 'help' => 'Any label; every line with the same key is one entry'],
                ['key' => 'date', 'label' => 'Date', 'required' => TRUE, 'help' => 'YYYY-MM-DD, in an open month'],
                ['key' => 'book', 'label' => 'Book', 'help' => 'general, cash_receipts, cash_disbursements, sales, purchases, adjusting'],
                ['key' => 'description', 'label' => 'Description', 'required' => TRUE],
                ['key' => 'reference', 'label' => 'Reference'],
                ['key' => 'party', 'label' => 'Paid to / received from'],
                ['key' => 'account_code', 'label' => 'Account code', 'required' => TRUE],
                ['key' => 'debit', 'label' => 'Debit'],
                ['key' => 'credit', 'label' => 'Credit'],
                ['key' => 'memo', 'label' => 'Memo'],
                ['key' => 'department_code', 'label' => 'Department'],
                ['key' => 'contact_code', 'label' => 'Customer or supplier'],
            ],
        ],
    ];

    public function __construct()
    {
        parent::__construct();
        $this->load->database();
        $this->config->load('app', FALSE, TRUE);
    }

    /** GET /api/v1/imports */
    public function index()
    {
        admin_check();
        require_method('GET');

        $kinds = [];
        foreach (self::KINDS as $key => $k) {
            $kinds[] = ['key' => $key, 'label' => $k['label'], 'about' => $k['about'], 'columns' => $k['columns'],
                        'sample' => $this->_sample($key, $k['columns'])];
        }
        return json_response([
            'kinds'    => $kinds,
            'counts'   => [
                'accounts'    => (int) $this->db->count_all_results('gp_accounts'),
                'contacts'    => (int) $this->db->count_all_results('gp_contacts'),
                'departments' => (int) $this->db->count_all_results('gp_departments'),
                'journals'    => (int) $this->db->count_all_results('gp_journals'),
            ],
            'max_rows' => self::MAX_ROWS,
            'today'    => company_today(),
        ], 'Imports');
    }

    /** POST /api/v1/imports/{kind} */
    public function run($kind)
    {
        $claims = admin_check();
        require_method('POST');
        rate_limit((int) $claims['user_id'], 'admin_write');

        if ( ! isset(self::KINDS[$kind])) return json_error('There is nothing of that kind to import.', 404);
        $in     = get_json_body();
        $rows   = array_values((array) ($in['rows'] ?? []));
        $commit = ! empty($in['commit']);
        if ( ! $rows)                        return json_invalid(['rows' => 'The file has no rows.']);
        if (count($rows) > self::MAX_ROWS)   return json_invalid(['rows' => 'Import up to ' . self::MAX_ROWS . ' rows at a time.']);

        $method = '_check_' . $kind;
        $checked = $this->$method($rows, $in);
        $bad = count(array_filter($checked['rows'], function ($r) { return ! $r['ok']; }));

        if ( ! $commit) {
            return json_response($checked + ['checked' => TRUE, 'bad' => $bad], $bad
                ? $bad . ' of ' . count($checked['rows']) . ' rows need fixing before this can be imported.'
                : 'All ' . count($checked['rows']) . ' rows look right. Import them when you are ready.');
        }
        if ($bad) return json_invalid(['rows' => 'Fix the ' . $bad . ' row' . ($bad === 1 ? '' : 's') . ' marked below first. Nothing was imported.']);

        $this->db->trans_begin();
        try {
            $apply = '_apply_' . $kind;
            $done  = $this->$apply($checked, $claims);
            if ( ! log_admin_action($claims, 'import.' . $kind, NULL, NULL, ['rows' => count($checked['rows'])] + $done)) {
                throw new DomainException('The audit trail could not be written, so nothing was imported.');
            }
            $this->db->trans_commit();
            return json_response($checked + ['imported' => TRUE, 'result' => $done], $done['message']);
        } catch (Throwable $t) {
            $this->db->trans_rollback();
            if ($t instanceof DomainException) return json_error($t->getMessage(), 409);
            if ($t instanceof RuntimeException && ! ($t instanceof mysqli_sql_exception)) return json_error($t->getMessage(), 409);
            log_message('error', '[Imports] ' . $kind . ': ' . $t->getMessage());
            return json_error('The database refused the import, and nothing was written.', 500);
        }
    }

    // =========================================================================
    // CHECKING
    // =========================================================================

    private function _check_accounts(array $rows)
    {
        $this->load->model('Account_model', 'accounts');
        $out  = [];
        $seen = [];
        $new  = [];   // codes this file adds, so a parent inside the file is found
        foreach ($rows as $i => $r) {
            $code = trim((string) ($r['code'] ?? ''));
            $e    = [];
            if ($code !== '' && isset($seen[strtoupper($code)])) $e[] = 'The code ' . $code . ' is in this file twice.';
            $exists = $code !== '' ? $this->accounts->find_by_code($code) : NULL;
            $data = NULL;
            if ($exists) {
                $out[] = $this->_row($i, TRUE, [], $code . ' ' . $exists['name'] . ' is already in the chart; it will be left as it is.', ['skip' => TRUE]);
                $seen[strtoupper($code)] = TRUE;
                $new[strtoupper($code)] = TRUE;
                continue;
            }
            $parent = trim((string) ($r['parent_code'] ?? ''));
            $pid    = NULL;
            if ($parent !== '') {
                $p = $this->accounts->find_by_code($parent);
                if ($p) $pid = (int) $p['id'];
                elseif ( ! isset($new[strtoupper($parent)])) $e[] = 'No account has the parent code ' . $parent . '.';
            }
            list($data, $ve) = $this->accounts->validate([
                'code' => $code, 'name' => $r['name'] ?? '', 'type' => strtolower(trim((string) ($r['type'] ?? ''))),
                'parent_id' => $pid, 'is_header' => self::_bool($r['is_header'] ?? FALSE), 'subtype' => $r['subtype'] ?? '',
                'cash_flow' => $r['cash_flow'] ?? '', 'control' => $r['control'] ?? '',
                'requires_department' => self::_bool($r['requires_department'] ?? FALSE), 'description' => $r['description'] ?? '',
                'is_active' => TRUE,
            ], NULL);
            foreach ($ve as $field => $msg) if ($field !== 'parent_id' || $pid !== NULL) $e[] = $msg;
            $seen[strtoupper($code)] = TRUE;
            $new[strtoupper($code)]  = TRUE;
            $out[] = $this->_row($i, ! $e, $e, $code . ' ' . trim((string) ($r['name'] ?? '')), ['data' => $data, 'parent_code' => $parent]);
        }
        return ['kind' => 'accounts', 'rows' => $out];
    }

    private function _check_contacts(array $rows)
    {
        $this->load->model('Contact_model', 'contacts');
        $out  = [];
        $seen = [];
        foreach ($rows as $i => $r) {
            $code = trim((string) ($r['code'] ?? ''));
            $e    = [];
            if ($code !== '' && isset($seen[strtoupper($code)])) $e[] = 'The code ' . $code . ' is in this file twice.';
            $seen[strtoupper($code)] = TRUE;
            $exists = $code !== '' ? $this->db->get_where('gp_contacts', ['code' => $code], 1)->row_array() : NULL;
            if ($exists) {
                $out[] = $this->_row($i, TRUE, [], $code . ' ' . $exists['name'] . ' is already here; it will be left as it is.', ['skip' => TRUE]);
                continue;
            }
            $role = strtolower(trim((string) ($r['role'] ?? '')));
            if ( ! in_array($role, ['customer', 'supplier', 'both'], TRUE)) $e[] = 'The role must be customer, supplier or both.';
            $limit = trim((string) ($r['credit_limit'] ?? ''));
            $ewt   = trim((string) ($r['ewt_rate_pct'] ?? ''));
            list($data, $ve) = $this->contacts->validate([
                'code' => $code, 'name' => $r['name'] ?? '',
                'is_customer' => in_array($role, ['customer', 'both'], TRUE), 'is_supplier' => in_array($role, ['supplier', 'both'], TRUE),
                'tin' => $r['tin'] ?? '', 'address' => $r['address'] ?? '', 'contact_person' => $r['contact_person'] ?? '',
                'email' => $r['email'] ?? '', 'phone' => $r['phone'] ?? '', 'terms_days' => trim((string) ($r['terms_days'] ?? '')),
                'credit_limit' => $limit, 'ewt_rate_bp' => $ewt === '' ? 0 : (int) round(((float) str_replace(',', '', $ewt)) * 100),
                'vat_registered' => self::_bool($r['vat_registered'] ?? TRUE), 'notes' => $r['notes'] ?? '',
            ], NULL);
            foreach ($ve as $msg) $e[] = $msg;
            $out[] = $this->_row($i, ! $e, $e, $code . ' ' . trim((string) ($r['name'] ?? '')), ['data' => $data]);
        }
        return ['kind' => 'contacts', 'rows' => $out];
    }

    private function _check_opening(array $rows, array $in = [])
    {
        list($acct, $dept, $contact) = $this->_maps();
        $out = [];
        $lines = [];
        $dr = 0;
        $cr = 0;
        foreach ($rows as $i => $r) {
            $e = [];
            $code = trim((string) ($r['code'] ?? ''));
            $a = $acct[strtoupper($code)] ?? NULL;
            if ( ! $a)                        $e[] = 'No account has the code ' . ($code === '' ? '(blank)' : $code) . '.';
            elseif ((int) $a['is_header'])    $e[] = $code . ' is a header account.';
            elseif ( ! (int) $a['is_active']) $e[] = $code . ' is inactive.';

            $d = self::_cents($r['debit'] ?? '');
            $c = self::_cents($r['credit'] ?? '');
            if ($d === NULL || $c === NULL)   $e[] = 'The amounts must be numbers like 1250 or 1,250.50.';
            elseif ($d > 0 && $c > 0)         $e[] = 'Put the amount in the debit column or the credit column, not both.';
            elseif ($d === 0 && $c === 0)     $e[] = 'Enter a debit or a credit.';

            $cc  = trim((string) ($r['contact_code'] ?? ''));
            $con = $cc !== '' ? ($contact[strtoupper($cc)] ?? NULL) : NULL;
            if ($cc !== '' && ! $con) $e[] = 'No customer or supplier has the code ' . $cc . '.';
            if ($a && in_array($a['control'], ['ar', 'ap'], TRUE) && ! $con) {
                $e[] = $code . ' is a control account, so each line needs the customer or supplier it belongs to.';
            }
            $dc  = trim((string) ($r['department_code'] ?? ''));
            $dep = $dc !== '' ? ($dept[strtoupper($dc)] ?? NULL) : NULL;
            if ($dc !== '' && ! $dep) $e[] = 'No department has the code ' . $dc . '.';
            if ($a && (int) $a['requires_department'] && ! $dep) $e[] = $code . ' needs a department.';

            if ( ! $e) {
                $lines[] = ['account_id' => (int) $a['id'], 'contact_id' => $con ? (int) $con['id'] : NULL,
                            'department_id' => $dep ? (int) $dep['id'] : NULL, 'debit_cents' => $d, 'credit_cents' => $c,
                            'memo' => trim((string) ($r['memo'] ?? ''))];
                $dr += $d;
                $cr += $c;
            }
            $out[] = $this->_row($i, ! $e, $e, ($a ? $a['code'] . ' ' . $a['name'] : $code) . ' · ' . ($d ? money_format_cents($d) . ' debit' : money_format_cents($c) . ' credit'));
        }
        $totals = ['debit_cents' => $dr, 'credit_cents' => $cr, 'difference_cents' => $dr - $cr];
        return ['kind' => 'opening', 'rows' => $out, 'lines' => $lines, 'totals' => $totals,
                'entry_date' => trim((string) ($in['entry_date'] ?? '')),
                'note' => $dr === $cr ? 'Debits and credits balance.' : 'Debits and credits differ by ' . money_format_cents(abs($dr - $cr)) . '. You can still fill the draft and fix it there.'];
    }

    private function _check_journals(array $rows)
    {
        list($acct, $dept, $contact) = $this->_maps();
        $this->load->model('Period_model', 'periods');
        $this->load->model('Journal_model');
        $books = Journal_model::MANUAL_BOOKS;
        $out    = [];
        $groups = [];
        foreach ($rows as $i => $r) {
            $e   = [];
            $key = trim((string) ($r['entry'] ?? ''));
            if ($key === '') $e[] = 'Give the entry a key, so its lines are kept together.';
            $date = trim((string) ($r['date'] ?? ''));
            if ( ! Period_model::valid_date($date)) $e[] = 'The date must look like 2026-09-30.';
            else {
                list($p, $why) = $this->periods->open_period_for($date);
                if ($why !== '') $e[] = $why;
            }
            $book = strtolower(trim((string) ($r['book'] ?? 'general'))) ?: 'general';
            if ( ! in_array($book, $books, TRUE)) $e[] = 'The book must be one of: ' . implode(', ', $books) . '.';
            $desc = trim((string) ($r['description'] ?? ''));
            if ($desc === '') $e[] = 'Describe the entry.';

            $code = trim((string) ($r['account_code'] ?? ''));
            $a = $acct[strtoupper($code)] ?? NULL;
            if ( ! $a)                        $e[] = 'No account has the code ' . ($code === '' ? '(blank)' : $code) . '.';
            elseif ((int) $a['is_header'])    $e[] = $code . ' is a header account.';
            elseif ( ! (int) $a['is_active']) $e[] = $code . ' is inactive.';

            $d = self::_cents($r['debit'] ?? '');
            $c = self::_cents($r['credit'] ?? '');
            if ($d === NULL || $c === NULL) $e[] = 'The amounts must be numbers like 1250 or 1,250.50.';
            elseif (($d > 0) === ($c > 0))  $e[] = 'Put the amount in the debit column or the credit column.';

            $cc  = trim((string) ($r['contact_code'] ?? ''));
            $con = $cc !== '' ? ($contact[strtoupper($cc)] ?? NULL) : NULL;
            if ($cc !== '' && ! $con) $e[] = 'No customer or supplier has the code ' . $cc . '.';
            if ($a && in_array($a['control'], ['ar', 'ap'], TRUE) && ! $con) $e[] = $code . ' needs its customer or supplier.';
            $dc  = trim((string) ($r['department_code'] ?? ''));
            $dep = $dc !== '' ? ($dept[strtoupper($dc)] ?? NULL) : NULL;
            if ($dc !== '' && ! $dep) $e[] = 'No department has the code ' . $dc . '.';
            if ($a && (int) $a['requires_department'] && ! $dep) $e[] = $code . ' needs a department.';

            if ( ! $e) {
                if ( ! isset($groups[$key])) {
                    $groups[$key] = ['head' => ['book' => $book, 'entry_date' => $date, 'reference' => trim((string) ($r['reference'] ?? '')),
                                                'party_name' => trim((string) ($r['party'] ?? '')), 'description' => $desc], 'lines' => [], 'dr' => 0, 'cr' => 0];
                }
                $groups[$key]['lines'][] = ['account_id' => (int) $a['id'], 'debit_cents' => $d, 'credit_cents' => $c,
                                            'memo' => trim((string) ($r['memo'] ?? '')), 'department_id' => $dep ? (int) $dep['id'] : NULL,
                                            'contact_id' => $con ? (int) $con['id'] : NULL];
                $groups[$key]['dr'] += $d;
                $groups[$key]['cr'] += $c;
            }
            $out[] = $this->_row($i, ! $e, $e, $key . ' · ' . ($a ? $a['code'] : $code) . ' · ' . ($d ? money_format_cents($d) . ' debit' : money_format_cents($c) . ' credit'));
        }

        /* An entry is checked as a whole as well: at least two lines, and balanced. */
        $entries = [];
        foreach ($groups as $key => $g) {
            $why = '';
            if (count($g['lines']) < 2)   $why = 'has only one line';
            elseif ($g['dr'] !== $g['cr']) $why = 'does not balance: ' . money_format_cents($g['dr']) . ' against ' . money_format_cents($g['cr']);
            if ($why !== '') {
                foreach ($out as &$o) if (strpos($o['summary'], $key . ' · ') === 0) { $o['ok'] = FALSE; $o['errors'][] = 'The entry "' . $key . '" ' . $why . '.'; }
                unset($o);
            }
            $entries[] = ['key' => $key, 'date' => $g['head']['entry_date'], 'book' => $g['head']['book'], 'description' => $g['head']['description'],
                          'lines' => count($g['lines']), 'total_cents' => $g['dr'], 'ok' => $why === ''];
        }
        return ['kind' => 'journals', 'rows' => $out, 'groups' => $groups, 'entries' => $entries];
    }

    // =========================================================================
    // WRITING  (inside the one transaction)
    // =========================================================================

    private function _apply_accounts(array $checked, array $claims)
    {
        $this->load->model('Account_model', 'accounts');
        $todo = [];
        foreach ($checked['rows'] as $r) if (empty($r['skip']) && ! empty($r['data'])) $todo[] = $r;
        $made = 0;
        $pass = 0;
        while ($todo && $pass++ < 25) {
            $left = [];
            foreach ($todo as $r) {
                $data = $r['data'];
                if ( ! empty($r['parent_code']) && empty($data['parent_id'])) {
                    $p = $this->accounts->find_by_code($r['parent_code']);
                    if ( ! $p) { $left[] = $r; continue; }
                    $data['parent_id'] = (int) $p['id'];
                }
                $this->accounts->create($data);
                $made++;
            }
            if (count($left) === count($todo)) break;
            $todo = $left;
        }
        if ($todo) throw new DomainException('These accounts name a parent that is not in the chart or in this file: ' . implode(', ', array_column(array_column($todo, 'data'), 'code')) . '.');
        return ['created' => $made, 'skipped' => count($checked['rows']) - $made, 'message' => $made . ' account' . ($made === 1 ? '' : 's') . ' added to the chart.'];
    }

    private function _apply_contacts(array $checked, array $claims)
    {
        $this->load->model('Contact_model', 'contacts');
        $made = 0;
        foreach ($checked['rows'] as $r) {
            if ( ! empty($r['skip']) || empty($r['data'])) continue;
            list($id, $err) = $this->contacts->create($r['data'], $claims);
            if ( ! $id) throw new DomainException($err ?: 'One of the rows could not be saved.');
            $made++;
        }
        return ['created' => $made, 'skipped' => count($checked['rows']) - $made,
                'message' => $made . ' customer' . ($made === 1 ? '' : 's') . ' or supplier' . ($made === 1 ? '' : 's') . ' added.'];
    }

    private function _apply_opening(array $checked, array $claims)
    {
        $this->load->model('Opening_model', 'opening');
        $draft = $this->opening->draft();
        $date  = $checked['entry_date'] !== '' ? $checked['entry_date'] : $draft['entry_date'];
        $e = $this->opening->save_draft(['entry_date' => $date, 'lines' => $checked['lines']], (int) $claims['user_id']);
        if ($e) throw new DomainException(reset($e));
        $n = count($checked['lines']);
        return ['lines' => $n, 'message' => $n . ' line' . ($n === 1 ? '' : 's') . ' put into the opening-balance draft. Check it and post it on the Opening balances screen.'];
    }

    private function _apply_journals(array $checked, array $claims)
    {
        $this->load->model('Journal_model');
        $ids = [];
        foreach ($checked['groups'] as $key => $g) {
            list($id, $errs) = $this->Journal_model->create_draft($g['head'], $g['lines'], (int) $claims['user_id'], 'manual', NULL, NULL, ['imported_as' => $key]);
            if ( ! $id) throw new DomainException('The entry "' . $key . '" could not be saved: ' . reset($errs));
            $ids[] = $id;
        }
        $n = count($ids);
        return ['drafts' => $n, 'ids' => $ids, 'message' => $n . ' draft entr' . ($n === 1 ? 'y is' : 'ies are') . ' ready in Journal entries for checking and submitting.'];
    }

    // =========================================================================
    // INTERNALS
    // =========================================================================

    private function _row($i, $ok, array $errors, $summary, array $extra = [])
    {
        return ['line' => $i + 2, 'ok' => (bool) $ok, 'errors' => array_values($errors), 'summary' => (string) $summary] + $extra;
    }

    /** Accounts, departments and contacts by upper-case code. */
    private function _maps()
    {
        $a = [];
        $d = [];
        $c = [];
        foreach ($this->db->select('id, code, name, is_header, is_active, control, requires_department')->get('gp_accounts')->result_array() as $r) $a[strtoupper($r['code'])] = $r;
        foreach ($this->db->select('id, code, name, is_active')->get('gp_departments')->result_array() as $r) $d[strtoupper($r['code'])] = $r;
        foreach ($this->db->select('id, code, name, is_active')->get('gp_contacts')->result_array() as $r) $c[strtoupper($r['code'])] = $r;
        return [$a, $d, $c];
    }

    /** A couple of example rows, so the shape of the file is obvious. */
    private function _sample($kind, array $columns)
    {
        $head = array_column($columns, 'key');
        $rows = [];
        if ($kind === 'accounts')      $rows = [['1115', 'Petty Cash Fund', 'asset', '1110', '', 'current', 'cash', '', '', 'Revolving fund']];
        elseif ($kind === 'contacts')  $rows = [['C010', 'Bicol Grocers Inc.', 'customer', '123-456-789-00000', 'Naga City', 'Ms. Reyes', '', '', '30', '', '', 'yes', '']];
        elseif ($kind === 'opening')   $rows = [['1111', '25000', '', '', '', 'Cash count at go-live'], ['1121', '48000', '', 'C002', '', 'Open invoice INV-1043']];
        else                           $rows = [['JV1', company_today(), 'general', 'Accrual of utilities', '', '', '6210', '4500', '', 'August bill', '', ''],
                                                ['JV1', company_today(), 'general', 'Accrual of utilities', '', '', '2130', '', '4500', 'August bill', '', '']];
        return ['header' => $head, 'rows' => $rows];
    }

    private static function _bool($v)
    {
        $s = strtolower(trim((string) $v));
        return ! in_array($s, ['', '0', 'no', 'false', 'n', 'off'], TRUE);
    }

    /** '1,250.50' → 125050; '' → 0; anything else → NULL. */
    private static function _cents($v)
    {
        $s = trim((string) $v);
        if ($s === '' || $s === '-') return 0;
        $s = str_replace([',', ' ', "\xC2\xA0"], '', $s);
        if ( ! preg_match('/^\d{1,13}(\.\d{1,2})?$/', $s)) return NULL;
        $c = money_cents($s);
        return $c === NULL ? NULL : (int) $c;
    }
}
