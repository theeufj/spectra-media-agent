<?php

namespace Tests\Feature;

use App\Models\AdSpendCredit;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\LinkedInAdsPerformanceData;
use App\Models\MicrosoftAdsPerformanceData;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The admin reconciliation tool must count every platform the ledger debits.
 *
 * It summed Google and Facebook only, against AdSpendTransaction::totalDebited()
 * — which is account-wide — and a nightly deduction that bills all four. For a
 * Microsoft or LinkedIn customer the figure therefore went negative and the
 * button reported "already reconciled" while genuinely unbilled spend sat there.
 * ReconcileAdSpend::platformSpend() had always summed all four, so the two paths
 * disagreed about what the same customer owed.
 */
class AdSpendPlatformCoverageTest extends TestCase
{
    use DatabaseTransactions;

    public function test_microsoft_and_linkedin_spend_is_reconcilable(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);
        $user->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));

        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        $yesterday = now()->subDay()->toDateString();

        MicrosoftAdsPerformanceData::create([
            'campaign_id' => $campaign->id,
            'date' => $yesterday,
            'cost' => 200.00,
            'impressions' => 0,
            'clicks' => 0,
            'conversions' => 0,
        ]);

        LinkedInAdsPerformanceData::create([
            'campaign_id' => $campaign->id,
            'date' => $yesterday,
            'cost' => 100.00,
            'impressions' => 0,
            'clicks' => 0,
            'conversions' => 0,
        ]);

        $credit = AdSpendCredit::create([
            'customer_id' => $customer->id,
            'initial_credit_amount' => 1000.00,
            'current_balance' => 1000.00,
            'currency' => 'USD',
            'status' => AdSpendCredit::STATUS_ACTIVE,
            'payment_status' => AdSpendCredit::PAYMENT_CURRENT,
        ]);

        $this->actingAs($user)
            ->post(route('admin.customers.reconcile-spend', $customer))
            ->assertRedirect();

        $this->assertEqualsWithDelta(
            700.00,
            (float) $credit->fresh()->current_balance,
            0.01,
            '$300 of Microsoft and LinkedIn spend was never billed; the tool must take it.'
        );
    }
}
