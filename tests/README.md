# The test suites

End-to-end checks that run against a **throwaway database**, never the real
one. Each suite signs in over the API, does the work a person would do, and
checks both the answers and what landed in the tables.

Node 18 or newer, and PHP on the path. No dependencies to install.

## Setting one up

```bash
# 1. a database whose name says "scratch" — some suites refuse anything else
mysql -u root -e "DROP DATABASE IF EXISTS acc_scratch; CREATE DATABASE acc_scratch CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root acc_scratch < SCHEMA.sql

# 2. the demo company: a year of books, four sign-ins, one per role
CI_ENV=development GP_DB_NAME=acc_scratch php index.php tools seed_demo
mysql -u root acc_scratch -e "UPDATE gp_settings SET value='0' WHERE k='notify_email_enabled'"

# 3. serve it (leave CI_ENV unset here)
GP_DB_NAME=acc_scratch php -S 127.0.0.1:8781 -t . tests/router.php
```

Then run the suites against that address. Afterwards:

```bash
mysql -u root -e "DROP DATABASE acc_scratch"
php index.php tools cache      # seeding rewrote the shell's branding cache
```

> Seeding, setup and saving settings all rewrite `application/cache/store_public.json`.
> Run `php index.php tools cache` against the real database when you are done, or
> the sign-in page will greet you as the demo company.

## The suites

| Suite | What it covers |
|---|---|
| `api-test.mjs <base> ledger` | the ledger API: drafts, submit, approve, post, reject, cancel, reverse, periods, roles |
| `api-test.mjs <base> setup <key>` | the setup wizard on an empty schema |
| `platform-test.mjs <base> <db>` | JSON errors, refresh-token reuse, the email-change password, the system actor |
| `reports-test.mjs <base>` | the four statements, the trial balance, the general ledger, the books, the analysis, CSV |
| `reports2-test.mjs <base> <db>` | the worksheet, the columnar books, the integrity check |
| `ye-test.mjs business <base> <db>` | year-end closing, reopening, the statements before and after |
| `ye-test.mjs coop <base> <db> <key>` | a co-operative: opening balances, closing, the net-surplus allocation |
| `tpl-test.mjs <base> <db>` | saved and recurring entries, and `tools cron` |
| `att-test.mjs <base> <db>` | attachments (including Apache refusing them by address) and vouchers |
| `import-test.mjs <base> <db>` | the CSV imports: chart, contacts, opening balances, entries |
| `ar-test.mjs <base> <db>` | receivables and payables end to end, and their reports |
| `fa-test.mjs <base> <db>` | fixed assets: the register, depreciation runs, disposals, the lapsing schedule |
| `fa-lib-test.php` | the depreciation maths on its own (`php tests/fa-lib-test.php`) |
| `bank-smoke.mjs <base>`, `budget-smoke.mjs <base>` | a quick pass over the banking and budget endpoints |
| `cli-test.mjs <db> [php] [mysql]` | installing a ledger from a shell: `seed_chart`, `create_year`, `create_admin`. It loads the schema itself and needs no server |
| `coop-test.mjs <base> <key>` | every report on an empty co-operative |
| `modcheck.mjs [root]` | static check: every module parses, every import resolves, every page and icon exists |

`<base>` is like `http://127.0.0.1:8781/api/v1`, `<db>` the scratch database's
name, `<key>` the `GP_SETUP_KEY` the server was started with.

## The demo sign-ins

Created by `tools seed_demo`, and only ever in a demo database:

| Role | Email | Password |
|---|---|---|
| Administrator | admin@example.test | DemoAdmin#2026 |
| Accountant | accountant@example.test | DemoAcct#2026 |
| Bookkeeper | bookkeeper@example.test | DemoBook#2026 |
| Viewer | viewer@example.test | DemoView#2026 |

## Rules

- Never point a suite at the real database. Several refuse a database whose
  name does not contain "scratch"; the rest trust you.
- The suites write, cancel, close months and reverse entries. Reseed between
  full runs if you want the demo figures back.
- `php -S` serves files that exist, so the deny rules in `.htaccess` are not in
  force there. The one check that depends on them (`att-test.mjs`) asks Apache
  on localhost instead, and says so when Apache is not answering.
