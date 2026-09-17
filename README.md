# GenericPOS Accounting

A double-entry accounting system for a Philippine business or co-operative:
the journals and ledgers, receivables and payables with their subsidiary
ledgers, bank reconciliation, fixed assets, budgets, and the financial
statements and BIR-style books that come out of them.

The API is CodeIgniter 3 (PHP 8.2) and answers JSON. The front end is a
dependency-free single-page app — no build step — that installs as a PWA and
works from cache when the connection drops.

It grew out of the GenericPOS shop codebase (baseline commit `3ab52a9`); the
shop, the point of sale, the USDT rail and the Android build were removed, and
what remains of that lineage is the account system, the mailer, the rate
limiting and the offline shell.

## The one rule

**Nothing reaches the ledger except through a posted, balanced journal entry.**
An invoice, a receipt, a depreciation run and the year-end closing all write
the same kind of entry through `Journal_model::post_system()`, each numbered
gap-free per book per fiscal year, each dated in an open month of an open
year, each with its audit row written in the same transaction. A posted entry
is never edited or deleted — it is reversed, and both stay on record.

## What it does

**The ledger.** Chart of accounts (business or CDA co-operative templates),
fiscal years and monthly periods (open → closed ⇄ open → locked), journal
entries with maker-checker (draft → submitted → posted, or rejected),
reversals, saved and recurring entries, opening balances, attachments and
printable vouchers.

**Sales and purchases.** Customers and suppliers, invoices and bills with VAT
(inclusive or exclusive, exempt and zero-rated), credit and debit notes,
receipts and payments with creditable and expanded withholding tax,
allocations, aging, statements of account and subsidiary ledgers.

**Banking.** Bank accounts, statements entered or imported from CSV, matching
(one bank line to many book lines) with auto-match, charges and interest
recorded straight from a statement line, and the reconciliation report.

**Fixed assets.** Categories, the register, straight-line and declining-balance
depreciation run month by month, disposals with gain or loss, and the lapsing
schedule tied to the ledger.

**Budgets and departments.** Departments on lines and budgets, a month-by-month
budget grid per department, approval, CSV in and out, budget vs actual, and
income by department.

**Reports.** Balance sheet, income statement, changes in equity and cash flows
(indirect), with comparative and month-by-month columns, common-size
percentages and a department filter; trial balance (unadjusted, adjusted,
post-closing), the ten-column worksheet, the general ledger, the books of
accounts (as entries, or columnar and paged with totals brought forward),
twenty-one ratios with their formulas, a co-operative's PESOS-style indicators
with editable benchmarks, and an integrity check of sixteen ties.

**Year-end.** The closing entry, a co-operative's net-surplus allocation to the
statutory funds, and reopening a year.

**Administration.** Users and roles (viewer, bookkeeper, accountant,
administrator), settings, CSV imports (chart, contacts, opening balances,
entries), an append-only audit log, and a scheduled job.

Every report prints A4 with the company's letterhead and signature lines, and
downloads as CSV.

## Running it

Requirements: PHP 8.2 with `mysqli`, `mbstring`, `fileinfo` and `gd`;
MariaDB 10.2.1+ or MySQL 8.0.16+ (the schema uses CHECK constraints); Apache
with `mod_rewrite`; Node 18+ only to build the guide or run the tests.

```bash
# 1. the database
mysql -u root -e "CREATE DATABASE accounting CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root accounting < SCHEMA.sql

# 2. the secrets
cp application/config/secrets.example.php application/config/secrets.php
#    fill in the database user and password, a long GP_JWT_SECRET,
#    and a GP_SETUP_KEY for the first run

# 3. open the site and follow the setup screen
```

The setup screen answers only while there is no user; after the first
administrator exists it refuses everybody. See
[docs/guide/02-first-time-setup.md](docs/guide/02-first-time-setup.md).

### Installing from a shell instead

A server with no browser to hand does the same three things by command:

```bash
php index.php tools seed_chart business_corporation   # or business_sole_proprietorship, cooperative
php index.php tools create_year 2026-01-01            # the first day of the first month of the books
php index.php tools create_admin owner%40example.test # prints a password once
```

The first fiscal year fixes the month the books turn on, which Settings will
not change afterwards. Percent-encode the `@` in an address: CodeIgniter reads
these arguments as a URI and refuses the character.

### The scheduled job

Every fifteen minutes:

```bash
php /path/to/accounting/index.php tools cron
```

It drafts the recurring saved entries that are due and clears out old
rate-limit rows, expired tokens, spent reset links and long-read
notifications. The audit log is never pruned.

### The command line

```bash
php index.php tools                                   what there is
php index.php tools create_admin <email> [password]   create or promote an administrator
php index.php tools create_user <email> <role> [password]
php index.php tools seed_chart <kind>                 the starting chart of accounts
php index.php tools create_year [YYYY-MM-01]          the next fiscal year and its twelve months
php index.php tools cache                             rebuild the sign-in page's branding cache
php index.php tools cron                              the scheduled job
php index.php tools seed_demo                         a demo company (development only, empty database)
php index.php tools trial_balance [date] [kind]       print a trial balance
php index.php maildiag <address>                      check the mail settings
```

## The user's guide

Sixteen chapters, step by step, in [docs/guide/](docs/guide/). They are also
the Help screen inside the app: after changing them, rebuild the file the app
reads.

```bash
node docs/build-guide.mjs        # docs/guide/*.md → help/guide.json
```

## The tests

End-to-end suites against a throwaway database: see
[tests/README.md](tests/README.md). They cover the ledger, the statements, the
year-end, receivables and payables, fixed assets, attachments, imports, the
security fixes and the static module check.

## How the code is laid out

```
application/
  controllers/      one per resource; guards, then the model, then json_response
  models/           the rules: Journal_model is the only way into the ledger
  libraries/        Statement_lib, Analysis_lib, Books_lib, Integrity_lib, Depreciation_lib…
  helpers/          api_helper (guards, envelope, audit), shop_helper (money, sequences), report_helper
  config/routes/    one route file per module, included by config/routes.php
  core/             the JSON error handlers
css/app.css         one stylesheet, tokens first
js/                 one ES module per screen: mount(root, ctx)
pages/              one HTML fragment per screen, named by its module
docs/guide/         the user's guide (source of truth)
tests/              the end-to-end suites
SCHEMA.sql          the whole database, with the reasoning in its comments
PLAN.md             what was decided and why
```

## Conventions

- **Money is integer centavos** everywhere — in PHP, in the database, in JSON.
  Rates are basis points. No floats touch money.
- **Dates are `YYYY-MM-DD`**; timestamps are UTC and shown in the company's
  time zone.
- **Every API answer is `{status, data, message}`**; `message` is written for
  the person reading it.
- **Every write is audited** in the same transaction as the change.
- Work the scheduled job or the command line did is recorded as **System**.
