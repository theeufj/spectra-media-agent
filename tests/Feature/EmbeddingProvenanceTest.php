<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerPage;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pgvector\Laravel\Vector;
use Tests\TestCase;

/**
 * A stored vector has to say which space it is in.
 *
 * GeminiService falls back from the configured embedding model to
 * gemini-embedding-001 on a 429. Both return 3072 dimensions, so the write
 * succeeds and nothing complains — but the two models embed into different
 * spaces, and cosine distance between them is noise. Nothing recorded which
 * model produced a row, so a 429 storm during a crawl silently degraded search
 * with no way to find the affected rows.
 */
class EmbeddingProvenanceTest extends TestCase
{
    use DatabaseTransactions;

    // embedContent() calls the region-prefixed Vertex host; the global
    // aiplatform.googleapis.com pattern does not match it.
    private const REGIONAL = 'https://*-aiplatform.googleapis.com/*';

    private const GLOBAL = 'https://aiplatform.googleapis.com/*';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.project_id' => 'test-project',
            'services.google.location' => 'us-central1',
            'services.google.credentials_path' => '/dev/null',
        ]);

        // Skip real GCP auth — inject a fake token directly into the cache.
        Cache::put('gcp_vertex_access_token', 'test-token', 3000);
    }

    private function vector(float $seed = 0.1): array
    {
        return array_fill(0, 3072, $seed);
    }

    public function test_embed_content_reports_the_model_that_answered(): void
    {
        Http::fake([self::REGIONAL => Http::response(['embedding' => ['values' => $this->vector()]])]);

        $vector = (new GeminiService)->embedContent('gemini-embedding-2-preview', 'hello', [], $used);

        $this->assertNotNull($vector);
        $this->assertSame('gemini-embedding-2-preview', $used);
    }

    public function test_a_429_fallback_reports_the_model_it_fell_back_to(): void
    {
        // The whole point: the caller asked for one model and got a vector from
        // another. Without this the row is stored as though it were comparable.
        Http::fake([
            self::REGIONAL => Http::response([], 429),
            self::GLOBAL => Http::response([
                'predictions' => [['embeddings' => ['values' => $this->vector(0.2)]]],
            ]),
        ]);

        $vector = (new GeminiService)->embedContent('gemini-embedding-2-preview', 'hello', [], $used);

        $this->assertNotNull($vector);
        $this->assertSame('gemini-embedding-001', $used, 'the fallback model has to be reported, not the requested one');
    }

    public function test_a_failed_embedding_reports_no_model(): void
    {
        Http::fake([self::REGIONAL => Http::response([], 500), self::GLOBAL => Http::response([], 500)]);

        $vector = (new GeminiService)->embedContent('gemini-embedding-2-preview', 'hello', [], $used);

        $this->assertNull($vector);
        $this->assertNull($used);
    }

    public function test_vector_search_ignores_rows_from_another_embedding_space(): void
    {
        $customer = Customer::factory()->create();

        CustomerPage::withoutCustomerScope()->getModel()->newInstance()->forceFill([
            'customer_id' => $customer->id,
            'url' => 'https://example.test/same-space',
            'title' => 'Same space',
            'content' => 'matching content about widgets',
            'embedding' => new Vector($this->vector(0.1)),
            'embedding_model' => 'gemini-embedding-2-preview',
        ])->save();

        CustomerPage::withoutCustomerScope()->getModel()->newInstance()->forceFill([
            'customer_id' => $customer->id,
            'url' => 'https://example.test/other-space',
            'title' => 'Other space',
            'content' => 'matching content about widgets',
            'embedding' => new Vector($this->vector(0.9)),
            'embedding_model' => 'gemini-embedding-001',
        ])->save();

        Http::fake([self::REGIONAL => Http::response(['embedding' => ['values' => $this->vector(0.1)]])]);

        config(['ai.models.embedding' => 'gemini-embedding-2-preview']);

        $output = app(\App\Services\KnowledgeBaseSearchService::class)
            ->search($customer->id, 'widgets');

        $this->assertStringContainsString('same-space', $output);
        $this->assertStringNotContainsString(
            'other-space',
            $output,
            'a vector from a different model is not a weak match — it is not comparable at all',
        );
    }
}
