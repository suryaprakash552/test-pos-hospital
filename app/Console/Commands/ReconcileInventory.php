<?php

namespace App\Console\Commands;

use App\Services\InventoryReconciliationService;
use Illuminate\Console\Command;

class ReconcileInventory extends Command
{
    protected $signature = 'inventory:reconcile
        {--business= : Restrict to one business ID}
        {--branch= : Restrict to one branch ID}
        {--dry-run : Report drift without changing any data}
        {--apply : Correct redundant tally columns only}
        {--confirm : Required together with --apply}';

    protected $description = 'Compare authoritative purchase-lot balances against redundant stock/product tallies.';

    public function handle(InventoryReconciliationService $reconciliation): int
    {
        if ($this->option('apply') && ! $this->option('confirm')) {
            $this->error('--apply requires --confirm. No data was changed.');

            return self::FAILURE;
        }

        if ($this->option('apply') && $this->option('dry-run')) {
            $this->error('Choose either --dry-run or --apply, not both.');

            return self::FAILURE;
        }

        $rows = $reconciliation->inspect(
            $this->option('business') === null ? null : (int) $this->option('business'),
            $this->option('branch') === null ? null : (int) $this->option('branch'),
        );
        $drifted = $rows->filter(fn (array $row) => $row['batch_mismatch'] || $row['product_mismatch']);
        $critical = $rows->filter(fn (array $row) => $row['negative_or_impossible']);

        $this->table(
            ['Business', 'Branch', 'Product', 'Stock', 'Batch', 'Lot lower', 'Expected batch', 'Stored batch', 'Expected product', 'Stored product', 'Mismatch'],
            $drifted->map(fn (array $row) => [
                $row['business_id'], $row['branch_id'], $row['product_id'], $row['stock_id'],
                $row['batch_no'], $row['authoritative_lower_quantity'], $row['expected_batch_quantity'],
                $row['stored_batch_quantity'], $row['expected_product_quantity'],
                $row['stored_product_quantity'],
                trim(($row['batch_mismatch'] ? 'batch ' : '').($row['product_mismatch'] ? 'product' : '')),
            ])->all(),
        );

        $this->info(json_encode([
            'mode' => $this->option('apply') ? 'apply' : 'read_only',
            'batches_checked' => $rows->count(),
            'products_checked' => $rows->pluck('product_id')->unique()->count(),
            'matched_records' => $rows->count() - $drifted->count(),
            'drifted_records' => $drifted->count(),
            'critical_negative_or_impossible' => $critical->count(),
        ], JSON_PRETTY_PRINT));

        if (! $this->option('apply')) {
            return self::SUCCESS;
        }

        $changes = $reconciliation->apply($rows);
        $this->warn("Applied {$changes} redundant-tally correction(s). Authoritative purchase lots were not changed.");

        return self::SUCCESS;
    }
}
