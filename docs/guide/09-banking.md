---
title: Banking
summary: Keep the bank accounts, enter or import a statement, match it against the books, record the charges and interest the bank added, and reconcile.
---

# Banking

Reconciling is checking that the bank and the books say the same thing, and explaining every difference. In this system a **bank statement** is entered (or imported) line by line, each line is **matched** to the entry in the books that caused it, and anything the bank did that the books do not know about — charges, interest, an automatic debit — is **recorded** as an entry from the statement itself. When nothing is left over, the statement is **reconciled** and locked.

## Before you start
- Each bank account must be linked to a cash account in the chart ([Chart of accounts](04-chart-of-accounts.md)); its cash-flow class must be *cash*.
- **Settings › Account defaults** needs a **Bank charges** account and an **Interest income** account for the entries recorded from the statement.
- **Settings › Banking** sets how many days apart a bank line and a book line may still be matched (7 in the demo company).

## Who can do what
| Task | Viewer | Bookkeeper | Accountant | Administrator |
|---|---|---|---|---|
| See the bank accounts, statements and the reconciliation report | Yes | Yes | Yes | Yes |
| Enter or import a statement, match, unmatch, set a line aside | | Yes | Yes | Yes |
| Add or change a bank account | | | Yes | Yes |
| Record an entry from a bank line, reconcile, reopen | | | Yes | Yes |

## Add a bank account
**Who:** Accountant or above.

1. Open **Cash and assets › Banking** and select **New bank account**.
2. Type the **Bank** and the **Account name**.
3. Type the **last four digits** of the account number — only those are kept.
4. Choose the **Ledger account** it belongs to, such as *1113 Cash in Bank - Current Account*. One ledger account, one bank account.
5. Select **Create**.

**Result:** the account appears with its balance in the books and a place for its statements.

## Enter a statement
**Who:** Bookkeeper or above.

1. Open **Cash and assets › Banking** and select **New statement** on the account.
2. Type the **Statement date** — the closing date the bank printed.
3. Type the **Opening balance** and the **Closing balance** exactly as the bank has them. The opening balance should equal the last statement's closing balance; the screen says so if it does not.
4. Select **Create statement**.
5. Add the lines, either by hand or by import (below). Each line is a **date**, a **description**, a **reference** and an **amount**: a deposit is positive, a withdrawal negative.

The screen keeps a running check: *opening + the lines = closing*. While they differ, it says by how much — usually a line was missed or keyed twice.

### Import the lines from a CSV
1. On the statement, select **Import**.
2. Choose the file the bank gave you, or paste the rows.
3. Say which column holds the **date**, the **description**, the **reference** and the **amount**. Banks that use two columns are handled by **Withdrawal and deposit columns**; one signed column by **One amount column (− for withdrawals)**.
4. Choose the **date format** the bank uses.
5. Look at the preview, then select **Import**.

Rows already on the statement (same date, amount and reference) are skipped and reported, so importing the same file twice does no harm.

## Match the statement to the books
**Who:** Bookkeeper or above.

The screen shows the statement's lines on one side and, on the other, the entries in the books on that bank account that nothing has cleared yet.

- **Auto-match** pairs every line that has exactly one candidate of the same amount within the matching window. It reports how many it matched.
- **To match by hand:** select a bank line, then select the book line or lines that make it up, and select **Match**. Several book lines may make up one bank line — a deposit that covers three receipts, for example. The amounts must add up exactly.
- **Unmatch** takes a pairing back while the statement is open.
- **Set aside** (ignore) is for a line that belongs to neither side: a bank error that the bank reverses on the same statement. Say why; it stays on the statement, marked.

## Record what the books do not have
**Who:** Accountant or above.

Bank charges, interest credited, an automatic debit: the bank knows, the books do not.

1. Select the bank line and choose **Record an entry…**.
2. The screen offers **Record the bank charge** (for a withdrawal) or **Record the bank credit** (for a deposit), with the account from Settings; change the account if this one is different.
3. Confirm.

**Result:** the entry posts, dated on the bank line's own date, and the line is matched to it at once.

### What goes into the books
A bank charge of 450.00:

| Account | Debit | Credit |
|---|---|---|
| 6270 Bank Charges | 450.00 | |
| 1113 Cash in Bank - Current Account | | 450.00 |

Interest credited of 150.00:

| Account | Debit | Credit |
|---|---|---|
| 1113 Cash in Bank - Current Account | 150.00 | |
| 4910 Interest Income | | 150.00 |

> **Note:** Got one wrong? **Undo entry** on the line reverses the entry and unmatches the line, as long as the statement is still open.

## Reconcile
**Who:** Accountant or above.

When every line is matched or set aside and the difference is zero, select **Reconcile**.

**Result:** the statement is locked — no more matching, no changes to its lines — and it is stamped with who reconciled it and when. An accountant can **Reopen** it while it is the latest reconciled statement for that bank account.

## The reconciliation report
**Where:** Reports › Bank reconciliation, or **Open the statement** from there.

It is the statement an auditor asks for:

- **Balance per bank statement**, plus **deposits in transit** (money in the books the bank has not credited yet), less **outstanding cheques** (cheques written that have not cleared) = the **adjusted bank balance**;
- **Balance per books**, plus the credit memos and less the debit memos the books do not have yet = the **adjusted book balance**;
- the two adjusted balances, and the difference between them, which must be zero.

It prints with the letterhead and the signature lines, and downloads as CSV.

## Common problems

**"The lines add up to 961,895.71, the statement says 961,745.71."** A line is missing or keyed twice. Compare the count of lines with the bank's.

**"That book line is already matched."** A book line clears once. If it was matched to the wrong bank line, unmatch it there first.

**"The amounts do not add up."** The book lines you picked do not equal the bank line. Check for a charge the bank took out of a deposit — that part is recorded as an entry, not matched.

**"Every line must be matched or set aside first."** Something is still unmatched. The count at the top says how many.

**The difference will not go to zero.** Look for: a deposit in transit recorded in the books after the statement date (that is fine — it shows in the report), a cheque written twice, or a bank charge not yet recorded.
