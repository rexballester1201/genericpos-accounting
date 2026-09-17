---
title: Purchases and payables
summary: Keep the supplier list, enter and post bills and debit notes, pay suppliers with expanded withholding tax, and watch what is due.
---

# Purchases and payables

Everything the company owes its suppliers runs through here, and it mirrors the sales side. A bill posts to the purchase journal and puts the amount on the payables control account under that supplier's name; a payment posts to the cash disbursements book and takes it off again.

## Before you start
- The supplier must exist ([Add a supplier](#add-a-supplier) below).
- **Settings › Account defaults** needs the **Payables control**, the **Input VAT** account, the **Withholding tax payable (expanded)** account and a **Default purchases account**.
- The date must fall in an open month ([Fiscal years and periods](05-fiscal-years-and-periods.md)).

## Who can do what
| Task | Viewer | Bookkeeper | Accountant | Administrator |
|---|---|---|---|---|
| See suppliers, bills, payments and the reports | Yes | Yes | Yes | Yes |
| Add or change a supplier | | Yes | Yes | Yes |
| Prepare a bill, a debit note or a payment | | Yes | Yes | Yes |
| Post one, cancel a posted one, remove an allocation | | | Yes | Yes |
| Apply a payment to bills | | Yes | Yes | Yes |

## Add a supplier
**Who:** Bookkeeper or above.

1. Open **Purchases › Suppliers** and select **New supplier**.
2. The **Code** is filled in (S006 next in the demo company).
3. Type the **Name**, **TIN**, **Address** and **Contact person**.
4. Set the **Terms** in days.
5. Set the **Withholding rate** the company withholds on this supplier's bills: 1 % on goods, 2 % on services, 5 % on rentals, 10 % or 15 % on professional fees. Leave it at *None* if nothing is withheld.
6. Choose a **Default purchases account** — the expense or inventory account this supplier's bills usually go to.
7. Select **Add supplier**.

## Enter a bill
**Who:** Bookkeeper or above prepares; an accountant posts.

1. Open **Purchases › Bills** and select **New bill**.
2. Choose the **Supplier**; the terms and default account fill in.
3. Check the **Date** — use the date on the supplier's invoice — and the **Due date**.
4. Put the supplier's own invoice number in **Reference**. It prints on the voucher and shows in the books.
5. Enter the lines: the **Account** (an expense, or inventory), what it is for, the **Quantity**, the **Unit price**, and the **VAT** treatment.
6. Choose a **Department** where the account asks for one.
7. Select **Save draft**, then **Post bill** when it is checked.

### What goes into the books
A bill of 11,200.00 including 12 % VAT, for merchandise:

| Account | Debit | Credit |
|---|---|---|
| 1131 Merchandise Inventory | 10,000.00 | |
| 1144 Input VAT | 1,200.00 | |
| 2111 Accounts Payable - Trade (the supplier) | | 11,200.00 |

> **Tip:** Only claim input VAT where the supplier's invoice is a VAT invoice with their TIN on it. Where it is not, mark the line *VAT-exempt* and the whole amount goes to the expense.

## Pay a supplier
**Who:** Bookkeeper or above prepares; an accountant posts.

1. Open **Purchases › Payments** and select **Record a payment** (or **Record payment** on the supplier's page or the bill).
2. Choose the **Supplier** and the **Date paid**.
3. Choose **Paid from** — the cash or bank account.
4. Type the **Cheque number** and the **Amount paid**.
5. **Tax withheld** is worked out from the supplier's rate on the bills you are paying; change it if the case is different. The bills are settled by the cash plus the tax withheld.
6. Under **What it pays**, select **Fill oldest first** or type the amounts against each bill.
7. Save it, then **Post payment**.

### What goes into the books
14,866.07 paid on bills of 15,000.00, with 2 % (133.93) withheld:

| Account | Debit | Credit |
|---|---|---|
| 2111 Accounts Payable - Trade (the supplier) | 15,000.00 | |
| 1113 Cash in Bank - Current Account | | 14,866.07 |
| 2123 Withholding Tax Payable - Expanded | | 133.93 |

The balance on 2123 is what the company remits to the BIR with its return; the certificate (BIR Form 2307) comes from the same figures.

## Debit notes
A debit note reduces what the company owes — goods returned to the supplier, an overcharge. Raise it from the bill it corrects, enter the lines, and post it; it is applied to that bill.

| Account | Debit | Credit |
|---|---|---|
| 2111 Accounts Payable - Trade (the supplier) | 11,200.00 | |
| 1131 Merchandise Inventory | | 10,000.00 |
| 1144 Input VAT | | 1,200.00 |

## Cancel a posted bill or payment
**Who:** Accountant or above.

Open it, select **Cancel bill** (or **Cancel payment**), choose the date of the reversal and say why. Its entry is reversed, what it settled is open again, and it keeps its number.

The system refuses while money is applied to a bill (remove the allocations first), or while a payment's cash line is matched on a bank statement (unmatch it in [Banking](09-banking.md) first).

## The reports

**Aging** (**Reports › Aging**, then *Payables*) — what is owed to each supplier, in buckets by how far past due it is, set against the payables control account.

**Statement of account** (**Reports › Statement of account**, then *Supplier*) — one supplier's bills and payments over a period. Useful when a supplier's statement disagrees with yours.

**Subsidiary ledger** (**Reports › Subsidiary ledger**, then *Payables*) — every supplier's balance, adding up to the control account.

**The purchase book and the cash disbursements book** (**Reports › Books of accounts**) — in columns, as the BIR expects them, with page totals and the amounts brought forward. See [Financial reports](12-financial-reports.md).

## Common problems

**"2111 needs a supplier."** A line on the payables control account has no supplier named. Open the entry and set it.

**"Applied more than the bill is open for."** The amounts under *What it pays* add up to more than the bill still owes. Lower them, or leave the rest unapplied.

**The supplier says we owe more than our books do.** Print their statement of account and compare it line by line with theirs; the usual causes are a bill that was never entered, or one entered twice under different reference numbers.
