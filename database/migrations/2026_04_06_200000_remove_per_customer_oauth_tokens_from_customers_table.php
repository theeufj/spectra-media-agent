<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove per-customer OAuth token columns.
     * All ad platforms now use management-account-level credentials.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Microsoft Ads — credentials are now at config/env level
            if (Schema::hasColumn('customers', 'microsoft_ads_access_token')) {
                $table->dropColumn('microsoft_ads_access_token');
            }
            if (Schema::hasColumn('customers', 'microsoft_ads_refresh_token')) {
                $table->dropColumn('microsoft_ads_refresh_token');
            }
            if (Schema::hasColumn('customers', 'microsoft_ads_token_expires_at')) {
                $table->dropColumn('microsoft_ads_token_expires_at');
            }

            // LinkedIn Ads — credentials are now at config/env level
            if (Schema::hasColumn('customers', 'linkedin_oauth_access_token')) {
                $table->dropColumn('linkedin_oauth_access_token');
            }
            if (Schema::hasColumn('customers', 'linkedin_oauth_refresh_token')) {
                $table->dropColumn('linkedin_oauth_refresh_token');
            }
            if (Schema::hasColumn('customers', 'linkedin_token_expires_at')) {
                $table->dropColumn('linkedin_token_expires_at');
            }
        });
    }

    /**
     * Re-add columns if rolled back.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->text('microsoft_ads_access_token')->nullable();
            $table->text('microsoft_ads_refresh_token')->nullable();
            $table->timestamp('microsoft_ads_token_expires_at')->nullable();
            $table->text('linkedin_oauth_access_token')->nullable();
            $table->text('linkedin_oauth_refresh_token')->nullable();
            $table->timestamp('linkedin_token_expires_at')->nullable();
        });
    }
};
