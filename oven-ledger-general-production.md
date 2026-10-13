# General Production Flow

**Project:** Oven Ledger
**Status:** Proposed — a production model that can record and track any made good
**Date:** 12 October 2026

Production is a location-scoped order: define how a good is made, record a run, and post stock only when that run is finished. The same flow covers food, parts, packaged goods, and assemblies.

---

## 1. What can be produced

Any stocked good can be a production output. Each output has a unit of measure and, when it is made in-house, a **bill of materials**.

The bill of materials records:

- the inputs (materials, components, or other produced goods)
- the quantity of each input
- the expected output quantity for that bill

A good with no bill of materials is bought or received, and is not scheduled as a production run.

---

## 2. Schedule a run

A production run is created for the current location and starts as **planned**. It stores:

- the output good and its bill of materials
- planned quantity
- an optional lot number, expiry, or other lot attribute when the good needs one
- a run number, generated per day (`PR-YYYYMMDD-0001`)

Nothing is deducted or added to stock at this point. The run is a plan.

---

## 3. Track the run

Status moves only forward. The stages are generic, so the same sequence fits an oven, a workshop, or a packing line:

```
planned → in progress → complete → released
                ↑          |
                └── on hold
```

`on hold` is the only step that can move backward: it returns to `in progress`. Every other move goes forward.

| Status | Meaning |
|---|---|
| planned | Scheduled, not started |
| in progress | Work has started |
| on hold | Paused; can return to in progress |
| complete | Output quantity is confirmed |
| released | Finished goods have left this location (transfer or handover) |

Completing or releasing a run asks for the **actual quantity** made. If that is left blank, the planned quantity is used.

---

## 4. Post stock when the run is finished

Entering `complete` or `released` posts inventory once, in one locked transaction:

1. **Scale the bill.** Factor = actual output ÷ expected output on the bill.
2. **Consume inputs.** Each input quantity × factor is written as a negative stock movement, referenced to this run. The same input is not consumed twice for the same run.
3. **Receive output.** A finished-stock row is created for this location, good, quantity, and run number. That quantity is what can be sold, transferred, or used as an input to another run.

Moving from `complete` to `released` does not post again. The run number on the finished-stock row is the guard. A background job can retry the same post safely, and opening the production list backfills any finished run that was never posted.

Input stock may go below zero so a run can be recorded before a late goods receipt is entered. Yield variance is planned quantity versus actual quantity on the run.

```
Bill of materials
        ↓
Production run (planned → in progress → complete)
        ↓  on complete / released, once
Input movements (scaled)  +  finished stock (run number)
        ↓
Available to sell, transfer, or consume in another run
```

---

## 5. Direct receipt

Locations that do not run production skip runs. Staff receive a finished quantity straight onto the shelf. If that good has a bill of materials, the same scaled input consumption is written, referenced to the receipt instead of a run. Purchased goods are received with no consumption.

---

## 6. What stays traceable

Each run keeps:

- its number and location
- status history
- planned and actual quantities
- the input movements and the finished-stock row tied to that number

A bill already used by a run cannot be deleted. Notifications cover runs that are in progress or complete and still need a status update.

---

## 7. How a person navigates

The left sidebar is the way in. On a narrow screen, open it from the menu button in the top bar. A section with a submenu expands when its name is clicked. Work is always for the current location: open the account menu (the avatar) and change **Branch** before planning, receiving, or selling.

| To do this | Open |
|---|---|
| Define a stocked good | **Catalog** → **Items** |
| Write or open a bill of materials | **Production** → **Bills of materials**. From **Production lines**, **Manage recipes** opens the same list. |
| Attach a bill to a line | **Production** → **Production lines**, then **Create line** |
| Schedule a run | **Production** → **Production orders** (the page title is **Production runs**), fill the form, **Plan run**. Or, on an active line, **Plan batch** — that opens the same form with the line already chosen. |
| See every run | **Production** → **Production orders**, or **View batches** on a line |
| Start, hold, resume, complete, or release | Click the run’s row. The buttons for that status are on the run page. **Cancel** is there too, until the run is finished. |
| Receive without a run | **Inventory** → **Direct receipts**, then **Receive stock** |
| Sell | **Sell** → **Sales terminal** (the page title is **Point of sale**). A cashier who signs in lands here. |
| Open a finished sale, its receipt, or a return | Payment opens that sale. Later, type the sale number in **Search items or sales** at the top of the screen. **Receipt**, **Reprint**, and **Return sale** are on the sale page. |

A notification for a run that still needs a status update opens that run directly.

On the sales terminal, search, the product grid, the order, hold, resume, payment, and sync all stay on the same screen. **Hold order** parks the cart; **Resume a held order** brings it back when the current order is empty. Offline sales wait on that screen until **Sync**.

The first time a shared device is used as a till, **Set up a terminal** asks for a name, branch, and paper width before the sales screen is available.
