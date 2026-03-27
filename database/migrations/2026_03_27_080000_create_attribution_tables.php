<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attribution_touchpoints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('visitor_id', 64)->index();
            $table->string('utm_source')->nullable();
            $table->string('utm_medium')->nullable();
            $table->string('utm_campaign')->nullable();
            $table->string('utm_content')->nullable();
            $table->string('utm_term')->nullable();
            $table->text('page_url')->nullable();
            $table->text('referrer')->nullable();
            $table->timestamp('touched_at');
            $table->timestamps();

            $table->index(['customer_id', 'visitor_id']);
            $table->index(['customer_id', 'touched_at']);
        });

        Schema::create('attribution_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('visitor_id', 64)->index();
            $table->string('conversion_type')->default('purchase');
            $table->decimal('conversion_value', 12, 2)->default(0);
            $table->json('touchpoints')->nullable(); // ordered journey
            $table->json('attributed_to')->nullable(); // model results: {last_click: {...}, linear: {...}, ...}
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attribution_conversions');
        Schema::dropIfExists('attribution_touchpoints');
    }
};
