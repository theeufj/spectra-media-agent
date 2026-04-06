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
        Schema::create('keywords', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('ad_group_resource_name')->nullable();
            $table->string('criterion_resource_name')->nullable();
            $table->string('keyword_text');
            $table->string('match_type')->default('BROAD');
            $table->string('status')->default('active');
            $table->string('source')->default('manual');
            $table->bigInteger('bid_micros')->nullable();
            $table->integer('quality_score')->nullable();
            $table->integer('avg_monthly_searches')->nullable();
            $table->integer('competition_index')->nullable();
            $table->bigInteger('estimated_cpc_micros')->nullable();
            $table->float('ctr')->nullable();
            $table->float('conversions')->nullable();
            $table->float('cost')->nullable();
            $table->string('intent')->nullable();
            $table->string('cluster')->nullable();
            $table->string('funnel_stage')->nullable();
            $table->unsignedBigInteger('added_by')->nullable();
            $table->string('added_by_agent')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['campaign_id', 'status']);
            $table->index(['keyword_text']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('keywords');
    }
};
