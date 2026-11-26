<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reports — and optionally repairs — purchase lots that cannot be sold from.
 *
 * `purchase_details.conversionQuantity` is what remains of a lot, and the
 * availability check on every sale sums exactly that column. Where it is NULL the
 * sum is zero, so the product reads as out of stock however full the shelf is, and
 * the till refuses the sale. A shop upgrading into this behaviour with older rows
 * would find nothing sellable on the first morning, with no obvious cause.
 *
 * Read-only. `--fix` once wrote a derived remainder straight onto the lot; it is
 * refused now, because a lot balance that changes with no source document, reason
 * or event is exactly the drift this command exists to find. `inventory:reconcile`
 * is the one supported repair path.
 *
 * Everything else is reported and left alone. A product with several lots cannot
 * have its sales attributed to one of them after the fact — FIFO order was never
 * recorded — and a product that has sold more than it bought is corrupt in a way
 * no default can express. Guessing either would put a wrong number on a shelf,
 * which is worse than the honest refusal it replaces.
 */
class CheckBatchQuantities extends Command
{
    protected $signature = 'pos:check-batch-quantities
                            {--fix : Deprecated and rejected; no inventory data is changed}
                            {--business= : Restrict to one business}';

    protected $description = 'Find purchase lots whose remaining quantity is unusable';

    public function handle(): int
    {
        // This legacy repair guessed a lot balance and wrote it directly. That
        // is incompatible with the inventory domain policy: every change must
        // retain a source, reason and immutable event. Keep the command as a
        // diagnostic for operators who still know it, but reject mutation.
        if ($this->option('fix')) {
            $this->error(
                'Legacy repair mode is disabled. Run inventory:reconcile --dry-run, '
                .'review the result, then use its explicit --apply --confirm mode '
                .'only for redundant-tally corrections.'
            );

            return self::FAILURE;
        }

        $lots = DB::table('purchase_details')
            ->join('stocks', 'stocks.id', '=', 'purchase_details.stock_id')
            ->when($this->option('business'), fn ($q, $id) => $q->where('stocks.business_id', $id))
            ->whereNull('purchase_details.conversionQuantity')
            ->select(
                'purchase_details.id',
                'purchase_details.quantities',
                'stocks.product_id',
                'stocks.business_id',
            )
            ->get();

        // Lots whose stock_id was cleared are invisible to the join above, and so
        // to the availability check — they can never be sold from at all.
        $orphaned = DB::table('purchase_details')->whereNull('stock_id')->count();

        $this->info("Lots with no remaining quantity recorded: {$lots->count()}");
        $this->info("Lots detached from any batch (stock_id null): {$orphaned}");

        if ($lots->isEmpty() && $orphaned === 0) {
            $this->info('Nothing to repair: every lot can be sold from.');

            return self::SUCCESS;
        }

        if ($orphaned > 0) {
            $this->warn(
                'Detached lots cannot be repaired automatically — their batch is '
                . 'unknown. They need to be matched by hand or written off.'
            );
        }

        $this->line('');
        $this->line('Read-only diagnostic. Use inventory:reconcile --dry-run for supported drift analysis.');

        $this->table(
            ['lot', 'product', 'business', 'purchased'],
            $lots->take(20)->map(fn ($l) => [
                $l->id, $l->product_id, $l->business_id, $l->quantities,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
