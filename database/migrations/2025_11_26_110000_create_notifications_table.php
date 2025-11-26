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
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('customer_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('type'); // e.g., 'campaign.strategy_ready', 'deployment.completed', etc.
            $table->string('title');
            $table->text('message');
            $table->string('icon')->nullable(); // Emoji or icon class
            $table->string('action_url')->nullable();
            $table->string('action_text')->nullable();
            $table->json('data')->nullable(); // Additional contextual data
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            
            // Indexes for common queries
            $table->index(['user_id', 'read_at']);
            $table->index(['user_id', 'created_at']);
            $table->index(['customer_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
