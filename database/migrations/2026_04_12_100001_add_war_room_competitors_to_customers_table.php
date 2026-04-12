<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->json('war_room_competitors')->nullable()->after('competitive_strategy_updated_at');
            $table->json('war_room_gap_analysis')->nullable()->after('war_room_competitors');
            $table->timestamp('war_room_gap_analysis_at')->nullable()->after('war_room_gap_analysis');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['war_room_competitors', 'war_room_gap_analysis', 'war_room_gap_analysis_at']);
        });
    }
};
