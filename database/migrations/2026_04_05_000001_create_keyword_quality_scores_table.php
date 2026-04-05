<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('keyword_quality_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->string('campaign_google_id');
            $table->string('ad_group_resource_name');
            $table->string('criterion_resource_name');
            $table->string('keyword_text');
            $table->string('match_type')->nullable();
            $table->unsignedTinyInteger('quality_score')->nullable();
            $table->string('creative_quality_score')->nullable();
            $table->string('post_click_quality_score')->nullable();
            $table->string('search_predicted_ctr')->nullable();
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->decimal('conversions', 10, 2)->default(0);
            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->unsignedBigInteger('cpc_bid_micros')->nullable();
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->index(['customer_id', 'keyword_text', 'recorded_at'], 'kqs_trending_index');
            $table->index(['customer_id', 'recorded_at'], 'kqs_customer_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('keyword_quality_scores');
    }
};
