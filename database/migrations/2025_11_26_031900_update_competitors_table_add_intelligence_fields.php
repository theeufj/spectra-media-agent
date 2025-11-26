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
        Schema::table('competitors', function (Blueprint $table) {
            // Basic identification
            $table->string('domain')->nullable()->after('url');
            $table->string('name')->nullable()->after('domain');
            
            // AI Analysis fields
            $table->json('messaging_analysis')->nullable()->after('raw_content');
            $table->json('value_propositions')->nullable()->after('messaging_analysis');
            $table->json('keywords_detected')->nullable()->after('value_propositions');
            $table->json('pricing_info')->nullable()->after('keywords_detected');
            $table->json('ad_copy_samples')->nullable()->after('pricing_info');
            
            // Auction Insights fields
            $table->json('auction_insights')->nullable()->after('ad_copy_samples');
            $table->decimal('impression_share', 5, 2)->nullable()->after('auction_insights');
            $table->decimal('overlap_rate', 5, 2)->nullable()->after('impression_share');
            $table->decimal('position_above_rate', 5, 2)->nullable()->after('overlap_rate');
            
            // Tracking fields
            $table->timestamp('last_analyzed_at')->nullable()->after('position_above_rate');
            $table->string('discovery_source')->nullable()->after('last_analyzed_at'); // google_search, auction_insights, manual
            
            // Index for faster lookups
            $table->index('domain');
            $table->index('discovery_source');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('competitors', function (Blueprint $table) {
            $table->dropIndex(['domain']);
            $table->dropIndex(['discovery_source']);
            
            $table->dropColumn([
                'domain',
                'name',
                'messaging_analysis',
                'value_propositions',
                'keywords_detected',
                'pricing_info',
                'ad_copy_samples',
                'auction_insights',
                'impression_share',
                'overlap_rate',
                'position_above_rate',
                'last_analyzed_at',
                'discovery_source',
            ]);
        });
    }
};
