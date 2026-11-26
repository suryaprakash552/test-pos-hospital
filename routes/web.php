<?php

use App\Models\Stock;
use App\Models\Option;
use App\Models\Product;
use App\Models\PaymentType;
use App\Http\Controllers as Web;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Artisan;

Route::get('/', [Web\WebController::class, 'index'])->name('home');
Route::resource('blogs', Web\BlogController::class)->only('index', 'show', 'store');
Route::get('/about-us', [Web\AboutController::class, 'index'])->name('about.index');
Route::get('/plans', [Web\PlanController::class, 'index'])->name('plan.index');

// Business Signup
Route::get('/get-business-categories', [Web\AcnooBusinessController::class, 'getBusinessCategories'])->name('get-business-categories');
// Named `business.signup`, not `business.store`: the API's
// `apiResource('business')` already owns that name, and two routes sharing
// one name makes `route:cache` refuse the whole table —
//   Unable to prepare route [businesses] for serialization.
// So the app has been running uncached, parsing every route on every
// request. `DemoMode` guards `business.store`, meaning the API route, so
// the API keeps the name.
Route::post('/businesses', [Web\AcnooBusinessController::class, 'store'])->name('business.signup');
Route::post('/verify-code', [Web\AcnooBusinessController::class, 'verifyCode'])->name('business.verify-code');

// Was also named `term.index`, colliding with terms-conditions below and
// blocking `route:cache` for the whole table. Every `route('term.index')`
// call site links Terms & Conditions, so that page keeps the name.
Route::get('/data-deletion', [Web\DataDeletionController::class, 'index'])->name('data-deletion.index');
Route::get('/terms-conditions', [Web\TermServiceController::class, 'index'])->name('term.index');
Route::get('/privacy-policy', [Web\PolicyController::class, 'index'])->name('policy.index');

Route::get('/contact-us', [Web\ContactController::class, 'index'])->name('contact.index');
Route::post('/contact/store', [Web\ContactController::class, 'store'])->name('contact.store');

// Payment Routes Start
Route::get('/payments-gateways/{plan_id}/{business_id}', [Web\PaymentController::class, 'index'])->name('payments-gateways.index');
Route::post('/payments/{plan_id}/{gateway_id}', [Web\PaymentController::class, 'payment'])->name('payments-gateways.payment');
Route::get('/payment/success', [Web\PaymentController::class, 'success'])->name('payment.success');
Route::get('/payment/failed', [Web\PaymentController::class, 'failed'])->name('payment.failed');
Route::post('ssl-commerz/payment/success', [Web\PaymentController::class, 'sslCommerzSuccess']);
Route::post('ssl-commerz/payment/failed', [Web\PaymentController::class, 'sslCommerzFailed']);
Route::get('/order-status', [Web\PaymentController::class, 'orderStatus'])->name('order.status');

Route::group([
    'namespace' => 'App\Library',
], function () {
    Route::get('/payment/paypal', 'Paypal@status');
    Route::get('/payment/mollie', 'Mollie@status');
    Route::post('/payment/paystack', 'Paystack@status')->name('paystack.status');
    Route::get('/paystack', 'Paystack@view')->name('paystack.view');
    Route::get('/razorpay/payment', 'Razorpay@view')->name('razorpay.view');
    Route::post('/razorpay/status', 'Razorpay@status');
    Route::get('/mercadopago/pay', 'Mercado@status')->name('mercadopago.status');
    Route::get('/payment/flutterwave', 'Flutterwave@status');
    Route::get('/payment/thawani', 'Thawani@status');
    Route::get('/payment/instamojo', 'Instamojo@status');
    Route::get('/payment/toyyibpay', 'Toyyibpay@status');
    Route::get('/phonepe/status', 'PhonePe@status')->name('phonepe.status');
    Route::post('/paytm/status', 'Paytm@status')->name('paytm.status');
    Route::get('/tap-payment/status', 'TapPayment@status')->name('tap-payment.status');
});
// Payment Routes End

Route::middleware(['auth', 'admin', 'role:superadmin'])->group(function () {
    Route::get('/cache-clear', function () {
        abort_unless(config('app-settings.allow_maintenance_routes') || app()->environment(['local', 'staging']), 403);

        Artisan::call('cache:clear');
        Artisan::call('config:clear');
        Artisan::call('route:clear');
        Artisan::call('view:clear');

        return back()->with('success', __('messages.cache_cleared'));
    });

    Route::get('/migrate', function () {
        abort_unless(config('app-settings.allow_maintenance_routes') || app()->environment(['local', 'staging']), 403);

        Artisan::call('migrate');

        return back()->with('success', __('messages.migrated'));
    });

    Route::get('/update', function () {
        abort_unless(config('app-settings.allow_maintenance_routes') || app()->environment(['local', 'staging']), 403);

        $version = Option::where('key', 'version')->value('value') ?? config('app-settings.version');

        Artisan::call('migrate');

        if (file_exists(base_path('storage/installed'))) {
            touch(base_path('vendor/autoload1.php'));
        }

        if (!PaymentType::exists()) {
            Artisan::call('db:seed', ['--class' => 'PaymentTypeSeeder']);
        }

        if (Schema::hasTable('stocks') && !Stock::exists()) {
            Product::chunk(500, function ($products) {
                $data = [];
                foreach ($products as $product) {
                    $data[] = [
                        'business_id' => $product->business_id,
                        'product_id'  => $product->id,
                        'expire_date' => $product->expire_date ?? null,
                        'productStock' => $product->productStock,
                        'profit_percent' => $product->profit_percent,
                        'productDealerPrice' => $product->productDealerPrice,
                        'productPurchasePrice' => $product->productPurchasePrice,
                        'productSalePrice' => $product->productSalePrice,
                        'productWholeSalePrice' => $product->productWholeSalePrice,
                        'created_at'  => $product->created_at,
                        'updated_at'  => $product->updated_at,
                    ];
                }
                Stock::insert($data);
            });
        }

        if ($version == 5.4) {
            $updateFile = base_path('updates/v5_5_update.php');
            if (file_exists($updateFile)) {
                require $updateFile;
                if (function_exists('runUpdate')) {
                    runUpdate();
                }
            }
        }

        Option::updateOrCreate(
            ['key' => 'version'],
            ['value' => config('app-settings.version')]
        );

        Artisan::call('cache:clear');
        Artisan::call('config:clear');
        Artisan::call('route:clear');
        Artisan::call('view:clear');

        return redirect('/')->with('message', __('messages.system_updated'));
    });
});

require __DIR__ . '/auth.php';
