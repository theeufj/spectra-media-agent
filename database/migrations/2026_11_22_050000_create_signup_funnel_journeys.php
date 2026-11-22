<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signup_funnel_journeys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('source', 20);
            $table->string('campaign_key', 32)->nullable();
            $table->string('entry_surface', 24)->nullable();
            $table->timestampTz('started_at')->index();
            $table->index(['source', 'campaign_key', 'started_at']);
        });
        Schema::create('signup_funnel_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('journey_id')->constrained('signup_funnel_journeys')->cascadeOnDelete();
            $table->string('stage', 32);
            $table->string('reason', 24)->nullable();
            $table->timestampTz('occurred_at');
            $table->unique(['journey_id', 'stage']);
        });
        Schema::table('users', function (Blueprint $table) {
            // Temporary private association so a signed verification in another session
            // can finish the same registration. Cleared on verification or retention.
            $table->foreignUuid('signup_funnel_journey_id')->nullable()->unique()->constrained('signup_funnel_journeys')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('signup_funnel_journey_id');
        });
        Schema::dropIfExists('signup_funnel_stages');
        Schema::dropIfExists('signup_funnel_journeys');
    }
};
