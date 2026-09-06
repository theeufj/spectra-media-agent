<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give ad-spend ledger rows a real "which day is this for" column.
 *
 * The double-billing guard (AdSpendBillingService::deductionsFor) matched the
 * day by `description LIKE '%2026-09-05'`. It worked only because all four
 * writers happened to append the date — and deduct()'s own default description,
 * 'Daily ad spend charge', does not. One caller omitting the description would
 * have silently defeated the guard and re-billed a day that was already paid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_spend_transactions', function (Blueprint $table) {
            $table->date('billed_for')->nullable()->after('amount');
            $table->index(['ad_spend_credit_id', 'billed_for']);
        });

        // Backfill from the convention the column replaces: every existing
        // deduction description ends in the day it bills for.
        DB::statement(<<<'SQL'
            UPDATE ad_spend_transactions
            SET billed_for = substring(description from '(\d{4}-\d{2}-\d{2})$')::date
            WHERE description ~ '\d{4}-\d{2}-\d{2}$'
        SQL);
    }

    public function down(): void
    {
        Schema::table('ad_spend_transactions', function (Blueprint $table) {
            $table->dropIndex(['ad_spend_credit_id', 'billed_for']);
            $table->dropColumn('billed_for');
        });
    }
};
