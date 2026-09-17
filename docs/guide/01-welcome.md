---
title: Welcome
summary: What this system is, who does what in it, and how a figure travels from a document to the financial statements.
---

# Welcome

GenericPOS Accounting keeps a double-entry set of books for a Philippine business or co-operative: the journals and ledgers, the subsidiary ledgers for customers and suppliers, the bank reconciliations, the fixed-asset register, the budgets, and the financial statements and BIR-style books that come out of them.

Everything in it obeys one rule: **nothing reaches the ledger except through a posted, balanced journal entry.** An invoice, a receipt, a depreciation run and a year-end closing all write the same kind of entry, each one numbered, dated in an open month, and kept for good. Nothing is ever quietly deleted.

## How a figure travels

1. Somebody prepares a document — an invoice, a bill, a receipt, a payment, or a journal entry itself. At this point it is a **draft**: it has no number and is not in the books.
2. An accountant checks it and **posts** it. The entry takes its number, the ledger changes, and the audit log records who posted it and when.
3. Every report — the trial balance, the statements, the aging, the books of accounts — reads the posted entries. There is no second set of figures to reconcile.
4. At the end of the month the periods are closed; at the end of the year the income and expense accounts are closed into equity.

If something is wrong after it has posted, it is not erased: it is **reversed** by a second entry, and both stay on record. That is what makes the books defensible to an auditor or an examiner.

## Who does what

| | Viewer | Bookkeeper | Accountant | Administrator |
|---|---|---|---|---|
| Read the reports, ledgers and analysis | Yes | Yes | Yes | Yes |
| Prepare drafts: entries, invoices, bills, receipts, payments; match the bank | | Yes | Yes | Yes |
| Approve and post, reverse, close months, run depreciation, keep the budgets | | | Yes | Yes |
| The chart of accounts, departments, fiscal years, the year-end, users, settings, imports | | | | Yes |

One person can hold a higher role and do everything below it. In a one-person office an administrator can do the lot, though the system will still ask a second person to approve unless that is switched off in Settings.

## How to read this guide

- **Sales › Invoices** means: the group *Sales* in the sidebar on the left, then *Invoices*.
- **Post invoice** in bold is a button or a field exactly as it appears on screen.
- Every task says who may do it, what to type, and what it puts into the books, like this:

| Account | Debit | Credit |
|---|---|---|
| 1121 Accounts Receivable - Trade (the customer) | 11,200.00 | |
| 4110 Sales | | 10,000.00 |
| 2121 Output VAT | | 1,200.00 |

- Co-operatives: the system uses the CDA's words where they differ — *net surplus* instead of net income, *statement of financial condition* instead of balance sheet, *members' equity* instead of equity. [Co-operatives](14-cooperatives.md) covers what is different.

## Where to start

- Nothing set up yet? [First-time setup](02-first-time-setup.md).
- New to the screens? [Finding your way](03-finding-your-way.md).
- Ready to keep books? [Journal entries](06-journal-entries.md), then [Sales and receivables](07-sales-and-receivables.md) and [Purchases and payables](08-purchases-and-payables.md).
- Closing a month or a year? [Month-end and year-end](13-month-end-and-year-end.md).
