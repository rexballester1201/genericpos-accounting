---
title: Troubleshooting and glossary
summary: The messages the system gives and what to do about them, the checks that must always tie, and what the words mean.
---

# Troubleshooting and glossary

## When something will not post

**"No fiscal period covers 2027-01-05. Create the fiscal year first."**
The date is outside every fiscal year. An administrator opens the year under **Ledger › Fiscal years and periods**.

**"Sep 2026 is closed. An accountant can reopen it."**
Date the entry in an open month, or ask an accountant to reopen that one.

**"Aug 2026 is locked."**
A locked month never takes another entry. Post the correction in an open month.

**"FY2026 is closed."**
The whole year is closed. An administrator can reopen it from **Ledger › Year-end closing**, or the entry can be dated in the current year.

**"Debits (11,200.00) and credits (10,000.00) do not balance."**
The two sides differ; the foot of the lines shows by how much.

**"Line 2: 1121 needs a customer."**
Receivables and payables lines must name the customer or supplier, so the subsidiary ledgers always equal their control accounts.

**"Line 3: 6210 needs a department."**
The account is marked as needing one. Choose it on the line.

**"Only a posted entry can be reversed."**
A draft is cancelled, not reversed.

**"This entry was posted from a customer invoice. Undo it there, so the document and the ledger stay in step."**
Open the document the entry links to and cancel it there.

**"The audit trail could not be written, so nothing was saved."**
The system refuses to change the books without recording who did it. Tell the administrator — the database is probably refusing writes.

## When a total does not tie

Everything in the books ties to something else. When two figures disagree, this is where to look.

| What disagrees | Where to look |
|---|---|
| The aging against the receivables control account | The foot of the aging lists the entries posted straight to the control account. Reverse them and raise the document instead. |
| The subsidiary ledger against the control account | The same cause; the report shows what could not be attributed. |
| The lapsing schedule against the asset accounts | An asset was bought but never registered, or registered but never booked. The tie-out block on the report names the category. |
| The bank against the books | The reconciliation report explains it: deposits in transit, outstanding cheques, and what the books do not have yet ([Banking](09-banking.md)). |
| The income statement against the department columns | Entries that carry no department are in the *No department* column. |
| The trial balance itself | Run **Reports › Integrity check** — that is exactly what it is for. |

## When the screen misbehaves

**A page says "Could not load…" with a message.**
The server refused or could not be reached. The message says which. If it says nothing useful, the browser was offline; the pages you already opened keep working.

**The figures look stale after somebody else posted.**
Reload the page. Reports are read fresh every time; the browser may be showing what it had.

**"Request failed (500)" or "Something went wrong on the server."**
Something broke where the system could not explain it. Tell the administrator what you were doing and when — the server's log has the detail, and the audit log shows what did and did not happen.

**The print comes out with the sidebar.**
Use the **Print** button on the report, not the browser's menu.

## The words

**Account** — a line in the chart of accounts that figures are posted to.

**Accrual** — an expense incurred or income earned that has not been billed yet, recorded so the month carries its own costs.

**Adjusting entry** — a month-end entry that puts the books right before the statements: depreciation, accruals, prepayments.

**Aging** — a report that puts what is owed into buckets by how long it has been outstanding.

**Allocation** — saying which invoice a receipt (or credit note) pays. It moves nothing in the ledger.

**Book** — where an entry is filed: general journal, cash receipts, cash disbursements, sales, purchases, adjusting, closing, opening.

**Brought forward / carried forward** — the balance at the start of a page or period, and at its end.

**Control account** — the account in the general ledger that holds the total of a subsidiary ledger, such as receivables.

**Credit note** — a document that reduces what a customer owes.

**CWT (creditable withholding tax)** — tax a customer withholds from its payment to the company, which the company credits against its own income tax.

**Debit note** — a document that reduces what the company owes a supplier.

**Draft** — prepared but not posted. Not in the books, and it has no number.

**EWT (expanded withholding tax)** — tax the company withholds from a payment to a supplier and remits to the BIR.

**Fiscal year** — the twelve months the company reports on.

**Journal entry** — the record of one transaction, balanced, with at least two lines. The only thing that reaches the ledger.

**Ledger** — every posted line, which is what every report reads.

**Maker-checker** — one person prepares, another approves. It is the control that keeps a single person from writing the books alone.

**Net surplus** — a co-operative's result for the year, before it is allocated.

**Opening balances** — the balances the books start from at go-live.

**Period** — a month inside a fiscal year. Entries post only into an open one.

**Posting** — approving an entry so it reaches the ledger, takes its number, and becomes part of the books.

**Reversal** — a second entry with every debit and credit swapped, which cancels the first. Both stay on record.

**Statutory funds** — a co-operative's reserve, education and training, community development and optional funds.

**Subsidiary ledger** — the detail behind a control account: the balance of each customer, supplier or asset.

**Trial balance** — every account's balance on a date, in two columns that must be equal.

**VAT-exempt, zero-rated, VATable** — how a sale or purchase is treated for VAT. VATable carries the 12 %; zero-rated is taxable at 0 %; exempt carries none.

**Voucher** — the printed sheet that is signed and filed: journal voucher, cash receipt voucher, disbursement voucher.

**Worksheet** — the ten-column sheet that carries the trial balance through the adjustments into the statements.
