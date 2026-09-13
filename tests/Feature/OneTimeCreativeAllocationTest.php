<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\User;
use App\Services\CreativeQuotaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * One payment buys one allocation, not a monthly refill.
 *
 * Creative usage is keyed on Y-m, which is how a subscription refills: a new
 * month is a new key, so firstOrCreate returns a fresh row at zero. Correct for
 * a plan billed monthly, wrong for the US$999 setup — that customer would
 * receive a new allowance every month for the life of the account, five videos
 * at a time, against a single payment.
 *
 * The numbers are why it matters. Measured from production ai_costs: an image
 * is $0.04 on Grok Imagine, a video $1.88, and $3.20 per 8-second segment when
 * the Veo fallback runs. A year of monthly refills at five videos is $113 on
 * Grok and $576 on Veo — more than the difference between every other limit on
 * the plan put together, and all of it after the only payment we ever take.
 */
class OneTimeCreativeAllocationTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{User, Customer} */
    private function setupOnlyCustomer(): array
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create([
            'service_type' => 'setup_only',
            'setup_fee_paid_at' => now(),
        ]);
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        return [$user, $customer];
    }

    public function test_a_new_month_does_not_hand_them_a_fresh_allowance(): void
    {
        [$user, $customer] = $this->setupOnlyCustomer();
        $service = app(CreativeQuotaService::class);

        Carbon::setTestNow('2026-09-13 10:00:00');
        $first = $service->getOrCreateUsage($user, $customer);
        $first->update(['image_generations_used' => 10, 'video_generations_used' => 5]);

        // Four months later.
        Carbon::setTestNow('2027-01-13 10:00:00');
        $later = $service->getOrCreateUsage($user, $customer);

        Carbon::setTestNow();

        $this->assertSame($first->id, $later->id, 'a one-time allocation must not roll over into a new row');
        $this->assertSame(10, $later->image_generations_used);
        $this->assertSame(5, $later->video_generations_used);
    }

    public function test_a_monthly_customer_still_refills(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create(['service_type' => 'managed']);
        $user->customers()->attach($customer->id, ['role' => 'owner']);
        $service = app(CreativeQuotaService::class);

        Carbon::setTestNow('2026-09-13 10:00:00');
        $september = $service->getOrCreateUsage($user, $customer);
        $september->update(['image_generations_used' => 40]);

        Carbon::setTestNow('2026-10-01 00:05:00');
        $october = $service->getOrCreateUsage($user, $customer);

        Carbon::setTestNow();

        // The one-time key must not leak into the plans that are sold monthly.
        $this->assertNotSame($september->id, $october->id);
        $this->assertSame(0, $october->image_generations_used);
    }

    public function test_the_summary_reports_the_bucket_it_actually_read(): void
    {
        [$user, $customer] = $this->setupOnlyCustomer();

        $summary = app(CreativeQuotaService::class)->getUsageSummary($user, $customer);

        // Reporting Y-m here would name a period nothing was read from, which
        // is how "resets on the 1st" would appear in a UI that never resets.
        $this->assertSame(CreativeQuotaService::ONE_TIME_PERIOD, $summary['period']);
        $this->assertTrue($summary['is_one_time']);
    }

    public function test_a_paid_setup_customer_is_not_on_free_limits(): void
    {
        [, $customer] = $this->setupOnlyCustomer();

        /*
           Before this plan existed they resolved to 'free': four images, no
           video, no refinements. Their dashboard read "Videos — Not on your
           plan" to someone who had just paid more than four months of Growth,
           for a build whose entire deliverable is creative.
        */
        $plan = $customer->resolvePlan();

        $this->assertSame('setup_only', $plan->slug);
        $this->assertSame(10, $plan->creative_limits['image_generations']);
        $this->assertSame(5, $plan->creative_limits['video_generations']);
        $this->assertSame(5, $plan->creative_limits['refinements']);
    }

    public function test_an_unpaid_setup_intent_earns_nothing(): void
    {
        $customer = Customer::factory()->create([
            'service_type' => 'setup_only',
            'setup_fee_paid_at' => null,
        ]);

        // Choosing the US$999 option is not paying for it.
        $this->assertSame('free', $customer->resolvePlan()->slug);
    }

    public function test_an_explicit_plan_still_wins(): void
    {
        [, $customer] = $this->setupOnlyCustomer();
        // Created here rather than read from a seed: the test database is
        // migrated, not seeded, and a test that skips when a row is missing is
        // a test that stops running.
        $growth = Plan::firstOrCreate(
            ['slug' => 'growth'],
            ['name' => 'Growth', 'price_cents' => 24900, 'billing_interval' => 'month'],
        );
        $customer->forceFill(['plan_id' => $growth->id])->save();

        // An admin moving someone onto a real plan must not be overridden by
        // the setup-only fallback.
        $this->assertSame('growth', $customer->fresh()->resolvePlan()->slug);
    }

    public function test_limits_come_from_the_account_not_the_person(): void
    {
        [$user, $customer] = $this->setupOnlyCustomer();

        /*
           The bug this exists to stop, seen on production immediately after
           resolvePlan() started returning setup_only: the dashboard still read
           "Images 0 / 4" and "Videos — Not on your plan".

           getLimits() asked User::resolveCurrentPlan(), which only looks at an
           assigned_plan_id or the person's own Stripe subscription. Entitlement
           lives on the customer now — a business has many users but one plan —
           so a customer carrying a plan with no personal subscription behind it
           resolved to free. Not specific to the one-time setup either: it is
           every account whose plan sits on the customer, which is all of them.
        */
        $summary = app(CreativeQuotaService::class)->getUsageSummary($user, $customer);

        $this->assertSame(10, $summary['image_generations']['limit']);
        $this->assertSame(5, $summary['video_generations']['limit']);
        $this->assertSame(5, $summary['refinements']['limit']);
        $this->assertSame('One-time setup', $summary['plan_name']);

        // And the gate agrees with the figure on screen.
        $this->assertTrue(app(CreativeQuotaService::class)->canGenerate($user, 'video', $customer));
    }

    public function test_a_customer_plan_beats_the_users_own_subscription_state(): void
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create(['service_type' => 'managed']);
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        $growth = Plan::firstOrCreate(
            ['slug' => 'growth'],
            ['name' => 'Growth', 'price_cents' => 24900, 'billing_interval' => 'month'],
        );
        $growth->forceFill(['creative_limits' => [
            'image_generations' => 150,
            'video_generations' => 10,
            'refinements' => 150,
            'max_refinements_per_item' => 3,
            'max_extensions_per_video' => 3,
        ]])->save();
        $customer->forceFill(['plan_id' => $growth->id])->save();

        // The user has no subscription of their own — a teammate on a company
        // plan never does. They still get the account's allowance.
        $summary = app(CreativeQuotaService::class)->getUsageSummary($user, $customer->fresh());

        $this->assertSame(150, $summary['image_generations']['limit']);
        $this->assertSame(10, $summary['video_generations']['limit']);
    }
}
