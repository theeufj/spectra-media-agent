<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per customer per billing day — the daily run's idempotency claim.
 *
 * This replaces a Cache::add() marker. The cache was the only thing standing
 * between a customer and being billed twice in a day, and a Redis flush,
 * failover, or eviction under an allkeys-lru policy removes it silently. A
 * unique index cannot be evicted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_spend_billing_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->date('billing_date');
            $table->timestamps();

            $table->unique(['customer_id', 'billing_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_spend_billing_runs');
    }
};
