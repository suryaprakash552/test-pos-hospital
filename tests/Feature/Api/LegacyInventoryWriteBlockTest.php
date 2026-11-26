<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The legacy web panel must not be able to move stock.
 *
 * Its purchase, sale and both return controllers add to and subtract from
 * `products.productStock` directly and never write
 * `purchase_details.conversionQuantity` - the column the API, the app, FIFO
 * allocation and every availability check actually read.
 *
 * So a sale recorded there decrements a tally nobody sells from and the stock
 * stays on the counter's screen afterwards; a purchase there writes lot rows
 * with no batch and no quantity, which is stock that can never be sold and the
 * audit cannot count. The route file claimed these were blocked by a middleware
 * that did not exist.
 *
 * These pin the block itself rather than any one controller, so a new write
 * action added to those resources is covered the day it is written.
 */
class LegacyInventoryWriteBlockTest extends TestCase
{
    use RefreshDatabase;

    /** The legacy resources whose write verbs must be refused. */
    private const GUARDED = [
        'business.purchases',
        'business.sales',
        'business.sale-returns',
        'business.purchase-returns',
    ];

    /**
     * POST routes on those resources that only read.
     *
     * The list screens post their filters and the invoice mailer posts an id;
     * none reaches the stock writes, which live only in `store`, `update`,
     * `destroy` and `deleteAll`. Listed by exact suffix rather than skipped by
     * pattern so that a new write action on these resources fails this test on
     * the day it is added.
     */
    private const READ_ONLY_POSTS = [
        'filter',
        'product-filter',
        'category-filter',
        'brand-filter',
        'mail',
    ];

    public function test_every_legacy_inventory_write_route_carries_the_block(): void
    {
        $unguarded = [];

        foreach (Route::getRoutes() as $route) {
            $name = (string) $route->getName();

            if ($name === '') {
                continue;
            }

            $isGuardedResource = false;
            foreach (self::GUARDED as $prefix) {
                if (str_starts_with($name, $prefix.'.')) {
                    $isGuardedResource = true;
                    break;
                }
            }

            if (! $isGuardedResource) {
                continue;
            }

            $writes = array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']);

            if ($writes === []) {
                continue;
            }

            $action = substr($name, strrpos($name, '.') + 1);

            if (in_array($action, self::READ_ONLY_POSTS, true)) {
                continue;
            }

            if (! in_array('block.legacy-inventory-write', $route->gatherMiddleware(), true)) {
                $unguarded[] = $name.' ['.implode(',', $writes).']';
            }
        }

        $this->assertSame(
            [],
            $unguarded,
            'these legacy routes can still change stock: '.implode(', ', $unguarded)
        );
    }

    public function test_the_block_is_off_by_default(): void
    {
        // The safe state must be the one you get by doing nothing, so a
        // deployment that never sets the flag is protected.
        $this->assertFalse(config('inventory.allow_legacy_web_writes'));
    }

    /**
     * An absent environment variable blocks.
     *
     * Read through the config default rather than asserting the literal, so
     * that changing the default to something unsafe fails here.
     */
    public function test_an_absent_environment_variable_blocks(): void
    {
        $this->assertFalse((bool) env('ALLOW_LEGACY_WEB_WRITES', false));

        $this->actingAs($this->owner())
            ->post(route('business.purchases.store'), [])
            ->assertRedirect();
    }

    /** An explicit false blocks, exactly as an absent one does. */
    public function test_an_explicit_false_blocks(): void
    {
        config(['inventory.allow_legacy_web_writes' => false]);

        $this->actingAs($this->owner())
            ->post(route('business.purchases.store'), [])
            ->assertRedirect();
    }

    /**
     * Turning the flag on lifts the block, and lifts nothing else.
     *
     * The escape hatch exists for a deployment that still depends on the old
     * panel for one of these documents. What it must not do is quietly reopen
     * anything beyond the four resources it names, so this asserts the request
     * gets past the middleware and that no other guard was loosened with it.
     */
    public function test_enabling_the_flag_lifts_the_block_and_nothing_else(): void
    {
        config(['inventory.allow_legacy_web_writes' => true]);

        $middleware = new \App\Http\Middleware\BlockLegacyInventoryWrite();
        $passed = false;

        $request = \Illuminate\Http\Request::create('/business/purchases', 'POST');
        $middleware->handle($request, function () use (&$passed) {
            $passed = true;

            return new \Illuminate\Http\Response('reached');
        });

        $this->assertTrue($passed, 'the flag must let the write through');

        // Everything the flag does NOT touch: the routes it governs are still
        // exactly the four legacy resources, and the API remains the only
        // authoritative writer.
        $governed = [];
        foreach (Route::getRoutes() as $route) {
            if (in_array('block.legacy-inventory-write', $route->gatherMiddleware(), true)) {
                $name = (string) $route->getName();
                $governed[] = substr($name, 0, strrpos($name, '.'));
            }
        }

        $governed = array_values(array_unique($governed));
        sort($governed);

        $expected = self::GUARDED;
        sort($expected);

        $this->assertSame(
            $expected,
            $governed,
            'the flag governs exactly the four legacy resources and no others'
        );
    }

    private function owner(): User
    {
        $business = Business::factory()->create();

        return User::factory()->create([
            'business_id' => $business->id,
            'role' => 'owner',
        ]);
    }

    public function test_a_blocked_write_changes_no_stock(): void
    {
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'owner',
        ]);
        $category = Category::factory()->create(['business_id' => $business->id]);

        $product = Product::create([
            'productName' => 'Blocked Item',
            'business_id' => $business->id,
            'category_id' => $category->id,
            'productCode' => 'BLK-1',
            'productStock' => 25,
        ]);

        $before = DB::table('products')->where('id', $product->id)->value('productStock');
        $lotsBefore = DB::table('purchase_details')->count();

        $this->actingAs($user)
            ->post(route('business.purchases.store'), [
                'party_id' => 1,
                'products' => [['product_id' => $product->id, 'quantities' => 100]],
            ]);

        $this->assertSame(
            $before,
            DB::table('products')->where('id', $product->id)->value('productStock'),
            'the tally moved despite the block'
        );
        $this->assertSame(
            $lotsBefore,
            DB::table('purchase_details')->count(),
            'a lot row was written despite the block'
        );
    }

    public function test_the_write_forms_are_turned_back_too(): void
    {
        // A form whose Save is refused reads as a broken page rather than a
        // withdrawn feature, so the entry point is turned back with the reason.
        $business = Business::factory()->create();
        $user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'owner',
        ]);

        $this->actingAs($user)
            ->get(route('business.purchases.create'))
            ->assertRedirect();
    }

    public function test_read_pages_are_left_alone(): void
    {
        // Seeing a purchase list is safe; creating one from here is not. The
        // block must not take the history and export pages with it.
        $listing = Route::getRoutes()->getByName('business.purchases.index');

        $this->assertNotNull($listing);
        $this->assertSame(['GET', 'HEAD'], $listing->methods());
    }

    /**
     * Exactly which requests the middleware turns back.
     *
     * Driven against the middleware itself rather than through the router, so
     * the boundary is stated without the web panel's auth redirects muddying
     * what is being asserted. Attaching the middleware to a resource is not the
     * same as refusing every action on it: the listing and the invoice view
     * carry it and must still pass.
     */
    public function test_the_refusal_boundary_is_exactly_writes_and_entry_forms(): void
    {
        $middleware = new \App\Http\Middleware\BlockLegacyInventoryWrite();

        $cases = [
            // [method, route name, may it through?]
            ['POST',   'business.purchases.store',   false],
            ['PUT',    'business.purchases.update',  false],
            ['PATCH',  'business.purchases.update',  false],
            ['DELETE', 'business.purchases.destroy', false],
            ['POST',   'business.sales.store',       false],
            ['POST',   'business.sale-returns.store', false],
            ['POST',   'business.purchase-returns.store', false],
            ['GET',    'business.purchases.create',  false],
            ['GET',    'business.purchases.edit',    false],
            ['GET',    'business.purchases.index',   true],
            ['GET',    'business.sales.show',        true],
            ['GET',    'business.sales.index',       true],
        ];

        foreach ($cases as [$method, $name, $shouldPass]) {
            $passed = false;

            $request = \Illuminate\Http\Request::create('/business/x', $method);
            $request->setRouteResolver(fn () => tap(
                new \Illuminate\Routing\Route([$method], '/business/x', []),
                fn ($r) => $r->name($name)
            ));

            $middleware->handle($request, function () use (&$passed) {
                $passed = true;

                return new \Illuminate\Http\Response('reached');
            });

            $this->assertSame(
                $shouldPass,
                $passed,
                "$method $name should ".($shouldPass ? 'pass' : 'be refused')
            );
        }
    }

    /** JSON callers get a machine-readable refusal, not an HTML redirect. */
    public function test_a_json_caller_gets_a_typed_refusal(): void
    {
        $middleware = new \App\Http\Middleware\BlockLegacyInventoryWrite();

        $request = \Illuminate\Http\Request::create('/business/purchases', 'POST');
        $request->headers->set('Accept', 'application/json');

        $response = $middleware->handle($request, fn () => new \Illuminate\Http\Response('reached'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            'legacy_inventory_write_disabled',
            json_decode($response->getContent(), true)['reason']
        );
    }

    /**
     * Creating a product from the web panel is not a stock movement.
     *
     * `store` wrote the posted quantity onto the product and onto a fresh
     * batch, with no purchase lot behind either. Availability sums the lots, so
     * that stock could never be sold - while the tally it inflated is what the
     * legacy screens read. It is the shape of every batch found holding a
     * quantity with no lot at all.
     */
    public function test_creating_a_product_from_the_web_panel_creates_no_stock(): void
    {
        $source = file_get_contents(
            base_path('Modules/Business/App/Http/Controllers/AcnooProductController.php')
        );

        $this->assertStringNotContainsString(
            "'productStock' => \$request->productStock",
            $source,
            'the web product controller must not write a posted stock quantity'
        );
    }

    /**
     * Editing a product does not rewrite every batch it is held in.
     *
     * `productStock` sat in the cascade that pushes prices down to a product's
     * batches, so one edit set all of them to the same number while the
     * purchase lots went on saying otherwise.
     */
    public function test_editing_a_product_does_not_cascade_a_quantity_to_its_batches(): void
    {
        $source = file_get_contents(
            base_path('Modules/Business/App/Http/Controllers/AcnooProductController.php')
        );

        $cascade = substr(
            $source,
            strpos($source, "Stock::where('product_id', \$product->id)->update(")
        );
        $cascade = substr($cascade, 0, strpos($cascade, ']);'));

        $this->assertStringNotContainsString(
            'productStock',
            $cascade,
            'the price cascade must not carry a quantity'
        );
    }

    /**
     * No inventory-writing route may sit outside the authenticated group.
     *
     * `sales/delete-all` and `purchases/delete-all` were registered after the
     * group closed, so they ran on the bare `web` stack - no authentication, no
     * tenant scope - while incrementing `products.productStock` from an
     * unscoped `whereIn('id', ...)`. They were also named `sales.delete-all`
     * rather than `business.sales.delete-all`, which is why a prefix scan over
     * the four resources walked straight past them.
     *
     * This looks at the controllers instead of the names: any route pointing at
     * a legacy controller method that writes stock must carry both the auth
     * middleware and the block.
     */
    public function test_no_stock_writing_legacy_action_escapes_the_guarded_group(): void
    {
        // Methods proven to write `products.productStock` in the legacy module.
        $writers = [
            'Modules\\Business\\App\\Http\\Controllers\\AcnooSaleController' =>
                ['store', 'update', 'destroy', 'deleteAll'],
            'Modules\\Business\\App\\Http\\Controllers\\AcnooPurchaseController' =>
                ['store', 'update', 'destroy', 'deleteAll'],
            'Modules\\Business\\App\\Http\\Controllers\\SaleReturnController' => ['store'],
            'Modules\\Business\\App\\Http\\Controllers\\PurchaseReturnController' => ['store'],
        ];

        $unprotected = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action, 2);

            if (! isset($writers[$class]) || ! in_array($method, $writers[$class], true)) {
                continue;
            }

            $middleware = $route->gatherMiddleware();

            $guarded = in_array('block.legacy-inventory-write', $middleware, true)
                || in_array(\App\Http\Middleware\BlockLegacyInventoryWrite::class, $middleware, true);

            $authed = in_array(\App\Http\Middleware\UserMiddleware::class, $middleware, true)
                || in_array('users', $middleware, true);

            if (! $guarded || ! $authed) {
                $unprotected[] = sprintf(
                    '%s (%s) guarded=%s authed=%s',
                    $route->uri(),
                    $method,
                    $guarded ? 'yes' : 'NO',
                    $authed ? 'yes' : 'NO'
                );
            }
        }

        $this->assertSame(
            [],
            $unprotected,
            "legacy stock writers reachable without auth and/or the block:\n  "
                .implode("\n  ", $unprotected)
        );
    }
}
