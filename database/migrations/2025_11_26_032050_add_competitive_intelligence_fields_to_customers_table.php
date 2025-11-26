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
        Schema::table('customers', function (Blueprint $table) {
            $table->string('industry')->nullable()->after('description');
            $table->json('competitive_strategy')->nullable()->after('industry');
            $table->timestamp('competitive_strategy_updated_at')->nullable()->after('competitive_strategy');
            $table->timestamp('competitor_analysis_at')->nullable()->after('competitive_strategy_updated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'industry',
                'competitive_strategy',
                'competitive_strategy_updated_at',
                'competitor_analysis_at',
            ]);
        });
    }
};
