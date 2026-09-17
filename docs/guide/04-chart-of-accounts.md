---
title: Chart of accounts
summary: The list of accounts everything is posted to — how it is arranged, how to add to it, and what can no longer be changed once an account has been used.
---

# Chart of accounts

Every figure in the books sits in an account, and the chart of accounts is the list of them. It is arranged as a tree: **headers** group accounts and take no entries themselves; the accounts under them are the ones you post to.

The chart the company started with came from the template chosen at setup — a business chart, or a co-operative one following the CDA's standard chart. Most companies add a few accounts in the first weeks and then leave it alone.

## Before you start
- Only an administrator can change the chart.
- Think before adding: a new account is easy, but an account that has been posted to can never be deleted, and its type can never change.

## Who can do what
| Task | Viewer | Bookkeeper | Accountant | Administrator |
|---|---|---|---|---|
| See the chart and each account's balance | Yes | Yes | Yes | Yes |
| Add, change, deactivate or delete an account | | | | Yes |

## What an account carries

| Field | What it means |
|---|---|
| **Code** | Up to 20 letters, digits, dots or dashes. It orders the chart and prints on every report. |
| **Name** | What people call it. |
| **Type** | Asset, liability, equity, income or expense. It decides which statement the account appears in and which side is its normal balance. |
| **Header** | A heading that groups others and takes no entries. |
| **Parent** | The header it sits under. |
| **Subtype** | Current or non-current for assets and liabilities; capital, retained, reserve, drawing or other for equity; operating or other for income; cost of sales, operating, finance, other or income tax for expenses. It decides where the account appears in the classified statements. |
| **Cash flow** | Operating, investing, financing or cash. The cash-flow statement uses it; *cash* marks the accounts that are the cash the statement explains. |
| **Control** | Receivables (ar) or payables (ap). A line on a control account must name its customer or supplier — that is what keeps the subsidiary ledgers equal to the control accounts. |
| **Needs a department** | Every line on the account must name a department. |
| **Contra** | An account that sits against another, like accumulated depreciation against the asset it belongs to. Its normal balance is the opposite of its type's. |

## Add an account
**Who:** Administrator.

1. Open **Ledger › Chart of accounts**.
2. Select **New account**.
3. Type the **Code** and **Name**.
4. Choose the **Type**, then the **Parent** header it belongs under.
5. Set the **Subtype** and the **Cash flow** if the statements should place it precisely.
6. Tick **Header** only if it takes no entries.
7. Tick **Needs a department** if every line on it must name one.
8. Select **Add account**.

**Result:** the account appears in the tree under its parent and can be used at once.

> **Tip:** Keep the codes in the same shape as the rest of the chart — four digits for a business, five for a co-operative — so the account sorts where people expect it.

## Change an account
**Who:** Administrator.

Open the account and change what you need. What can still be changed depends on what has happened to it:

| Once the account… | You can still change | You cannot change |
|---|---|---|
| has no entries at all | everything | — |
| has draft entries only | everything | — |
| has posted entries | the name, the parent, the subtype, the cash-flow class, whether it needs a department | the code's meaning is kept, the **type**, the **normal side** and the **control** flag are frozen |

> **Note:** The reason is simple: a posted entry is part of the books. If an account could change from an expense to a liability afterwards, every statement printed before that would be wrong, with nothing to show why.

## Stop using an account
**Who:** Administrator.

An account that has been posted to is never deleted. Open it and clear **Active**. It stays in the reports where it has figures, but nobody can post to it again.

The system refuses to deactivate an account that:

- still has a balance (move it first to the account that replaces it),
- has activity in a fiscal year that is still open (the year-end closing has to clear it first),
- sits on an entry that is waiting to post,
- is named in **Settings › Account defaults** (point that setting at another account first).

An account that was never used at all can be deleted outright.

## Import a chart
**Who:** Administrator. **Where:** Administration › Imports › Chart of accounts.

A spreadsheet with **code**, **name**, **type**, and optionally **parent code**, **header**, **subtype**, **cash flow**, **control**, **needs a department** and a description. Download the template on that screen, fill it in, check it, and import. Codes already in the chart are left alone. See [Imports](15-administration.md#imports).

## Common problems

**"Another account already uses that code."** Codes are unique. Look the code up first; the account you want may already exist, deactivated.

**"1121 is a header account."** Headers take no entries. Post to one of the accounts under it.

**"This account still has a balance of 12,500.00 (debit)."** Deactivating would leave the balance stranded. Post an entry that moves it to another account first.

**"This is the receivables control account in Settings → Account defaults."** Point that setting at another account, then deactivate this one.
