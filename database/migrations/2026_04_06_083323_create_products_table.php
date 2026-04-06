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
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_feed_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('offer_id'); // Product ID in Merchant Center
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('link')->nullable();
            $table->string('image_link')->nullable();
            $table->decimal('price', 12, 2)->nullable();
            $table->decimal('sale_price', 12, 2)->nullable();
            $table->string('currency_code', 3)->default('USD');
            $table->string('availability')->default('in_stock'); // in_stock, out_of_stock, preorder
            $table->string('condition')->default('new'); // new, refurbished, used
            $table->string('brand')->nullable();
            $table->string('gtin')->nullable();
            $table->string('mpn')->nullable();
            $table->string('google_product_category')->nullable();
            $table->string('product_type')->nullable();
            $table->string('status')->default('pending'); // pending, approved, disapproved, expiring
            $table->json('disapproval_reasons')->nullable();
            $table->json('custom_attributes')->nullable();
            $table->float('impressions')->default(0);
            $table->float('clicks')->default(0);
            $table->float('cost')->default(0);
            $table->float('conversions')->default(0);
            $table->timestamps();

            $table->unique(['product_feed_id', 'offer_id']);
            $table->index(['customer_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
