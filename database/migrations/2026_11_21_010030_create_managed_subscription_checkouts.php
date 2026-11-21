<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_subscription_checkouts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_customer_id');
            $table->string('price_id');
            $table->json('parameters');
            $table->string('stripe_session_id')->nullable()->unique();
            $table->string('status')->default('pending');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
        });
        // A Cashier payer has one default subscription. Only a terminal remote result frees either slot.
        foreach (['customer_id', 'user_id'] as $column) {
            DB::statement("create unique index managed_checkout_open_{$column} on managed_subscription_checkouts ({$column}) where closed_at is null");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('managed_subscription_checkouts');
    }
};
