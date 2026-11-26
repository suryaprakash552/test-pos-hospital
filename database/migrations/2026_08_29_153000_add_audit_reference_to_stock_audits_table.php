<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_audits', function (Blueprint $table) {
            $table->string('audit_reference', 40)
                ->nullable()
                ->after('user_id')
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('stock_audits', function (Blueprint $table) {
            $table->dropIndex(['audit_reference']);
            $table->dropColumn('audit_reference');
        });
    }
};
