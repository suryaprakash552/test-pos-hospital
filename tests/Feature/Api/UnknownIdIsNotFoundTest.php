<?php

namespace Tests\Feature\Api;

use App\Models\Business;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * An id that resolves to nothing is a 404, not a 500.
 *
 * Straight from the production log:
 *
 *   Stock update failed {"stock_id":":stock",
 *     "error":"No query results for model [App\Models\Stock] :stock", ...}
 *
 * `:stock` is a Postman path variable that was never filled in. The endpoint
 * answered 500 and wrote a full stack trace at error level — so a request that
 * was simply malformed was indistinguishable, in the log, from the application
 * falling over. A 404 would have named the mistake instantly.
 *
 * This is the third time the same shape has been found: `PurchaseController::
 * destroy` did it, `StockController::update` did it, and both take a raw
 * `string $id` rather than a bound model, so `findOrFail()` lands in a generic
 * catch that returns 500. The ratchet at the bottom is there because finding it
 * a fourth time by reading a production log is not a plan.
 */
class UnknownIdIsNotFoundTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        return User::factory()->create([
            'business_id' => Business::factory()->create()->id,
            'role' => 'shop-owner',
        ]);
    }

    public function test_an_unfilled_path_variable_is_a_404(): void
    {
        // Verbatim the request that filled the log.
        $this->actingAs($this->owner())
            ->putJson('/api/v1/stocks/:stock', ['quantities' => 5])
            ->assertStatus(404);
    }

    public function test_an_unknown_stock_id_is_a_404(): void
    {
        $this->actingAs($this->owner())
            ->putJson('/api/v1/stocks/987654', ['quantities' => 5])
            ->assertStatus(404);
    }

    public function test_it_is_not_logged_as_an_error(): void
    {
        Log::spy();

        $this->actingAs($this->owner())
            ->putJson('/api/v1/stocks/:stock', ['quantities' => 5])
            ->assertStatus(404);

        Log::shouldNotHaveReceived('error');
    }

    /**
     * No API method may take a raw `$id`, look the record up with
     * `findOrFail`/`firstOrFail`, and let a generic catch turn the miss into a
     * 500. Either bind the model in the route, or check for null and return
     * 404 — the lock-and-refetch calls further inside a transaction are fine,
     * because those really do indicate a row vanishing mid-flight.
     */
    public function test_no_controller_turns_an_unknown_id_into_a_server_error(): void
    {
        $files = array_merge(
            glob(base_path('app/Http/Controllers/Api/*.php')),
            glob(base_path('Modules/*/app/Http/Controllers/Api/*.php'))
        );

        $this->assertNotEmpty($files, 'scanned no controllers at all');

        $offenders = [];
        $scanned = 0;

        foreach ($files as $file) {
            $src = file_get_contents($file);

            preg_match_all('/public function (\w+)\(([^)]*)\)/', $src, $ms, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

            foreach ($ms as $m) {
                $name = $m[1][0];
                $sig = $m[2][0];

                // Only methods handed a raw scalar id — a bound model already
                // 404s before the body runs.
                if (! preg_match('/(string|int)\s+\$id\b/', $sig)) {
                    continue;
                }

                $open = strpos($src, '{', $m[0][1] + strlen($m[0][0]));
                if ($open === false) {
                    continue;
                }

                // Brace-match rather than trust indentation: the method that
                // started all this opens its brace in column 0, and an
                // indentation-based scan silently truncated its body and
                // reported it clean.
                $depth = 0;
                $end = $open;
                for ($i = $open, $len = strlen($src); $i < $len; $i++) {
                    if ($src[$i] === '{') {
                        $depth++;
                    } elseif ($src[$i] === '}') {
                        if (--$depth === 0) {
                            $end = $i;
                            break;
                        }
                    }
                }

                $body = substr($src, $open, $end - $open + 1);
                $scanned++;

                if (preg_match('/->(findOrFail|firstOrFail)\(\s*\$id\s*\)/', $body)
                    && preg_match('/catch\s*\(\s*\\\\?(Exception|Throwable)/', $body)
                    && str_contains($body, '500')) {
                    $offenders[] = basename($file)."::{$name}";
                }
            }
        }

        $this->assertGreaterThan(0, $scanned,
            'matched no id-taking methods — this guard is not reading what it thinks');

        $this->assertSame([], $offenders,
            "These answer 500 for an id that simply does not exist, and log a "
            ."stack trace for it:\n  ".implode("\n  ", $offenders));
    }
}
