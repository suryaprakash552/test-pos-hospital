# NexDine POS — Completion Queue Status

Machine-readable progress marker for the autonomous completion queue.
A session starting fresh should read this, verify the baseline, and resume from
**Next queue item**.

---

## Verified baseline (measured this session, not carried over)

```
Flutter analyzer errors      0
Flutter analyzer warnings    0
Flutter analyzer infos       0
Flutter tests              742   passing
Laravel tests             1069   passing
Postman contract audit     103 write requests, 0 missing fields
Production suppressions      0   (no ignore_for_file / // ignore: in hand-written lib/)
```

`lib/generated/**` is excluded in `analysis_options.yaml` — machine-generated
intl output, rebuilt from ARB files, where edits are overwritten on the next
regeneration. No lint rule is disabled. Including it, the figure is 162.

---

## Current phase

Phase 1 — P0/P1 functional queue.

## Current item

**F06 — Batch dates** — date-fill half closed by F05.5; selection UX outstanding.
UI Phase 2 (error-handling UX) closed below.

## Status of Phase 1

| ID  | Item | Status |
|-----|------|--------|
| F01 | Category loading | **DONE — verified** |
| F02 | Inventory delete refresh | **NOT REPRODUCIBLE — traced** |
| F03 | Add Product settings | regression only — covered by `ProductSettingsSaveTest` (8 tests) |
| F04 | Quantity input | **FIXED — verified** |
| F05 | Loyalty Program | **FIXED — verified** |
| F05.5 | Searchable pickers audit | **DONE — batch date-fill fixed** |
| UI-P2 | Error-handling UX (raw errors) | **DONE — 0 exception leaks remain** |
| UI-P3 | Dialogs / confirmations | **DONE — see below** |
| UI-P4 | Bottom sheets | **DONE — 13 live sheets, all safe-area + keyboard-safe** |
| UI-P5 | Inputs / forms (correctness) | **DONE — 33 leaked controllers disposed** |
| UI-P5B | Inputs / forms (visual) | **IN PROGRESS — H, I, A, G, B, E done; C, F, J open** |
| UI-GD | Filter / search / dropdown / calendar | **DONE — date pickers unified; see below** |
| UI-GC | Bottom sheets / popups / action menus | **DONE — popup theme overrides removed; see below** |
| UI-GF | Context safety / async lifecycle | **COMPLETE — 76 -> 0** |
| F06 | Batch dates | NEXT (date-fill done; selection UX outstanding) |
| F07 | Relationship filter | not started |
| F08 | Customer duplicate rules | not started — see open decision below |
| F09 | Expected Handover | not started |
| F10 | Add All | not started |
| F11 | UPI QR exclusivity | renderer precedence already correct; **configuration UI not yet verified** |
| F12 | Doctor invoice | **DONE** — app screen fixed; PDF path not yet checked |
| F13 | Stock Audit | regression only — covered by `stock_audit_parsing_test.dart` (10 tests) |
| F14 | Rack/Shelf | crash fixed earlier; no behavioural test yet |
| F15 | Due payment | covered by `DueCollectionPaymentMethodTest` (10) + `payment_type_default_test.dart` (3) |
| F16 | Sale billing | covered by `SaleInvoicePersistenceTest` (6) + existing financial tests |
| F17 | Purchase billing | **no equivalent persistence test exists** — gap |
| F18 | Expense | covered by `ExpenseVisibilityTest` (6) |

---

## F01 — Category loading (completed this session)

**Root cause.** All three selling screens rendered `error: (_, __) => const
SizedBox.shrink()` for a failed category request — an empty strip, visually
identical to a shop with no categories, with nothing to press. Products below
still loaded, so the screen looked healthy while one of its two filters had
silently vanished. The purchase sidebar showed a bare `wifi_off` icon with no
label and no retry.

Loading skeletons and the mobile chip rail were already correct
(`CategoryChipBar` + `AppListSkeleton`), so only the error path was outstanding.

**Change.** New `lib/core/widgets/category_rail_error.dart` with
`CategoryRailError` (56–60dp rail) and `CategorySidebarError` (tablet/desktop
column). Deliberately not `ErrorState`, which fills a page. Both name the failure
and offer a retry that calls `ref.invalidate(categoryProvider)`.

**Files changed**

```
lib/core/widgets/category_rail_error.dart      (new)
lib/core/widgets/widgets.dart                  (export)
lib/Screens/pos_sale/pos_sale.dart             (rail + sidebar)
lib/Screens/Purchase/purchase_products.dart    (rail + sidebar)
lib/Screens/Sales/sales_products_list_screen.dart (rail)
test/core/category_rail_error_test.dart        (new, 6 tests)
```

**Verification.** 6 new tests, including a 360px overflow check and a rail-height
check so the grid below does not jump between states. Full suite 676 → 682,
analyzer unchanged at 77.

---

## F02 — Inventory delete refresh (traced this session, no change needed)

The flow is already correct end to end. The delete does not live on a
`deleteProduct` symbol in the screen, which is why an earlier grep missed it — it
runs through `runProductDelete` in
`lib/Screens/Products/Widgets/product_delete_blocked_dialog.dart`.

```
PopupMenu delete
  -> showDeleteAlert (confirm)          product_list_screen.dart:945
  -> runProductDelete                   product_delete_blocked_dialog.dart:15
       (refuses while the product has transaction history,
        offers the owner-authorised override)
  -> ProductRepo invalidates productProvider   product_repo.dart:595
  -> _loadPage(pageToLoad, search: ...)  product_list_screen.dart:963
```

Three details that were already handled and are worth not regressing:

* `isLastItemOnPage` steps back a page when the deleted row was the only one
  left, instead of leaving an empty page.
* The active search string is passed back into `_loadPage`, so the filter
  survives the delete.
* `_loadPage` carries a `_queryGeneration` guard, so a slow reload cannot
  overwrite a newer query.

No manual refresh is required and no change was made. Adding invalidation here
would have been the speculative refresh the brief warns against.

---

## F04 — Quantity input (completed this session)

**What was already there.** Both carts already had the full control — `−`,
an editable `TextField`, `+`, commit-on-blur, a `_committing` re-entry guard, and
a fixed-width cell so the row does not jump while editing. `pos_sale.dart` has no
quantity control of its own; its cart delegates to the same sales cart screen, so
there was no third copy to reconcile.

**Root cause of the defect.** The commit logic existed twice and the two copies
disagreed on the floor:

```
purchase  add_and_edit_purchase.dart:1205   entered.clamp(1.0, maxQty)   correct
sales     sales_cart_screen.dart:1201       entered.clamp(0.0, maxQty)   bug
```

Typing `0` into the sales quantity field left a **zero-quantity line in the
cart** — listed, priced at nothing, and counted as a line. No other route could
produce that state: `CartNotifier.quantityDecrease` stops at 1, so the stepper
and the text field disagreed about the same value depending on which the user
touched. The `−` button also rendered enabled at quantity 1 while doing nothing,
because its guard read `> 0` and the notifier's read `> 1`.

**Change.** New `lib/core/cart/quantity_rules.dart` holding the rule once:
`kMinCartQuantity`, `clampCartQuantity()`, `parseCartQuantity()`. Both carts call
it. The sales `−` button now greys out at the minimum instead of looking live.

Deliberately *not* done: extracting the two `_TableQtyControl` widgets into one
shared widget. They read different models (`SaleCartModel.quantity` versus
`PurchaseCartModel.quantities` + `conversionQuantities` +
`recalculateItemFields`), so unifying the widgets means restructuring two
financial screens. The duplication that actually caused this bug was the *rule*,
and the rule is now single-sourced. The widget-level merge is a legitimate
follow-up but was not needed to close the defect.

**Boundary behaviour pinned by tests**

| input | result | why |
|---|---|---|
| `0`, `-3` | 1 | the defect |
| `2.5` | 2.5 | conversion units are not whole |
| `9999` with stock 12 | 12 | stock ceiling |
| stock `0` | 1 | ceiling cannot meet the minimum; the caller refuses out-of-stock adds |
| stock `null` / infinite | uncapped | services and untracked products |
| `''`, `'   '`, `'abc'` | unchanged | clearing the field is how a number is retyped |
| `NaN`, `infinity` | unchanged | malformed input must not reset the line |

**Files changed**

```
lib/core/cart/quantity_rules.dart                (new)
lib/Screens/Sales/sales_cart_screen.dart         (commit + button guard)
lib/Screens/Purchase/add_and_edit_purchase.dart  (shares the rule)
test/core/cart_quantity_rules_test.dart          (new, 22 tests)
```

**Verification.** 22 new tests. Full suite 682 → **704**, analyzer unchanged at
77, 0 errors. One test failed on first run and was right to: I had documented
`stock <= 0` as "unlimited", which would have left a genuinely out-of-stock
product with no cap. Only `null`/non-finite means unlimited now.

**Not verified without a device:** on-screen keyboard behaviour and the 360/390/412
layouts. The fixed-width quantity cell and `_committing` guard are unchanged from
the working implementation, so no regression is expected, but this is a
device-blocked item rather than a claim.

---

## F05 — Loyalty Program (completed this session)

**Backend: complete, unchanged.** Full contract already present —
`loyalty-subscriptions` store/renew/upgrade/cancel/refund,
`customers/{customer}/loyalty` for read-back. Duplicate protection is enforced
server-side inside the transaction: `LoyaltySubscriptionManager::subscribe`
calls `activeFor()` and throws, surfacing as 422 *"already has an active loyalty
package"*. No backend change was made, so `php artisan test` was not re-run.

**Create path: already correct.** `_loyaltyPicker()` renders at
`add_customer.dart:827` gated on `groupValue != 'Supplier'`, hides itself when
the shop has no packages configured, and the create branch subscribed after the
customer existed, treating loyalty as secondary (a failure is reported, the
customer is not undone).

**Root cause of the defect: edit was never wired.** The picker rendered in edit
mode — it is not gated on `isFromEdit` — but nothing loaded the customer's
existing subscription, so it always opened blank, and the subscribe call existed
only on the *create* branch. Choosing a package while editing accepted the input
and discarded it. A customer already on a programme appeared to be on none.

**Change**

* `_loadExistingLoyalty()` reads `LoyaltyRepo().forCustomer(id)` after the
  package list loads and preselects by **id**, so a held package that is now
  inactive (and therefore absent from the active-only picker list) still shows.
* `_applyLoyaltyChange()` runs on both save branches.
* New `lib/core/loyalty/loyalty_change.dart` holds the decision as a pure
  function, because getting it wrong is expensive in one specific way: calling
  `subscribe` for a customer who already holds a package is exactly what the
  server refuses, so the mistake surfaces as a 422 the cashier cannot act on.

| held | selected | operation |
|---|---|---|
| none | none | none |
| same | same | none — editing a phone number must not re-send a subscription |
| none | package | `subscribe` |
| package (live) | different | `upgrade` — **not** a second subscribe |
| package (live) | cleared | `cancel` |
| lapsed, no subscription id | different | `subscribe` — nothing live to move |
| lapsed, no subscription id | cleared | none — nothing live to cancel |

* Picker shows an `AppSkeleton` while the existing state loads instead of
  vanishing.

**Semantics preserved** (criterion 10): loyalty stays a secondary operation on
both paths. The customer saves regardless; a loyalty failure reports *"Changes
saved, but the loyalty program was not updated"* rather than claiming the save
failed. No raw exception or API body reaches the user —
`LoyaltyRepo.forCustomer` never throws by design and the mutation helpers return
a safe message or null.

**Files changed**

```
lib/core/loyalty/loyalty_change.dart        (new)
lib/Screens/Customers/add_customer.dart     (load, reconcile, picker skeleton)
test/core/loyalty_change_test.dart          (new, 8 tests)
```

**Verification.** 8 new tests. Full suite 704 → **712**, analyzer unchanged at
77, 0 errors. One test failed first run — its name said "no-op" while its
expectation said `cancel`; the implementation was right and the expectation was
mine to fix.

**Not verified without a device or seeded data:** that the picker appears for a
shop with packages configured and hides for one without, and the live round trip
create → reload → change → cancel. The visibility gate is
`_loyaltyPackages.isEmpty`, driven by the real `loyalty-packages` endpoint, so it
is exercised only against a server with data. Marked device/data-blocked rather
than claimed.

---

## F05.5 — Searchable dropdowns / pickers (completed this session)

**Audit result: the premise did not hold.** Most selection in this app is
already searchable. Category, Brand, Unit, Rack, Shelf and Manufacturer all route
through `LookupListScreen` (search, live count, empty / filtered-empty / error,
pull-to-refresh). Product and Customer use dedicated searchable list screens.
Loyalty Package uses `SearchablePicker`. Only **10 raw dropdown sites** remain in
`lib/`, and eight are Class A.

Note: `AppBottomSheet` does not exist. The real API is `AppDialog.sheet`.

| Site | Backing list | Class | Verdict |
|---|---|---|---|
| `manage_loyalty_screen` payment mode | 4 const strings | A | keep |
| `sell_loyalty_package_screen` payment mode | 4 const strings | A | keep |
| `loyalty_package_form_screen` validity ×3 | `LoyaltyValidity.all` enum | A | keep |
| `sales_contact` party type | 4 fixed types | A | keep |
| `inventory_shell` sort | fixed options | A | keep |
| `edit_profile` UPI QR mode | 3 fixed options | A | keep |
| `add_income` category | dynamic | B | **not converted** |
| `edit_profile` business category | dynamic taxonomy | B | **not converted** |

The two Class B sites are classified but were not converted — genuine remaining
work, not a blocker.

**Batch No. — the priority item, and it had a real defect.**

`loadExistingBatchDetails` assigned both date fields unconditionally:

```dart
manufactureDateController.text = dateFormateChange(date: mfgDate);
expireDateController.text      = dateFormateChange(date: expireDate);
```

A batch recorded without dates answers null, `dateFormateChange` turns null into
an empty string, and the assignment therefore **erased dates the user had already
typed**. Selecting a batch to confirm which one you meant silently cleared your
own input — exactly the behaviour the brief forbids.

New `lib/core/purchase/batch_date_fill.dart` holds the rule: a batch fills a
field it has a value for and leaves alone one it does not. A batch that *does*
carry a date still replaces what is there, because selecting the batch is the
request to use its details. Manufacture and expiry are decided independently, so
a batch with an expiry but no manufacture date fills one and preserves the other.

Also removed two dead `for (int i = 0; i < purchaseDetails.length; i++) {}`
loops with empty bodies in the same method.

**Files changed**

```
lib/core/purchase/batch_date_fill.dart               (new)
lib/Screens/Purchase/purchase_product_buttom_sheet.dart (guarded fill, dead loops removed)
test/core/batch_date_fill_test.dart                  (new, 8 tests)
```

**Verification.** 8 new tests covering: batch date fills an empty field; null,
empty and whitespace-only batch dates preserve typed input; a real batch date
replaces a typed one; both absent stays empty; trimming; and the two fields
decided independently. Full suite 712 → **720**, analyzer unchanged at 77.

**Not verified without a device or seeded data:** the batch *search* interaction
itself (`searchBatchNumbers` at `purchase_product_buttom_sheet.dart:674,781`) —
debounce, duplicate requests, keyboard behaviour and 360/390/412 layout. Marked
device-blocked rather than claimed.

**Incidental finding for F11.** `edit_profile.dart:489` has a `_upiQrMode`
selector with `auto` / `dynamic` / `uploaded`. F11's configuration UI therefore
exists; what remains unverified is whether it prevents both sources being set,
and how `auto` resolves.

---

## UI Phase 2 — Error-handling UX (completed this session)

**Rule enforced:** no exception object, `toString()`, or raw API body reaches a
user. Technical detail goes to `AppLogger`; the user gets a sentence describing
the operation and what to do.

**Scale.** 57 sites across 43 files on the first sweep, then a further 25 that
the first regex had missed — it matched `$e` but not `${e.toString()}`, which is
the more common form in the repositories. Final count **≈82 sites**, now zero.

Message chosen by operation rather than one generic string:

| operation | message |
|---|---|
| Excel export | Could not create the Excel file. Please try again. |
| PDF export | Could not create the PDF. Please try again. |
| report export | Could not export the report. Please try again. |
| network | Connection failed. Please check your internet connection and try again. |
| fetch/load | Could not load the data. Please check your connection and try again. |
| upload/attach | Could not upload the image. Please try again. |
| delete customer/doctor | Could not delete this …. Please try again. |
| otherwise | Something went wrong. Please try again. |

Also replaced two inline `child: Text('Error: $e')` widget error displays, and
switched `add_doctor`'s hard-coded `Colors.red` SnackBar to
`colorScheme.error`.

**Verification command** (should print 0):

```bash
grep -rnE "(Text\(|EasyLoading\.(showError|showToast|showInfo)\(|toast\()[^;]*\$\{?(e|error|err|exception|ex)(\.toString\(\))?\}?([^a-zA-Z0-9_]|$)" \
  lib --include=*.dart | grep -v AppLogger | grep -v user_role_repo.dart | wc -l
```

**Analyzer went 77 → 76.** Adding an `AppLogger` + themed SnackBar to
`add_doctor.dart` briefly introduced a new context-after-await (78); guarding it
with `if (!mounted) return;` cleared that one and an adjacent pre-existing site.

**Files changed:** 44 (43 with leaks + `add_doctor.dart`). Full suite **720**
passing, unchanged — these are error paths that no test exercised, which is
precisely why they had drifted.

**Not done in this phase:** the remaining 19 UI phases in the brief (dialogs,
sheets, inputs, dates, filters, payment, reports, loading, cards, responsive,
Home/Dashboard, invoice, performance). Phase 2 was completed in full; the others
are untouched, not partially migrated.

---

## UI Phase 3 — Dialogs / confirmations (completed this session)

### Inventory (production `lib/`, generated and tests excluded)

```
showDialog(            33
AlertDialog(           34
SimpleDialog(           0
showModalBottomSheet(  14
AppDialog.confirm(      1  -> now the shared delete path
AppDialog.alert(        2
```

Deliberately **not** a blanket migration. The 33 `showDialog` sites are mostly
specialised — UPI QR, printer connection, product conversion, subscription,
permission, date/filter, owner-password override — which the brief says to leave
functionally intact. The migration target was *confirmations*, and those funnel
through one shared helper.

### The defect: the shared delete confirmation was lying

`showDeleteAlert` (`lib/Screens/Products/Widgets/widgets.dart`) — 6 callers:
product list, product details, warehouse, payment type, tax report ×2 — was a
hand-rolled `AlertDialog` whose message read:

> Are you sure you want to delete this {item}?
> **Related data will be deleted also.**

That is the opposite of what the API now does. The cascade work in this
engagement (`LookupDeletionGuard`, `HistoryDeletionGuard`, migrations
`2026_08_11_000001/2`) made related data *block* the delete — the server refuses
and names what is in the way. The dialog was describing the data-loss behaviour
those migrations were written to stop, and asking the user to confirm it.

Also fixed:

* generic title "Delete Confirmation" never named the entity -> `Delete {item}?`
* hard-coded `Colors.red` -> `PosButton` danger variant via `AppDialog.confirm`
* `tax_report.dart` passed `itemsName: 'vat_&_tax'` — a raw identifier rendering
  as *"delete this vat_&_tax?"* — now `'tax rate'`

### Second defect: `AppDialog.confirm` could pop twice

Both actions called `Navigator.of(context).pop(...)` directly. A fast double-tap
— the ordinary reaction to a dialog that has not visibly reacted — popped twice
and took the screen underneath with it. Guarded with an `answered` flag hoisted
outside the builder so a rebuild cannot reset it.

### Destructive-flow audit (all 6 `showDeleteAlert` callers traced)

| flow | gates on result? | closes on failure? |
|---|---|---|
| product list | yes — `runProductDelete`, reload only if deleted | no |
| product details | yes — pops only `if (deleted)` | no |
| warehouse | yes — `if (!success) return;` | no |
| payment type | yes — `SaveOutcome`, invalidates only if saved | no |
| tax report ×2 | yes — branches on `result` | no |

All six were already correct; no change needed. Earlier phases had fixed them.

### Tests — 8 new (`test/core/delete_confirmation_test.dart`)

Cancel -> false; Delete -> true; barrier dismiss -> false (a null result must not
read as confirmation); title names the entity; message no longer claims related
data is deleted; double-tap confirm pops once; double-tap cancel pops once; a
43-character entity name does not overflow a 360px dialog.

One failure on first run was mine, not the widget's: the test helper used
`onResult?.call(await open(context))`, and Dart short-circuits `?.` without
evaluating the argument — so the dialog never opened in the two tests that passed
no callback.

### Completion gate

```
[x] production dialog inventory        [x] full Flutter tests pass  (728)
[x] applicable confirmations migrated  [x] analyze 0 errors         (76 infos)
[x] destructive actions audited        [x] no new suppressions      (0)
[x] failed ops don't close UI          [x] Laravel tests pass       (1069)
[x] double-submit protection tested    [x] git diff reviewed (3 files)
[x] safe error messages preserved      [x] queue file updated
[x] payment dialogs inspected — left functionally intact
[x] responsive checked statically at 360px
```

### Documented exceptions (intentionally not migrated)

Specialised dialogs keeping their own structure because their behaviour is the
point, not their chrome: UPI QR, printer connection, product conversion,
subscription/premium, permission, owner-password delete override, date and
report filters, batch and customer selection. `showModalBottomSheet` (14 sites)
belongs to Phase 4 and was not touched — no half-migrated sheets.

---

## UI Phase 4 — Bottom sheets (completed this session)

### Inventory

14 textual `showModalBottomSheet(` matches; **13 live**. The 14th
(`product_details.dart:872`) is a commented-out block — dead code, not a sheet.
That was caught by an assertion in the migration script, not by reading, and is
the reason the script asserted rather than pattern-matched.

`AppDialog.sheet` already did the right things: `isScrollControlled`,
`useSafeArea: true`, `MediaQuery.viewInsetsOf` bottom padding, optional title,
`Flexible` content. No new component was created.

### Defect 1 — six sheets ran under the gesture bar

`useSafeArea` was missing on `check_actions_when_no_branch:33`,
`product_details:995`, `purchase_product_buttom_sheet:451`,
`sales_cart_screen:409`, `add_and_edit_purchase:422` and the commented one.
All live sites now set it.

### Defect 2 — three sheets with text inputs had no keyboard padding

The keyboard covered the very fields the sheet exists to edit:

| sheet | contents |
|---|---|
| `sales_cart_screen:409` | edit a cart line |
| `add_and_edit_purchase:422` | purchase line entry |
| `add_group_tax:406` | group-tax entry |

Each now wraps its content in
`Padding(padding: EdgeInsets.only(bottom: MediaQuery.viewInsetsOf(ctx).bottom))`.
`add_and_edit_purchase` already had `SafeArea(top: false)`, which covers the top
edge and does nothing about the keyboard.

### Defect 3 — the theme hardcoded the sheet colour

`_bottomSheetTheme` set `backgroundColor: Colors.white` and
`modalBackgroundColor: Colors.white`. Every sheet in the app inherits this, so a
hardcode there defeats theming for all thirteen at once. Both now read
`scheme.surface`.

### Tests — 6 new (`test/core/bottom_sheet_conventions_test.dart`)

Source-scanned, the house pattern from `error_presentation_test.dart`: rendering
each sheet would need its screen's providers, repositories and a server, which is
far more infrastructure than the property under test.

* the live-sheet count is 13 (a new sheet added without the conventions fails
  this file rather than slipping in)
* every live sheet sets `useSafeArea`
* every sheet containing a text input pads for the keyboard
* `AppDialog.sheet` itself does both
* the sheet surface comes from `scheme.surface`, not a hardcoded white
* `AppDialog.sheet` renders its title and content

### Completion gate

```
[x] all sheets inventoried (13 live, 1 dead documented)
[x] applicable sheets standardized
[x] legitimate custom sheets documented (below)
[x] responsive constraints checked statically
[x] keyboard behaviour checked statically
[x] loading / error states checked
[x] filters use committed state (Sales Report pattern intact)
[x] tests added where behaviour was unprotected
[x] flutter analyze 0 errors, 0 warnings (76 infos)
[x] Flutter tests pass (734)
[x] Laravel tests pass (1069)
[x] Postman 103 / 0
[x] suppressions 0, raw error leaks 0
[x] git diff reviewed — 7 files, no backend/financial/API/provider changes
[x] queue updated
```

### Legitimate custom sheets (kept, not migrated to AppDialog.sheet)

All thirteen keep `showModalBottomSheet` directly. `AppDialog.sheet` builds a
fixed `Column(title, Flexible(child))`; these need their own scaffolding —
`StatefulBuilder` for local sheet state (`add_group_tax`), a drag handle and
custom barrier colour (`add_and_edit_purchase`), full-height scroll controllers
(`purchase_product_buttom_sheet`, `pos_sale`), or a committed-filter footer
(`global_report_filter_bottomshet`). Converting them would mean widening
`AppDialog.sheet` until it is a passthrough. The conventions they must share —
safe area, keyboard padding, themed surface — are now enforced by test instead.

### Device-blocked (not claimed)

Actual keyboard behaviour on hardware, and rendering at 360/390/412/600/768. The
checks above are structural: they prove the padding and safe-area are wired, not
that a given sheet looks right on a Pixel.

---

## UI Phase 5 — Inputs / forms (completed this session)

### Inventory (production `lib/`, generated excluded)

```
TextField(              148     TextEditingController(  399
TextFormField(          168     FocusNode(               11
AppTextField(            49     InputDecoration(        201
PosTextField(            18
```

### Clean on audit — no change needed

* **Controllers created in `build()`: 0.** Scanned every `build` body; none.
* **Literal `"null"` in a request: 0.** The only two matches are comments
  documenting the earlier `product_setting_repo` fix.
* **Untrimmed controller text sent to the API: 0.**
* **`.toString()` on a nullable request value: 0.** All 39 sites are either
  non-nullable required parameters or guarded by `if (x != null)`.

### Defect — 33 controllers leaked across 6 State classes

A `State` that creates a controller as its own field must dispose it; an
undisposed one keeps its listeners, and through them the State, alive after the
screen is gone.

| file | leaked |
|---|---|
| `add_customer` | 19 (disposed 6 of 25) |
| `loyalty_package_form_screen` | 8 |
| `printing_invoice_screen` | 3 |
| `add_income`, `add_erxpense` | 1 each (`dateController`) |
| `manage_payment_type_view` | 1 — **no `dispose()` override at all** |

### A false positive worth recording

The first scan reported 9 classes and 40 controllers. Applying it broke the
build: three of those classes (`relationship_screen`, `stock_list_main`,
`low_stock`) declare their controllers as **locals inside methods**, and
disposing a local from `dispose()` does not compile.

The corrected detector matches only class fields — exactly two spaces of indent.
`low_stock.dart:243` (`final manufacturerCtrl = TextEditingController(...)`
inside a builder) is correctly excluded; `add_customer.dart:66`
(`final upiIdController = TextEditingController();`) is correctly included.

The compiler caught it, and the fix was to make the detector stricter rather than
to hand-edit around it.

### Tests — 2 new (`test/core/controller_disposal_test.dart`)

* every `State` disposes the controllers it owns — the scan that found the 33,
  kept as a guard so a new leak fails the suite
* no controller or focus node is created inside `build()` — currently true;
  this holds the line

Source-scanned for the same reason as Phase 4: these forms need providers,
repositories and a server to build.

### Completion gate

```
[x] input inventory completed          [x] search inputs audited
[x] normal inputs standardized where   [x] POS inputs audited (F04 rules intact)
    applicable — see exceptions        [x] keyboard-safe structure (Phase 4)
[x] validation audited                 [x] tests added
[x] null / "null" audited — clean      [x] analyze 0 errors, 0 warnings (76 infos)
[x] controller ownership audited       [x] Flutter tests pass (736)
[x] owned controllers disposed (33)    [x] Laravel tests pass (1069)
[x] async save lifecycle audited       [x] suppressions 0
[x] double-submit protection audited   [x] git diff reviewed (13 files)
[x] queue updated
```

### Explicitly NOT done — recorded as unfinished, not complete

The **visual** half of Phase 5 was not attempted: 148 `TextField` / 168
`TextFormField` / 201 `InputDecoration` sites were inventoried and classified but
not migrated to `PosTextField`. That is a genuine remaining piece of Phase 5, not
an exception — a blanket migration is what the brief forbids, and doing it
properly means per-file verification across ~200 sites.

What *was* completed is the correctness half: lifecycle, validation-safety,
null-handling and the double-submit/save-lifecycle audit.

Async save lifecycle and double-submit were audited and already correct — they
were fixed in earlier phases (`LookupEditorScreen`, `CategoryEditorScreen`,
`add_customer`, `AppDialog.confirm`), all on `SaveOutcome` with a busy flag.

---

## UI Phase 5B — Input visual migration (CLASSIFICATION ONLY — NOT COMPLETE)

Gate items 1–3 (classify every site) are done. **No migration group has been
started**, so nothing is half-migrated. The remaining gate items are open.

### Correction to the inventory

The brief's figures (148 `TextField` + 168 `TextFormField` = 316) double-count:
`AppTextField` from `nb_utils` subclasses `TextFormField`, so class references
were counted alongside call sites. The real live count is **238 call sites**
(comments excluded).

### Classification — 209 migratable, 29 specialised

| group | migratable | specialised |
|---|---|---|
| A Customer / Supplier | 33 | 0 |
| B Product | 36 | 1 |
| C Purchase | 17 | 5 |
| D Sales | 21 | 4 |
| E Inventory | 9 | 1 |
| F Payments | 2 | 1 |
| G Reports / Filters | 22 | 0 |
| H Profile / Settings | 11 | 0 |
| I Authentication | 19 | 0 |
| J Other | 39 | 17 |
| **total** | **209** | **29** |

"Specialised" = uses a property `PosTextField` does not expose, so migrating
would change behaviour. Blocking properties, with counts:

```
textAlign          16     initialValue        5
showCursor          7     onFieldSubmitted    2
onEditingComplete   1
```

`PosTextField` exposes: controller, label, hint, helperText, errorText,
prefixIcon, suffixIcon, onChanged, onSubmitted, validator, keyboardType,
textInputAction, inputFormatters, obscureText, enabled, readOnly, autofocus,
maxLines, minLines, maxLength, focusNode, onTap, textCapitalization, isRequired.

### The finding that should reshape this phase

`_inputDecorationTheme` in `lib/core/theme/theme.dart:200` is **already
complete**: `filled`, `fillColor`, `AppRadius.field`, all five border states
(border / enabled / focused / error / focusedError / disabled), `contentPadding`,
`hintStyle`, `labelStyle`, `floatingLabelStyle`, `errorStyle`, `helperStyle`,
prefix/suffix icon colours, and `minHeight: AppSizing.fieldHeight`.

**A raw `TextField` with no local decoration therefore already renders
correctly.** Migrating 209 call sites to `PosTextField` would produce a very
large diff for little visual gain.

The actual inconsistency is the **71 of 200 `InputDecoration` sites that override
the theme with hardcoded values**:

```
39  border:            17  hintStyle:
32  contentPadding:    14  fillColor:
23  enabledBorder:     14  filled:
19  focusedBorder:     13  labelStyle:
```

Each restates something the theme already supplies, usually with a different
radius or colour — that is what fragments the look, not the choice of widget
class.

Worst offenders: `purchase_orders_screen` (6), `stock_list_main` (6),
`sales_billing_screen` (4), `purchase_billing_screen` (4),
`invoice_return_screen` (3), `sales_return_report_screen` (3).

### Recommended next unit (not a decision taken here)

Removing a local override that merely duplicates the theme is a no-visual-change
edit and unifies the look immediately. But each must be checked individually —
some are deliberate (a borderless search field inside an AppBar is a real design,
not drift). That is a per-site job, which is why it was not begun with limited
context rather than half-done.

### Queued groups, in order

```
H Profile / Settings   11   non-financial, self-contained — good first group
I Authentication       19   non-financial
A Customer/Supplier    33   0 specialised
G Reports / Filters    22   0 specialised
B Product              36
E Inventory             9
D Sales                21   4 specialised — check each
C Purchase             17   5 specialised — check each
F Payments              2   financial: presentation only, per brief §7
J Other                39   17 specialised
```

### GROUP H COMPLETE — Profile / Settings (11 sites)

Nine of eleven were **already correct**: `gst_configuration` ×4,
`printer_settings` ×3, `delete_acount_allart_dialog` ×1 carry semantic content
only (labelText / hintText / errorText / helperText) and inherit the theme. No
change made to them.

Two real defects:

* `edit_profile.dart:370` — a `SizedBox(height: 60.0)` wrapping a
  `TextFormField`. The theme guarantees `AppSizing.fieldHeight` (52); a floating
  label plus an error line needs more than 60, so the fixed height clipped
  validation errors. Removed — a fixed height on an input only ever cuts
  something off.
* `contact_us.dart:32` — `keyboardType: TextInputType.name` on a message field
  with `maxLines: 30` inside a 150px box: a name keyboard where sentences belong,
  and thirty lines given room for about five. Now `multiline`,
  `TextCapitalization.sentences`, `minLines: 5, maxLines: 8`, box sizes itself.

**Recorded, not fixed:** the `contact_us` message field has **no controller and
nothing reads it** — the message cannot be sent. Wiring that is new
functionality, not presentation, so it was left as found.

Also checked: `kInputDecoration` (`constant.dart:93`, 63 uses) is only
`floatingLabelBehavior: always` and inherits everything else from the theme. Not
a bypass; left alone.

### GROUP I COMPLETE — Authentication (23 sites)

Twenty of twenty-three carry no style overrides at all. Three did:

* `profile_setup_screen.dart:360` — **fixed.** Overrode `focusedBorder` with a
  grey, square `OutlineInputBorder`, so this one field lost the primary focus
  ring and the `AppRadius.field` corner every other field has. Drift, not
  design. Removed; the theme supplies both.
* `sign_in_screen.dart:234` and `:267` — **preserved as a documented
  exception.** Each field sits inside a custom `_buildInputField` container that
  draws its own border and leading icon, so `border: InputBorder.none` (and the
  four other border states) is *required*. Removing them would double-border
  every field on the login screen. This is the "intentionally different UX" case
  the brief says not to normalize.

  Their hardcoded `Color(0xFF1F2937)` and `fontSize: 15` text/hint styles were
  also left alone: changing the login screen's type colours is a visible change
  I cannot verify without a device, and the borders are the part that fragments
  the look.

### GROUP A COMPLETE — Customer / Supplier (40 sites)

40 sites, not 33 — the earlier group split put doctor and supplier screens
elsewhere. All 40 accounted for: **12 changed, 26 already compliant, 2 preserved
as documented exceptions.**

**The 21 "fixed height" flags were false positives.** The detector's window
caught `SizedBox(height: N)` *spacers between* fields, not wrappers. Verified
each by walking up for an enclosing widget with a `child:` — none had one. No
change made.

**Four search bars were the same control written four times:**

```
customer_list        fill #F5F5F5      radius 8    hint grey[500]   text black
sale_customer_list   fill #F5F5F5      radius 8    hint grey[500]   text black
doctor_list_screen   fill #F5F5F5      radius 8    hint grey[500]   text black
choose_supplier      fill kBackground  radius 12   padding vertical: 0
```

Two radii and two fill colours for one control. Collapsed into
`searchFieldDecoration(context, hint:)` — added beside the existing
`filterFieldDecoration` in `filter_field_decoration.dart`, the same helper that
previously collapsed nine copies of a report filter field. Not a new component.
Borderless is kept deliberately: the fill defines the field and the header strip
separates it from the list.

**Other changes**

| site | change |
|---|---|
| `relationship_screen:74` | dialog field used radius 6 and its own grey; theme supplies both |
| `add_doctor:242, :348` | `hintStyle` used `Colors.grey.shade400`; theme uses `kGreyTextColor` |
| `add_sale_customer:245` | `hintStyle` restated the theme's own colour — redundant |

**Documented exceptions (preserved)**

* `transaction_screen:30` — an AppBar search field with a tighter
  `contentPadding`. A toolbar field must be shorter than a form field; the theme
  padding would not fit the AppBar.
* `relationship_screen:185` — a compact inline search sharing a row with an Add
  button, `border: InputBorder.none` inside its own container. Same category as
  the `sign_in_screen` exception from group I.

**Two mistakes caught during the work**, both by verification rather than by
reading:

1. The paren-matched replacement left a double comma (`),,`) in two files —
   caught by `dart analyze` immediately.
2. In `doctor_list_screen` I replaced the hint `'Search doctor or
   specialization'` with the generic `lang.S.of(context).search`, losing
   information the screen actually conveys. Caught by diffing against `HEAD` and
   restored verbatim. **User-facing copy is not a styling detail.**

Also reverted: `doctor_model.dart` and `doctor_provider.dart` picked up a
trailing-newline reformat from running `dart format` on the whole directory.
Unrelated to group A, so backed out.

**Functional preservation verified** by counting
`controller:` / `validator:` / `keyboardType:` / `onChanged:` /
`inputFormatters:` / `textCapitalization:` / `focusNode:` per file against
`HEAD` — identical in all seven changed files.

Untouched as instructed: `phoneMatch || nameMatch`, phone-update uniqueness,
loyalty logic, all providers and repositories.

### GROUP G COMPLETE — Reports / Filters (47 sites)

47 sites, not 22 — the classification undercounted. All accounted for:
**11 changed, 34 already compliant, 2 preserved as documented exceptions.**

Sixteen sites already used `filterFieldDecoration()`; the work was finding the
ones that never adopted it.

**Pattern 1 — the black-18pt filter label, 6 copies.** Exactly what
`filterFieldDecoration` was written to collapse: its own doc records nine screens
carrying "a black 18pt label, a bare OutlineInputBorder, and its own hint
colour". These six were left behind by that migration —
`followup_report` ×2, `purchase_return_report` ×2, `sales_return_report` ×2, all
date-range triggers. Now on the helper, `readOnly`/`onTap`/`controller`
untouched.

Two regex passes were needed: the second three wrap their arguments across lines
and use `lang.S.of(context).fromDate` rather than a quoted literal, so the first
pattern missed them rather than mangling them.

**Pattern 2 — the rounded search field, 4 copies.** `border` + `filled` +
`fillColor` + `BorderRadius.circular(16)`, in `purchase_report`,
`sale_due_report_screen`, `sales_report_screen`. Now `searchFieldDecoration`.
`staff_report_screen` was the same control at radius 12 with `0xFFFFFFFF`;
migrated too, keeping its `suffixIcon` clear button.

**Documented exceptions (preserved)**

* `reports.dart:235` — the search field sits inside a `kMainColor` header, so
  `fillColor: Colors.white` is deliberate. `searchFieldDecoration` uses
  `surfaceContainerHighest`, which would be near-invisible against the primary
  colour.
* `rack_shelf_report` ×2, `sales_return_report:497`, `stock_audit_screen:204`,
  `stock_audit_batch:355` — compact in-table and in-sheet filters with
  `isDense` and tighter `contentPadding`. A toolbar-height control cannot take
  the form-field padding. Same category as group A's `transaction_screen`.

**Filter-state safety verified.** The committed-state architecture in
`sales_report_screen` is intact — `_selectedName`, `_selectedPhone`,
`_selectedRelationship` still back `_hasFilter` and the query, with controllers
as the draft source. Nothing in this group touched a provider, a query
parameter, a debounce or an Apply/Clear path; the diff is decoration arguments
only.

**Functional preservation verified numerically** — `controller:` / `validator:` /
`keyboardType:` / `onChanged:` / `onSubmitted:` / `onTap:` / `readOnly:` /
`focusNode:` / `inputFormatters:` counted per file against `HEAD`; identical in
all seven changed files.

Net diff: **30 insertions, 88 deletions** across 7 files — the migration removes
more than it adds, which is the point.

### GROUP B COMPLETE — Product (22 sites)

Live inventory: **22 sites, not the estimated 36.** (Estimates continue to be
unreliable in both directions — A 33→40, G 22→47, B 36→22.) All accounted for:
**5 changed, 16 already compliant, 1 documented exception.**

**The finding: the Product module's own shared helper carried the drift.**

`buildProductFieldDecoration` (`product_form_fields.dart:62`) is used by six
files — `add_product`, `product_code_field`, `product_date_field`,
`product_tax_fields` ×3, `product_type_ahead_field` — and it hardcoded

```dart
enabledBorder: ... Colors.grey.shade400 ... BorderRadius.circular(6)
focusedBorder: ... kMainColor, width: 1.3 ... BorderRadius.circular(6)
```

So the **entire Product module** rendered at radius 6 with its own grey while
every other form in the app was on `AppRadius.field` with `kBorderColor`. One
hardcode in a shared helper made the module the odd one out, and no individual
screen looked wrong on its own.

Removed both overrides; `inputDecorationTheme` already supplies enabled,
focused, error, focusedError and disabled. Six files inherit the fix.

`add_product` additionally inlined the *same* two borders at six call sites,
duplicating the helper it was already using. Removed — 84 lines deleted from
that file alone.

**Documented exception**

`product_form_fields:190` — the `label:` is a `RichText` with a black label and a
red asterisk for required fields. That is the required-field affordance, not
styling drift, and `InputDecoration.label` is the only way to render a composite
label. Preserved.

**False positives verified, not assumed**

* `product_model_list:55` — the `BorderRadius.circular(5.0)` belonged to an
  adjacent `InkWell`, not the field.
* `bulk_transaction_upload:204` — the `Colors.white` came from elsewhere in the
  scan window.

Both decorations are semantic-only (`hintText`, `prefixIcon`, `labelText`) and
were left untouched.

**Financial and functional safety.** No pricing, GST, discount, conversion,
stock, barcode, batch, image, permission or save-lifecycle code was touched; the
diff is decoration arguments only. `controller:` / `validator:` /
`keyboardType:` / `onChanged:` / `inputFormatters:` / `readOnly:` / `onTap:` /
`focusNode:` / `maxLength:` counted against `HEAD` — 78 and 16, unchanged in both
edited files. Numeric formatters and the `nullable|integer|in:0,1` settings
contract untouched.

Net diff: **26 insertions, 101 deletions** across 3 files.

### GROUP E — Inventory: **COMPLETE** (input half + surface half)

**Group E is not closed.** The brief scoped it as two things — Phase 5B input
consistency *and* Inventory visual quality. The first is finished; the second was
inventoried but not begun, so it is recorded here rather than claimed.

#### Input half — COMPLETE

Live inventory: **9 input sites** across `stock_list`, `stock_audit` and
`core/product`. 8 needed work, 1 already compliant.

Same drift shape as every other group, inlined rather than shared: `radius 8` +
`Colors.grey.shade400` on `enabledBorder`, `kMainColor` on `focusedBorder`.
Removed 11 override blocks — `stock_list_main` ×9, `low_stock` ×2 — so all
inherit `AppRadius.field` and `kBorderColor` from the theme.

`isDense: true` preserved everywhere: the stock filter drawer is a genuinely
compact control and the theme padding would not fit it.

**Documented exception:** `stock_audit_batch:355` — a numeric quantity input in a
table row, `SizedBox(height: 36)` + `textAlign: center` + `hintStyle`. A
specialised in-table control; also carries `textAlign`, which puts it in the
"specialised" bucket by the original classification. Untouched.

Net: **61 deletions, 0 insertions** across 2 files. Functional properties
verified against `HEAD` — `controller:` / `validator:` / `keyboardType:` /
`onChanged:` / `inputFormatters:` / `readOnly:` / `onTap:` / `isDense:` — 39 and
14, unchanged.

#### Surface half — CORE WIDGETS DONE, SCREEN FILES OPEN

**Corrected inventory.** The earlier "raw Card" counts were wrong — a fresh scan
excluding comments finds **0 raw `Card(`** anywhere in the module. The real drift
is `BoxDecoration` (27), `Colors.white`/`kWhite` (45), `BorderRadius.circular`
(24) and raw `fontSize` (18) across 11 files.

**Two real defects found and fixed, both in shared `core/product` widgets:**

1. **`product_list_tile.dart` — the loading skeleton was invisible.** The card
   *and* all five placeholder blocks were `Colors.white`, so
   `ProductListSkeletonTile` rendered as a blank white card. That is the "weak
   loading experience" that was reported: nothing appeared to be loading because
   nothing was. The card now takes `scheme.surface` and the blocks
   `scheme.surfaceContainerHighest`.

2. **`inventory_shell.dart` — a second `StatusTone` colour map.** It restated
   `StatusBadge`'s tones with a different green, a different red, and an info
   colour one channel apart, for the same enum. So a stock chip in the inventory
   shell and a `StatusBadge` elsewhere disagreed on screen. Success and error now
   read `DAppColors.kStockInBg/Text` and `kStockOutBg/Text` — the badge's values.

**Tests added: 3** (`test/core/inventory_surface_test.dart`) — skeleton blocks
are a different colour from their card; the skeleton holds a sane height so the
list does not jump when real rows replace it; every `StatusTone` has a badge
treatment.

#### Screen files — 9 of 9 COMPLETE

`product_list_screen.dart` — the inventory product card was a hand-rolled
`AppCard`: `Colors.white`, `BorderRadius.circular(12)`,
`Colors.grey.shade200` border, custom shadow. Values now read
`colorScheme.surface`, `AppRadius.card` and `colorScheme.outlineVariant` — the
same values `AppCard` produces.

**Why the values and not the widget.** The tree is
`InkWell(borderRadius:, child: Container(decoration:))` and `AppCard` owns its
own `InkWell`. Swapping the widget means restructuring tap geometry in the main
inventory grid. The brief permits exactly this — "if consolidation is unsafe,
preserve separate behaviour but make the visual language identical" — and two
structural edits earlier in this session (a double comma from paren-matching, a
mis-scoped replacement) are the reason I took the low-risk path on the highest-
traffic screen in the module rather than the last one.

**`stock_list_main.dart` — COMPLETE.** 10 surfaces migrated, 1 documented
exception, 0 remaining drift.

| surface | was | now |
|---|---|---|
| filter drawer | `Material(color: Colors.white)` | `scheme.surface` |
| "Clear All" chip | `red.shade50` / `circular(20)` / `red.shade200` / `Colors.red` | `errorContainer` / `AppRadius.pill` / `error` / `onErrorContainer` |
| AppBar | `kMainColor` + `kWhite` + `fontSize: 16` | deleted — `appBarTheme` already supplies all three |
| stock-update field ×2 | `circular(8)` | `AppRadius.field`; validation red kept |
| filter button | `Radius.circular(6)` | `AppRadius.xs`; one-sided shape kept (attached to the search field) |
| data table rows | `Colors.white` | `scheme.surface` |
| PDF icon | `kWhite` | `scheme.onPrimary` |
| Excel icon | `kWhite` | `scheme.onPrimary` |
| active filter glyph | `Colors.white` | `scheme.onPrimary` |
| expiry date | `Colors.red` + `fontSize: 12` | `labelSmall` + `scheme.error` |

The AppBar was the largest single win: `appBarTheme` already sets
`scheme.primary`, `scheme.onPrimary` and `titleTextStyle`, so three local
overrides were deleted outright rather than re-expressed.

The icon colours could not simply be dropped — `HugeIcon` and `SvgPicture` do not
inherit `actionsIconTheme` — so they take `scheme.onPrimary` explicitly, which is
the value the AppBar sits on.

**Documented exception:** the filter count badge (`Colors.white`, `fontSize: 9`)
on a 3px-padded red circle. 9pt is below every typography token, and the white is
fixed against the red dot rather than against a theme surface.

**Verified:** analyzer clean on the file; full suite 739 passing; net **52
insertions, 88 deletions**; user-facing string set diffed against `HEAD` —
identical, no copy changed; functional-property count (`controller:` /
`validator:` / `onChanged:` / `onTap:` / `readOnly:` / `keyboardType:` /
`inputFormatters:` / `onPressed:` / `Navigator.` / `ref.read|watch|invalidate`)
45 before and after.

**Device-blocked:** rendering at 360/390/412/600/768. The changes are token
substitutions with no layout-geometry change, so no reflow is expected, but that
is reasoning rather than observation.

**`low_stock.dart` — COMPLETE.** 9 surfaces migrated, 1 documented exception,
0 remaining drift.

| surface | was | now |
|---|---|---|
| active-filter chip delete icon | `Colors.red` | `scheme.error` |
| "Clear All" chip | `red.shade50` / `circular(20)` / `red.shade200` / `Colors.red` | `errorContainer` / `AppRadius.pill` / `error` / `onErrorContainer` |
| filter drawer | `Material(color: Colors.white)` | `scheme.surface` |
| drawer search field | `circular(8)` | `AppRadius.field` |
| AppBar | `kMainColor` + `kWhite` + `fontSize: 16` | deleted — `appBarTheme` supplies all three |
| PDF icon | `kWhite` | `scheme.onPrimary` |
| Excel icon | `kWhite` | `scheme.onPrimary` |
| filter button | `circular(8)` + `Colors.white` glyph | `AppRadius.sm` + `scheme.onPrimary` |
| data table rows | `Colors.white` | `scheme.surface` |

This screen is a near-twin of `stock_list_main`, and every fix from that screen
applied unchanged — the same AppBar triple-override, the same drawer white, the
same "Clear All" chip, the same table row colour. Two screens carrying an
identical set of drift is what "the module was written once and copied" looks
like.

`kMainColor50` on the active-filter chip background was **kept**: it is the
app's own primary tint used as a selected-state fill, not an arbitrary colour.

**Documented exception:** the filter count badge (`Colors.white`, `fontSize: 9`)
on a 3px-padded red circle — identical to `stock_list_main`'s and documented the
same way.

**Verified:** analyzer clean on the file; full suite 739 passing; net **29
insertions, 31 deletions**; user-facing string set diffed against `HEAD` —
identical; functional-property count 27 before and after.

**Device-blocked:** rendering at 360/390/412/600/768. Token substitutions with no
geometry change, so no reflow is expected — reasoning, not observation.

**`stock_audit_report_screen.dart` — COMPLETE.** 10 surfaces migrated,
2 documented exceptions, 0 remaining UI drift.

**The queue's counts were partly PDF.** Of the 7 `fontSize` values, **4 belong to
`pw.` PDF document generation** (lines 603, 617, 620, 657), not Flutter UI. Those
are document typography and were left untouched — verified by diffing every
`pw.` line against `HEAD`: identical. Only 3 were UI.

| surface | was | now |
|---|---|---|
| filter drawer | `Material(color: Colors.white)` | `scheme.surface` |
| date-picker trigger | `grey.shade400` border, `circular(8)` | `outlineVariant`, `AppRadius.field` |
| date value text | `fontSize: 14` | `bodyMedium` — a value inside a field-shaped control |
| "Clear All" button | `circular(8)` | `AppRadius.button` |
| "Apply" button | `Colors.white` fg, `circular(8)` | `scheme.onPrimary`, `AppRadius.button` |
| AppBar | `kMainColor` + `elevation: 0` + `centerTitle` + `iconTheme` + white/16 title | deleted — `appBarTheme` supplies all five |
| export spinner | `Colors.white` | `scheme.onPrimary` |
| PDF action icon | `Colors.white` | `scheme.onPrimary` |
| filter button | `circular(8)` + white glyph | `AppRadius.sm` + `scheme.onPrimary` |
| data table rows | `Colors.white` | `scheme.surface` |

The AppBar restated **five** theme properties here, the most of the three screens
so far.

**Typography decisions.** `fontSize: 14` on the date value became `bodyMedium`
(a value inside a field-shaped control). `fontSize: 16` on the AppBar title was
deleted rather than mapped — `titleTextStyle` already supplies it. `fontSize: 9`
on the count badge stays.

**Documented exceptions**

* Line 188 — `ColorScheme.light(primary: kMainColor, onPrimary: Colors.white)`
  for the date picker. This *constructs* a scheme rather than consuming one, so
  literal values are correct: white is the contrast colour against the brand
  primary.
* The filter count badge (`Colors.white`, `fontSize: 9`) — same as the other two
  screens.

**One `const` correction.** Lifting `Theme.of(context)` into the export spinner
broke a `const Padding`; the `const` moved to the inner `EdgeInsets`. Caught by
`dart analyze` on the file, before the suite.

**Verified:** analyzer clean on the file; full suite 739 passing; net **42
insertions, 30 deletions**; PDF generation lines diffed against `HEAD` —
identical; user-facing string set — identical; functional-property count 37
before and after.

**Device-blocked:** rendering at 360/390/412/600/768, and the report table's
horizontal behaviour on narrow screens.

**`stock_audit_batch.dart` — COMPLETE.** 8 surfaces migrated, 0 remaining drift.

| surface | was | now |
|---|---|---|
| AppBar | `kMainColor` + `elevation: 0` + `centerTitle` + `iconTheme` + white/15 title | deleted — `appBarTheme` supplies all five |
| rack chip | `circular(12)` | `AppRadius.md` |
| compact numeric input ×2 | `circular(6)` | `AppRadius.xs` |
| variance indicators ×2 | `circular(6)` | `AppRadius.xs` |
| product spacer | `Colors.grey.shade100` | `scheme.surfaceContainerHighest` |
| submit button | `Colors.white` fg, `circular(8)` | `scheme.onPrimary`, `AppRadius.button` |
| submit spinner | `Colors.white` | `scheme.onPrimary` |

Four of the six radii were **already the token's value** (6 == `AppRadius.xs`,
12 == `AppRadius.md`); the change names them rather than altering them, so those
four are visually identical.

**Semantic colours preserved, deliberately:**

* the product-name header's `kMainColor.withValues(alpha: 0.08)` tint and its
  0.2-alpha border — a primary-tinted section header, not arbitrary colour
* `updateBorderColor` and `kMainColor` on the numeric input — validation and
  focus states
* the blue/red variance indicators at 0.08 fill and 0.4 border — positive and
  negative stock variance. Replacing these with `scheme` colours would have lost
  the positive/negative distinction, which is the entire point of that column.
* `AcnooTheme.brandTint` at line 175 — already a token, untouched

**The compact numeric input exception holds.** Its `isDense`, 4×8 content
padding, 36px row height and small radius are unchanged; only the radius literal
was renamed to the token it already equalled. No keyboard, validation,
controller, decimal, commit or focus behaviour was touched.

**Two corrections during the work:** `AppRadius` was not imported (added), and
the submit spinner sat inside a `const SizedBox` that could not hold
`theme.colorScheme` (const lifted). Both caught by `dart analyze` on the file.

**Verified:** analyzer clean; full suite 739 passing; net **25 insertions, 22
deletions**; user-facing strings identical to `HEAD`; functional-property count
11 before and after.

**Device-blocked:** rendering at 360/390/412/600/768, and how the batch table's
compact numeric columns behave at 360.

**`stock_in_batch.dart` — COMPLETE.** 2 surfaces migrated, 0 exceptions,
0 remaining drift.

**First `AppCard` adoption in the Inventory module.** The outer surface wrapping
the batch table is a genuine, non-interactive card — no `InkWell`, no tap
geometry to preserve — so it took `AppCard(padding: EdgeInsets.zero)` outright
rather than values-alignment. `AppCard` supplies `scheme.surface`,
`AppRadius.card` and the `outlineVariant` border, and because it clips to its own
corners the header strip no longer needs to restate
`BorderRadius.vertical(top: Radius.circular(12))` — that line was deleted rather
than tokenised.

This is the distinction the brief asked for: values-alignment where structure
matters, real component adoption where it does not.

**A one-character defect found.** The table header read `Color(0xf0fef0f1)` —
alpha `f0`, 94% opaque. `FEF0F1` is the module's table-header colour and every
other use is fully opaque:

```
stock_audit_report_screen.dart:506   ExcelColor.fromHexString('FEF0F1')
stock_audit_report_screen.dart:606   PdfColor.fromHex('FEF0F1')
stock_list_main.dart:1117            Color(0xffFEF0F1)
stock_in_batch.dart:39               Color(0xf0fef0f1)   <- the odd one
```

So this one header was faintly translucent against the card behind it while the
exports and the other table were solid. Corrected to `0xFFFEF0F1`; the colour is
kept because it is deliberate and shared, not drift.

The AppBar on this screen was **already clean** — `title` and `centerTitle` only,
no theme overrides. First of the six with nothing to remove there.

**Verified:** analyzer clean; full suite 739 passing; net **12 insertions, 7
deletions**; user-facing strings identical to `HEAD`; functional-property count 6
before and after.

**Device-blocked:** rendering at 360/390/412/600/768, and whether `AppCard`'s
elevation and border read correctly behind the tinted header strip.

**`product_grid.dart` — COMPLETE.** 1 surface migrated, 0 exceptions,
0 remaining drift.

The file's only drift was `_CardSkeleton` — the placeholder tile the product grid
shows while loading: `Colors.white`, `BorderRadius.circular(12)`, and an
`outlineVariant` border at half alpha.

**Second `AppCard` adoption.** Non-interactive placeholder, no tap geometry, so
it took `AppCard` directly rather than values-alignment. The half-alpha border
was preserved via `AppCard(borderColor:)` — a skeleton is deliberately fainter
than the card it stands in for, and that is a design decision rather than drift.

As with `stock_in_batch`, `AppCard`'s clipping made a hand-matched radius
redundant: the image block's
`BorderRadius.only(topLeft: 12, topRight: 12)` existed only to follow the
container's corners and was deleted. That is the second file where adopting
`AppCard` removed a pair of radii that had to be kept in step by hand.

**No invisibility risk.** `AppSkeleton` draws on
`scheme.surfaceContainerHighest.withValues(alpha: 0.6)`, which reads against
`scheme.surface` — checked before changing the card colour, because the
`product_list_tile` skeleton fixed earlier in this group was white-on-white.

**Grid geometry untouched.** `ProductGridMetrics.delegate`, column count, aspect
ratio and spacing lines diffed against `HEAD` — identical. Column/responsive
behaviour was not altered, per the brief.

**Functional-property count 12 -> 13**, and the single delta is accounted for:
`padding: EdgeInsets.zero` added to `AppCard`, because `AppCard` defaults to
`AppSpacing.lg` while the original `Container` had none. Isolated by diffing the
property multiset, not assumed.

**Verified:** analyzer clean; full suite 739 passing; net **12 insertions, 14
deletions**; user-facing strings identical to `HEAD`.

**Finding recorded, not fixed** (outside this screen's scope):
`lib/core/widgets/app_skeleton.dart` has three `Colors.white` at lines 37, 199
and 270. Worth checking when the design-system widgets are next revisited —
given the `product_list_tile` skeleton was invisible for exactly this reason, at
least one of them may be a live defect.

**Device-blocked:** rendering at 360/390/412/600/768, and whether `AppCard`'s
elevation reads correctly on a grid of placeholder tiles.

**`product_card.dart` — COMPLETE.** 0 surfaces needed migration,
1 exception documented, 0 remaining drift.

**This file was already compliant.** An exhaustive scan for `Colors.*`, hex
literals, raw `fontSize`, `BorderRadius.circular(n)` and numeric `EdgeInsets`
found **23 token usages and exactly 2 literals** — and both belong to one
element. Everything else already reads `scheme.surface`, `AppRadius.card`,
`AppRadius.chip`, `scheme.outlineVariant`, `scheme.primary`, `scheme.onPrimary`,
`AppSpacing.*`, `AppMotion.*` and the `textTheme` scale.

That is not a coincidence: this is the consolidated `ProductCard` built earlier in
this engagement, and it was written against the design system from the start.
It is the counter-example to the rest of the module — a file that never drifted
because it was never hand-rolled.

**`AppCard` deliberately NOT adopted.** The card owns its interaction geometry:
`Material(clipBehavior, shape)` wrapping an `InkWell` with
`onTap`/`onTapDown`/`onTapUp`/`onTapCancel` driving an `AnimatedScale` press
effect, plus a second `InkWell` on the quantity chip. `AppCard` owns its own
`InkWell` and would flatten that press feedback. Per the brief's rule, values
were already aligned and the structure stays.

**Documented exception** — the out-of-stock overlay, lines 232-241:
`ColoredBox(Colors.black @ 45%)` with `Colors.white` label text. This is a scrim
over a product *photograph*, not a surface in the app's colour system. A theme
colour would be invisible against a light image and wrong against a dark one;
black-at-45% with white text is the contrast pair that holds over arbitrary image
content in either theme. Comment added in place so the next scan does not
re-open it.

**Shared-component verification** (stronger than previous screens, as required):

```
importers          7 files
production callers 3  — sales_products_list_screen:651
                        purchase_products:769
                        pos_sale:769
test callers      12  — product_card_test.dart (8), ui_stability_test.dart (4)
constructor        diffed against HEAD — identical
callers            dart analyze on all three — clean
dedicated tests    39 passing
full suite        739 passing
```

Inventory reaches this component through `product_grid` / `product_list_tile`
rather than constructing it directly, so all four consuming areas — Inventory,
Purchase, Sales, Counter Sale — are covered by the above.

**Verified:** analyzer clean; full suite 739 passing; net **10 insertions, 2
deletions** (all comment); constructor API identical; user-facing strings
identical.

**Device-blocked:** rendering at 360/390/412/600/768, and how the scrim reads
over real product photography.

**`stock_audit_report_batches.dart` — COMPLETE.** 2 surfaces, 0 exceptions.
AppBar's five overrides deleted; `dataRowColor` -> `scheme.surface`.
`AcnooTheme.brandTint` on the heading row was already a token.

**`product_list_screen.dart` — NOW ACTUALLY COMPLETE.** The final audit caught
that this file was only *partially* migrated: the earlier turn did its product
card and stopped, and the queue recorded "(card done)" but the item was counted
as one of the nine. Six real sites remained:

| surface | was | now |
|---|---|---|
| AppBar popup menu | `kWhite` | `scheme.surface` |
| FAB shape | `circular(100)` | `AppRadius.pill` |
| FAB icon | `kWhite` | `scheme.onPrimary` |
| card InkWell radius | `circular(12)` | `AppRadius.card` — matches the card it ripples inside |
| per-product popup menu | `kWhite` | `scheme.surface` |
| product name | `fontSize: 14` | `bodyMedium` (the 14pt it already was) |

Caught only because the final audit scanned all nine files rather than trusting
the per-turn completion notes. Worth remembering: "screen N complete" recorded
mid-migration is not the same as verified.

---

## GROUP E SCREEN MIGRATION — 9/9 COMPLETE

| # | file | approach |
|---|---|---|
| 1 | `product_list_screen.dart` | values-alignment (card owns InkWell) |
| 2 | `stock_list_main.dart` | values-alignment |
| 3 | `low_stock.dart` | values-alignment |
| 4 | `stock_audit_report_screen.dart` | values-alignment |
| 5 | `stock_audit_batch.dart` | values-alignment |
| 6 | `stock_in_batch.dart` | **AppCard adopted** |
| 7 | `product_grid.dart` | **AppCard adopted** |
| 8 | `product_card.dart` | already compliant — no migration needed |
| 9 | `stock_audit_report_batches.dart` | values-alignment |

Plus the two shared `core/product` widgets fixed earlier in the group
(`product_list_tile` invisible skeleton, `inventory_shell` duplicate StatusTone
map).

**AppCard adoption: 2 files.** Both non-interactive surfaces. In each, adopting
the component also deleted a hand-matched child radius that existed only to
follow the parent's corners — `AppCard` clips, so the pair no longer has to be
kept in step. That is the strongest argument the group produced for the component
over values-alignment.

**Values-alignment: 6 files.** Every one owns interaction geometry (`InkWell`,
`GestureDetector`, `AnimatedScale` press feedback) that `AppCard` would flatten.

**The recurring find: the AppBar.** Seven of nine screens restated properties
`appBarTheme` already supplies — `backgroundColor`, `elevation`, `centerTitle`,
`iconTheme`, `titleTextStyle` — between three and five each. Those were deleted,
not re-expressed. `stock_in_batch` and `product_card` were the only two clean.

**Documented exceptions (7)**

* filter count badge x3 (`stock_list_main`, `low_stock`,
  `stock_audit_report_screen`) — `Colors.white` at `fontSize: 9` on a 3px-padded
  circle; below every token, and white against the red dot rather than a surface
* `stock_audit_report_screen:191` — `ColorScheme.light(...)` *constructs* a
  scheme for the date picker; literals are correct there
* `stock_audit_report_screen` PDF block — `pw.*` document typography, out of scope
* `product_card:248` — scrim over a product photograph; theme colours cannot
  hold contrast over arbitrary image content
* `stock_audit_batch` compact numeric input — the in-table exception from the
  input pass, still intact

**Semantic colours preserved throughout:** low/out-of-stock status, positive and
negative stock variance (blue/red), validation and focus states, destructive
red, the primary-tinted section headers, `kMainColor50` selected-state fills, and
the shared `FEF0F1` table header.

**Final audit result**

```
production suppressions        0
analyzer errors                0
analyzer                      76  (unchanged all group — all use_build_context_synchronously)
Flutter tests                739  passing
copy changes across 11 files   0  (string sets diffed against HEAD)
functional-property counts     unchanged in every file
Group E diff                  11 files, +274 -238
```

**Device-blocked, not claimed:** rendering at 360/390/412/600/768 for every
screen; whether `AppCard` elevation reads correctly behind tinted headers and
across placeholder grids; how the scrim reads over real photography.

**Finding recorded, not fixed:** `lib/core/widgets/app_skeleton.dart` has three
`Colors.white` (lines 37, 199, 270). Outside Group E's scope. Given the
`product_list_tile` skeleton was invisible for exactly this reason, at least one
may be a live defect — worth checking when the design-system widgets are next
touched.

---

#### (historical) still-open list, now empty

The screen-level files were inventoried but not migrated:

```
product_list_screen.dart        3 white  4 BoxDecoration  1 radius  4 fontSize  (card done)
+ ..._report_batches
```

Each remaining surface needs the same per-site judgement: card, section, image
well, toolbar, or table cell. That decision cannot be made by pattern.

`AppCard` is still used zero times in the module. Migrating these means deciding,
per surface, whether it is a card, a section, an image well, a toolbar or a table
cell — the judgement the brief asks for and not something to rush.

**Not verified this pass:** product-list hierarchy rework, filter/search visual
alignment, responsive constraints, the performance audit, product-image handling,
and delete-flow re-verification. F02's delete flow was traced correct in an
earlier session and nothing here touched it.

The reported symptom ("some place card transparent and some place white") is
real and measurable. Raw counts of `Colors.white`/`kWhite`, raw
`Card(`/`BoxDecoration(`, `AppCard(` and `BorderRadius.circular(`:

```
file                                   white  rawCard  AppCard  radius  fontSize
Products/product_list_screen.dart          4        5        0       3         4
Report/Screens/stock_audit_report.dart    10        4        0       4         7
Report/stock_audit/stock_audit_batch       4        5        0       6         1
Report/stock_audit/..._report_batches      3        0        0       0         1
stock_list/low_stock.dart                  8        3        0       5         2
stock_list/stock_in_batch.dart             1        2        0       1         0
stock_list/stock_list_main.dart            8        3        0      12         3
core/product/inventory_shell.dart          0        1        0       1         0
core/product/product_card.dart             1        1        0       0         0
core/product/product_grid.dart             1        1        0       1         0
core/product/product_list_tile.dart        5        2        0       2         0
```

**`AppCard` is used zero times in the entire Inventory module** — every surface
is a hand-rolled `Container`/`BoxDecoration`, which is exactly why the
backgrounds and radii disagree between screens.

That is a substantive redesign across 11 files, not an override cleanup, and
starting it without the context to finish would leave the module half-migrated —
the thing the stop rule forbids. It is the next unit of work, not a documented
exception.

**Not verified this pass** (part of the surface half): card/typography/icon
consistency, loading/empty/error state review, delete-flow re-verification,
pagination, responsive constraints, the performance audit, and product-image
handling. F02's delete flow was traced in an earlier session and found correct;
nothing in this pass touched it.

### Gate status

```
[x] H complete    [x] B   [ ] C
[~] I complete    [~] E   [ ] F
[x] A complete    [x] G   [ ] J
                  [ ] D

[x] all TextField sites classified        [~] applicable inputs migrated (H, I)
[x] all TextFormField sites classified    [ ] specialised inputs documented
[x] all InputDecoration sites classified      individually
[x] no duplicate component created        [ ] git diff reviewed for migration
[x] Flutter analyze 0 errors (76 infos)   [ ] queue gate passed
[x] Flutter tests pass (736)
[x] Laravel tests pass (1069)
[x] suppressions 0
```

**Phase 5 is NOT complete.** Phase 6 must not start.

---

## Next queue item

**Group E surface half, screen files** — `stock_list_main`, `low_stock`,
`stock_audit_report_screen`, `product_list_screen`, `stock_audit_batch` and four
smaller files onto `AppCard`/theme surfaces. The shared `core/product` widgets
are already done.
Then D (Sales), C (Purchase), F (Payments), J (Other). Re-inventory each; the
estimates are wrong in both directions (A 33→40, G 22→47, B 36→22, E 9 inputs).

Three notes carried forward:

* Check for a **module-level shared decoration helper** first. Group B's entire
  drift lived in one, and fixing it corrected six files at once. Known helpers:
  `filterFieldDecoration`, `searchFieldDecoration`
  (`lib/core/widgets/filter_field_decoration.dart`) and
  `buildProductFieldDecoration` (`Products/Widgets/product_form_fields.dart`).
* A scan window catches neighbouring widgets. Every `hard=` flag has been worth
  opening before acting — several per group have been false positives.
* Composite `label:` widgets (required-field asterisks) are semantic, not drift. **F06 — Batch dates**
follows Phase 5.

Method that worked for H and I, worth repeating: list the group's sites with
their `InputDecoration` style-override keys, then only open the ones that
override `border` / `fill` / `contentPadding` / `hintStyle` / `labelStyle` or
wrap the field in a fixed height. Most sites in a group carry semantic content
only and need no change — 9 of 11 in H, 20 of 23 in I.

The date-fill half is done and tested (F05.5 above). What remains is the
selection UX: batch search behaviour, and the "editing an existing purchase"
case.

Purchase: selecting an existing Batch No must populate Manufacture Date and
Expiry Date. Cases: both present, manufacture only, expiry only, neither, a new
batch, and editing an existing purchase — without overwriting values the user
has already typed. Entry points: `lib/Screens/Purchase/add_and_edit_purchase.dart`
and `PurchaseRepo.fetchBatchDetails` / `searchBatchNumbers`
(`lib/Screens/Purchase/Repo/purchase_repo.dart:522,546`).

---

## Open decisions (blocked on the product owner, not on code)

1. **Customer duplicate rule (F08).** `add_customer.dart` rejects a create when
   `phoneMatch || nameMatch`. Backend enforces phone uniqueness only
   (`PartyPhoneUniquenessTest`, 7 tests). Whether two customers may share a name
   is a product question, not a defect to settle unilaterally.
2. **`PurchaseRepo.deletePurchase`.** Verified unreachable — the only textual
   matches in `lib/` are its own definition and an unrelated private method in
   `held_sales_list_screen`. Delete it, or wire it up if purchase deletion was
   intended.
3. **Cascade migrations.** `2026_08_11_000001` and `2026_08_11_000002` have run
   only against the test database. Their orphan pre-checks will abort a deploy if
   a cascade has already fired on production data — correct behaviour, but it
   should be rehearsed against a production copy first.

## External blockers

```
Firebase configuration (google-services.json, package registration; iOS unknown)
Production API URLs for a release build
Real-device visual and responsive verification
Printer hardware verification
Disposable credentials + throwaway DB to execute the Postman collection
```

---

## GROUP D — Filter / Search / Dropdown / Calendar: **COMPLETE**

### Phase 1 inventory (production `lib/`, comments excluded)

```
showDatePicker          46      SearchablePicker         8
PopupMenuButton         10      filterFieldDecoration   29
DropdownButtonFormField  8      searchFieldDecoration    9
DropdownButton<          3      LookupListScreen        11
DropdownMenu             0      CategoryChipBar          5
showDateRangePicker      0      FilteredEmptyState       9
showTimePicker           0      AppDropdown              0   <- component exists, unused
                                AppDialog.sheet          0   <- component exists, unused
```

**The dropdown/search half needed almost nothing.** Searchable selection is
already well adopted — `LookupListScreen` (11), `SearchablePicker` (8),
`filterFieldDecoration` (29), `searchFieldDecoration` (9), `CategoryChipBar` (5).
The 11 remaining raw dropdowns were classified in Group 5B as Class A (4-item
payment modes, an enum, party type, sort options, UPI QR mode) and converting
them would make them worse. No new search architecture was created.

**Date selection was the real gap.** 46 `showDatePicker` call sites carrying:

```
firstDate   2000 (7)   2015 (30)   2020 (7)   2021 (1)   now.year-5 (1)
lastDate    2030 (1)   2100 (5)    2101 (37)  now (1)    now.year+10 (1)
```

Six different start years and six different end years. Two date fields on the
same screen could disagree about which years exist.

### The correction

One site carried a `builder` applying
`ColorScheme.light(primary: kMainColor, onPrimary: Colors.white)`. **An earlier
pass in this engagement documented that as an intentional exception. That was
wrong**, and this phase reversed it.

`AcnooTheme` already supplies a full `datePickerTheme` — brand header,
`AppRadius.dialog`, today-outlined/selected-filled day treatment — and
`scheme.primary` is `kMainColor`. So all 46 pickers were *already* correctly
themed. `ColorScheme.light` with two arguments resets every other role to
Material's defaults, so that builder was **discarding** the app's picker theme.
It was the only unthemed picker in the app, not the only themed one.

### Change

New `lib/core/date/app_date_picker.dart` — `showAppDatePicker()`:

* one shared range, 2015..2101 — the union of what screens already offered, so
  no screen loses a year
* clamps an out-of-range `initialDate` rather than letting it assert inside the
  framework
* deliberately does **not** wrap in a `Theme`; the ambient theme is correct
* `firstDate`/`lastDate`/`helpText` remain overridable

**44 of 47 call sites migrated.** Three keep raw calls, all semantic:

| site | bound | why |
|---|---|---|
| `loyalty_package_form_screen:136` | `now.year-5 .. now.year+10` | a validity window relative to today |
| `dashboard:156` | `.. DateTime.now()` | a date that cannot be in the future |
| `app_date_picker.dart` | — | the helper itself |

### A regression I introduced and caught

The first migration pass kept only `context` and `initialDate`, which silently
**dropped `helpText:` from four call sites** — `'Select From Date'` and
`'Select To Date'` in `purchase_report` and `sales_report_screen`. Those two
screens have two identical pickers each, and the help text is the only thing
distinguishing them.

Caught by diffing the user-facing string set against `HEAD`, then confirmed by
enumerating every parameter present in the `HEAD` calls beyond the four the
helper kept. `helpText` added to the helper and all four values restored
verbatim. The remaining flagged parameters (`builder`, `data`, `child`,
`colorScheme`, `primary`, `onPrimary`) all belonged to the mis-themed builder
that was removed deliberately.

The string-set diff is now clean across every changed file.

### Tests — 3 new (`test/core/app_date_picker_test.dart`)

* only semantic bounds bypass the shared picker — a new raw call with an
  arbitrary range fails this test
* the helper owns one range and does not re-theme (asserted over code with
  comments stripped, since the doc comment quotes the pattern it replaced)
* an out-of-range initial date is clamped

### Not done, recorded as findings

* **Three date display formats** remain: `dd MMM yyyy` (37), `dd-MM-yyyy` (21),
  `dd/MM/yyyy` (7). Unifying them is user-visible and some of these strings are
  read back by parsers — the known `paymentDate` d/m/Y issue is exactly that
  hazard — so it needs its own pass with regression tests, not a rename here.
* **`AppDropdown` and `AppDialog.sheet` have zero usages.** Both exist and are
  unused. Either they should be adopted or they are dead code; worth a decision.

### Verification

```
Flutter analyze        76   0 errors, 0 warnings (unchanged)
Flutter tests         742   passing (was 739)
production suppressions 0
inline ignores          0
copy regression         0   string sets diffed against HEAD, every changed file
Laravel                     not run — no backend code changed
```

**Device verification: NOT AVAILABLE.** Static responsive inspection: completed.
Not observed: how the picker renders at 360-768, and whether the wider
2015..2101 range changes scroll behaviour on screens that previously offered a
narrower one.

---

## GROUP C — Bottom sheets / popups / action menus: **COMPLETE**

### Phase 0 inventory

```
showDialog              55     PopupMenuButton         10
AlertDialog             33     PopupMenuItem           21
showModalBottomSheet    27     onLongPress             22
DraggableScrollableSheet 1     AppDialog.confirm        2
showBottomSheet          0     AppDialog.alert          2
showMenu / MenuAnchor    0     AppDialog.sheet          0
SimpleDialog             0     CupertinoActionSheet     0
```

**Sheets and dialogs were already closed.** UI-P4 covered the 13 live sheets
(safe area, keyboard padding, themed surface, enforced by
`bottom_sheet_conventions_test.dart` — still green). UI-P3 covered
confirmations (`showDeleteAlert` on `AppDialog.confirm`, double-pop guard). The
`showDialog`/`AlertDialog` counts are the specialised surfaces both phases
documented as legitimate exceptions.

**The new surface was popup menus.** `popupMenuTheme` already supplies
`color: scheme.surface`, `elevation: AppElevation.dialog` and
`shape: AppRadius.card` — and four of ten sites overrode it:

| site | override | action |
|---|---|---|
| `purchase_transaction_widget:277` | `shape: circular(4.0)` | removed — theme gives `AppRadius.card` |
| `sales_transaction_widget:331` | `shape: circular(4.0)` | removed |
| `purchase_list_screen:536` | `shape: circular(4.0)` | removed |
| `product_list_screen:885` | `color` + `elevation: 2` | removed — theme gives both |
| `branch_list:311` | icon `Color(0xff4B5563)` | -> `scheme.onSurfaceVariant` |

Three menus rendered at radius 4 while the theme drew every other at 12.

`offset` and `padding` were **kept** at every site: those position the menu
relative to its trigger and are per-site geometry, not theme drift.

**Final audit:** all ten popup menus now carry zero theme overrides. The three
remaining `color:` values are `Icon` colours, not menu surfaces — `branch_list`
now theme-derived, `product_list_screen` uses `DAppColors`, `transfer_list` uses
`kSubPeraColor`.

### Phase 13 — dead component decision, and a correction

**`AppDropdown` is not dead. My Group D report was wrong.**

I searched for `AppDropdown` — the name the brief used — and found zero. The
class in `lib/core/widgets/app_dropdown.dart` is actually **`PosDropdown`**,
following the same `Pos*` convention as `PosTextField` and `PosButton`
(documented in `widgets.dart`: `nb_utils` already exports `AppTextField` and
`AppButton`, so the app's own components take the `Pos` prefix to avoid ambiguous
imports).

**`PosDropdown` has 30 usages across 25 files.** It is one of the most widely
adopted components in the app. Recorded here because "component X is unused"
is exactly the kind of claim that gets acted on destructively, and it came from
trusting a name instead of reading the source.

**`AppDialog.sheet` genuinely has 0 usages** — that one stands. UI-P4 already
established why: all 13 sheets need their own scaffolding (`StatefulBuilder`,
drag handles, scroll controllers, committed-filter footers), and widening
`AppDialog.sheet` to fit them would reduce it to a passthrough. It is a
component built for a case that did not materialise. **Not removed** — the brief
is explicit that zero usages alone is not grounds, and it is correct code that a
future simple sheet could use.

### Functional defect found — recorded, not fixed

`lib/Screens/warehouse/tab_item/transfer_list.dart:66`

```dart
onPressed: () => PopupMenuButton(
    itemBuilder: (context) => [
          PopupMenuItem(child: Text('Edit')),
          PopupMenuItem(child: Text('Delete')),
        ]),
```

The callback **constructs a `PopupMenuButton` widget and discards it**. A widget
is not a function; nothing opens. The overflow button on every transfer row is
inert. Even if it did open, the items have no `value` and the button no
`onSelected`, so Edit and Delete would do nothing.

Not fixed here: making it work means implementing transfer edit and delete,
which is new functionality rather than interaction polish. Whether those actions
should exist is a product decision.

### Verification

```
Flutter analyze        76   0 errors, 0 warnings (unchanged)
Flutter tests         742   passing
production suppressions 0
inline ignores          0
raw exception leaks     0
copy regression         0   string sets diffed against HEAD, every changed file
bottom sheet test           6/6 still green
Laravel                     not run — no backend code changed
```

Files changed: 5 (`purchase_transaction_widget`, `sales_transaction_widget`,
`purchase_list_screen`, `product_list_screen`, `branch_list`).

**Device verification: NOT AVAILABLE.** Static responsive inspection completed.
Not observed: menu placement near screen edges at 360, and whether the radius
change from 4 to 12 alters perceived menu size on small screens.

---

## GROUP F — Context safety / async lifecycle: **COMPLETE (76 -> 0)**

### Phase 0 inventory — all 76 categorised

```
category            repo   screen   total
A navigation           2       26      28
B message/dialog      20       11      31
G other                1       16      17
                      23       53      76
```

**23 of 76 were repositories driving UI** — 20 of those showing SnackBars. That
is the architectural fix Phase 1 asks for, not a mounted-guard exercise.

### Repositories converted — **6 of 6, all 23 repo sites cleared**

| repo | sites | callers | outcome |
|---|---|---|---|
| `business_setup_repo` | 4 | 1 | -> `SaveOutcome` |
| `register_repo` | 3 | 1 | -> `SaveOutcome` |
| `subscription_plan_repo` | 4 | 0 | -> `SaveOutcome` |
| `invoice_return_repo` | 4 | 2 | -> `SaveOutcome` |
| `bulk_upload_repo` | 6 | 3 | -> `SaveOutcome` (3 methods) |
| `customer_due_repo` | 2 | 1 | -> `DataOutcome<CustomerDueCollection>` |

Plus their call sites. Each now returns a result; the screen decides what to
show and whether to navigate.

### Behavioural defects found while converting

1. **`business_setup_repo` showed the raw response body.**
   `Text('Profile setup failed: $responseData')` — validation maps, HTML error
   pages, whatever the server sent. It also **navigated to Home itself** and
   returned `void`, so the caller could not tell a completed setup from a
   rejected one.

2. **`register_repo` read `responseData['message']` unguarded** on *both*
   branches. A response without that key puts null into a `Text` and throws —
   during account creation.

3. **`invoice_return_repo` showed `error.toString()` on a `kMainColor`
   background** — the raw exception, painted in the app's primary colour, so a
   failed sale return read as a confirmation. Same shape as the `updateSale`
   defect fixed in UI-P2.

4. **`subscribePlan` has no callers.** Dead code. Converted rather than deleted
   (zero usages alone is not grounds) so it is correct if wired up. Its
   `ref.invalidate` calls also sat *inside* the UI branch — Phase 4's warning
   exactly. `ref` stays in the repo; cache invalidation is business state.

### Three raw-error leaks that UI-P2 missed

UI-P2 reported "0 exception leaks". That was measured with a regex matching
`Text('...$e')` and `${e.toString()}`. It did not match:

* `Text('...: $responseData')` — a raw *response body*, not an exception
* `Text(errorMessage)` where `errorMessage = error.toString().replaceFirst(...)`
  — the exception laundered through a variable first

Both forms were found here by reading the code, not by grepping. The lesson is
narrow and worth keeping: a regex sweep proves the shapes it matches are absent,
not that the class of fault is gone.

### A house test corrected

`error_presentation_test.dart` scans source for `Text(error.toString())`. It was
scanning raw file text, so a **comment documenting a fixed instance of that bug**
tripped it. Now strips comment lines before scanning — a scanner that cannot tell
code from the note explaining the code punishes writing the note. (The same
collision occurred in the Group D picker test and was fixed the same way.)

### bulk_upload_repo and customer_due_repo (this session)

`uploadBulkFile` popped the screen itself and showed
`'Failed: ${parsedData['message']}'` unguarded. `downloadFile` took a
`BuildContext` **solely** to raise three SnackBars — it now takes none at all.
`uploadTransactionFile` already had a `context.mounted` guard but still owned its
own messaging.

`collectCustomerDue` returned `CustomerDueCollection?`, so the caller could not
distinguish "no record returned" from "the collection was refused". Now
`DataOutcome<CustomerDueCollection>`, and the failing branch keeps the sheet open
so the entered amount is not lost.

**Phase 6 applied deliberately in both.** `bulk_upload_repo` invalidates four
providers and `customer_due_repo` seven; in each case the invalidation was moved
*before* the return and left unguarded. The catalogue and the due balances have
changed whether or not the screen that started the request is still alive.
Guarding those behind a mounted check would leave stale caches after a user
navigates away mid-upload.

### Screen files completed (16 sites)

| file | sites | how |
|---|---|---|
| `gererate_barcode` | 5 | guards before PDF push, before the device-picker message, and **inside** the sticker loop |
| `settings_screen` | 4 | one guard; three fixed by closing a fall-through |
| `invoice_return_screen` | 4 | cleared by the repo conversion — no further edit needed |
| PDF cluster | 7 | `pdf_common_functions` (2) + four `*_pdf.dart` callers + one export catch |

**`gererate_barcode`'s sticker loop needed a per-iteration guard.** Each sticker
is rasterised against the live element tree and the loop awaits between them, so
a guard before the loop is not enough — a long print run can outlive the screen.

**`settings_screen` needed no guards for three of its four.** The tile handler
awaited a navigation and then fell through to the function-type branch, so every
context use below read as post-await. Adding `return;` after the navigation —
which is correct behaviour anyway, a navigation tile is never also a function
tile — removed three warnings without a single mounted check.

**The PDF cluster shares one helper.** `PDFCommonFunctions.savePdfAndShowPdf`
pushes a viewer after writing the file; two guards there plus one in each of the
four callers covers invoice, due-invoice, sale-due and purchase. The last site
was an Excel export whose catch showed a `Colors.red` SnackBar with no logging —
now `AppLogger` plus `colorScheme.error`.

### Final accounting — the last 33 screen sites

| File | Sites | Resolution |
|---|---|---|
| `purchase_premium_plan_screen` | 3 | failure early-returns; subscription caches invalidated **before** the lifetime check |
| `edit_profile` | 3 | two `Future.delayed` pops guarded; one `mounted` corrected to `context.mounted` |
| `language` | 3 | persistence and locale save stay above the guard; only provider lookup + nav below |
| `custom_print` | 3 | guard before `showDialog`; failure message resolved **before** the pairing await |
| `due_collection_screen` | 2 | invalidation hoisted above the guard; also fixed a false "not found" and an `$e` leak |
| `product_model_list` | 2 | invalidation hoisted; silent delete failure now reported |
| `printing_invoice_screen` | 2 | guards + a `pickedImage!` crash on a cancelled picker |
| `print_thermal_invoice_provider` | 2 | message resolved pre-await, `context1.mounted` guard |
| `sales_transaction_widget` | 1 | guard after the branch check |
| `add_doctor` | 1 | `ref.invalidate` hoisted above the guard |
| `due_list_screen` | 1 | deferred `unfocus` guarded |
| `sale_due_collection_screen` | 1 | same false "not found" as due_collection |
| `sale_due_contact_screen` | 1 | deferred `unfocus` guarded |
| `product_details` | 1 | guard after the branch check |
| `sales_settings_screen` | 1 | cache clear, invalidation and rounding option all stay above the guard |
| `splash_screen` | 1 | guard before `setState` + provider lookup |
| `barcode_preview` | 1 | guard before pop |
| `add_products_models` | 1 | edit branch matched to the create branch's existing guard |
| `add_new_warehouse` | 1 | invalidation hoisted above the guard |
| `warehouse` | 1 | **navigator captured before the pop** — a guard here would have disabled Edit |
| `command_handler` | 1 | guard between the confirmation and `execute` |
| **Total** | **33** | |

Every one of the original 76 is accounted for: 23 repository sites, 43 screen and
PDF sites in the earlier passes, these 33 here. No site disappeared without an
intentional change.

### Findings worth keeping

**`warehouse.dart`'s Edit button could not be fixed with a guard.** It pops the
popup, awaits a frame, then pushes the editor through the *same* context — which
is defunct by then. `if (!context.mounted) return;` would have silently disabled
Edit. The navigator is captured before the pop instead.

**Five screens hid provider invalidation behind a lifetime check.** In
`due_collection_screen` the whole `ref.invalidate` block sat inside
`if (dueData != null && mounted)`, so a payment recorded while the screen was
backgrounded left every due list showing the pre-payment balance. Same shape in
`add_doctor`, `add_new_warehouse`, `product_model_list` and
`sales_settings_screen`. Application state now lands first; only UI is guarded.

**Two screens reported success as failure.** `if (transaction != null && mounted)
{ ... } else { showError('Invoice details not found') }` — an unmounted screen
falls into the else, so a *successful* lookup announced "not found".
(`due_collection_screen`, `sale_due_collection_screen`.)

**`printing_invoice_screen` crashed on a cancelled image picker.** Both branches
did `File(pickedImage!.path)` with no null check; `edit_profile` had the check,
this screen did not.

**Two printer dialogs resolved their failure copy after the await.** Pairing can
outlive the dialog, so `l.S.of(context).tryAgain` was read from a tree that might
be gone. The string is now resolved before the await — the "move UI work before
the gap" fix rather than a guard. (`custom_print`,
`print_thermal_invoice_provider`.)

### Verification

```
Flutter analyze          0   0 errors, 0 warnings, 0 infos  (was 76)
Flutter tests          742   passing
suppressions             0   (production)
inline ignores           0
Laravel                      not run — no backend code changed
```

The only `ignore_for_file` anywhere is `lib/core/database/app_database.g.dart:5`,
a drift build artifact that lives outside `lib/generated/`. Not production code,
not introduced here.

Diff reviewed: no user-facing copy lost (the only strings absent versus `HEAD`
are the raw-exception messages the repo conversions deliberately removed), no
live validator or callback removed (the one `validator:` count drop is a
commented-out block), no API payload, financial, GST or payment logic touched.

**GROUP F — COMPLETE. 76 -> 0.** Groups C, D and E remain green.

---

## GROUP J — Final engineering + production readiness: **IN PROGRESS**

### Fresh baseline (re-measured, not carried over)

```
Flutter analyze          0   0 errors, 0 warnings, 0 infos
Flutter tests          750   passing  (742 + 8 added in J3)
Laravel tests         1069   passing (2931 assertions, post_manage_testing)
production suppressions  0   inline ignores 0
print(                   0
```

`php artisan test` was confirmed to target `post_manage_testing` (forced in
`phpunit.xml`) before it was run. No development or production database was
touched.

### J1 — Baseline + inventory: **COMPLETE**

502 production Dart files. `debugPrint` 148 (mostly inside `AppLogger` itself),
`AppLogger` 215 uses, TODO/FIXME 6 (one is upstream Flutter code in
`cusotm_data_table.dart`). Analyzer clean.

### J2 — Error handling: **COMPLETE — 12 leaks fixed**

The existing `error_presentation_test` was passing while **twelve live sites**
put exceptions in front of users. The scan, not the code, was the problem — it
had been written to match the shape of the offenders that were known at the
time:

- **v1** required `Text(e.toString())` with the call as the *only* argument, so
  `Text(e.toString(), style: ...)` walked past it (`staff_detail_screen`,
  `profile_side_drawer`).
- **v2** added interpolation but anchored it to an `error:` builder, so
  `EasyLoading.showError('Export failed: $e')` inside a `catch` walked past it
  (both report exports, `bulk_purchase`, `invoice_size_repo`, `tax_report` ×2,
  `sale_due_collection_screen`).
- Neither counted `salesError`/`duesError` as error identifiers, which is how
  the **home screen** kept `Text(salesError.toString())` — a bare, unstyled Dart
  exception where the day's activity should be.

| File | Was | Now |
|---|---|---|
| `home_screen` ×2 | `Text(salesError.toString())` | `ErrorState` + `AppLogger` |
| `staff_detail_screen` | `Text(e.toString(), …)` | friendly copy (the retry UI around it was already right) |
| `profile_side_drawer` | `Text(e.toString())` | friendly copy + log |
| `sold_items_screen` | `Text('Error: $e')` | friendly copy + log |
| `product_sale_history_screen` | `'Failed to load history.\n${e}'` | friendly copy + log |
| `sales_report_screen` | `'Export failed: $e'` | matched the correct handler already in the same file |
| `purchase_report` | `'Export failed: $e'` | same |
| `bulk_purchase` | `showError(e.toString())` | friendly copy + log |
| `invoice_size_repo` | `showError(e.toString())` | friendly copy + log |
| `tax_report` ×2 | `'${l10n.errorDeletingTax}: $e'` | the l10n string alone + log |
| `sale_due_collection_screen` | `'Could not record the payment: $e'` | friendly copy + log |
| `purchase_repo` | `'An error occurred: $e'` | friendly copy + log |

Two further defects of the same family, found in passing:

- `expanse_repo` and `parties_repo` interpolated `parsedData['message']`
  **unguarded**, so a response body without that key printed `Delete failed:
  null` / `Error: null`. Both now fall back to operation-specific copy.

**Not leaks — classified and left alone:**

- `purchase_repo` / `sales_repo` prefix the server's own `message` field
  (`'Purchase update failed: $message'`). That is deliberate and documented in
  `purchase_repo` at the `_showError` helper: the server names the cause — a
  credit limit exceeded, a split payment that does not add up — and a shopkeeper
  can act on that. It is a curated field, not an exception.
- `add_customer`'s `'…was not applied: $error'` reads like a leak but `error`
  held a `String?` from `LoyaltyPackageRepo`, which returns either the server's
  `message` or `'Could not reach the server.'` — never an exception. Renamed to
  `loyaltyFailure` so it stops looking like one.

The scan itself is now written against the *identifier* rather than the
surrounding shape, and two extra constraints were needed after it produced false
positives: `[^;{}]` to keep a match inside one statement, and `[^'\n]` to keep a
string literal on one line — without the second it opened a quote in one widget
and closed it two methods later, reporting a `Text('Size')` because an unrelated
`debugPrint` further down the file interpolated an exception.

### J3 — Repository architecture: **COMPLETE (7/7 UI-owning repos)**

#### Fresh inventory (not the old 23-repo list)

**64 repository files.** Scanned with comments *and* string literals stripped, so
documentation describing a removed SnackBar cannot register as one — the J2
lesson applied up front.

**37 carry a UI-ish token in executable code. Classified:**

| Class | Count | Detail |
|---|---|---|
| **A — true UI ownership** | 7 | `sign_up_repo`, `sign_in_repo`, `expanse_repo`, `purchase_repo`, `sales_repo`, `due_repo`, `parties_repo` |
| **F — legitimate infrastructure** | 14 | `BuildContext` passed only to `CustomHttpClient` (subscription-expiry redirect + permission gate), constructed before any await. Already documented in each file. |
| **G — repository owns the loader** | 16 | `EasyLoading` only: `product_repo` (32 calls), `product_models_repo` (19), `warehouse_repo` (19), `branch_repo` (10), `product_setting_repo` (9), `tax_repo` (8), `delete_account_repo` (4), and 9 others |
| **B — cache/provider work** | — | `ref.invalidate` in repositories is kept; it is application state, not UI |

The 27 repositories with no UI token at all are already correct.

#### Done this phase

| Repository | UI sites | Action |
|---|---|---|
| `sign_in_repo` | 4 SnackBar, 3 Navigator, 1 `Theme.of` | → `DataOutcome<SignInDestination>` |
| `sign_up_repo` | 9 SnackBar, 6 EasyLoading | → `DataOutcome<SignUpDestination>` + `SaveOutcome` ×2 |

**`logIn` returned a bare `bool` for three different outcomes** — signed in;
signed in but no business profile yet; *your email is not verified* (HTTP 201).
It navigated to a different screen for each itself. The caller saw only `true`,
so `_saveUserCredentials()` ran on all three, including the branch where no
token had been issued. The destination is now named in an enum and the screen
navigates.

**`signUp` returned `Future<dynamic>`** and the caller separated cases with
`result is String` — a token meant one screen, `true` meant another. Same
treatment.

**Two owners of one spinner.** Both callers already called `EasyLoading.show()`
and the repository called `EasyLoading.dismiss()` — dismissing a loader it had
not started. Dismissal moved to the caller.

**One unguarded read fixed:** `Text(responseData['message'])` on the 201 branch
of sign-in. A response without that key put null into a `Text` and threw *after*
the credentials had been accepted.

8 focused tests added (`auth_repo_outcome_test.dart`) covering the contract
shape, server-message extraction, missing/blank/non-string `message`, and the
saved/failed value invariants.

#### Done — batch 2

| Repository | UI sites | Action |
|---|---|---|
| `expanse_repo` | 6 SnackBar, 2 Navigator, 6 EasyLoading | 3× `Future<void>` → `Future<SaveOutcome>` |
| `due_repo` | 2 SnackBar, 3 EasyLoading | `dueCollect` → `DataOutcome<DueCollection>` |
| `parties_repo` | 2 SnackBar | dead `sendCustomerUdeSms` → `SaveOutcome` |

**All three expense writes returned `Future<void>`** while presenting their own
results — `Navigator.pop` on success, SnackBar on failure. A caller could not
tell an accepted save from a refusal, and the delete path reported *nothing at
all* on success. Now the form pops only when the expense actually saved, and a
refusal keeps the form open with the server's reason on it.

**`expanse_repo` swallowed every exception with no logging.** Three `catch`
blocks showed "Something went wrong" and dropped the error. They log now.

**`dueCollect` returned `DueCollection?`** — `null` meant both "the server
refused" and "the request never arrived", and the server's reason for a refusal
was shown from inside the repository, so the screen never saw it. Amounts,
`payment_type_id`, invoice linkage and split handling are untouched; only the
reporting changed. Verified by diffing the request-body keys against `HEAD`: no
payload drift in any converted repository.

**`parties_repo.sendCustomerUdeSms` is dead** — the only occurrence of the name
across `lib/` and `test/` is its own declaration, it is not provider- or
route-registered, and Dart has no reflective dispatch here. Kept and corrected
rather than deleted (the decision taken for `subscribePlan`): it took a
`BuildContext` and showed its own SnackBars, so wiring it up later would have
reintroduced exactly what this phase removes.

**Loader ownership moved to the screens.** `expanse_repo` dismissed a loader its
callers had started. The repositories now catch everything and answer with an
outcome, so the caller's `EasyLoading.dismiss()` after the await is
unconditional — the same guarantee the repo's `finally` provided.

#### Done — batch 3

| Repository | UI sites | Action |
|---|---|---|
| `sales_repo` | 3 SnackBar, 1 Navigator, 1 AppDialog, 4 EasyLoading | `createSale` → `DataOutcome<SalesTransactionModel>`; `updateSale` → `SaveOutcome` |

**A refused sale edit produced a receipt.** `updateSale` returned `void` and
popped the billing screen itself; the caller then carried on unconditionally —
rebuilding the sale from local state, pushing `SalesInvoiceDetails` and clearing
the cart — whether or not the server had accepted the edit. The invoice is built
from local values, so it looked entirely normal. The screen now stops on a
refusal and shows the server's reason.

**`createSale` collapsed two different failures into `null`.** A server refusal
and a dropped connection were indistinguishable to the caller. The distinction is
now carried by `SaveOutcome.message` exactly as that type documents: a refusal
arrives with the server's own sentence and the caller shows it in a **dialog**;
a transport failure arrives with a null message and the caller supplies the
generic connection line in a snack bar.

That dialog-not-snack-bar treatment was documented in-file as deliberate and is
preserved — it moved to the caller intact, wording unchanged. Verified by
string-set diff: the four sentences that left `sales_repo`
(`'Sale not saved'`, `'The sale was not saved…'`, `'Could not update the sale…'`,
`'Sales update failed: …'`) are all present in `sales_billing_screen`, none were
reworded. No request-body key changed in any converted repository.

#### Done — batch 4: `purchase_repo`

| Method | Before | After |
|---|---|---|
| `createPurchase` | `PurchaseTransaction?` + `_showError` dialog helper | `DataOutcome<PurchaseTransaction>` |
| `updatePurchase` | `PurchaseTransaction?` + static `lastEditViolations` + 2 dialogs/snack bars | `PurchaseUpdateOutcome<PurchaseTransaction>` |
| `deletePurchase` | `Future<void>` + **double `Navigator.pop`** | `SaveOutcome` |
| `fetchPurchaseList` | `EasyLoading.dismiss()` in a *read* | removed |

**UI sites before: 5 SnackBar, 2 Navigator, 1 AppDialog helper, 1 violations
dialog, 9 EasyLoading, 3 `Theme.of`. After: zero.** Verified by a scan that
strips comments *and* string literals, so the file's own documentation naming
those tokens cannot register as usage.

**The result type already existed.** `PurchaseUpdateOutcome<T>` sits in
`purchase_edit_violation.dart` with `saved` / `belowSold` / `failed`
constructors and a `blockedBySales` getter — written for exactly this, with its
own passing tests, and never wired to the repository. No new abstraction was
needed; the brief's suggested shape was already in the codebase.

**The 409 violations travelled in a mutable static.** `lastEditViolations` was a
`static` field on `PurchaseRepo`: the repository parsed the per-line minimums
into it, showed the dialog itself, and returned `null`, and the caller was meant
to read the static *after* the await. Nothing ever read it — the only references
were the two writes and one self-read inside the repository. It is gone; the
violations travel with the result.

**A refused edit produced two messages.** The repository showed the reason (a
dialog for 409, a snack bar otherwise) and then the caller's `else` branch added
"Failed to update purchase. Please try again." on top. Both generic duplicates
are removed and the single meaningful message survives.

**`deletePurchase` has no callers.** The `_deletePurchase` in
`held_sales_list_screen` is a different local method that removes a *held sale*.
Kept and corrected rather than deleted, as with `subscribePlan` and
`sendCustomerUdeSms`. Its double pop — "the detail screen and the sheet that
opened it" — is a decision about a screen stack the repository cannot see and
which matches no live flow; whoever wires it up owns that navigation.

**Request contract unchanged**, checked mechanically against `HEAD`: identical
body keys (no additions, no removals), identical endpoints, identical
`http.post` / `customHttpClient.post` / `.delete` / `clientGet.get` call counts.
No amount, GST, discount, rounding, `payment_type_id`, quantity or batch value
was touched.

**13 tests added** (`purchase_repo_outcome_test.dart`): repository owns no UI;
`BuildContext` survives only as an http-client dependency; the static is gone;
each write reports a typed outcome; 409 field-by-field parsing including
Laravel's decimal-as-string quantities; multi-line violations; blocked vs
ordinary refusal vs transport failure; and two caller tests proving no failure
branch can reach the invoice, cart-clear or navigation.

#### J3 final accounting — fresh inventory, re-run after the work

**64 repository files. Class A (true UI ownership): 0.**

| Repository | UI sites before | Result contract |
|---|---|---|
| `sign_in_repo` | 4 SnackBar, 3 Navigator, 1 Theme.of | `DataOutcome<SignInDestination>` |
| `sign_up_repo` | 9 SnackBar, 6 EasyLoading | `DataOutcome<SignUpDestination>`, `SaveOutcome` ×2 |
| `expanse_repo` | 6 SnackBar, 2 Navigator, 6 EasyLoading | `SaveOutcome` ×3 |
| `due_repo` | 2 SnackBar, 3 EasyLoading | `DataOutcome<DueCollection>` |
| `parties_repo` | 2 SnackBar | `SaveOutcome` (dead method) |
| `sales_repo` | 3 SnackBar, 1 Navigator, 1 AppDialog | `DataOutcome<SalesTransactionModel>`, `SaveOutcome` |
| `purchase_repo` | 5 SnackBar, 2 Navigator, 2 dialogs, 9 EasyLoading | `DataOutcome`, `PurchaseUpdateOutcome`, `SaveOutcome` |

14 repositories keep a `BuildContext` **only** to construct `CustomHttpClient`
(subscription-expiry redirect + permission gate), built before any await —
class F, correct as they stand. 27 carry no UI token at all.

**Deferred, not done: 20 repositories still call `EasyLoading` and nothing else**
— `product_repo` (32), `product_models_repo` (19), `warehouse_repo` (19),
`branch_repo` (10), `product_setting_repo` (9), `tax_repo` (8) and 14 more.
That is loader ownership, not presentation, and it is the next unit of work.

Verification: Flutter analyze 0 · Flutter tests 763 passing · suppressions 0 ·
inline ignores 0 · request contracts unchanged · `git diff --check` clean.

`purchase_repo`/`sales_repo` prefix the server's own `message` field on failure.
That is a curated business message ("credit limit exceeded"), not exception text,
and per Step 4 it stays.

### Remaining Group J items — NOT started

J4 API contracts · J5 financial coverage · J6 shared
components · J7 loading/state UX · J8 date/input consistency · J9 delete paths ·
J10 payment/QR · J11 performance · J12 dependencies · J13 dead code · J14 design
regressions · J15 copy · J16 coverage · J17 production safety · J18 migration
safety.

**Group J is NOT complete.** Groups A–F remain green; Group F stays at 0.

---

## GROUP J4 — Loading / busy state architecture: **COMPLETE (20/20 repositories)**

### Fresh inventory at the start

20 repositories, **134 EasyLoading calls**, split as the brief required:

```
loading ownership   show 17 + dismiss 64 = 81
user feedback       showSuccess 11 + showError 42 = 53
```

### Final accounting — every repository

| Repository | Before | After | Caller loader | Result type |
|---|---|---|---|---|
| `product_repo` | 32 | 0 | rebalanced (3 callers already owned it) | `SaveOutcome` ×4 |
| `product_models_repo` | 19 | 0 | **added** | `DataOutcome<Data>`, `SaveOutcome` ×2 |
| `warehouse_repo` | 19 | 0 | **added** | `SaveOutcome` ×3 |
| `branch_repo` | 10 | 0 | already owned | `SaveOutcome` ×2 |
| `product_setting_repo` | 9 | 0 | **added** | `SaveOutcome` |
| `tax_repo` | 8 | 0 | already owned | unchanged (`TaxOutcome`) |
| `invoice_return_repo` | 6 | 0 | already owned | unchanged |
| `user_role_repo` | 5 | 0 | **added** | unchanged |
| `delete_account_repo` | 4 | 0 | **added** | `SaveOutcome` |
| `invoice_size_repo` | 4 | 0 | **added** | `SaveOutcome` |
| `business_info_update_repo` | 3 | 0 | already owned | `SaveOutcome` + `lastUpdateError` |
| `business_setup_repo` | 3 | 0 | `LoadingHelper` already | unchanged |
| `customer_due_repo` | 3 | 0 | already owned | unchanged |
| `phone_auth_repo` | 2 | 0 | dismiss added | unchanged |
| `income_repo` | 2 | 0 | dismiss added | unchanged |
| `logout_repo` | 1 | 0 | success moved to caller | unchanged |
| `change_pass_repo` | 1 | 0 | dismiss added | unchanged |
| `forgot_pass_repo` | 1 | 0 | dismiss added | unchanged |
| `expanse_category_repo` | 1 | 0 | dismiss added | unchanged |
| `income_category_repo` | 1 | 0 | dismiss added | unchanged |
| **Total** | **134** | **0** | | |

### Repository-wide audit (64 files, comments and string literals stripped)

```
EasyLoading.show / dismiss / showSuccess / showError   0
showProgress · CircularProgressIndicator · Linear…     0
SnackBar · ScaffoldMessenger · Navigator.              0
showDialog · showModalBottomSheet · AppDialog · toast( 0
Theme.of · S.of(                                       0
error.toString() in a repository                       0
```

### Defects found and fixed

1. **`tax_repo` cleared its callers' overlays mid-request.** Eight `dismiss()`
   and no `show()`; every caller raises its own. The spinner vanished while the
   request was still running.
2. **`tax_report` dismissed its own success message.** `finally { dismiss(); }`
   ran after `showSuccess`/`showError` had been queued, cancelling the very
   toast it followed — a tax delete reported nothing at all. Dismissal now
   precedes the message.
3. **Three save flows would have hung on a stuck spinner.** `add_income`,
   `add_expense_category`, `add_income_category` raised an overlay and never
   dismissed it, relying entirely on the repository.
4. **Silent failures**, all masked by a repository toast: `add_products_models`
   (`if (success)` with no else on both branches), `product_setting_drawer`,
   `edit_profile` (its else branch only dismissed a loader), both
   `printing_invoice_screen` saves, `branch_list` and `home_screen` branch
   switching.
5. **Duplicate messages:** `createProduct` (repo + localised caller toast),
   `deleteStock`, `addStock`, `invoiceSizeChange`, `deleteAccount`.
6. **Reads driving global overlays:** `fetchProductDetails`, `fetchModelsList`,
   `fetchWareHouseList`, `fetchProductSetting` — all behind `FutureProvider`,
   all raising toasts over the `AsyncValue.error` consumers already receive.
   `fetchModelsList` is also called from `_loadDropdownData`, which loads seven
   lookups in a row, so its `dismiss()` cancelled the caller's overlay for the
   other six.
7. **Unguarded `['message']` reads:** `updateSalesSettings` (both branches),
   `deleteAccount` — a body without that key put `null` into the toast.
8. **A third class of raw-exception leak.** `GstSaveResult.invalid({'_':
   'Could not save: $error'})` is passed to `_report` and shown verbatim; and
   `ProductDeleteOutcome.failed(message: 'Error: $e')` likewise. Both travelled
   through a **result object**, not a presentation call, which is exactly why
   the J2 sweep never saw them. The new test scans result messages too.

### Tests

`repository_loader_ownership_test.dart` — 4 tests: the repository set is
non-empty (so a path change cannot make the suite pass vacuously); no repository
raises or clears an overlay; none presents a message, dialog or navigation; no
exception text is packed into a result message.

### Full regression

```
flutter clean + pub get      done
Flutter analyze                0   0 errors, 0 warnings, 0 infos
Flutter tests                767   passing
Laravel tests               1069   passing (2931 assertions)
suppressions                   0   inline ignores 0
git diff --check              clean
request-contract drift         0   across 49 repository files
```

Contract check compares every changed repository against `HEAD` for body keys,
endpoints and HTTP call counts: no drift.

**Groups A–F, J1, J2, J3, J4 complete.**

---

## FIELD REPORT — reported defects and production readiness

### Fixed and covered by tests

| # | Defect | Cause | Proof |
|---|---|---|---|
| 1 | Stock List tile dead | `route: 'Stock'` had no handler anywhere | removed |
| 2 | Quantity only +/- | batch popup rendered its controller as read-only `Text` | typed field, clamped on commit |
| 3,7 | Payment methods invisible on 5 screens | card/split mode never rendered the types it was given | 6 tests |
| 4a | Product stock never moved after an audit | audit wrote `purchase_details` only, never `stocks.productStock` | 9 tests; reverting the fix fails 4 |
| 4b | Inventory figure wrong after an audit | counted stock units compared against sellable units | tests failed `7.0 vs 70.0` before fix |
| 6 | HSN/Cess fields | removed; controllers kept so existing GST data round-trips | — |
| 8 | Relationship filter inert | no branch for relationship without a phone | 6 tests |
| 9a | Every edited party became a Supplier | `groupValue` hardcoded in `_initializeFields` | reads stored type, safe fallback |

Backend 1092 passing (was 1069), Flutter 773, analyzer 0.

### Diagnosable, not yet root-caused

**#5 loyalty packages.** Backend proven correct — routes, controller, permissions,
all 7 migrations applied, schema complete, 8 new endpoint tests passing against
the app's exact payload. An unauthenticated probe of
`https://dev.naayahs.com/api/v1/loyalty-packages` returns **401, not 404**, so the
route *is* deployed; the "not deployed" theory is disproved. The client now
raises `LoyaltyRequestException` carrying the status and the list screen says
which it was — 401 session, 403 account permission, 404 feature absent, 5xx
server. The next run names the cause.

**#9 customer edit.** One real defect fixed (above). The "Something went wrong"
text comes from a `catch`; both repository calls return outcomes rather than
throwing, so whatever throws is in this screen's own payload assembly. The catch
logged `'Error'` with no operation and no stack — it now names create vs edit and
carries the stack.

### Production readiness

**Fixed:** `.env.example` carried a populated `APP_KEY`, `MAIL_PASSWORD` and two
Pusher keys. None matched the live `.env` (verified by hash, values never
printed), so nothing leaked — but a valid-looking `APP_KEY` in a tracked file
invites deploying with it, which makes signed cookies and encrypted values
forgeable. All four blanked.

**Verified clean:** no `.env`, keystore, `.jks` or service-account JSON tracked;
`.env` is gitignored; release signing reads from `key.properties`;
`APIConfig.assertConfiguredForRelease()` intact and correctly refuses a release
build pointed at the dev host.

**Blockers for a Play release:**

1. **The APK is 168 MB and cannot be uploaded** — Play's single-APK ceiling is
   100 MB. It is a *universal* APK carrying four ABIs: arm64-v8a, armeabi-v7a,
   x86 and x86_64. Per device the payload is ~45 MB; the rest is three redundant
   architectures. Build `flutter build appbundle --release` (Play then delivers
   per-device) or `--split-per-abi` for direct distribution.
2. **`minifyEnabled false` and `shrinkResources false`** on release — no R8, no
   shrinking. Worth enabling, but it can break reflective code, so it needs a
   pass of device testing rather than a blind flip.
3. **No production API URL.** Every build so far defaults to
   `dev.naayahs.com`. A production build needs
   `--dart-define=API_BASE_URL=... --dart-define=API_DOMAIN=... --dart-define=API_STORAGE_URL=...`.
4. `ios/Runner/GoogleService-Info.plist` is tracked. Google treats it as
   non-secret and it ships in the bundle, so this is a judgement call rather than
   a leak — flagged, not changed.

**Group J remains open:** J5–J18 (financial coverage, shared components,
loading/state UX, dates, delete flows, payment/QR, performance, dependencies,
dead code, design regressions, copy, coverage, production safety, migrations).

---

## FINAL PRODUCTION CLOSURE PASS

### Corrected premise

The brief listed "~76 use_build_context_synchronously remaining". Measured: **0**.
Group F closed that and the analyzer confirms it. No work was done against that
item; it was already complete.

### Completed this pass

**J6 financial — purchase invoice persistence (P0).** Purchase had heavy
calculation coverage but no round-trip test: the invoice recalculates nothing, it
renders stored values. 10 tests covering plain, GST, flat discount, percentage
discount, shipping, part payment, paid+due identity, decimal prices, change and
payment type — the purchase-side mirror of `SaleInvoicePersistenceTest`.

**J8 UPI QR.** The ambiguous-configuration requirement was *already* satisfied:
`upi_qr_mode` (auto/dynamic/uploaded) is the documented deterministic rule, and
the config screen warns when "uploaded" is chosen with no image. What was missing
was proof. 8 tests covering all four configurations, uploaded-wins precedence,
and that a static code reports it cannot carry the amount — losing that flag
makes a static QR silently under-collect, because the customer types their own
figure.

**J9 error handling — three new channels found.** Earlier sweeps matched
*presentation calls* (`Text(...)`, `showError(...)`). The exception was reaching
users by three other roads:

| Channel | Sites | Example |
|---|---|---|
| `ErrorState(message:)` | 15 | `ErrorState(message: e.toString())` across 12 screens |
| local component copy | 1 | `_ErrorState(message: '$error')` in the GST report |
| global handler | 1 | `toast('UI Error: ${details.exception}')` in `main.dart` |

All 17 fixed with operation-specific copy plus `AppLogger`. The duplicate
`_ErrorState` was deleted in favour of the design-system component.
`error_presentation_test` now asserts all three channels, and the assertions were
verified against `HEAD` to confirm they catch the original code.

**J4 dependencies.** 66 declared; 12 had no `package:` import. Six proven unused
and removed — `flutter_grid_button`, `youtube_player_flutter`,
`animated_notch_bottom_bar`, `faker`, `flutter_slidable`, `google_fonts` —
dropping 14 transitive packages. `youtube_player_flutter` alone pulls a webview,
and `faker` is test-data tooling that had no business in production dependencies.
Six kept and documented: `sqlite3_flutter_libs` (native library, loaded by name —
removing it breaks the database), `cupertino_icons` (font asset), `intl_utils`
(l10n tooling), `collection` (27 references), `barcode_widget`, `screenshot`.

**J18 security.** `.env.example` verified placeholders-only. Tracked-file scan
clean except `ios/Runner/GoogleService-Info.plist`, which Google treats as
non-secret and which ships in the bundle — flagged, not changed. Signing
externalised to `key.properties` with a build guard that fails a release when it
is absent.

**J19 Android release — R8 prepared, not enabled.** `proguard-rules.pro` written
with keep rules for the Flutter embedding, the thermal-printer SDK, ML Kit
barcode, the PDF renderer, sqlite, Firebase, coroutines and enum reflection, plus
`SourceFile`/`LineNumberTable` so release stack traces stay readable. Wired via
`proguardFiles`; `minifyEnabled` deliberately left **false** with the reasoning
in-file and a device checklist. R8 strips reflectively-reached code and the
failure only appears in release, in a shop — enabling it without a device pass
would be a guess.

### Final verification

```
Flutter analyze              0    (0 errors, 0 warnings, 0 infos)
use_build_context_sync       0
Flutter tests              784    (was 773)
Laravel tests             1102    (3078 assertions; was 1069)
production suppressions      0    inline ignores 0
print(                       0
local ErrorState copies      0
git diff --check         clean    both repositories
```

### External blockers — unchanged, none resolvable internally

1. **Production API URLs** not supplied. Every build defaults to
   `dev.naayahs.com`. The release guard correctly refuses such a build.
2. **AAB build blocked by tool permission.** Not bypassed. The 168 MB universal
   APK carries four ABIs; per device the payload is ~45 MB, so
   `flutter build appbundle` resolves the Play size limit outright.
3. **Physical device** for R8 verification, printer, and visual checks.
4. **Firebase production config** not supplied.
5. **Disposable Postman credentials + throwaway database** for collection
   execution. Never run against production data.

### Remaining internal work

**J5, J7, J11–J17 are not complete.** Performance profiling, shared-component
sweep, loading/state UX, date consistency, delete flows, bottom-sheet phase 4,
reports audit, invoice logo formats. These need either a device or a full screen
sweep and were not started rather than half-done.

`applicationId` is `com.acnoo.pospro` — the template vendor's identifier. It is
permanent once published to Play and should be confirmed against the intended
account before the first release.

---

## J11 PERFORMANCE — refresh handlers

### The finding

Sixteen screens waited on a clock instead of on their data:

```dart
_isRefreshing = true;
ref.invalidate(someProvider);
await Future.delayed(const Duration(seconds: 1)); // Optional delay
_isRefreshing = false;
```

`ref.invalidate` returns immediately — it marks the provider dirty and does not
wait for the refetch. `RefreshIndicator` holds its spinner until the future it
was handed completes, so the fixed sleep was standing in for the load. Wrong in
both directions: a fetch slower than the sleep lost its spinner while still
loading, and a faster one held the user for the remainder of it.

**The home screen slept for three seconds** — the busiest screen in the app,
stalling every pull-to-refresh regardless of how quickly the figures returned.

All sixteen now await `provider.future`. Verified every provider involved is a
`FutureProvider` before changing it.

### Two further defects the guard test found

Writing the regression test surfaced things the manual pass had missed:

* **`home_screen`** held a 3-second sleep the first sweep did not match, because
  its duration differed from the others.
* **`package_screen`** awaited a refetch with no `try`/`finally`. A throw — an
  offline till is the ordinary case — left `_isRefreshing` set, and the early
  return at the top of the method then refused every later pull-to-refresh for
  the life of the screen. Permanently broken refresh, from one dropped request.

The test asserts the property rather than one spelling of it: a handler is
protected by either a `finally` or a catch that falls through to the reset.

### Deliberately left alone

`splash_screen`'s `Duration(seconds: 1)` is a **retry backoff** between sign-in
attempts, not a refresh. Removing it would fire three requests instantly at an
unreachable server. The test asserts it stays.

### Also completed

Scanned for the rest of the brief's performance list: **no repository or HTTP
call is made from inside `build()`** anywhere in the tree.

### Verification

```
Flutter analyze     0
Flutter tests     787   (was 784)
Laravel tests    1102
git diff --check  clean
```

---

## J5 SHARED COMPONENTS — loading states: **COMPLETE**

### Two stale premises in the brief, corrected by measurement

* "Analyzer: 0 errors, 0 warnings, **77 infos**" — measured **0 infos**. Group F
  closed `use_build_context_synchronously` and it remains 0.
* "Add `PurchaseInvoicePersistenceTest`" — it already exists with 10 passing
  tests, added earlier in this pass. F13 is closed, not open.

### What the audit found

`Card(` — **zero** raw uses. That migration was already complete; nothing to do.

`Colors.white` — present but overwhelmingly legitimate (icon and label colours on
brand-coloured bars and buttons). The brief says not to replace mechanically, and
this is the case it was written for. Left alone.

The one coherent, bounded item was loading state: **34 sites across 26 files**
centred a bare `CircularProgressIndicator` for a full-area load while
`AppLoader.centered()` existed for exactly that.

### What was migrated, and what deliberately was not

Only the plain idiom — `Center(child: CircularProgressIndicator())` with no
arguments. A spinner carrying `strokeWidth`, `size` or `color` is a deliberate
inline or in-button indicator: a 16px spinner inside a save button is not the
same control as a page load, and converting those would have been exactly the
mechanical substitution the brief warns against. 27 such sites remain, correctly.

Guard test added asserting the plain idiom does not return, plus a render test
for `AppLoader.centered`.

### A formatting mistake, caught and repaired

`dart format lib/` (whole tree, rather than the changed files) reflowed files
this item never touched and exposed 5 pre-existing `curly_braces` lints in
`gst_components.dart` and `business_info_update_repo.dart`, plus 11
`unnecessary_const` from the migration itself. All 16 fixed properly — braces
added, redundant `const` removed — rather than left or suppressed. Analyzer back
to 0. Worth noting: this is the second time whole-tree formatting has widened a
diff beyond the work in hand.

### Verification

```
Flutter analyze     0
Flutter tests     789   (was 787)
Laravel tests    1102
git diff --check  clean
```

### Still open in J5

Typography, spacing and radius token adoption across the priority screens has not
been swept. It needs a screen-by-screen visual judgement rather than a pattern
match, and honestly needs a device to confirm — marked for the next pass rather
than half-done.

---

## J7 DATE CONTRACT: **COMPLETE**

### Root cause

`AcnooDueController::preparePaymentDate` ran *before* validation and called
`Carbon::createFromFormat('d/m/Y', ...)` unguarded. Anything not in that exact
shape — an ISO date, a typo, an impossible day — threw inside the parser and
reached the shopkeeper as a **500**, rather than the 422 the `required|date`
rule exists to produce. A mistyped date read as "the server is broken".

`CustomerDueController` had already been corrected. The two collect the same kind
of payment, so they now read the same contract; a silent disagreement between
them is how one ends up wrong again.

### Contract, confirmed by tracing

Both due screens send `DateFormat('dd/MM/yyyy')`. The server accepts that,
accepts ISO unchanged, and refuses anything else with a 422.

### Tests

`DuePaymentDateContractTest` — 6 tests: the app's slash format, ISO accepted
unchanged, malformed refused not crashed, impossible date refused not crashed,
missing date refused, and the customer-due endpoint agreeing. **Verified by
reverting the fix: 3 of the 6 fail**, including both malformed cases.

## J12 DELETE FLOWS: **COMPLETE**

### What was already right

`delete_confirmation_test.dart` (8 tests) covers the dialog's behaviour. The six
lookup lists delegate to `LookupListScreen`, which confirms via
`AppDialog.confirm`; `customer_details`, `warehouse`, `expense_list` and the
product flows each have their own. An initial scan flagged 21 files, and
inspection showed all but one were false positives — either delegating, or cart
row-removal which should not prompt.

### The one real gap

**`product_model_list` deleted a salt on a single tap.** The permission check was
the only thing between a mis-tap and the record going. It now asks, with the same
wording pattern the lookup lists use, including the honest note that the server
will refuse if products still reference it.

### Tests

`delete_is_confirmed_test.dart` — the structural half that the existing dialog
test cannot cover: every screen calling a `delete…` repository method must reach
it through a confirmation. Exemptions are documented and justified in the file
(`deleteToCart` on an uncommitted cart, session teardown, batch-picker clearing),
and a second test guards the `LookupListScreen` exemption so six screens cannot
pass merely by mentioning its name.

### Verification

```
Flutter analyze     0
Flutter tests     792   (was 789)
Laravel tests    1108   (3095 assertions; was 1102)
git diff --check  clean, both repositories
```

---

## CUSTOMER DUPLICATE RULE: **COMPLETE** (product decision applied)

### Decision

Identical customer names are valid. The phone number is the duplicate
constraint. `nameMatch` removed from the blocking check; phone uniqueness and
tenant isolation untouched.

### Two defects in the same block

1. **`phoneMatch || nameMatch`** refused a second "Rahul Kumar" outright.
2. **An empty phone matched every phone-less contact.** `(party.phone ?? '')
   .trim() == newPhone` with `newPhone` empty is true for every walk-in saved
   without a number, so the *second* phone-less customer was refused as a
   duplicate of the first. The block now only applies when a phone was entered.

Also corrected the message: it said "Supplier already exists" on a screen that
adds customers too, and now names the phone as the reason.

### Tests

`PartyDuplicateRuleTest` — 7 tests: same name + different phone allowed; same
phone refused; a contact keeps its own phone when edited; another business may
hold the same phone; several contacts may have no phone; and the unrelated
required-field and type validations still refuse. The server rule is proven
**unweakened**.

## J13 BOTTOM SHEETS: **COMPLETE — no migration warranted**

### Correcting my own count

I reported 30 sheet sites. That was wrong: the grep filtered comments against the
`path:line:` prefix rather than the source line, so commented-out sheets counted.
The real figure is **13 live sheets**, which matches the brief's ~14.

### Why nothing was migrated

`bottom_sheet_conventions_test.dart` already asserts, across every live sheet,
the exact properties J13 lists as acceptance: `useSafeArea`, keyboard padding
where the sheet contains inputs, scheme-derived surface, and `AppDialog.sheet`'s
own behaviour. It passes.

Inspecting the generic candidates — the report filter sheets, the view modal —
each already sets `isScrollControlled`, `useSafeArea` (or wraps in `SafeArea`)
and pads for `viewInsets`, *and* carries its own drag handle and corner radius.
Forcing them through `AppDialog.sheet` would strip that presentation and gain no
behaviour. That is the blind replacement the brief warns against, so the
property-based guarantee stands instead of a constructor-based one.

### Verification

```
Flutter analyze     0
Flutter tests     792
Laravel tests    1115   (3116 assertions; was 1108)
git diff --check  clean, both repositories
```

---

## J14 REPORTS — filter/query source of truth: **COMPLETE**

### Three defects, all divergences between what the UI shows and what the
### request carries

**1. Shown but not sent** (`sales_report_screen`, `purchase_report`).
The filter controllers are `State` fields and outlive the sheet. Typing
"Mother" into Relationship and dismissing without pressing Apply left the text
in the field: reopening showed a filter the request had never included, so the
report looked filtered and was not. The sheet now seeds its drafts from the
applied values on open, so an abandoned edit discards itself — which is what
dismissing a sheet means.

**2. Sent but not applied** (`purchase_report`).
`phone` was read straight off the controller at the request site while `name`
went through `_selectedName`. A typed-then-abandoned phone still filtered the
next fetch, and the "filtered" chip lit on the first keystroke — the opposite
failure, in the same sheet. Phone now has an applied `_selectedPhone`, committed
by Apply, cleared by Clear, and read by both the request and the indicator.

**3. The export disagreed with the screen** (`sales_report_screen`).
The list read `_selectedPhone`; the **export** read the draft controller. A
typed-but-not-applied number therefore exported a different set of rows from the
one displayed. Both now read the applied value.

The third is the one that would have been hardest to notice and worst to
discover: a shopkeeper reconciling an exported spreadsheet against the screen.

### The rule now in force

`_selected…` is the applied value; the controller is the draft; Apply copies
draft → applied; opening the sheet seeds draft ← applied; Clear resets both and
refetches.

### Tests

`report_filter_state_test.dart` — 6 tests: no filter is sent from a draft
controller; each report exposes applied fields; the sheet seeds drafts on open;
the filtered indicator follows applied state rather than typing; Clear resets
both halves; Clear refetches.

### Verification

```
Flutter analyze     0
Flutter tests     798   (was 792)
Laravel tests    1115
git diff --check  clean, both repositories
```

### Remaining in J14

The date-range, pagination and export paths of the other six reports were not
re-audited to the same depth; the two highest-traffic reports were taken first
and the guard test covers them. Stock Audit and Rack/Shelf hold their filters in
providers rather than sheet controllers, so the divergence shape above does not
apply to them.

---

## J14 REMAINING REPORTS: **COMPLETE**

Scanned every screen for the draft-into-request shape. Four hits — `add_doctor`,
`add_sales`, `printer_settings`, `add_sale_customer` — are **form submissions**,
where reading the controller is correct. No filter sheet among them.

Only three reports use sheet-held drafts. Two were fixed earlier; the third,
`sale_due_report_screen`, had the same inconsistency **client-side**: the list
filtered on `_relationshipController.text` directly while minimum-due went
through `_minDueFilter`. Dismissing without Apply still filtered by
relationship, and the "filtered" indicator counted a draft. Relationship now has
an applied `_appliedRelationship`, committed by Apply, cleared by Clear, seeded
on open.

Expense, Stock Audit and Rack/Shelf hold filters in providers rather than sheet
controllers, so the divergence cannot occur there.

## ADD ALL: **COMPLETE (wiring, not building)**

### Root cause

The whole feature already existed and was unreachable. `CategoryBulkAddRepo`
against `/categories/{id}/bulk-add`, the `CategoryBulkAdd` model with per-line
skip reasons, `addCategoryToCart` merging into the cart without clearing it,
`reportBulkAdd` for the outcome, and a `bulk_add_enabled` flag on the category —
all shipped. **`addCategoryToCart` had no callers.** The sales screen even
imported the action file.

### Fix

Wired the existing engine into `sales_products_list_screen`: an "Add All" action
pinned after the category chips, shown only when the selected category has
`bulkAddEnabled`. No second implementation — the engine caps quantities from the
server precisely so availability is not decided twice, which is the mistake its
own comments warn about.

`CategoryChipBar` gained an optional `trailing` slot (defaulting to null, so no
existing caller changes) rather than the screen rebuilding a chip rail.

Double-tap is blocked by an `_addingCategory` guard cleared in a `finally`; a
refused category shows the server's reason via `BulkAddUnavailable`; anything
unexpected is logged, not rendered.

### Tests

`add_all_wiring_test.dart` — 9 tests: the engine is called; no second
implementation; offered only for configured categories; the in-flight guard
exists, disables the button and is cleared in a `finally`; refusals show the
server reason; failures are logged not shown; and the result model reports
nothing-to-add and preserves skip explanations.

**DEVICE VERIFICATION REQUIRED:** the button's placement in the chip rail at
small widths, and the end-to-end add against a real category.

### Verification

```
Flutter analyze     0
Flutter tests     807   (was 798)
Laravel tests    1115
git diff --check  clean, both repositories
```

---

## EXPECTED HANDOVER: **COMPLETE**

### Root cause

The calculation was never the problem and is untouched. `_cashTerms` and
`_upiTerms` carry signed terms; the totals are summed *from* those lists via
`_sum`, so the breakdown cannot drift from the figure it explains — that was
already right.

The presentation was wrong: **both breakdowns rendered permanently** on the
dashboard. Eight terms each, sixteen rows between them, pushing every other card
below the fold on a phone — for a calculation that matters at one moment in the
day.

### Fix

`_HandoverCard` now shows the three figures someone actually hands over — Cash,
UPI, Total — plus a **View Details** action. The details open in
`AppDialog.sheet`, reusing the *same* `_HandoverBreakdown` and `_AmountRow`
widgets, so the person counting the drawer still sees why the figure is what it
is. No new calculation, no second sheet framework, content in a
`SingleChildScrollView` so a long breakdown scrolls rather than overflows.

Dashboard imports tidied where the design-system barrel subsumed them.

### Tests

`expected_handover_test.dart` — 9 tests. Beyond the obvious ones, three assert
the properties that make splitting summary from detail safe:

* both totals are summed from the term lists rather than written out separately
  — writing either figure independently is how a breakdown starts contradicting
  its own total;
* `adds: false` still changes the **arithmetic**, not merely the styling;
* Counter Amount is still the first term of each side — it is the opening float,
  and dropping it silently understates the drawer.

**DEVICE VERIFICATION REQUIRED:** the sheet's appearance at small widths and the
card's vertical footprint on a real phone.

### Verification

```
Flutter analyze     0
Flutter tests     816   (was 807)
Laravel tests    1115
git diff --check  clean, both repositories
```

---

## J15 INVOICE — authoritative values: **COMPLETE**

### Inventory

4 PDF renderers, 4 thermal renderers, 4 detail screens. Scanned all twelve for
arithmetic that recomputes a headline total instead of reading the persisted
one.

### Defect 1 — the receipt re-derived what the server had stored

Four renderers computed `paidAmount = totalDue - dueAmountAfterPay`:
`due_invoice_pdf` (×2), `sale_due_invoice_pdf`, `due_invoice_details`,
`sale_due_invoice_details`.

`payDueAmount` is persisted, validated and already on the model. Subtracting two
snapshots is a second calculation of the same number inside the renderer — and
when `totalDue` is absent, the `?? 0` above it turns the receipt's **paid figure
negative**. All four now read `payDueAmount`, keeping the subtraction only as a
fallback for records saved before the field existed.

### Defect 2 — cross-tenant payment types (found by the test, not by reading)

`AcnooDueController` validated `'payment_type_id' => 'required|exists:payment_types,id'`
— a **global** existence check. Another business's payment type satisfied it, was
stored against this business's collection, and would have been printed on the
receipt. `party_id` had the same unscoped rule.

`CustomerDueController` already scoped both. The two controllers had diverged —
the same way they had over the date format. Both rules are now scoped to the
signed-in business.

This is the third defect found in the pair by comparing them against each other;
they collect the same kind of payment and drift apart silently.

### Tests

`DuePaymentMethodTest` — 5 tests: each method stored exactly as chosen; a card
sale settled in cash records **cash** (the inheritance defect); the paid amount
is persisted rather than left to the receipt; a payment type from another
business is refused; a missing type is refused.

**DEVICE VERIFICATION REQUIRED:** visual PDF rendering and thermal print output.
Logo format handling (png/jpg/svg/webp/bmp/gif/missing) was fixed and covered
earlier and remains intact.

### Verification

```
Flutter analyze     0
Flutter tests     816
Laravel tests    1120   (3130 assertions; was 1115)
```

---

## TENANT ISOLATION AUDIT: **COMPLETE — one real vulnerability fixed**

### Method

Scanned every controller and form request for bare `exists:table,id` rules —
global checks on tenant-scoped tables — then traced each to see whether anything
downstream enforced ownership. 23 bare rules found.

### Classified

**Legitimately global (no change):** `plans`, `business_categories`,
`businesses` — shared reference tables, not tenant data.

**Guarded downstream (no change):** `SaleReturnController` and
`PurchaseReturnController` both follow the bare rule with
`Sale::where('business_id', …)->findOrFail(…)`. The weak rule only costs a less
precise error, not isolation.

**Genuinely exploitable — FIXED:** `AcnooProductController::update`.

### Root cause

`StoreProductRequest` scopes every lookup with the existing `TenantOwned` rule.
`update` had drifted to bare `exists:` on `unit_id`, `brand_id`,
`manufacturer_id`, `category_id`, `model_id`, `vat_id`, `upperSelectedUnit` and
`lowerSelectedUnit` — while the row is written from the request, so the ids
reached the column unchanged.

An edit could therefore file this shop's product under **another shop's**
category, brand or unit. `vat_id` is the serious one: a foreign VAT applies that
shop's tax rate to this shop's sales — a financial error, not only a data leak.
A bare `exists` is also an enumeration oracle, since a real id from another
tenant validates while an invented one does not.

### Fix

All eight switched to `TenantOwned::in(...)` — the rule the store path already
used, not a new helper.

### Tests

`ProductTenantScopingTest` — 6 tests: foreign category, VAT, brand and unit each
refused; the shop's own lookups still accepted; another business cannot edit the
product at all. **Verified by reverting the fix: 4 of 6 fail.**

## J15 addendum — `AcnooDueController` vs `CustomerDueController`

Comparing the pair, as instructed, found the third divergence between them:
`payment_type_id` and `party_id` were bare `exists` in `AcnooDueController` while
`CustomerDueController` scoped both. Fixed and covered by `DuePaymentMethodTest`.

These two controllers have now drifted apart three times — date format, paid
amount, tenant scoping. Collapsing them onto shared validation is the structural
fix; recorded as a recommendation, not done here.

### Verification

```
Flutter analyze     0
Flutter tests     816
Laravel tests    1126   (3148 assertions; was 1120)
git diff --check  clean, both repositories
```

