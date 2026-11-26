<?php

namespace App\Services;

use App\Models\InventoryChangeLog;
use App\Models\Purchase;
use App\Models\PurchaseDetails;
use App\Models\Product;
use App\Models\Stock;
use App\Models\StockAudit;
use App\Services\Inventory\MovementContext;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Records controlled inventory source-document changes and invalidates the
 * inventory read model after a successful transaction. It never mutates a
 * quantity, lot, batch, or product balance itself.
 */
class InventoryChangeService
{
    public const PURCHASE_CREATED = 'purchase_created';
    public const PURCHASE_CORRECTED = 'purchase_corrected';
    public const STOCK_AUDIT_ADJUSTMENT = 'stock_audit_adjustment';
    public const RECONCILIATION_CORRECTED = 'derived_tally_reconciled';
    public const SALE_OUT = 'sale_out';
    public const SALE_RETURN_IN = 'sale_return_in';
    public const PURCHASE_RETURN_OUT = 'purchase_return_out';
    public const DISPOSAL_OUT = 'disposal_out';

    /**
     * Stock put back because a sale was cancelled or edited, as distinct from
     * a customer returning goods.
     *
     * `SALE_RETURN_IN` means a customer brought something back and is a
     * document in its own right. Reversing a sale that never completed is a
     * different fact, and recording both under one type would make the returns
     * figures include sales that were merely corrected.
     */
    public const SALE_REVERSAL_IN = 'sale_reversal_in';

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    public function recordPurchaseEvent(
        string $eventType,
        Purchase $purchase,
        PurchaseDetails $detail,
        Stock $stock,
        array $before,
        array $after,
        ?string $reason = null,
        ?string $correlationId = null,
    ): InventoryChangeLog {
        $user = auth()->user();

        if ((int) $purchase->business_id !== (int) $stock->business_id) {
            throw new InvalidArgumentException('Purchase and stock must belong to the same business.');
        }

        return InventoryChangeLog::create([
            'business_id' => $purchase->business_id,
            'branch_id' => $purchase->branch_id ?? $user?->active_branch_id ?? $user?->branch_id,
            'user_id' => $user?->id,
            'event_type' => $eventType,
            'source_type' => Purchase::class,
            'source_id' => $purchase->id,
            'purchase_id' => $purchase->id,
            'purchase_detail_id' => $detail->id,
            'stock_id' => $stock->id,
            'product_id' => $stock->product_id,
            'correlation_id' => $correlationId,
            'quantity_delta' => round(
                (float) ($after['remaining_quantity'] ?? 0) - (float) ($before['remaining_quantity'] ?? 0),
                4,
            ),
            'before_values' => $before,
            'after_values' => $after,
            'reason' => $reason,
            'ip_address' => request()?->ip(),
        ]);
    }

    /** @param array<string,mixed> $before */
    public function recordPurchaseRemovalEvent(
        Purchase $purchase,
        Stock $stock,
        array $before,
        string $reason,
        string $correlationId,
    ): InventoryChangeLog {
        $user = auth()->user();

        return InventoryChangeLog::create([
            'business_id' => $purchase->business_id,
            'branch_id' => $purchase->branch_id ?? $user?->active_branch_id ?? $user?->branch_id,
            'user_id' => $user?->id,
            'event_type' => self::PURCHASE_CORRECTED,
            'source_type' => Purchase::class,
            'source_id' => $purchase->id,
            'purchase_id' => $purchase->id,
            'purchase_detail_id' => $before['purchase_detail_id'] ?? null,
            'stock_id' => $stock->id,
            'product_id' => $stock->product_id,
            'correlation_id' => $correlationId,
            'quantity_delta' => round(-(float) ($before['remaining_quantity'] ?? 0), 4),
            'before_values' => $before,
            'after_values' => [
                'state' => 'removed_from_purchase',
                'stock_id' => $stock->id,
                'product_id' => $stock->product_id,
                'remaining_quantity' => 0.0,
            ],
            'reason' => $reason,
            'ip_address' => request()?->ip(),
        ]);
    }

    /** Schedule product/inventory cache invalidation only after a successful commit. */
    public function invalidateInventoryAfterCommit(): void
    {
        DB::afterCommit(static fn () => CacheService::clearProductsCache());
    }

    /** Rebuild only redundant current-state tallies from authoritative lots. */
    public function synchronizeDerivedTallies(Stock $stock): void
    {
        $batchUpper = (float) DB::table('purchase_details')
            ->where('stock_id', $stock->id)
            ->selectRaw('COALESCE(SUM(conversionQuantity / GREATEST(COALESCE(NULLIF(conversionValue, 0), 1), 1)), 0) as total')
            ->value('total');
        $stock->forceFill(['productStock' => round($batchUpper, 4)])->save();

        // products.productStock is not updated here as it represents a manual
        // max quantity/alert threshold set by the user, not a derived tally
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    public function recordStockAuditEvent(
        StockAudit $audit,
        Stock $stock,
        array $before,
        array $after,
    ): InventoryChangeLog {
        $user = auth()->user();

        if ((int) $audit->business_id !== (int) $stock->business_id) {
            throw new InvalidArgumentException('Stock audit and batch must belong to the same business.');
        }

        return InventoryChangeLog::create([
            'business_id' => $audit->business_id,
            'branch_id' => $stock->branch_id ?? $user?->active_branch_id ?? $user?->branch_id,
            'user_id' => $audit->user_id,
            'event_type' => self::STOCK_AUDIT_ADJUSTMENT,
            'source_type' => StockAudit::class,
            'source_id' => $audit->id,
            'stock_id' => $stock->id,
            'product_id' => $stock->product_id,
            'correlation_id' => $audit->audit_reference,
            'quantity_delta' => $audit->difference_quantity,
            'before_values' => $before,
            'after_values' => $after,
            'reason' => $audit->remarks ?: 'Physical stock audit',
            'ip_address' => request()?->ip(),
        ]);
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    public function recordDerivedTallyCorrection(
        Stock|Product $subject,
        array $before,
        array $after,
        string $correlationId,
    ): InventoryChangeLog {
        $user = auth()->user();
        $isStock = $subject instanceof Stock;

        return InventoryChangeLog::create([
            'business_id' => $subject->business_id,
            'branch_id' => $isStock ? $subject->branch_id : ($user?->active_branch_id ?? $user?->branch_id),
            'user_id' => $user?->id,
            'event_type' => self::RECONCILIATION_CORRECTED,
            'source_type' => self::class,
            'source_id' => null,
            'stock_id' => $isStock ? $subject->id : null,
            'product_id' => $isStock ? $subject->product_id : $subject->id,
            'correlation_id' => $correlationId,
            'quantity_delta' => round(
                (float) ($after['stored_upper_quantity'] ?? 0) - (float) ($before['stored_upper_quantity'] ?? 0),
                4,
            ),
            'before_values' => $before,
            'after_values' => $after,
            'reason' => 'Explicit inventory reconciliation of redundant tally',
            'ip_address' => request()?->ip(),
        ]);
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    public function recordMovement(
        string $eventType,
        Model $source,
        Stock $stock,
        float $quantityDelta,
        array $before,
        array $after,
        ?string $reason = null,
        ?string $correlationId = null,
    ): InventoryChangeLog {
        $user = auth()->user();

        if ((int) $source->business_id !== (int) $stock->business_id) {
            throw new InvalidArgumentException('Inventory source and stock must belong to the same business.');
        }

        return InventoryChangeLog::create([
            'business_id' => $stock->business_id,
            'branch_id' => $stock->branch_id ?? $source->branch_id ?? $user?->active_branch_id ?? $user?->branch_id,
            'user_id' => $user?->id ?? $source->user_id ?? null,
            'event_type' => $eventType,
            'source_type' => $source::class,
            'source_id' => $source->getKey(),
            'stock_id' => $stock->id,
            'product_id' => $stock->product_id,
            'correlation_id' => $correlationId,
            'quantity_delta' => round($quantityDelta, 4),
            'before_values' => $before,
            'after_values' => $after,
            'reason' => $reason,
            'ip_address' => request()?->ip(),
        ]);
    }

    /**
     * Records one authoritative lot movement.
     *
     * `recordMovement` above writes at batch level, which cannot say which lot
     * moved. A sale drawing 30 from one lot and 20 from another needs both
     * facts kept separately: the aggregate 50 is what the batch holds, but only
     * the per-lot split can be reversed correctly later, and only it can
     * attribute cost to the purchase the stock actually came from.
     *
     * These rows are the allocation record. There is no separate allocation
     * table, deliberately - a second store of the same fact would be a second
     * thing to keep in step.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     */
    public function recordLotMovement(
        MovementContext $context,
        string $eventType,
        Stock $stock,
        int $purchaseDetailId,
        float $quantityDelta,
        array $before,
        array $after,
    ): InventoryChangeLog {
        if ($context->businessId !== (int) $stock->business_id) {
            throw new InvalidArgumentException('Inventory source and stock must belong to the same business.');
        }

        return InventoryChangeLog::create([
            'business_id' => $context->businessId,
            'branch_id' => $context->branchId ?? $stock->branch_id,
            'user_id' => $context->userId,
            'event_type' => $eventType,
            'source_type' => $context->sourceType,
            'source_id' => $context->sourceId,
            'purchase_detail_id' => $purchaseDetailId,
            'stock_id' => $stock->id,
            'product_id' => $stock->product_id,
            'correlation_id' => $context->correlationId,
            'quantity_delta' => round($quantityDelta, 4),
            'before_values' => $before,
            'after_values' => $after,
            'reason' => $context->reason,
            'ip_address' => request()?->ip(),
        ]);
    }

    /**
     * What a source document still holds against each lot of a batch.
     *
     * Sums every movement this document has made against the batch, so a lot
     * consumed 30 and already restored 30 nets to zero and cannot be restored
     * again. Idempotency therefore comes from the ledger itself rather than a
     * flag that could disagree with it.
     *
     * @return array<int,float> purchase_detail_id => outstanding consumed quantity
     */
    public function outstandingAllocation(MovementContext $context, int $stockId): array
    {
        $rows = InventoryChangeLog::query()
            ->where('business_id', $context->businessId)
            ->where('source_type', $context->sourceType)
            ->where('source_id', $context->sourceId)
            ->where('stock_id', $stockId)
            ->whereNotNull('purchase_detail_id')
            ->orderBy('id')
            ->get(['purchase_detail_id', 'quantity_delta']);

        $net = [];

        foreach ($rows as $row) {
            $lot = (int) $row->purchase_detail_id;
            $net[$lot] = ($net[$lot] ?? 0.0) + (float) $row->quantity_delta;
        }

        // A negative net is stock still out against this document; that is what
        // there is to give back. Positive or zero means nothing is outstanding.
        return array_filter(
            array_map(static fn (float $delta) => round(-$delta, 4), $net),
            static fn (float $outstanding) => $outstanding > 0,
        );
    }

    /** @return array<string,mixed> */
    public static function purchaseLineSnapshot(PurchaseDetails $detail, Stock $stock): array
    {
        return [
            'purchase_detail_id' => $detail->id,
            'stock_id' => $stock->id,
            'product_id' => $detail->product_id,
            'batch_no' => $stock->batch_no,
            'quantity' => (float) $detail->quantities,
            'free_quantity' => (float) ($detail->free_quantity ?? 0),
            'conversion_value' => (float) ($detail->conversionValue ?? 1),
            'remaining_quantity' => (float) $detail->conversionQuantity,
            'purchase_price' => (float) $detail->productPurchasePrice,
            'sale_price' => (float) $detail->productSalePrice,
            'mfg_date' => self::dateValue($detail->mfg_date),
            'expire_date' => self::dateValue($detail->expire_date),
        ];
    }

    private static function dateValue(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return filled($value) ? (string) $value : null;
    }
}
