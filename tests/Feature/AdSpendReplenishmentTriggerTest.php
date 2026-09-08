<?php

namespace Tests\Feature;

use App\Models\AdSpendCredit;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\User;
use App\Services\AdSpendBillingService;
use App\Services\Agents\BudgetIntelligenceAgent;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Auto-replenishment must size itself from the authorised daily budgets.
 *
 * checkAndReplenish() filtered with `whereNotIn('primary_status', [...])`, which
 * is NULL-not-true. The column is nullable and MonitorCampaignStatus is its only
 * writer — and that job returns early when the platform lookup yields nothing —
 * so a campaign the monitor has not reached yet dropped out of the sum entirely.
 * With every campaign NULL the sum was 0 and control fell through to the rolling
 * average the comment two lines above says must not be used, which on a fresh
 * account is also 0: no top-up, no low-balance mail, and the campaigns kept
 * spending against an empty account.
 */
class AdSpendReplenishmentTriggerTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_campaign_with_no_primary_status_still_sizes_the_replenishment(): void
    {
        Mail::fake();

        $customer = Customer::factory()->create();
        $user = User::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        Campaign::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'active',
            'primary_status' => null,
            'daily_budget' => 100.00,
        ]);

        // One day of runway left, so the < 3 days rule fires.
        $credit = AdSpendCredit::create([
            'customer_id' => $customer->id,
            'initial_credit_amount' => 700.00,
            'current_balance' => 100.00,
            'currency' => 'USD',
            'status' => AdSpendCredit::STATUS_ACTIVE,
            'payment_status' => AdSpendCredit::PAYMENT_CURRENT,
        ]);

        $service = new class(new BudgetIntelligenceAgent) extends AdSpendBillingService
        {
            /** @var list<array{amount: float, description: string}> */
            public array $charges = [];

            protected function chargeCustomer(
                Customer $customer,
                float $amount,
                string $description,
                string $idempotencyKey,
            ): array {
                $this->charges[] = ['amount' => $amount, 'description' => $description];

                // Declined, so the run stops here rather than reaching Stripe.
                return ['success' => false, 'error' => 'declined in test'];
            }
        };

        (function () use ($customer, $credit) {
            $this->checkAndReplenish($customer, $credit);
        })->call($service);

        $this->assertNotEmpty(
            $service->charges,
            'A campaign with a NULL primary_status is still authorised to spend $100/day — the sum must include it.'
        );

        $this->assertEqualsWithDelta(
            700.00,
            $service->charges[0]['amount'],
            0.01,
            'Seven days of the $100 daily budget, not a rolling average of a ledger with nothing in it.'
        );
    }

    public function test_a_paused_campaign_is_still_excluded(): void
    {
        Mail::fake();

        $customer = Customer::factory()->create();

        Campaign::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'active',
            'primary_status' => 'PAUSED',
            'daily_budget' => 100.00,
        ]);

        $credit = AdSpendCredit::create([
            'customer_id' => $customer->id,
            'initial_credit_amount' => 700.00,
            'current_balance' => 100.00,
            'currency' => 'USD',
            'status' => AdSpendCredit::STATUS_ACTIVE,
            'payment_status' => AdSpendCredit::PAYMENT_CURRENT,
        ]);

        $service = new class(new BudgetIntelligenceAgent) extends AdSpendBillingService
        {
            /** @var list<array{amount: float, description: string}> */
            public array $charges = [];

            protected function chargeCustomer(
                Customer $customer,
                float $amount,
                string $description,
                string $idempotencyKey,
            ): array {
                $this->charges[] = ['amount' => $amount, 'description' => $description];

                return ['success' => false, 'error' => 'declined in test'];
            }
        };

        (function () use ($customer, $credit) {
            $this->checkAndReplenish($customer, $credit);
        })->call($service);

        // No authorised budget and no spend history: nothing to size a top-up
        // from, so nothing is charged.
        $this->assertEmpty($service->charges);
    }
}
