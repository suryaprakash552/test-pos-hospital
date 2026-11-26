# Production Security & Code Quality Review

Status as of 2026-07-29. Every item below was verified against the code, not
assumed from a previous report.

## Fixed in this pass

### 1. Unauthenticated updater allowed remote code execution - CRITICAL
`config/installer.php` hardcoded `'updaterEnabled' => 'true'`, and the `/update/*`
routes carry no auth middleware. That exposed, to anonymous users:
`GET /update/database` (runs migrations) and `POST /update/download-updated-code`
+ `GET /update/update-process`, which fetch an archive and `extractTo()` the
application root.

Now `env('INSTALLER_UPDATER_ENABLED', false)`, so the routes 404 by default.
Enable it only for the duration of a real upgrade. (`/install/*` was already
closed by the `storage/installed` lock file.)

### 2. Cross-tenant read/write/delete (IDOR)
Route-model-bound and scalar-id lookups that never checked `business_id`:

- `Modules/Business/.../AcnooCategoryController` - `update`, `destroy`, `status`,
  `deleteAll`. `update` also reassigned `business_id`, so another tenant's
  category could be *stolen*, not just edited. `deleteAll` deleted arbitrary ids.
- `Modules/HrmAddon/.../AcnooHolidayController` - `update`, `destroy`, `deleteAll`.
- `Modules/HrmAddon/.../AcnooLeaveTypeController` - `update`, `destroy`, `status`, `deleteAll`.
- `Api/AcnooIncomeCategoryController::destroy`, `Api/ExpenseCategoryController::destroy`.
- `Api/SaleReturnController::show`, `Api/PurchaseReturnController::show`.

### 3. Stock manipulation via return endpoints
`SaleReturnController::store` and `PurchaseReturnController::store` resolved
`sale_detail_id` / `purchase_detail_id` globally, so any id could inflate or
drain stock on records belonging to another business. Both now constrain the
line to the parent sale/purchase, scope stock by `business_id`, and reject a
return quantity greater than the quantity actually sold/purchased (previously
unbounded, which allowed negative stock).

### 4. Authorization policies were inert
`app/Policies/` existed but was registered nowhere and `authorize()` was called
zero times project-wide - the policies provided no protection at all. They are
now registered in `AuthServiceProvider` (`Category`, `Product`, `Sale`, `User`)
and used by the Business category controller.

Related bug: `User::hasPermission()` did `explode('.', $permission)` while every
policy passed hyphenated keys (`products-create`), destructuring to a null
`$action` and denying all staff. It now accepts either separator and returns
false on malformed keys instead of erroring.

### 5. Error message exposure
~16 endpoints returned raw `$e->getMessage()` to clients; the Business purchase
controller also returned `getFile()` and `getLine()`. All now log full detail
server-side and return a generic translated message. `SendResetCodeAction`
returned raw SMTP failures to the caller and no longer does.

### 6. Secrets committed to the repository
`.env.example` (tracked) contained real-format Google and Twitter OAuth client
secrets; `config/installer.php` contained a hardcoded Envato bearer token. Both
scrubbed. **These remain in git history (since commit `b63ff8d`) - rotate them.**

### 7. Tests ran against the live database
`phpunit.xml` had its test-DB lines commented out, so `RefreshDatabase` ran
`migrate:fresh` against whatever `.env` pointed at. Now pinned to
`post_manage_testing`.

### 8. Duplicate foreign key broke sale inserts
`sales.customer_id` carried both `sales_customer_id_foreign` -> `customers` and a
stale `sales_party_id_foreign` -> `parties`, so an insert only succeeded when the
id existed in both tables. Dropped via
`2026_07_29_000001_drop_stale_party_foreign_key_from_sales_table`.

### 9. Dead `party_id` references after the column rename
`sales.party_id` was renamed to `customer_id`, but code still read the old name:

- `Sale::party()` pointed at a non-existent column, so `$sale->party` was always
  null and every invoice, PDF, thermal receipt and dashboard row rendered
  "Guest". Re-aimed at `Customer` via `customer_id`.
- Customer due adjustments on sale return and sale deletion silently never ran
  (`Party::find(null)`), in both the API and Business module.
- Five queries selected the dropped `party_id` column, a hard SQL error that
  broke every sale-return listing and detail screen.

### 10. Customer data never migrated from `parties` to `customers`
The schema and API moved sales to `customers`, but the data did not: `parties`
held 9 non-supplier rows and `customers` was empty. The Business UI posted
`party_id`, which is neither fillable on `Sale` nor a real column, so it was
silently dropped - **all 4 existing sales had `customer_id = NULL`.**

The move is now complete, in the direction the schema already pointed (the FK,
`Sale::$fillable`, `Sale::customer()` and the API all target `customers`;
suppliers stay in `parties` for `purchases.party_id`):

- `2026_07_29_000002_copy_non_supplier_parties_into_customers_table` copies every
  non-supplier party into `customers`, preserving ids. Idempotent.
- The Business sales controller and its create/edit views now use `Customer` and
  post `customer_id`.
- The 4 orphaned sales were re-linked to their original customers.

**Note on the backfill:** `2026_04_13_111913_fix_sales_customer_foreign_key` runs
`SET customer_id = NULL WHERE customer_id NOT IN (SELECT id FROM customers)`
*before* any customer rows exist, so it destroys the references on every
environment it touches. They were recovered here from the pre-rename snapshot
`post_manage_v20260421_170834`. On another environment, run the copy migration
first, then re-link from a pre-rename backup:

```sql
UPDATE  target.sales s
  JOIN  backup.sales old ON old.id = s.id
  JOIN  target.customers c ON c.id = old.party_id
   SET  s.customer_id = old.party_id
 WHERE  s.customer_id IS NULL;
```

## Open - needs a decision

### A. Unused service layer
`CurrencyService`, `NotificationService`, `ProductService`, `SaleService`,
`StatisticsService` and `UserService` are referenced nowhere. Each now carries a
`NOT WIRED UP` header so nobody edits one expecting behaviour to change.

Not deleted, because `ENTERPRISE_TRANSFORMATION_SUMMARY.md` lists them as
intended architecture and the files are untracked (deletion would not be
recoverable from git). `SaleService` is the notable trap: its stock adjustments
omit the `lockForUpdate()` the live controllers rely on, so adopting it piecemeal
would reintroduce the stock race. Finish the migration into these services or
remove them.

## Verified as already correct

- Sale and purchase stock mutations in the API use `lockForUpdate()` inside
  transactions, so the check-then-act race is genuinely closed.
- Performance indexes from `2026_07_09_000001_add_performance_indexes` are
  present on `sales`, `products`, `stocks` and `purchases`.
- `.env` is gitignored and untracked.
- Remaining `getMessage()` responses in `PurchaseController` (400) are
  app-authored `SplitPayment` validation messages, safe to surface.

## Reminders for deployment

- `APP_DEBUG=false` in production. It is `true` in the local `.env`, which also
  gates the unauthenticated `_ignition/execute-solution` endpoint.
- Keep `INSTALLER_UPDATER_ENABLED=false` except during an upgrade.
