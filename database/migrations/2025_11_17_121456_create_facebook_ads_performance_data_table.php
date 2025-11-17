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
        Schema::create('facebook_ads_performance_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->onDelete('cascade');
            $table->string('facebook_campaign_id')->index();
            $table->date('date')->index();
            $table->bigInteger('impressions')->default(0);
            $table->bigInteger('clicks')->default(0);
            $table->decimal('cost', 12, 2)->default(0);
            $table->decimal('conversions', 10, 2)->default(0);
            $table->bigInteger('reach')->nullable();
            $table->decimal('frequency', 8, 2)->nullable();
            $table->decimal('cpc', 10, 4)->nullable();
            $table->decimal('cpm', 10, 4)->nullable();
            $table->decimal('cpa', 10, 4)->nullable();
            $table->timestamps();

            $table->unique(['campaign_id', 'facebook_campaign_id', 'date']);
            $table->index(['campaign_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('facebook_ads_performance_data');
    }
};
