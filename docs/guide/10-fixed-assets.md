---
title: Fixed assets
summary: Keep the register of property and equipment, run the monthly depreciation, dispose of assets, and print the lapsing schedule that ties the register to the ledger.
---

# Fixed assets

The fixed-asset register lists everything the company owns and uses for more than a year: office equipment, furniture, vehicles, computers. For each asset it keeps the cost, the useful life and the depreciation charged so far. Once a month an accountant runs the depreciation, which posts one adjusting entry for every asset in use. When an asset is sold, scrapped or lost, its disposal posts the entry that takes it off the books and records any gain or loss.

The **lapsing schedule** puts the whole register on one page and checks it against the ledger, account by account.

## Before you start
- The chart of accounts needs, for each kind of asset, an asset account (such as **1218 Transportation Equipment**), a contra-asset account for its accumulated depreciation (such as **1219**) and an expense account for the depreciation (such as **6200 Depreciation Expense**). See [Chart of accounts](04-chart-of-accounts.md).
- An administrator sets the **Gain on disposal** and **Loss on disposal** accounts, the rule for when depreciation starts, and the asset number prefix in [Settings](15-administration.md#settings). The demo company uses 4920, 7200, "the month after acquisition" and the prefix FA.
- The month you post into must be open. See [Fiscal years and periods](05-fiscal-years-and-periods.md).
- Book the purchase itself the usual way, through a supplier bill ([Purchases and payables](08-purchases-and-payables.md)) or a journal entry ([Journal entries](06-journal-entries.md)). Registering an asset does not post anything.

## Who can do what
| Task | Viewer | Bookkeeper | Accountant | Administrator |
|---|---|---|---|---|
| See the register, the schedules and the lapsing schedule | Yes | Yes | Yes | Yes |
| Register, change or delete an asset | | Yes | Yes | Yes |
| Set up asset categories | | | Yes | Yes |
| Run or undo depreciation | | | Yes | Yes |
| Dispose of an asset, or take a disposal back | | | Yes | Yes |

## Set up asset categories
**Who:** Accountant or above.

A category groups similar assets. It names the three accounts its assets post to and suggests a method, a useful life and a residual value for new assets. The demo company has four: Office Equipment, Furniture and Fixtures, Transportation Equipment (5 years each) and Computer Equipment (3 years).

1. Open **Cash and assets › Fixed assets** and select **Categories**.
2. Select **New category**.
3. Type the **Name**, for example *Leasehold Improvements*.
4. Choose the **Asset account** (where the cost is kept), the **Accumulated depreciation account** (a contra-asset account) and the **Depreciation expense account**.
5. Choose the **Method**: *Straight line* charges the same amount every month; *Declining balance (double)* charges more in the early years.
6. Enter the **Useful life** in years or months, and the **Residual value** as a percentage of cost (0 is common).
7. Select **Add category**.

**Result:** the category appears in the list and can be chosen for new assets.

> **Note:** Changing a category's method, life or residual value affects only assets registered afterwards. Each asset keeps its own figures. Once a category has assets, its accounts are fixed; for different accounts, create a new category. To stop using a category, open it and clear **Active — new assets can be registered in it**. A category no asset has used can be deleted.

## Register an asset
**Who:** Bookkeeper or above.

1. Open **Cash and assets › Fixed assets** and select **New asset**.
2. Type the **Description**, for example *Honda delivery motorcycle*.
3. Choose the **Category**. Its method, useful life and residual value fill in; you can change them.
4. Enter the **Date acquired**. **Depreciation starts** fills in by the company's rule: the 1st of the next month in the demo company.
5. Choose the **Department** the depreciation should be charged to, and fill in **Location**, **Serial or plate no.** and **Supplier** if you like.
6. Under **Purchase entry**, choose the posted bill or journal entry that recorded the purchase. The list shows only entries that debit the category's asset account.
7. Enter the **Cost**: the price and the costs of getting it ready for use, without the input VAT the company claims.
8. Check the **Residual value**, **Useful life** and **Method**. The box below them shows about how much will be charged each month.
9. Leave **Accumulated depreciation brought in** at 0 for a new purchase.
10. Select **Register asset**.

**Result:** the asset gets the next number (the demo company's next one is FA-0005) and its page opens, with its depreciation schedule.

> **Tip:** Register an asset in the month you book its purchase. If you register it late, the next depreciation run catches up the months it missed, so nothing is lost.

### What goes into the books
Nothing. The purchase was already booked by the bill or journal entry, for example:

| Account | Debit | Credit |
|---|---|---|
| 1218 Transportation Equipment | 100,000.01 | |
| 2111 Accounts Payable - Trade (the supplier), or 1113 Cash in Bank | | 100,000.01 |

### Assets the company already had at go-live
When the books start in this system, the opening balances already hold the cost of the old assets and the depreciation charged on them before. Register each of those assets with its original **Date acquired**, its original **Depreciation starts** month, its **Cost**, and in **Accumulated depreciation brought in** the depreciation charged before go-live. The demo company's truck, FA-0003, cost 1,200,000.00, started depreciating in May 2024, and brought in 400,000.00.

> **Note:** If the amount brought in is more than the straight line would have charged by then, the next months charge nothing until the line catches up. If it is less, the first run catches up the difference. The asset page shows the months ahead before anything is posted.

## Change or delete an asset
**Who:** Bookkeeper or above.

1. Open the asset from **Fixed assets** and select **Edit**.
2. Change what you need and select **Save changes**.

Once depreciation has been charged on an asset, its category, cost, residual value, useful life, method, depreciation start and accumulated depreciation brought in are fixed, because the posted entries were worked out from them. The form shows them locked and says why. The description, department, location, serial number, supplier, purchase entry and notes can still change. A disposed asset keeps its department too.

An asset that has no depreciation and has not been disposed of can be removed with **Delete**, for example one registered twice by mistake. Its number is not used again.

## Read an asset's page
The page shows the cost, the accumulated depreciation (including any brought in at go-live), the net book value and what is left to depreciate. Below are its details and its accounts, with a link to the purchase entry.

The **Depreciation schedule** shows each year under **By year**, or each month under **By month**. Months already charged link to the depreciation entry that charged them. Months marked **Projected** are still to come, worked out exactly as the next run will work them out. **History** lists who registered and changed the asset, from the audit log.

## Run depreciation each month
**Who:** Accountant or above.

1. Open **Cash and assets › Depreciation**.
2. Under **Run depreciation**, choose the **Month**. The first month that can still be run is already chosen.
3. Select **Preview**.
4. Check each asset's charge for the month, the accumulated depreciation and book value after it, and the journal entry below.
5. Select **Post depreciation for Sep 2026** (the button names the month), then **Post depreciation** to confirm.

**Result:** one adjusting entry is posted, dated the last day of the month, with the reference DEP-2026-09 and the description "Depreciation for September 2026". The run appears at the top of **Runs**. Assets that reach the end of their depreciation show as **Fully depreciated**.

### What goes into the books
The demo company's September run:

| Account | Debit | Credit |
|---|---|---|
| 6200 Depreciation Expense — Computer Equipment (ADM) | 4,166.65 | |
| 6200 Depreciation Expense — Furniture and Fixtures (ADM) | 3,000.00 | |
| 6200 Depreciation Expense — Office Equipment (ADM) | 7,500.00 | |
| 6200 Depreciation Expense — Transportation Equipment (WHS) | 20,000.00 | |
| 1222 Accumulated Depreciation - Computer Equipment | | 4,166.65 |
| 1217 Accumulated Depreciation - Furniture and Fixtures | | 3,000.00 |
| 1215 Accumulated Depreciation - Office Equipment | | 7,500.00 |
| 1219 Accumulated Depreciation - Transportation Equipment | | 20,000.00 |

There is one debit line for each category and department, and one credit line for each category. The memo on each line names the assets.

### Which assets a run depreciates
- Assets in use (not fully depreciated, not disposed of) whose depreciation has started by that month.
- Depreciation starts in the month shown on the asset, the month after acquisition by the demo company's rule.
- **Nothing is depreciated in the month an asset is disposed of, or after.** A run made after a disposal leaves the asset out.
- An asset is never depreciated below its residual value.

### How the monthly amount is worked out
**Straight line.** Each month brings the accumulated depreciation up to where a straight line from the start would be. The laptops (FA-0004) cost 150,000.00 over 36 months, so the line reaches 4,166.67 after one month, 8,333.33 after two, and exactly 150,000.00 after 36. Some months charge 4,166.67 and some 4,166.66, and the total is always exactly the cost less the residual value. A month that was never run is caught up by the next run. The demo company's earlier runs charged the laptops 4,166.67 every month, 2 centavos ahead of the line after August, so September charges 4,166.65.

**Declining balance (double).** Each month charges twice the straight-line rate on the book value left, for example 2 ÷ 60 of it for a five-year asset. When spreading what is left evenly over the remaining months gives more, it charges that instead. The last month of the life takes exactly what is left, so the asset ends on its residual value.

> **Tip:** Run depreciation every month before you close it; see [Month-end and year-end](13-month-end-and-year-end.md). Runs go in order, so once October has been run, September can no longer be run.

## Undo a depreciation run
**Who:** Accountant or above.

Undo a run if it was posted too early or an asset's figures were wrong.

1. Open **Cash and assets › Depreciation**.
2. In **Runs**, select **Undo** on the latest run.
3. Say why and select **Undo the run**.

**Result:** the run's entry is reversed by a mirror entry dated the same day, its charges come off every asset, and the month can be run again. Both entries stay in the ledger, linked to each other.

Only the latest run can be undone, and only while its month is open. A run cannot be undone after an asset it depreciated has been disposed of; take that disposal back first.

## Dispose of an asset
**Who:** Accountant or above.

1. Open the asset from **Fixed assets** and select **Dispose of**.
2. Enter the **Date of disposal**. It cannot be in the future.
3. Enter the **Proceeds**, or 0 if the asset was scrapped, lost or given away.
4. If there are proceeds, choose where they went under **Received into**: a cash or bank account, or a receivable. If you choose **1121 Accounts Receivable - Trade**, also choose the **Customer**.
5. Type who bought it under **Sold to** (optional), and what happened under **What happened**.
6. Check the book value, the gain or loss, and the entry shown at the bottom.
7. Select **Post the disposal**.

**Result:** the entry is posted in the general journal, the asset shows as **Disposed**, and its page shows the disposal with a link to the entry.

### What goes into the books
The Isuzu truck (FA-0003) cost 1,200,000.00. Its depreciation was charged through August: 560,000.00. It is sold for 700,000.00 on 14 September, so its book value is 640,000.00 and the gain is 60,000.00:

| Account | Debit | Credit |
|---|---|---|
| 1219 Accumulated Depreciation - Transportation Equipment | 560,000.00 | |
| 1113 Cash in Bank - Current Account | 700,000.00 | |
| 1218 Transportation Equipment | | 1,200,000.00 |
| 4920 Gain on Disposal of Property and Equipment | | 60,000.00 |

The office furniture (FA-0002) cost 180,000.00 and has 78,000.00 accumulated. It is sold for 50,000.00, below its book value of 102,000.00, so the loss is 52,000.00:

| Account | Debit | Credit |
|---|---|---|
| 1217 Accumulated Depreciation - Furniture and Fixtures | 78,000.00 | |
| 1113 Cash in Bank - Current Account | 50,000.00 | |
| 7200 Loss on Disposal of Property and Equipment | 52,000.00 | |
| 1216 Furniture and Fixtures | | 180,000.00 |

The accumulated depreciation removed includes what was brought in at go-live. A gain or loss is charged to the asset's department.

> **Warning:** Record the disposal before you run depreciation for that month. If the month's run already includes the asset, the disposal is refused: undo that run, dispose of the asset, then run the month again. To charge depreciation for the months before the disposal, run those months first.

## Take a disposal back
**Who:** Accountant or above.

1. Open the disposed asset and select **Take the disposal back**.
2. Say why and select **Take it back**.

**Result:** the disposal entry is reversed on its own date (that month must still be open), and the asset is in use again. If runs were made while it was disposed of, the next run catches up its straight-line depreciation.

## The lapsing schedule
**Who:** Everyone.

The lapsing schedule shows every asset held during a period, grouped by category, one row each: the number, name, date acquired and useful life; the cost at the beginning, additions, disposals and cost at the end; the accumulated depreciation at the beginning, the depreciation for the period, the accumulated depreciation on disposals and at the end; the book value at the end; and the months of life left. Each category has a subtotal, and there is a grand total.

1. Open **Cash and assets › Fixed assets** and select **Lapsing schedule**.
2. Choose the period: **Year to date** is shown first. Pick another from the list, or type the dates.
3. Read the **Tie-out to the ledger** below the schedule.
4. Select **Print** for a landscape copy with the letterhead and signature blocks, or **Download CSV** for a spreadsheet.

**Result:** the tie-out compares, for each category account, the register's figure with the ledger balance on the end date. **Ties** means they agree. A difference, register less ledger, points to one of these:
- an asset bought through a bill or journal entry but never registered;
- an asset registered but its purchase never booked;
- an entry posted to the asset or accumulated depreciation account by hand;
- a balance on a property account no category uses, listed as *Not linked to any asset category*.

The demo company's register ties to the ledger to the centavo.

> **Tip:** Auditors usually ask for the lapsing schedule for the whole year. Choose the fiscal year's **whole year** option, print it, and file it with the year-end papers. The register itself also downloads as a spreadsheet from **Fixed assets › Download CSV**.

## Co-operatives
Everything works the same way in a co-operative. The co-operative chart numbers its accounts differently: property accounts sit under **12200**, with their accumulated depreciation accounts (12225, 12235, 12245) next to them. Depreciation is an expense in the statement of operations, so it reduces the net surplus. See [Co-operatives](14-cooperatives.md).

## Common problems
**"Sep 2026 already has a depreciation run (AJ-2026-00028). Undo it first to run the month again."** Each month is run once. To run it again, undo the run first.

**"Depreciation has already been run for Oct 2026. Runs go month by month, so Sep 2026 can no longer be run."** A later month has a run. For straight-line assets, that later run already caught up the month you skipped.

**"Nothing to depreciate in Sep 2026: no asset in use is due for depreciation that month."** Every asset is fully depreciated, disposed of, or starts later. Nothing is posted.

**"Sep 2026 is closed. An accountant can reopen it on the Fiscal years screen."** Runs, undos and disposals post into the month they are dated in, which must be open.

**"Only the latest run can be undone. Undo Oct 2026 first."** Undo runs from the newest back.

**"FA-0003 was disposed of on 2026-09-14 with this run's depreciation counted. Undo that disposal first."** Take the disposal back, undo the run, then dispose of the asset again.

**"The depreciation run for Sep 2026 includes FA-0002, and nothing is depreciated in the month of disposal or after."** Undo September's run, post the disposal, then run September again.

**"Fixed: depreciation has already been charged on this asset."** Its cost, life and the other figures depreciation used can no longer change. If a figure was wrong, undo the runs that charged it while their months are open, correct the asset, and run again.

**"PJ-2026-00012 does not debit 1218 Transportation Equipment. Link the bill or entry that recorded the purchase."** The purchase entry must debit the category's asset account. Pick another entry, or check the asset's category.

**"Set the gain on disposal account in Settings → Account defaults first (an administrator can)."** An administrator sets the gain and loss on disposal accounts in [Settings](15-administration.md#settings).

**"This category has assets, so its accounts are fixed. Create a new category for different accounts."** Create a new category and register new assets in it.

**"The acquisition date cannot be in the future."** or **"The disposal date cannot be in the future."** Enter the date it actually happened.

**The lapsing schedule shows a difference.** Read the tie-out: register the asset that was bought but not registered, or book the purchase of an asset that was registered but not booked.
