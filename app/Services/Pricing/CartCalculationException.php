<?php

namespace App\Services\Pricing;

use RuntimeException;

/**
 * A cart the server refuses to price: an impossible quantity, a discount beyond
 * what is allowed, a batch belonging to another tenant.
 *
 * Carries a machine-readable reason so the till can react to the specific fault
 * rather than parsing prose, and an HTTP status so the controller does not have
 * to map it.
 */
class CartCalculationException extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly int $status = 422,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function reject(string $reason, string $message, array $context = [], int $status = 422): self
    {
        return new self($message, $reason, $status, $context);
    }
}
