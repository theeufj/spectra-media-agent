<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\ImageCollateral;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * A customer is a business; several people work on it; the plan belongs to the
 * business.
 *
 * Entitlements are consumed per customer by jobs that have no acting user, so
 * before `customers.plan_id` each one had to guess which person spoke for the
 * account — and the codebase held four different guesses, three of which did
 * not even prefer an owner:
 *
 *   DeployCampaign      users()->wherePivot('role','owner')->first() ?? users()->first()
 *   GenerateStrategy    users()->first()
 *   OptimizeCampaigns   users()?->first()
 *   GenerateImage       users()->first()
 *
 * On an account with several people and no ordering, which platforms a campaign
 * deployed to was decided by database row order. These pin that it is now
 * decided by the account.
 */
class CustomerPlanOwnershipTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // The test database is migrated but not seeded, and every assertion here
        // is about which plan governs.
        $this->seed(\Database\Seeders\PlanSeeder::class);
    }

    private function plan(string $slug): Plan
    {
        return Plan::where('slug', $slug)->firstOrFail();
    }

    public function test_the_account_plan_governs_regardless_of_who_is_attached(): void
    {
        $customer = Customer::factory()->create(['plan_id' => $this->plan('growth')->id]);

        // Two people on wildly different personal plans. Neither decides
        // anything; the account is on Growth, so all four platforms are open.
        $customer->users()->attach(
            User::factory()->create(['assigned_plan_id' => $this->plan('free')->id])->id,
            ['role' => 'owner'],
        );
        $customer->users()->attach(
            User::factory()->create(['assigned_plan_id' => $this->plan('starter')->id])->id,
            ['role' => 'admin'],
        );

        $this->assertSame(
            ['google', 'facebook', 'microsoft', 'linkedin'],
            $customer->allowedPlatforms(),
        );
    }

    public function test_a_free_account_is_google_only_even_when_a_growth_user_is_attached(): void
    {
        $customer = Customer::factory()->create(['plan_id' => $this->plan('free')->id]);
        $customer->users()->attach(
            User::factory()->create(['assigned_plan_id' => $this->plan('growth')->id])->id,
            ['role' => 'owner'],
        );

        // The old code read the attached user and would have opened all four.
        $this->assertSame(['google'], $customer->allowedPlatforms());
    }

    public function test_a_starter_account_gets_the_single_platform_it_chose(): void
    {
        $customer = Customer::factory()->create([
            'plan_id' => $this->plan('starter')->id,
            'starter_platform' => 'facebook',
        ]);

        $this->assertSame(['facebook'], $customer->allowedPlatforms());
    }

    public function test_an_account_with_nobody_attached_still_has_its_plan(): void
    {
        // Onboarding writes the customer before anyone is on the pivot. Three
        // entitlement checks used to return false outright in this window —
        // CRO audits, brand extraction and image collateral — so the account
        // could do nothing at exactly the point it needed to do everything.
        $customer = Customer::factory()->create(['plan_id' => $this->plan('growth')->id]);

        $this->assertCount(0, $customer->users);
        $this->assertSame('growth', $customer->resolvePlan()->slug);
        $this->assertTrue($customer->isOnPaidPlan());
    }

    public function test_a_customer_with_no_plan_set_falls_back_to_free(): void
    {
        $customer = Customer::factory()->create(['plan_id' => null]);

        $this->assertSame('free', $customer->resolvePlan()->slug);
        $this->assertFalse($customer->isOnPaidPlan());
        $this->assertSame(['google'], $customer->allowedPlatforms());
    }

    public function test_a_paid_setup_only_customer_counts_as_paid_without_a_subscription(): void
    {
        // One-time setup buys deploy access outright. The old check asked
        // whether a user held a Stripe subscription, which these never do.
        $customer = Customer::factory()->create([
            'plan_id' => null,
            'service_type' => 'setup_only',
            'setup_fee_paid_at' => now(),
        ]);

        $this->assertTrue($customer->isOnPaidPlan());
    }

    public function test_the_per_campaign_image_limit_follows_the_account(): void
    {
        $free = Campaign::factory()->create([
            'customer_id' => Customer::factory()->create(['plan_id' => $this->plan('free')->id])->id,
        ]);
        $paid = Campaign::factory()->create([
            'customer_id' => Customer::factory()->create(['plan_id' => $this->plan('growth')->id])->id,
        ]);

        $this->assertTrue(ImageCollateral::canGenerateForCampaign($free));
        $this->assertTrue(ImageCollateral::canGenerateForCampaign($paid));

        // No factory for this model; the limit only counts rows.
        foreach ([$free, $paid] as $campaign) {
            for ($i = 0; $i < ImageCollateral::FREE_TIER_LIMIT_PER_CAMPAIGN; $i++) {
                ImageCollateral::create([
                    'campaign_id' => $campaign->id,
                    'platform' => 'Google Ads (SEM)',
                    's3_path' => "collateral/{$campaign->id}/{$i}.png",
                    'cloudfront_url' => "https://cdn.example.test/{$campaign->id}/{$i}.png",
                ]);
            }
        }

        $this->assertFalse(ImageCollateral::canGenerateForCampaign($free));
        $this->assertTrue(ImageCollateral::canGenerateForCampaign($paid), 'a paid account has no per-campaign limit');
    }
}
