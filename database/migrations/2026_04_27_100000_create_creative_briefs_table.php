<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('platform');                          // google, facebook, microsoft, linkedin
            $table->string('brief_type');                        // fatigue_refresh | ab_winner | scheduled_refresh | cross_platform_winner
            $table->string('status')->default('pending');        // pending | in_review | actioned | dismissed
            $table->string('created_by_agent');
            $table->text('ai_brief');                            // AI-generated brief content
            $table->json('context')->nullable();                 // supporting data (winning headline, fatigue metrics, etc.)
            $table->timestamp('actioned_at')->nullable();
            $table->timestamps();

            $table->index(['campaign_id', 'status']);
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_briefs');
    }
};
