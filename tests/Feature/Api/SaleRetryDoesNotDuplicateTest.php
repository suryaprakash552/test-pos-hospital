<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * A checkout that is retried must not become two sales.
 *
 * Reported from the shop floor: a medicine bill would not save, so it was split
 * into three and entered again — and the original turned up on the server after
 * all.
 *
 * The mechanism: `CustomHttpClient` gives up after 30 seconds. A long bill can
 * take longer than that server-side, so the till reports failure for a sale the
 * server goes on to commit. The operator, told it did not save, enters it
 * again.
 *
 * `EnsureIdempotentRequest` already guards this endpoint and is written for
 * exactly this — but its own doc says "applying this without a key is a no-op",
 * and the app had never sent one. The guard was inert in production.
 *
 * The first test pins that the guard is attached to the real sale route. The
 * rest drive the middleware on a controlled route: a successful sale needs
 * products, stock and batches that are not what is under test, and the
 * middleware releases the key on any 4xx by design — so a sale that fails
 * validation would exercise nothing at all.
 */
class SaleRetryDoesNotDuplicateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $business = Business::factory()->create();
        // `sales.create` is enforced before the controller; without the owner
        // role every request 403s, and the middleware releases the key on any
        // refusal by design — so nothing would be exercised.
        $this->user = User::factory()->create([
            'business_id' => $business->id,
            'role' => 'shop-owner',
        ]);

        // Stands in for a checkout that succeeds. The body is echoed so a
        // replay can be told from a fresh run.
        Route::middleware(['api', 'auth:sanctum', 'idempotent'])
            ->post('/__test/checkout', function () {
                return response()->json([
                    'invoice' => 'INV-'.uniqid(),
                ]);
            });
    }

    private function rows(): int
    {
        return DB::table('idempotency_keys')->count();
    }

    public function test_the_middleware_is_attached_to_the_sale_route(): void
    {
        $routes = collect(app('router')->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/v1/sales'
                && in_array('POST', $r->methods(), true));

        $this->assertNotNull($routes, 'the sale create route is missing');
        $this->assertContains(
            'idempotent',
            $routes->gatherMiddleware(),
            'without this a retried checkout creates a second sale'
        );
    }

    public function test_a_key_is_claimed_once(): void
    {
        // Two arrivals of the same attempt must leave one record, not two.
        $before = $this->rows();

        $this->actingAs($this->user)->postJson(
            '/__test/checkout',
            [],
            ['Idempotency-Key' => 'checkout-abc']
        );

        $afterFirst = $this->rows();

        $this->actingAs($this->user)->postJson(
            '/__test/checkout',
            [],
            ['Idempotency-Key' => 'checkout-abc']
        );

        $this->assertSame($afterFirst, $this->rows(),
            'the repeat claimed a second key, so it ran as a new sale');
        $this->assertGreaterThan($before, $afterFirst);
    }

    public function test_a_different_attempt_gets_its_own_key(): void
    {
        // The next customer must not be answered with the previous sale, which
        // is why the screen drops the key once one is saved.
        $this->actingAs($this->user)->postJson(
            '/__test/checkout', [],
            ['Idempotency-Key' => 'checkout-one']
        );
        $afterFirst = $this->rows();

        $this->actingAs($this->user)->postJson(
            '/__test/checkout', [],
            ['Idempotency-Key' => 'checkout-two']
        );

        $this->assertGreaterThan($afterFirst, $this->rows());
    }

    /**
     * Reusing a key for a genuinely different bill is refused rather than
     * answered with the old one — the failure mode that would be worst of all.
     */
    public function test_the_same_key_with_a_different_body_is_refused(): void
    {
        $this->actingAs($this->user)->postJson(
            '/__test/checkout',
            ['totalAmount' => '100'],
            ['Idempotency-Key' => 'checkout-xyz']
        );

        $response = $this->actingAs($this->user)->postJson(
            '/__test/checkout',
            ['totalAmount' => '999'],
            ['Idempotency-Key' => 'checkout-xyz']
        );

        $response->assertStatus(422);
        $this->assertSame('idempotency_key_reused', $response->json('reason'));
    }

    public function test_a_request_without_a_key_still_works(): void
    {
        // The no-op path older clients rely on; adding the key must not make it
        // a hard requirement.
        $before = $this->rows();

        $this->actingAs($this->user)
            ->postJson('/__test/checkout', []);

        $this->assertSame($before, $this->rows());
    }
}
