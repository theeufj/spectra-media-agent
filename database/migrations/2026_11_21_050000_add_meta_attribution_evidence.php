<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facebook_attribution_windows', function (Blueprint $table) {
            $table->foreignId('strategy_id')->primary()->constrained()->cascadeOnDelete();
            $table->uuid('window_id');
            $table->char('binding', 64);
            $table->char('provider_signature', 64);
            $table->string('account_id');
            $table->string('campaign_id');
            $table->string('adset_id');
            $table->string('pixel_id');
            $table->string('goal', 20);
            $table->char('currency', 3);
            $table->string('timezone');
            $table->date('first_full_day');
            $table->timestampTz('verified_at')->nullable();
        });

        Schema::table('facebook_ads_performance_data', function (Blueprint $table) {
            $table->string('facebook_account_id')->nullable();
            $table->char('currency', 3)->nullable();
            $table->uuid('attribution_window')->nullable()->index();
            $table->decimal('attributed_conversions', 18, 6)->nullable();
            $table->decimal('attributed_value', 18, 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('facebook_ads_performance_data', fn (Blueprint $table) => $table->dropColumn(
            ['facebook_account_id', 'currency', 'attribution_window', 'attributed_conversions', 'attributed_value']
        ));
        Schema::dropIfExists('facebook_attribution_windows');
    }
};
