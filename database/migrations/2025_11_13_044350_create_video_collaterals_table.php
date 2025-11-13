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
        Schema::create('video_collaterals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->onDelete('cascade');
            $table->foreignId('strategy_id')->constrained()->onDelete('cascade');
            $table->string('platform');
            $table->string('status')->default('pending'); // e.g., pending, processing, completed, failed
            $table->string('operation_name')->nullable(); // From the Gemini API
            $table->string('s3_path')->nullable();
            $table->string('cloudfront_url')->nullable();
            $table->foreignId('parent_id')->nullable()->constrained('video_collaterals')->onDelete('set null');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('video_collaterals');
    }
};
