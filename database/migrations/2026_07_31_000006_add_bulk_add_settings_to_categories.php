<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a category be added to the cart in one tap.
 *
 * Some trades sell in fixed assortments — a wholesaler taking an order for "all the
 * 500ml lines", a canteen adding the day's menu — and adding twenty items one at a
 * time is the slowest part of that sale.
 *
 * Three settings, all admin-controlled:
 *
 *   * `bulk_add_enabled`  — off by default, so no existing category changes behaviour
 *                           and nobody adds twenty items by mistapping a category
 *   * `default_quantity`  — how many of each line to add (20 is the documented default)
 *   * `min_order_quantity`— the floor a line may not go below once added
 *
 * `default_quantity` is deliberately not nullable-with-a-magic-fallback: a bulk add
 * that silently used 1 would look broken, and one that used 20 without being asked
 * would be worse. It is defaulted in the column so the value is always explicit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // Opt-in: an existing category behaves exactly as before.
            $table->boolean('bulk_add_enabled')->default(false)->after('status');

            // The documented default quantity per line.
            $table->unsignedInteger('default_quantity')->default(20)->after('bulk_add_enabled');

            // Floor for a line from this category. 0 means no floor.
            $table->unsignedInteger('min_order_quantity')->default(0)->after('default_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn([
                'bulk_add_enabled',
                'default_quantity',
                'min_order_quantity',
            ]);
        });
    }
};
