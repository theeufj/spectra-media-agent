<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Plan;
use App\Models\Recommendation;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StrategyNavigationTest extends TestCase
{
    use DatabaseTransactions;

    private function userOnPlan(string $slug): array
    {
        $plan = Plan::firstOrCreate(['slug' => $slug], ['name' => ucfirst($slug)]);
        $user = User::factory()->create(['assigned_plan_id' => $plan->id]);
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        return [$user, $customer];
    }

    public function test_proposals_navigation_is_only_shared_with_agency_users(): void
    {
        [$growthUser, $growthCustomer] = $this->userOnPlan('growth');
        $this->actingAs($growthUser)
            ->withSession(['active_customer_id' => $growthCustomer->id])
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.user.can_view_proposals', false));

        $adminRole = Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin']));
        $growthUser->roles()->attach($adminRole);
        $this->actingAs($growthUser)
            ->withSession(['active_customer_id' => $growthCustomer->id])
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.user.can_view_proposals', false));

        [$agencyUser, $agencyCustomer] = $this->userOnPlan('agency');
        $this->actingAs($agencyUser)
            ->withSession(['active_customer_id' => $agencyCustomer->id])
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.user.can_view_proposals', true));

        $growthCustomer->update(['plan_id' => Plan::where('slug', 'agency')->firstOrFail()->id]);
        $this->actingAs($growthUser)
            ->withSession(['active_customer_id' => $growthCustomer->id])
            ->get(route('dashboard'))
            ->assertInertia(fn (Assert $page) => $page->where('auth.user.can_view_proposals', true));
    }

    public function test_activity_and_competitors_uses_the_selected_customer(): void
    {
        [$user, $firstCustomer] = $this->userOnPlan('growth');
        $secondCustomer = Customer::factory()->create([
            'competitive_strategy' => ['summary' => 'Selected account only'],
            'competitive_strategy_updated_at' => now(),
        ]);
        $user->customers()->attach($secondCustomer->id, ['role' => 'owner']);
        $firstCustomer->update(['competitive_strategy' => ['summary' => 'Wrong account']]);

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $secondCustomer->id])
            ->get(route('strategy.war-room'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canAccessWarRoom', true)
                ->where('competitiveStrategy.summary', 'Selected account only'));
    }

    public function test_activity_queue_shows_one_pending_decision_per_campaign_action_and_target(): void
    {
        [$user, $customer] = $this->userOnPlan('growth');
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        foreach (['Old suggestion', 'New suggestion'] as $rationale) {
            Recommendation::create([
                'campaign_id' => $campaign->id,
                'type' => 'AD_COPY_OPTIMIZATION',
                'target_entity' => null,
                'rationale' => $rationale,
                'status' => 'pending',
                'requires_approval' => false,
            ]);
        }

        $this->actingAs($user)
            ->withSession(['active_customer_id' => $customer->id])
            ->get(route('strategy.war-room'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('recommendations', 1)
                ->where('recommendations.0.rationale', 'New suggestion')
                ->where('recommendations.0.campaign_uuid', $campaign->uuid));
    }
}
