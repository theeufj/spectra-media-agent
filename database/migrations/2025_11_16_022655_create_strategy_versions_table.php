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
        Schema::create('strategy_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_id')->constrained()->onDelete('cascade');
            $table->foreignId('campaign_id')->constrained()->onDelete('cascade');
            $table->string('platform');
            $table->text('ad_copy_strategy');
            $table->text('imagery_strategy');
            $table->text('video_strategy');
            $table->json('bidding_strategy')->nullable();
            $table->unsignedInteger('cpa_target')->nullable();
            $table->decimal('revenue_cpa_multiple', 5, 2)->default(1.0);
            $table->timestamp('versioned_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('strategy_versions');
    }
};
