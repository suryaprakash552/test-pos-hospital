<?php

namespace App\Providers;

use App\Models\Category;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Policies\CategoryPolicy;
use App\Policies\ProductPolicy;
use App\Policies\SalePolicy;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Opcodes\LogViewer\Facades\LogViewer;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        Category::class => CategoryPolicy::class,
        Product::class => ProductPolicy::class,
        Sale::class => SalePolicy::class,
        User::class => UserPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->gateLogViewer();
    }

    /**
     * Who may read the application log.
     *
     * `opcodesio/log-viewer` denies everyone outside `local` until a gate is
     * defined, which is why `/log-viewer` answers 403 on the server. That is the
     * safe default and worth keeping: the log carries request payloads, customer
     * details and stack traces, so it is not something to open up.
     *
     * Signed in, and an administrator of the platform — not a shop owner, whose
     * log would contain other tenants' activity.
     */
    private function gateLogViewer(): void
    {
        if (! class_exists(LogViewer::class)) {
            return;
        }

        LogViewer::auth(function ($request) {
            $token = (string) config('app.log_viewer_token', '');

            if ($token !== '') {
                $presented = (string) $request->query('token', '');

                if ($presented !== '' && hash_equals($token, $presented)) {
                    // Remembered for the rest of the session. The viewer is a
                    // single-page app: the shell is the only request that
                    // carries the query string, and every `log-viewer/api/*`
                    // call its JavaScript makes afterwards arrives without one.
                    // Checking the query alone let the shell load and then
                    // 403'd all of its data, which is what "the log viewer is
                    // broken" looked like from the browser.
                    if ($request->hasSession()) {
                        $request->session()->put('log_viewer_token_ok', true);
                    }

                    return true;
                }

                if ($request->hasSession()
                    && $request->session()->get('log_viewer_token_ok') === true) {
                    return true;
                }
            }

            $user = $request->user();

            return $user !== null
                && in_array($user->role, ['superadmin', 'admin'], true);
        });
    }
}
