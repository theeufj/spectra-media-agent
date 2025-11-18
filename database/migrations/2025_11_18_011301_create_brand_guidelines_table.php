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
        Schema::create('brand_guidelines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            
            // Brand Voice & Tone
            $table->json('brand_voice');
            $table->json('tone_attributes');
            $table->json('writing_patterns')->nullable();
            
            // Visual Identity
            $table->json('color_palette');
            $table->json('typography');
            $table->json('visual_style');
            
            // Messaging
            $table->json('messaging_themes');
            $table->json('unique_selling_propositions');
            
            // Audience & Positioning
            $table->json('target_audience');
            $table->json('competitor_differentiation')->nullable();
            $table->json('brand_personality');
            
            // Constraints
            $table->json('do_not_use')->nullable();
            
            // Metadata
            $table->integer('extraction_quality_score')->nullable();
            $table->timestamp('extracted_at');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
            
            $table->index('customer_id');
            $table->index('extracted_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('brand_guidelines');
    }
};
