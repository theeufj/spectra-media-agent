<?php

namespace Tests\Feature;

use App\Jobs\SendGoogleAdsLinkInvitation;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Linking a customer's existing Google Ads account is the way around the
 * account-creation gate.
 *
 * Google will not let a manager account create client accounts through the API
 * until it has managed roughly US$1,000 of spend — the constraint that has kept
 * every sign-up stuck at the same step. Linking an account the customer already
 * owns carries no such threshold, so that segment can be onboarded immediately.
 *
 * The invitation lands in the customer's own Google Ads interface and does
 * nothing until they accept, which is the correct shape: access to someone's
 * advertising account should require them to say yes.
 */
class GoogleAdsAccountLinkingTest extends TestCase
{
    use RefreshDatabase;

    public function test_supplying_an_account_id_requests_a_link(): void
    {
        Queue::fake();

        Customer::factory()->create([
            'google_ads_customer_id' => '9636173260',
            'is_sandbox' => false,
        ]);

        Queue::assertPushed(SendGoogleAdsLinkInvitation::class);
    }

    public function test_adding_the_account_id_later_also_requests_a_link(): void
    {
        // The common case — customers rarely have the id to hand at sign-up.
        Queue::fake();

        $customer = Customer::factory()->create([
            'google_ads_customer_id' => null,
            'is_sandbox' => false,
        ]);

        Queue::assertNotPushed(SendGoogleAdsLinkInvitation::class);

        $customer->update(['google_ads_customer_id' => '9636173260']);

        Queue::assertPushed(SendGoogleAdsLinkInvitation::class);
    }

    public function test_an_already_linked_customer_is_not_invited_again(): void
    {
        Queue::fake();

        Customer::factory()->create([
            'google_ads_customer_id' => '9636173260',
            'google_ads_link_status' => 'active',
            'is_sandbox' => false,
        ]);

        Queue::assertNotPushed(SendGoogleAdsLinkInvitation::class);
    }

    public function test_a_customer_awaiting_approval_is_not_chased_with_another_request(): void
    {
        // Re-inviting would create a second pending request in the customer's
        // account and make it unclear which one to accept.
        Queue::fake();

        $customer = Customer::factory()->create([
            'google_ads_customer_id' => '9636173260',
            'google_ads_link_status' => 'pending',
            'is_sandbox' => false,
        ]);

        $customer->update(['name' => 'Renamed']);

        Queue::assertNotPushed(SendGoogleAdsLinkInvitation::class);
    }

    public function test_sandbox_customers_are_never_invited(): void
    {
        // A sandbox customer is synthetic. Sending a manager request would put a
        // real invitation into a real Google Ads account for a customer that
        // does not exist.
        Queue::fake();

        Customer::factory()->create([
            'google_ads_customer_id' => '9636173260',
            'is_sandbox' => true,
        ]);

        Queue::assertNotPushed(SendGoogleAdsLinkInvitation::class);
    }

    public function test_a_malformed_account_id_is_rejected_before_calling_google(): void
    {
        // Google Ads customer ids are exactly ten digits. Anything else is a
        // typo, and sending it would spend an API call to be told so.
        $customer = Customer::factory()->create([
            'google_ads_customer_id' => '12345',
            'is_sandbox' => false,
        ]);

        (new SendGoogleAdsLinkInvitation($customer))->handle();

        $this->assertNull($customer->fresh()->google_ads_link_status);
    }

    public function test_dashes_in_the_account_id_are_tolerated(): void
    {
        // Google displays ids as 123-456-7890, so that is how customers copy them.
        Queue::fake();

        Customer::factory()->create([
            'google_ads_customer_id' => '963-617-3260',
            'is_sandbox' => false,
        ]);

        Queue::assertPushed(SendGoogleAdsLinkInvitation::class);
    }
}
