<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (! Schema::hasColumn('products', 'rack')) {
                $table->string('rack', 50)->nullable()->after('product_type');
            }

            if (! Schema::hasColumn('products', 'shelf')) {
                $table->string('shelf', 50)->nullable()->after('rack');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'shelf')) {
                $table->dropColumn('shelf');
            }

            if (Schema::hasColumn('products', 'rack')) {
                $table->dropColumn('rack');
            }
        });
    }
};
