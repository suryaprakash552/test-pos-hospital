<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Development databases have occasionally received this column during
        // hot-fixes before the migration reached source control.  A migration
        // must remain safe in that state: one existing column must not block
        // the rest of a production release.
        if (! Schema::hasColumn('sale_return_details', 'return_card')) {
            Schema::table('sale_return_details', function (Blueprint $table) {
                $table->double('return_card')->default(0)->after('return_upi');
            });
        }

        if (! Schema::hasColumn('purchase_return_details', 'return_card')) {
            Schema::table('purchase_return_details', function (Blueprint $table) {
                $table->double('return_card')->default(0)->after('return_upi');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('sale_return_details', 'return_card')) {
            Schema::table('sale_return_details', function (Blueprint $table) {
                $table->dropColumn('return_card');
            });
        }

        if (Schema::hasColumn('purchase_return_details', 'return_card')) {
            Schema::table('purchase_return_details', function (Blueprint $table) {
                $table->dropColumn('return_card');
            });
        }
    }
};
