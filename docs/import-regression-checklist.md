# Import release acceptance

Run against an isolated testing database, not live employee transactions.

- Name-only sales file: matched product defaults to one unit, uses Inventory price and tax.
- Larger sale quantity: consume non-expired stock earliest-expiry first and split batches.
- Duplicate product rows: reserve allocated stock across the entire import, not per row.
- Missing product, insufficient stock or missing selling price: explain the problem; allow correction/removal; do not partially add the cart.
- Stock changed after review: check availability again before cart preparation and checkout.
- Expiry changed after review: reject expired batches in both payload modes and cart pricing; expiry today remains eligible under the existing business rule.
- Purchase file: preview totals and hand off to the purchase cart; never silently post a credit bill.
- Purchase handoff: retain verified server totals, reject a nonempty cart and validate the import identifier before clearing any cart state.
- Mapping confirmation: lock before opening dialogs; reset busy controls after cancellation or unexpected errors.
- Document tax differs from Inventory: explain the adjustment without blocking a valid import.
- Repeat checkout/retry: the same import cannot create a second transaction.
- Foreign business product, customer, supplier or stock: refuse access and mapping.
- Product edit with unchanged legacy brand/unit references: permit unrelated metadata edits; reject newly selected foreign references.
- Barcode: exact product-code lookup, including alphanumeric codes; never substitute a partial match.
- Image source: disabled and marked Upcoming until reliable recognition is implemented.

Device acceptance remains necessary: inspect keyboard/scrolling, mapping correction,
stock errors, checkout totals and invoice line prices on the supported Android devices.
Automated tests do not establish production load performance or absence of all UI errors.
