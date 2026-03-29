<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Expand the campaign_type enum to include demand_gen, local_services, and performance_max.
     */
    public function up(): void
    {
        // PostgreSQL: change column to varchar, then recreate the check constraint
        DB::statement("ALTER TABLE strategies ALTER COLUMN campaign_type TYPE VARCHAR(255)");
        DB::statement("ALTER TABLE strategies DROP CONSTRAINT IF EXISTS strategies_campaign_type_check");
        DB::statement("ALTER TABLE strategies ADD CONSTRAINT strategies_campaign_type_check CHECK (campaign_type IN ('display', 'search', 'video', 'shopping', 'app', 'demand_gen', 'local_services', 'performance_max'))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE strategies DROP CONSTRAINT IF EXISTS strategies_campaign_type_check");
        DB::statement("ALTER TABLE strategies ADD CONSTRAINT strategies_campaign_type_check CHECK (campaign_type IN ('display', 'search', 'video', 'shopping', 'app'))");
    }
};
