<?php

namespace Tests\Feature\Admin;

use App\Models\AiCost;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiCostControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $role = Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin']));

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach($role);

        $this->regularUser = User::factory()->create();
    }

    // ── Access control ────────────────────────────────────────────────────────

    public function test_admin_can_access_ai_costs_page(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.ai-costs.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page->component('Admin/AiCosts'));
    }

    public function test_regular_user_cannot_access_ai_costs_page(): void
    {
        $response = $this->actingAs($this->regularUser)->get(route('admin.ai-costs.index'));

        $response->assertStatus(403);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $response = $this->get(route('admin.ai-costs.index'));

        $response->assertRedirect(route('login'));
    }

    // ── Summary data ──────────────────────────────────────────────────────────

    public function test_returns_correct_summary_totals(): void
    {
        AiCost::factory()->create(['cost' => 1.50, 'input_tokens' => 1000, 'output_tokens' => 500, 'cached_tokens' => 100]);
        AiCost::factory()->create(['cost' => 0.75, 'input_tokens' => 500,  'output_tokens' => 200, 'cached_tokens' => 0]);

        $response = $this->actingAs($this->admin)->get(route('admin.ai-costs.index'));

        $response->assertInertia(fn ($page) => $page
            ->component('Admin/AiCosts')
            ->where('summary.total_cost', 2.25)   // non-whole, stays float after JSON round-trip
            ->where('summary.total_calls', 2)
            ->where('summary.total_input_tokens', 1500)
            ->where('summary.total_output_tokens', 700)
            ->where('summary.total_cached_tokens', 100)
        );
    }

    public function test_excludes_records_outside_period(): void
    {
        AiCost::factory()->create(['cost' => 5.00, 'created_at' => now()->subDays(60)]);
        AiCost::factory()->create(['cost' => 1.00, 'created_at' => now()->subDays(5)]);

        $response = $this->actingAs($this->admin)->get(route('admin.ai-costs.index', ['period' => '30']));

        $response->assertInertia(fn ($page) => $page
            ->where('summary.total_cost', 1)   // whole number → JSON int after round-trip
            ->where('summary.total_calls', 1)
        );
    }

    public function test_period_param_filters_correctly(): void
    {
        AiCost::factory()->create(['cost' => 2.00, 'created_at' => now()->subDays(3)]);
        AiCost::factory()->create(['cost' => 1.00, 'created_at' => now()->subDays(10)]);

        $response = $this->actingAs($this->admin)->get(route('admin.ai-costs.index', ['period' => '7']));

        $response->assertInertia(fn ($page) => $page->where('summary.total_calls', 1));
    }

    // ── By model ──────────────────────────────────────────────────────────────

    public function test_groups_costs_by_model(): void
    {
        AiCost::factory()->create(['model' => 'gemini-3.5-flash',    'cost' => 1.00]);
        AiCost::factory()->create(['model' => 'gemini-3.5-flash',    'cost' => 0.50]);
        AiCost::factory()->create(['model' => 'gemini-3.1-pro-preview', 'cost' => 3.00]);

        $response = $this->actingAs($this->admin)->get(route('admin.ai-costs.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('byModel', 2)
            ->where('byModel.0.model', 'gemini-3.1-pro-preview')
            ->where('byModel.0.total_cost', 3)     // whole → JSON int
            ->where('byModel.0.calls', 1)
            ->where('byModel.1.model', 'gemini-3.5-flash')
            ->where('byModel.1.total_cost', 1.5)   // non-whole → stays float
            ->where('byModel.1.calls', 2)
        );
    }

    public function test_model_percentage_sums_to_100(): void
    {
        AiCost::factory()->create(['model' => 'gemini-3.5-flash', 'cost' => 3.00]);
        AiCost::factory()->create(['model' => 'gemini-2.5-flash-lite', 'cost' => 1.00]);

        $response = $this->actingAs($this->admin)->get(route('admin.ai-costs.index'));

        $response->assertInertia(fn ($page) => $page
            ->where('byModel.0.pct', 75)   // whole → JSON int
            ->where('byModel.1.pct', 25)
        );
    }

    // ── By operation ──────────────────────────────────────────────────────────

    public function test_groups_costs_by_operation_and_task_type(): void
    {
        AiCost::factory()->create(['operation' => 'generateContent', 'task_type' => 'creative',    'cost' => 2.00]);
        AiCost::factory()->create(['operation' => 'generateContent', 'task_type' => 'creative',    'cost' => 1.00]);
        AiCost::factory()->create(['operation' => 'generateImage',   'task_type' => null,          'cost' => 0.50]);

        $response = $this->actingAs($this->admin)->get(route('admin.ai-costs.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('byOperation', 2)
            ->where('byOperation.0.operation', 'generateContent')
            ->where('byOperation.0.task_type', 'creative')
            ->where('byOperation.0.calls', 2)
            ->where('byOperation.1.operation', 'generateImage')
        );
    }

    // ── Per customer ──────────────────────────────────────────────────────────

    public function test_groups_costs_by_customer(): void
    {
        $customer = Customer::factory()->create(['name' => 'Acme Corp']);
        AiCost::factory()->create(['customer_id' => $customer->id, 'cost' => 4.00]);
        AiCost::factory()->create(['customer_id' => $customer->id, 'cost' => 1.00]);
        AiCost::factory()->create(['customer_id' => null,          'cost' => 0.50]); // unattributed

        $response = $this->actingAs($this->admin)->get(route('admin.ai-costs.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('byCustomer', 1)
            ->where('byCustomer.0.customer_name', 'Acme Corp')
            ->where('byCustomer.0.total_cost', 5)   // whole → JSON int
            ->where('byCustomer.0.calls', 2)
            ->where('unattributed.calls', 1)
            ->where('unattributed.cost', 0.5)       // non-whole → stays float
        );
    }

    // ── Daily trend ───────────────────────────────────────────────────────────

    public function test_returns_daily_cost_series(): void
    {
        AiCost::factory()->create(['cost' => 1.00, 'created_at' => now()->subDays(2)->startOfDay()]);
        AiCost::factory()->create(['cost' => 2.00, 'created_at' => now()->subDays(1)->startOfDay()]);

        $response = $this->actingAs($this->admin)->get(route('admin.ai-costs.index'));

        $response->assertInertia(fn ($page) => $page->has('daily', 2));
    }

    // ── Fallbacks ─────────────────────────────────────────────────────────────

    public function test_surfaces_fallback_events(): void
    {
        AiCost::factory()->create([
            'model'    => 'gemini-2.5-flash',
            'cost'     => 0.50,
            'metadata' => ['fallback_from' => 'gemini-3.1-pro-preview'],
        ]);
        AiCost::factory()->create(['metadata' => null, 'cost' => 1.00]); // normal call

        $response = $this->actingAs($this->admin)->get(route('admin.ai-costs.index'));

        $response->assertInertia(fn ($page) => $page
            ->has('fallbacks', 1)
            ->where('fallbacks.0.chain', 'gemini-3.1-pro-preview → gemini-2.5-flash')
            ->where('fallbacks.0.count', 1)
        );
    }

    // ── Empty state ───────────────────────────────────────────────────────────

    public function test_handles_empty_data_gracefully(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.ai-costs.index'));

        $response->assertStatus(200);
        $response->assertInertia(fn ($page) => $page
            ->where('summary.total_cost', 0)   // whole → JSON int
            ->where('summary.total_calls', 0)
            ->has('byModel', 0)
            ->has('byOperation', 0)
            ->has('byCustomer', 0)
            ->has('daily', 0)
            ->has('fallbacks', 0)
        );
    }
}
