<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Demo_seed.php — a demo company with most of a year of books
 *
 * GenericPOS Accounting · `php index.php tools seed_demo` (development only)
 *
 * Builds "Demo Trading Corporation", a VAT-registered trading company in Naga
 * City, into an EMPTY ledger:
 *   · four demo users, one per role (admin, accountant, bookkeeper, viewer)
 *   · company settings, the business (corporation) chart and account defaults
 *   · three departments, six customers, five suppliers
 *   · the fiscal year, with opening balances on 1 January (go-live) and the
 *     open invoices and bills behind them
 *   · 1 January to yesterday: invoices, cash sales and deposits, supplier bills,
 *     collections with CWT, payments with EWT, payroll and remittances, rent,
 *     utilities, VAT, income tax, loan amortisation, depreciation, month-end
 *     cost of sales, a credit note, a reversed mistake, a bad-debt provision
 *     and a new set of laptops
 *   · the fixed-asset register and its depreciation runs, last month's BDO
 *     statement (unmatched, ready to reconcile) and the year's budget
 *   · the first quarter locked, later months closed up to last month, and
 *     entries waiting in the current month: submitted, rejected, draft, cancelled
 *
 * NOTE: EVERYTHING POSTS THROUGH Journal_model, exactly as a real entry would:
 * balanced, into open periods, numbered in date order. Entries a person would
 * key in go the long way — the bookkeeper prepares and submits, the accountant
 * approves — so the seed exercises maker-checker too. Documents, settlements
 * and depreciation runs post as their modules will (post_system).
 *
 * NOTE: ALL OR NOTHING. The whole seed runs in one transaction (the models'
 * transactions nest inside it), so a failure leaves the database empty.
 *
 * Deterministic: mt_srand() is fixed, so every run on an empty database gives
 * the same books (dates follow the calendar year it runs in).
 */
class Demo_seed
{
    /** Local demo accounts only. seed_demo refuses to run outside development. */
    const USERS = [
        'admin'      => ['admin@example.test',      'DemoAdmin#2026', 'Jose Reyes'],
        'accountant' => ['accountant@example.test', 'DemoAcct#2026',  'Maria Santos'],
        'bookkeeper' => ['bookkeeper@example.test', 'DemoBook#2026',  'Ana Cruz'],
        'viewer'     => ['viewer@example.test',     'DemoView#2026',  'Pedro Lim'],
    ];

    /** Customers that withhold 1% creditable tax on what they pay us. */
    const CWT_CUSTOMERS = ['C001', 'C002'];

    /** Days after which an invoice is never collected (the doubtful one). */
    const NEVER = 100000;

    private $CI;
    private $acct    = [];   // code => id
    private $contact = [];   // code => row
    private $dept    = [];   // code => id
    private $uid     = [];   // role => user id
    private $cat_id  = [];   // asset category key => id

    private $year;
    private $end;

    private $open_inv  = [];   // customer invoices still owed
    private $open_bill = [];   // supplier bills still unpaid
    private $vat_out   = [];   // quarter => centavos
    private $vat_in    = [];
    private $wh        = [];   // 'Y-m' => withholdings to remit
    private $tax_due   = [];   // quarter => income tax accrued
    private $month_net_sales = 0;
    private $pending_deposit = 0;
    private $loan_balance    = 84000000;   // 240,000 current + 600,000 non-current
    private $assets    = [];
    private $feb_error = 0;
    private $or_no     = 2000;
    private $chk_no    = 500100;
    private $counts    = ['manual' => 0, 'system' => 0];
    private $bank_note = 'no bank statement yet (no complete month)';

    public function run()
    {
        $this->CI =& get_instance();
        $CI = $this->CI;
        $CI->load->model('Account_model', 'accounts');
        $CI->load->model('Period_model', 'periods');
        $CI->load->model('Journal_model', 'journals');
        $CI->load->model('Ledger_model', 'ledger');
        $CI->load->model('Settings_model', 'settings_model');
        $CI->load->model('User_model', 'users');
        require_once APPPATH . 'libraries/Chart_templates.php';

        if ($CI->accounts->count_all() > 0 || $CI->db->count_all('gp_journals') > 0) {
            throw new RuntimeException('The ledger is not empty. seed_demo only runs on a fresh database: load SCHEMA.sql into an empty one first.');
        }

        mt_srand(20260101);

        /* The current year once it has a few months in it; otherwise last year. */
        $this->year = (int) date('n') >= 4 ? (int) date('Y') : (int) date('Y') - 1;
        $this->end  = $this->year === (int) date('Y') ? date('Y-m-d', strtotime('-1 day')) : $this->year . '-12-31';

        $started = microtime(TRUE);
        $CI->db->trans_begin();
        try {
            $this->_users();
            $this->_company();
            $this->_masters();
            $this->_year_and_opening();
            $this->_asset_register();
            $this->_transactions();
            $this->_banks_and_statement();
            $this->_budget();
            $this->_periods();
            $this->_workflow();
        } catch (Throwable $t) {
            $CI->db->trans_rollback();
            throw $t;
        }
        $CI->db->trans_commit();
        $CI->settings_model->refresh_store_cache();

        return $this->_summary(microtime(TRUE) - $started);
    }

    // =========================================================================
    // SET-UP
    // =========================================================================

    private function _users()
    {
        foreach (self::USERS as $role => $u) {
            if ($err = $this->CI->users->password_policy_error($u[1])) throw new RuntimeException($u[0] . ': ' . $err);
            $row = $this->CI->users->find_by_email($u[0]);
            if ($row) {
                $id = (int) $row['id'];
                $this->CI->users->set_role($id, $role);
                $this->CI->users->admin_set_password($id, $u[1]);
            } else {
                $id = $this->CI->users->create(['email' => $u[0], 'full_name' => $u[2], 'password' => $u[1], 'role' => $role, 'created_via' => 'seed']);
            }
            $this->CI->db->where('id', $id)->update('gp_users', [
                'full_name' => $u[2], 'is_email_verified' => 1, 'account_state' => 'active', 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $this->uid[$role] = $id;
        }
    }

    private function _company()
    {
        $tpl = Chart_templates::get('business_corporation');
        $this->CI->accounts->seed_template($tpl['accounts']);
        $this->acct = $this->CI->accounts->id_map();

        $vals = [
            'store_name'          => 'Demo Trading Corporation',
            'store_legal_name'    => 'Demo Trading Corporation',
            'store_tagline'       => 'Wholesale and retail of consumer goods',
            'store_tin'           => '000-123-456-00000',
            'store_address'       => "2/F Magsaysay Business Center, Magsaysay Avenue\nNaga City, Camarines Sur 4400",
            'store_email'         => 'accounts@demotrading.example',
            'store_phone'         => '+63 54 473 0000',
            'report_note'         => 'Unaudited — for management use',
            'sign_checked_by'     => 'Maria Santos',
            'sign_checked_title'  => 'Accountant',
            'sign_approved_by'    => 'Jose Reyes',
            'sign_approved_title' => 'General Manager',
        ] + $tpl['settings'] + $tpl['defaults'];

        foreach ($vals as $k => $v) {
            if ($v === '') continue;
            $r = $this->CI->settings_model->set($k, $v, $this->uid['admin'], TRUE);
            if ( ! $r['ok']) throw new RuntimeException('Setting ' . $k . ': ' . $r['error']);
        }
    }

    private function _masters()
    {
        $now = date('Y-m-d H:i:s');
        foreach ([['ADM', 'Administration'], ['SLS', 'Sales'], ['WHS', 'Warehouse and Delivery']] as $d) {
            $this->CI->db->insert('gp_departments', ['code' => $d[0], 'name' => $d[1], 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
            $this->dept[$d[0]] = (int) $this->CI->db->insert_id();
        }

        // code, name, customer, supplier, TIN, terms, EWT basis points, default account, address
        $rows = [
            ['C001', 'Naga Supermart Inc.',              1, 0, '001-234-567-00000', 30, 0,    '4110', 'Panganiban Drive, Naga City'],
            ['C002', 'Camsur Food Distributors Corp.',   1, 0, '002-345-678-00000', 30, 0,    '4110', 'Maharlika Highway, Pili, Camarines Sur'],
            ['C003', 'Legazpi Mini Mart',                1, 0, '003-456-789-00000', 30, 0,    '4110', 'Rizal Street, Legazpi City'],
            ['C004', 'Iriga General Merchandise',        1, 0, '004-567-890-00000', 30, 0,    '4110', 'San Francisco, Iriga City'],
            ['C005', 'Pili Wholesale Center',            1, 0, '005-678-901-00000', 15, 0,    '4110', 'Old San Roque, Pili, Camarines Sur'],
            ['C006', 'Daet Trading Center',              1, 0, '006-789-012-00000', 30, 0,    '4110', 'Vinzons Avenue, Daet, Camarines Norte'],
            ['S001', 'Luzon Consumer Goods Corp.',       0, 1, '101-234-567-00000', 30, 100,  '1131', 'Ortigas Center, Pasig City'],
            ['S002', 'Metro Beverage Distributors Inc.', 0, 1, '102-345-678-00000', 30, 100,  '1131', 'Balintawak, Quezon City'],
            ['S003', 'Naga Office Depot',                0, 1, '103-456-789-00000', 30, 100,  '1143', 'Elias Angeles Street, Naga City'],
            ['S004', 'Bicol Truck and Auto Care',        0, 1, '104-567-890-00000', 15, 200,  '6180', 'Diversion Road, Naga City'],
            ['S005', 'Reyes & Associates, CPAs',         0, 1, '105-678-901-00000', 15, 1000, '6230', 'Barlin Street, Naga City'],
        ];
        foreach ($rows as $r) {
            $row = [
                'code' => $r[0], 'name' => $r[1], 'is_customer' => $r[2], 'is_supplier' => $r[3], 'tin' => $r[4],
                'terms_days' => $r[5], 'ewt_rate_bp' => $r[6], 'default_account_id' => $this->acct[$r[7]],
                'address' => $r[8], 'vat_registered' => 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ];
            $this->CI->db->insert('gp_contacts', $row);
            $row['id'] = (int) $this->CI->db->insert_id();
            $this->contact[$r[0]] = $row;
        }
    }

    private function _year_and_opening()
    {
        list($fy, $err) = $this->CI->periods->create_year($this->year . '-01-01');
        if ( ! $fy) throw new RuntimeException($err);

        $prev = $this->year - 1;
        $ob_inv  = [['C001', '11-28', 72000], ['C001', '12-15', 48000], ['C002', '12-10', 85000], ['C003', '12-18', 65000], ['C004', '10-20', 50000]];
        $ob_bill = [['S001', '12-12', 150000], ['S002', '12-19', 90000], ['S003', '12-22', 40000]];

        $L = [
            ['1111', self::p(50000), 0, 'Cash on hand'],
            ['1112', self::p(10000), 0, 'Petty cash fund'],
            ['1113', self::p(850000), 0, 'BDO current account'],
            ['1114', self::p(300000), 0, 'BPI savings account'],
        ];
        $ar = [];
        foreach ($ob_inv as $o) $ar[$o[0]] = ($ar[$o[0]] ?? 0) + self::p($o[2]);
        foreach ($ar as $c => $amt) $L[] = ['1121', $amt, 0, 'Open invoices', NULL, $c];
        $L = array_merge($L, [
            ['1122', 0, self::p(16000)],
            ['1131', self::p(600000), 0],
            ['1141', self::p(60000), 0, 'Extra storage space, January to June'],
            ['1145', self::p(12000), 0],
            ['1214', self::p(450000), 0], ['1215', 0, self::p(150000)],
            ['1216', self::p(180000), 0], ['1217', 0, self::p(54000)],
            ['1218', self::p(1200000), 0], ['1219', 0, self::p(400000)],
            ['1251', self::p(120000), 0, 'Office lease deposit'],
        ]);
        $ap = [];
        foreach ($ob_bill as $o) $ap[$o[0]] = ($ap[$o[0]] ?? 0) + self::p($o[2]);
        foreach ($ap as $s => $amt) $L[] = ['2111', 0, $amt, 'Unpaid bills', NULL, $s];
        $L = array_merge($L, [
            ['2112', 0, self::p(45000), 'December utilities and audit fee'],
            ['2122', 0, self::p(38000), 'VAT for the fourth quarter'],
            ['2123', 0, self::p(8000)], ['2124', 0, self::p(12000)],
            ['2125', 0, self::p(9500)], ['2126', 0, self::p(4500)], ['2127', 0, self::p(2000)],
            ['2130', 0, self::p(240000)], ['2210', 0, self::p(600000)],
            ['3110', 0, self::p(1500000)],
        ]);
        $dr = 0; $cr = 0;
        foreach ($L as $l) { $dr += $l[1]; $cr += $l[2]; }
        $L[] = ['3210', 0, $dr - $cr, 'Balance brought forward from the previous system'];

        $jid = $this->_sys('opening', $this->year . '-01-01', 'Opening balances at go-live', $L, 'Go-live', NULL, 'opening');

        foreach ($ob_inv as $i => $o) {
            $amt  = self::p($o[2]);
            $date = $prev . '-' . $o[1];
            $doc  = $this->_doc('invoice', 'OB-' . sprintf('%04d', $i + 1), $o[0], $date, $amt, self::net($amt), $amt - self::net($amt), '4110',
                                'Open invoice brought forward from the previous system', 'SLS', $jid);
            $this->open_inv[] = ['id' => $doc, 'contact' => $o[0], 'date' => $date, 'open' => $amt, 'net' => self::net($amt),
                                 'after' => $o[0] === 'C004' ? self::NEVER : 20];
        }
        foreach ($ob_bill as $i => $o) {
            $amt  = self::p($o[2]);
            $date = $prev . '-' . $o[1];
            $doc  = $this->_doc('bill', 'OB-' . sprintf('%04d', $i + 1), $o[0], $date, $amt, self::net($amt), $amt - self::net($amt), '1131',
                                'Unpaid bill brought forward from the previous system', NULL, $jid);
            $this->open_bill[] = ['id' => $doc, 'contact' => $o[0], 'date' => $date, 'open' => $amt, 'net' => self::net($amt), 'after' => 20];
        }

        /* December's withholdings, remitted in January. */
        $this->wh[$prev . '-12'] = ['sss' => self::p(9500), 'ph' => self::p(4500), 'hdmf' => self::p(2000), 'wtc' => self::p(12000), 'ewt' => self::p(8000)];
    }

    /** The register behind the opening balances of the equipment accounts. */
    private function _asset_register()
    {
        $now = date('Y-m-d H:i:s');
        $cats = [
            'OE' => ['Office Equipment',         '1214', '1215', 60],
            'FF' => ['Furniture and Fixtures',   '1216', '1217', 60],
            'TE' => ['Transportation Equipment', '1218', '1219', 60],
            'CE' => ['Computer Equipment',       '1221', '1222', 36],
        ];
        foreach ($cats as $k => $c) {
            $this->CI->db->insert('gp_asset_categories', [
                'name' => $c[0], 'asset_account_id' => $this->acct[$c[1]], 'accum_account_id' => $this->acct[$c[2]],
                'expense_account_id' => $this->acct['6200'], 'method' => 'straight_line', 'useful_life_months' => $c[3],
                'residual_bp' => 0, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->cat_id[$k] = (int) $this->CI->db->insert_id();
        }

        /* Bought two years back; the accumulated depreciation matches the opening balances. */
        $y2 = $this->year - 2;
        $this->_asset('OE', 'Office equipment set (copier, printers, safe)', $y2 . '-04-20', $y2 . '-05-01', 450000, 60, 150000, 'ADM', 'Main office', NULL);
        $this->_asset('FF', 'Office furniture and fixtures',                $y2 . '-06-15', $y2 . '-07-01', 180000, 60, 54000,  'ADM', 'Main office', NULL);
        $this->_asset('TE', 'Isuzu delivery truck',                         $y2 . '-04-10', $y2 . '-05-01', 1200000, 60, 400000, 'WHS', 'Warehouse', NULL);
    }

    private function _asset($cat, $name, $acquired, $start, $cost_pesos, $life, $opening_accum_pesos, $dept, $location, $journal_id)
    {
        $now    = date('Y-m-d H:i:s');
        $cost   = self::p($cost_pesos);
        $prefix = strtoupper((string) $this->CI->config->item('fa_asset_prefix')) ?: 'FA';
        $no     = $prefix . '-' . str_pad((string) next_sequence('doc:asset'), 4, '0', STR_PAD_LEFT);
        $this->CI->db->insert('gp_assets', [
            'asset_no' => $no, 'name' => $name, 'category_id' => $this->cat_id[$cat], 'acquired_on' => $acquired,
            'depreciation_start' => $start, 'cost_cents' => $cost, 'residual_cents' => 0, 'useful_life_months' => $life,
            'method' => 'straight_line', 'opening_accum_cents' => self::p($opening_accum_pesos), 'department_id' => $this->dept[$dept],
            'location' => $location, 'status' => 'active', 'acquisition_journal_id' => $journal_id,
            'created_by' => $this->uid['accountant'], 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->assets[$no] = [
            'id' => (int) $this->CI->db->insert_id(), 'cat' => $cat, 'start' => substr($start, 0, 7), 'dept' => $dept,
            'monthly' => (int) round($cost / $life), 'accum' => self::p($opening_accum_pesos),
        ];
    }

    // =========================================================================
    // THE YEAR, DAY BY DAY
    // =========================================================================

    /**
     * Walks the calendar so every book numbers its entries in date order.
     * Within a day the order below is fixed.
     */
    private function _transactions()
    {
        $end = strtotime($this->end);
        for ($t = strtotime($this->year . '-01-01'); $t <= $end; $t = strtotime('+1 day', $t)) {
            $date = date('Y-m-d', $t);
            $day  = (int) date('j', $t);
            $last = (int) date('t', $t);
            $mon  = (int) date('n', $t);
            if ($day === 1) $this->month_net_sales = 0;

            if ($this->pending_deposit > 0)                         $this->_deposit($date);
            if ($day === 5)                                         $this->_rent($date);
            if ($day === 10)                                        $this->_remit($date);
            if ($mon === 1 && $day === 12)                          $this->_insurance($date);
            if ($mon === 1 && $day === 20)                          $this->_january_payables($date);
            if ($day === 15 || $day === $last)                      $this->_payroll($date);
            if (in_array($day, [3, 7, 11, 16, 21, 26], TRUE))       $this->_invoice($date);
            if (in_array($day, [2, 9, 17, 24], TRUE))               $this->_bill_goods($date);
            if (in_array($day, [5, 12, 19, 27], TRUE))              $this->_collect($date);
            if (in_array($day, [10, 25], TRUE))                     $this->_pay_suppliers($date);
            if (in_array($day, [7, 14, 21, 28], TRUE))              $this->_cash_sales($date);
            if ($day === 11 || $day === 25)                         $this->_fuel($date);
            if ($day === 22)                                        $this->_utilities($date);
            if ($day === 23)                                        $this->_communication($date);
            if (in_array($mon, [1, 4, 7, 10], TRUE) && $day === 15)  $this->_supplies($date);
            if (in_array($mon, [3, 7, 11], TRUE) && $day === 5)     $this->_advertising($date);
            if (in_array($mon, [2, 5, 8, 11], TRUE) && $day === 10)  $this->_professional_fees($date);
            if (in_array($mon, [4, 7, 10], TRUE) && $day === 25)    $this->_pay_vat($date, intdiv($mon - 1, 3));
            if (in_array($mon, [5, 8, 11], TRUE) && $day === 14)    $this->_pay_income_tax($date, intdiv($mon - 1, 3));
            if ($mon === 2 && $day === 18)                          $this->_february_mistake($date);
            if ($mon === 2 && $day === 20)                          $this->_february_fix($date);
            if ($mon === 3 && $day === 16)                          $this->_buy_laptops($date);
            if ($mon === 3 && $day === 17)                          $this->_credit_note($date);
            if ($mon === 5 && $day === 20)                          $this->_truck_repair($date);
            if ($day === $last)                                     $this->_month_end($date, $mon);
        }
    }

    private function _month_end($date, $mon)
    {
        $this->_cost_of_sales($date);
        $this->_employer_contributions($date);
        $this->_amortise($date, $mon);
        $this->_depreciation($date);
        $this->_manual('cash_disbursements', $date, 'Bank service charges', [
            ['6270', self::p(350), 0, 'Service charges', 'ADM'], ['1113', 0, self::p(350)],
        ], 'DM ' . date('m-Y', strtotime($date)), 'BDO Unibank');
        $this->_manual('cash_receipts', $date, 'Interest on savings, net of final tax', [
            ['1114', self::p(100), 0], ['6220', self::p(25), 0, '20% final tax', 'ADM'], ['4910', 0, self::p(125), 'Savings interest'],
        ], 'CM ' . date('m-Y', strtotime($date)), 'BPI');
        if ($mon === 6) {
            $this->_manual('adjusting', $date, 'Additional allowance for doubtful accounts', [
                ['6260', self::p(10000), 0, 'Iriga General Merchandise is long past due', 'ADM'], ['1122', 0, self::p(10000)],
            ]);
        }
        if (in_array($mon, [3, 6, 9, 12], TRUE)) {
            $this->_loan($date);
            $this->_income_tax_accrual($date, intdiv($mon - 1, 3) + 1);
        }
    }

    // ── Sales ────────────────────────────────────────────────────────────────

    private function _invoice($date)
    {
        $pick = ['C001', 'C001', 'C002', 'C002', 'C003', 'C004', 'C005', 'C006'];
        $code = $pick[mt_rand(0, count($pick) - 1)];
        $this->_sales_invoice($date, $code, mt_rand(6000, 20000) * 1000);   // 60,000 – 200,000
    }

    private function _sales_invoice($date, $code, $gross)
    {
        $net = self::net($gross);
        $vat = $gross - $net;
        $no  = $this->_docno('invoice');
        $c   = $this->contact[$code];

        $doc = $this->_doc('invoice', $no, $code, $date, $gross, $net, $vat, '4110', 'Merchandise', 'SLS', NULL);
        $jid = $this->_sys('sales', $date, 'Invoice ' . $no . ' — ' . $c['name'], [
            ['1121', $gross, 0, $no, NULL, $code],
            ['4110', 0, $net, 'Merchandise sales', 'SLS'],
            ['2121', 0, $vat, 'Output VAT'],
        ], $no, $c['name'], 'invoice', $doc);
        $this->CI->db->where('id', $doc)->update('gp_documents', ['journal_id' => $jid]);

        $this->vat_out[self::quarter($date)] = ($this->vat_out[self::quarter($date)] ?? 0) + $vat;
        $this->month_net_sales += $net;

        /* Most customers pay in 28–45 days; Iriga is always slow; now and then
           anyone is. */
        if ($code === 'C004')           $after = 60 + mt_rand(0, 30);
        elseif (mt_rand(1, 100) <= 8)   $after = 75 + mt_rand(0, 35);
        else                            $after = 28 + mt_rand(0, 17);
        $this->open_inv[] = ['id' => $doc, 'contact' => $code, 'date' => $date, 'open' => $gross, 'net' => $net, 'after' => $after];
    }

    private function _cash_sales($date)
    {
        $gross = mt_rand(5500, 9500) * 1000;            // 55,000 – 95,000
        $net   = self::net($gross);
        $this->_manual('cash_receipts', $date, 'Cash sales, week ending ' . date('M j', strtotime($date)), [
            ['1111', $gross, 0, 'Cash sales'], ['4110', 0, $net, 'Cash sales', 'SLS'], ['2121', 0, $gross - $net, 'Output VAT'],
        ], 'CS-' . date('md', strtotime($date)), 'Walk-in customers');
        $this->vat_out[self::quarter($date)] = ($this->vat_out[self::quarter($date)] ?? 0) + ($gross - $net);
        $this->month_net_sales += $net;
        $this->pending_deposit += $gross;
    }

    private function _deposit($date)
    {
        $amt = $this->pending_deposit;
        $this->pending_deposit = 0;
        $this->_manual('general', $date, 'Deposit of cash sales to BDO', [['1113', $amt, 0, 'Deposit'], ['1111', 0, $amt]], 'DS-' . date('md', strtotime($date)));
    }

    private function _collect($date)
    {
        $by = [];
        foreach ($this->open_inv as $i => $o) {
            if ($o['open'] <= 0) continue;
            if ((strtotime($date) - strtotime($o['date'])) / 86400 < $o['after']) continue;
            $by[$o['contact']][] = $i;
        }
        foreach ($by as $code => $idx) $this->_receipt($date, $code, $idx);
    }

    private function _receipt($date, $code, array $idx)
    {
        $c = $this->contact[$code];
        $total = 0; $cwt = 0; $nos = [];
        foreach ($idx as $i) {
            $total += $this->open_inv[$i]['open'];
            if (in_array($code, self::CWT_CUSTOMERS, TRUE)) $cwt += (int) round($this->open_inv[$i]['net'] * 0.01);
            $nos[] = $this->_doc_no($this->open_inv[$i]['id']);
        }
        $cash = $total - $cwt;
        $or   = 'OR ' . (++$this->or_no);
        $sid  = $this->_settlement('receipt', $this->_docno('receipt'), $code, $date, '1113', $or, $cash, $cwt, 'Payment of ' . implode(', ', $nos));
        foreach ($idx as $i) {
            $this->_allocate($this->open_inv[$i]['id'], $sid, NULL, $this->open_inv[$i]['open']);
            $this->open_inv[$i]['open'] = 0;
        }
        $jid = $this->_sys('cash_receipts', $date, 'Collection from ' . $c['name'], [
            ['1113', $cash, 0, $or],
            ['1145', $cwt, 0, 'Creditable withholding tax'],
            ['1121', 0, $total, implode(', ', $nos), NULL, $code],
        ], $or, $c['name'], 'receipt', $sid);
        $this->CI->db->where('id', $sid)->update('gp_settlements', ['journal_id' => $jid]);
    }

    /** Legazpi Mini Mart returns damaged goods against an open invoice. */
    private function _credit_note($date)
    {
        $gross = self::p(11200);
        $pick  = NULL;
        foreach ($this->open_inv as $i => $o) {
            if ($o['open'] >= $gross && $o['after'] < self::NEVER && ($pick === NULL || $o['contact'] === 'C003')) $pick = $i;
        }
        if ($pick === NULL) return;

        $o      = $this->open_inv[$pick];
        $code   = $o['contact'];
        $c      = $this->contact[$code];
        $net    = self::net($gross);
        $no     = $this->_docno('credit_note');
        $inv_no = $this->_doc_no($o['id']);

        $doc = $this->_doc('credit_note', $no, $code, $date, $gross, $net, $gross - $net, '4120', 'Damaged goods returned against ' . $inv_no, 'SLS', NULL, $o['id']);
        $jid = $this->_sys('sales', $date, 'Credit note ' . $no . ' — ' . $c['name'] . ' (damaged goods returned)', [
            ['4120', $net, 0, 'Returned goods', 'SLS'],
            ['2121', $gross - $net, 0, 'Output VAT reversed'],
            ['1121', 0, $gross, $no . ' against ' . $inv_no, NULL, $code],
        ], $no, $c['name'], 'credit_note', $doc);
        $this->CI->db->where('id', $doc)->update('gp_documents', ['journal_id' => $jid]);
        $this->_allocate($o['id'], NULL, $doc, $gross);
        $this->open_inv[$pick]['open'] -= $gross;
        $this->open_inv[$pick]['net']  -= $net;
        $this->vat_out[self::quarter($date)] -= ($gross - $net);

        /* The month-end cost of sales covers everything shipped; the returned
           goods come back into stock at cost here. */
        $cost = (int) round($net * 0.58);
        $this->_manual('general', $date, 'Returned goods back into stock (' . $no . ')', [
            ['1131', $cost, 0, 'At cost'], ['5100', 0, $cost, 'Cost of goods returned', 'SLS'],
        ], $no);
    }

    private function _cost_of_sales($date)
    {
        $cost = (int) (round($this->month_net_sales * 0.58 / 100) * 100);
        if ($cost <= 0) return;
        $this->_manual('general', $date, 'Cost of goods sold for ' . date('F', strtotime($date)), [
            ['5100', $cost, 0, 'Cost of goods sold', 'SLS'], ['1131', 0, $cost, 'Inventory issued'],
        ], 'COGS-' . date('m', strtotime($date)));
    }

    // ── Purchases ────────────────────────────────────────────────────────────

    private function _bill_goods($date)
    {
        $pick = ['S001', 'S001', 'S002', 'S002', 'S003'];
        $this->_supplier_bill($date, $pick[mt_rand(0, 4)], mt_rand(11000, 20500) * 1000, '1131', 'Merchandise purchases', NULL);
    }

    /** @return int the bill's journal id */
    private function _supplier_bill($date, $code, $gross, $account, $desc, $dept)
    {
        $net = self::net($gross);
        $vat = $gross - $net;
        $no  = $this->_docno('bill');
        $c   = $this->contact[$code];
        $ref = 'SI ' . mt_rand(10000, 99999);

        $doc = $this->_doc('bill', $no, $code, $date, $gross, $net, $vat, $account, $desc, $dept, NULL, NULL, $ref);
        $jid = $this->_sys('purchases', $date, 'Bill ' . $no . ' — ' . $c['name'] . ' (' . $ref . ')', [
            [$account, $net, 0, $desc, $dept],
            ['1144', $vat, 0, 'Input VAT'],
            ['2111', 0, $gross, $no . ' / ' . $ref, NULL, $code],
        ], $ref, $c['name'], 'bill', $doc);
        $this->CI->db->where('id', $doc)->update('gp_documents', ['journal_id' => $jid]);

        $this->_vat_in($date, $vat);
        $this->open_bill[] = ['id' => $doc, 'contact' => $code, 'date' => $date, 'open' => $gross, 'net' => $net, 'after' => 26 + mt_rand(0, 10)];
        return $jid;
    }

    private function _pay_suppliers($date)
    {
        $by = [];
        foreach ($this->open_bill as $i => $b) {
            if ($b['open'] <= 0) continue;
            if ((strtotime($date) - strtotime($b['date'])) / 86400 < $b['after']) continue;
            $by[$b['contact']][] = $i;
        }
        foreach ($by as $code => $idx) $this->_payment($date, $code, $idx);
    }

    private function _payment($date, $code, array $idx)
    {
        $c = $this->contact[$code];
        $total = 0; $ewt = 0; $nos = [];
        foreach ($idx as $i) {
            $total += $this->open_bill[$i]['open'];
            $ewt   += (int) round($this->open_bill[$i]['net'] * (int) $c['ewt_rate_bp'] / 10000);
            $nos[]  = $this->_doc_no($this->open_bill[$i]['id']);
        }
        $cash = $total - $ewt;
        $chk  = 'CHK ' . (++$this->chk_no);
        $sid  = $this->_settlement('payment', $this->_docno('payment'), $code, $date, '1113', $chk, $cash, $ewt, 'Payment of ' . implode(', ', $nos));
        foreach ($idx as $i) {
            $this->_allocate($this->open_bill[$i]['id'], $sid, NULL, $this->open_bill[$i]['open']);
            $this->open_bill[$i]['open'] = 0;
        }
        $jid = $this->_sys('cash_disbursements', $date, 'Payment to ' . $c['name'], [
            ['2111', $total, 0, implode(', ', $nos), NULL, $code],
            ['1113', 0, $cash, $chk],
            ['2123', 0, $ewt, 'Expanded withholding tax'],
        ], $chk, $c['name'], 'payment', $sid);
        $this->CI->db->where('id', $sid)->update('gp_settlements', ['journal_id' => $jid]);
        $this->_withhold($date, 'ewt', $ewt);
    }

    // ── Payroll and remittances ─────────────────────────────────────────────

    private function _payroll($date)
    {
        $this->_manual('cash_disbursements', $date, 'Payroll for the period ending ' . date('M j', strtotime($date)), [
            ['6100', self::p(35000), 0, 'Salaries', 'ADM'],
            ['6100', self::p(35000), 0, 'Salaries', 'SLS'],
            ['6100', self::p(20000), 0, 'Salaries', 'WHS'],
            ['2124', 0, self::p(6300),  'Withholding tax on compensation'],
            ['2125', 0, self::p(4050),  'SSS, employee share'],
            ['2126', 0, self::p(2250),  'PhilHealth, employee share'],
            ['2127', 0, self::p(900),   'Pag-IBIG, employee share'],
            ['1113', 0, self::p(76500), 'Net pay'],
        ], 'PR-' . date('Ymd', strtotime($date)), 'Payroll');
        $this->_withhold($date, 'wtc', self::p(6300));
        $this->_withhold($date, 'sss', self::p(4050));
        $this->_withhold($date, 'ph', self::p(2250));
        $this->_withhold($date, 'hdmf', self::p(900));
    }

    private function _employer_contributions($date)
    {
        $this->_manual('general', $date, 'Employer contributions for ' . date('F', strtotime($date)), [
            ['6110', self::p(5800), 0, 'Employer share', 'ADM'],
            ['6110', self::p(5800), 0, 'Employer share', 'SLS'],
            ['6110', self::p(3200), 0, 'Employer share', 'WHS'],
            ['2125', 0, self::p(8500), 'SSS, employer share'],
            ['2126', 0, self::p(4500), 'PhilHealth, employer share'],
            ['2127', 0, self::p(1800), 'Pag-IBIG, employer share'],
        ]);
        $this->_withhold($date, 'sss', self::p(8500));
        $this->_withhold($date, 'ph', self::p(4500));
        $this->_withhold($date, 'hdmf', self::p(1800));
    }

    /** Last month's withholdings and contributions, paid on the 10th. */
    private function _remit($date)
    {
        $prev = date('Y-m', strtotime(substr($date, 0, 8) . '01 -1 day'));
        $w = $this->wh[$prev] ?? NULL;
        if ( ! $w) return;
        $label = date('F Y', strtotime($prev . '-01'));
        foreach ([['sss', '2125', 'Social Security System', 'SSS contributions'],
                  ['ph', '2126', 'PhilHealth', 'PhilHealth contributions'],
                  ['hdmf', '2127', 'Pag-IBIG Fund', 'Pag-IBIG contributions']] as $r) {
            if (empty($w[$r[0]])) continue;
            $this->_manual('cash_disbursements', $date, $r[3] . ' for ' . $label, [[$r[1], $w[$r[0]], 0], ['1113', 0, $w[$r[0]]]],
                           strtoupper($r[0]) . '-' . $prev, $r[2]);
        }
        $bir = ($w['wtc'] ?? 0) + ($w['ewt'] ?? 0);
        if ($bir > 0) {
            $this->_manual('cash_disbursements', $date, 'Withholding taxes for ' . $label, [
                ['2124', $w['wtc'] ?? 0, 0, 'BIR 1601-C'], ['2123', $w['ewt'] ?? 0, 0, 'BIR 0619-E'], ['1113', 0, $bir],
            ], 'BIR-' . $prev, 'Bureau of Internal Revenue');
        }
    }

    private function _withhold($date, $kind, $cents)
    {
        if ($cents <= 0) return;
        $k = substr($date, 0, 7);
        if ( ! isset($this->wh[$k])) $this->wh[$k] = ['sss' => 0, 'ph' => 0, 'hdmf' => 0, 'wtc' => 0, 'ewt' => 0];
        $this->wh[$k][$kind] += $cents;
    }

    // ── Operating expenses ──────────────────────────────────────────────────

    private function _rent($date)
    {
        $this->_manual('cash_disbursements', $date, 'Office and warehouse rent for ' . date('F', strtotime($date)), [
            ['6130', self::p(30000), 0, 'Office rent', 'ADM'],
            ['6130', self::p(20000), 0, 'Warehouse rent', 'WHS'],
            ['1144', self::p(6000), 0, 'Input VAT'],
            ['1113', 0, self::p(53500)],
            ['2123', 0, self::p(2500), 'EWT 5% on rent'],
        ], 'CHK R-' . date('m', strtotime($date)), 'Magsaysay Realty Corp.');
        $this->_vat_in($date, self::p(6000));
        $this->_withhold($date, 'ewt', self::p(2500));
    }

    private function _utilities($date)
    {
        $gross = mt_rand(1900, 2800) * 1000;
        $net   = self::net($gross);
        $adm   = (int) round($net * 0.6);
        $this->_manual('cash_disbursements', $date, 'Electricity and water for ' . date('F', strtotime($date)), [
            ['6140', $adm, 0, 'Office', 'ADM'], ['6140', $net - $adm, 0, 'Warehouse', 'WHS'],
            ['1144', $gross - $net, 0, 'Input VAT'], ['1113', 0, $gross],
        ], 'UT-' . date('m', strtotime($date)), 'CASURECO II and Metro Naga Water');
        $this->_vat_in($date, $gross - $net);
    }

    private function _communication($date)
    {
        $this->_manual('cash_disbursements', $date, 'Telephone and internet for ' . date('F', strtotime($date)), [
            ['6150', self::p(4500), 0, 'Telephone and internet', 'ADM'], ['1144', self::p(540), 0, 'Input VAT'], ['1113', 0, self::p(5040)],
        ], 'PLDT-' . date('m', strtotime($date)), 'PLDT Inc.');
        $this->_vat_in($date, self::p(540));
    }

    private function _fuel($date)
    {
        $gross = mt_rand(600, 950) * 1000;
        $net   = self::net($gross);
        $this->_manual('cash_disbursements', $date, 'Fuel for the delivery truck', [
            ['6170', $net, 0, 'Diesel', 'WHS'], ['1144', $gross - $net, 0, 'Input VAT'], ['1113', 0, $gross],
        ], 'FUEL-' . date('md', strtotime($date)), 'Petron Naga');
        $this->_vat_in($date, $gross - $net);
    }

    private function _supplies($date)
    {
        $this->_manual('cash_disbursements', $date, 'Office supplies for the quarter', [
            ['6190', self::p(8000), 0, 'Office supplies', 'ADM'], ['1144', self::p(960), 0, 'Input VAT'], ['1113', 0, self::p(8960)],
        ], 'OR ' . mt_rand(40000, 49999), 'Naga Office Depot');
        $this->_vat_in($date, self::p(960));
    }

    private function _advertising($date)
    {
        $this->_manual('cash_disbursements', $date, 'Radio and print advertising', [
            ['6240', self::p(30000), 0, 'Advertising', 'SLS'], ['1144', self::p(3600), 0, 'Input VAT'],
            ['1113', 0, self::p(33000)], ['2123', 0, self::p(600), 'EWT 2%'],
        ], 'CHK A-' . date('m', strtotime($date)), 'Bicol Media Ads');
        $this->_vat_in($date, self::p(3600));
        $this->_withhold($date, 'ewt', self::p(600));
    }

    private function _professional_fees($date)
    {
        $this->_supplier_bill($date, 'S005', self::p(28000), '6230', 'Accounting and tax services', 'ADM');
    }

    private function _truck_repair($date)
    {
        $this->_supplier_bill($date, 'S004', self::p(20160), '6180', 'Truck repair and parts', 'WHS');
    }

    private function _insurance($date)
    {
        $this->_manual('cash_disbursements', $date, 'Vehicle insurance for 12 months', [
            ['1142', self::p(36000), 0, 'Prepaid insurance'], ['1113', 0, self::p(36000)],
        ], 'Policy MV-' . $this->year . '-0381', 'Pioneer Insurance');
    }

    /** What the opening balance sheet owed, settled in January. */
    private function _january_payables($date)
    {
        $this->_manual('cash_disbursements', $date, 'VAT for the fourth quarter of ' . ($this->year - 1), [
            ['2122', self::p(38000), 0], ['1113', 0, self::p(38000)],
        ], 'BIR 2550Q', 'Bureau of Internal Revenue');
        $this->_manual('cash_disbursements', $date, 'December utilities and audit fee accrued at year end', [
            ['2112', self::p(45000), 0], ['1113', 0, self::p(45000)],
        ], 'ACCR-' . ($this->year - 1), 'Various');
        $this->_manual('cash_disbursements', $date, 'Business permit and licences for ' . $this->year, [
            ['6220', self::p(25000), 0, 'Mayor\'s permit', 'ADM'], ['1113', 0, self::p(25000)],
        ], 'OR ' . mt_rand(700000, 799999), 'City Treasurer of Naga');
    }

    private function _amortise($date, $mon)
    {
        $this->_manual('adjusting', $date, 'Insurance expense for ' . date('F', strtotime($date)), [
            ['6210', self::p(3000), 0, 'Vehicle insurance', 'WHS'], ['1142', 0, self::p(3000)],
        ]);
        if ($mon <= 6) {
            $this->_manual('adjusting', $date, 'Rent of the extra storage space for ' . date('F', strtotime($date)) . ' (prepaid)', [
                ['6130', self::p(10000), 0, 'Extra storage space', 'WHS'], ['1141', 0, self::p(10000)],
            ]);
        }
    }

    /** One run per month, the way the fixed-asset module will make it. */
    private function _depreciation($date)
    {
        $ym = substr($date, 0, 7);
        $accum_code = ['OE' => '1215', 'FF' => '1217', 'TE' => '1219', 'CE' => '1222'];
        $lines = [];
        $amounts = [];
        foreach ($this->assets as $no => $a) {
            if ($ym < $a['start']) continue;
            $lines[] = ['6200', $a['monthly'], 0, $no, $a['dept']];
            $lines[] = [$accum_code[$a['cat']], 0, $a['monthly'], $no];
            $amounts[$no] = $a['monthly'];
        }
        if ( ! $amounts) return;

        $period = $this->CI->periods->period_for_date($date);
        $this->CI->db->insert('gp_depreciation_runs', ['period_id' => (int) $period['id'], 'total_cents' => array_sum($amounts),
                                                      'run_by' => $this->uid['accountant'], 'run_at' => date('Y-m-d H:i:s')]);
        $run = (int) $this->CI->db->insert_id();
        $jid = $this->_sys('adjusting', $date, 'Depreciation for ' . date('F Y', strtotime($date)), $lines, 'DEP-' . $ym, NULL, 'depreciation', $run);
        $this->CI->db->where('id', $run)->update('gp_depreciation_runs', ['journal_id' => $jid]);

        foreach ($amounts as $no => $amt) {
            $this->assets[$no]['accum'] += $amt;
            $this->CI->db->insert('gp_depreciation_entries', ['run_id' => $run, 'asset_id' => $this->assets[$no]['id'],
                'period_id' => (int) $period['id'], 'amount_cents' => $amt, 'accum_after_cents' => $this->assets[$no]['accum']]);
        }
    }

    // ── Taxes, loans, one-offs ──────────────────────────────────────────────

    /** Quarterly VAT: output less input for quarter $q, paid the month after. */
    private function _pay_vat($date, $q)
    {
        $out = $this->vat_out[$q] ?? 0;
        $in  = $this->vat_in[$q] ?? 0;
        if ($out <= $in) {
            /* Excess input VAT carries over to the next quarter. */
            if ($out > 0) {
                $this->_manual('general', $date, 'VAT for quarter ' . $q . ' (excess input VAT carried over)', [
                    ['2121', $out, 0, 'Output VAT'], ['1144', 0, $out, 'Input VAT applied'],
                ], 'BIR 2550Q Q' . $q);
            }
            $this->vat_in[$q + 1] = ($this->vat_in[$q + 1] ?? 0) + ($in - max($out, 0));
            return;
        }
        $this->_manual('cash_disbursements', $date, 'VAT for quarter ' . $q, [
            ['2121', $out, 0, 'Output VAT'], ['1144', 0, $in, 'Input VAT'], ['1113', 0, $out - $in, 'VAT paid'],
        ], 'BIR 2550Q Q' . $q, 'Bureau of Internal Revenue');
    }

    /** 20% of the quarter's income before tax (a small corporation's rate). */
    private function _income_tax_accrual($date, $q)
    {
        $qs = $this->year . '-' . sprintf('%02d', ($q - 1) * 3 + 1) . '-01';
        $r = $this->CI->db->query(
            "SELECT COALESCE(SUM(l.credit_cents - l.debit_cents), 0) AS income
               FROM gp_ledger l JOIN gp_accounts a ON a.id = l.account_id
              WHERE a.type IN ('income', 'expense') AND COALESCE(a.subtype, '') <> 'income_tax'
                AND l.entry_date BETWEEN ? AND ? AND l.book <> 'closing'",
            [$qs, $date]
        )->row_array();
        $tax = (int) (round(((int) $r['income']) * 0.20 / 100) * 100);
        if ($tax <= 0) return;
        $this->tax_due[$q] = $tax;
        $this->_manual('adjusting', $date, 'Income tax for quarter ' . $q . ' (20%)', [
            ['8100', $tax, 0, 'Quarterly income tax', 'ADM'], ['2128', 0, $tax],
        ], 'ITX-Q' . $q);
    }

    /** Paid with the quarterly return, less the tax customers withheld (BIR Form 2307). */
    private function _pay_income_tax($date, $q)
    {
        $tax = $this->tax_due[$q] ?? 0;
        if ($tax <= 0) return;
        $cwt = (int) $this->CI->db->query('SELECT COALESCE(SUM(net_cents), 0) AS b FROM gp_ledger WHERE account_id = ? AND entry_date <= ?',
                                          [$this->acct['1145'], $date])->row()->b;
        $use = max(0, min($cwt, $tax));
        $this->_manual('cash_disbursements', $date, 'Quarterly income tax, quarter ' . $q . ($use > 0 ? ', less creditable withholding tax' : ''), [
            ['2128', $tax, 0, 'Income tax due'], ['1145', 0, $use, 'Creditable withholding tax applied'], ['1113', 0, $tax - $use, 'Paid'],
        ], 'BIR 1702Q Q' . $q, 'Bureau of Internal Revenue');
    }

    private function _loan($date)
    {
        $interest  = (int) round($this->loan_balance * 0.08 / 4);
        $principal = self::p(60000);
        $this->_manual('cash_disbursements', $date, 'Quarterly loan amortisation', [
            ['2130', $principal, 0, 'Principal'], ['7100', $interest, 0, 'Interest at 8% a year', 'ADM'], ['1113', 0, $principal + $interest],
        ], 'LBP 0457-' . date('m', strtotime($date)), 'Land Bank of the Philippines');
        $this->loan_balance -= $principal;
        $this->_manual('adjusting', $date, 'Current portion of the long-term loan', [
            ['2210', $principal, 0, 'Principal due within a year'], ['2130', 0, $principal],
        ]);
    }

    /** A mistake, found two days later: reversed, then posted correctly. */
    private function _february_mistake($date)
    {
        $this->feb_error = $this->_manual('cash_disbursements', $date, 'Office supplies', [
            ['6180', self::p(5000), 0, 'Office supplies', 'ADM'], ['1144', self::p(600), 0, 'Input VAT'], ['1113', 0, self::p(5600)],
        ], 'OR 45521', 'Naga Office Depot');
        $this->_vat_in($date, self::p(600));
    }

    private function _february_fix($date)
    {
        list($rev, $err) = $this->CI->journals->reverse($this->feb_error, $this->uid['accountant'], $date, 'Charged to Repairs and Maintenance instead of Office Supplies');
        if ( ! $rev) throw new RuntimeException('reversal: ' . $err);
        $this->counts['system']++;
        $this->_manual('cash_disbursements', $date, 'Office supplies (corrected entry)', [
            ['6190', self::p(5000), 0, 'Office supplies', 'ADM'], ['1144', self::p(600), 0, 'Input VAT'], ['1113', 0, self::p(5600)],
        ], 'OR 45521', 'Naga Office Depot');
    }

    private function _buy_laptops($date)
    {
        $jid = $this->_supplier_bill($date, 'S003', self::p(168000), '1221', 'Five laptop computers', 'ADM');
        $this->_asset('CE', 'Laptop computers (5 units)', $date, date('Y-m-d', strtotime($date . ' first day of next month')), 150000, 36, 0, 'ADM', 'Main office', $jid);
    }

    // =========================================================================
    // BANKS, BUDGET, PERIODS, WAITING ENTRIES
    // =========================================================================

    /**
     * The bank accounts, and last month's BDO statement built from the ledger
     * with the usual differences left in for reconciling: the last two cheques
     * still outstanding, the last cash deposit in transit, and a fee and some
     * interest the books do not have yet.
     */
    private function _banks_and_statement()
    {
        $now = date('Y-m-d H:i:s');
        $this->CI->db->insert('gp_bank_accounts', ['account_id' => $this->acct['1113'], 'bank_name' => 'BDO Unibank', 'account_name' => 'Demo Trading Corporation',
            'account_last4' => '4821', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $bdo = (int) $this->CI->db->insert_id();
        $this->CI->db->insert('gp_bank_accounts', ['account_id' => $this->acct['1114'], 'bank_name' => 'Bank of the Philippine Islands', 'account_name' => 'Demo Trading Corporation',
            'account_last4' => '0937', 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now]);

        $ms = date('Y-m-01', strtotime(substr($this->end, 0, 8) . '01 -1 day'));
        $me = date('Y-m-t', strtotime($ms));
        if ($ms < $this->year . '-01-01' || $me > $this->end) return;

        $open = (int) $this->CI->db->query('SELECT COALESCE(SUM(net_cents), 0) AS b FROM gp_ledger WHERE account_id = ? AND entry_date < ?',
                                           [$this->acct['1113'], $ms])->row()->b;
        $rows = $this->CI->db->query('SELECT entry_date, debit_cents, credit_cents, reference FROM gp_ledger
                                       WHERE account_id = ? AND entry_date BETWEEN ? AND ? ORDER BY entry_date, journal_id, line_no',
                                     [$this->acct['1113'], $ms, $me])->result_array();

        $skip = [];
        $oc = 0; $oc_n = 0; $dit = 0;
        for ($i = count($rows) - 1; $i >= 0 && $oc_n < 2; $i--) {
            if ((int) $rows[$i]['credit_cents'] > 0 && strpos((string) $rows[$i]['reference'], 'CHK') === 0) { $skip[$i] = TRUE; $oc += (int) $rows[$i]['credit_cents']; $oc_n++; }
        }
        for ($i = count($rows) - 1; $i >= 0; $i--) {
            if ((int) $rows[$i]['debit_cents'] > 0 && strpos((string) $rows[$i]['reference'], 'DS-') === 0) { $skip[$i] = TRUE; $dit = (int) $rows[$i]['debit_cents']; break; }
        }

        $lines = [];
        foreach ($rows as $i => $r) {
            if (isset($skip[$i])) continue;
            $dep  = (int) $r['debit_cents'] > 0;
            $when = $dep ? $r['entry_date'] : min($me, date('Y-m-d', strtotime($r['entry_date'] . ' +' . (1 + $i % 3) . ' days')));
            $what = $dep ? 'DEPOSIT' : (strpos((string) $r['reference'], 'CHK') === 0 ? 'CHECK ENCASHED' : 'DEBIT MEMO');
            $lines[] = [$when, $what, $r['reference'], $dep ? (int) $r['debit_cents'] : -(int) $r['credit_cents']];
        }
        $lines[] = [date('Y-m-20', strtotime($ms)), 'ONLINE TRANSFER FEE', NULL, -self::p(150)];
        $lines[] = [$me, 'INTEREST CREDITED', NULL, self::p(45.20)];
        usort($lines, function ($a, $b) { return strcmp($a[0], $b[0]); });

        $close = $open + array_sum(array_column($lines, 3));
        $this->CI->db->insert('gp_bank_statements', ['bank_account_id' => $bdo, 'statement_date' => $me, 'opening_balance_cents' => $open,
            'closing_balance_cents' => $close, 'status' => 'open', 'created_by' => $this->uid['bookkeeper'], 'created_at' => $now, 'updated_at' => $now]);
        $st = (int) $this->CI->db->insert_id();
        foreach ($lines as $l) {
            $this->CI->db->insert('gp_bank_lines', ['statement_id' => $st, 'txn_date' => $l[0], 'description' => $l[1],
                'reference' => $l[2], 'amount_cents' => $l[3], 'status' => 'unmatched']);
        }
        $this->bank_note = 'BDO statement for ' . date('F', strtotime($ms)) . ', ' . count($lines) . ' lines, unreconciled: '
                         . $oc_n . ' outstanding cheques (' . number_format($oc / 100, 2) . '), a deposit in transit ('
                         . number_format($dit / 100, 2) . '), a 150.00 fee and 45.20 interest to record';
    }

    /** The year's approved budget, per account, department and month (pesos). */
    private function _budget()
    {
        $fy  = $this->CI->periods->year_for_date($this->year . '-01-01');
        $now = date('Y-m-d H:i:s');
        $this->CI->db->insert('gp_budgets', ['fiscal_year_id' => (int) $fy['id'], 'name' => 'Original budget', 'status' => 'approved', 'is_primary' => 1,
            'notes' => 'Approved by the board in December', 'created_by' => $this->uid['accountant'], 'created_at' => $now, 'updated_at' => $now]);
        $b = (int) $this->CI->db->insert_id();

        $plan = [
            ['4110', 'SLS', 900000], ['5100', 'SLS', 522000],
            ['6100', 'ADM', 70000], ['6100', 'SLS', 70000], ['6100', 'WHS', 40000],
            ['6110', 'ADM', 5800], ['6110', 'SLS', 5800], ['6110', 'WHS', 3200],
            ['6120', 'SLS', 1000],
            ['6130', 'ADM', 30000], ['6130', 'WHS', function ($m) { return $m <= 6 ? 30000 : 20000; }],
            ['6140', 'ADM', 13000], ['6140', 'WHS', 9000], ['6150', 'ADM', 4500], ['6170', 'WHS', 14000],
            ['6180', 'WHS', 2000], ['6190', 'ADM', 3000],
            ['6200', 'ADM', function ($m) { return $m <= 3 ? 10500 : 14666.67; }], ['6200', 'WHS', 20000],
            ['6210', 'WHS', 3000], ['6220', 'ADM', function ($m) { return $m === 1 ? 25000 : 0; }],
            ['6230', 'ADM', 8500], ['6240', 'SLS', 7500], ['6270', 'ADM', 400], ['7100', 'ADM', 5500],
        ];
        foreach ($plan as $p) {
            for ($m = 1; $m <= 12; $m++) {
                $amt = self::p(is_callable($p[2]) ? $p[2]($m) : $p[2]);
                if ($amt === 0) continue;
                $this->CI->db->insert('gp_budget_lines', ['budget_id' => $b, 'account_id' => $this->acct[$p[0]],
                    'department_id' => $this->dept[$p[1]], 'period_no' => $m, 'amount_cents' => $amt]);
            }
        }
    }

    /** Close every month before last month; lock the first quarter. */
    private function _periods()
    {
        $cutoff = date('Y-m-01', strtotime(substr($this->end, 0, 8) . '01 -1 day'));
        foreach ($this->CI->db->where('end_date <', $cutoff)->order_by('start_date')->get('gp_periods')->result_array() as $p) {
            if ($e = $this->CI->periods->set_status($p['id'], 'closed', $this->uid['accountant'])) throw new RuntimeException('close ' . $p['name'] . ': ' . $e);
            if ((int) $p['period_no'] <= 3 && ($e = $this->CI->periods->set_status($p['id'], 'locked', $this->uid['admin']))) {
                throw new RuntimeException('lock ' . $p['name'] . ': ' . $e);
            }
        }
    }

    /** Entries in the current month at every stage short of posted. */
    private function _workflow()
    {
        $bk = $this->uid['bookkeeper'];
        $ac = $this->uid['accountant'];
        $first = substr($this->end, 0, 8) . '01';
        $day = function ($back) use ($first) { return max($first, date('Y-m-d', strtotime($this->end . ' -' . $back . ' days'))); };

        $id = $this->_draft('cash_disbursements', $day(6), 'Fuel for the delivery truck', [
            ['6170', self::p(7000), 0, 'Diesel', 'WHS'], ['1113', 0, self::p(7000)],
        ], 'FUEL-DUP', 'Petron Naga');
        $this->_check('cancel', $this->CI->journals->cancel($id, $bk));

        $id = $this->_draft('cash_disbursements', $day(4), 'Replenish the petty cash fund', [
            ['6290', self::p(4200), 0, 'Petty cash expenses', 'ADM'], ['1113', 0, self::p(4200)],
        ], 'PCF-01', 'Ana Cruz (petty cash custodian)');
        $this->_check('submit', $this->CI->journals->submit($id, $bk));
        $this->_check('reject', $this->CI->journals->reject($id, $ac, 'Attach the petty cash replenishment report and correct the amount to 4,350.00.'));

        $id = $this->_draft('adjusting', $day(0), 'Accrue this month\'s electricity (estimate)', [
            ['6140', self::p(21000), 0, 'Estimated', 'ADM'], ['2112', 0, self::p(21000)],
        ], 'ACC-UT');
        $this->_check('submit', $this->CI->journals->submit($id, $bk));

        $this->_draft('general', $day(1), 'Uniforms for the sales team', [
            ['6120', self::p(12500), 0, 'Uniforms', 'SLS'], ['1113', 0, self::p(12500)],
        ], 'UNIF-01', 'Naga Uniform Shop');
    }

    private function _summary($seconds)
    {
        $db = $this->CI->db;
        $by = [];
        foreach ($db->query('SELECT status, COUNT(*) AS n FROM gp_journals GROUP BY status')->result_array() as $r) $by[$r['status']] = (int) $r['n'];
        $books = [];
        foreach ($db->query("SELECT book, COUNT(*) AS n FROM gp_journals WHERE status = 'posted' GROUP BY book ORDER BY book")->result_array() as $r) {
            $books[] = $r['book'] . ' ' . $r['n'];
        }
        $docs = [];
        foreach ($db->query('SELECT doc_type, COUNT(*) AS n FROM gp_documents GROUP BY doc_type ORDER BY doc_type')->result_array() as $r) {
            $docs[] = $r['n'] . ' ' . str_replace('_', ' ', $r['doc_type']) . ((int) $r['n'] === 1 ? '' : 's');
        }
        $tb = $this->CI->ledger->trial_balance($this->end, 'post_closing');
        $first = substr($this->end, 0, 8) . '01';

        $out = [];
        $out[] = 'Demo Trading Corporation — seeded in ' . round($seconds, 1) . ' s';
        $out[] = '  Books      1 January ' . $this->year . ' to ' . $this->end;
        $out[] = '  Master     ' . count($this->acct) . ' accounts, ' . count($this->contact) . ' customers and suppliers, '
               . count($this->dept) . ' departments, ' . count($this->assets) . ' fixed assets';
        $out[] = '  Journals   ' . ($by['posted'] ?? 0) . ' posted (' . implode(', ', $books) . ')';
        $out[] = '             ' . $this->counts['manual'] . ' prepared by the bookkeeper and approved by the accountant, '
               . $this->counts['system'] . ' posted by the modules';
        $out[] = '             waiting: ' . ($by['submitted'] ?? 0) . ' submitted, ' . ($by['rejected'] ?? 0) . ' rejected, '
               . ($by['draft'] ?? 0) . ' draft; ' . ($by['cancelled'] ?? 0) . ' cancelled';
        $out[] = '  Documents  ' . implode(', ', $docs) . '; ' . (int) $db->count_all('gp_settlements') . ' receipts and payments';
        $out[] = '  Banking    ' . $this->bank_note;
        $out[] = '  Periods    first quarter locked; closed through ' . date('F', strtotime($first . ' -1 month -1 day'))
               . '; ' . date('F', strtotime($first . ' -1 day')) . ' and ' . date('F', strtotime($this->end)) . ' open';
        $out[] = '  Trial balance at ' . $this->end . ': debits ' . number_format($tb['total_debit_cents'] / 100, 2) . ', credits '
               . number_format($tb['total_credit_cents'] / 100, 2) . ($tb['balanced'] ? ' — balanced' : ' — NOT BALANCED');
        $out[] = '';
        $out[] = 'Demo sign-ins (local only):';
        foreach (self::USERS as $role => $u) $out[] = sprintf('  %-11s %-26s %s', $role, $u[0], $u[1]);
        return $out;
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    /** Posted by the modules, as the accountant. */
    private function _sys($book, $date, $desc, array $rows, $ref = NULL, $party = NULL, $source = 'manual', $source_id = NULL)
    {
        list($id, $e) = $this->CI->journals->post_system(
            ['book' => $book, 'entry_date' => $date, 'description' => $desc, 'reference' => $ref, 'party_name' => $party],
            $this->_lines($rows), $this->uid['accountant'], $source, $source_id
        );
        if ( ! $id) throw new RuntimeException($date . ' "' . $desc . '": ' . implode(' ', $e));
        $this->counts['system']++;
        return $id;
    }

    /** Prepared by the bookkeeper. */
    private function _draft($book, $date, $desc, array $rows, $ref = NULL, $party = NULL)
    {
        list($id, $e) = $this->CI->journals->create_draft(
            ['book' => $book, 'entry_date' => $date, 'description' => $desc, 'reference' => $ref, 'party_name' => $party],
            $this->_lines($rows), $this->uid['bookkeeper']
        );
        if ( ! $id) throw new RuntimeException($date . ' "' . $desc . '": ' . implode(' ', $e));
        return $id;
    }

    /** Prepared and submitted by the bookkeeper, approved (posted) by the accountant. */
    private function _manual($book, $date, $desc, array $rows, $ref = NULL, $party = NULL)
    {
        $id = $this->_draft($book, $date, $desc, $rows, $ref, $party);
        $this->_check($date . ' submit "' . $desc . '"', $this->CI->journals->submit($id, $this->uid['bookkeeper']));
        $this->_check($date . ' approve "' . $desc . '"', $this->CI->journals->approve($id, $this->uid['accountant']));
        $this->counts['manual']++;
        return $id;
    }

    private function _check($what, $error)
    {
        if ($error !== '') throw new RuntimeException($what . ': ' . $error);
    }

    /** [code, debit, credit, memo, department code, contact code] → model lines; zero lines dropped. */
    private function _lines(array $rows)
    {
        $out = [];
        foreach ($rows as $r) {
            if ((int) $r[1] === 0 && (int) $r[2] === 0) continue;
            $out[] = [
                'account_id'    => $this->acct[$r[0]],
                'debit_cents'   => (int) $r[1],
                'credit_cents'  => (int) $r[2],
                'memo'          => $r[3] ?? NULL,
                'department_id' => ! empty($r[4]) ? $this->dept[$r[4]] : NULL,
                'contact_id'    => ! empty($r[5]) ? (int) $this->contact[$r[5]]['id'] : NULL,
            ];
        }
        return $out;
    }

    /** The next number for a document type, as the AR/AP module will number it. */
    private function _docno($type)
    {
        $prefix = strtoupper((string) $this->CI->config->item('doc_prefix_' . $type));
        $digits = (int) $this->CI->config->item('doc_number_digits') ?: 6;
        return $prefix . '-' . str_pad((string) next_sequence('doc:' . $type), $digits, '0', STR_PAD_LEFT);
    }

    private function _doc_no($document_id)
    {
        return $this->CI->db->select('doc_no')->get_where('gp_documents', ['id' => (int) $document_id], 1)->row()->doc_no;
    }

    private function _doc($type, $no, $code, $date, $total, $net, $vat, $account, $desc, $dept, $journal_id, $related = NULL, $ref = NULL)
    {
        $c   = $this->contact[$code];
        $now = date('Y-m-d H:i:s');
        $this->CI->db->insert('gp_documents', [
            'doc_type' => $type, 'doc_no' => $no, 'contact_id' => (int) $c['id'], 'doc_date' => $date,
            'due_date' => in_array($type, ['invoice', 'bill'], TRUE) ? date('Y-m-d', strtotime($date . ' +' . (int) $c['terms_days'] . ' days')) : NULL,
            'reference' => $ref, 'description' => $desc, 'prices_include_tax' => 1,
            'net_cents' => $net, 'vat_cents' => $vat, 'total_cents' => $total, 'applied_cents' => 0,
            'status' => 'posted', 'journal_id' => $journal_id, 'related_document_id' => $related,
            'created_by' => $this->uid['bookkeeper'], 'posted_by' => $this->uid['accountant'], 'posted_at' => $now,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $this->CI->db->insert_id();
        $this->CI->db->insert('gp_document_lines', [
            'document_id' => $id, 'line_no' => 1, 'account_id' => $this->acct[$account], 'description' => $desc,
            'quantity' => 1, 'unit_price_cents' => $total, 'amount_cents' => $total,
            'vat_mode' => $vat > 0 ? 'vatable' : 'exempt', 'net_cents' => $net, 'vat_cents' => $vat,
            'department_id' => $dept ? $this->dept[$dept] : NULL,
        ]);
        return $id;
    }

    private function _settlement($kind, $no, $code, $date, $cash_code, $ref, $amount, $withholding, $desc)
    {
        $now = date('Y-m-d H:i:s');
        $this->CI->db->insert('gp_settlements', [
            'kind' => $kind, 'settle_no' => $no, 'contact_id' => (int) $this->contact[$code]['id'], 'settle_date' => $date,
            'cash_account_id' => $this->acct[$cash_code], 'reference' => $ref, 'amount_cents' => $amount, 'withholding_cents' => $withholding,
            'description' => mb_substr($desc, 0, 500), 'status' => 'posted', 'created_by' => $this->uid['bookkeeper'],
            'posted_by' => $this->uid['accountant'], 'posted_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return (int) $this->CI->db->insert_id();
    }

    /** Settle $amount of a document from a receipt/payment or a credit note. */
    private function _allocate($document_id, $settlement_id, $credit_document_id, $amount)
    {
        $this->CI->db->insert('gp_allocations', ['document_id' => $document_id, 'settlement_id' => $settlement_id,
            'credit_document_id' => $credit_document_id, 'amount_cents' => $amount, 'created_at' => date('Y-m-d H:i:s')]);
        foreach (array_filter([$document_id, $credit_document_id]) as $id) {
            $this->CI->db->set('applied_cents', 'applied_cents + ' . (int) $amount, FALSE)->where('id', (int) $id)->update('gp_documents');
        }
    }

    private function _vat_in($date, $cents)
    {
        $q = self::quarter($date);
        $this->vat_in[$q] = ($this->vat_in[$q] ?? 0) + $cents;
    }

    /** Pesos → centavos. */
    private static function p($pesos)
    {
        return (int) round($pesos * 100);
    }

    /** The VATable amount inside a 12% VAT-inclusive gross, in centavos. */
    private static function net($gross)
    {
        return (int) round($gross * 100 / 112);
    }

    private static function quarter($date)
    {
        return intdiv((int) substr($date, 5, 2) - 1, 3) + 1;
    }
}
