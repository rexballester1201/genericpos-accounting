---
title: Journal entries
summary: Prepare an entry, submit it, approve and post it, reverse one that was wrong, and keep the ones you make every month as saved entries.
---

# Journal entries

A journal entry is the only thing that ever reaches the ledger. Invoices, receipts, depreciation and the year-end closing all write one; this chapter is about the ones you write yourself — accruals, adjustments, corrections, payroll, and anything the modules do not cover.

Its life:

**draft** → **submitted** → **posted**, or **rejected** (back to whoever prepared it), or **cancelled**

Only a posted entry is in the books. A posted entry is never edited: it is **reversed**, and both entries stay on record.

## Before you start
- The date must fall in an open month of an open year ([Fiscal years and periods](05-fiscal-years-and-periods.md)).
- Debits must equal credits, and an entry needs at least two lines.
- A line on a receivables or payables control account must name its customer or supplier; an account marked "needs a department" must have one.

## Who can do what
| Task | Viewer | Bookkeeper | Accountant | Administrator |
|---|---|---|---|---|
| See entries and their history | Yes | Yes | Yes | Yes |
| Prepare, change and submit a draft | | Yes | Yes | Yes |
| Approve and post, reject, reverse | | | Yes | Yes |
| Cancel a draft | | its own preparer | Yes | Yes |

## The books an entry can go in

| Book | Prefix | What goes in it |
|---|---|---|
| General journal | GJ | anything that is not cash, sales or purchases |
| Cash receipts | CR | money in |
| Cash disbursements | CD | money out |
| Sales journal | SJ | posted invoices and credit notes |
| Purchase journal | PJ | posted bills and debit notes |
| Adjusting entries | AJ | month-end adjustments, depreciation |
| Closing entries | CJ | the year-end closing (written by the system) |
| Opening balances | OB | the balances at go-live (written by the system) |

Numbers look like **CD-2026-00042**: the book's prefix, the fiscal year, then a number that never skips.

## Prepare an entry
**Who:** Bookkeeper or above.

1. Open **Ledger › Journal entries** and select **New entry**.
2. Choose the **Book** and the **Date**. The hint under the date says which months are open.
3. Fill in the **Reference** (an OR or cheque number, say) and **Paid to / received from** if they apply.
4. Type the **Description** — what this entry is for, in a sentence someone will understand next year.
5. For each line: choose the **Account**, type a **Memo** if it helps, choose the **Department** or the **Customer or supplier** where the account asks for one, and type the amount under **Debit** or **Credit**.
6. Select **Add a line** for more lines. A new line is offered the amount that would balance the entry.
7. Watch the foot of the table: it says **Balanced** or how far apart the two sides are.
8. Select **Save draft** to keep working on it later, or **Save and submit** to send it for approval.

**Result:** the entry is saved. A draft has no number yet — numbers are given when an entry posts, so an abandoned draft never leaves a hole in the books.

### What goes into the books
Nothing yet. A draft is not in the ledger.

> **Tip:** **Save as a saved entry** keeps the shape of the entry — accounts, memos, even the amounts — under a name, so next month you start from it instead of typing it again. See *Saved entries* below.

## Submit, approve and post
**Who:** the preparer submits; an accountant or administrator approves.

1. The preparer opens the entry and selects **Submit for approval**.
2. The approver opens **Ledger › Approvals**, checks it, and selects **Approve and post**.

**Result:** the entry takes its number, the ledger changes, and the audit log records who posted it and when.

If something is wrong, the approver selects **Reject** and says what to fix. The entry goes back to its preparer, who corrects it and submits it again.

> **Note:** By default an approver may not post an entry they prepared themselves — that is maker-checker, and it is what an auditor expects. In a one-person office an administrator can switch it off under **Settings › The ledger**.

## Reverse a posted entry
**Who:** Accountant or above.

1. Open the entry and select **Reverse**.
2. Choose the **Date of the reversal** — on or after the entry's own date, in an open month. Reversing in the same month leaves that month clean; reversing in a later month leaves both months as they were reported.
3. Say **why**.
4. Select **Reverse it**.

**Result:** a new posted entry with every debit and credit swapped. The two are linked, and each says so on its page.

### What goes into the books
The mirror image of the original:

| Account | Debit | Credit |
|---|---|---|
| 6210 Utilities Expense | | 4,500.00 |
| 2130 Accrued Expenses | 4,500.00 | |

> **Note:** Entries a module posted — invoices, receipts, depreciation, disposals, bank adjustments — are not reversed from here. Undo them where they were made, so the document and the ledger stay in step. The entry's page links to the document it came from.

## Cancel a draft
A draft, a submitted entry or a rejected one can be **cancelled**: it stays on record as cancelled, with its history, and never reaches the ledger. Cancelling cannot be undone; prepare a new entry instead.

## Saved entries
**Who:** Bookkeeper or above. **Where:** Ledger › Saved entries.

A saved entry is the shape of an entry the office makes again and again — the rent, the payroll, a monthly accrual.

**To make one:** fill in the entry form as usual and select **Save as a saved entry**, then give it a name. Lines whose amount changes every time can be left at zero.

**To use one:** open **Ledger › Saved entries** and select **Use it**, or choose it in **Start from a saved entry** at the top of a new entry. The form fills in; check the date and the amounts and save it as usual.

**To have it made for you every month:** open **Ledger › Saved entries**, select **Schedule**, choose the day of the month, the date of the next one, and leave it **Active**. On that day the scheduled job prepares it as a **draft** for you and tells you in your notifications. It never posts by itself — somebody still submits and approves it. A recurring saved entry needs all its amounts filled in.

> **Tip:** In the description, `{month}` becomes the month of each entry: "Office rent for {month}" comes out as "Office rent for October 2026".

## The voucher
Every entry has a **Voucher** button: the printable sheet that gets signed and filed — a journal voucher, a cash receipt voucher or a disbursement voucher, depending on the book. It carries the letterhead, the particulars, the account distribution, the amount in words for cash entries, and the signature lines.

## Attachments
Each entry has an **Attachments** panel for the supporting papers: the scanned receipt, the supplier's invoice, the computation behind an accrual. Files can be added at any time, before or after posting, and only people who are signed in can open them.

## Common problems

**"Debits (11,200.00) and credits (10,000.00) do not balance."** The two sides differ. The foot of the lines table shows by how much.

**"Line 2: 1121 needs a customer."** A receivables or payables line must name the customer or supplier it belongs to.

**"Line 3: 6210 needs a department."** That account is marked as needing one. Choose it on the line.

**"Sep 2026 is closed. An accountant can reopen it."** Date the entry in an open month, or ask for that one to be reopened.

**"Only its preparer can change a draft or a rejected entry."** Approvers do not rewrite other people's entries; they reject them with a reason.

**"This entry was posted from a customer invoice. Undo it there…"** Open the document the entry's page links to and cancel it there.
