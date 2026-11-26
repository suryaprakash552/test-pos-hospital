<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records which client-supplied request keys have already been carried out.
 *
 * A till on a poor connection retries. Without this, the retry of a checkout that
 * actually succeeded creates a second sale and deducts stock twice - the customer
 * is billed once but the books show two. The key is the client's own stable id for
 * the attempt, so a retry is recognised as the same attempt rather than a new one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('business_id')->index();
            $table->unsignedBigInteger('user_id')->nullable();

            // The client's key for this attempt.
            $table->string('idempotency_key', 191);

            // Scoped so the same key on a different endpoint is a different attempt.
            $table->string('endpoint', 191);

            // Fingerprint of the payload. A key reused with a different body is a
            // client bug, not a retry, and must not silently return the old answer.
            $table->string('request_fingerprint', 64)->nullable();

            $table->string('status', 20)->default('processing');
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->longText('response_body')->nullable();

            $table->timestamps();

            // The concurrency control: two simultaneous retries race to insert, and
            // exactly one wins. The loser reads the winner's result.
            $table->unique(['business_id', 'endpoint', 'idempotency_key'], 'idempotency_keys_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
