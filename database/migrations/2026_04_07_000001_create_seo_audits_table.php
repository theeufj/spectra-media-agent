<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->string('url', 500);
            $table->integer('score')->nullable();
            $table->json('issues')->nullable();
            $table->json('recommendations')->nullable();
            $table->json('meta_analysis')->nullable();
            $table->json('heading_analysis')->nullable();
            $table->json('image_analysis')->nullable();
            $table->json('link_analysis')->nullable();
            $table->json('schema_analysis')->nullable();
            $table->json('security_analysis')->nullable();
            $table->json('performance_analysis')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_audits');
    }
};
