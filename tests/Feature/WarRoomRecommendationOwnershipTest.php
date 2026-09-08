<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Recommendation;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Recommendation has no `customer_id`, so it carries neither BelongsToCustomer
 * nor CustomerScope: a route-model binding hands the controller whatever row
 * the URL names, from any tenant. The War Room approve/reject actions ran a
 * bare update() on it, so anyone could clear a competitor's pending queue.
 * Ownership now comes from RecommendationPolicy, via campaign->customer_id.
 */
class WarRoomRecommendationOwnershipTest extends TestCase
{
    use DatabaseTransactions;

    private function userWithCustomer(): array
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);

        return [$user, $customer];
    }

    private function pendingRecommendationFor(Customer $customer): Recommendation
    {
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        return Recommendation::create([
            'campaign_id' => $campaign->id,
            'type' => 'BUDGET',
            'target_entity' => ['campaign_id' => $campaign->id],
            'parameters' => ['daily_budget' => 80],
            'rationale' => 'Hitting its cap before noon every day.',
            'status' => 'pending',
            'requires_approval' => true,
        ]);
    }

    public function test_the_owner_can_approve_their_own_recommendation(): void
    {
        [$user, $customer] = $this->userWithCustomer();
        $recommendation = $this->pendingRecommendationFor($customer);

        $this->actingAs($user)
            ->post(route('strategy.war-room.recommendations.approve', $recommendation))
            ->assertRedirect();

        $this->assertSame('approved', $recommendation->fresh()->status);
    }

    public function test_another_tenant_cannot_approve_a_recommendation(): void
    {
        [, $victimCustomer] = $this->userWithCustomer();
        $recommendation = $this->pendingRecommendationFor($victimCustomer);

        [$attacker] = $this->userWithCustomer();

        $this->actingAs($attacker)
            ->post(route('strategy.war-room.recommendations.approve', $recommendation))
            ->assertForbidden();

        $this->assertSame('pending', $recommendation->fresh()->status);
    }

    public function test_another_tenant_cannot_reject_a_recommendation(): void
    {
        // Reject is the damaging direction: it empties the queue the owner's
        // strategy regeneration reads, and leaves no trace in their War Room.
        [, $victimCustomer] = $this->userWithCustomer();
        $recommendation = $this->pendingRecommendationFor($victimCustomer);

        [$attacker] = $this->userWithCustomer();

        $this->actingAs($attacker)
            ->post(route('strategy.war-room.recommendations.reject', $recommendation))
            ->assertForbidden();

        $this->assertSame('pending', $recommendation->fresh()->status);
    }
}
