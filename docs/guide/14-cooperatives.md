---
title: Co-operatives
summary: What is different for a co-operative — the chart, the words on the statements, the statutory funds, the net-surplus allocation and the PESOS-style indicators.
---

# Co-operatives

A co-operative keeps the same double-entry books as any company. What differs is the vocabulary the CDA uses, the chart of accounts, the statutory funds, and how the surplus is divided at the end of the year. Choosing *Co-operative* at setup turns all of that on; it cannot be changed afterwards, because the chart and the statements depend on it.

## The words

| A business says | A co-operative says |
|---|---|
| Balance sheet | Statement of Financial Condition |
| Income statement | Statement of Operations |
| Net income | Net surplus |
| Equity | Members' equity |
| Retained earnings | Undivided net surplus |
| Changes in equity | Statement of Changes in Members' Equity |

The reports, the year-end screen and the analysis all follow this by themselves. Nothing has to be set.

## The chart of accounts

The co-operative template follows the CDA's standard chart: five-digit codes, loans receivable and their allowance under assets, deposit liabilities, share capital split between common and preferred, the four statutory funds in equity, and interest on share capital and patronage refund as payables.

| Account | What it is for |
|---|---|
| 11270 Accounts Receivable - Trade | what members and others owe on sales |
| 11210 Loans Receivable | the loan portfolio, if the co-op lends |
| 21500 Interest on Share Capital Payable | the members' share of the surplus, by share capital |
| 21600 Patronage Refund Payable | the members' share of the surplus, by patronage |
| 31100 Paid-up Share Capital - Common | members' shares |
| 32100 Undivided Net Surplus | where the year's surplus lands at closing |
| 34100 Reserve Fund | the statutory reserve |
| 34200 Education and Training Fund | CETF |
| 34300 Community Development Fund | CDF |
| 34400 Optional Fund | the optional fund |

Add to it as the co-operative needs; the rules in [Chart of accounts](04-chart-of-accounts.md) are the same.

## The CDA registration number
**Who:** Administrator. **Where:** Settings › Company.

Fill in **CDA registration number**. It prints on the letterhead of every report, beside the TIN.

## The net-surplus allocation

At the end of the year the surplus is moved out of undivided net surplus into the statutory funds and what is owed to members. The percentages live in **Settings › Co-operative**:

| Setting | The usual figure |
|---|---|
| Reserve fund (%) | 10 — at least, by the Code |
| Education and training fund (%) | 10 — up to |
| Community development fund (%) | 3 — at least |
| Optional fund (%) | 7 — up to |
| Interest on share capital (% of the remainder) | 30, with the patronage refund taking the rest |

> **Warning:** These are the figures in the Cooperative Code. **Check them against the co-operative's own by-laws and the general assembly's resolution before the first allocation** — a co-operative may set higher shares, and the board may allocate differently in a given year.

The allocation itself, with or without the closing, is in [Month-end and year-end](13-month-end-and-year-end.md#a-co-operatives-net-surplus). Each fund is rounded to the centavo and the patronage refund takes what is left, so the parts always add up.

## Paying members

The allocation only records what is owed. Paying it out is an ordinary payment:

| Account | Debit | Credit |
|---|---|---|
| 21600 Patronage Refund Payable | 98,000.00 | |
| 11110 Cash on Hand | | 98,000.00 |

Do it as a journal entry in the cash disbursements book ([Journal entries](06-journal-entries.md)), or as a payment if the members are set up as contacts.

## The indicators
**Where:** Reports › Financial analysis.

Besides the usual ratios, a co-operative gets five PESOS-style indicators:

| Indicator | What it says | The usual mark |
|---|---|---|
| Portfolio at risk | loans past due ÷ loans receivable | 5 % or less |
| Allowance cover | allowance for probable losses ÷ loans past due | at least 35 % |
| Share capital to total assets | how much of the co-op is its members' shares | at least 35 % |
| Statutory funds to total assets | the four funds together against assets | at least 10 % |
| Operating cost ratio | operating and financing costs ÷ revenue | 30 % or less |

Each one shows whether it meets its mark. **The marks are yours to set** — an administrator changes them in **Settings › Co-operative**, where they sit under the allocation percentages. Setting one to zero takes the mark off the report.

For the portfolio indicators to mean anything, the loan accounts must be there and used: loans receivable, loans past due (or a separate account for them), and the allowance for probable losses.

## What is the same

Everything else. Invoices, bills, receipts, payments, the bank reconciliations, the fixed assets, the budgets, the books of accounts, the audit trail, the month-end and the approval rules all work exactly as the rest of this guide describes.

## Common problems

**The statements still say "balance sheet".** The company was set up as a business. The kind of organisation is chosen at setup and cannot be changed; a co-operative needs a fresh company with the co-operative chart.

**"Choose the account for the reserve fund in Settings › Co-operative."** The allocation needs an account for each fund it is about to post to. Set them in Settings.

**The allocation percentages add up to more than 100 %.** The four funds together cannot take more than the whole surplus. Check them against the by-laws.

**Portfolio at risk shows nothing.** There are no loans in the chart, or nothing has been posted to them. The indicator divides by the loan portfolio, and there is none.
