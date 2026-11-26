<?php

namespace App\Exceptions;

/**
 * Raised when a batch number is renamed after stock from it has moved.
 *
 * A batch number is how a shop, and a drug inspector, identify physical stock.
 * Once units have been sold from it, that identifier appears on invoices,
 * returns and inventory events that cannot be reissued - renaming it makes
 * every one of those records point at a batch that no longer exists by that
 * name.
 *
 * Carries the counts so the refusal can say what is holding it.
 */
class BatchIdentityProtected extends \RuntimeException
{
    /** @param array<string,int> $lineage */
    public function __construct(
        public readonly int $stockId,
        public readonly string $currentBatch,
        public readonly string $attemptedBatch,
        public readonly array $lineage,
    ) {
        parent::__construct('This batch number cannot be changed because stock from it has already moved.');
    }

    /** @return array<string,mixed> */
    public function toResponse(): array
    {
        return [
            'success' => false,
            'code' => 'BATCH_IDENTITY_PROTECTED',
            'message' => __('This batch number cannot be changed because stock from it has already been sold or moved. Correct it on the original purchase, or receive the corrected stock as a new batch.'),
            'stock_id' => $this->stockId,
            'current_batch' => $this->currentBatch,
            'attempted_batch' => $this->attemptedBatch,
            'lineage' => $this->lineage,
        ];
    }
}
