<?php

namespace Tests\Feature\Controllers;

use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * @group integration
 * @group controllers
 */
class KnowledgeBaseControllerTest extends TestCase
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
        $response = $this->actingAs($this->user)->get(route('knowledge-base.index'));

        $response->assertStatus(200);
    }

    public function test_create_returns_200(): void
    {
        $response = $this->actingAs($this->user)->get(route('knowledge-base.create'));

        $response->assertStatus(200);
    }

    public function test_store_creates_knowledge_base_entry(): void
    {
        $response = $this->actingAs($this->user)->post(route('knowledge-base.store'), [
            'title'   => 'Test Knowledge Base Entry',
            'content' => 'This is the content of the test entry.',
            'type'    => 'company_info',
        ]);

        $response->assertRedirect();

        $this->assertDatabaseHas('knowledge_bases', [
            'title'       => 'Test Knowledge Base Entry',
            'customer_id' => $this->customer->id,
        ]);
    }

    public function test_destroy_removes_entry(): void
    {
        $kb = KnowledgeBase::factory()->create([
            'customer_id' => $this->customer->id,
        ]);

        $response = $this->actingAs($this->user)
            ->delete(route('knowledge-base.destroy', $kb));

        $response->assertRedirect();
        $this->assertDatabaseMissing('knowledge_bases', ['id' => $kb->id]);
    }

    public function test_search_returns_matching_entries(): void
    {
        KnowledgeBase::factory()->create([
            'customer_id' => $this->customer->id,
            'title'       => 'Google Ads Best Practices',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson(route('knowledge-base.search'), ['query' => 'Google Ads']);

        $response->assertStatus(200)
                 ->assertJsonStructure(['results']);
    }

    public function test_requires_auth(): void
    {
        $response = $this->get(route('knowledge-base.index'));

        $response->assertRedirect(route('login'));
    }
}
