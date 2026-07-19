<?php

namespace Tests\Feature\Controllers;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * @group integration
 * @group controllers
 */
class CampaignControllerTest extends TestCase
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

    public function test_index_lists_campaigns_for_authenticated_user(): void
    {
        Campaign::factory()->count(3)->create(['customer_id' => $this->customer->id]);

        $response = $this->actingAs($this->user)->get(route('campaigns.index'));

        $response->assertStatus(200);
    }

    public function test_index_requires_authentication(): void
    {
        $response = $this->get(route('campaigns.index'));

        $response->assertRedirect(route('login'));
    }

    public function test_create_returns_campaign_wizard(): void
    {
        $response = $this->actingAs($this->user)->get('/campaigns/create');

        $response->assertStatus(200);
    }

    public function test_show_returns_campaign_detail_for_owner(): void
    {
        $campaign = Campaign::factory()->create(['customer_id' => $this->customer->id]);

        $response = $this->actingAs($this->user)->get("/campaigns/{$campaign->id}");

        $response->assertStatus(200);
    }

    public function test_deployment_status_returns_json_for_owner(): void
    {
        $campaign = Campaign::factory()->create(['customer_id' => $this->customer->id]);

        $response = $this->actingAs($this->user)
            ->get("/campaigns/{$campaign->id}/deployment-status");

        $response->assertStatus(200);
    }

    public function test_performance_endpoint_returns_200(): void
    {
        $campaign = Campaign::factory()->create(['customer_id' => $this->customer->id]);

        $response = $this->actingAs($this->user)
            ->get("/campaigns/{$campaign->id}/performance");

        $response->assertStatus(200);
    }

    public function test_destroy_deletes_campaign_owned_by_user(): void
    {
        $campaign = Campaign::factory()->create([
            'customer_id'            => $this->customer->id,
            'google_ads_campaign_id' => null,
            'status'                 => 'draft',
        ]);

        $response = $this->actingAs($this->user)
            ->delete("/campaigns/{$campaign->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('campaigns', ['id' => $campaign->id]);
    }

    public function test_api_show_returns_json_campaign_data(): void
    {
        $campaign = Campaign::factory()->create(['customer_id' => $this->customer->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/campaigns/{$campaign->id}/api");

        $response->assertStatus(200)
                 ->assertJsonStructure(['id']);
    }
}
