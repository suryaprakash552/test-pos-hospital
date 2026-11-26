<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §17: the last date the books are closed to.
 *
 * The specification requires that a GST effective date cannot fall earlier than the
 * last closed accounting period. The system had no notion of a closed period at all,
 * so this is that notion in its smallest honest form: one date meaning "everything up
 * to and including this day has been filed, do not backdate into it".
 *
 * Deliberately not a periods table. A full accounting-period module — opening,
 * closing, reopening, per-period locks — is a much larger change than this clause
 * implies, and none of the rest of the system asks for one. A single high-water mark
 * answers the only question actually being asked.
 *
 * Null means no period has been closed, which is how every existing shop starts and
 * why this changes nothing for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->date('books_closed_upto')->nullable()->after('gst_effective_from');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('books_closed_upto');
        });
    }
};
