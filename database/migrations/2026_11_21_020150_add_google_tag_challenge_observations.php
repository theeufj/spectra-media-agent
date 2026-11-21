<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_outcome_challenges', function (Blueprint $table) {
            $table->char('browser_event_hash', 64)->nullable();
            $table->timestampTz('browser_tag_at')->nullable();
            $table->timestampTz('browser_received_at')->nullable();
            $table->decimal('browser_value', 12, 2)->nullable();
            $table->char('browser_currency', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('campaign_outcome_challenges', fn (Blueprint $table) => $table->dropColumn(['browser_event_hash', 'browser_tag_at', 'browser_received_at', 'browser_value', 'browser_currency']));
    }
};
