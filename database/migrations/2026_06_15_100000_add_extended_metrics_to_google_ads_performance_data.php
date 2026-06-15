<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_ads_performance_data', function (Blueprint $table) {
            $table->decimal('search_impression_share', 8, 4)->nullable()->after('cpa');
            $table->decimal('search_top_impression_share', 8, 4)->nullable()->after('search_impression_share');
            $table->decimal('view_through_conversions', 12, 2)->default(0)->after('search_top_impression_share');
            $table->decimal('all_conversions', 12, 2)->default(0)->after('view_through_conversions');
            $table->decimal('interaction_rate', 8, 4)->nullable()->after('all_conversions');
        });
    }

    public function down(): void
    {
        Schema::table('google_ads_performance_data', function (Blueprint $table) {
            $table->dropColumn([
                'search_impression_share',
                'search_top_impression_share',
                'view_through_conversions',
                'all_conversions',
                'interaction_rate',
            ]);
        });
    }
};
