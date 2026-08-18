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

    public function test_a_new_customer_gets_an_account_provisioned(): void
    {
        Queue::fake();

        Customer::factory()->create([
            'google_ads_customer_id' => null,
            'google_ads_link_status' => null,
            'is_sandbox' => false,
        ]);

        Queue::assertPushed(ProvisionGoogleAdsAccount::class);
    }

    public function test_a_customer_bringing_their_own_account_is_left_alone(): void
    {
        // Creating a second account would leave them advertising from the one
        // they did not accept the invitation into.
        Queue::fake();

        Customer::factory()->create([
            'google_ads_customer_id' => null,
            'google_ads_link_status' => 'pending',
            'is_sandbox' => false,
        ]);

        Queue::assertNotPushed(ProvisionGoogleAdsAccount::class);
    }

    public function test_a_customer_who_already_has_an_account_is_left_alone(): void
    {
        Queue::fake();

        Customer::factory()->create([
            'google_ads_customer_id' => '1234567890',
            'is_sandbox' => false,
        ]);

        Queue::assertNotPushed(ProvisionGoogleAdsAccount::class);
    }

    public function test_sandbox_customers_are_never_provisioned(): void
    {
        // A sandbox customer is synthetic; creating a real Google Ads account
        // for one would put a real account under the MCC for nobody.
        Queue::fake();

        Customer::factory()->create([
            'google_ads_customer_id' => null,
            'is_sandbox' => true,
        ]);

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

    public function test_the_job_skips_a_customer_whose_link_became_pending(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => null, 'is_sandbox' => false]);
        $customer->updateQuietly(['google_ads_link_status' => 'pending']);

        (new ProvisionGoogleAdsAccount($customer))->handle();

        $this->assertNull($customer->fresh()->google_ads_customer_id);
    }
}
