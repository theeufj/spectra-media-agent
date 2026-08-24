<?php

namespace Tests\Feature\Admin;

use App\Models\BrandGuideline;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\ImageCollateral;
use App\Models\Role;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The admin workspace review page: brand guidelines, strategies, and
 * creative for one customer, in one place, for monitoring.
 */
class CustomerWorkspaceTest extends TestCase
{
    use DatabaseTransactions;

    private function admin(): User
    {
        $role = Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin']));
        $admin = User::factory()->create(['email_verified_at' => now()]);
        $admin->roles()->attach($role);

        return $admin;
    }

    public function test_admin_sees_guidelines_strategies_and_imagery(): void
    {
        $this->withoutVite();

        $customer = Customer::factory()->create(['website' => 'https://example.com']);

        BrandGuideline::create([
            'customer_id' => $customer->id,
            'brand_voice' => ['description' => 'Confident and plain-spoken'],
            'tone_attributes' => ['direct', 'warm'],
            'color_palette' => ['#FF5733', '#1A1A2E'],
            'typography' => [],
            'visual_style' => [],
            'messaging_themes' => ['Quality first'],
            'unique_selling_propositions' => ['Handmade locally'],
            'target_audience' => ['primary' => 'Homeowners'],
            'brand_personality' => [],
            'extraction_quality_score' => 8,
            'extracted_at' => now(),
        ]);

        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'name' => 'Spring Launch']);
        $strategy = Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'platform' => 'Google Ads',
            'signed_off_at' => now(),
            'deployment_status' => 'verified',
        ]);

        ImageCollateral::create([
            'campaign_id' => $campaign->id,
            'strategy_id' => $strategy->id,
            'platform' => 'google',
            's3_path' => 'collateral/images/a.jpg',
            'cloudfront_url' => 'https://cdn.example/a.jpg',
            'is_active' => true,
        ]);

        // A campaign-level wizard upload must appear too.
        ImageCollateral::create([
            'campaign_id' => $campaign->id,
            'strategy_id' => null,
            'platform' => 'google',
            's3_path' => 'collateral/images/b.jpg',
            'cloudfront_url' => 'https://cdn.example/b.jpg',
            'is_active' => true,
            'source' => 'uploaded',
        ]);

        \App\Models\KnowledgeBase::create([
            'user_id' => User::factory()->create()->id,
            'customer_id' => $customer->id,
            'url' => 'https://example.com/about',
            'content' => str_repeat('About the business. ', 40),
        ]);

        \App\Models\Keyword::create([
            'customer_id' => $customer->id,
            'keyword_text' => 'buy leather boots',
            'match_type' => 'PHRASE',
            'status' => 'active',
        ]);

        $this->actingAs($this->admin())
            ->get(route('admin.customers.workspace', $customer))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/CustomerWorkspace')
                ->where('brandGuideline.extraction_quality_score', 8)
                ->has('campaigns', 1)
                ->has('campaigns.0.strategies', 1)
                ->has('campaigns.0.strategies.0.image_collaterals', 1)
                ->has('campaigns.0.image_collaterals', 1)
                ->has('knowledgePages', 1)
                ->where('knowledgePages.0.url', 'https://example.com/about')
                ->has('keywords', 1)
                ->has('personas')
                ->has('creativeBriefs')
                ->has('proposals')
                ->has('products')
                ->has('knowledge.pages'));
    }

    public function test_customer_rows_carry_coverage_lights(): void
    {
        $this->withoutVite();

        $customer = Customer::factory()->create(['website' => 'https://example.com']);
        Campaign::factory()->create(['customer_id' => $customer->id]);

        $this->actingAs($this->admin())
            ->get(route('admin.customers.index'))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Admin/Customers')
                ->where('customers.0.coverage.brand', 'red')
                // A campaign with no signed-off strategy is partial, not done.
                ->where('customers.0.coverage.campaigns', 'orange')
                ->where('customers.0.coverage.creative', 'red'));
    }

    public function test_regular_users_cannot_open_the_workspace(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('admin.customers.workspace', $customer))
            ->assertStatus(403);
    }
}
