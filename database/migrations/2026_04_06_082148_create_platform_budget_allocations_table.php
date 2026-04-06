<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('platform_budget_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->decimal('total_monthly_budget', 12, 2);
            $table->decimal('google_ads_pct', 5, 2)->default(100.00);
            $table->decimal('facebook_ads_pct', 5, 2)->default(0.00);
            $table->decimal('microsoft_ads_pct', 5, 2)->default(0.00);
            $table->json('per_campaign_splits')->nullable();
            $table->string('strategy')->default('performance'); // performance, equal, manual, roas_target
            $table->decimal('target_roas', 8, 2)->nullable();
            $table->decimal('target_cpa', 10, 2)->nullable();
            $table->boolean('auto_rebalance')->default(false);
            $table->string('rebalance_frequency')->default('weekly'); // daily, weekly, monthly
            $table->timestamp('last_rebalanced_at')->nullable();
            $table->json('constraints')->nullable(); // min/max per platform
            $table->timestamps();

            $table->unique('customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('platform_budget_allocations');
    }
};
