<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Pruning `idempotency_keys`.
 *
 * Nothing ever removed a row, and a completed one carries a whole response body,
 * so the table grew one row per sale, purchase and return for the life of the
 * install.
 *
 * The two halves pull in opposite directions and the tests below pin both: a
 * completed key must survive long enough that a late retry is still a replay
 * rather than a second sale, while a claim left behind by a crashed request must
 * be released quickly, because until it is that key answers 409 and the same
 * basket can never be retried.
 */
class PruneIdempotencyKeysTest extends TestCase
{
    use RefreshDatabase;

    private function key(string $status, \DateTimeInterface $createdAt, string $keyName = 'k1'): int
    {
        return DB::table('idempotency_keys')->insertGetId([
            'business_id' => 1,
            'user_id' => 1,
            'idempotency_key' => $keyName,
            'endpoint' => 'POST /api/v1/sales',
            'request_fingerprint' => hash('sha256', $keyName),
            'status' => $status,
            'response_status' => $status === 'completed' ? 200 : null,
            'response_body' => $status === 'completed' ? '{"ok":true}' : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function prune(): void
    {
        $this->artisan('pos:prune-idempotency-keys')->assertSuccessful();
    }

    private function exists(int $id): bool
    {
        return DB::table('idempotency_keys')->where('id', $id)->exists();
    }

    public function test_a_recent_completed_key_is_kept_so_a_retry_is_still_a_replay(): void
    {
        $id = $this->key('completed', now()->subHour());

        $this->prune();

        $this->assertTrue($this->exists($id));
    }

    public function test_a_completed_key_past_the_retention_window_is_removed(): void
    {
        config(['pos.idempotency.retention_hours' => 168]);
        $id = $this->key('completed', now()->subDays(8));

        $this->prune();

        $this->assertFalse($this->exists($id));
    }

    public function test_a_key_just_inside_the_retention_window_survives(): void
    {
        config(['pos.idempotency.retention_hours' => 168]);
        $id = $this->key('completed', now()->subDays(6));

        $this->prune();

        $this->assertTrue($this->exists($id));
    }

    public function test_a_claim_left_by_a_crashed_request_is_released(): void
    {
        config(['pos.idempotency.stale_processing_minutes' => 15]);
        $id = $this->key('processing', now()->subHour());

        $this->prune();

        $this->assertFalse(
            $this->exists($id),
            'Until this is released the same basket can never be retried.',
        );
    }

    public function test_a_request_still_in_flight_is_not_released(): void
    {
        config(['pos.idempotency.stale_processing_minutes' => 15]);
        $id = $this->key('processing', now()->subMinutes(2));

        $this->prune();

        $this->assertTrue(
            $this->exists($id),
            'Releasing a live claim would let a duplicate through mid-checkout.',
        );
    }

    public function test_an_old_processing_row_is_released_even_though_it_predates_retention(): void
    {
        // The two windows are independent: a stuck claim is not protected by the
        // completed-key retention period.
        config([
            'pos.idempotency.retention_hours' => 168,
            'pos.idempotency.stale_processing_minutes' => 15,
        ]);
        $id = $this->key('processing', now()->subHours(2));

        $this->prune();

        $this->assertFalse($this->exists($id));
    }

    public function test_a_dry_run_deletes_nothing(): void
    {
        $expired = $this->key('completed', now()->subDays(30), 'expired');
        $stale = $this->key('processing', now()->subDays(30), 'stale');

        $this->artisan('pos:prune-idempotency-keys', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertTrue($this->exists($expired));
        $this->assertTrue($this->exists($stale));
    }

    public function test_it_removes_more_than_one_batch(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->key('completed', now()->subDays(30), "bulk-{$i}");
        }

        $this->prune();

        $this->assertSame(0, DB::table('idempotency_keys')->count());
    }
}
