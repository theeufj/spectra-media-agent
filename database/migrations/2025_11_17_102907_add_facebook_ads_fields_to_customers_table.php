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
            $table->string('facebook_ads_account_id')->nullable()->unique()->after('google_ads_customer_id');
            $table->text('facebook_ads_access_token')->nullable()->after('facebook_ads_account_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['facebook_ads_account_id', 'facebook_ads_access_token']);
        });
    }
};
