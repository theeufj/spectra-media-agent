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
        Schema::create('campaign_hourly_performance', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->onDelete('cascade');
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->date('date');
            $table->unsignedTinyInteger('hour'); // 0-23
            $table->unsignedTinyInteger('day_of_week'); // 0=Sunday, 6=Saturday
            $table->string('platform', 20)->default('google_ads'); // google_ads | facebook_ads
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->decimal('conversions', 10, 2)->default(0);
            $table->decimal('spend', 12, 2)->default(0);
            $table->decimal('conversion_value', 12, 2)->default(0);
            $table->decimal('ctr', 8, 6)->default(0);
            $table->decimal('roas', 8, 4)->default(0);
            $table->timestamps();

            $table->unique(['campaign_id', 'date', 'hour', 'platform'], 'campaign_hourly_unique');
            $table->index(['customer_id', 'date']);
            $table->index(['customer_id', 'hour', 'day_of_week']);
        });

        // Add average_order_value and agent_thresholds to customers table
        Schema::table('customers', function (Blueprint $table) {
            $table->decimal('average_order_value', 10, 2)->nullable()->after('currency_code');
            $table->json('agent_thresholds')->nullable()->after('average_order_value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_hourly_performance');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['average_order_value', 'agent_thresholds']);
        });
    }
};
