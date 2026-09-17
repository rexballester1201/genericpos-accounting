---
title: Fiscal years and periods
summary: Open a fiscal year, close a month when it is finished, reopen one when something late arrives, and lock a month for good.
---

# Fiscal years and periods

A **fiscal year** is twelve monthly **periods**. An entry can be dated only inside a period, and it can only post while that period and its year are open. That single rule is what stops last quarter's figures changing after the reports have gone out.

The life of a month:

**open** → **closed** (month-end) ⇄ **open** again (an accountant reopens it) → **locked** (final, and never reopened)

## Before you start
- An administrator opens fiscal years; an accountant closes and reopens months; only an administrator locks one.
- Years follow on from each other: the first can start anywhere, and every year after it starts the day after the last one ends.

## Who can do what
| Task | Viewer | Bookkeeper | Accountant | Administrator |
|---|---|---|---|---|
| See the years and months and what is in them | Yes | Yes | Yes | Yes |
| Close a month, reopen a closed month | | | Yes | Yes |
| Lock a month for good | | | | Yes |
| Open a fiscal year | | | | Yes |

## Open the first fiscal year
**Who:** Administrator.

1. Open **Ledger › Fiscal years and periods**.
2. Choose the **month** the year starts and type the **year**.
3. Select **Open it**.

**Result:** the year and its twelve months appear, all open, and entries can be dated in them.

## Open the next fiscal year
**Who:** Administrator.

1. Open **Ledger › Fiscal years and periods**.
2. Select **Open the year from …** at the top right — the date is fixed: the day after the last year ends.
3. Confirm.

> **Tip:** Open the next year before the current one ends, so entries dated in January can be prepared in December. Opening a year posts nothing.

## Close a month
**Who:** Accountant or above.

1. Open **Ledger › Fiscal years and periods**.
2. Find the month and select **Close**.
3. Confirm.

**Result:** nothing can be posted into that month until it is reopened. The month shows who closed it and when.

A month refuses to close while entries in it are still waiting — drafts, or entries submitted but not approved. The tile says how many; post, reject or cancel them first.

> **Tip:** Work through [the month-end list](13-month-end-and-year-end.md) before closing: bank reconciliations, depreciation, accruals, then the reports.

## Reopen a month
**Who:** Accountant or above.

1. Find the closed month and select **Reopen**.
2. Confirm.

**Result:** entries can be posted into it again. Reopening is recorded in the audit log, with who did it.

> **Note:** A month inside a **closed fiscal year** cannot be reopened on its own — reopen the year first ([Month-end and year-end](13-month-end-and-year-end.md)).

## Lock a month for good
**Who:** Administrator.

1. Find the closed month and select **Lock**.
2. Confirm — this cannot be undone.

**Result:** the month can never be reopened, and nothing can ever be posted into it. Lock a month once its figures are final: after the audit, or once the returns for it have been filed.

## What each month shows

Each tile carries the month, its state, how many entries are posted in it, how many are still waiting, how many were rejected, and who closed it. Select the counts to see those entries.

## Common problems

**"3 entries are still waiting in Aug 2026. Post, reject or cancel them first."** Open the link on the tile, then deal with each entry.

**"Sep 2026 is closed. An accountant can reopen it."** You are trying to post into a closed month. Either date the entry in an open month, or ask an accountant to reopen that one.

**"FY2026 is closed."** The whole year is closed. Reopening is done from **Ledger › Year-end closing**, by an administrator.

**"The next fiscal year must start on 2027-01-01."** Years follow on from each other; the date is not yours to choose.
