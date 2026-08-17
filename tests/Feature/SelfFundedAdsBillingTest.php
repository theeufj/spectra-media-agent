<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Customers who own their ad account are never asked to pre-pay ad credit.
 *
 * Two kinds of Google Ads account reach this platform. One we create as a
 * sub-account under the MCC, where Spectra fronts the spend and bills it back —
 * that is what the ad-spend credit system is for. The other the customer already
 * owned and simply granted us management of. Their card is on that account and
 * Google charges them directly.
 *
 * Asking the second group for prepaid ad credit bills them twice for the same
 * clicks. They are gated on their subscription and their media allowance, and on
 * nothing else.
 */
class SelfFundedAdsBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_linked_account_is_self_funded(): void
    {
        // An accepted manager link means the customer brought the account with
        // them — and its billing.
        $customer = Customer::factory()->create(['google_ads_link_status' => 'active']);

        $this->assertTrue($customer->isSelfFundedAds());
    }

    public function test_an_account_we_created_is_not(): void
    {
        // A sub-account created under the MCC never sets a link status, which is
        // what makes the field a reliable marker.
        $customer = Customer::factory()->create([
            'google_ads_customer_id' => '1234567890',
            'google_ads_link_status' => null,
        ]);

        $this->assertFalse($customer->isSelfFundedAds());
    }

    public function test_a_pending_link_is_not_yet_self_funded(): void
    {
        // Until they accept, we manage nothing and the distinction has not been
        // established.
        $customer = Customer::factory()->create(['google_ads_link_status' => 'pending']);

        $this->assertFalse($customer->isSelfFundedAds());
    }

    public function test_a_customer_with_allowance_left_can_publish(): void
    {
        $customer = Customer::factory()->create(['google_ads_link_status' => 'active']);
        $customer->users()->attach(User::factory()->create());

        $this->assertTrue($customer->hasMediaCreditsRemaining());
    }

    public function test_allowance_is_shared_across_the_team(): void
    {
        // A customer is a team. One member exhausting their own allowance must
        // not block everybody else.
        $customer = Customer::factory()->create(['google_ads_link_status' => 'active']);

        $exhausted = User::factory()->create();
        $fresh = User::factory()->create();

        $customer->users()->attach($exhausted);
        $customer->users()->attach($fresh);

        $quota = app(\App\Services\CreativeQuotaService::class);
        $usage = $quota->getOrCreateUsage($exhausted);
        $usage->update([
            'image_generations_used' => 99999,
            'video_generations_used' => 99999,
        ]);

        $this->assertTrue($customer->hasMediaCreditsRemaining(), 'a teammate with room should keep the customer publishing');
    }

    public function test_a_customer_with_no_users_is_not_blocked(): void
    {
        // Refusing here would block deployment for a reason the customer has no
        // way to act on.
        $customer = Customer::factory()->create(['google_ads_link_status' => 'active']);

        $this->assertTrue($customer->hasMediaCreditsRemaining());
    }
}
