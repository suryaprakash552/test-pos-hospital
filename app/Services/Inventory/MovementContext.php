<?php

namespace App\Services\Inventory;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Who and what a lot movement is on behalf of.
 *
 * `BatchConsumption` needs to name the document it is moving stock for, and the
 * tenant and actor behind it, in order to write an event that means anything
 * later. It must not learn about HTTP to get them, so this carries the domain
 * facts and nothing else - no request, no controller, no response concerns.
 *
 * The correlation id ties every lot touched by one operation together: a sale
 * that draws from three lots writes three events sharing one id, which is what
 * makes the allocation readable back as a unit when the sale is reversed.
 */
final class MovementContext
{
    private function __construct(
        public readonly string $sourceType,
        public readonly int $sourceId,
        public readonly int $businessId,
        public readonly ?int $branchId,
        public readonly ?int $userId,
        public readonly string $operation,
        public readonly string $correlationId,
        public readonly ?string $reason = null,
    ) {
    }

    /**
     * Builds a context from the source document itself.
     *
     * Tenant and branch are read off the document rather than the session: an
     * import runs without a logged-in user, and a queued job runs without a
     * request at all, but the document always knows which shop it belongs to.
     */
    public static function forSource(
        Model $source,
        string $operation,
        ?string $correlationId = null,
        ?string $reason = null,
    ): self {
        return new self(
            sourceType: $source::class,
            sourceId: (int) $source->getKey(),
            businessId: (int) $source->business_id,
            branchId: isset($source->branch_id) ? (int) $source->branch_id : null,
            userId: isset($source->user_id) ? (int) $source->user_id : auth()->id(),
            operation: $operation,
            correlationId: $correlationId ?? (string) Str::uuid(),
            reason: $reason,
        );
    }

    /** The same operation against the same document, under a fresh correlation. */
    public function withCorrelation(string $correlationId): self
    {
        return new self(
            $this->sourceType,
            $this->sourceId,
            $this->businessId,
            $this->branchId,
            $this->userId,
            $this->operation,
            $correlationId,
            $this->reason,
        );
    }

    public function withOperation(string $operation): self
    {
        return new self(
            $this->sourceType,
            $this->sourceId,
            $this->businessId,
            $this->branchId,
            $this->userId,
            $operation,
            $this->correlationId,
            $this->reason,
        );
    }
}
