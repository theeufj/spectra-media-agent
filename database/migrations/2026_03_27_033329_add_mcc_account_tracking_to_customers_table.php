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
            // Store MCC (Manager) account ID separately from Standard account ID
            $table->string('google_ads_manager_customer_id')->nullable()->after('google_ads_customer_id');
            // Track whether the selected account is a manager account
            $table->boolean('google_ads_customer_is_manager')->default(false)->after('google_ads_manager_customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('google_ads_manager_customer_id');
            $table->dropColumn('google_ads_customer_is_manager');
        });
    }
};
