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
        Schema::table('strategies', function (Blueprint $table) {
            // Execution tracking fields for AI-powered execution agents
            $table->json('execution_plan')->nullable()->after('bidding_strategy')
                ->comment('AI-generated execution plan (steps, budget allocation, etc.)');
            
            $table->json('execution_result')->nullable()->after('execution_plan')
                ->comment('Execution result with success status, platform IDs, errors, warnings');
            
            $table->float('execution_time', 8, 3)->nullable()->after('execution_result')
                ->comment('Execution time in seconds');
            
            $table->json('execution_errors')->nullable()->after('execution_time')
                ->comment('Array of execution errors if deployment failed');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('strategies', function (Blueprint $table) {
            $table->dropColumn([
                'execution_plan',
                'execution_result',
                'execution_time',
                'execution_errors',
            ]);
        });
    }
};
