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
        Schema::create('strategies', function (Blueprint $table) {
            $table->id();

            // Foreign key to the parent campaign.
            $table->foreignId('campaign_id')->constrained()->onDelete('cascade');

            // The platform for this specific strategy (e.g., 'Facebook', 'Google Ads').
            $table->string('platform');

            // Text columns to hold the LLM-generated creative strategies for this platform.
            $table->text('ad_copy_strategy');
            $table->text('imagery_strategy');
            $table->text('video_strategy');

            // A string column to track the status of this specific strategy.
            // e.g., 'pending_approval', 'approved', 'rejected'.
            $table->string('status')->default('pending_approval')->index();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('strategies');
    }
};
