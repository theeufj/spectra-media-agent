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
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // Core Campaign Brief
            $table->string('name'); // e.g., "Summer 2025 Dress Sale"
            $table->text('reason'); // The "why" - e.g., "Clear out summer inventory before fall."
            $table->text('goals'); // High-level objectives - e.g., "Increase online sales and website traffic."

            // Audience & Messaging
            $table->text('target_market'); // e.g., "Women aged 25-40 in coastal cities..."
            $table->string('voice'); // e.g., "Fun, vibrant, and youthful"

            // Constraints & Specifics
            $table->decimal('total_budget', 10, 2); // Total budget for the campaign.
            $table->date('start_date');
            $table->date('end_date');
            $table->string('primary_kpi'); // e.g., "4x ROAS" or "$25 CPA"
            $table->text('product_focus')->nullable(); // The specific product/service being promoted.
            $table->text('exclusions')->nullable(); // What to avoid.

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaigns');
    }
};
