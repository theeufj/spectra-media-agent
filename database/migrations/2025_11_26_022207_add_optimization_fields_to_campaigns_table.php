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
            $table->json('latest_optimization_analysis')->nullable()->after('last_checked_at');
            $table->timestamp('last_optimized_at')->nullable()->after('latest_optimization_analysis');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['latest_optimization_analysis', 'last_optimized_at']);
        });
    }
};
