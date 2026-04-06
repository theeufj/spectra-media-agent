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
        Schema::table('customers', function (Blueprint $table) {
            $table->string('microsoft_ads_customer_id')->nullable()->after('facebook_ads_account_id');
            $table->string('microsoft_ads_account_id')->nullable()->after('microsoft_ads_customer_id');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('microsoft_ads_campaign_id')->nullable()->after('facebook_ads_campaign_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['microsoft_ads_customer_id', 'microsoft_ads_account_id']);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('microsoft_ads_campaign_id');
        });
    }
};
