---
title: Sales and receivables
summary: Keep the customer list, raise and post invoices and credit notes, record receipts with creditable withholding tax, and print the aging and statements of account.
---

# Sales and receivables

Everything owed to the company by its customers runs through here. An invoice posts to the sales journal and puts the amount on the receivables control account under that customer's name; a receipt posts to the cash receipts book and takes it off again. Because every line on the control account names its customer, the subsidiary ledger and the control account can never drift apart.

## Before you start
- The customer must exist ([Add a customer](#add-a-customer) below).
- **Settings › Account defaults** needs the **Receivables control**, the **Output VAT** account, the **Creditable withholding tax** account and a **Default sales account**. See [Administration](15-administration.md#settings).
- **Settings › Tax** decides whether the company is VAT-registered, the rate, and whether typed amounts include VAT.
- The date must fall in an open month ([Fiscal years and periods](05-fiscal-years-and-periods.md)).

## Who can do what
| Task | Viewer | Bookkeeper | Accountant | Administrator |
|---|---|---|---|---|
| See customers, invoices, receipts and the reports | Yes | Yes | Yes | Yes |
| Add or change a customer | | Yes | Yes | Yes |
| Prepare an invoice, a credit note or a receipt | | Yes | Yes | Yes |
| Post one, cancel a posted one, remove an allocation | | | Yes | Yes |
| Apply money to invoices | | Yes | Yes | Yes |

## Add a customer
**Who:** Bookkeeper or above.

1. Open **Sales › Customers** and select **New customer**.
2. The **Code** is filled in for you (C007 next in the demo company); change it if you have your own scheme.
3. Type the **Name**, and the **TIN**, **Address** and **Contact person** if you have them.
4. Set the **Terms** in days — 30 means an invoice is due thirty days after its date.
5. Set a **Credit limit** if the company works with one. It never blocks an invoice; it warns before posting.
6. Choose a **Default sales account** if this customer's sales always go to one account.
7. Select **Add customer**.

**Result:** the customer appears in the list with a zero balance, ready to invoice.

> **Tip:** A customer who is also a supplier is one contact with both boxes ticked — the same TIN and address, two balances.

> **Note:** A customer who has never been used can be deleted. One with entries is made inactive instead: open them and clear **Active**. They stay in the ledgers and reports where they have figures.

## Raise an invoice
**Who:** Bookkeeper or above prepares it; an accountant posts it.

1. Open **Sales › Invoices** and select **New invoice**.
2. Choose the **Customer**. The terms and the default sales account fill in.
3. Check the **Date** and the **Due date** (the date plus the customer's terms).
4. Type a **Reference** if the customer quotes a PO number, and a **Description** of the sale.
5. For each line: choose the **Account** the sale belongs to, type what it is for, the **Quantity** and the **Unit price**. The **Amount** works itself out, or type it directly.
6. Set each line's **VAT**: *VATable*, *VAT-exempt* or *Zero-rated*. The totals show the VATable sales, the exempt sales and the VAT.
7. Choose a **Department** on a line if the account asks for one.
8. Select **Save draft**, or **Save and post** if you may post.

**Result:** a draft invoice with no number yet.

## Post an invoice
**Who:** Accountant or above.

1. Open the draft invoice.
2. Check the customer, the date, the lines and the VAT.
3. Select **Post invoice** and confirm.

**Result:** the invoice takes its number (INV-000052 in the demo company), the entry posts to the sales journal, and the amount appears on the customer's account and in the aging.

### What goes into the books
A VAT-registered company invoicing 11,200.00 including 12 % VAT:

| Account | Debit | Credit |
|---|---|---|
| 1121 Accounts Receivable - Trade (the customer) | 11,200.00 | |
| 4110 Sales | | 10,000.00 |
| 2121 Output VAT | | 1,200.00 |

> **Note:** If the customer is over their credit limit, the screen says so before you post and you can still go ahead — it is a warning, not a wall.

## Record a receipt
**Who:** Bookkeeper or above prepares it; an accountant posts it.

1. Open **Sales › Receipts** and select **Record a receipt** (or use **Record receipt** on the customer's page or the invoice).
2. Choose the **Customer** and the **Date received**.
3. Choose **Deposited to** — the cash or bank account the money went into.
4. Type the **OR number** you issued, and the **Amount received**.
5. If the customer withheld tax, type it under **Tax withheld**. The invoice is settled by the cash plus the tax withheld.
6. Under **What it pays**, the open invoices are listed. Select **Fill oldest first** to apply the money from the oldest invoice down, or type the amounts yourself.
7. Select **Save draft**, or **Save and post** if you may post.
8. To post later: open it and select **Post receipt**.

**Result:** the receipt takes its number, the cash goes in, and each invoice it paid shows less open.

### What goes into the books
25,000.00 received, with 1 % (252.53) withheld by the customer against an invoice of 25,252.53:

| Account | Debit | Credit |
|---|---|---|
| 1113 Cash in Bank - Current Account | 25,000.00 | |
| 1145 Creditable Withholding Tax | 252.53 | |
| 1121 Accounts Receivable - Trade (the customer) | | 25,252.53 |

> **Tip:** Money received that does not settle anything yet — a deposit, an advance — is posted with nothing applied. It sits on the customer's account, and the invoice it belongs to can be applied later with **Apply to invoices**.

## Apply money to invoices
**Who:** Bookkeeper or above.

Open the receipt (or the credit note) and select **Apply to invoices**. The open invoices appear with the oldest filled first; change the amounts and select **Apply**.

Applying moves nothing in the ledger — the control account already holds the money. It only says which invoice it belongs to, which is what the aging reads.

To undo one, open the invoice or the receipt and select **Remove** beside the allocation, with a reason. The invoice opens again by that much.

## Credit notes
**Who:** Bookkeeper or above prepares; an accountant posts.

A credit note reduces what a customer owes — goods returned, an allowance, an invoice raised twice.

1. Open the invoice it corrects and select **New credit note** (or start one from **Sales › Invoices › New credit note**).
2. Check the customer and the **Corrects invoice** box.
3. Enter the lines the same way as an invoice — usually to a *Sales Returns and Allowances* account.
4. Save it, then **Post credit note**.

**Result:** it takes its own number (CN-…), posts the reverse of an invoice, and is applied to the invoice it corrects, up to what is still open on it.

### What goes into the books
| Account | Debit | Credit |
|---|---|---|
| 4120 Sales Returns and Allowances | 10,000.00 | |
| 2121 Output VAT | 1,200.00 | |
| 1121 Accounts Receivable - Trade (the customer) | | 11,200.00 |

## Cancel a posted invoice or receipt
**Who:** Accountant or above.

1. Open it and select **Cancel invoice** (or **Cancel receipt**).
2. Choose the date of the reversal and say why.
3. Confirm.

**Result:** its entry is reversed, it is marked cancelled, and anything it had settled is open again. It keeps its number, so the books show what happened.

The system refuses to cancel:

- an invoice that has money applied to it — remove the allocations first;
- a receipt whose cash line is matched to a bank statement — unmatch it in [Banking](09-banking.md) first.

## The reports

**Aging** (**Reports › Aging**) — every customer's open invoices at a date, in buckets by how far past due they are, with anything unapplied in its own column. The foot sets the total against the receivables control account in the ledger and lists any difference, line by line: that difference can only come from an entry posted straight to the control account by hand.

**Statement of account** (**Reports › Statement of account**) — one customer's charges and payments over a period, with the balance brought forward, the balance carried forward, and an aging summary. Print it and send it.

**Subsidiary ledger** (**Reports › Subsidiary ledger**) — every customer's balance on the control account at a date, adding up to the control account itself.

All three download as CSV and print with the letterhead and signature lines.

## Common problems

**"Only a draft can be posted."** Somebody posted it already — refresh the page.

**"1121 needs a customer."** A line on the control account has no customer. It happens on hand-written entries; open the entry and set it.

**"Remove what is applied to it first."** The invoice has receipts or credit notes against it. Open it, remove the allocations, then cancel.

**"Unmatch it in Banking first."** The receipt's cash line is matched on a bank statement. Open the statement, unmatch it, then cancel the receipt.

**The aging does not equal the control account.** The foot of the report lists the entries that caused it — almost always a journal entry posted straight to 1121 without going through an invoice. Reverse it and raise the document instead.
