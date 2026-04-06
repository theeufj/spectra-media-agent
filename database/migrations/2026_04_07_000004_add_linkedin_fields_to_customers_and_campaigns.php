<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('linkedin_ads_account_id')->nullable()->after('microsoft_ads_account_id');
            $table->text('linkedin_oauth_access_token')->nullable()->after('linkedin_ads_account_id');
            $table->text('linkedin_oauth_refresh_token')->nullable()->after('linkedin_oauth_access_token');
            $table->timestamp('linkedin_token_expires_at')->nullable()->after('linkedin_oauth_refresh_token');
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('linkedin_campaign_id')->nullable()->after('microsoft_ads_campaign_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'linkedin_ads_account_id',
                'linkedin_oauth_access_token',
                'linkedin_oauth_refresh_token',
                'linkedin_token_expires_at',
            ]);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('linkedin_campaign_id');
        });
    }
};
