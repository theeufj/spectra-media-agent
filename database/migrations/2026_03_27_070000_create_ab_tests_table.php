<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ab_tests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->string('test_type'); // headline, description, image, audience
            $table->string('status')->default('running'); // running, significant, applied, stopped
            $table->json('variants'); // [{id, label, content, impressions, clicks, conversions, cost}]
            $table->timestamp('started_at');
            $table->timestamp('significance_reached_at')->nullable();
            $table->float('confidence_level')->nullable(); // 0.0 - 1.0
            $table->string('winning_variant_id')->nullable();
            $table->json('results')->nullable(); // {chi_squared, p_value, lift_pct, metrics_snapshot}
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ab_tests');
    }
};
