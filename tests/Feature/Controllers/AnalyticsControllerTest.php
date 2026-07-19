<?php

namespace Tests\Feature\Controllers;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * @group integration
 * @group controllers
 */
class AnalyticsControllerTest extends TestCase
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

    public function test_analytics_index_returns_200(): void
    {
        $response = $this->actingAs($this->user)->get('/analytics');

        $response->assertStatus(200);
    }

    public function test_cross_platform_endpoint_returns_200(): void
    {
        $response = $this->actingAs($this->user)->get('/analytics/cross-platform');

        $response->assertStatus(200);
    }

    public function test_attribution_endpoint_returns_200(): void
    {
        $response = $this->actingAs($this->user)->get('/analytics/attribution');

        $response->assertStatus(200);
    }

    public function test_requires_authentication(): void
    {
        $response = $this->get('/analytics');

        $response->assertRedirect(route('login'));
    }
}
