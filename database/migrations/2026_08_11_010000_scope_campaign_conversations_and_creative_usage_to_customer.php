<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Key campaign conversations and creative quota to the customer.
 *
 * A customer may have many users. Anything describing the advertising account
 * belongs to the customer; only things describing a person belong to the user.
 *
 * campaign_conversations was keyed by (campaign_id, user_id), so two colleagues
 * discussing the same campaign got separate threads and neither saw the other's
 * context — despite the campaign itself belonging to one customer. user_id is
 * retained as the last author, which is genuinely per-person.
 *
 * creative_usages tracked generation quota per user per period, so a customer
 * with three users received three separate quotas. Quota is sold per account,
 * not per seat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_conversations', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('campaign_id')
                ->constrained()->cascadeOnDelete();
            $table->index('customer_id');
        });

        // user_id becomes "most recent participant" rather than an owner, so a
        // thread can exist before anyone has spoken.
        DB::statement('ALTER TABLE campaign_conversations ALTER COLUMN user_id DROP NOT NULL');

        // Derive from the campaign, which already knows its customer.
        DB::statement('
            UPDATE campaign_conversations cc
            SET customer_id = c.customer_id
            FROM campaigns c
            WHERE cc.campaign_id = c.id
        ');

        Schema::table('creative_usages', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('user_id')
                ->constrained()->cascadeOnDelete();
        });

        // Attribute existing usage only where the owning user has exactly one
        // customer. Splitting a shared quota across customers on a guess would
        // hand someone free generations or silently take them away.
        DB::statement('
            UPDATE creative_usages cu
            SET customer_id = sole.customer_id
            FROM (
                SELECT user_id, MIN(customer_id) AS customer_id
                FROM customer_user
                GROUP BY user_id
                HAVING COUNT(*) = 1
            ) sole
            WHERE cu.user_id = sole.user_id
        ');

        // The old key was per-seat and has to go, or a customer could still only
        // ever hold one row per user per period.
        DB::statement('ALTER TABLE creative_usages DROP CONSTRAINT IF EXISTS creative_usages_user_id_period_unique');

        // One quota row per customer per period. Partial, because the
        // mid-onboarding fallback writes rows with no customer yet — Postgres
        // treats NULLs as distinct, so those would otherwise escape any
        // uniqueness at all.
        DB::statement('CREATE UNIQUE INDEX creative_usages_customer_period_unique
                       ON creative_usages (customer_id, period) WHERE customer_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX creative_usages_user_period_unique
                       ON creative_usages (user_id, period) WHERE customer_id IS NULL');
    }

    public function down(): void
    {
        Schema::table('campaign_conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });

        DB::statement('DROP INDEX IF EXISTS creative_usages_customer_period_unique');
        DB::statement('DROP INDEX IF EXISTS creative_usages_user_period_unique');

        Schema::table('creative_usages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
        });
    }
};
