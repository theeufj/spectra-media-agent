<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->decimal('approved_daily_budget', 12, 2)->nullable();
            $table->decimal('billing_budget_multiplier', 5, 4)->default(1);
            $table->timestamp('last_budget_changed_at')->nullable();
            $table->timestamp('last_bidding_changed_at')->nullable();
        });
        DB::table('campaigns')->update(['approved_daily_budget' => DB::raw('daily_budget')]);
    }

    public function down(): void
    {
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn([
            'approved_daily_budget', 'billing_budget_multiplier', 'last_budget_changed_at', 'last_bidding_changed_at',
        ]));
    }
};
