<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Plan;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Given the platforms somebody wants, which plan covers them.
 *
 * The wizard used to gate the choice by plan: Facebook greyed out with
 * "upgrade your plan to unlock", nothing saying which upgrade or what it cost.
 * A customer had to buy before finding out what the purchase let them pick.
 * Asking where they want to advertise and then pricing it is the same two facts
 * in the order a buyer can act on.
 *
 * Starter is why this is not a subset test. It covers any ONE platform, because
 * starter_platform is the customer's choice — and that choice is exactly what
 * the wizard is collecting.
 */
class PlanForPlatformsTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Plan::updateOrCreate(['slug' => 'starter'], [
            'name' => 'Starter', 'price_cents' => 14900, 'billing_interval' => 'month',
            'is_active' => true, 'stripe_price_id' => 'price_starter_test',
        ]);
        Plan::updateOrCreate(['slug' => 'growth'], [
            'name' => 'Growth', 'price_cents' => 24900, 'billing_interval' => 'month',
            'is_active' => true, 'stripe_price_id' => 'price_growth_test',
        ]);
        Plan::updateOrCreate(['slug' => 'agency'], [
            'name' => 'Agency', 'price_cents' => 0, 'billing_interval' => 'month',
            'is_active' => true, 'stripe_price_id' => null,
        ]);
    }

    public function test_one_platform_is_starter_whichever_one_it_is(): void
    {
        // starter_platform is the customer's choice, so Starter covers Facebook
        // just as it covers Google.
        $this->assertSame('starter', Plan::cheapestFor(['google'])?->slug);
        $this->assertSame('starter', Plan::cheapestFor(['facebook'])?->slug);
    }

    public function test_two_platforms_is_growth(): void
    {
        $this->assertSame('growth', Plan::cheapestFor(['google', 'facebook'])?->slug);
    }

    public function test_the_cheapest_that_covers_it_wins(): void
    {
        // Not merely "a plan that works" — the one they should be asked to buy.
        $this->assertSame(14900, Plan::cheapestFor(['google'])?->price_cents);
    }

    public function test_a_plan_nobody_can_buy_is_never_the_answer(): void
    {
        // Agency is "Contact Us" with no stripe_price_id. Offering it as the
        // answer to "what does this cost" is a dead end.
        $all = Plan::cheapestFor(['google', 'facebook', 'microsoft', 'linkedin']);

        $this->assertNotSame('agency', $all?->slug);
        $this->assertNotNull($all?->stripe_price_id);
    }

    public function test_nothing_selected_has_no_answer(): void
    {
        $this->assertNull(Plan::cheapestFor([]));
    }

    public function test_entitlement_still_reads_from_the_same_mapping(): void
    {
        /*
           Customer::allowedPlatforms() and Plan::cheapestFor() have to agree, or
           the wizard prices a plan that then refuses the platform at deploy.
           They now share Plan::platformsFor rather than carrying two copies.
        */
        $customer = Customer::factory()->create([
            'plan_id' => Plan::where('slug', 'starter')->value('id'),
            'starter_platform' => 'facebook',
        ]);

        $this->assertSame(['facebook'], $customer->fresh()->allowedPlatforms());
        $this->assertSame(['google'], Plan::platformsFor('setup_only'));
        $this->assertContains('linkedin', Plan::platformsFor('growth'));
    }
}
