<?php

namespace Tests\Feature;

use App\Jobs\ProvisionGoogleAdsAccount;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A customer without an ad account has nowhere to advertise.
 *
 * A fresh manager account cannot create client accounts until it has managed
 * roughly US$1,000 of spend, so every signup from March onwards arrived without
 * one and nothing attempted to fix it. The only symptom was conversion tracking
 * failing on "Google Ads account not connected", retrying three times and
 * paging an admin about a condition nobody could act on.
 *
 * The gate has cleared, so creating the account is now part of onboarding.
 */
class GoogleAdsAccountProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_signup_alone_provisions_nothing(): void
    {
        // Provisioning at creation minted a REAL Google Ads sub-account for
        // every tire-kicker and test signup — accounts that had to be
        // cancelled by hand in the MCC UI. Signup is not deploy-intent.
        Queue::fake();

        Customer::factory()->create([
            'google_ads_customer_id' => null,
            'google_ads_link_status' => null,
            'is_sandbox' => false,
        ]);

        Queue::assertNotPushed(ProvisionGoogleAdsAccount::class);
    }

    public function test_budget_confirmation_is_the_deploy_intent_that_provisions(): void
    {
        Queue::fake();
        $user = \App\Models\User::factory()->create();
        $customer = Customer::factory()->create([
            'google_ads_customer_id' => null,
            'google_ads_link_status' => null,
            'is_sandbox' => false,
        ]);
        $customer->users()->attach($user->id, ['role' => 'owner']);
        $campaign = \App\Models\Campaign::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->post(route('campaigns.confirm-budget', $campaign), ['daily_budget' => 45]);

        Queue::assertPushed(ProvisionGoogleAdsAccount::class);
    }

    public function test_a_customer_bringing_their_own_account_is_left_alone(): void
    {
        // Creating a second account would leave them advertising from the one
        // they did not accept the invitation into.
        Queue::fake();

        ProvisionGoogleAdsAccount::dispatchIfNeeded(Customer::factory()->create([
            'google_ads_customer_id' => null,
            'google_ads_link_status' => 'pending',
            'is_sandbox' => false,
        ]));

        Queue::assertNotPushed(ProvisionGoogleAdsAccount::class);
    }

    public function test_a_customer_who_already_has_an_account_is_left_alone(): void
    {
        Queue::fake();

        ProvisionGoogleAdsAccount::dispatchIfNeeded(Customer::factory()->create([
            'google_ads_customer_id' => '1234567890',
            'is_sandbox' => false,
        ]));

        Queue::assertNotPushed(ProvisionGoogleAdsAccount::class);
    }

    public function test_sandbox_customers_are_never_provisioned(): void
    {
        // A sandbox customer is synthetic; creating a real Google Ads account
        // for one would put a real account under the MCC for nobody.
        Queue::fake();

        ProvisionGoogleAdsAccount::dispatchIfNeeded(Customer::factory()->create([
            'google_ads_customer_id' => null,
            'is_sandbox' => true,
        ]));

        Queue::assertNotPushed(ProvisionGoogleAdsAccount::class);
    }

    public function test_the_job_skips_a_customer_who_gained_an_account_meanwhile(): void
    {
        // Queued work runs later than it was dispatched. By then the customer
        // may have linked their own account, and creating a second one is the
        // failure mode worth guarding.
        $customer = Customer::factory()->create(['google_ads_customer_id' => null, 'is_sandbox' => false]);
        $customer->updateQuietly(['google_ads_customer_id' => '9999999999']);

        (new ProvisionGoogleAdsAccount($customer))->handle();

        $this->assertSame('9999999999', $customer->fresh()->google_ads_customer_id);
    }

    public function test_an_invalid_currency_blocks_creation_rather_than_producing_a_wrong_account(): void
    {
        // A Google Ads account's currency can never be changed after creation.
        // One customer carried "DOL", which is not an ISO 4217 code — so a bad
        // value does not produce a failed call to retry, it produces a
        // permanently wrong account.
        //
        // The MCC row matters: without one the job exits before the currency
        // check, and this test only passed on machines whose .env supplied
        // the fallback — CI has no such env and saw an empty activity table.
        \App\Models\MccAccount::create([
            'name' => 'Test MCC',
            'google_customer_id' => '111-222-3333',
            'refresh_token' => 'test-token',
            'is_active' => true,
        ]);

        $customer = Customer::factory()->create([
            'google_ads_customer_id' => null,
            'currency_code' => 'DOL',
            'is_sandbox' => false,
        ]);

        (new ProvisionGoogleAdsAccount($customer))->handle();

        $this->assertNull($customer->fresh()->google_ads_customer_id);
        $this->assertDatabaseHas('agent_activities', [
            'customer_id' => $customer->id,
            'action' => 'google_ads_account_blocked',
        ]);
    }

    public function test_the_job_skips_a_customer_whose_link_became_pending(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => null, 'is_sandbox' => false]);
        $customer->updateQuietly(['google_ads_link_status' => 'pending']);

        (new ProvisionGoogleAdsAccount($customer))->handle();

        $this->assertNull($customer->fresh()->google_ads_customer_id);
    }
}
