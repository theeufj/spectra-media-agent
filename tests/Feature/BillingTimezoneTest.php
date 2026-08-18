<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Services\AdSpendBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Billing follows the ad account's day, not the server's.
 *
 * Ad platforms report by their account's timezone; this application runs in UTC.
 * For an Australian account that is ten hours apart, so now()->subDay() asked
 * for 17 August while Google had already closed off the 18th. Billing sat
 * permanently one day behind, and every reconciliation compared mismatched
 * windows and reported a divergence that was really just an offset.
 */
class BillingTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private function billingDate(Customer $customer): string
    {
        $method = new \ReflectionMethod(AdSpendBillingService::class, 'billingDate');
        $method->setAccessible(true);

        return $method->invoke(app(AdSpendBillingService::class), $customer);
    }

    public function test_an_australian_account_bills_the_australian_day(): void
    {
        // 21:45 UTC on the 18th is already 07:45 on the 19th in Sydney, so the
        // day just closed is the 18th — not the 17th.
        Carbon::setTestNow('2026-08-18 21:45:00');

        $customer = Customer::factory()->create(['timezone' => 'Australia/Sydney']);

        $this->assertSame('2026-08-18', $this->billingDate($customer));
    }

    public function test_the_same_moment_bills_a_different_day_for_a_us_account(): void
    {
        // 21:45 UTC on the 18th is 17:45 on the 18th in New York, so yesterday
        // is still the 17th. Two customers, one run, two correct answers.
        Carbon::setTestNow('2026-08-18 21:45:00');

        $customer = Customer::factory()->create(['timezone' => 'America/New_York']);

        $this->assertSame('2026-08-17', $this->billingDate($customer));
    }

    public function test_a_customer_without_a_usable_timezone_keeps_the_old_behaviour(): void
    {
        // customers.timezone is NOT NULL, so in practice every customer has one
        // and this fix applies universally. An empty string is the only way the
        // fallback is reached, and falling back to the app timezone is the
        // previous behaviour rather than a guess at where they are.
        Carbon::setTestNow('2026-08-18 21:45:00');

        $customer = Customer::factory()->create(['timezone' => '']);

        $this->assertSame('2026-08-17', $this->billingDate($customer));
    }

    public function test_an_invalid_timezone_does_not_stop_billing(): void
    {
        // A bad string in one customer's record must not take the nightly run
        // down for everybody.
        Carbon::setTestNow('2026-08-18 21:45:00');

        $customer = Customer::factory()->create(['timezone' => 'Not/AZone']);

        $this->assertSame('2026-08-17', $this->billingDate($customer));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
