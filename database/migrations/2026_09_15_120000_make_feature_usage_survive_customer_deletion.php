<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deleting a customer aborted on this table's own unique index.
 *
 * Two deliberate decisions collided. customer_id is nullOnDelete so that
 * removing an account does not rewrite last quarter's adoption figures; and the
 * unique index is NULLS NOT DISTINCT so that usage recorded before an account
 * is selected still deduplicates instead of inserting a row per request.
 *
 * Put together, deleting a customer sets every one of their rows to NULL at
 * once — and under NULLS NOT DISTINCT those rows, which differed only by
 * customer_id, become duplicates of each other. Postgres refuses, and the
 * whole DELETE aborts. Any customer with more than one usage row could not be
 * deleted at all: not a test-only problem, but an erasure request that cannot
 * be honoured.
 *
 * customer_key is the same value with no foreign key on it, so deletion leaves
 * it alone. Moving the index onto it keeps both properties: rows survive their
 * customer, and rows that were never attributed to one still collapse together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('feature_usage_daily', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_key')->nullable()->after('customer_id');
        });

        // Existing rows keep their attribution, including any already orphaned
        // by a delete that happened to succeed.
        DB::statement('UPDATE feature_usage_daily SET customer_key = customer_id');

        DB::statement('DROP INDEX IF EXISTS feature_usage_daily_unique');

        DB::statement(
            'CREATE UNIQUE INDEX feature_usage_daily_unique
             ON feature_usage_daily (customer_key, user_id, feature, action, day)
             NULLS NOT DISTINCT'
        );

        // The drill-down reads by customer; it should follow the column that
        // still has a value after a deletion.
        Schema::table('feature_usage_daily', function (Blueprint $table) {
            $table->index(['customer_key', 'feature', 'day']);
        });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS feature_usage_daily_unique');

        DB::statement(
            'CREATE UNIQUE INDEX feature_usage_daily_unique
             ON feature_usage_daily (customer_id, user_id, feature, action, day)
             NULLS NOT DISTINCT'
        );

        Schema::table('feature_usage_daily', function (Blueprint $table) {
            $table->dropIndex(['customer_key', 'feature', 'day']);
            $table->dropColumn('customer_key');
        });
    }
};
