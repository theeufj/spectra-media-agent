<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_order_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->char('order_key', 64);
            $table->char('currency', 3);
            $table->unsignedBigInteger('gross_cents');
            $table->unsignedBigInteger('refunded_cents')->default(0);
            $table->timestampTz('occurred_at');
            $table->unique(['customer_id', 'order_key']);
            $table->index(['customer_id', 'occurred_at']);
        });
        Schema::create('merchant_refund_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('merchant_order_receipts')->cascadeOnDelete();
            $table->char('refund_key', 64);
            $table->unsignedBigInteger('amount_cents');
            $table->timestampTz('occurred_at');
            $table->unique(['customer_id', 'refund_key']);
            $table->index(['customer_id', 'occurred_at']);
        });
        // Before order_id was accepted, signed purchase source_id was the documented stable order ID.
        // Conflicting historical values have no trustworthy canonical amount.
        DB::table('merchant_order_receipts')->insertUsing(['customer_id', 'order_key', 'currency', 'gross_cents', 'occurred_at'],
            DB::table('campaign_outcome_events as e')->join('strategies as s', 's.id', '=', 'e.strategy_id')->join('campaigns as c', 'c.id', '=', 's.campaign_id')
                ->where('e.goal', 'purchase')->where('e.mode', 'live')->where('e.source', 'signed_website')
                ->whereNotNull('e.currency')->where('e.amount', '>', 0)
                ->groupBy('c.customer_id', 'e.source_key')->havingRaw('COUNT(DISTINCT e.amount) = 1 AND COUNT(DISTINCT e.currency) = 1')
                ->selectRaw('c.customer_id, e.source_key, MIN(e.currency), ROUND(MIN(e.amount) * 100), MIN(e.occurred_at)'));
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_refund_receipts');
        Schema::dropIfExists('merchant_order_receipts');
    }
};
