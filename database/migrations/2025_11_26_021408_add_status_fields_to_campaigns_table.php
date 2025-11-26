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
            $table->string('platform_status')->nullable()->after('google_ads_campaign_id');
            $table->string('primary_status')->nullable()->after('platform_status');
            $table->json('primary_status_reasons')->nullable()->after('primary_status');
            $table->timestamp('last_checked_at')->nullable()->after('primary_status_reasons');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['platform_status', 'primary_status', 'primary_status_reasons', 'last_checked_at']);
        });
    }
};
