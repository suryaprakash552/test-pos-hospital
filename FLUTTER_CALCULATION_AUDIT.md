# Flutter calculation audit

Where `mtl_pos` computes money or inventory, whether that figure is authoritative,
and what replaces it server-side.

The rule applied throughout: a figure the customer is charged, or that changes
stock, must be produced by Laravel. Flutter may compute the same thing for
responsiveness, but only as a display estimate that the server's answer overwrites.

## Status legend

| Status | Meaning |
| --- | --- |
| **Superseded** | Server now calculates this; the client value is ignored on arrival. |
| **Display-only** | Never sent, or sent and discarded. Safe to keep. |
| **Open** | Still trusted somewhere, or not yet migrated. |

## Sale / checkout path

| File | Method | Calculation | Kind | Server replacement | Status |
| --- | --- | --- | --- | --- | --- |
| `Screens/Sales/sales_cart_screen.dart` | `_grandTotal` | Σ line totals | Business-critical | `CartCalculationService::calculate` → `subtotal` | **Superseded** |
| `Screens/Sales/sales_cart_screen.dart` | `_grandDiscount` | Σ line discounts | Business-critical | `CalculatedCart::lineDiscountTotal` | **Superseded** |
| `Screens/Sales/sales_cart_screen.dart` | `_grandGst` | Σ line tax | Business-critical | `CalculatedCart::taxTotal` | **Superseded** |
| `Screens/Sales/sales_cart_screen.dart` | `_grandOverall` | Σ line payable | Business-critical | `CalculatedCart::grandTotal` | **Superseded** |
| `Screens/Sales/sales_billing_screen.dart` | `calculateTotalGST` | Per-line inclusive/exclusive GST | Business-critical | `CartCalculationService::lineTax` | **Superseded** |
| `Screens/Sales/sales_products_list_screen.dart` | `_priceForCustomer` | Price tier by customer type | Business-critical | `PriceResolver::forStock` | **Superseded** |
| `Screens/Sales/Repo/sales_repo.dart` | sale POST body | `totalAmount`, `vat_amount`, `dueAmount`, `paidAmount`, `change_amount`, `rounding_amount`, `actual_total_amount`, per-line `price` | Business-critical | Stripped in `AcnooSaleController::store` via `$request->except(...)`, replaced by `CalculatedCart::saleColumns()` | **Superseded** |
| `Screens/Sales/Repo/sales_repo.dart` | `paidAmount: totalAmount - dueAmount` | Client-derived paid | Business-critical | `CartCalculationService::settlement` | **Superseded** |
| `model/add_to_cart_model.dart` | `SaleCartModel` fields | Holds client prices/discounts | Input intent | Fields still sent; server ignores the monetary ones | **Display-only** |

## Purchase path

| File | Method | Calculation | Kind | Server replacement | Status |
| --- | --- | --- | --- | --- | --- |
| `Provider/add_to_cart_purchase.dart` | `calculatePrice` | cart subtotal, GST, discount, shipping, rounding, payable, due/change | Business-critical | `PurchaseCalculationService::calculate` → `purchaseColumns()` | **Superseded** |
| `Provider/add_to_cart_purchase.dart` | `calculateDiscount` | overall discount, clamping | Business-critical | `PurchaseCalculationService::orderDiscount` | **Superseded** |
| `Provider/add_to_cart_purchase.dart` | `recalculateItemFields` | per-unit conversion price / cost | Business-critical | `CalculatedPurchaseLine::conversionPurchasePrice` | **Superseded** |
| `Provider/add_to_cart_purchase.dart` | `getTotalAmount` | gross cart value | Display-only | — | **Display-only** |
| `core/pricing/purchase_line_amounts.dart` | `purchaseLineAmounts` | gross/discount/net/gst/payable | Business-critical | `PurchaseCalculationService::calculateLine` | **Superseded** |
| `Screens/Purchase/Repo/purchase_repo.dart` | purchase POST body | `totalAmount`, `vat_amount`, `dueAmount`, `paidAmount`, per-line `total_purchase_price`, `total_price`, `conversion_price` | Business-critical | Stripped in `PurchaseController::store`, replaced by server figures | **Superseded** |

### The trust boundary differs from sales

On a sale the price is the shop's own, held in the database, so a client-supplied
price is always wrong to accept. On a purchase the unit cost is what the *supplier*
charged — the server cannot look that up, so it is a legitimate operator input.

Everything derived from it is not. Line totals, conversion prices, subtotal, tax,
discounts, rounding, grand total and due are all recomputed server-side.

### Faults fixed in the provider

- **Two independently-rounded totals.** `totalPayableAmount` was the line sum
  rounded alone; `afterOverallDiscountAmount` was the same sum less discount plus
  shipping, rounded again. Both public, so which figure a screen showed depended on
  which field it read. There is now one payable and `totalPayableAmount` mirrors it.
- **Float accumulation.** Totals summed doubles across lines — ten lines of 33.33
  came to 333.29999999999995. Now accumulated in integer paise.
- **Stale discount clamp.** `calculateDiscount` clamped against `_actualTotalAmount`,
  which only updates part-way through `calculatePrice`, so the first discount typed
  after any cart change was measured against the *previous* subtotal.
- **`getTotalAmount` read a written-back field** (`totalPurchasePrice`), answering
  from stale data between recalculations.
- 39 lines of dead commented-out `calculatePrice`, and financial `debugPrint` in
  release paths, removed.

## Returns / refunds

| File | Method | Calculation | Kind | Server replacement | Status |
| --- | --- | --- | --- | --- | --- |
| `Screens/invoice return/repo/invoice_return_repo.dart` | return POST | `return_amount`, `lossProfit`, and the sale's amended `totalAmount` / `dueAmount` / `paidAmount` | Business-critical | `SaleReturnCalculationService::calculate` → `saleColumns()` | **Superseded** |

A refund is money leaving the business, so the amount is derived from the original
`sale_details` row — the price the item actually sold at, times the quantity coming
back. Previously `return_amount` was taken from the request and written to both the
refund and the customer's balance, and the return also rewrote the sale's own
totals from client figures. Both are now server-computed.

A refund settles any outstanding due first; only the remainder is recorded as cash
handed back, so a customer cannot be paid out for goods they had not finished
paying for.

## Reports and receipts

`Screens/Report/**`, `thermal priting invoices/**`, `Screens/Report/gst/**` compute
totals for display from data the server already returned. They do not feed a write
path, so they are **display-only** and were left alone.

## Loyalty

`core/loyalty/loyalty_discount.dart` and `loyalty_cart_panel.dart` compute an
expected discount to show in the cart. The server resolves the real one from the
customer's package (`CartLoyaltyDiscount::resolve`) and refuses a manual figure
above the entitlement. The eligible base is now taken from the server's own line
values rather than the request — previously `LoyaltyEligibility::lineValue` read
`subtotal`/`price` straight off the client payload, so a caller could enlarge their
own discount by claiming a bigger line. **Superseded.**

## Offline

`core/offline/offline_queue.dart` and `offline_sync_controller.dart` queue writes
taken while disconnected. The trust boundary is unchanged by this work and is
documented separately: an offline sale is a *claim* until it syncs. On sync the
server re-prices it exactly as it would a live sale, so a stale client price is
corrected rather than accepted.

The idempotency middleware is what makes replay safe — a queued sale carries a
stable key, so a retry after a dropped connection cannot create a second sale or
deduct stock twice. **Sync conflict states (stock moved since the offline sale) are
not yet modelled explicitly** and remain open.

## Remaining client-side responsibility

Flutter still:

1. chooses which batch a line draws from,
2. proposes discounts,
3. states what was tendered,
4. shows an immediate local estimate so the till stays responsive.

All four are intent. None of them is trusted as a result.

## Logging

`main()` runs the app inside a zone whose `print` handler drops everything unless
the build is debug, and `test/core/release_logging_test.dart` pins that. `debugPrint`
routes through `debugPrintSynchronously` → `print()`, so it is covered by the same
guard.

That means the auth tokens, customer details and per-batch prices these calls carry
do not reach logcat in a release build. The live `debugPrint` calls were therefore
left in place — deleting several hundred of them would be churn with regression
risk against a hazard that is already closed. What was removed is dead weight:

- `Screens/Sales/Repo/sales_repo.dart` — 384 lines of a commented-out earlier copy
  of `SaleRepo` (866 → 482 lines), and one raw `print` converted to `debugPrint`.
- `Provider/add_to_cart.dart` — a commented-out `calculatePrice` fragment, a
  per-line "GST DEBUG" dump that ran on every recalculation, and a product-grouping
  loop that computed `totalQty` and discarded it.
- `Provider/add_to_cart_purchase.dart` — 39 lines of commented-out `calculatePrice`
  and the financial `debugPrint` blocks.
