<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->integer('productUpperLimit')->default(0)->after('productStock');
            $table->integer('productLowerUnit')->default(0)->after('productUpperLimit');
            $table->decimal('conversionValue', 10, 2)->default(0)->after('productLowerUnit');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'productUpperLimit',
                'productLowerUnit',
                'conversionValue'
            ]);
        });
    }
};
