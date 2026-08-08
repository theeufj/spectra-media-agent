<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * campaigns.status held mixed casing: the column default was 'DRAFT' and
 * CheckCampaignPolicyViolations wrote 'PAUSED', while readers such as
 * CampaignHealthChecker compared against lowercase 'draft'/'paused'. Those
 * comparisons never matched, so intentionally-paused campaigns were treated
 * as unexpectedly non-delivering.
 *
 * Lowercase every value and move the column default to 'draft' so the
 * CampaignStatus enum cast has a single canonical representation.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Only touches casing — no value is remapped to a different state.
        DB::table('campaigns')->update(['status' => DB::raw('LOWER(status)')]);

        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('status')->default('draft')->change();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('status')->default('DRAFT')->change();
        });

        DB::table('campaigns')->whereIn('status', ['draft', 'paused'])->update([
            'status' => DB::raw('UPPER(status)'),
        ]);
    }
};
