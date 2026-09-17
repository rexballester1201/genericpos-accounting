---
title: Financial reports
summary: The four statements, the trial balance and the worksheet, the general ledger and the books of accounts, the analysis — how to read each one, compare it, print it and check that it ties.
---

# Financial reports

Every report reads the same posted entries, so they always agree with one another. **Reports › All reports** lists them; the ones used every day are also in the sidebar.

Each report has the same three buttons: the period or date it covers, **Download CSV**, and **Print** (A4 with the letterhead and the signature lines, landscape where it needs the width).

## The four statements

### Balance sheet
*(A co-operative's is the **statement of financial condition**.)*

What the company owns, owes and is worth on a date, classified: current and non-current assets, current and non-current liabilities, equity.

- **As of** any date.
- **Compare with** the end of last year, or the same date last year.
- **Detail**: the totals of each heading, or every account.
- The result for the year that has not been closed yet shows in equity as its own line, so the statement balances before the year-end closing as well as after it.

The check at the foot — assets = liabilities + equity — must hold. It always does, because entries must balance to post.

### Income statement
*(A co-operative's is the **statement of operations**.)*

Revenue, cost of sales, gross profit, operating expenses and the result for a period, in the multi-step shape.

- **For a period**: this month, last month, this quarter, the year to date, or a whole fiscal year.
- **Compare with** the period before, the same period last year, or **month by month** — each month of the year in its own column (printed landscape).
- **A department** on its own.
- **As percentages** — every line as a share of revenue (common-size).

Closing entries are left out, so a closed year still reports the income it earned.

### Changes in equity
Each equity account from the balance at the start of the period, through the result for the period and everything else that moved it — contributions, withdrawals, dividends, transfers between funds, the year-end closing — to the balance at the end. The ending column equals the equity on the balance sheet.

### Cash flows
Where the cash came from and where it went, split into operating, investing and financing. Both methods PAS 7 allows are here; pick one from the **Method** list.

**Indirect** (the usual one) starts from the result for the period and adjusts it: depreciation added back, then the movement in every other balance-sheet account.

**Direct** starts from the cash accounts themselves. Every entry that touched cash is taken apart, and the cash it moved is attributed to the other accounts in the same entry, so the statement reads as receipts and payments:

- **Cash received from customers** — the receivables and sales side of the entries that brought cash in.
- **Cash paid to suppliers and employees** — the payables, inventory and expense side of the entries that took it out.
- **Cash generated from operations**, then interest received, interest paid and income taxes paid on their own lines. Income tax settled through a payable account sits with the other operating amounts.
- Underneath, a note reaches the same operating figure the other way, from the result for the period. Where the two methods differ, the note says why on a line called **Investing and financing activities that moved no cash** — equipment bought on account is the usual reason. The direct method is right there: no cash moved, so nothing is shown in investing.

Either way:

- Selling an asset shows as one thing: the **proceeds** in investing, because the whole amount the buyer paid is an investing flow.
- The foot reconciles: cash at the beginning, the change, cash at the end. Those figures are the cash accounts themselves.

## The trial balance
**Where:** Reports › Trial balance.

Every account's balance on a date, in two columns that must be equal.

- **Unadjusted** leaves out the adjusting and closing entries of the current year — the balances before the month-end work.
- **Adjusted** leaves out only the closing entries. This is the usual one.
- **Post-closing** counts everything, so after a year-end closing the income and expense accounts are zero.
- **Zero balances** shows accounts with no balance as well.

Select any account to open its general ledger.

## The worksheet
**Where:** Reports › Worksheet.

The ten-column worksheet, printed landscape: the unadjusted trial balance, the adjustments, the adjusted trial balance, and then each balance carried into either the income statement or the balance sheet, with the result balancing the last two pairs. It is the clearest single page for showing how the month-end adjustments turned into the statements.

## The general ledger
**Where:** Reports › General ledger.

One account's entries over a period: the balance brought forward, each line with its date, entry number, particulars and the running balance, then the totals and the balance carried forward. Choosing a header account takes in everything under it.

Every line links to the entry it came from, so a figure can always be traced back to the document behind it.

## The books of accounts
**Where:** Reports › Books of accounts.

The books the BIR expects, kept in this system as posted entries:

- the **general journal**, the **cash receipts book**, the **cash disbursements book**, the **sales book** and the **purchase book**;
- **as journal entries**, each entry with its lines, credits set in from the debits; or
- **in columns, paged** — the traditional layout for the four special books: a column for each account that recurs (cash, receivables, sales, output VAT…) and a *sundry* column for everything else. Each printed page carries its own **page total**, the **total carried forward** at its foot and the **total brought forward** at the top of the next one, and the last page carries the total for the period.

How many lines go on a printed page is set in **Settings › Printed reports**.

## Receivables, payables, banking and assets
These have their own chapters: [Sales and receivables](07-sales-and-receivables.md) for the aging, the statements of account and the subsidiary ledger, [Banking](09-banking.md) for the bank reconciliation, [Fixed assets](10-fixed-assets.md) for the lapsing schedule, [Budgets and departments](11-budgets-and-departments.md) for budget vs actual and income by department.

## Financial analysis
**Where:** Reports › Financial analysis.

Twenty-one ratios grouped as liquidity, solvency, profitability and efficiency, each with:

- its value on the date, and the same ratio a year earlier, with an arrow saying whether the move was the good way;
- a sentence saying what it means in plain words;
- its formula, and the figures that went into it — which are the statements' own figures, so a ratio can always be traced.

A co-operative also gets its PESOS-style indicators — portfolio at risk, allowance cover, share capital and statutory funds against assets, the operating cost ratio — each marked against the benchmark set in **Settings › Co-operative**. See [Co-operatives](14-cooperatives.md).

## The integrity check
**Where:** Reports › Integrity check. **Who:** Accountant or above.

Sixteen checks over the stored records: that every posted entry balances and has at least two lines, that numbers run without gaps, that every receivable and payable names its customer or supplier, that documents agree with their entries and their payments, that bank matches add up, that depreciation stays inside cost, that reversals point both ways, that closed years are closed out. Each check passes, warns, or fails with up to twenty examples that link to the record concerned.

Run it before a year-end, before an audit, and any time a total looks wrong.

## Common problems

**"Choose an account."** The general ledger needs one; pick it from the list or come from the trial balance.

**A report says a difference.** The aging, the subsidiary ledger and the lapsing schedule all set their totals against the ledger. A difference means something was posted straight to the control account by hand; the report lists the entries.

**The printed report is cut off on the right.** Wide reports print landscape by themselves. If a browser ignores that, set the orientation in the print dialog.

**A CSV shows 45,000.00 as text.** Some spreadsheets take the thousands separator that way. The files are written with plain numbers; check the locale set in the spreadsheet's import dialog.
