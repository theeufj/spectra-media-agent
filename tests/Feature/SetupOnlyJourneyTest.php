<?php

namespace Tests\Feature;

use App\Models\BrandGuideline;
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
            ['site_scan', 'brand_confirmed', 'payment', 'build', 'handover'],
            $keys,
        );

        // The account is created on payment, so nothing that needs it may be
        // asked for first.
        $this->assertLessThan(
            array_search('build', $keys, true),
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

    public function test_the_build_is_reported_rather_than_assigned(): void
    {
        [$user, $customer] = $this->setupOnlyCustomer(['setup_fee_paid_at' => now()]);

        $steps = collect(
            $this->actingAs($user)
                ->withSession(['active_customer_id' => $customer->id])
                ->getJson('/api/setup-progress')
                ->json('steps')
        )->keyBy('key');

        // No action on our own work: a button here is an instruction, and the
        // whole proposition is that they are not doing this.
        $this->assertNull($steps['build']['action_url']);
        $this->assertNull($steps['handover']['action_url']);
        $this->assertSame('in_progress', $steps['build']['status']);
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
