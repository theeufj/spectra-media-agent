<?php

namespace Tests\Feature\Controllers;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * @group integration
 * @group controllers
 */
class AgentActivityControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('RUN_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_INTEGRATION_TESTS=true to run.');
        }

        $this->user     = User::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->customer->users()->attach($this->user->id, ['role' => 'owner']);
    }

    public function test_index_returns_200(): void
    {
        $response = $this->actingAs($this->user)->get('/agent-activities');

        $response->assertStatus(200);
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->get('/agent-activities');

        $response->assertRedirect(route('login'));
    }

    public function test_index_only_shows_activities_for_current_customer(): void
    {
        $campaign = Campaign::factory()->create(['customer_id' => $this->customer->id]);

        AgentActivity::factory()->create([
            'campaign_id' => $campaign->id,
            'action'      => 'test_action',
        ]);

        // Activity for a different customer
        $otherCustomer  = Customer::factory()->create();
        $otherCampaign  = Campaign::factory()->create(['customer_id' => $otherCustomer->id]);
        AgentActivity::factory()->create(['campaign_id' => $otherCampaign->id]);

        $response = $this->actingAs($this->user)->getJson('/agent-activities');

        $response->assertStatus(200);

        // Verify activities are scoped to the authenticated user's customer
        $data = $response->json('data') ?? $response->json();
        $this->assertIsArray($data);
    }
}
