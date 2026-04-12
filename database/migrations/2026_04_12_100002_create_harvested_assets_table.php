<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('harvested_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->foreignId('customer_page_id')->nullable()->constrained('customer_pages')->onDelete('set null');
            $table->string('source_url', 1000);
            $table->string('source_page_url', 1000)->nullable();
            $table->string('s3_path')->nullable();
            $table->string('cloudfront_url')->nullable();
            $table->unsignedInteger('original_width')->nullable();
            $table->unsignedInteger('original_height')->nullable();
            $table->string('mime_type', 50)->nullable();
            $table->unsignedInteger('file_size')->nullable();
            $table->string('classification', 30)->nullable(); // product, lifestyle, team, logo, decorative, junk
            $table->json('classification_details')->nullable(); // AI classification metadata
            $table->string('status', 20)->default('pending'); // pending, classified, processed, failed
            $table->json('variants')->nullable(); // { "landscape": {...}, "square": {...}, "vertical": {...} }
            $table->string('bg_removed_s3_path')->nullable();
            $table->string('bg_removed_url')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['customer_id', 'classification']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('harvested_assets');
    }
};
