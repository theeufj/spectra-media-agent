<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_boost_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('stripe_checkout_session_id')->nullable()->unique();
            $table->unsignedInteger('image_generations')->default(0);
            $table->unsignedInteger('video_generations')->default(0);
            $table->unsignedInteger('refinements')->default(0);
            $table->unsignedInteger('amount_cents')->default(0);
            $table->string('period', 7); // '2026-04'
            $table->string('status', 20)->default('pending'); // pending, completed, failed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_boost_purchases');
    }
};
