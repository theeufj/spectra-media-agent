<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records that a feature was used, so read-only usage stops being invisible.
 *
 * Two things are unmeasurable without this table:
 *
 *  - Every read-only surface. Viewing analytics, opening the war room, browsing
 *    creatives, downloading a report — none of it writes a row anywhere today,
 *    so adoption of roughly half the product simply cannot be reported.
 *  - Login recency past 30 days. Logins land in activity_logs, which
 *    ArchiveActivityLogs deletes at config('activity.retention_days') = 30, and
 *    there is no last_login_at column on users. Beyond a month there is no
 *    record that anyone ever signed in.
 *
 * DAILY COUNTERS, NOT RAW EVENTS. One row per (customer, user, feature, action,
 * day), incremented by upsert. Every question the usage dashboard asks is a
 * count-per-period-per-account, so nothing is lost, and the write path stays
 * cheap by construction: someone refreshing /dashboard four hundred times
 * writes one row and four hundred indexed increments. A raw event log would
 * need a throttle to survive that, and the throttle marker would itself be a
 * write on the database cache store this app runs.
 *
 * What is given up: per-event timestamps and ordering, so session-level
 * analysis is not possible from this table. Add a raw event log later if a
 * question actually demands it, rather than paying for it up front.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_usage_daily', function (Blueprint $table) {
            $table->id();

            // Both nullable: usage can happen before an account is selected, and
            // nullOnDelete keeps the history when an account or user is removed —
            // deleting a customer should not rewrite last quarter's adoption rate.
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Values come from App\Enums\ProductFeature. Never write a bare
            // string: casing drift in campaigns.status is what BILL-8 was.
            $table->string('feature', 64);
            $table->string('action', 32)->default('viewed');

            $table->date('day');
            $table->unsignedInteger('count')->default(1);
            $table->timestamp('last_at')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'feature', 'day']);  // per-account drill-down
            $table->index(['feature', 'day']);                 // platform-wide adoption
            $table->index('day');                              // pruning
        });

        // The upsert target, and the reason this table stays small. Without it
        // the design collapses back into an append-only event log.
        //
        // NULLS NOT DISTINCT is load-bearing, not a flourish. Postgres treats
        // NULLs as distinct in a unique index by default, so a plain UNIQUE
        // would dedupe rows for users who have selected an account and silently
        // insert a fresh row on every request for those who have not — exactly
        // the new signups whose activation this dashboard is meant to measure,
        // and the write amplification would be unbounded.
        //
        // Requires Postgres 15. Checked rather than assumed: a silently
        // duplicate-prone index is far worse than a migration that refuses to run.
        $version = DB::selectOne('SHOW server_version_num');
        $versionNum = (int) ($version->server_version_num ?? 0);

        if ($versionNum < 150000) {
            throw new RuntimeException(
                'feature_usage_daily needs PostgreSQL 15+ for UNIQUE NULLS NOT DISTINCT '
                ."(server reports {$versionNum}). Without it, usage rows for users who have "
                .'not selected an account would never deduplicate.'
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX feature_usage_daily_unique
             ON feature_usage_daily (customer_id, user_id, feature, action, day)
             NULLS NOT DISTINCT'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_usage_daily');
    }
};
