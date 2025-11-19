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
        Schema::create('targeting_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('strategy_id')
                ->constrained('strategies')
                ->cascadeOnDelete();
            
            // Geographic targeting
            $table->json('geo_locations')->nullable()->comment('Array of location objects with country, region, city, DMA, postal_code');
            $table->json('excluded_geo_locations')->nullable()->comment('Array of excluded locations');
            
            // Demographic targeting
            $table->integer('age_min')->default(18)->comment('Minimum age');
            $table->integer('age_max')->default(65)->comment('Maximum age');
            $table->json('genders')->nullable()->comment('Array of gender values: male, female, all');
            $table->json('languages')->nullable()->comment('Array of language codes (e.g., en, es, fr)');
            
            // Audience targeting
            $table->json('custom_audiences')->nullable()->comment('Array of custom audience IDs');
            $table->json('lookalike_audiences')->nullable()->comment('Array of lookalike audience IDs');
            $table->json('interests')->nullable()->comment('Array of interest/affinity audience IDs or keywords');
            $table->json('behaviors')->nullable()->comment('Array of behavioral targeting options');
            
            // Device and placement targeting
            $table->json('device_types')->nullable()->comment('Array: desktop, mobile, tablet');
            $table->json('placements')->nullable()->comment('Platform-specific placements (Facebook: feed, stories; Google: display network positions)');
            $table->json('excluded_placements')->nullable()->comment('Array of excluded placements');
            
            // Platform-specific
            $table->string('platform')->default('both')->comment('google, facebook, or both');
            $table->json('google_options')->nullable()->comment('Google-specific targeting options (keywords, topics, content labels)');
            $table->json('facebook_options')->nullable()->comment('Facebook-specific targeting options (connections, education, work)');
            
            $table->timestamps();
            
            // Indexes
            $table->index('strategy_id');
            $table->index('platform');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('targeting_configs');
    }
};
