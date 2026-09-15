<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\User;
use App\Services\CreativeQuotaService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Paying for a plan has to grant the plan.
 *
 * Entitlement lives on the customer — a business has many users but one plan —
 * while Cashier records subscriptions against the user. Nothing bridged the
 * two, so buying Starter left customers.plan_id NULL and resolvePlan()
 * returned free.
 *
 * Seen live: an active subscription to the $149 price, and a collateral page
 * reading "0 video generations remaining" to someone who had just bought ten
 * of them. The purchase worked, the money moved, and the entitlement never
 * arrived.
 */
class SubscribedPlanEntitlementTest extends TestCase
{
    use DatabaseTransactions;

    private function plan(string $slug, int $cents, string $price, array $limits): Plan
    {
        $plan = Plan::firstOrCreate(
            ['slug' => $slug],
            ['name' => ucfirst($slug), 'price_cents' => $cents, 'billing_interval' => 'month'],
        );

        $plan->forceFill(['stripe_price_id' => $price, 'creative_limits' => $limits, 'price_cents' => $cents])->save();

        return $plan;
    }

    /** @return array{User, Customer} */
    private function account(): array
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create(['service_type' => 'managed']);
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        return [$user, $customer->fresh()];
    }

    private function subscribe(User $user, string $price, string $status = 'active'): void
    {
        DB::table('subscriptions')->insert([
            'user_id' => $user->id,
            'type' => 'default',
            'stripe_id' => 'sub_'.$user->id.'_'.$price,
            'stripe_status' => $status,
            'stripe_price' => $price,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_an_active_subscription_grants_its_plan(): void
    {
        $this->plan('starter', 14900, 'price_starter', [
            'image_generations' => 50,
            'video_generations' => 10,
            'refinements' => 50,
            'max_refinements_per_item' => 3,
        ]);

        [$user, $customer] = $this->account();
        $this->subscribe($user, 'price_starter');

        $this->assertSame('starter', $customer->fresh()->resolvePlan()->slug);

        // The figure the page actually showed as zero.
        $summary = app(CreativeQuotaService::class)->getUsageSummary($user, $customer->fresh());
        $this->assertSame(10, $summary['video_generations']['limit']);
    }

    public function test_a_cancelled_subscription_grants_nothing(): void
    {
        $this->plan('starter', 14900, 'price_starter', ['image_generations' => 50, 'video_generations' => 10]);

        [$user, $customer] = $this->account();
        $this->subscribe($user, 'price_starter', 'canceled');

        $this->assertSame('free', $customer->fresh()->resolvePlan()->slug);
    }

    public function test_a_trial_counts(): void
    {
        $this->plan('starter', 14900, 'price_starter', ['image_generations' => 50, 'video_generations' => 10]);

        [$user, $customer] = $this->account();
        $this->subscribe($user, 'price_starter', 'trialing');

        // A trial is a subscription whose invoice has not landed yet; refusing
        // the allowances during it is refusing the trial.
        $this->assertSame('starter', $customer->fresh()->resolvePlan()->slug);
    }

    public function test_an_explicit_plan_still_wins(): void
    {
        $this->plan('starter', 14900, 'price_starter', ['image_generations' => 50]);
        $growth = $this->plan('growth', 24900, 'price_growth', ['image_generations' => 150]);

        [$user, $customer] = $this->account();
        $this->subscribe($user, 'price_starter');
        $customer->forceFill(['plan_id' => $growth->id])->save();

        // An admin moving an account onto a plan must not be overridden by
        // what Stripe happens to say.
        $this->assertSame('growth', $customer->fresh()->resolvePlan()->slug);
    }

    public function test_the_more_expensive_of_two_active_subscriptions_wins(): void
    {
        $this->plan('starter', 14900, 'price_starter', ['image_generations' => 50]);
        $this->plan('growth', 24900, 'price_growth', ['image_generations' => 150]);

        [$user, $customer] = $this->account();
        $second = User::factory()->create();
        $second->customers()->attach($customer->id, ['role' => 'member']);

        $this->subscribe($user, 'price_starter');
        $this->subscribe($second, 'price_growth');

        // A team where two people subscribed, or someone mid-upgrade. They are
        // being charged for both; grant the one they are paying most for.
        $this->assertSame('growth', $customer->fresh()->resolvePlan()->slug);
    }

    public function test_a_price_no_plan_matches_is_ignored(): void
    {
        [$user, $customer] = $this->account();
        $this->subscribe($user, 'price_something_retired');

        // A retired price must not resolve to an arbitrary plan.
        $this->assertSame('free', $customer->fresh()->resolvePlan()->slug);
    }
}
