<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_ads_performance_data', function (Blueprint $table) {
            $table->decimal('conversion_value', 12, 2)->default(0)->after('conversions');
            $table->decimal('ctr', 8, 2)->default(0)->after('conversion_value');
            $table->decimal('cpc', 10, 2)->default(0)->after('ctr');
            $table->decimal('cpa', 10, 2)->default(0)->after('cpc');

            // Add unique constraint to prevent duplicates
            $table->unique(['campaign_id', 'date'], 'google_perf_campaign_date_unique');
        });
    }

    public function down(): void
    {
        Schema::table('google_ads_performance_data', function (Blueprint $table) {
            $table->dropUnique('google_perf_campaign_date_unique');
            $table->dropColumn(['conversion_value', 'ctr', 'cpc', 'cpa']);
        });
    }
};
