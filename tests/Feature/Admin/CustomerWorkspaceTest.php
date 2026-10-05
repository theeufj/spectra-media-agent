<?php

namespace Tests\Feature\Admin;

use App\Models\BrandGuideline;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\ImageCollateral;
use App\Models\KnowledgeBase;
use App\Models\KnowledgeBaseChunk;
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
                // A campaign with no signed-off strategy is partial, not done.
                ->where('customers', fn ($rows) => collect($rows)->contains(fn ($row) => $row['id'] === $customer->id
                    && $row['coverage']['brand'] === 'red'
                    && $row['coverage']['campaigns'] === 'orange'
                    && $row['coverage']['creative'] === 'red')));
    }

    public function test_regular_users_cannot_open_the_workspace(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create(['email_verified_at' => now()]);

        $this->actingAs($user)
            ->get(route('admin.customers.workspace', $customer))
            ->assertStatus(403);
    }

    public function test_empty_sources_do_not_claim_complete_knowledge_coverage(): void
    {
        $this->withoutVite();
        $customer = Customer::factory()->create();
        $uploader = User::factory()->create();
        for ($i = 0; $i < 5; $i++) {
            KnowledgeBase::create([
                'customer_id' => $customer->id, 'user_id' => $uploader->id,
                'url' => "https://example.com/{$i}", 'content' => '', 'processing_status' => 'failed',
            ]);
        }
        $this->actingAs($this->admin())->get(route('admin.customers.index'))
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('customers', fn ($rows) => collect($rows)->contains(fn ($row) => $row['id'] === $customer->id
                && $row['coverage']['knowledge'] === 'red')));
    }

    public function test_health_counts_only_current_included_passages_and_actual_fetch_times(): void
    {
        $customer = Customer::factory()->create();
        $uploader = User::factory()->create();
        $fetched = now()->subDays(2)->startOfSecond();
        $indexed = now()->subDay()->startOfSecond();
        $source = KnowledgeBase::create([
            'customer_id' => $customer->id, 'user_id' => $uploader->id,
            'url' => 'https://example.com/current', 'content' => 'Readable content',
            'source_version' => 2, 'processing_status' => 'needs_attention',
            'fetched_at' => $fetched, 'indexed_at' => $indexed,
        ]);
        foreach ([1, 2] as $version) {
            KnowledgeBaseChunk::create([
                'customer_id' => $customer->id, 'knowledge_base_id' => $source->id,
                'source_version' => $version, 'position' => 0, 'content' => 'A passage',
                'embedding' => array_fill(0, 3072, 0.1),
                'embedding_model' => $version === 2 ? 'different-space' : config('ai.models.embedding'),
            ]);
        }
        KnowledgeBase::create([
            'customer_id' => $customer->id, 'user_id' => $uploader->id,
            'url' => 'https://example.com/excluded', 'content' => 'Excluded content',
            'processing_status' => 'ready', 'excluded_at' => now(), 'fetched_at' => now(),
        ]);
        $this->actingAs($this->admin())->getJson(route('admin.customers.knowledge-status', $customer))
            ->assertOk()->assertJsonPath('health.total', 2)->assertJsonPath('health.selected', 1)
            ->assertJsonPath('health.readable', 1)->assertJsonPath('health.ready', 0)
            ->assertJsonPath('health.needs_attention', 1)->assertJsonPath('health.passages', 1)
            ->assertJsonPath('health.indexed_passages', 1)->assertJsonPath('health.mismatched_passages', 1)
            ->assertJsonPath('health.primary_passages', 0)
            ->assertJsonPath('health.fetched_at', $fetched->toIso8601String())
            ->assertJsonPath('health.indexed_at', $indexed->toIso8601String());
    }
}
