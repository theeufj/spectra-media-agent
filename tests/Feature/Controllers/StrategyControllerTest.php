<?php

namespace Tests\Feature\Controllers;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * @group integration
 * @group controllers
 */
class StrategyControllerTest extends TestCase
{
    use DatabaseTransactions;

    protected User $user;
    protected Customer $customer;
    protected Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('RUN_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_INTEGRATION_TESTS=true to run.');
        }

        $this->user     = User::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->customer->users()->attach($this->user->id, ['role' => 'owner']);
        $this->campaign = Campaign::factory()->create(['customer_id' => $this->customer->id]);
    }

    public function test_create_returns_200(): void
    {
        $response = $this->actingAs($this->user)
            ->get("/campaigns/{$this->campaign->id}/strategies/create");

        $response->assertStatus(200);
    }

    public function test_edit_returns_200_for_existing_strategy(): void
    {
        $strategy = Strategy::factory()->create(['campaign_id' => $this->campaign->id]);

        $response = $this->actingAs($this->user)
            ->get("/campaigns/{$this->campaign->id}/strategies/{$strategy->id}/edit");

        $response->assertStatus(200);
    }

    public function test_approve_updates_strategy_status(): void
    {
        $strategy = Strategy::factory()->create([
            'campaign_id' => $this->campaign->id,
            'status'      => 'draft',
        ]);

        $response = $this->actingAs($this->user)
            ->post("/campaigns/{$this->campaign->id}/strategies/{$strategy->id}/approve");

        $response->assertRedirect();
        $this->assertDatabaseHas('strategies', [
            'id'     => $strategy->id,
            'status' => 'approved',
        ]);
    }

    public function test_destroy_deletes_strategy(): void
    {
        $strategy = Strategy::factory()->create(['campaign_id' => $this->campaign->id]);

        $response = $this->actingAs($this->user)
            ->delete("/campaigns/{$this->campaign->id}/strategies/{$strategy->id}");

        $response->assertRedirect();
        $this->assertDatabaseMissing('strategies', ['id' => $strategy->id]);
    }

    public function test_requires_authentication(): void
    {
        $strategy = Strategy::factory()->create(['campaign_id' => $this->campaign->id]);

        $response = $this->get("/campaigns/{$this->campaign->id}/strategies/{$strategy->id}/edit");

        $response->assertRedirect(route('login'));
    }
}
