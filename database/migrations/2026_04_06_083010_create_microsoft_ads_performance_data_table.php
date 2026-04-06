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
        Schema::create('microsoft_ads_performance_data', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->integer('impressions')->default(0);
            $table->integer('clicks')->default(0);
            $table->float('cost')->default(0);
            $table->float('conversions')->default(0);
            $table->float('conversion_value')->default(0);
            $table->float('ctr')->default(0);
            $table->float('cpc')->default(0);
            $table->float('cpa')->default(0);
            $table->timestamps();

            $table->unique(['campaign_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('microsoft_ads_performance_data');
    }
};
