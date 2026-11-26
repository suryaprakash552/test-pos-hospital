# Postman setup

## Import

1. **Collection** — `postman/POS-API.postman_collection.json`
2. **Environment** — pick one:
   - `postman/POS-Local.postman_environment.json` → `http://localhost:8000/api/v1`
   - `postman/POS-Staging.postman_environment.json` → `https://dev.naayahs.com/api/v1`
   - `postman/POS-Production.postman_environment.example.json` → template only

Select the environment in the top-right picker.

## Credentials

`email`, `password` and `token` ship **empty** and are marked as secret. Fill
them in your own Postman; do not commit them back. The production file is an
example — copy it somewhere git-ignored before putting anything real in it.

## Authenticate

Run **Auth → `POST /sign-in`**. Its test script stores the Sanctum token:

```js
pm.environment.set('token', pm.response.json().token);
```

The collection is configured with bearer auth using `{{token}}`, so every other
request picks it up. Nothing needs pasting per-request. If the deployment has OTP
enabled, run `POST /submit-otp` instead — it returns a token the same way.

## Order of operations

Ids flow between requests rather than being hardcoded. Creating records first
populates the variables the later requests need:

```
POST /sign-in          → token
POST /categories       → category_id
POST /brands           → brand_id
POST /units            → unit_id
POST /products         → product_id
POST /parties          → party_id
POST /purchase         → purchase_id
POST /sales            → sale_id
POST /dues             (uses party_id, payment_type_id)
GET  /dashboard
```

Every `POST` that creates something captures `data.id` into its variable — the
API returns `{ message, data }`, and the record is in `data`.

The remaining id variables default to `1` so a request can be run standalone
against a seeded database. Overwrite them, or run the chain above.

## Idempotency

`purchase`, `sales`, `sales-return` and `purchases-return` carry an
`Idempotency-Key` header set to `{{$guid}}`, so each send generates a fresh key.

To exercise the guard, replace `{{$guid}}` with a literal and send twice:

- identical body → the first response is replayed
- changed body → **422** `idempotency_key_reused`
- while the first is still running → **409** `idempotency_in_progress`

## File uploads

Four requests are multipart/form-data because the backend requires a real file:

| Request | Field |
| --- | --- |
| `POST /products` | `productPicture` (`required, image, max:2048`) |
| `POST /bulk-uploads` | `file` (`required, mimes:xlsx,xls,csv, max:10240`) |
| `POST /bulk-uploads/purchases` | `file` |
| `POST /bulk-uploads/sales` | `file` |

Attach a file in the Body tab before sending — Postman cannot store one in the
committed collection.

## Which endpoints are what

- **Server-to-server / app**: everything under `/api/v1` — Sanctum bearer token.
- **Dashboard/admin**: the `/admin` routes are web routes with session auth, and
  are deliberately **not** in this collection.
- **No Android companion is required** for any endpoint here.

## Keeping the collection honest

The bodies are generated from Laravel's validation rules, not written by hand:

```bash
python3 postman/tools/contract_audit.py            # audit; exits 1 on drift
python3 postman/tools/contract_audit.py --fix      # regenerate bodies
python3 postman/tools/contract_audit.py --matrix   # print the field matrix
```

Run the audit after changing any FormRequest. It is a reasonable CI step — it
fails when a request is missing a field the API requires.

## Running the whole collection

Newman is **not currently installed** in this checkout. To run it:

```bash
npm install -g newman

newman run postman/POS-API.postman_collection.json \
  -e postman/POS-Local.postman_environment.json
```

Point it at a local or staging environment with test data. Do not run it against
production, and do not put production credentials in an environment file.
