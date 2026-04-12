<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add LinkedIn to budget allocation
        Schema::table('platform_budget_allocations', function (Blueprint $table) {
            $table->decimal('linkedin_ads_pct', 5, 2)->default(0.00)->after('microsoft_ads_pct');
            $table->json('ai_reasoning')->nullable()->after('constraints');
            $table->timestamp('last_ai_analysis_at')->nullable()->after('ai_reasoning');
        });

        // Create personas table
        Schema::create('personas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->text('description');
            $table->json('demographics')->nullable();
            $table->json('psychographics')->nullable();
            $table->json('pain_points')->nullable();
            $table->text('messaging_angle')->nullable();
            $table->json('tone_adjustments')->nullable();
            $table->string('source', 20)->default('ai_generated'); // ai_generated, manual
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['customer_id', 'is_active']);
        });

        // Add persona_id to ad_copies
        Schema::table('ad_copies', function (Blueprint $table) {
            $table->foreignId('persona_id')->nullable()->after('strategy_id')
                ->constrained('personas')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ad_copies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('persona_id');
        });

        Schema::dropIfExists('personas');

        Schema::table('platform_budget_allocations', function (Blueprint $table) {
            $table->dropColumn(['linkedin_ads_pct', 'ai_reasoning', 'last_ai_analysis_at']);
        });
    }
};
