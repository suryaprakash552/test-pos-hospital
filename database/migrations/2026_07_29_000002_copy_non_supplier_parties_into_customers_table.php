<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sales were re-pointed from `parties` to `customers` (2026_03_24_071053 renamed
 * the column, 2026_04_13_111913 added the FK), but the customer rows themselves
 * were never copied across. That left `customers` empty while every real customer
 * stayed in `parties`, so the API returned an empty customer list and the Business
 * UI offered parties the FK would reject.
 *
 * This copies every non-supplier party into `customers`, preserving ids so any
 * surviving reference to a party id still resolves. Suppliers stay in `parties`,
 * which is still the correct table for purchases (`purchases.party_id`).
 *
 * Idempotent: rows already present in `customers` are skipped.
 */
return new class extends Migration
{
    /**
     * `customers` is a strict subset of `parties`; these are exactly its columns
     * apart from the supplier/shipper-specific ones.
     */
    private array $columns = [
        'id', 'name', 'business_id', 'email', 'type', 'phone', 'relationship',
        'due', 'opening_balance_type', 'opening_balance', 'wallet',
        'loyalty_points', 'credit_limit', 'address', 'image', 'status', 'meta',
        'shipping_address', 'billing_address', 'created_at', 'updated_at',
    ];

    public function up(): void
    {
        $list = implode(', ', array_map(static fn ($c) => "`$c`", $this->columns));

        DB::statement("
            INSERT INTO customers ($list)
            SELECT $list
              FROM parties
             WHERE parties.type <> 'supplier'
               AND NOT EXISTS (
                   SELECT 1 FROM customers WHERE customers.id = parties.id
               )
        ");
    }

    public function down(): void
    {
        // Intentionally not reversed: the rows are now the canonical customer
        // records and sales reference them by id.
    }
};
