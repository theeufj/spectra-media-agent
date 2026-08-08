<?php

namespace Tests\Unit;

use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class GeminiServiceTest extends TestCase
{
    // GeminiService::recordCost() writes an ai_costs row on every call, so
    // these tests were committing cost rows that leaked into the suite and
    // broke AiCostControllerTest's totals.
    use DatabaseTransactions;

    private GeminiService $service;

    private const VERTEX_URL = 'https://aiplatform.googleapis.com/*';

    // embedContent() calls the region-prefixed host
    // (https://{location}-aiplatform.googleapis.com/...), which VERTEX_URL does
    // not match — faking only VERTEX_URL left embedding requests unstubbed.
    private const VERTEX_REGIONAL_URL = 'https://*-aiplatform.googleapis.com/*';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.project_id' => 'test-project',
            'services.google.location' => 'us-central1',
            'services.google.credentials_path' => '/dev/null',
        ]);

        // Skip real GCP auth — inject a fake token directly into the cache
        Cache::put('gcp_vertex_access_token', 'test-token', 3000);

        $this->service = new GeminiService;
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /** Wrap text in the streaming chunk array format the service expects */
    private function streamChunk(string $text, array $usage = []): array
    {
        return [[
            'candidates' => [[
                'content' => ['parts' => [['text' => $text]], 'role' => 'model'],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => array_merge([
                'promptTokenCount' => 100,
                'candidatesTokenCount' => 50,
                'totalTokenCount' => 150,
            ], $usage),
        ]];
    }

    private function imageChunk(string $b64 = 'base64encodedimage', string $mime = 'image/png'): array
    {
        return [[
            'candidates' => [[
                'content' => ['parts' => [['inlineData' => ['data' => $b64, 'mimeType' => $mime]]]],
            ]],
            'usageMetadata' => ['promptTokenCount' => 20, 'candidatesTokenCount' => 0],
        ]];
    }

    // ─── generateContent ──────────────────────────────────────────────────────

    public function test_generate_content_returns_array_on_success(): void
    {
        Http::fake([self::VERTEX_URL => Http::response($this->streamChunk('Generated text response'), 200)]);

        $result = $this->service->generateContent('gemini-3.5-flash', 'Test prompt');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('text', $result);
        $this->assertEquals('Generated text response', $result['text']);
    }

    public function test_generate_content_returns_null_on_api_failure(): void
    {
        Http::fake([self::VERTEX_URL => Http::response(['error' => ['message' => 'API error']], 500)]);
        Log::spy();

        $result = $this->service->generateContent('gemini-3.5-flash', 'Test prompt');

        $this->assertNull($result);
    }

    public function test_generate_content_returns_null_when_candidates_missing(): void
    {
        Http::fake([self::VERTEX_URL => Http::response([['data' => 'no candidates']], 200)]);

        $result = $this->service->generateContent('gemini-3.5-flash', 'Test prompt');

        $this->assertNull($result);
    }

    public function test_generate_content_applies_task_type_temperature(): void
    {
        Http::fake([self::VERTEX_URL => Http::response($this->streamChunk('ok'), 200)]);

        $result = $this->service->generateContent(
            'gemini-3.5-flash',
            'Test',
            context: ['task_type' => 'extraction'],
        );

        $this->assertIsArray($result);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            // extraction task_type should apply low temperature (0.15)
            return ($body['generationConfig']['temperature'] ?? null) == 0.15;
        });
    }

    public function test_generate_content_caller_config_overrides_task_preset(): void
    {
        Http::fake([self::VERTEX_URL => Http::response($this->streamChunk('ok'), 200)]);

        $result = $this->service->generateContent(
            'gemini-3.5-flash',
            'Test',
            config: ['temperature' => 0.99],
            context: ['task_type' => 'extraction'],
        );

        $this->assertIsArray($result);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return ($body['generationConfig']['temperature'] ?? null) == 0.99;
        });
    }

    // ─── Fallback chain ───────────────────────────────────────────────────────

    public function test_generate_content_falls_back_to_next_model_on_failure(): void
    {
        // Read the fallback target from config instead of hardcoding it — this
        // test previously stubbed gemini-2.5-flash while the chain actually falls
        // back to gemini-3.5-flash, so the fallback request was never stubbed.
        $primary = 'gemini-3.1-pro-preview';
        $fallback = config('ai.fallback_chain')[$primary];

        Http::fake([
            "*{$primary}*" => Http::response([], 500),
            "*{$fallback}*" => Http::response($this->streamChunk('fallback response'), 200),
        ]);

        $result = $this->service->generateContent($primary, 'Test prompt');

        $this->assertIsArray($result);
        $this->assertEquals('fallback response', $result['text']);
    }

    public function test_generate_content_returns_null_when_both_models_fail(): void
    {
        Http::fake([self::VERTEX_URL => Http::response([], 500)]);
        Log::spy();

        $result = $this->service->generateContent('gemini-3.1-pro-preview', 'Test prompt');

        $this->assertNull($result);
    }

    // ─── resolveModel ─────────────────────────────────────────────────────────

    public function test_resolve_model_resolves_model_key(): void
    {
        $this->assertEquals(config('ai.models.default'), $this->service->resolveModel('default'));
        $this->assertEquals(config('ai.models.pro'), $this->service->resolveModel('pro'));
        $this->assertEquals(config('ai.models.lite'), $this->service->resolveModel('lite'));
    }

    public function test_resolve_model_resolves_task_type(): void
    {
        $this->assertEquals(config('ai.models.pro'), $this->service->resolveModel('strategy'));
        $this->assertEquals(config('ai.models.lite'), $this->service->resolveModel('extraction'));
        $this->assertEquals(config('ai.models.lite'), $this->service->resolveModel('classification'));
        $this->assertEquals(config('ai.models.default'), $this->service->resolveModel('creative'));
    }

    public function test_resolve_model_passes_through_raw_model_string(): void
    {
        $this->assertEquals('gemini-2.5-pro', $this->service->resolveModel('gemini-2.5-pro'));
    }

    // ─── Cost calculation ─────────────────────────────────────────────────────

    public function test_calculate_cost_returns_correct_usd_amount(): void
    {
        // Derived from config rather than hardcoded: these expectations were
        // pinned to old prices and silently wrong once config/ai.php was repriced.
        // This asserts the formula, which is what the method is responsible for.
        $rates = config('ai.pricing')['gemini-3.5-flash'];

        $cost = $this->service->calculateCost('gemini-3.5-flash', 1_000_000, 1_000_000);

        $this->assertEqualsWithDelta($rates['input'] + $rates['output'], $cost, 0.000001);
    }

    public function test_calculate_cost_includes_cached_token_discount(): void
    {
        $rates = config('ai.pricing')['gemini-3.5-flash'];

        $cost = $this->service->calculateCost('gemini-3.5-flash', 0, 0, 1_000_000);

        $this->assertEqualsWithDelta($rates['cached'], $cost, 0.000001);
        $this->assertLessThan($rates['input'], $rates['cached'], 'cached tokens should be cheaper than fresh input');
    }

    public function test_calculate_cost_returns_zero_for_unknown_model(): void
    {
        $cost = $this->service->calculateCost('unknown-model-xyz', 1_000_000, 1_000_000);
        $this->assertEquals(0.0, $cost);
    }

    // ─── Retry logic ──────────────────────────────────────────────────────────

    public function test_generate_content_retries_on_503(): void
    {
        Http::fake([self::VERTEX_URL => Http::sequence()
            ->push([], 503)
            ->push($this->streamChunk('Generated text'), 200),
        ]);

        $result = $this->service->generateContent('gemini-3.5-flash', 'Test prompt', maxRetries: 3);

        $this->assertIsArray($result);
        $this->assertEquals('Generated text', $result['text']);
    }

    public function test_generate_content_retries_on_429(): void
    {
        Http::fake([self::VERTEX_URL => Http::sequence()
            ->push([], 429)
            ->push($this->streamChunk('Generated text'), 200),
        ]);

        $result = $this->service->generateContent('gemini-3.5-flash', 'Test prompt', maxRetries: 3);

        $this->assertIsArray($result);
        $this->assertEquals('Generated text', $result['text']);
    }

    public function test_generate_content_retries_multiple_times(): void
    {
        Http::fake([self::VERTEX_URL => Http::sequence()
            ->push([], 503)
            ->push([], 503)
            ->push($this->streamChunk('Generated text'), 200),
        ]);

        $result = $this->service->generateContent('gemini-3.5-flash', 'Test prompt', maxRetries: 4);

        $this->assertIsArray($result);
        $this->assertEquals('Generated text', $result['text']);
    }

    public function test_generate_content_fails_after_max_retries(): void
    {
        Http::fake([self::VERTEX_URL => Http::sequence()
            ->push([], 503)->push([], 503)->push([], 503),
        ]);
        Log::spy();

        $result = $this->service->generateContent('gemini-3.5-flash', 'Test prompt', maxRetries: 3);

        $this->assertNull($result);
    }

    public function test_generate_content_does_not_retry_on_400(): void
    {
        // 400 is not retryable — no retry attempts, but the fallback chain still fires once.
        // Verify by checking only 2 HTTP requests were made (primary + one fallback attempt).
        Http::fake([self::VERTEX_URL => Http::response(['error' => 'Bad request'], 400)]);

        $result = $this->service->generateContent('gemini-3.5-flash', 'Test prompt');

        $this->assertNull($result);
        Http::assertSentCount(2); // 1 primary (no retries on 400) + 1 fallback
    }

    public function test_generate_content_respects_custom_max_retries(): void
    {
        // maxRetries: 2 → 1 retry attempt (attempt 0 + retry 1 = 2 total requests for primary)
        Http::fake([self::VERTEX_URL => Http::response([], 503)]);

        $result = $this->service->generateContent('gemini-3.5-flash', 'Test prompt', maxRetries: 2);

        $this->assertNull($result);
        // 2 attempts for primary (maxRetries=2) + attempts for fallback model
        Http::assertSentCount(4); // 2 primary + 2 fallback (fallback also uses maxRetries=2)
    }

    // ─── embedContent ─────────────────────────────────────────────────────────

    public function test_embed_content_returns_array_on_success(): void
    {
        // Vertex AI embedding response format
        // gemini-embedding-2-* uses the regional :embedContent endpoint, whose
        // response is {embedding: {values: []}}. The old fixture used the
        // :predict shape ({predictions: [{embeddings: {values: []}}]}), which the
        // service correctly does not read for this model.
        Http::fake([self::VERTEX_REGIONAL_URL => Http::response([
            'embedding' => ['values' => [0.1, 0.2, 0.3, 0.4]],
        ], 200)]);

        $result = $this->service->embedContent('gemini-embedding-2-preview', 'Test text');

        $this->assertIsArray($result);
        $this->assertEquals([0.1, 0.2, 0.3, 0.4], $result);
    }

    public function test_embed_content_returns_null_on_api_failure(): void
    {
        Http::fake([self::VERTEX_URL => Http::response(['error' => ['message' => 'API error']], 500)]);
        Log::spy();

        $result = $this->service->embedContent('gemini-embedding-2-preview', 'Test text');

        $this->assertNull($result);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_embed_content_returns_null_when_values_missing(): void
    {
        Http::fake([self::VERTEX_URL => Http::response(['predictions' => [[]]], 200)]);

        $result = $this->service->embedContent('gemini-embedding-2-preview', 'Test text');

        $this->assertNull($result);
    }

    // ─── generateImage ────────────────────────────────────────────────────────

    public function test_generate_image_returns_array_on_success(): void
    {
        Http::fake([self::VERTEX_URL => Http::response($this->imageChunk(), 200)]);

        $result = $this->service->generateImage('A cat');

        $this->assertIsArray($result);
        $this->assertArrayHasKey('data', $result);
        $this->assertArrayHasKey('mimeType', $result);
        $this->assertEquals('base64encodedimage', $result['data']);
        $this->assertEquals('image/png', $result['mimeType']);
    }

    public function test_generate_image_returns_null_when_inlinedata_missing(): void
    {
        Http::fake([self::VERTEX_URL => Http::response(
            [['candidates' => [['content' => ['parts' => [['text' => 'some text']]]]]]],
            200
        )]);
        Log::spy();

        $result = $this->service->generateImage('An image');

        $this->assertNull($result);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_generate_image_returns_null_on_api_failure(): void
    {
        Http::fake([self::VERTEX_URL => Http::response(
            [['error' => ['message' => 'API error', 'code' => 500, 'status' => 'INTERNAL']]],
            500
        )]);
        Log::spy();

        $result = $this->service->generateImage('An image');

        $this->assertNull($result);
        Log::shouldHaveReceived('error')->once();
    }

    // ─── startVideoGeneration ─────────────────────────────────────────────────

    public function test_start_video_generation_returns_operation_name_on_success(): void
    {
        Http::fake([self::VERTEX_URL => Http::response([
            'name' => 'projects/123/locations/us-central1/operations/abc123',
        ], 200)]);

        $result = $this->service->startVideoGeneration('A sunset');

        $this->assertIsString($result);
        $this->assertEquals('projects/123/locations/us-central1/operations/abc123', $result);
    }

    public function test_start_video_generation_returns_null_on_api_failure(): void
    {
        Http::fake([self::VERTEX_URL => Http::response(['error' => ['message' => 'API error']], 500)]);
        Log::spy();

        $result = $this->service->startVideoGeneration('A video');

        $this->assertNull($result);
        Log::shouldHaveReceived('error')->once();
    }

    public function test_start_video_generation_accepts_custom_parameters(): void
    {
        Http::fake([self::VERTEX_URL => Http::response([
            'name' => 'projects/123/locations/us-central1/operations/abc123',
        ], 200)]);

        $result = $this->service->startVideoGeneration('A video', 'veo-3.1-generate-preview', [
            'aspectRatio' => '9:16',
            'durationSeconds' => 15,
        ]);

        $this->assertIsString($result);
        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['parameters']['aspectRatio'] === '9:16'
                && $body['parameters']['durationSeconds'] === 15;
        });
    }

    // ─── checkVideoGenerationStatus ───────────────────────────────────────────

    public function test_check_video_generation_status_returns_operation_when_done(): void
    {
        Http::fake([self::VERTEX_URL => Http::response([
            'done' => true,
            'response' => ['predictions' => [['bytesBase64Encoded' => 'base64video', 'mimeType' => 'video/mp4']]],
        ], 200)]);

        $result = $this->service->checkVideoGenerationStatus('projects/123/locations/us-central1/operations/abc123');

        $this->assertIsArray($result);
        $this->assertTrue($result['done']);
    }

    public function test_check_video_generation_status_returns_null_when_not_done(): void
    {
        Http::fake([self::VERTEX_URL => Http::response([
            'done' => false,
            'name' => 'projects/123/locations/us-central1/operations/abc123',
        ], 200)]);

        $result = $this->service->checkVideoGenerationStatus('projects/123/locations/us-central1/operations/abc123');

        $this->assertNull($result);
    }

    public function test_check_video_generation_status_returns_null_on_api_failure(): void
    {
        Http::fake([self::VERTEX_URL => Http::response(['error' => ['message' => 'API error']], 500)]);
        Log::spy();

        $result = $this->service->checkVideoGenerationStatus('projects/123/locations/us-central1/operations/abc123');

        $this->assertNull($result);
        Log::shouldHaveReceived('error')->once();
    }

    // ─── config validation ────────────────────────────────────────────────────

    public function test_all_configured_model_names_are_non_empty_strings(): void
    {
        foreach (config('ai.models') as $key => $model) {
            $this->assertIsString($model, "ai.models.{$key} should be a string");
            $this->assertNotEmpty($model, "ai.models.{$key} should not be empty");
        }
    }

    public function test_all_fallback_chain_entries_reference_valid_model_strings(): void
    {
        foreach (config('ai.fallback_chain') as $from => $to) {
            $this->assertIsString($to, "Fallback for {$from} should be a string");
            $this->assertNotEmpty($to, "Fallback for {$from} should not be empty");
            // Fallback must not loop to itself
            $this->assertNotEquals($from, $to, "Model {$from} cannot fall back to itself");
        }
    }

    public function test_all_task_types_have_config_and_model(): void
    {
        $taskTypes = array_keys(config('ai.task_config'));
        foreach ($taskTypes as $taskType) {
            $this->assertArrayHasKey($taskType, config('ai.task_models'),
                "task_type '{$taskType}' has task_config but no task_models entry");
        }
    }

    public function test_pricing_table_has_entries_for_all_named_models(): void
    {
        $namedModels = array_values(config('ai.models'));
        $pricing = config('ai.pricing');

        foreach ($namedModels as $model) {
            $this->assertArrayHasKey($model, $pricing,
                "Model '{$model}' is configured but has no pricing entry — cost tracking will return 0");
        }
    }
}
