---
title: Month-end and year-end
summary: The checklist for closing a month, and the whole year-end: what the closing entry does, how to reopen a year, and a co-operative's net-surplus allocation.
---

# Month-end and year-end

## Closing a month

Work through this list in order. Everything in it has its own chapter; this is the order to do it in.

1. **Post what is still waiting.** **Ledger › Journal entries**, filter by *waiting*: post, reject or cancel every draft and submitted entry dated in the month. A month will not close while any are left.
2. **Bill and invoice everything.** No invoice or bill for the month should still be a draft. The dashboard counts them.
3. **Reconcile the banks.** Enter or import each statement, match it, record the charges and interest, and reconcile. See [Banking](09-banking.md).
4. **Run the depreciation.** **Cash and assets › Depreciation**: preview, then run the month. See [Fixed assets](10-fixed-assets.md).
5. **Post the accruals and prepayments.** Utilities not yet billed, rent paid in advance, interest earned. Saved entries make this a two-minute job — see [Journal entries](06-journal-entries.md).
6. **Check what ties.** **Reports › Aging** for receivables and for payables (each against its control account), the lapsing schedule against the asset accounts, and **Reports › Trial balance**.
7. **Run the integrity check.** **Reports › Integrity check** — nothing should fail.
8. **Print the month.** The trial balance, the income statement for the month, the balance sheet at the month end. File them, or save them as PDFs.
9. **Close the month.** **Ledger › Fiscal years and periods**, then **Close** on that month. See [Fiscal years and periods](05-fiscal-years-and-periods.md).

> **Tip:** Something late arrives after a month is closed? An accountant can reopen it, post, and close it again — every step is in the audit log. If the month has already been reported to a bank or the BIR, post the entry in the current month instead and explain it in the description.

## Closing a year
**Who:** Administrator. **Where:** Ledger › Year-end closing.

Closing a year moves the income and expenses into equity and stops anything more being dated in that year.

### 1. Check the list
The screen shows a checklist. The red ones stop the closing:

- an **earlier year** is still open — close the years in order;
- **entries are still waiting** in the year — post, reject or cancel them;
- the **last month is locked** — the closing entry is dated in it;
- the **closing account** in Settings is not set, or is not an active equity account;
- an account or a department the closing entry needs is **inactive** — make it active again, close, then deactivate;
- the **trial balance does not balance** — run the integrity check.

The amber ones are worth knowing but do not stop you: the year has not ended yet, rejected entries are dated in it, invoices or bills are still drafts, a bank statement is not reconciled, depreciation has not been run for some months, or the next fiscal year is not open yet.

### 2. Look at the entry it will post
The screen shows the closing entry line by line: every income and expense account with a balance for the year, reversed, and the difference going to the closing account. A sole proprietorship also closes the owner's drawings into capital in the same entry.

### 3. Close it
1. Select **Close FY2026**.
2. Type the year's name to confirm.
3. Confirm.

**Result:** the closing entry posts in the closing book, dated the last day of the year; every open month of the year is closed; and the year is marked closed.

### What goes into the books
A year with 8,182,401.79 of revenue and 7,507,517.02 of expenses:

| Account | Debit | Credit |
|---|---|---|
| 4110 Sales (and every other income account) | 8,182,401.79 | |
| 5100 Cost of Goods Sold (and every other expense account) | | 7,507,517.02 |
| 3210 Retained Earnings | | 674,884.77 |

After it, the income statement for the year still shows what the year earned — it leaves the closing book out — while the balance sheet shows the result inside retained earnings, and the post-closing trial balance shows the income and expense accounts at zero.

## Reopening a year
**Who:** Administrator.

Only the latest closed year can be reopened.

1. Open **Ledger › Year-end closing** and choose the year.
2. Select **Reopen FY2026**.
3. Say why, then type the year's name to confirm.

**Result:** the closing entries are reversed, dated the year end; the year and its last month are open again. Post what was missing, then close the year again — the closing book simply gets the next number, with no gaps.

> **Note:** If a co-operative's net-surplus allocation was posted in a **later** year, undo that first; the screen says so.

## A co-operative's net surplus
**Who:** Administrator. **Where:** Ledger › Year-end closing.

A co-operative does not stop at moving the surplus into equity: the Cooperative Code sets out how it is divided. The screen shows the split before anything is posted:

| Fund or payable | Usual share |
|---|---|
| Reserve fund | at least 10 % of the net surplus |
| Education and training fund | up to 10 % |
| Community development fund | at least 3 % |
| Optional fund | up to 7 % |
| Interest on share capital | a share of what is left |
| Patronage refund | the rest |

The percentages come from **Settings › Co-operative** and can be changed for one allocation on this screen. Each fund is rounded to the centavo and the patronage refund takes the remainder, so the parts always add up to the surplus exactly.

**Allocate with the closing:** tick *Allocate it now* before closing the year. The allocation posts as a second closing-book entry dated the year end.

**Allocate later:** leave it unticked, close the year, and come back after the general assembly has approved the allocation. Set the **date** — in an open month after the year — and select **Allocate the net surplus**.

### What goes into the books
A net surplus of 200,000.00 at the usual percentages:

| Account | Debit | Credit |
|---|---|---|
| 32100 Undivided Net Surplus | 200,000.00 | |
| 34100 Reserve Fund | | 20,000.00 |
| 34200 Education and Training Fund | | 20,000.00 |
| 34300 Community Development Fund | | 6,000.00 |
| 34400 Optional Fund | | 14,000.00 |
| 21500 Interest on Share Capital Payable | | 42,000.00 |
| 21600 Patronage Refund Payable | | 98,000.00 |

**To undo an allocation** posted after the year end: open the year and select **Undo the allocation**, with a reason. It is reversed and can be posted again with different percentages.

## Common problems

**"FY2025 is still open. Close the years in order, oldest first."** Close the earlier year first.

**"4 journal entries are still waiting in FY2026."** The link on the item opens them.

**"Dec 2026 is locked for good, and the closing entry is dated in it."** A locked month can never take an entry. This one cannot be worked around; it is what locking means.

**"The net-surplus allocation was posted on 2027-02-15, in a later year. Undo it first."** Undo the allocation, then reopen the year.

**"Only the latest closed year can be reopened."** Reopen the later year first.
