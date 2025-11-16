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
            $table->decimal('max_daily_spend', 8, 2)->nullable()->after('status');
            $table->decimal('total_spend_limit', 10, 2)->nullable()->after('max_daily_spend');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['max_daily_spend', 'total_spend_limit']);
        });
    }
};
