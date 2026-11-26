<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'productManufacturer')) {
                $table->dropColumn('productManufacturer');
            }

            $table->foreignId('manufacturer_id')
                ->nullable()
                ->after('brand_id')
                ->constrained('manufacturers')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['manufacturer_id']);
            $table->dropColumn('manufacturer_id');

            $table->string('productManufacturer')->nullable();
        });
    }
};

