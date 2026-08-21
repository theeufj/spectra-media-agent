<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a campaign be generated for someone before they have asked for one.
 *
 * After the sitemap crawl the platform already knows the customer's brand
 * voice, audience, messaging and every page they sell from — enough to build a
 * first campaign without making them fill in a nine-step wizard. In production
 * 16 accounts pasted their website and were crawled; none went on to build
 * anything.
 *
 * `budget_confirmed_at` is the important one, and it is a safety mechanism
 * rather than a flag. The proposed daily budget comes from a language model,
 * and daily budget is not a display value: deployment charges seven days of it
 * up front. So an unconfirmed number must never reach a card. The column is
 * null until a human has looked at the figure and accepted it, and deployment
 * refuses without it — which is what makes the suggestion a placeholder in
 * fact, not just in the UI.
 *
 * `budget_rationale` exists so the number is arguable. A figure with no
 * reasoning is one a customer can only accept or distrust.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->timestamp('auto_generated_at')->nullable()->after('created_at');
            $table->timestamp('budget_confirmed_at')->nullable()->after('daily_budget');
            $table->text('budget_rationale')->nullable()->after('budget_confirmed_at');

            // "Which auto-generated campaigns is nobody reviewing?" — the
            // question this feature will be judged on.
            $table->index(['auto_generated_at', 'budget_confirmed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropIndex(['auto_generated_at', 'budget_confirmed_at']);
            $table->dropColumn(['auto_generated_at', 'budget_confirmed_at', 'budget_rationale']);
        });
    }
};
