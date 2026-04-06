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
        Schema::create('cross_channel_rebalance_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->json('before_allocation');
            $table->json('after_allocation');
            $table->json('performance_snapshot');
            $table->json('recommendations');
            $table->string('trigger')->default('scheduled'); // scheduled, manual, agent
            $table->boolean('auto_executed')->default(false);
            $table->decimal('estimated_improvement_pct', 5, 2)->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cross_channel_rebalance_logs');
    }
};
