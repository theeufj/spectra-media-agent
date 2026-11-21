<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_outcome_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('strategy_id')->constrained()->cascadeOnDelete();
            $table->string('binding', 64);
            $table->string('previous_tag_at')->nullable();
            $table->string('send_to')->nullable();
            $table->timestampTz('started_at');
            $table->timestampTz('expires_at')->index();
            $table->string('source_key', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_outcome_challenges');
    }
};
