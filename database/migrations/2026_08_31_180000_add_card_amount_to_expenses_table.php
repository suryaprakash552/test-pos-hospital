<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('expenses', 'card_amount')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->decimal('card_amount', 20, 2)->default(0)->after('upi_amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('expenses', 'card_amount')) {
            Schema::table('expenses', function (Blueprint $table) {
                $table->dropColumn('card_amount');
            });
        }
    }
};
