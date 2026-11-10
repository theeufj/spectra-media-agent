<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\KnowledgeBase;
use App\Models\User;
use App\Services\GeminiService;
use App\Services\KnowledgeBase\KnowledgeBaseIndexer;
use App\Services\KnowledgeBaseSearchService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Pgvector\Laravel\Vector;
use Tests\TestCase;

class CloudflareEmbeddingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.models.embedding' => array_key_first(config('ai.cloudflare_embedding_dimensions')),
            'services.cloudflare.account_id' => 'example-account',
            'services.cloudflare.workers_ai_token' => 'test-token',
            'services.google.project_id' => null,
        ]);
    }

    public function test_cloudflare_indexes_text_and_searches_only_its_own_vectors(): void
    {
        $customer = Customer::factory()->create();
        $user = User::factory()->create();
        $indexer = app(KnowledgeBaseIndexer::class);
        $new = $indexer->prepare(KnowledgeBase::create([
            'customer_id' => $customer->id, 'user_id' => $user->id,
            'url' => 'note:new', 'title' => 'Returns', 'content' => '', 'source_type' => 'text',
        ]), 'Customers can send purchases back within 30 days.');
        $old = $indexer->prepare(KnowledgeBase::create([
            'customer_id' => $customer->id, 'user_id' => $user->id,
            'url' => 'note:old', 'title' => 'An older source', 'content' => '', 'source_type' => 'text',
        ]), 'Information stored before the provider change.');
        $old->chunks()->firstOrFail()->update([
            'embedding' => new Vector(array_fill(0, 3072, 1.0)), 'embedding_model' => 'previous-space',
        ]);

        Http::fake(['api.cloudflare.com/*' => Http::response([
            'success' => true, 'result' => ['data' => [array_fill(0, 1024, 0.1)]],
        ])]);

        $this->assertTrue($indexer->index($new, app(GeminiService::class), $new->source_version));
        $this->assertCount(1024, $new->fresh()->embedding->toArray());
        $this->assertSame(config('ai.models.embedding'), $new->fresh()->getAttribute('embedding_model'));

        $results = app(KnowledgeBaseSearchService::class)->passages($customer->id, 'What is the refund window?');
        $this->assertSame([$new->id], array_column($results, 'kb_id'));
        $this->assertSame('semantic', $results[0]['method']);

        Http::assertSent(fn (Request $request) => $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->data() === ['text' => ['Customers can send purchases back within 30 days.']]);
        Http::assertSent(fn (Request $request) => $request->data() === ['queries' => ['What is the refund window?']]);
    }

    public function test_a_cloudflare_limit_does_not_fall_back_to_google(): void
    {
        Http::fake(['api.cloudflare.com/*' => Http::response(['success' => false], 429)]);
        $usedModel = 'previous-space';

        $this->assertNull(app(GeminiService::class)->embedContent(config('ai.models.embedding'), 'Text', [], $usedModel));
        $this->assertNull($usedModel);
        Http::assertSentCount(1);
    }
}
