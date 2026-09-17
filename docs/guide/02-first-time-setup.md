---
title: First-time setup
summary: Set the company up, choose its chart of accounts, open the first fiscal year, add the people who will use it, and bring in the balances the books start from.
---

# First-time setup

Setting up takes an hour, and once it is done the books are ready for the first entry. Do it in this order; each step depends on the one before it.

## Before you start
Have these at hand:

- The company's registered name, address and TIN (a co-operative also needs its CDA registration number).
- The month the fiscal year starts — January for most companies.
- The trial balance on the day you begin here: the balances of every account, and the open invoices and bills, customer by customer and supplier by supplier.
- The email addresses of the people who will use the system.

## 1. Create the company
**Who:** whoever installs the system, once. After that the setup screen is closed for good.

1. Open the address of the system in a browser. Because nothing is set up yet, the setup screen appears.
2. Type the **Setup key** the installer put in `application/config/secrets.php`.
3. Fill in the **Company name**, and the **Registered name** if the papers say something longer.
4. Choose the **Kind of organisation**: *Business* or *Co-operative*. This decides the chart of accounts and the names of the statements, and it cannot be changed afterwards.
5. Choose the **Business form** for a business: *Corporation* or *Sole proprietorship*. A sole proprietorship closes the owner's drawings into capital at year end.
6. Choose the month the **fiscal year starts**, and the year of the first one.
7. Fill in the **currency** (PHP and ₱ in the Philippines).
8. Fill in the first administrator: **full name**, **email** and a **password** of at least ten characters.
9. Select **Set up the company**.

**Result:** the company exists, the chart of accounts is installed, the first fiscal year is open with twelve months, and you are signed in as the administrator.

> **Note:** The setup screen answers only while there is no user in the system. Once the administrator exists it refuses everybody, so it cannot be used to take the company over later.

## 2. Check the company settings
**Who:** Administrator. **Where:** Administration › Settings.

Go through the groups on the left:

- **Company** — name, registered name, business style, TIN, address, email, phone. These print at the top of every report.
- **Branding** — the logo, the brand colour, light or dark, the typeface.
- **Currency** — the code, the symbol, the separators, the locale and the time zone (Asia/Manila). Times are stored in UTC and shown in this zone.
- **Tax** — whether the company is **VAT-registered**, the **VAT rate** (12 in the Philippines), and whether typed amounts **include VAT** by default.
- **The ledger** — whether a second person approves every entry, the digits in journal numbers, and the prefix of each book.
- **Account defaults** — which account is the receivables control, the payables control, output and input VAT, withholding tax, the default sales and purchases accounts, bank charges, interest income, gain and loss on disposal, and the account the year-end closing posts to. The chart template fills these in; check them.
- **Documents** — the prefixes and digits of invoice, bill, receipt and payment numbers, the default terms, and the aging buckets.
- **Banking and fixed assets** — how many days apart a bank line and a book line may still be matched, when depreciation starts, and the asset number prefix.
- **Printed reports** — the note under report titles ("Unaudited", for example) and the names and titles that print over the signature lines.

Each setting saves on its own as you change it, and the audit log records who changed what.

## 3. Add the people
**Who:** Administrator. **Where:** Administration › Users.

1. Select **Add user**.
2. Fill in the **name** and **email**, and choose the **role** — viewer, bookkeeper, accountant or administrator ([Welcome](01-welcome.md) has the table of what each may do).
3. Select **Create**. Their password is shown once; give it to them and have them change it on their account screen.

> **Tip:** Give people the smallest role that lets them do their work. A bookkeeper who prepares invoices does not need to post them — that separation is what maker-checker means, and it is the point of the approval step.

## 4. Check the chart of accounts
**Who:** Administrator. **Where:** Ledger › Chart of accounts.

The template you chose at setup installs a full chart. Go through it and:

- add the accounts the company needs that are not there,
- deactivate the ones it will never use,
- set **Needs a department** on accounts you always want split by branch or cost centre.

[Chart of accounts](04-chart-of-accounts.md) explains how. If the company already has a chart in a spreadsheet, [Imports](15-administration.md#imports) brings it in.

## 5. Add customers, suppliers and departments
**Who:** Bookkeeper or above for contacts; an administrator for departments.

- **Sales › Customers** and **Purchases › Suppliers**: add the people you invoice and the ones who bill you, with their TIN, terms and (for suppliers) the withholding tax rate.
- **Planning › Departments**: add branches or cost centres if the company reports by them.

Both can be imported from a spreadsheet instead — see [Imports](15-administration.md#imports).

## 6. Enter the opening balances
**Who:** Administrator. **Where:** Ledger › Opening balances.

This is the trial balance on the day the books start here — usually the first day of the fiscal year.

1. Open **Ledger › Opening balances**.
2. Set **The books start on** to the go-live date.
3. Add a line for every account with a balance: choose the account, then type the amount in **Debit** or **Credit**.
4. On the receivables and payables control accounts, add **one line per customer or supplier**, each with its own amount. That way the subsidiary ledgers start in step with the control accounts.
5. Watch the total at the foot: debits and credits must balance before it can be posted.
6. Select **Save draft** as often as you like — nothing is in the books yet.
7. When it balances, select **Post opening balances**.

**Result:** one entry in the opening book, numbered OB-…, dated the go-live day.

### What goes into the books
| Account | Debit | Credit |
|---|---|---|
| 1111 Cash on Hand | 25,000.00 | |
| 1121 Accounts Receivable - Trade (each customer) | 48,000.00 | |
| 2111 Accounts Payable - Trade (each supplier) | | 30,000.00 |
| 3210 Retained Earnings | | 43,000.00 |

> **Tip:** Have the list in a spreadsheet? Use **Administration › Imports › Opening balances**: it fills this draft in for you, and you still check and post it here.

> **Note:** Got it wrong? Open **Ledger › Opening balances**, select **Undo**, give a reason, and the entry is reversed and its lines come back into the draft to correct and post again. The month it is dated in has to be open for that.

## 7. Enter the fixed assets
**Who:** Bookkeeper or above. **Where:** Cash and assets › Fixed assets.

Register each asset the company already owns, and put the depreciation charged before go-live in **Accumulated depreciation brought in**. The opening balances above already carry the totals of both the asset and accumulated-depreciation accounts, so the register only has to agree with them. See [Fixed assets](10-fixed-assets.md).

## 8. Check it all ties
**Who:** Accountant or administrator.

1. Open **Reports › Trial balance** — it must balance, and it must match the trial balance you came from.
2. Open **Reports › Aging** for receivables and again for payables — each total must equal its control account.
3. Open **Reports › Integrity check** — everything should pass.

Now the books are ready. Post the first entry: [Journal entries](06-journal-entries.md).

## Common problems

**"No fiscal period covers 2026-01-01. Create the fiscal year first."** The date is outside every fiscal year. Open **Ledger › Fiscal years and periods** and open the year that covers it.

**"Choose an active equity account as the closing account in Settings › Account defaults."** The template did not fill in one of the account defaults. Set it in Settings.

**The opening balances do not balance.** The difference is shown at the foot of the draft. Usually an account was missed, or retained earnings has not been put in — it is the figure that makes the two sides equal.
