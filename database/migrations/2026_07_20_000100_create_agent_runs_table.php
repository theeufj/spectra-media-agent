<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per execution of a scheduled optimization job/agent. Gives every pass a
 * visible trace (ran / what it changed / errored / did nothing) so silent failures
 * and no-ops surface instead of disappearing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_runs', function (Blueprint $table) {
            $table->id();
            $table->string('job');                          // e.g. "RunSelfHealingChecks"
            $table->string('status')->default('completed');  // completed | no_op | partial | failed
            $table->unsignedInteger('actions_taken')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->unsignedInteger('warnings')->default(0);
            $table->string('scope')->nullable();             // e.g. "12 campaigns"
            $table->unsignedInteger('duration_ms')->nullable();
            $table->text('note')->nullable();                // short human summary / first error
            $table->json('details')->nullable();
            $table->timestamps();

            $table->index(['job', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_runs');
    }
};
