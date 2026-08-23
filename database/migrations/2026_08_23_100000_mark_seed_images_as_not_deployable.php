<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed images are AI inspiration, never ad creative. They were created
     * with should_deploy left at its default (true), which was harmless while
     * deploy queries filtered on strategy_id — seeds have strategy_id null and
     * were unreachable. Deploy queries now adopt campaign-level rows, so the
     * flag has to say what was always meant.
     */
    public function up(): void
    {
        DB::table('image_collaterals')
            ->where('is_seed', true)
            ->update(['should_deploy' => false]);
    }

    public function down(): void
    {
        // Intentionally left blank: restoring should_deploy=true on seeds
        // would put raw seed uploads into customers' live ads.
    }
};
