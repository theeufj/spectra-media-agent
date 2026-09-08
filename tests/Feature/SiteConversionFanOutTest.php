<?php

namespace Tests\Feature;

use App\Jobs\RecordSiteConversion;
use App\Jobs\RecordSiteGoogleConversion;
use App\Jobs\Scheduled\RecordSevenDayReturnConversions;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The two server-side own-site conversions must survive iOS.
 *
 * RecordSiteConversion selected its users with `whereNotNull('gclid')` and then
 * called the gclid-only Data Manager wrapper. Google substitutes gbraid or
 * wbraid for the gclid wherever iOS ATT applies, so every iOS conversion on
 * campaign_live and seven_day_return was dropped — silently, on the two
 * bottom-of-funnel signals Maximize Conversions bids from. The seven-day sweep
 * filtered the same way, so those users never even reached the fan-out.
 */
class SiteConversionFanOutTest extends TestCase
{
    use DatabaseTransactions;

    private function userOn(Customer $customer, array $attributes): User
    {
        $user = User::factory()->create($attributes);
        $customer->users()->attach($user->id, ['role' => 'member']);

        return $user;
    }

    public function test_the_fan_out_covers_gbraid_and_wbraid_not_just_gclid(): void
    {
        Queue::fake();

        $customer = Customer::factory()->create();
        $gclid = $this->userOn($customer, ['gclid' => 'Cj0KCQiA-demo-click-id']);
        $gbraid = $this->userOn($customer, ['gclid' => null, 'gbraid' => 'demo-gbraid']);
        $wbraid = $this->userOn($customer, ['gclid' => null, 'wbraid' => 'demo-wbraid']);
        $organic = $this->userOn($customer, ['gclid' => null]);

        (new RecordSiteConversion($customer->fresh(), 'campaign_live'))->handle();

        $dispatchedFor = [];
        Queue::assertPushed(RecordSiteGoogleConversion::class, function ($job) use (&$dispatchedFor) {
            $dispatchedFor[] = (fn () => $this->user->id)->call($job);

            return true;
        });

        sort($dispatchedFor);
        $expected = [$gclid->id, $gbraid->id, $wbraid->id];
        sort($expected);

        $this->assertSame($expected, $dispatchedFor);
        $this->assertNotContains($organic->id, $dispatchedFor);
    }

    public function test_the_conversion_is_timestamped_when_it_happened_not_when_the_user_registered(): void
    {
        Queue::fake();

        // campaign_live happens the day the campaign goes live. Handing the
        // child job nothing would date the conversion at registration, which for
        // a seven-day return is a week out and for a long-onboarding account can
        // fall outside Google's click lookback window entirely.
        $customer = Customer::factory()->create();
        $this->userOn($customer, [
            'gclid' => 'Cj0KCQiA-demo-click-id',
            'created_at' => now()->subDays(30),
        ]);

        (new RecordSiteConversion($customer->fresh(), 'campaign_live'))->handle();

        Queue::assertPushed(RecordSiteGoogleConversion::class, function ($job) {
            $occurredAt = (fn () => $this->occurredAt)->call($job);

            $this->assertNotNull($occurredAt, 'campaign_live must carry its own timestamp.');
            $this->assertLessThan(60, abs($occurredAt->getTimestamp() - now()->getTimestamp()));

            return true;
        });
    }

    public function test_signup_keeps_the_registration_timestamp(): void
    {
        // The default the fan-out deliberately does not override: for `signup`
        // the registration *is* the conversion, and both auth paths dispatch
        // the child job directly with two arguments.
        $user = User::factory()->create(['gclid' => 'Cj0KCQiA-demo-click-id']);

        $occurredAt = (fn () => $this->occurredAt)->call(new RecordSiteGoogleConversion($user, 'signup'));

        $this->assertNull($occurredAt);
    }

    public function test_the_seven_day_sweep_finds_an_ios_signup(): void
    {
        Queue::fake();

        // gbraid only — the sweep's own `whereNotNull('gclid')` excluded this
        // customer before the fan-out could see them.
        $customer = Customer::factory()->create();
        $this->userOn($customer, [
            'gclid' => null,
            'gbraid' => 'demo-gbraid',
            'created_at' => now()->subDays(7)->setTime(11, 0),
        ]);

        (new RecordSevenDayReturnConversions)->handle();

        Queue::assertPushed(
            RecordSiteConversion::class,
            fn ($job) => (fn () => $this->customer->id)->call($job) === $customer->id
        );
    }
}
