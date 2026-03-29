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
        DB::statement("ALTER TABLE strategies MODIFY COLUMN campaign_type ENUM('display', 'search', 'video', 'shopping', 'app', 'demand_gen', 'local_services', 'performance_max') NOT NULL DEFAULT 'display'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE strategies MODIFY COLUMN campaign_type ENUM('display', 'search', 'video', 'shopping', 'app') NOT NULL DEFAULT 'display'");
    }
};
