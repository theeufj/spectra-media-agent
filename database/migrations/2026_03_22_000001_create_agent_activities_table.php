<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->onDelete('cascade');
            $table->foreignId('campaign_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('agent_type');          // e.g. 'optimization', 'budget', 'creative', 'maintenance', 'monitoring'
            $table->string('action');              // e.g. 'paused_keyword', 'adjusted_budget', 'generated_ad_copy'
            $table->text('description');
            $table->json('details')->nullable();   // structured data about what changed
            $table->string('status')->default('completed'); // 'completed', 'failed', 'in_progress'
            $table->timestamps();

            $table->index(['customer_id', 'created_at']);
            $table->index(['campaign_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_activities');
    }
};
