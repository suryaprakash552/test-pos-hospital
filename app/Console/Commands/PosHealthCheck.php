<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Why a deployment cannot take sales.
 *
 * "The app is not stable and we cannot submit sales" is not something anyone can
 * act on, and it is usually not the app: the till refuses a sale when the *data*
 * says there is nothing to sell, and the message it shows — "Available stock is:
 * 0" — looks like a bug when the shelf is visibly full.
 *
 * Stock available to sell is `SUM(purchase_details.conversionQuantity)` for the
 * batch. That column is what a sale decrements. Where it was never populated the
 * sum is zero, so the product is unsellable however many units were purchased, and
 * nothing in the app explains why.
 *
 * This command is read-only. It reports what would block a sale on *this* database
 * and names the fix, so a report of "lots of bugs" becomes a list of rows.
 */
class PosHealthCheck extends Command
{
    protected $signature = 'pos:health-check
                            {--business= : Restrict to one business}';

    protected $description = 'Report what is preventing sales and purchases on this database';

    public function handle(): int
    {
        $businessId = $this->option('business');

        $this->info('POS health check');
        $this->line(str_repeat('─', 60));

        $blocked = $this->reportSellability($businessId);
        $this->reportDataIntegrity($businessId);
        $this->reportConfiguration();

        $this->line(str_repeat('─', 60));

        if ($blocked > 0) {
            $this->error("{$blocked} batch(es) cannot be sold from.");
            $this->line('');
            $this->line('This is the commonest cause of "cannot submit a sale". The till');
            $this->line('sums `purchase_details.conversionQuantity` to decide what is');
            $this->line('available; where that is NULL the answer is zero and the sale is');
            $this->line('refused with "Available stock is : 0".');
            $this->line('');
            $this->line('Fix what can be derived:  php artisan pos:check-batch-quantities --fix');
            $this->line('Anything it refuses needs a human — it will say which and why.');

            return self::FAILURE;
        }

        $this->info('Every batch on this database can be sold from.');

        return self::SUCCESS;
    }

    /** How much of the catalogue the till would refuse, and why. */
    private function reportSellability(?string $businessId): int
    {
        $stocks = DB::table('stocks')
            ->when($businessId, fn ($q) => $q->where('business_id', $businessId));

        $total = (clone $stocks)->count();

        // A batch is sellable only if some lot against it carries a remaining
        // quantity. This is the same question the sale endpoint asks.
        $sellable = (clone $stocks)
            ->whereIn('id', fn ($q) => $q
                ->select('stock_id')
                ->from('purchase_details')
                ->whereNotNull('stock_id')
                ->whereNotNull('conversionQuantity'))
            ->count();

        $blocked = $total - $sellable;

        $this->line('');
        $this->line("Batches:          {$total}");
        $this->line("Sellable:         {$sellable}");

        if ($blocked > 0) {
            $this->warn("Not sellable:     {$blocked}");
        } else {
            $this->line("Not sellable:     0");
        }

        if ($blocked === 0) {
            return 0;
        }

        // Named, because "6 batches" is not something anyone can act on.
        $examples = DB::table('stocks')
            ->leftJoin('products', 'products.id', '=', 'stocks.product_id')
            ->when($businessId, fn ($q) => $q->where('stocks.business_id', $businessId))
            ->whereNotIn('stocks.id', fn ($q) => $q
                ->select('stock_id')
                ->from('purchase_details')
                ->whereNotNull('stock_id')
                ->whereNotNull('conversionQuantity'))
            ->limit(15)
            ->get([
                'stocks.id as stock_id',
                'stocks.batch_no',
                'products.productName',
                'products.productCode',
            ]);

        $this->line('');
        $this->line('Products a cashier cannot sell:');
        $this->table(
            ['batch', 'batch no', 'product', 'code'],
            $examples->map(fn ($r) => [
                $r->stock_id,
                $r->batch_no ?? '—',
                $r->productName ?? '(deleted product)',
                $r->productCode ?? '—',
            ])->all(),
        );

        if ($blocked > $examples->count()) {
            $this->line('… and ' . ($blocked - $examples->count()) . ' more.');
        }

        return $blocked;
    }

    /** Rows that are inconsistent in ways that will surface later. */
    private function reportDataIntegrity(?string $businessId): void
    {
        $this->line('');
        $this->line('Data integrity');

        $nullRemaining = DB::table('purchase_details')
            ->whereNull('conversionQuantity')
            ->count();

        // A lot whose batch was cleared is invisible to the availability check, so
        // its stock can never be sold and never be counted.
        $detached = DB::table('purchase_details')->whereNull('stock_id')->count();

        // A batch with no lots at all was never received against.
        $emptyBatches = DB::table('stocks')
            ->when($businessId, fn ($q) => $q->where('business_id', $businessId))
            ->whereNotIn('id', fn ($q) => $q
                ->select('stock_id')->from('purchase_details')->whereNotNull('stock_id'))
            ->count();

        $this->line("  Lots with no remaining quantity recorded: {$nullRemaining}");
        $this->line("  Lots detached from any batch:             {$detached}");
        $this->line("  Batches never purchased into:             {$emptyBatches}");

        // Sold more than was ever bought — corrupt in a way no default can express.
        $oversold = DB::table('sale_details')
            ->join('products', 'products.id', '=', 'sale_details.product_id')
            ->when($businessId, fn ($q) => $q->where('products.business_id', $businessId))
            ->groupBy('sale_details.product_id')
            ->havingRaw('SUM(sale_details.quantities) > COALESCE((
                SELECT SUM(pd.quantities) FROM purchase_details pd
                WHERE pd.product_id = sale_details.product_id
            ), 0)')
            ->select('sale_details.product_id')
            ->get()
            ->count();

        if ($oversold > 0) {
            $this->warn("  Products sold beyond what was purchased:  {$oversold}");
        } else {
            $this->line("  Products sold beyond what was purchased:  0");
        }
    }

    /** Settings that stop work even when the data is sound. */
    private function reportConfiguration(): void
    {
        $this->line('');
        $this->line('Configuration');

        $queues = array_unique(array_values(config('pos.queues', [])));
        $this->line('  Queues in use: ' . (empty($queues) ? 'default' : implode(', ', $queues)));

        if (! empty($queues) && $queues !== ['default']) {
            // A job on a named queue that no worker consumes never runs, and
            // nothing reports it — the work simply never happens.
            $this->line('    A worker must consume each of these, e.g.');
            $this->line('    php artisan queue:work --queue=' . implode(',', $queues));
        }

        $this->line('  Mail host:     ' . (config('mail.mailers.' . config('mail.default') . '.host') ?: 'not configured'));
        $this->line('  Reminders on:  ' . implode(', ', config('pos.loyalty.reminder_channels', [])));
    }
}
