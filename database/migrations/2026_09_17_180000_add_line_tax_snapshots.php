<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Null means legacy/unknown. Never invent old rates from today's product.
        foreach (['sale_details', 'purchase_details'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->decimal('tax_rate_snapshot', 8, 4)->nullable();
                $table->boolean('tax_inclusive_snapshot')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['sale_details', 'purchase_details'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropColumn(['tax_rate_snapshot', 'tax_inclusive_snapshot']);
            });
        }
    }
};
