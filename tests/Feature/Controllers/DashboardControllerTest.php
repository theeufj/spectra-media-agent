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
class DashboardControllerTest extends TestCase
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

    public function test_dashboard_returns_200_for_authenticated_user(): void
    {
        $response = $this->actingAs($this->user)->get(route('dashboard'));

        $response->assertStatus(200);
    }

    public function test_dashboard_redirects_unauthenticated_user(): void
    {
        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('login'));
    }

    public function test_dashboard_contains_inertia_component(): void
    {
        $response = $this->actingAs($this->user)->get(route('dashboard'));

        $response->assertInertia(fn ($page) => $page->component('Dashboard'));
    }

    public function test_campaign_roi_returns_200_for_campaign_owner(): void
    {
        $campaign = Campaign::factory()->create(['customer_id' => $this->customer->id]);

        $response = $this->actingAs($this->user)
            ->get("/campaigns/{$campaign->id}/roi");

        $response->assertStatus(200);
    }
}
