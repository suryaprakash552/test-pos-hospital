<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctors', function (Blueprint $table) {

            $table->id();

            $table->unsignedBigInteger('business_id');

            $table->string('name');

            $table->string('qualification')->nullable();

            $table->string('specialization')->nullable();

            $table->string('register_no')->nullable();

            $table->string('mci_no')->nullable();

            $table->text('location')->nullable();

            $table->timestamps();

            $table->foreign('business_id')
                ->references('id')
                ->on('businesses')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctors');
    }
};
