# GenericPOS Accounting — build plan

A standalone **general ledger** with financial analysis and printable reports.
It is cloned from GenericPOS (commit `3ab52a9`) and stripped of the shop, the
POS, the USDT rail and the Android app. This file is the working anchor for the
build: decisions, design and the progress checklist. The root `.htaccess`
denies it over HTTP (`.md`).

---

## 1. Decisions (owner, 2026-09-13)

| Topic | Decision |
|---|---|
| Shape | **Standalone ledger.** Keeps GenericPOS's sign-in, roles, settings registry, design system, printing and audit log. Entries are keyed in or imported from CSV. |
| Charts | **Business and co-operative, chosen at setup.** Business: PFRS for SMEs-style statements and BIR books of accounts. Co-op: CDA-style chart, statutory funds, net-surplus allocation, co-op indicators. |
| v1 modules | Core ledger, **AR/AP** subsidiary ledgers, **bank reconciliation**, **budgets & departments**, **fixed assets** |
| Companies | **One company per install** (a second company is a second install and database) |
| Reference | `cempc` (CI3). Borrowed: void while the period is open and reverse once it closes; control accounts; signatories; print CSS. Avoided: postable closed periods, an income statement that nets to zero after closing, a non-atomic closing, MAX+1 numbering, float money, editable opening snapshots, no audit trail. |
| My defaults | Money in BIGINT centavos · maker-checker on, with self-approval off (a setting lets a one-person office post its own entries) · numbers assigned at posting, gap-free per book per fiscal year · browser printing (A4, page numbers, signatories) plus CSV · roles viewer / bookkeeper / accountant / admin |

## 2. Invariants — the rules everything else obeys

1. **Double entry.** A journal posts only when its debits equal its credits to the centavo. PHP checks it, and the posting statement checks it again in SQL inside the same transaction.
2. **Posted is permanent.** A posted journal is never edited or deleted; it is corrected by a reversal (a new posted journal linked both ways). Drafts can be edited or cancelled.
3. **Periods are enforced.** The entry date must fall in an OPEN period of an OPEN fiscal year, or the entry is refused. There is no "no period set up, post anyway".
4. **Numbers.** A journal number is assigned when the journal posts: per book, per fiscal year, gap-free, taken under a row lock in the posting transaction, and protected by a unique key.
5. **Balances are derived.** Every balance comes from posted lines (the `gp_ledger` view), never from stored snapshots. Opening balances of later years follow from history, so back-posting can never leave a year out of step.
6. **Closing entries are fenced.** An income statement for any range excludes closing entries. The trial balance has three kinds: unadjusted (without adjusting or closing entries), adjusted (without closing entries) and post-closing.
7. **Maker-checker.** The person who prepares an entry cannot approve it (unless `allow_self_approval`). Every state change is written to the audit log with who, when and why.
8. **Subsidiary ledgers tie by construction.** Every line on an AR or AP control account carries its customer or supplier, so the schedule of balances always equals the control account.
9. **Accounts.** Only postable (non-header) accounts take lines, and headers roll up. An account's type and normal side are fixed once it has postings. A used account is deactivated, never deleted.
10. **Rounding** uses largest-remainder allocation (the Pricing_lib technique), so a split always sums back to its whole.

## 3. Architecture

- **API.** CodeIgniter 3.1.9, JSON under `/api/v1`, envelope `{status, data, message, code}`, JWT with refresh tokens, `rate_limit()`, a settings registry with admin overrides, and an audit log. Every controller method guards itself.
- **Shell.** `index.html` plus the router. Screens are `pages/*.html` fragments with `js/<name>.js` modules. The whole app uses the back-office chrome (sidebar and top bar); there is no storefront.
- **Ledger engine** lives in the models:
  - `Account_model` — the chart and its rules.
  - `Period_model` — fiscal years and periods.
  - `Journal_model` — drafts, validation, posting, reversal and numbering.
  - `Ledger_model` — balances, activity and running balances.
  - `Statement_lib` — statement layouts for business and co-op.
  - `Analysis_lib` — ratios.

  The modules post through `Journal_model::post_system()`: invoices, bills, receipts, payments, depreciation, disposals, bank adjustments, closing and allocation.
- **Printing.** Reports render into a print container:
  - `@page` A4, portrait or landscape;
  - a letterhead (company, address, TIN), the report title and period;
  - "Printed by / on" and "Page x of y" in the page margin boxes;
  - signature blocks from settings;
  - journal books printed in fixed-size pages with page totals and "brought forward".

  Every report also exports CSV (with a BOM and formula-safe cells).

## 4. Data model (`SCHEMA.sql`, prefix `gp_`)

**Kept from GenericPOS:** `users` (roles changed), `login_attempts`, `refresh_tokens`, `password_resets`, `rate_limits`, `app_state`, `admin_audit_log`, `settings`, `notifications`, `counters`.

**Chart and periods**
- `accounts`
  - identity: `code` (unique), `name`, `parent_id`, `is_header`
  - classification:
    - `type`: asset / liability / equity / income / expense
    - `subtype`: current, non_current, capital, retained, reserve, drawing, operating, cost_of_sales, finance, other, income_tax
    - `is_contra`, and `normal_side` (derived)
  - reporting: `cash_flow` (operating / investing / financing / cash), `tags` (analysis vocabulary)
  - rules: `control` (ar / ap), `requires_department`, `is_active`, `sort_order`
- `fiscal_years` (name, start, end, status, closed by and when). The start month is set at setup.
- `periods` (fiscal year, number, start, end, status open / closed / locked, closed by and when)

**Journals**
- `journals`
  - `book`: general, cash_receipts, cash_disbursements, sales, purchases, adjusting, closing, opening
  - identity: `journal_no` (NULL until posted, unique), `entry_date`, fiscal year, period
  - text: `reference`, `party_name`, `description`, `total_cents`
  - `status`: draft, submitted, posted, rejected, cancelled
  - links: `source` + `source_id`, `reversal_of_id`, `reversed_by_id`
  - who and when: created, submitted, approved, rejected with a reason, cancelled
- `journal_lines`
  - `account_id`, `debit_cents`, `credit_cents`, with a CHECK that exactly one side is positive
  - `memo`, `department_id`, `contact_id`
- `ledger` — a VIEW of posted lines joined to their journal header
- `journal_templates` (saved and recurring entries: lines in JSON, day of month, next date)
- `attachments` (scanned ORs and invoices, on journals and documents)

**AR/AP**
- `contacts` (customer / supplier flags, code, name, TIN, address, terms, credit limit, default accounts)
- `documents` (invoice, credit_note, bill, debit_note; number, dates, due date, VAT and totals, status, journal) and `document_lines` (account, description, quantity, price, VAT mode, department)
- `settlements` (receipt or payment, contact, cash or bank account, amount, OR or check number, withholding, journal) and `allocations` (settlement or credit document → document, amount)

**Bank**
- `bank_accounts` (GL cash account, bank, masked account number)
- `bank_statements` (period end, opening and closing balance, status)
- `bank_lines` (date, description, reference, signed amount)
- `bank_matches` (bank line ↔ journal line; many-to-one allowed)

**Fixed assets**
- `asset_categories` (asset, accumulated-depreciation and expense accounts; method; life; residual %)
- `assets` (number, name, category, acquired, cost, residual, life, method, department, location, status, disposal)
- `depreciation_runs` (period, journal) and `depreciation_entries` (asset, amount, accumulated after), with a unique key on asset + period

**Budgets and departments**
- `departments` (code, name, parent, active)
- `budgets` (fiscal year, name, status) and `budget_lines` (account, department, period number, amount)

**Co-op**
- `surplus_allocations` (fiscal year, net surplus, reserve, CETF, CDF, optional fund, interest on share capital, patronage refund, journal)

## 5. Books, sources and numbering

| Book | Prefix | Used for |
|---|---|---|
| General journal | GJ | manual entries, depreciation, disposals |
| Cash receipts | CR | receipts, deposits, bank credits |
| Cash disbursements | CD | payments, cheques, bank charges |
| Sales journal | SJ | posted customer invoices and credit notes |
| Purchase journal | PJ | posted supplier bills and debit notes |
| Adjusting | AJ | period-end adjustments |
| Closing | CJ | year-end closing and co-op net-surplus allocation |
| Opening | OB | balances at go-live |

A number looks like `CD-2026-00042`: the prefix (configurable), the fiscal year, then a gap-free sequence. Documents have their own sequences, for example `INV-000123`. OR and cheque numbers are typed, because they come from pre-printed forms.

## 6. Reports (all printable, all CSV)

- **Masters:** chart of accounts, contacts, departments, asset register.
- **Vouchers:** journal voucher, cash receipt, check voucher, each with signatories.
- **Books:** general journal, cash receipts, cash disbursements, sales journal, purchase journal. These are columnar, paged, with page totals.
- **Ledgers:**
  - general ledger per account, with an opening balance, running balance and drill-down;
  - AR/AP subsidiary ledgers;
  - statements of account.
- **Trial balance** (unadjusted / adjusted / post-closing, as of a date) and the 10-column **worksheet**.
- **Statements, business:** financial position (classified), comprehensive income (multi-step), changes in equity, cash flows (indirect, or direct).
- **Statements, co-op:** financial condition, operations, changes in equity, cash flows, net-surplus allocation.
- **Statement options:**
  - comparative columns (prior period or prior year) and monthly columns;
  - detail level (header totals or every account);
  - a department filter.
- **Receivables and payables:** aging (30 / 60 / 90 / over 90), schedules tied to their control accounts.
- **Banking:** bank reconciliation (book vs bank, deposits in transit, outstanding cheques).
- **Fixed assets:** depreciation schedule and lapsing schedule.
- **Budgets:** budget vs actual (period and year to date, variance and %), department income statements.
- **Integrity report:** unbalanced or orphaned entries, entries outside periods, control-account ties, bank and asset cross-checks.

## 7. Financial analysis

- **Liquidity:** current, quick and cash ratios; working capital.
- **Solvency:** debt, debt-to-equity and equity ratios; times interest earned.
- **Profitability:** gross, operating and net margins; ROA; ROE.
- **Efficiency:** receivable, payable and inventory turnover; DSO, DPO and DIO; cash conversion cycle; asset turnover.
- **Co-op indicators:** portfolio at risk, allowance cover, share-capital and statutory-fund structure, operating cost ratios. These are PESOS-style indicators with editable benchmarks, and every indicator shows its formula.
- **Common-size and trend analysis:** vertical percentages, and year-on-year or month-on-month change.
- **Dashboard:** cash position, receivables, payables, revenue and net income to date, key ratios, the monthly trend, and queues (approvals, unreconciled accounts, periods to close).

## 8. Roles

| | viewer | bookkeeper | accountant | admin |
|---|---|---|---|---|
| Reports, ledgers, analysis | ✓ | ✓ | ✓ | ✓ |
| Prepare drafts, invoices, bills, receipts, payments, bank matching | | ✓ | ✓ | ✓ |
| Approve and post, reverse, close months, depreciation runs, budgets | | | ✓ | ✓ |
| Chart of accounts, fiscal years, year-end close, users, settings, audit | | | | ✓ |

## 9. Phases / checklist

- [ ] 0. Strip the shop, POS, USDT, Android and archive; rebrand; new roles; company config; admin-only chrome
- [ ] A. Core ledger: schema, setup wizard with chart templates, chart of accounts, fiscal years and periods, opening balances, journals (draft → submit → approve/post, reject, reverse, cancel), numbering, templates, audit
- [ ] B. Ledger reports and printing: trial balance, worksheet, general ledger, books, vouchers, drill-down, print framework, CSV
- [ ] C. Statements and year-end: business and co-op statements, comparative and monthly columns, common-size and trend, year-end closing, co-op allocation, reopening a year
- [ ] D. Receivables and payables: contacts, invoices, bills, notes, receipts and payments with allocations and withholding, subsidiary ledgers, statements, aging
- [ ] E. Banking: bank accounts, statement import, matching, adjusting entries, reconciliation report
- [ ] F. Fixed assets: categories, register, depreciation runs, disposals, lapsing schedule
- [ ] G. Budgets and departments: departments on lines, budgets, budget vs actual, department income statements
- [ ] H. Analysis and finish: ratios, dashboard, CSV imports, integrity report, docs, QA

## 10. Local environment

- `http://localhost/dashboard/accounting/`, database `accounting`, own `secrets.php` (never shared with GenericPOS)
- Browser storage is namespaced (`acc_` keys, IndexedDB `accounting`, service-worker caches `acc-`), because both apps share the `localhost` origin
- Never touch the `genericpos` database or the GenericPOS folder from this project
- Demo data: `CI_ENV=development php index.php tools seed_demo`, into an EMPTY database (load SCHEMA.sql first). It builds Demo Trading Corporation from 1 January to yesterday, with one demo sign-in per role (`application/libraries/Demo_seed.php`). Runs in one transaction: a failure leaves the database empty
- `php index.php tools trial_balance [YYYY-MM-DD] [unadjusted|adjusted|post_closing]` prints the trial balance until the report screens exist
