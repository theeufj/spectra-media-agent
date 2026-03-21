<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CampaignAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected User $otherUser;
    protected Customer $customer;
    protected Customer $otherCustomer;
    protected Campaign $campaign;
    protected Strategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->otherUser = User::factory()->create();

        $this->customer = Customer::factory()->create();
        $this->otherCustomer = Customer::factory()->create();

        $this->owner->customers()->attach($this->customer->id, ['role' => 'owner']);
        $this->otherUser->customers()->attach($this->otherCustomer->id, ['role' => 'owner']);

        $this->campaign = Campaign::factory()->create(['customer_id' => $this->customer->id]);
        $this->strategy = Strategy::factory()->create(['campaign_id' => $this->campaign->id]);
    }

    public function test_owner_can_view_own_campaign(): void
    {
        $response = $this->actingAs($this->owner)
            ->withSession(['active_customer_id' => $this->customer->id])
            ->get(route('campaigns.show', $this->campaign));

        $response->assertStatus(200);
    }

    public function test_other_user_cannot_view_campaign(): void
    {
        $response = $this->actingAs($this->otherUser)
            ->withSession(['active_customer_id' => $this->otherCustomer->id])
            ->get(route('campaigns.show', $this->campaign));

        $response->assertStatus(403);
    }

    public function test_owner_can_delete_own_campaign(): void
    {
        $response = $this->actingAs($this->owner)
            ->withSession(['active_customer_id' => $this->customer->id])
            ->delete(route('campaigns.destroy', $this->campaign));

        $response->assertRedirect(route('campaigns.index'));
        $this->assertDatabaseMissing('campaigns', ['id' => $this->campaign->id]);
    }

    public function test_other_user_cannot_delete_campaign(): void
    {
        $response = $this->actingAs($this->otherUser)
            ->withSession(['active_customer_id' => $this->otherCustomer->id])
            ->delete(route('campaigns.destroy', $this->campaign));

        $response->assertStatus(403);
        $this->assertDatabaseHas('campaigns', ['id' => $this->campaign->id]);
    }

    public function test_owner_can_update_own_strategy(): void
    {
        $response = $this->actingAs($this->owner)
            ->withSession(['active_customer_id' => $this->customer->id])
            ->put(route('strategies.update', $this->strategy), [
                'ad_copy_strategy' => 'Updated ad copy',
                'imagery_strategy' => 'Updated imagery',
                'video_strategy' => 'Updated video',
            ]);

        $response->assertRedirect();
        $this->assertEquals('Updated ad copy', $this->strategy->fresh()->ad_copy_strategy);
    }

    public function test_other_user_cannot_update_strategy(): void
    {
        $originalAdCopy = $this->strategy->ad_copy_strategy;

        $response = $this->actingAs($this->otherUser)
            ->withSession(['active_customer_id' => $this->otherCustomer->id])
            ->put(route('strategies.update', $this->strategy), [
                'ad_copy_strategy' => 'Hacked ad copy',
                'imagery_strategy' => 'Hacked imagery',
                'video_strategy' => 'Hacked video',
            ]);

        $response->assertStatus(403);
        $this->assertEquals($originalAdCopy, $this->strategy->fresh()->ad_copy_strategy);
    }

    public function test_unauthenticated_user_cannot_access_campaigns(): void
    {
        $response = $this->get(route('campaigns.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_store_campaign_requires_active_customer(): void
    {
        $response = $this->actingAs($this->owner)
            ->withSession(['active_customer_id' => $this->otherCustomer->id])
            ->post(route('campaigns.store'), [
                'name' => 'Test Campaign',
                'reason' => 'Test',
                'goals' => 'Test goals',
                'target_market' => 'Everyone',
                'voice' => 'Professional',
                'total_budget' => 1000,
                'start_date' => now()->format('Y-m-d'),
                'end_date' => now()->addDays(30)->format('Y-m-d'),
                'primary_kpi' => '4x ROAS',
                'product_focus' => 'Test product',
            ]);

        // Should be forbidden since the user doesn't belong to the other customer
        $response->assertStatus(403);
    }
}
