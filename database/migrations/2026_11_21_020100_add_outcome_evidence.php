<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->boolean('ad_measurement_consent')->nullable());
        Schema::table('strategies', function (Blueprint $table) {
            $table->text('conversion_webhook_secret')->nullable();
        });
        Schema::create('campaign_outcome_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_id')->constrained()->cascadeOnDelete();
            $table->string('binding', 64);
            $table->string('source_key', 64);
            $table->string('goal', 20);
            $table->string('mode', 12);
            $table->string('source', 32);
            $table->timestampTz('occurred_at');
            $table->decimal('amount', 12, 2)->nullable();
            $table->char('currency', 3)->nullable();
            $table->string('delivery_status', 25)->default('pending');
            $table->string('provider_receipt')->nullable();
            $table->text('payload')->nullable();
            $table->timestampsTz();
            $table->unique(['strategy_id', 'mode', 'source_key']);
            $table->index(['strategy_id', 'binding', 'mode']);
        });
        Schema::table('spectra_conversion_events', function (Blueprint $table) {
            $table->json('meta_delivery')->nullable();
        });
        Schema::table('offline_conversions', function (Blueprint $table) {
            $table->string('source_key', 64)->nullable()->unique();
            $table->json('delivery_bindings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('offline_conversions', fn (Blueprint $table) => $table->dropColumn(['source_key', 'delivery_bindings']));
        Schema::table('spectra_conversion_events', fn (Blueprint $table) => $table->dropColumn('meta_delivery'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('ad_measurement_consent'));
        Schema::dropIfExists('campaign_outcome_events');
        Schema::table('strategies', fn (Blueprint $table) => $table->dropColumn('conversion_webhook_secret'));
    }
};
