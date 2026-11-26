# POS API — Postman

`POS-API.postman_collection.json` is generated from `php artisan route:list`,
so it lists the routes that actually exist rather than a hand-kept copy that
drifts. Regenerate it whenever routes change.

## Running it

1. Import both files.
2. Select the **POS — dev** environment and set `email` and `password`.
   `base_url` defaults to the dev host — point it at production only
   deliberately, since several requests write.
3. Run **Auth › POST /sign-in**. Its test script stores `token`, and the
   collection sends it as a bearer token on every other request, so nothing
   else needs configuring.
4. Fill the id variables (`product_id`, `stock_id`, `customer_id`, …) from a
   list call before running the writes that reference them.

Dates (`{{today}}`, `{{month_start}}`, `{{next_year}}`) are recalculated before
every request, so a saved collection stays runnable months later.
`{{today_dmy}}` exists separately because the due endpoints take `d/m/Y` where
everything else takes ISO.

## Writes

`POST /sales` and `POST /purchase` send an `Idempotency-Key`. The API honours
it: replaying a request returns the first response rather than creating a second
sale. That makes the collection safe to re-run, and is worth keeping if you add
requests of your own.

## Worth exercising

- **Sales › POST /sales/calculate** — prices a cart without saving. Its totals
  are what `POST /sales` will store; if they ever differ, that is a bug.
- **Purchase › POST /purchase/calculate** — the same for purchases.
- **GST › GET /gst-report** — carries `branch_id`, `rate` and a date range.
- **Dues › GET /customer-dues** — `search` covers name and phone; `from_date`
  and `to_date` filter by collection date.
