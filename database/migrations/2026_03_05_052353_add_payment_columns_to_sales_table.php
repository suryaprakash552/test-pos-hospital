<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {

            $table->double('cash_amount')->default(0)->after('paidAmount');
            $table->double('upi_amount')->default(0)->after('cash_amount');
            $table->double('card_amount')->default(0)->after('upi_amount');
            $table->date('follow_up_date')->nullable()->after('card_amount');

        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {

            $table->dropColumn([
                'cash_amount',
                'upi_amount',
                'card_amount',
                'follow_up_date'
            ]);

        });
    }
};
