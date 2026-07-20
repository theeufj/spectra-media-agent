<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Track upload attempts so failed offline conversions can be retried a bounded
 * number of times (transient ad-network outages must not cause permanent data
 * loss for Smart Bidding), without hammering a permanently-bad row forever. (JOB-3)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offline_conversions', function (Blueprint $table) {
            if (!Schema::hasColumn('offline_conversions', 'upload_attempts')) {
                $table->unsignedInteger('upload_attempts')->default(0)->after('upload_results');
            }
        });
    }

    public function down(): void
    {
        Schema::table('offline_conversions', function (Blueprint $table) {
            if (Schema::hasColumn('offline_conversions', 'upload_attempts')) {
                $table->dropColumn('upload_attempts');
            }
        });
    }
};
