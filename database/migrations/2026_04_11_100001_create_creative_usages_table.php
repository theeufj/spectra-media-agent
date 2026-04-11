<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('period', 7); // '2026-04'
            $table->unsignedInteger('image_generations_used')->default(0);
            $table->unsignedInteger('video_generations_used')->default(0);
            $table->unsignedInteger('refinements_used')->default(0);
            $table->unsignedInteger('bonus_image_generations')->default(0);
            $table->unsignedInteger('bonus_video_generations')->default(0);
            $table->unsignedInteger('bonus_refinements')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_usages');
    }
};
