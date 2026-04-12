<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'linkedin_ads_account_id')) {
                $table->string('linkedin_ads_account_id')->nullable()->after('microsoft_ads_account_id');
            }
            // Per-customer OAuth tokens are prohibited by the management account pattern.
            // All LinkedIn API calls use the platform-level refresh token from config/linkedinads.php.
        });

        Schema::table('campaigns', function (Blueprint $table) {
            if (!Schema::hasColumn('campaigns', 'linkedin_campaign_id')) {
                $table->string('linkedin_campaign_id')->nullable()->after('microsoft_ads_campaign_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['linkedin_ads_account_id']);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn('linkedin_campaign_id');
        });
    }
};
