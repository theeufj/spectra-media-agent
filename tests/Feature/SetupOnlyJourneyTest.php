<?php

namespace Tests\Feature;

use App\Models\BrandGuideline;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The one-time setup is a different journey, not the managed one with a
 * different payment step.
 *
 * A US$999 customer buys their way out of Google Ads: we build the account, the
 * conversion tracking, the campaign and the ads, hand it over, and they spend
 * what they want. The checklist asked them to do four of those themselves — and
 * in an order that cannot work, because the Google Ads account is created when
 * the fee is paid and the campaign wizard needs that account to exist. So the
 * wizard told them "No platforms available. Please contact your admin", which is
 * nobody's job.
 *
 * Paying is the step that unlocks everything, so it comes before everything that
 * needs it.
 */
class SetupOnlyJourneyTest extends TestCase
{
    use DatabaseTransactions;

    /** @return array{User, Customer} */
    private function setupOnlyCustomer(array $attributes = []): array
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'user'])));

        $customer = Customer::factory()->create(array_merge([
            'service_type' => 'setup_only',
            'is_sandbox' => false,
        ], $attributes));
        $customer->users()->attach($user->id, ['role' => 'owner']);

        return [$user, $customer];
    }

    /** @return list<string> */
    private function stepKeys(User $user, Customer $customer): array
    {
        return collect(
            $this->actingAs($user)
                ->withSession(['active_customer_id' => $customer->id])
                ->getJson('/api/setup-progress')
                ->json('steps')
        )->pluck('key')->all();
    }

    public function test_paying_comes_before_everything_that_needs_the_account(): void
    {
        [$user, $customer] = $this->setupOnlyCustomer();

        $keys = $this->stepKeys($user, $customer);

        $this->assertSame(
            ['site_scan', 'brand_confirmed', 'payment', 'review_ads', 'handover'],
            $keys,
        );

        // The account is created on payment, so nothing that needs it may be
        // asked for first.
        $this->assertLessThan(
            array_search('review_ads', $keys, true),
            array_search('payment', $keys, true),
        );
    }

    public function test_it_never_asks_them_to_do_the_work_they_paid_us_for(): void
    {
        [$user, $customer] = $this->setupOnlyCustomer();

        $keys = $this->stepKeys($user, $customer);

        foreach (['first_campaign', 'budget_confirmed', 'conversion_tracking'] as $ours) {
            $this->assertNotContains(
                $ours,
                $keys,
                "a US$999 customer was asked to do '{$ours}' themselves",
            );
        }
    }

    public function test_the_ads_are_theirs_to_approve_and_the_handover_is_not(): void
    {
        [$user, $customer] = $this->setupOnlyCustomer(['setup_fee_paid_at' => now()]);

        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        $steps = collect(
            $this->actingAs($user)
                ->withSession(['active_customer_id' => $customer->id])
                ->getJson('/api/setup-progress')
                ->json('steps')
        )->keyBy('key');

        /*
           We write the ads; they press Create. That is the one decision in the
           build that is theirs, and the step has to be reachable or the button
           is somewhere they have no reason to look.
        */
        $this->assertSame(
            route('campaigns.show', $campaign),
            $steps['review_ads']['action_url'],
        );
        $this->assertSame('Review', $steps['review_ads']['action_text']);

        // The handover is ours and stays ours: a button here is an instruction
        // to do the thing they paid us to do.
        $this->assertNull($steps['handover']['action_url']);
    }

    public function test_there_is_nothing_to_review_before_the_account_exists(): void
    {
        [$user, $customer] = $this->setupOnlyCustomer(['setup_fee_paid_at' => now()]);

        $steps = collect(
            $this->actingAs($user)
                ->withSession(['active_customer_id' => $customer->id])
                ->getJson('/api/setup-progress')
                ->json('steps')
        )->keyBy('key');

        // Paid, but the build has not produced a campaign yet. Linking to a
        // campaign that does not exist is a 404 at the end of the funnel.
        $this->assertNull($steps['review_ads']['action_url']);
        $this->assertSame('in_progress', $steps['review_ads']['status']);
    }

    public function test_they_are_never_shown_the_campaign_wizard(): void
    {
        [$user, $customer] = $this->setupOnlyCustomer();

        /*
           Reported from production: a US$999 customer who had not paid yet
           landed in "Create New Campaign" and was told "No ad platform
           sub-accounts are set up yet. Contact us to get your account
           configured." Their account is created on payment, automatically, and
           being told to contact us is the exact intimidation the fee removes.
        */
        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->get(route('campaigns.wizard'))
            ->assertRedirect(route('subscription.pricing', absolute: false));
    }

    public function test_a_paid_customer_is_sent_to_the_campaign_we_built(): void
    {
        [$user, $customer] = $this->setupOnlyCustomer(['setup_fee_paid_at' => now()]);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        // Not the pricing page — they have paid. The wizard's job for them is
        // already done, so the URL resolves to the work itself.
        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->get(route('campaigns.wizard'))
            ->assertRedirect(route('campaigns.show', $campaign, absolute: false));
    }

    public function test_a_paid_customer_mid_build_is_told_to_wait_not_to_build(): void
    {
        [$user, $customer] = $this->setupOnlyCustomer(['setup_fee_paid_at' => now()]);

        // Paid, but the campaign does not exist yet. Anywhere but the wizard.
        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->get(route('campaigns.wizard'))
            ->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_a_managed_customer_still_reaches_the_wizard(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'user'])));
        $managed = Customer::factory()->create(['service_type' => 'managed', 'is_sandbox' => false]);
        $managed->users()->attach($user->id, ['role' => 'owner']);

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $managed->id])
            ->get(route('campaigns.wizard'))
            ->assertOk();
    }

    public function test_the_managed_journey_is_untouched(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'user'])));
        $managed = Customer::factory()->create(['service_type' => 'managed', 'is_sandbox' => false]);
        $managed->users()->attach($user->id, ['role' => 'owner']);

        $keys = $this->stepKeys($user, $managed);

        $this->assertContains('first_campaign', $keys);
        $this->assertContains('budget_confirmed', $keys);
        $this->assertNotContains('handover', $keys);
    }

    public function test_confirming_the_brand_is_the_one_thing_only_they_can_do(): void
    {
        [$user, $customer] = $this->setupOnlyCustomer();
        // No factory for this model; the columns below are NOT NULL.
        BrandGuideline::create([
            'customer_id' => $customer->id,
            'brand_voice' => ['primary_tone' => 'direct'],
            'tone_attributes' => ['direct'],
            'writing_patterns' => [],
            'color_palette' => ['primary' => '#0077CC'],
            'typography' => [],
            'visual_style' => [],
            'messaging_themes' => ['Launch in minutes'],
            'unique_selling_propositions' => ['No-code store builder'],
            'target_audience' => ['primary' => 'First-time sellers'],
            'brand_personality' => ['practical'],
            'extraction_quality_score' => 96,
            'user_verified' => true,
            'extracted_at' => now(),
        ]);

        $steps = collect(
            $this->actingAs($user)
                ->withSession(['active_customer_id' => $customer->id])
                ->getJson('/api/setup-progress')
                ->json('steps')
        )->keyBy('key');

        $this->assertTrue($steps['brand_confirmed']['completed']);
    }
}
