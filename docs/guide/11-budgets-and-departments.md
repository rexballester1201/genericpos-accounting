---
title: Budgets and departments
summary: Set up departments, build a budget month by month, approve it, and compare the year against it — for the whole company or department by department.
---

# Budgets and departments

**Departments** are the branches, divisions or cost centres the company reports by. They sit on journal lines, on invoice and bill lines, and on budgets. **Budgets** are what the company planned to earn and spend, month by month, account by account — the figure the actual results are measured against.

Neither posts anything to the ledger. They shape how the ledger is read.

## Before you start
- An administrator adds departments; an accountant keeps the budgets.
- A budget belongs to one fiscal year, so open the year first ([Fiscal years and periods](05-fiscal-years-and-periods.md)).
- To report by department, the entries have to carry one. Mark the accounts that always need a department in the [chart](04-chart-of-accounts.md) — then the system refuses a line without one.

## Who can do what
| Task | Viewer | Bookkeeper | Accountant | Administrator |
|---|---|---|---|---|
| See departments, budgets and the reports | Yes | Yes | Yes | Yes |
| Add, change or deactivate a department | | | | Yes |
| Create a budget, fill it in, import or export it | | | Yes | Yes |
| Approve a budget, return it to draft, make it the primary one | | | Yes | Yes |

## Add a department
**Who:** Administrator.

1. Open **Planning › Departments** and select **New department**.
2. Type a short **Code** (ADM, SLS, WHS) and the **Name**.
3. Choose a **Parent** if it sits under another.
4. Select **Add department**.

**Result:** it can be chosen on entry lines, invoice and bill lines, assets and budgets.

Each department shows its income, expenses and result for the year so far, and links to the income-by-department report.

> **Note:** A department that has never been used can be deleted; one with entries is deactivated instead. Its figures stay in the reports.

## Create a budget
**Who:** Accountant or above.

1. Open **Planning › Budgets** and select **New budget**.
2. Choose the **Fiscal year** and give it a **Name** — *Original budget*, *Revised August*, *Board approved*.
3. Choose how to start it:
   - **Blank** — every month at zero;
   - **A copy of another budget** — start from last year's, or from a draft;
   - **Last year's actuals** — what really happened last year, with a percentage uplift if you like.
4. Select **Create budget**.

**Result:** the budget opens as a **draft** you can fill in.

## Fill in the grid
**Who:** Accountant or above, while the budget is a draft.

The grid has one row per income and expense account and one column per month, plus a total.

1. Choose **Company-wide** or a single **department** at the top. Company-wide figures are the ones not attached to any department; a department's figures are budgeted separately.
2. Type the amounts. They are positive: a revenue account holds what you expect to earn, an expense account what you expect to spend.
3. The tools at the end of each row save typing:
   - **Spread over 12 months** — type the year's figure and it is divided evenly, with the odd centavos put in the first months so the twelve add up exactly;
   - **Copy the first month across**;
   - **Clear the row**.
4. Select **Save changes**. **Discard changes** puts back what was last saved.

**Result:** the section totals and the planned net income at the foot follow what you type.

> **Tip:** Budget only the accounts that matter. A row left at zero is simply not budgeted, and the report shows the actual figures against nothing.

## Import and export
**Who:** Accountant or above.

**Download CSV** gives the grid with a column per month. Change it in a spreadsheet and bring it back with **Import** into a draft budget; rows it cannot match are skipped and listed, and nothing else is touched.

## Approve a budget
**Who:** Accountant or above.

1. Open the budget and select **Approve**.
2. Confirm.

**Result:** the budget is read-only. To change it again, select **Return to draft** and say why — both are in the audit log.

**Set as primary** marks the budget the reports use by default. One per fiscal year.

## Budget vs actual
**Where:** Reports › Budget vs actual.

Choose the budget, the months (a month, a quarter, the year to date, the whole year) and a department, and the report puts the actual figures beside the budget, in the same shape as the income statement:

- **Actual**, **Budget**, **Variance** and **Variance %** for every line;
- whether the variance is **favourable** — more income than planned, or less expense;
- totals for income, expenses and the result.

Actual figures leave out the closing entries, so the comparison still works in a year that has been closed.

## Income by department
**Where:** Reports › Income by department.

Every department in its own column, plus one for what carries no department, and a total. The total column is the company's income statement for the same period, to the centavo — which is the check that nothing is filed against the wrong department.

Both reports print (landscape) and download as CSV.

## Common problems

**"That department has entries and cannot be deleted."** Deactivate it instead; the figures stay where they are.

**"An approved budget cannot be changed."** Select **Return to draft** first.

**"Enter an amount of zero or more."** Budgets are kept in the account's normal direction; a negative figure means the account is wrong for the line.

**The department columns do not add up to the income statement.** Some entries carry no department; they are in the *No department* column. If that column holds more than it should, the entries need the department set — reverse and repost, or post a correcting entry.
