<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_outcome_events', function (Blueprint $table) {
            $table->string('ad_user_data', 10)->nullable();
            $table->string('ad_personalization', 10)->nullable();
            $table->timestampTz('consent_observed_at')->nullable();
            $table->text('provider_event_id')->nullable();
            $table->char('provider_event_id_hash', 64)->nullable();
            $table->char('retry_payload_hash', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_outcome_events', fn (Blueprint $table) => $table->dropColumn([
            'ad_user_data', 'ad_personalization', 'consent_observed_at', 'provider_event_id', 'provider_event_id_hash', 'retry_payload_hash',
        ]));
    }
};
