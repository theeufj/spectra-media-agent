<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Google Ads campaign id lived only on campaigns, so a campaign with
     * two Google strategies had the second executor "reuse" the first
     * strategy's platform campaign — attaching, say, Display ad groups to a
     * SEARCH campaign and silently discarding the second strategy's budget.
     * Ad group ids were already per-strategy; the campaign id now is too.
     */
    public function up(): void
    {
        Schema::table('strategies', function (Blueprint $table) {
            $table->string('google_ads_campaign_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('strategies', function (Blueprint $table) {
            $table->dropColumn('google_ads_campaign_id');
        });
    }
};
