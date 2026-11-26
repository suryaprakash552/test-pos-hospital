<?php

namespace App\Providers;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->registerInstallerBeforeInstall();
    }

    /**
     * The setup wizard, registered only until the app is installed.
     *
     * `safiull/laravel-installer` declares `LaravelInstaller::codeVerifyProcess`
     * twice — once at `Routes/web.php:52` and again in a second `envato` group
     * at :125. Laravel tolerates that while routes are loaded per request, but
     * `route:cache` has to serialise names and refuses:
     *
     *     Unable to prepare route [envato/purchase-code/verify/process] for
     *     serialization. Another route has already been assigned name
     *     [LaravelInstaller::codeVerifyProcess].
     *
     * So route caching could never complete, and every request paid to parse
     * the whole route table. The package is excluded from auto-discovery in
     * `composer.json`; this puts it back only while `storage/installed` is
     * absent, which is the one time the wizard is wanted. Patching vendor code
     * would not survive `composer install`.
     */
    private function registerInstallerBeforeInstall(): void
    {
        if (file_exists(storage_path('installed'))) {
            return;
        }

        $provider = 'Laravel\\LaravelInstaller\\Providers\\LaravelInstallerServiceProvider';

        if (class_exists($provider)) {
            $this->app->register($provider);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        // Serialize dates in app timezone instead of UTC to prevent 1-day offset
        // when dates are sent to JSON. Without this, a date like "2026-10-02 00:00:00"
        // in Asia/Kolkata timezone becomes "2026-10-01 18:30:00" in UTC, which
        // then displays as the previous day.
        \Carbon\Carbon::serializeUsing(function (\Carbon\Carbon $carbon) {
            return $carbon->toDateString();
        });

        Blade::if('usercan', function ($permission) {
            return auth()->check() && auth()->user()->hasPermission($permission);
        });

        Blade::if('usercanany', function ($permissions) {
            return auth()->check() && auth()->user()->hasAnyPermission($permissions);
        });
    }
}
