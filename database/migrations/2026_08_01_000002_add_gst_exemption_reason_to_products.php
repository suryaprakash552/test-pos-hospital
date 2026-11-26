<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §9: why a product is exempt from GST.
 *
 * An out-of-scope item still appears on a return, and the reason it carries no tax has
 * to be stated — "exempt supply", "nil rated", a notification number. Without a field
 * for it the reason lives in someone's memory, and the return is filed on a guess.
 *
 * Free text rather than a fixed list: the accepted reasons change with notifications,
 * and a stale list would push users into picking whichever option was least wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('gst_exemption_reason', 255)->nullable()->after('cess_rate');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('gst_exemption_reason');
        });
    }
};
