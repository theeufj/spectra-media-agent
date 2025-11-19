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
        Schema::table('strategies', function (Blueprint $table) {
            // Campaign type for explicit classification
            $table->enum('campaign_type', ['display', 'search', 'video', 'shopping', 'app'])
                ->default('display')
                ->after('platform');
            
            // Google Ads deployment tracking
            $table->string('google_ads_ad_group_id')->nullable()->after('status');
            
            // Facebook Ads deployment tracking
            $table->string('facebook_campaign_id')->nullable()->after('google_ads_ad_group_id');
            $table->string('facebook_adset_id')->nullable()->after('facebook_campaign_id');
            $table->string('facebook_ad_id')->nullable()->after('facebook_adset_id');
            $table->string('facebook_creative_id')->nullable()->after('facebook_ad_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('strategies', function (Blueprint $table) {
            $table->dropColumn([
                'campaign_type',
                'google_ads_ad_group_id',
                'facebook_campaign_id',
                'facebook_adset_id',
                'facebook_ad_id',
                'facebook_creative_id',
            ]);
        });
    }
};
