# API Contract Audit

Laravel is the source of truth. Every field below comes from a FormRequest
`rules()` array or an inline `->validate([...])` call on that route — nothing is
inferred from column nullability, route names, or the Flutter client.

Regenerate with:

```bash
python3 postman/tools/contract_audit.py --matrix
python3 postman/tools/contract_audit.py --fix     # rewrite Postman bodies
python3 postman/tools/contract_audit.py           # audit only; exit 1 on drift
```

## What was wrong

The collection was generated from `php artisan route:list`. That got routing
completely right — **234/234 requests match a real route, and no API route is
uncovered** — and left every write body as `{ }`.

So all 115 POST/PUT/PATCH requests would have returned `422`. Of those, **91 had
at least one required field missing**; the remaining 24 take no required fields.
This is the "missing fields / does not represent the contract" problem.

## What changed

| | Before | After |
| --- | --- | --- |
| Requests | 234 | 234 |
| Write requests with a populated body | 0 | 103 |
| Write requests missing a required field | 91 | **0** |
| Requests with response tests | 0 | 234 |
| Requests with field documentation | 0 | 103 |
| `Idempotency-Key` headers | 0 | 10 |
| multipart/form-data requests | 0 | 4 |

103 of 115 write requests take validated input; the other 12 have no rules to
derive a body from (they read path/query only).

## Contract facts worth recording

**Idempotency** — `EnsureIdempotentRequest`, on `purchase`, `sales`,
`sales-return`, `purchases-return`. Key from `Idempotency-Key`,
`X-Idempotency-Key`, or an `idempotency_key` body field; max 191 chars. Without a
key the middleware stands aside.

- same key + same body, completed → replays the stored response and status
- same key + **different** body → **422** `idempotency_key_reused`
- same key while the first is in flight → **409** `idempotency_in_progress`

Note this is 422, not 409, for the payload-mismatch case.

**Customer/party opening balance type** — `opening_balance_type` is `required` on
create and `nullable` on update, for both `customers` and `parties`. The
collection reflects this: it is present in the create body and absent from the
update body.

**Auth** — Laravel Sanctum bearer tokens. `POST /sign-in` and `POST /submit-otp`
return the token at the top level of the body; the collection captures it into
`{{token}}` and the collection-level auth applies it to every other request.

**Required file uploads** — `POST /products` (`productPicture`) and the three
`bulk-uploads` endpoints (`file`) require a real upload, so those four requests
are multipart/form-data rather than raw JSON. Image fields elsewhere are
`nullable` and stay out of the JSON body.

## Field matrix

"Optional" and "In Postman" are counts; "Required", "Conditional" and "Missing"
are field lists. Conditional means `required_if` / `required_with` — present in
the docs for each request, not forced into the example body.

| Method | Endpoint | Required | Optional | Conditional | In Postman | Missing |
| --- | --- | --- | --- | --- | --- | --- |
| PATCH | `/api/v1/brands/{X}` | brandName | 0 | — | 1 | none |
| PATCH | `/api/v1/business/{X}` | — | 10 | companyName, business_category_id | 0 | none |
| PATCH | `/api/v1/categories/{X}` | categoryName | 5 | — | 1 | none |
| PATCH | `/api/v1/customer-tiers/{X}` | name | 3 | — | 1 | none |
| PATCH | `/api/v1/customers/{X}` | name, type | 7 | — | 2 | none |
| PATCH | `/api/v1/expense-categories/{X}` | categoryName | 0 | — | 1 | none |
| PATCH | `/api/v1/expenses/{X}` | expense_category_id | 8 | — | 1 | none |
| PATCH | `/api/v1/income-categories/{X}` | categoryName | 0 | — | 1 | none |
| PATCH | `/api/v1/manufacturers/{X}` | manufacturerName | 0 | — | 1 | none |
| PATCH | `/api/v1/parties/{X}` | name, type | 7 | — | 2 | none |
| PATCH | `/api/v1/payment-types/{X}` | name | 1 | — | 1 | none |
| PATCH | `/api/v1/product-models/{X}` | name | 1 | — | 1 | none |
| PATCH | `/api/v1/products/{X}` | productName | 15 | — | 1 | none |
| PATCH | `/api/v1/purchase/{X}` | party_id, products | 6 | — | 2 | none |
| PATCH | `/api/v1/racks/{X}` | name | 0 | — | 1 | none |
| PATCH | `/api/v1/relationships/{X}` | relationship | 0 | — | 1 | none |
| PATCH | `/api/v1/sales/{X}` | products, products_payload, totalAmount | 18 | — | 3 | none |
| PATCH | `/api/v1/shelfs/{X}` | name | 0 | — | 1 | none |
| PATCH | `/api/v1/units/{X}` | unitName | 0 | — | 1 | none |
| PATCH | `/api/v1/users/{X}` | email, name | 3 | — | 2 | none |
| PATCH | `/api/v1/vats/{X}` | name | 1 | vat_ids, rate | 1 | none |
| PATCH | `/api/v1/warehouses/{X}` | name | 3 | — | 1 | none |
| POST | `/api/v1/brands` | brandName | 0 | — | 1 | none |
| POST | `/api/v1/bulk-uploads` | — | 1 | — | 1 | none |
| POST | `/api/v1/bulk-uploads/purchases` | — | 3 | — | 1 | none |
| POST | `/api/v1/bulk-uploads/sales` | — | 3 | — | 1 | none |
| POST | `/api/v1/business` | business_category_id, companyName | 4 | — | 2 | none |
| POST | `/api/v1/business-delete` | password | 0 | — | 1 | none |
| POST | `/api/v1/categories` | categoryName | 5 | — | 1 | none |
| POST | `/api/v1/change-password` | current_password, password | 0 | — | 2 | none |
| POST | `/api/v1/counter` | — | 3 | — | 0 | none |
| POST | `/api/v1/customer-dues` | customer_id, payDueAmount, paymentDate, payment_type_id | 3 | — | 4 | none |
| POST | `/api/v1/customer-tiers` | name | 3 | — | 1 | none |
| POST | `/api/v1/customers` | name, opening_balance_type, type | 6 | — | 3 | none |
| POST | `/api/v1/doctors` | name | 5 | — | 1 | none |
| POST | `/api/v1/dues` | party_id, payDueAmount, paymentDate, payment_type_id | 2 | — | 4 | none |
| POST | `/api/v1/expense-categories` | categoryName | 0 | — | 1 | none |
| POST | `/api/v1/expenses` | expense_category_id | 8 | — | 1 | none |
| POST | `/api/v1/generate-upi-qr` | amount | 0 | — | 1 | none |
| POST | `/api/v1/income-categories` | categoryName | 0 | — | 1 | none |
| POST | `/api/v1/incomes` | amount, income_category_id | 6 | — | 2 | none |
| POST | `/api/v1/invoice-settings/update` | invoice_size | 0 | — | 1 | none |
| POST | `/api/v1/lang` | lang | 0 | — | 1 | none |
| POST | `/api/v1/loyalty-exclusions` | excludable_id, type | 1 | — | 2 | none |
| POST | `/api/v1/loyalty-subscriptions` | customer_id, loyalty_package_id | 6 | — | 2 | none |
| POST | `/api/v1/loyalty-subscriptions/{X}/cancel` | — | 1 | — | 0 | none |
| POST | `/api/v1/loyalty-subscriptions/{X}/refund` | amount, reason | 0 | — | 2 | none |
| POST | `/api/v1/loyalty-subscriptions/{X}/renew` | — | 6 | — | 0 | none |
| POST | `/api/v1/loyalty-subscriptions/{X}/upgrade` | loyalty_package_id | 7 | — | 1 | none |
| POST | `/api/v1/manufacturers` | manufacturerName | 0 | — | 1 | none |
| POST | `/api/v1/order-management/search` | from_date, to_date | 0 | — | 2 | none |
| POST | `/api/v1/order-management/store` | products | 0 | — | 1 | none |
| POST | `/api/v1/parties` | name, opening_balance_type, type | 6 | — | 3 | none |
| POST | `/api/v1/password-reset` | code, email, password | 0 | — | 3 | none |
| POST | `/api/v1/payment-types` | name | 1 | — | 1 | none |
| POST | `/api/v1/product-models` | name | 1 | — | 1 | none |
| POST | `/api/v1/products` | productName | 29 | — | 2 | none |
| POST | `/api/v1/profile` | email, name | 1 | — | 2 | none |
| POST | `/api/v1/profit-loss-report` | — | 2 | — | 0 | none |
| POST | `/api/v1/purchase` | party_id, products | 6 | — | 2 | none |
| POST | `/api/v1/purchases-return` | purchase_detail_id, purchase_id, return_amount, return_date, return_qty | 0 | — | 5 | none |
| POST | `/api/v1/racks` | name | 0 | — | 1 | none |
| POST | `/api/v1/relationships` | relationship | 0 | — | 1 | none |
| POST | `/api/v1/resend-otp` | email | 0 | — | 1 | none |
| POST | `/api/v1/sales` | products, products_payload, totalAmount | 18 | — | 3 | none |
| POST | `/api/v1/sales-return` | return_amount, return_date, return_qty, sale_detail_id, sale_id | 0 | — | 5 | none |
| POST | `/api/v1/sales/calculate` | invoiceNumber | 0 | — | 1 | none |
| POST | `/api/v1/send-reset-code` | email | 0 | — | 1 | none |
| POST | `/api/v1/shelfs` | name | 0 | — | 1 | none |
| POST | `/api/v1/sign-in` | email, password | 1 | — | 2 | none |
| POST | `/api/v1/sign-up` | email, name, password | 0 | — | 3 | none |
| POST | `/api/v1/stock-audit-submit` | items | 0 | — | 1 | none |
| POST | `/api/v1/stocks` | productStock, stock_id | 0 | — | 2 | none |
| POST | `/api/v1/submit-otp` | email, otp | 0 | — | 2 | none |
| POST | `/api/v1/units` | unitName | 0 | — | 1 | none |
| POST | `/api/v1/users` | email, name, password | 2 | — | 3 | none |
| POST | `/api/v1/vats` | name | 1 | vat_ids, rate | 1 | none |
| POST | `/api/v1/verify-reset-code` | code, email | 0 | — | 2 | none |
| POST | `/api/v1/warehouses` | name | 3 | — | 1 | none |
| PUT | `/api/v1/brands/{X}` | brandName | 0 | — | 1 | none |
| PUT | `/api/v1/business/{X}` | — | 10 | companyName, business_category_id | 0 | none |
| PUT | `/api/v1/categories/{X}` | categoryName | 5 | — | 1 | none |
| PUT | `/api/v1/counter` | — | 3 | — | 0 | none |
| PUT | `/api/v1/customer-tiers/{X}` | name | 3 | — | 1 | none |
| PUT | `/api/v1/customers/{X}` | name, type | 7 | — | 2 | none |
| PUT | `/api/v1/expense-categories/{X}` | categoryName | 0 | — | 1 | none |
| PUT | `/api/v1/expenses/{X}` | expense_category_id | 8 | — | 1 | none |
| PUT | `/api/v1/gst-configuration` | — | 8 | — | 0 | none |
| PUT | `/api/v1/income-categories/{X}` | categoryName | 0 | — | 1 | none |
| PUT | `/api/v1/manufacturers/{X}` | manufacturerName | 0 | — | 1 | none |
| PUT | `/api/v1/parties/{X}` | name, type | 7 | — | 2 | none |
| PUT | `/api/v1/payment-types/{X}` | name | 1 | — | 1 | none |
| PUT | `/api/v1/product-models/{X}` | name | 1 | — | 1 | none |
| PUT | `/api/v1/products/{X}` | productName | 15 | — | 1 | none |
| PUT | `/api/v1/purchase/{X}` | party_id, products | 6 | — | 2 | none |
| PUT | `/api/v1/racks/{X}` | name | 0 | — | 1 | none |
| PUT | `/api/v1/relationships/{X}` | relationship | 0 | — | 1 | none |
| PUT | `/api/v1/sales/{X}` | products, products_payload, totalAmount | 18 | — | 3 | none |
| PUT | `/api/v1/shelfs/{X}` | name | 0 | — | 1 | none |
| PUT | `/api/v1/units/{X}` | unitName | 0 | — | 1 | none |
| PUT | `/api/v1/users/{X}` | email, name | 3 | — | 2 | none |
| PUT | `/api/v1/vats/{X}` | name | 1 | vat_ids, rate | 1 | none |
| PUT | `/api/v1/warehouses/{X}` | name | 3 | — | 1 | none |

write requests audited: 103
requests missing required fields: 0
