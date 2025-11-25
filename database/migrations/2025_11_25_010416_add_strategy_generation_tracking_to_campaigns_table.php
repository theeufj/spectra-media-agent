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
        Schema::table('campaigns', function (Blueprint $table) {
            $table->timestamp('strategy_generation_started_at')->nullable()->after('updated_at');
            $table->timestamp('strategy_generation_completed_at')->nullable()->after('strategy_generation_started_at');
            $table->text('strategy_generation_error')->nullable()->after('strategy_generation_completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['strategy_generation_started_at', 'strategy_generation_completed_at', 'strategy_generation_error']);
        });
    }
};
