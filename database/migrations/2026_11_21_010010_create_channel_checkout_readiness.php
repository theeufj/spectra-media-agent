<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_checkout_readiness', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('channels');
            $table->boolean('ready');
            $table->timestamps();
            $table->unique(['customer_id', 'user_id', 'plan_id']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('channel_checkout_readiness');
    }
};
