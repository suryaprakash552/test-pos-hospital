<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Keeps `idempotency_keys` from growing for the life of the install.
 *
 * Every money-moving write stores a row here, and a completed row carries the
 * whole response body in a longText column. Nothing removed them, so a busy shop
 * accumulated one per sale, purchase and return, forever.
 *
 * Two different problems, deliberately handled differently:
 *
 *  - A **completed** key is a replayable answer. It is deleted only once it is
 *    older than the retention window, because deleting one early turns a late
 *    retry back into a second sale. A week is far longer than any till retries.
 *
 *  - A **processing** key is a claim whose request never finished - the till
 *    lost power, the worker was killed. Until it is released that key answers
 *    409 forever, so the same basket can never be retried. These are removed
 *    after a short window rather than kept.
 */
class PruneIdempotencyKeys extends Command
{
    protected $signature = 'pos:prune-idempotency-keys
                            {--dry-run : Report what would be removed without deleting anything}';

    protected $description = 'Remove expired idempotency keys and release claims left behind by crashed requests.';

    public function handle(): int
    {
        $retentionHours = max(1, (int) config('pos.idempotency.retention_hours', 168));
        $staleMinutes = max(1, (int) config('pos.idempotency.stale_processing_minutes', 15));

        $expiredBefore = now()->subHours($retentionHours);
        $staleBefore = now()->subMinutes($staleMinutes);

        $expired = DB::table('idempotency_keys')
            ->where('status', 'completed')
            ->where('created_at', '<', $expiredBefore);

        $stale = DB::table('idempotency_keys')
            ->where('status', '!=', 'completed')
            ->where('created_at', '<', $staleBefore);

        if ($this->option('dry-run')) {
            $this->info(sprintf(
                '%d completed keys older than %dh and %d claims stuck longer than %dm would be removed.',
                $expired->count(),
                $retentionHours,
                $stale->count(),
                $staleMinutes,
            ));

            return self::SUCCESS;
        }

        // Chunked so a long-neglected table does not lock for the length of one
        // enormous delete, which on a live till would stall checkout.
        $expiredRemoved = $this->deleteInBatches($expired);
        $staleRemoved = $this->deleteInBatches($stale);

        $this->info(sprintf(
            'Removed %d expired keys and released %d stuck claims.',
            $expiredRemoved,
            $staleRemoved,
        ));

        return self::SUCCESS;
    }

    private function deleteInBatches(\Illuminate\Database\Query\Builder $query, int $batch = 1000): int
    {
        $total = 0;

        do {
            $deleted = (clone $query)->limit($batch)->delete();
            $total += $deleted;
        } while ($deleted === $batch);

        return $total;
    }
}
