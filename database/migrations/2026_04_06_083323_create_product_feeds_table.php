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
        Schema::create('product_feeds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('merchant_id')->nullable(); // Google Merchant Center ID
            $table->string('feed_name');
            $table->string('source_type')->default('manual'); // manual, url, api, shopify, woocommerce
            $table->string('source_url')->nullable();
            $table->json('source_config')->nullable();
            $table->string('status')->default('pending'); // pending, active, processing, error
            $table->integer('total_products')->default(0);
            $table->integer('approved_products')->default(0);
            $table->integer('disapproved_products')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('sync_frequency')->default('daily'); // hourly, daily, weekly
            $table->text('last_error')->nullable();
            $table->json('feed_diagnostics')->nullable();
            $table->timestamps();

            $table->index('customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_feeds');
    }
};
