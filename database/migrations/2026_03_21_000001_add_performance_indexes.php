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
        Schema::table('campaigns', function (Blueprint $table) {
            $table->index(['customer_id', 'primary_status'], 'campaigns_customer_status_index');
            $table->index(['customer_id', 'created_at'], 'campaigns_customer_created_index');
        });

        Schema::table('strategies', function (Blueprint $table) {
            $table->index(['campaign_id', 'platform'], 'strategies_campaign_platform_index');
        });

        Schema::table('ad_copies', function (Blueprint $table) {
            $table->index(['strategy_id', 'platform'], 'ad_copies_strategy_platform_index');
        });

        Schema::table('google_ads_performance_data', function (Blueprint $table) {
            $table->index(['campaign_id', 'date'], 'google_ads_perf_campaign_date_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex('campaigns_customer_status_index');
            $table->dropIndex('campaigns_customer_created_index');
        });

        Schema::table('strategies', function (Blueprint $table) {
            $table->dropIndex('strategies_campaign_platform_index');
        });

        Schema::table('ad_copies', function (Blueprint $table) {
            $table->dropIndex('ad_copies_strategy_platform_index');
        });

        Schema::table('google_ads_performance_data', function (Blueprint $table) {
            $table->dropIndex('google_ads_perf_campaign_date_index');
        });
    }
};
