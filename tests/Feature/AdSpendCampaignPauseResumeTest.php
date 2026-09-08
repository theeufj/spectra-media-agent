<?php

namespace Tests\Feature;

use App\Models\AdSpendCredit;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\AdSpendBillingService;
use App\Services\Customers\DeactivateCustomerService;
use App\Services\GoogleAds\CommonServices\UpdateCampaignStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The third declined charge has to actually stop the ads, and a recovered
 * payment has to actually start them again.
 *
 * Neither did. The pause loop called Google's UpdateCampaignStatus::pause()
 * with one of its two required arguments — an ArgumentCountError on the first
 * statement inside the per-campaign try, swallowed by the \Throwable catch
 * before Facebook, Microsoft or LinkedIn were touched and before the campaign
 * was marked paused. The resume loop filtered on `campaigns.paused_reason`, a
 * column no migration has ever created, so it threw SQLSTATE 42703 after the
 * recovery charge had been taken and the account already read healthy.
 */
class AdSpendCampaignPauseResumeTest extends TestCase
{
    use DatabaseTransactions;

    private function customerWithPausedAccount(): Customer
    {
        $customer = Customer::factory()->create([
            'google_ads_customer_id' => '123-456-7890',
        ]);

        AdSpendCredit::create([
            'customer_id' => $customer->id,
            'status' => 'active',
            'payment_status' => AdSpendCredit::PAYMENT_PAUSED,
            'current_balance' => 0,
            'initial_credit_amount' => 350,
            'failed_charge_count' => 3,
            'campaigns_paused_at' => now(),
        ]);

        return $customer->fresh();
    }

    private function invoke(string $method, mixed ...$args): mixed
    {
        $reflected = new \ReflectionMethod(AdSpendBillingService::class, $method);
        $reflected->setAccessible(true);

        return $reflected->invoke(app(AdSpendBillingService::class), ...$args);
    }

    public function test_the_payment_failure_pause_hands_every_active_campaign_to_the_shared_pause(): void
    {
        // The platform calls live in DeactivateCustomerService, which passes
        // Google the customer id *and* the resource name. Billing keeping a
        // second copy of them is how the one-argument call survived.
        $customer = $this->customerWithPausedAccount();

        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'active',
            'platform_status' => 'ENABLED',
            'google_ads_campaign_id' => 'customers/1234567890/campaigns/999',
        ]);

        $deactivator = new class extends DeactivateCustomerService
        {
            /** @var list<int> */
            public array $pausedCampaignIds = [];

            public function pauseCampaign(Customer $customer, Campaign $campaign): true|string|null
            {
                $this->pausedCampaignIds[] = $campaign->id;

                return true;
            }
        };

        $this->app->instance(DeactivateCustomerService::class, $deactivator);

        $this->invoke('pauseAllCampaigns', $customer);

        $this->assertSame([$campaign->id], $deactivator->pausedCampaignIds);
    }

    public function test_the_billing_pause_reuses_the_implementation_that_passes_google_both_arguments(): void
    {
        // pause(string $customerId, string $campaignResourceName). Called with
        // one argument PHP raises ArgumentCountError — an \Error, which a
        // catch (\Exception) would not even see.
        $this->assertSame(
            2,
            (new \ReflectionMethod(UpdateCampaignStatus::class, 'pause'))->getNumberOfRequiredParameters()
        );

        $this->assertTrue(
            (new \ReflectionMethod(DeactivateCustomerService::class, 'pauseCampaign'))->isPublic(),
            'Ad spend billing pauses through this method; making it private again invites the second copy back.'
        );
    }

    public function test_resuming_reads_no_column_that_does_not_exist(): void
    {
        // The query is the assertion: `where('paused_reason', ...)` threw
        // SQLSTATE 42703 here, after the recovery charge had been taken and
        // restoreAccount() had already made the account read healthy.
        $customer = $this->customerWithPausedAccount();

        // Microsoft only, and the customer has no microsoft_ads_account_id, so
        // no platform call is attempted and the local write is what is under test.
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'paused',
            'platform_status' => 'PAUSED',
            'microsoft_ads_campaign_id' => 'ms-999',
        ]);

        $this->invoke('resumeAllCampaigns', $customer);

        $campaign->refresh();

        $this->assertSame('active', $campaign->status->value);
        $this->assertSame(
            'ENABLED',
            $campaign->platform_status,
            'Both columns move together, or the next billing run reads the campaign as still paused.'
        );
    }

    public function test_a_campaign_paused_for_a_policy_violation_is_not_resumed_by_a_top_up(): void
    {
        // CheckCampaignPolicyViolations writes only the local `status`; it never
        // pauses the campaign on the platform. Resuming every paused campaign
        // would put a disapproved ad back in front of people, on the customer's
        // money, because they entered a new card.
        $customer = $this->customerWithPausedAccount();

        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'paused',
            'platform_status' => 'ENABLED',
            'microsoft_ads_campaign_id' => 'ms-998',
        ]);

        $this->invoke('resumeAllCampaigns', $customer);

        $this->assertSame('paused', $campaign->fresh()->status->value);
    }

    public function test_recovery_only_un_pauses_when_the_pause_sweep_had_run(): void
    {
        // A top-up on an account that only ever reached grace period restores
        // budgets, but must not switch on campaigns billing never paused.
        $customer = $this->customerWithPausedAccount();

        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'paused',
            'platform_status' => 'PAUSED',
            'microsoft_ads_campaign_id' => 'ms-997',
        ]);

        app(AdSpendBillingService::class)->recoverCampaigns($customer, false);

        $this->assertSame('paused', $campaign->fresh()->status->value);
    }
}
