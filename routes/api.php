<?php

use App\Http\Controllers\Api as Api;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::match(['get', 'head'], '/', fn () => response()->json([
        'message' => 'API is healthy.',
        'version' => 'v1',
    ]));

    Route::get('/module-check', [Api\Auth\AuthController::class, 'moduleCheck']);
    // AuthController applies the account/device-aware five-attempt, three-hour
    // login lock. This wider transport ceiling only absorbs request floods.
    Route::post('/sign-in', [Api\Auth\AuthController::class, 'login'])->middleware('throttle:30,1');
    // Reachable without a token because they are what a person needs when a
    // fourth device is refused — the one moment they cannot sign in anywhere.
    // Both verify the password on every call and act only on that account; the
    // rate limit matches sign-in so neither is a cheaper way to guess one.
    Route::post('/sign-in/devices', [Api\Auth\AuthController::class, 'devices'])->middleware('throttle:5,1');
    Route::post('/sign-in/devices/release', [Api\Auth\AuthController::class, 'releaseDevice'])->middleware('throttle:5,1');
    Route::post('/submit-otp', [Api\Auth\AuthController::class, 'submitOtp'])->middleware('throttle:10,1');
    Route::post('/sign-up', [Api\Auth\AuthController::class, 'signUp'])->middleware('throttle:3,1');
    Route::post('/resend-otp', [Api\Auth\AuthController::class, 'resendOtp'])->middleware('throttle:5,1');
    Route::get('/otp-settings', [Api\Auth\AuthController::class, 'otpSettings']);

    Route::post('/send-reset-code', [Api\Auth\AcnooForgotPasswordController::class, 'sendResetCode'])->middleware('throttle:3,1');
    Route::post('/verify-reset-code', [Api\Auth\AcnooForgotPasswordController::class, 'verifyResetCode'])->middleware('throttle:10,1');
    Route::post('/password-reset', [Api\Auth\AcnooForgotPasswordController::class, 'resetPassword'])->middleware('throttle:3,1');

    // Public routes
    Route::apiResource('banners', Api\AcnooBannerController::class)->only('index');

    Route::group(['middleware' => ['auth:sanctum', 'throttle:pos-api']], function () {
        Route::get('update-expire-date', [Api\BusinessController::class, 'updateExpireDate']);

        Route::get('summary', [Api\StatisticsController::class, 'summary']);
        Route::get('due-summary', [Api\StatisticsController::class, 'dueSummary']);
        Route::get('dashboard', [Api\StatisticsController::class, 'dashboard']);
        Route::post('counter', [Api\StatisticsController::class, 'addCounterAmount']);
        Route::get('counter', [Api\StatisticsController::class, 'viewCounterAmount']);
        Route::put('counter', [Api\StatisticsController::class, 'updateCounterAmount']);
        Route::get('staff-reports', [Api\StatisticsController::class, 'staffReports']);

        Route::apiResource('parties', Api\PartyController::class);
        Route::apiResource('customers', Api\CustomerController::class);
        Route::apiResource('relationships', Api\AcnooRelationshipController::class)->except('show');
        Route::apiResource('users', Api\AcnooUserController::class)->except('show');
        Route::apiResource('units', Api\UnitController::class)->except('show');
        Route::apiResource('brands', Api\AcnooBrandController::class)->except('show');
        Route::apiResource('manufacturers', Api\AcnooManufacturerController::class)->except('show');
        Route::apiResource('categories', Api\AcnooCategoryController::class)->except('show');
        Route::apiResource('products', Api\AcnooProductController::class);
        Route::get('expired-products', [Api\AcnooProductController::class, 'expiredProducts']);
        Route::get('products-by-location', [Api\AcnooProductController::class, 'getProductsByLocation']);
        // Immutable, product-wide ledger across every batch. This must appear
        // before the products resource so `inventory-history` is never treated
        // as a product id.
        Route::get('products/{product}/inventory-history', [Api\StockController::class, 'productHistory']);
        Route::get('inventory-history', [Api\StockController::class, 'inventoryHistory']);
        // Declared before the resource so 'summary' is not captured as {stock}.
        Route::get('stocks/summary', [Api\StockController::class, 'summary']);
        Route::get('stocks/{stock}/origin', [Api\StockController::class, 'origin']);
        Route::get('stocks/{stock}/history', [Api\StockController::class, 'history']);
        Route::apiResource('stocks', Api\StockController::class);
        Route::get('low-stock-products',[Api\StockController::class, 'lowStockProducts']);

        // Expired stock: the disposal queue, the act of disposing, and the
        // register that satisfies a drug inspector. `store` is idempotent —
        // a retried confirmation must not destroy the same stock twice.
        Route::get('expired-stock/pending', [Api\StockDisposalController::class, 'pending']);
        Route::post('stock-disposals', [Api\StockDisposalController::class, 'store'])
            ->middleware('idempotent');
        Route::get('stock-disposals', [Api\StockDisposalController::class, 'index']);
        Route::get('stock-disposals/{id}', [Api\StockDisposalController::class, 'show']);
        Route::post('profit-loss-report', [Api\StockController::class, 'profitLossReport']);
        Route::get('profit-loss-report/export', [Api\StockController::class, 'profitLossExport']);
        // Tax collected vs tax paid for a period, with the net position.
        Route::get('gst-report', [Api\GstReportController::class, 'index']);
        // What each branch is configured to bill under — why the invoices look the
        // way they do, as opposed to what they totalled.
        Route::get('branch-gst-report', Api\BranchGstConfigurationReportController::class);

        // A category's items prepared for one-tap add to cart, quantities already
        // capped at what is in stock.
        Route::get('categories/{category}/bulk-add', Api\CategoryBulkAddController::class);

        // How the shop bills tax: registration, GSTIN, and the pricing method.
        Route::get('gst-configuration', [Api\GstConfigurationController::class, 'show']);
        Route::put('gst-configuration', [Api\GstConfigurationController::class, 'update']);
        Route::get('gst-configuration/history', [Api\GstConfigurationController::class, 'history']);
        // §2, §3: loyalty packages a customer buys, and their subscriptions.
        // Distinct from customer tiers below, which are only a printed label.
        Route::apiResource('loyalty-packages', Api\LoyaltyPackageController::class);
        Route::get('loyalty-subscriptions', [Api\LoyaltySubscriptionController::class, 'index']);
        Route::post('loyalty-subscriptions', [Api\LoyaltySubscriptionController::class, 'store']);
        Route::get('customers/{customer}/loyalty', [Api\LoyaltySubscriptionController::class, 'show']);
        Route::post('loyalty-subscriptions/{subscription}/renew', [Api\LoyaltySubscriptionController::class, 'renew']);
        Route::post('loyalty-subscriptions/{subscription}/upgrade', [Api\LoyaltySubscriptionController::class, 'upgrade']);
        Route::post('loyalty-subscriptions/{subscription}/cancel', [Api\LoyaltySubscriptionController::class, 'cancel']);
        Route::get('loyalty-subscriptions/{subscription}/refund-quote', [Api\LoyaltySubscriptionController::class, 'refundQuote']);
        Route::post('loyalty-subscriptions/{subscription}/refund', [Api\LoyaltySubscriptionController::class, 'refund']);

        // §11: what a loyalty discount does not apply to.
        Route::get('loyalty-exclusions', [Api\LoyaltyExclusionController::class, 'index']);
        Route::post('loyalty-exclusions', [Api\LoyaltyExclusionController::class, 'store']);
        Route::delete('loyalty-exclusions/{exclusion}', [Api\LoyaltyExclusionController::class, 'destroy']);

        // §12: the six loyalty reports. All read what was recorded at the time,
        // so a report run later still reconciles against the invoices it covers.
        Route::prefix('loyalty-reports')->group(function () {
            Route::get('customers', [Api\LoyaltyReportController::class, 'customers']);
            Route::get('expired', [Api\LoyaltyReportController::class, 'expired']);
            Route::get('renewals', [Api\LoyaltyReportController::class, 'renewals']);
            Route::get('upgrades', [Api\LoyaltyReportController::class, 'upgrades']);
            Route::get('amount-received', [Api\LoyaltyReportController::class, 'amountReceived']);
            Route::get('utilisation', [Api\LoyaltyReportController::class, 'utilisation']);
        });

        // Customer grades printed on the bill. Reference only — no pricing rule.
        Route::apiResource('customer-tiers', Api\CustomerTierController::class)
            ->except('show');
        Route::get('follow-up-report', [Api\StockController::class, 'followUpReport']);
        Route::get('stock-audit', [Api\StockController::class, 'stockAuditIndex']);
        Route::post('stock-audit-submit', [Api\StockController::class, 'stockAuditSubmit']);
        Route::get('stock-audit-report', [Api\StockController::class, 'stockAuditReport']);
        Route::get('purchase/batch-details/{product_id}/{batch_no?}',[Api\PurchaseController::class, 'getBatchDetails']);
        Route::get('search-purchase-invoice', [Api\PurchaseController::class, 'searchPurchaseInvoice']);
        Route::apiResource('racks', Api\RackController::class)->except('show');
        Route::apiResource('shelfs', Api\ShelfController::class)->except('show');
        Route::apiResource('business', Api\BusinessController::class)->only('index', 'store', 'update');
        Route::apiResource('business-categories', Api\BusinessCategoryController::class)->only('index');
        // Money-moving writes carry an idempotency guard: a till that retries after
        // a dropped connection must not create a second sale or deduct stock twice.
        // Without an Idempotency-Key header the middleware stands aside, so existing
        // clients are unaffected.
        // Pricing previews. Registered before the resource routes so `calculate`
        // is not swallowed by `purchase/{purchase}` and `sales/{sale}`.
        //
        // Deliberately outside the idempotency guard: they write nothing, and a
        // billing screen re-prices on every keystroke, so a retried preview is
        // harmless while a reused key would not be.
        Route::post('purchase/calculate', [Api\PurchaseController::class, 'calculate']);
        Route::post('sales/calculate', [Api\AcnooSaleController::class, 'calculate']);

        Route::apiResource('purchase', Api\PurchaseController::class)
            ->middleware('idempotent');
        Route::apiResource('sales', Api\AcnooSaleController::class)->except('show')
            ->middleware('idempotent');
        Route::get('search-invoice-id', [Api\AcnooSaleController::class, 'searchInvoiceById']);
        Route::post('/generate-upi-qr', [Api\AcnooSaleController::class, 'generateUpiQr']);
        Route::get('product-report', [Api\AcnooSaleController::class, 'productReport']);
        Route::post('order-management/search', [Api\OrderManagementController::class, 'search']);
        Route::post('order-management/store', [Api\OrderManagementController::class, 'store']);
        Route::get('purchase-order', [Api\OrderManagementController::class, 'stockReport']);
        Route::apiResource('sales-return', Api\SaleReturnController::class)->only('index', 'store', 'show')
            ->middleware('idempotent');
        Route::apiResource('purchases-return', Api\PurchaseReturnController::class)->only('index', 'store', 'show')
            ->middleware('idempotent');
        Route::apiResource('invoices', Api\AcnooInvoiceController::class)->only('index');
        // Guarded like the checkout routes: a due collection that is retried
        // after a dropped connection would otherwise take the money twice and
        // reduce the balance twice. The middleware is a no-op until a client
        // sends a key, so older builds are unaffected.
        Route::apiResource('dues', Api\AcnooDueController::class)->only('index', 'store')
            ->middleware('idempotent');
        Route::apiResource('customer-dues', Api\CustomerDueController::class)->only('index','store')
            ->middleware('idempotent');
        // Current outstanding balances — distinct from `dues`/`customer-dues`
        // above, which are collection *history*. This answers "who owes what
        // right now", combined across suppliers and customers.
        Route::get('outstanding-dues-report', [Api\OutstandingDuesReportController::class, 'index']);
        Route::apiResource('expense-categories', Api\ExpenseCategoryController::class)->except('show');
        Route::apiResource('expenses', Api\AcnooExpenseController::class);
        Route::apiResource('income-categories', Api\AcnooIncomeCategoryController::class)->except('show');
        Route::apiResource('incomes', Api\AcnooIncomeController::class)->only('index', 'store');

        Route::apiResource('doctors',Api\DoctorController::class);

        Route::apiResource('lang', Api\AcnooLanguageController::class)->only('index', 'store');
        Route::apiResource('profile', Api\AcnooProfileController::class)->only('index', 'store');
        Route::apiResource('plans', Api\AcnooSubscriptionsController::class)->only('index');
        Route::apiResource('subscribes', Api\AcnooSubscribesController::class)->only('index');
        Route::apiResource('currencies', Api\AcnooCurrencyController::class)->only('index', 'show');
        Route::apiResource('vats', Api\AcnooVatController::class)->except('show');
        Route::apiResource('payment-types', Api\PaymentTypeController::class)->except('show');
        Route::apiResource('warehouses', Api\WarehouseController::class)->except('show');
        Route::apiResource('product-models', Api\ProducModelController::class)->except('show');
        Route::apiResource('product-settings', Api\ProductSettingsController::class)->only('index', 'store');

        Route::get('business-settings', [Api\BusinessSettingController::class, 'index']);

        Route::post('bulk-uploads/sales', [Api\BulkUploadControler::class, 'sales']);
        Route::post('bulk-uploads/purchases', [Api\BulkUploadControler::class, 'purchases']);

        // Importing a supplier bill, reviewed before it becomes a purchase.
        //
        // Separate from `bulk-uploads/purchases` above, which parses and commits
        // in one step. That is fine for a spreadsheet a shop prepared itself and
        // unsafe for a photographed bill, so this route stops at a draft.
        // One supplier's account as a statement: purchases, returns and
        // payments interleaved with a running balance. Built server-side
        // because a balance computed over three partly-loaded lists is wrong
        // in a way that looks plausible.
        Route::get('parties/{id}/ledger', [Api\PartyLedgerController::class, 'show']);

        Route::get('purchase-imports', [Api\PurchaseImportController::class, 'index']);
        Route::post('purchase-imports', [Api\PurchaseImportController::class, 'store']);
        Route::get('purchase-imports/{id}', [Api\PurchaseImportController::class, 'show']);
        Route::patch('purchase-imports/{id}/lines/{lineId}', [Api\PurchaseImportController::class, 'updateLine']);
        Route::get('purchase-imports/{id}/commit-payload', [Api\PurchaseImportController::class, 'commitPayload']);
        Route::post('purchase-imports/{id}/committed', [Api\PurchaseImportController::class, 'markCommitted']);
        Route::delete('purchase-imports/{id}', [Api\PurchaseImportController::class, 'destroy']);

        // The sale-side mirror of purchase-imports above: upload -> parse ->
        // review -> commit through the ordinary sale endpoint. A sale also
        // has to decide which existing batch each line sells from, hence
        // stock-options alongside the line update.
        Route::get('sale-imports', [Api\SaleImportController::class, 'index']);
        Route::post('sale-imports', [Api\SaleImportController::class, 'store']);
        Route::get('sale-imports/{id}', [Api\SaleImportController::class, 'show']);
        Route::patch('sale-imports/{id}/lines/{lineId}', [Api\SaleImportController::class, 'updateLine']);
        Route::get('sale-imports/{id}/lines/{lineId}/stock-options', [Api\SaleImportController::class, 'stockOptions']);
        Route::get('sale-imports/{id}/commit-payload', [Api\SaleImportController::class, 'commitPayload']);
        Route::post('sale-imports/{id}/committed', [Api\SaleImportController::class, 'markCommitted']);
        Route::delete('sale-imports/{id}', [Api\SaleImportController::class, 'destroy']);

        Route::post('bulk-uploads', [Api\BulkUploadControler::class, 'store']);
        Route::post('change-password', [Api\AcnooProfileController::class, 'changePassword'])->name('api.change-password');

        Route::get('new-invoice', [Api\AcnooInvoiceController::class, 'newInvoice']);

        Route::get('invoice-settings', [Api\BusinessInvoiceSettingController::class, 'index']);
        Route::post('/invoice-settings/update', [Api\BusinessInvoiceSettingController::class, 'updateInvoice'])->name('invoice.update');
        Route::post('/business-delete', [Api\BusinessController::class, 'deleteBusiness']);

        Route::get('/sign-out', [Api\Auth\AuthController::class, 'signOut']);
        Route::get('/auth/sessions', [Api\Auth\AuthController::class, 'sessions']);
        Route::delete('/auth/sessions/{id}', [Api\Auth\AuthController::class, 'revokeSession']);
        Route::get('/refresh-token', [Api\Auth\AuthController::class, 'refreshToken']);
        Route::get('/logout-all-users', [Api\Auth\AuthController::class, 'logoutAllUsers'])->middleware('admin');
    });

    // Customer App Routes - Completely separate from business auth
    Route::prefix('customer-app')->group(function () {
        Route::post('/register', [Api\Customer\CustomerAuthController::class, 'register']);
        Route::post('/login', [Api\Customer\CustomerAuthController::class, 'login']);
        Route::get('/businesses', [Api\Customer\CustomerAuthController::class, 'businesses']);

        Route::middleware('auth:sanctum')->group(function () {
            Route::apiResource('visit-reasons', Api\Customer\VisitReasonController::class);
            Route::apiResource('app-doctors', Api\Customer\AppDoctorController::class);
            Route::apiResource('patient-visits', Api\Customer\PatientVisitController::class);
            Route::apiResource('specializations', Api\Customer\SpecializationController::class);
            Route::apiResource('op-details', Api\Customer\OpDetailController::class);
            Route::apiResource('quick-actions', Api\Customer\QuickActionController::class);
            Route::apiResource('quick-action-banners', Api\Customer\QuickActionBannerController::class);
            Route::get('/profile', [Api\Customer\CustomerAuthController::class, 'profile']);
            Route::put('/profile', [Api\Customer\CustomerAuthController::class, 'updateProfile']);
            Route::post('/change-password', [Api\Customer\CustomerAuthController::class, 'changePassword']);
            Route::post('/logout', [Api\Customer\CustomerAuthController::class, 'logout']);
        });
    });
});
