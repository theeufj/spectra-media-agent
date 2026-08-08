<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Move the remaining money columns off `double precision`.
 *
 * Every other platform's performance table already stores money as `numeric`;
 * microsoft_ads_performance_data was the odd one out. That matters because
 * AdSpendBillingService::getMicrosoftAdsSpend() does SUM(cost) and the result is
 * charged to the customer's card — binary floating point accumulates error across
 * the sum, while the identical LinkedIn/Google paths are exact.
 *
 * Totals use decimal(12,2) to match facebook_ads_performance_data (the widest
 * existing definition). The derived per-unit rates (cpc, cpa) use decimal(10,4)
 * so sub-cent rates survive, again matching Facebook — rounding those to 2dp
 * would discard real precision on cheap clicks.
 *
 * Postgres casts double precision -> numeric in place, rounding to scale. These
 * values were never meaningfully more precise than cents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('microsoft_ads_performance_data', function (Blueprint $table) {
            $table->decimal('cost', 12, 2)->default(0)->change();
            $table->decimal('conversion_value', 12, 2)->default(0)->change();
            $table->decimal('cpc', 10, 4)->default(0)->change();
            $table->decimal('cpa', 10, 4)->default(0)->change();
        });

        Schema::table('keywords', function (Blueprint $table) {
            $table->decimal('cost', 12, 2)->nullable()->change();
        });

        // Sits alongside products.price / products.sale_price, already decimal(12,2).
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('cost', 12, 2)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('microsoft_ads_performance_data', function (Blueprint $table) {
            $table->float('cost')->default(0)->change();
            $table->float('conversion_value')->default(0)->change();
            $table->float('cpc')->default(0)->change();
            $table->float('cpa')->default(0)->change();
        });

        Schema::table('keywords', function (Blueprint $table) {
            $table->float('cost')->nullable()->change();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->float('cost')->default(0)->change();
        });
    }
};
