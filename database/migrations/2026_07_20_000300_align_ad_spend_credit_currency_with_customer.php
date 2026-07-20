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
        DB::table('ad_spend_credits')
            ->join('customers', 'customers.id', '=', 'ad_spend_credits.customer_id')
            ->whereRaw('UPPER(ad_spend_credits.currency) <> UPPER(COALESCE(customers.currency_code, ad_spend_credits.currency))')
            ->update([
                'ad_spend_credits.currency' => DB::raw('UPPER(customers.currency_code)'),
            ]);
    }

    public function down(): void
    {
        // Not reversed — the previous 'USD' label was incorrect.
    }
};
