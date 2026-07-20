<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Ad spend credit rows were created with a hardcoded 'USD' currency even though
 * deductions were recorded in the ad account's native currency. Relabel each credit
 * row to its customer's currency_code. This is a label correction only — balances are
 * already denominated in the customer's currency (spend was deducted 1:1). (BILL-6)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Per-row update (not a JOIN update) so it compiles identically on every driver —
        // Postgres UPDATE ... FROM rejects table-qualified SET columns.
        DB::table('ad_spend_credits')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                $currency = DB::table('customers')->where('id', $row->customer_id)->value('currency_code');
                if ($currency && strtoupper($currency) !== strtoupper((string) $row->currency)) {
                    DB::table('ad_spend_credits')
                        ->where('id', $row->id)
                        ->update(['currency' => strtoupper($currency)]);
                }
            }
        });
    }

    public function down(): void
    {
        // Not reversed — the previous 'USD' label was incorrect.
    }
};
