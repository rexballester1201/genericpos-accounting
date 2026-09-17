---
title: Administration
summary: Users and roles, every setting and what it changes, importing from spreadsheets, the audit log, the scheduled job, and keeping the books safe.
---

# Administration

Everything in this chapter is an administrator's work.

## Users and roles
**Where:** Administration › Users.

| Role | What it may do |
|---|---|
| **Viewer** | read every report, ledger and analysis; change nothing |
| **Bookkeeper** | prepare drafts — entries, invoices, bills, receipts, payments — and match the bank |
| **Accountant** | everything a bookkeeper may, and approve and post, reverse, close and reopen months, run depreciation, keep the budgets |
| **Administrator** | everything, plus the chart of accounts, departments, fiscal years, the year-end, users, settings and imports |

**To add someone:** select **Add user**, fill in the name, email and role, and select **Create**. The password shows once — give it to them and have them change it under **My account**.

**To change what someone may do:** open them and change the role. It takes effect on their next request; they do not need to sign in again.

**To stop someone using the system:** open them and set the state to *Disabled*. Their sessions end at once. People are never deleted — the audit log points at them for years.

> **Tip:** Keep at least two administrators. If the only one loses their password and their email, there is no way back in except by the installer on the server.

## Settings
**Where:** Administration › Settings.

Each setting saves on its own, and every change is in the audit log with who made it.

| Group | What it holds |
|---|---|
| **Company** | name, registered name, business style, TIN, CDA registration number, address, email, phone. These print on every report. The kind of organisation, the business form and the fiscal-year start month were chosen at setup and cannot be changed. |
| **Branding** | logo, brand and accent colour, light or dark, corner roundness, typeface. |
| **Currency** | code, symbol, where the symbol goes, the separators, the locale and the time zone. Decimals are fixed at installation. |
| **Tax** | whether the company is VAT-registered, the tax name and rate, whether typed amounts include VAT, and the label for the tax ID. |
| **The ledger** | whether a second person approves every entry, whether preparers may approve their own, the digits in journal numbers, the most lines an entry may have, and the prefix of each book. |
| **Account defaults** | which account is the closing account, the owner's drawings, the receivables and payables controls, output and input VAT, withholding tax, creditable withholding tax, the default sales and purchases accounts, bank charges, interest income, and gain and loss on disposal. |
| **Documents** | the prefixes and digits of invoice, credit note, bill, debit note, receipt and payment numbers, the default customer and supplier terms, and the aging buckets. |
| **Banking and fixed assets** | how many days apart a bank line and a book line may still be matched, when depreciation starts, and the asset number prefix. |
| **Co-operative** | the allocation percentages and the accounts for the statutory funds, and the marks the indicators are measured against. |
| **Printed reports** | how many lines go on a printed page of the books, whether "printed by" is shown, the note under report titles, and the names and titles over the signature lines. |
| **Notifications** | whether email copies of notifications are sent. |

> **Note:** A prefix can never be blank, and two books — or two kinds of document — can never share one. Numbers would run into each other.

## Imports
**Where:** Administration › Imports.

Four kinds of file can be brought in:

| Kind | What it does |
|---|---|
| **Chart of accounts** | adds accounts; codes already there are left alone |
| **Customers and suppliers** | adds contacts; codes already there are left alone |
| **Opening balances** | fills the opening-balance draft, for you to check and post |
| **Journal entries** | creates draft entries, one per entry key, for someone to check and submit |

**How to import:**

1. Choose what you are importing.
2. Select **Download a template** to get the columns, or use your own file with a heading row.
3. Select **Choose a CSV file**, or paste the rows.
4. Say which column holds what — the obvious ones match themselves.
5. Select **Check the file**. Every row comes back marked *Ready*, *already here*, or with what is wrong with it.
6. Fix anything red, check again, then select **Import**.

**Result:** the whole file goes in as one change. If any row fails, nothing is written — there is no such thing as a half-imported chart.

> **Note:** Imports never post to the ledger. Opening balances land in the draft on the Opening balances screen; entries land as drafts in Journal entries. Somebody still checks and posts them.

## The audit log
**Where:** Administration › Audit log.

Every change is recorded: who, what, when, from which address, and the details. Entries posted, reversed, cancelled; months closed and reopened; years closed; settings changed; users added; files attached and removed; imports. It cannot be edited or deleted from anywhere in the system.

Work the scheduled job did shows as **System**.

Search by action, by person or by what was touched. Every record links to the entry or document it belongs to.

## The scheduled job

Once installed, the server should run this every fifteen minutes:

```bash
php /path/to/accounting/index.php tools cron
```

It does two things: it turns recurring saved entries that are due into drafts for their owners ([Journal entries](06-journal-entries.md#saved-entries)), and it clears out old rate-limit rows, expired sign-in tokens, spent password-reset links and notifications that were read long ago. The audit log is never pruned.

On Windows, a Task Scheduler task running the same command does the job.

## Setting up from a command line

The setup screen is the ordinary way in ([First-time setup](02-first-time-setup.md)). A server with no browser to hand can do the same three things by command:

```bash
php index.php tools seed_chart business_corporation   # or business_sole_proprietorship, cooperative
php index.php tools create_year 2026-01-01            # the first day of the first month of the books
php index.php tools create_admin owner%40example.test # prints a password once
```

The chart is seeded only into an empty ledger, never merged into one that already has accounts. The first fiscal year fixes the month the books turn on, and Settings will not change that afterwards. Percent-encode the `@` in an address, as above.

Later years need no date: `tools create_year` on its own takes the day after the last one ends.

## Keeping the books safe

- **Back up the database every day**, and keep a copy off the server. The books live there; everything else can be reinstalled.
- **Back up `uploads/`** as well: the attachments are files, not database rows.
- **Keep `application/config/secrets.php` out of version control.** It holds the database password and the key that signs sessions.
- **Lock months** once their figures are final, so nothing can change under a filed return.
- **Run the integrity check** before an audit and after any unusual work.
- **Keep the roles honest.** The point of maker-checker is that the person who prepares an entry is not the one who approves it.

## Common problems

**"Only an administrator can do that."** The role is not high enough. The table above says which is.

**A setting will not save.** The message says why — a prefix that is already in use, an account of the wrong type, a percentage out of range.

**An import says "Fix the 3 rows marked below first."** Nothing was written. Correct those rows in the file and check it again.

**The scheduled job is not making the recurring drafts.** Run `php index.php tools cron` by hand on the server and read what it prints. Usually the month the draft would be dated in is closed, and the owner has a notification saying so.
