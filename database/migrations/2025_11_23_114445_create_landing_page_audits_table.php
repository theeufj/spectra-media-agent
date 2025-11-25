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
        Schema::create('landing_page_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->string('url')->index();
            
            // Page Speed Metrics
            $table->integer('load_time_ms')->nullable()->comment('Page load time in milliseconds');
            $table->decimal('page_size_kb', 10, 2)->nullable()->comment('Total page size in KB');
            $table->integer('dom_elements')->nullable()->comment('Number of DOM elements');
            $table->json('core_web_vitals')->nullable()->comment('LCP, FID, CLS scores');
            
            // CTA Detection
            $table->boolean('has_above_fold_cta')->default(false);
            $table->json('cta_buttons')->nullable()->comment('List of detected CTA buttons');
            $table->integer('cta_count')->default(0);
            $table->text('primary_cta')->nullable()->comment('Main call-to-action text');
            
            // Message Match Analysis
            $table->integer('message_match_score')->nullable()->comment('0-100 score for message consistency');
            $table->text('message_analysis')->nullable()->comment('AI analysis of messaging');
            $table->json('keywords_found')->nullable()->comment('Keywords detected on page');
            
            // Audit Results
            $table->json('issues')->nullable()->comment('List of CRO issues found');
            $table->json('recommendations')->nullable()->comment('Actionable recommendations');
            $table->integer('overall_score')->nullable()->comment('Overall CRO health score 0-100');
            
            // Metadata
            $table->timestamp('audited_at')->nullable();
            $table->timestamps();
            
            // Indexes for performance
            $table->index(['customer_id', 'audited_at']);
            $table->index('overall_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('landing_page_audits');
    }
};
