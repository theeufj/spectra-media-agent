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
            $table->text('microsoft_ads_access_token')->nullable()->after('microsoft_ads_account_id');
            $table->text('microsoft_ads_refresh_token')->nullable()->after('microsoft_ads_access_token');
            $table->timestamp('microsoft_ads_token_expires_at')->nullable()->after('microsoft_ads_refresh_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'microsoft_ads_access_token',
                'microsoft_ads_refresh_token',
                'microsoft_ads_token_expires_at',
            ]);
        });
    }
};
