<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/**
 * Chart_templates.php — the starting charts of accounts
 *
 * GenericPOS Accounting
 *
 *   business_corporation          PFRS for SMEs-style chart; equity closes to retained earnings
 *   business_sole_proprietorship  the same, with owner's capital and drawings
 *   cooperative                   laid out after the CDA standard chart for multipurpose
 *                                 co-operatives (credit and consumer operations),
 *                                 with share capital and the four statutory funds
 *
 * Setup seeds one of these; after that the chart belongs to the company and is
 * edited in Settings → Chart of accounts.
 *
 * NOTE: THE CO-OP CODES FOLLOW THE CDA STANDARD CHART'S STRUCTURE, NOT A
 * VERIFIED COPY OF ITS CURRENT EDITION. Before a co-operative goes live, compare
 * them with the CDA-prescribed chart it reports under and rename or renumber
 * accounts there — the statements are built from the account TYPES and the
 * header tree, so renumbering changes nothing else.
 *
 * ─── ROW FORMAT ───────────────────────────────────────────────────────────
 *   [code, name, parent code, attributes]
 *   h       1 = header (groups accounts, takes no lines)
 *   t       type: asset | liability | equity | income | expense   (top level only)
 *   s       subtype (see Account_model::SUBTYPES)
 *   cf      cash-flow class: operating | investing | financing | cash
 *   contra  1 = contra account (its normal side is the opposite of its type's)
 *   ctl     ar | ap — a control account; every line on it names a customer/supplier
 *   tags    analysis tags, comma-separated (Analysis_lib)
 *
 * Type, subtype, cash-flow class and tags are INHERITED from the parent unless
 * the row sets them, so a header decides for everything under it.
 */
class Chart_templates
{
    const KINDS = ['business_corporation', 'business_sole_proprietorship', 'cooperative'];

    /**
     * @return array|NULL ['label', 'accounts' => normalised rows, 'defaults' => [setting => code],
     *                     'settings' => [setting => value]]
     */
    public static function get($kind)
    {
        switch ((string) $kind) {
            case 'business_corporation':         return self::_business('corporation');
            case 'business_sole_proprietorship': return self::_business('sole_proprietorship');
            case 'cooperative':                  return self::_coop();
        }
        return NULL;
    }

    public static function labels()
    {
        return [
            'business_corporation'         => 'Business — corporation',
            'business_sole_proprietorship' => 'Business — sole proprietorship',
            'cooperative'                  => 'Co-operative (CDA-style chart)',
        ];
    }

    /**
     * Resolve inheritance and validate. Parents must come before their children.
     * @return array rows: code, name, parent_code, is_header, type, subtype, is_contra,
     *                     normal_side, cash_flow, control, tags, sort_order
     */
    public static function normalise(array $rows)
    {
        $out = [];
        $by  = [];
        $i   = 0;
        foreach ($rows as $r) {
            $code   = (string) $r[0];
            $parent = $r[2] !== NULL ? (string) $r[2] : NULL;
            $a      = isset($r[3]) ? $r[3] : [];

            if (isset($by[$code])) throw new InvalidArgumentException('duplicate account code ' . $code);
            $p = NULL;
            if ($parent !== NULL) {
                if ( ! isset($by[$parent]))       throw new InvalidArgumentException($code . ': parent ' . $parent . ' is not defined before it');
                if ( ! $by[$parent]['is_header']) throw new InvalidArgumentException($code . ': parent ' . $parent . ' is not a header');
                $p = $by[$parent];
            }

            $type = isset($a['t']) ? $a['t'] : ($p ? $p['type'] : NULL);
            if ( ! in_array($type, ['asset', 'liability', 'equity', 'income', 'expense'], TRUE)) {
                throw new InvalidArgumentException($code . ': no account type');
            }
            $contra = ! empty($a['contra']);
            $debit_type = in_array($type, ['asset', 'expense'], TRUE);

            $tags = $p ? array_filter(explode(',', $p['tags'])) : [];
            if ( ! empty($a['tags'])) $tags = array_merge($tags, explode(',', $a['tags']));

            $row = [
                'code'        => $code,
                'name'        => (string) $r[1],
                'parent_code' => $parent,
                'is_header'   => ! empty($a['h']),
                'type'        => $type,
                'subtype'     => isset($a['s']) ? $a['s'] : ($p ? $p['subtype'] : NULL),
                'is_contra'   => $contra,
                'normal_side' => ($debit_type xor $contra) ? 'D' : 'C',
                'cash_flow'   => isset($a['cf']) ? $a['cf'] : ($p ? $p['cash_flow'] : NULL),
                'control'     => isset($a['ctl']) ? $a['ctl'] : NULL,
                'tags'        => implode(',', array_values(array_unique(array_map('trim', $tags)))),
                'sort_order'  => $i += 10,
            ];
            $by[$code] = $row;
            $out[]     = $row;
        }
        return $out;
    }

    // =========================================================================
    // BUSINESS
    // =========================================================================

    private static function _business($form)
    {
        $rows = [
            ['1000', 'Assets', NULL, ['h' => 1, 't' => 'asset']],
            ['1100', 'Current Assets', '1000', ['h' => 1, 's' => 'current', 'cf' => 'operating']],
            ['1110', 'Cash and Cash Equivalents', '1100', ['h' => 1, 'cf' => 'cash', 'tags' => 'cash']],
            ['1111', 'Cash on Hand', '1110'],
            ['1112', 'Petty Cash Fund', '1110'],
            ['1113', 'Cash in Bank - Current Account', '1110'],
            ['1114', 'Cash in Bank - Savings Account', '1110'],
            ['1120', 'Trade and Other Receivables', '1100', ['h' => 1, 'tags' => 'receivable']],
            ['1121', 'Accounts Receivable - Trade', '1120', ['ctl' => 'ar', 'tags' => 'trade_receivable']],
            ['1122', 'Allowance for Doubtful Accounts', '1120', ['contra' => 1, 'tags' => 'trade_receivable']],
            ['1123', 'Advances to Officers and Employees', '1120'],
            ['1124', 'Other Receivables', '1120'],
            ['1130', 'Inventories', '1100', ['h' => 1, 'tags' => 'inventory']],
            ['1131', 'Merchandise Inventory', '1130'],
            ['1132', 'Allowance for Inventory Obsolescence', '1130', ['contra' => 1]],
            ['1140', 'Prepayments and Other Current Assets', '1100', ['h' => 1]],
            ['1141', 'Prepaid Rent', '1140'],
            ['1142', 'Prepaid Insurance', '1140'],
            ['1143', 'Office Supplies', '1140'],
            ['1144', 'Input VAT', '1140', ['tags' => 'input_vat']],
            ['1145', 'Creditable Withholding Tax', '1140', ['tags' => 'cwt']],
            ['1146', 'Advances to Suppliers', '1140'],
            ['1200', 'Non-Current Assets', '1000', ['h' => 1, 's' => 'non_current', 'cf' => 'investing']],
            ['1210', 'Property and Equipment', '1200', ['h' => 1, 'tags' => 'ppe']],
            ['1211', 'Land', '1210'],
            ['1212', 'Building', '1210'],
            ['1213', 'Accumulated Depreciation - Building', '1210', ['contra' => 1, 'tags' => 'accum_depr']],
            ['1214', 'Office Equipment', '1210'],
            ['1215', 'Accumulated Depreciation - Office Equipment', '1210', ['contra' => 1, 'tags' => 'accum_depr']],
            ['1216', 'Furniture and Fixtures', '1210'],
            ['1217', 'Accumulated Depreciation - Furniture and Fixtures', '1210', ['contra' => 1, 'tags' => 'accum_depr']],
            ['1218', 'Transportation Equipment', '1210'],
            ['1219', 'Accumulated Depreciation - Transportation Equipment', '1210', ['contra' => 1, 'tags' => 'accum_depr']],
            ['1221', 'Computer Equipment', '1210'],
            ['1222', 'Accumulated Depreciation - Computer Equipment', '1210', ['contra' => 1, 'tags' => 'accum_depr']],
            ['1223', 'Leasehold Improvements', '1210'],
            ['1224', 'Accumulated Depreciation - Leasehold Improvements', '1210', ['contra' => 1, 'tags' => 'accum_depr']],
            ['1250', 'Other Non-Current Assets', '1200', ['h' => 1]],
            ['1251', 'Security Deposits', '1250'],
            ['1252', 'Other Non-Current Assets', '1250'],

            ['2000', 'Liabilities', NULL, ['h' => 1, 't' => 'liability']],
            ['2100', 'Current Liabilities', '2000', ['h' => 1, 's' => 'current', 'cf' => 'operating']],
            ['2110', 'Trade and Other Payables', '2100', ['h' => 1, 'tags' => 'payable']],
            ['2111', 'Accounts Payable - Trade', '2110', ['ctl' => 'ap', 'tags' => 'trade_payable']],
            ['2112', 'Accrued Expenses', '2110'],
            ['2113', 'Advances from Customers', '2110'],
            ['2114', 'Other Payables', '2110'],
            ['2120', 'Taxes and Government Contributions Payable', '2100', ['h' => 1]],
            ['2121', 'Output VAT', '2120', ['tags' => 'output_vat']],
            ['2122', 'VAT Payable', '2120'],
            ['2123', 'Withholding Tax Payable - Expanded', '2120', ['tags' => 'ewt']],
            ['2124', 'Withholding Tax Payable - Compensation', '2120'],
            ['2125', 'SSS Contributions Payable', '2120'],
            ['2126', 'PhilHealth Contributions Payable', '2120'],
            ['2127', 'Pag-IBIG Contributions Payable', '2120'],
            ['2128', 'Income Tax Payable', '2120'],
            ['2130', 'Loans Payable - Current Portion', '2100', ['cf' => 'financing', 'tags' => 'debt']],
            ['2140', 'Interest Payable', '2100'],
            ['2200', 'Non-Current Liabilities', '2000', ['h' => 1, 's' => 'non_current', 'cf' => 'financing']],
            ['2210', 'Loans Payable - Non-Current', '2200', ['tags' => 'debt']],
            ['2220', 'Retirement Benefit Obligation', '2200', ['cf' => 'operating']],

            ['3000', 'Equity', NULL, ['h' => 1, 't' => 'equity', 'cf' => 'financing']],
        ];

        if ($form === 'sole_proprietorship') {
            $rows[] = ['3100', "Owner's Equity", '3000', ['h' => 1, 's' => 'capital']];
            $rows[] = ['3110', "Owner's Capital", '3100'];
            $rows[] = ['3120', "Owner's Drawings", '3100', ['s' => 'drawing', 'contra' => 1]];
        } else {
            $rows[] = ['3100', 'Contributed Capital', '3000', ['h' => 1, 's' => 'capital']];
            $rows[] = ['3110', 'Share Capital', '3100', ['tags' => 'share_capital']];
            $rows[] = ['3120', 'Additional Paid-in Capital', '3100'];
            $rows[] = ['3130', 'Treasury Shares', '3100', ['contra' => 1]];
            $rows[] = ['3200', 'Retained Earnings', '3000', ['h' => 1, 's' => 'retained']];
            $rows[] = ['3210', 'Retained Earnings - Unappropriated', '3200'];
            $rows[] = ['3220', 'Retained Earnings - Appropriated', '3200'];
        }

        $rows = array_merge($rows, [
            ['4000', 'Revenue', NULL, ['h' => 1, 't' => 'income', 's' => 'operating', 'cf' => 'operating']],
            ['4100', 'Net Sales', '4000', ['h' => 1, 'tags' => 'sales']],
            ['4110', 'Sales', '4100'],
            ['4120', 'Sales Returns and Allowances', '4100', ['contra' => 1]],
            ['4130', 'Sales Discounts', '4100', ['contra' => 1]],
            ['4200', 'Service Revenue', '4000', ['h' => 1, 'tags' => 'sales']],
            ['4210', 'Service Income', '4200'],
            ['4900', 'Other Income', '4000', ['h' => 1, 's' => 'other']],
            ['4910', 'Interest Income', '4900', ['tags' => 'interest_income']],
            ['4920', 'Gain on Disposal of Property and Equipment', '4900', ['tags' => 'disposal_gain']],
            ['4930', 'Miscellaneous Income', '4900'],

            ['5000', 'Cost of Sales', NULL, ['h' => 1, 't' => 'expense', 's' => 'cost_of_sales', 'cf' => 'operating', 'tags' => 'cogs']],
            ['5100', 'Cost of Goods Sold', '5000'],
            ['5110', 'Inventory Losses and Shrinkage', '5000'],

            ['6000', 'Operating Expenses', NULL, ['h' => 1, 't' => 'expense', 's' => 'operating', 'cf' => 'operating']],
            ['6100', 'Salaries and Wages', '6000'],
            ['6110', 'SSS, PhilHealth and Pag-IBIG Contributions', '6000'],
            ['6120', 'Employee Benefits', '6000'],
            ['6130', 'Rent Expense', '6000'],
            ['6140', 'Power, Light and Water', '6000'],
            ['6150', 'Communication Expense', '6000'],
            ['6160', 'Transportation and Travel', '6000'],
            ['6170', 'Fuel and Oil', '6000'],
            ['6180', 'Repairs and Maintenance', '6000'],
            ['6190', 'Office Supplies Expense', '6000'],
            ['6200', 'Depreciation Expense', '6000', ['tags' => 'depreciation']],
            ['6210', 'Insurance Expense', '6000'],
            ['6220', 'Taxes and Licenses', '6000'],
            ['6230', 'Professional Fees', '6000'],
            ['6240', 'Advertising and Promotion', '6000'],
            ['6250', 'Representation and Entertainment', '6000'],
            ['6260', 'Bad Debts Expense', '6000', ['tags' => 'provision']],
            ['6270', 'Bank Charges', '6000'],
            ['6290', 'Miscellaneous Expense', '6000'],

            ['7000', 'Other Expenses', NULL, ['h' => 1, 't' => 'expense', 's' => 'other', 'cf' => 'operating']],
            ['7100', 'Interest Expense', '7000', ['s' => 'finance', 'tags' => 'interest_expense']],
            ['7200', 'Loss on Disposal of Property and Equipment', '7000', ['tags' => 'disposal_loss']],
            ['7300', 'Foreign Exchange Loss', '7000'],

            ['8000', 'Income Tax Expense', NULL, ['h' => 1, 't' => 'expense', 's' => 'income_tax', 'cf' => 'operating']],
            ['8100', 'Income Tax Expense - Current', '8000'],
            ['8200', 'Income Tax Expense - Deferred', '8000'],
        ]);

        $sole = $form === 'sole_proprietorship';
        return [
            'label'    => self::labels()[$sole ? 'business_sole_proprietorship' : 'business_corporation'],
            'accounts' => self::normalise($rows),
            'defaults' => [
                'acct_retained_earnings' => $sole ? '3110' : '3210',
                'acct_drawings'          => $sole ? '3120' : '',
                'acct_ar_control'        => '1121',
                'acct_ap_control'        => '2111',
                'acct_output_vat'        => '2121',
                'acct_input_vat'         => '1144',
                'acct_ewt_payable'       => '2123',
                'acct_cwt_receivable'    => '1145',
                'acct_default_sales'     => '4110',
                'acct_default_purchases' => '1131',
                'acct_bank_charges'      => '6270',
                'acct_interest_income'   => '4910',
                'acct_gain_on_disposal'  => '4920',
                'acct_loss_on_disposal'  => '7200',
            ],
            'settings' => ['entity_type' => 'business', 'business_form' => $form, 'vat_registered' => TRUE],
        ];
    }

    // =========================================================================
    // CO-OPERATIVE
    // =========================================================================

    private static function _coop()
    {
        $rows = [
            ['10000', 'Assets', NULL, ['h' => 1, 't' => 'asset']],
            ['11000', 'Current Assets', '10000', ['h' => 1, 's' => 'current', 'cf' => 'operating']],
            ['11100', 'Cash and Cash Equivalents', '11000', ['h' => 1, 'cf' => 'cash', 'tags' => 'cash']],
            ['11110', 'Cash on Hand', '11100'],
            ['11120', 'Checks and Other Cash Items', '11100'],
            ['11130', 'Cash in Bank', '11100'],
            ['11150', 'Petty Cash Fund', '11100'],
            ['11160', 'Revolving Fund', '11100'],
            ['11170', 'Change Fund', '11100'],
            ['11200', 'Loans and Receivables', '11000', ['h' => 1, 'tags' => 'receivable']],
            ['11210', 'Loans Receivable - Current', '11200', ['tags' => 'loans,loans_current']],
            ['11220', 'Loans Receivable - Past Due', '11200', ['tags' => 'loans,loans_past_due']],
            ['11230', 'Loans Receivable - Restructured', '11200', ['tags' => 'loans,loans_past_due']],
            ['11240', 'Loans Receivable - Under Litigation', '11200', ['tags' => 'loans,loans_past_due']],
            ['11250', 'Unearned Interests and Discounts', '11200', ['contra' => 1, 'tags' => 'loans']],
            ['11260', 'Allowance for Probable Losses on Loans', '11200', ['contra' => 1, 'tags' => 'loan_allowance']],
            ['11270', 'Accounts Receivable - Trade', '11200', ['ctl' => 'ar', 'tags' => 'trade_receivable']],
            ['11280', 'Allowance for Probable Losses on Accounts Receivable', '11200', ['contra' => 1, 'tags' => 'trade_receivable']],
            ['11290', 'Accrued Interest Receivable', '11200'],
            ['11291', 'Advances to Officers, Employees and Members', '11200'],
            ['11292', 'Other Receivables', '11200'],
            ['11400', 'Inventories', '11000', ['h' => 1, 'tags' => 'inventory']],
            ['11410', 'Merchandise Inventory', '11400'],
            ['11420', 'Allowance for Decline in Value of Inventory', '11400', ['contra' => 1]],
            ['11500', 'Prepayments and Other Current Assets', '11000', ['h' => 1]],
            ['11510', 'Prepaid Expenses', '11500'],
            ['11520', 'Unused Supplies', '11500'],
            ['11530', 'Input Tax', '11500', ['tags' => 'input_vat']],
            ['11540', 'Creditable Withholding Tax', '11500', ['tags' => 'cwt']],
            ['12000', 'Non-Current Assets', '10000', ['h' => 1, 's' => 'non_current', 'cf' => 'investing']],
            ['12100', 'Financial Assets', '12000', ['h' => 1]],
            ['12110', 'Investment in Other Co-operatives', '12100'],
            ['12120', 'Long-Term Time Deposits', '12100'],
            ['12200', 'Property, Plant and Equipment', '12000', ['h' => 1, 'tags' => 'ppe']],
            ['12210', 'Land', '12200'],
            ['12220', 'Building and Improvements', '12200'],
            ['12225', 'Accumulated Depreciation - Building and Improvements', '12200', ['contra' => 1, 'tags' => 'accum_depr']],
            ['12230', 'Furniture, Fixtures and Equipment', '12200'],
            ['12235', 'Accumulated Depreciation - Furniture, Fixtures and Equipment', '12200', ['contra' => 1, 'tags' => 'accum_depr']],
            ['12240', 'Transportation Equipment', '12200'],
            ['12245', 'Accumulated Depreciation - Transportation Equipment', '12200', ['contra' => 1, 'tags' => 'accum_depr']],
            ['12300', 'Other Non-Current Assets', '12000', ['h' => 1]],
            ['12310', 'Other Non-Current Assets', '12300'],

            ['20000', 'Liabilities', NULL, ['h' => 1, 't' => 'liability']],
            ['21000', 'Current Liabilities', '20000', ['h' => 1, 's' => 'current', 'cf' => 'operating']],
            ['21100', 'Deposit Liabilities', '21000', ['h' => 1, 'tags' => 'deposits']],
            ['21110', 'Savings Deposits', '21100'],
            ['21120', 'Time Deposits', '21100'],
            ['21200', 'Trade and Other Payables', '21000', ['h' => 1, 'tags' => 'payable']],
            ['21210', 'Accounts Payable - Trade', '21200', ['ctl' => 'ap', 'tags' => 'trade_payable']],
            ['21220', 'Accrued Expenses', '21200'],
            ['21230', 'Other Payables', '21200'],
            ['21300', 'Statutory and Tax Payables', '21000', ['h' => 1]],
            ['21310', 'Output Tax', '21300', ['tags' => 'output_vat']],
            ['21320', 'Withholding Tax Payable', '21300', ['tags' => 'ewt']],
            ['21330', 'SSS, PhilHealth and Pag-IBIG Premiums Payable', '21300'],
            ['21340', 'Income Tax Payable', '21300'],
            ['21400', 'Due to Union/Federation (CETF)', '21000'],
            ['21500', 'Interest on Share Capital Payable', '21000', ['cf' => 'financing']],
            ['21600', 'Patronage Refund Payable', '21000', ['cf' => 'financing']],
            ['21700', 'Loans Payable - Current', '21000', ['cf' => 'financing', 'tags' => 'debt']],
            ['22000', 'Non-Current Liabilities', '20000', ['h' => 1, 's' => 'non_current', 'cf' => 'financing']],
            ['22100', 'Loans Payable - Long Term', '22000', ['tags' => 'debt']],
            ['22200', 'Retirement Fund Payable', '22000', ['cf' => 'operating']],

            ['30000', "Members' Equity", NULL, ['h' => 1, 't' => 'equity', 'cf' => 'financing']],
            ['31000', 'Share Capital', '30000', ['h' => 1, 's' => 'capital', 'tags' => 'share_capital']],
            ['31100', 'Paid-up Share Capital - Common', '31000'],
            ['31200', 'Paid-up Share Capital - Preferred', '31000'],
            ['31300', 'Deposits for Share Capital Subscription', '31000'],
            ['32000', 'Undivided Net Surplus', '30000', ['h' => 1, 's' => 'retained']],
            ['32100', 'Undivided Net Surplus (Loss)', '32000'],
            ['33000', 'Donations and Grants', '30000', ['s' => 'other']],
            ['34000', 'Statutory Funds', '30000', ['h' => 1, 's' => 'reserve', 'tags' => 'statutory']],
            ['34100', 'Reserve Fund', '34000'],
            ['34200', 'Co-operative Education and Training Fund', '34000'],
            ['34300', 'Community Development Fund', '34000'],
            ['34400', 'Optional Fund', '34000'],

            ['40000', 'Revenues', NULL, ['h' => 1, 't' => 'income', 's' => 'operating', 'cf' => 'operating']],
            ['41000', 'Income from Credit Operations', '40000', ['h' => 1, 'tags' => 'sales']],
            ['41100', 'Interest Income from Loans', '41000', ['tags' => 'loan_interest']],
            ['41200', 'Service Fees', '41000'],
            ['41300', 'Fines, Penalties and Surcharges', '41000'],
            ['41400', 'Filing Fees', '41000'],
            ['42000', 'Income from Merchandising Operations', '40000', ['h' => 1, 'tags' => 'sales']],
            ['42100', 'Sales', '42000'],
            ['42200', 'Sales Returns and Allowances', '42000', ['contra' => 1]],
            ['42300', 'Sales Discounts', '42000', ['contra' => 1]],
            ['43000', 'Income from Service Operations', '40000', ['h' => 1, 'tags' => 'sales']],
            ['43100', 'Service Income', '43000'],
            ['44000', 'Other Income', '40000', ['h' => 1, 's' => 'other']],
            ['44100', 'Interest Income from Deposits and Investments', '44000', ['tags' => 'interest_income']],
            ['44200', 'Membership Fees', '44000'],
            ['44300', 'Miscellaneous Income', '44000'],
            ['44400', 'Gain on Disposal of Property and Equipment', '44000', ['tags' => 'disposal_gain']],

            ['50000', 'Costs and Expenses', NULL, ['h' => 1, 't' => 'expense', 's' => 'operating', 'cf' => 'operating']],
            ['51000', 'Cost of Goods Sold', '50000', ['h' => 1, 's' => 'cost_of_sales', 'tags' => 'cogs']],
            ['51100', 'Cost of Goods Sold', '51000'],
            ['52000', 'Financing Costs', '50000', ['h' => 1, 's' => 'finance', 'tags' => 'interest_expense']],
            ['52100', 'Interest Expense on Deposits', '52000'],
            ['52200', 'Interest Expense on Borrowings', '52000'],
            ['52300', 'Other Financing Charges', '52000'],
            ['53000', 'Administrative Costs', '50000', ['h' => 1, 's' => 'operating']],
            ['53100', 'Salaries and Wages', '53000'],
            ['53110', "Employees' Benefits", '53000'],
            ['53120', 'SSS, PhilHealth and Pag-IBIG Contributions', '53000'],
            ['53130', "Officers' Honorarium and Allowances", '53000'],
            ['53140', 'Meetings and Conferences', '53000'],
            ['53150', 'Travel and Transportation', '53000'],
            ['53160', 'Office Supplies', '53000'],
            ['53170', 'Power, Light and Water', '53000'],
            ['53180', 'Communication', '53000'],
            ['53190', 'Rentals', '53000'],
            ['53200', 'Insurance', '53000'],
            ['53210', 'Repairs and Maintenance', '53000'],
            ['53220', 'Depreciation', '53000', ['tags' => 'depreciation']],
            ['53230', 'Provision for Probable Losses', '53000', ['tags' => 'provision']],
            ['53240', 'Audit Fees', '53000'],
            ['53250', 'Professional Fees', '53000'],
            ['53260', 'Taxes, Licenses and Fees', '53000'],
            ['53270', 'General Assembly Expenses', '53000'],
            ['53280', "Members' Benefits", '53000'],
            ['53290', 'Affiliation Fees', '53000'],
            ['53300', 'Social and Community Service', '53000'],
            ['53310', 'Bank Charges', '53000'],
            ['53390', 'Miscellaneous Expenses', '53000'],
            ['54000', 'Operating Costs', '50000', ['h' => 1, 's' => 'operating']],
            ['54100', 'Store Supplies', '54000'],
            ['54200', 'Delivery Expenses', '54000'],
            ['54300', 'Spoilage and Shrinkage', '54000'],
            ['55000', 'Other Expenses', '50000', ['h' => 1, 's' => 'other']],
            ['55100', 'Loss on Disposal of Property and Equipment', '55000', ['tags' => 'disposal_loss']],
        ];

        return [
            'label'    => self::labels()['cooperative'],
            'accounts' => self::normalise($rows),
            'defaults' => [
                'acct_retained_earnings'      => '32100',
                'acct_drawings'               => '',
                'acct_ar_control'             => '11270',
                'acct_ap_control'             => '21210',
                'acct_output_vat'             => '21310',
                'acct_input_vat'              => '11530',
                'acct_ewt_payable'            => '21320',
                'acct_cwt_receivable'         => '11540',
                'acct_default_sales'          => '42100',
                'acct_default_purchases'      => '11410',
                'acct_bank_charges'           => '53310',
                'acct_interest_income'        => '44100',
                'acct_gain_on_disposal'       => '44400',
                'acct_loss_on_disposal'       => '55100',
                'acct_coop_reserve_fund'      => '34100',
                'acct_coop_cetf'              => '34200',
                'acct_coop_cdf'               => '34300',
                'acct_coop_optional_fund'     => '34400',
                'acct_coop_isc_payable'       => '21500',
                'acct_coop_patronage_payable' => '21600',
            ],
            /* A co-op dealing only with its members is usually VAT-exempt; the
               accountant switches VAT on in Settings if it is not. */
            'settings' => ['entity_type' => 'cooperative', 'business_form' => 'corporation', 'vat_registered' => FALSE],
        ];
    }
}
