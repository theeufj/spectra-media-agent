<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Negative keyword lists are created customer-scoped (no campaign) via
 * KeywordController::storeNegativeList, but the original table (2025_11_16 create)
 * declared campaign_id NOT NULL, so every such insert failed with a NOT NULL
 * violation. Drop the constraint. (DATA-4)
 *
 * Fresh installs create the table without a campaign_id column at all
 * (2026_04_06 create), so this is guarded by hasColumn.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('negative_keyword_lists', 'campaign_id')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE negative_keyword_lists ALTER COLUMN campaign_id DROP NOT NULL');
        } else {
            Schema::table('negative_keyword_lists', function ($table) {
                $table->unsignedBigInteger('campaign_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Not reversed — campaign-less lists would violate a re-added NOT NULL.
    }
};
