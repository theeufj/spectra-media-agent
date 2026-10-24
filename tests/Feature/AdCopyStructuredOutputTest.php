<?php

namespace Tests\Feature;

use App\Prompts\AdCopyResponseSchema;
use App\Services\GeminiService;
use App\Services\OpenRouterService;
use App\Services\XaiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdCopyStructuredOutputTest extends TestCase
{
    use DatabaseTransactions;

    public static function providers(): array
    {
        return ['xai' => [XaiService::class, 'api.x.ai'], 'openrouter' => [OpenRouterService::class, 'openrouter.ai']];
    }

    #[DataProvider('providers')]
    public function test_provider_receives_actual_asset_bounds_without_changing_other_json_callers(string $service, string $host): void
    {
        config(['services.xai.api_key' => 'fake', 'services.openrouter.api_key' => 'fake']);
        Cache::forget('xai:unavailable');
        Http::fake(['*' => Http::response(['choices' => [['message' => ['content' => '{"headlines":[],"descriptions":[]}']]]])]);
        $schema = AdCopyResponseSchema::rsa();
        app($service)->generateText('Write ad copy.', ['responseMimeType' => 'application/json', 'responseJsonSchema' => $schema]);
        Http::assertSent(function ($request) use ($host, $schema) {
            $format = $request['response_format'];

            return str_contains($request->url(), $host)
                && $format['type'] === 'json_schema' && $format['json_schema']['strict'] === true
                && $format['json_schema']['schema'] === $schema
                && $format['json_schema']['schema']['properties']['headlines']['items']['maxLength'] === 30
                && $format['json_schema']['schema']['properties']['descriptions']['items']['maxLength'] === 90;
        });

        app($service)->generateText('Return ordinary JSON.', ['responseMimeType' => 'application/json']);
        Http::assertSent(fn ($request) => $request['response_format'] === ['type' => 'json_object']);
    }

    public function test_schema_reaches_gemini_when_it_is_the_selected_or_fallback_provider(): void
    {
        config(['ai.text_provider' => 'gemini', 'services.xai.api_key' => null, 'services.openrouter.api_key' => null,
            'services.google.project_id' => 'test-project', 'services.google.location' => 'us-central1',
            'services.google.credentials_path' => '/dev/null']);
        Cache::put('gcp_vertex_access_token', 'test-token', 3000);
        Http::fake(['aiplatform.googleapis.com/*' => Http::response([[
            'candidates' => [['content' => ['parts' => [['text' => '{"headlines":[],"descriptions":[]}']]]]],
        ]])]);
        $schema = AdCopyResponseSchema::rsa();
        app(GeminiService::class)->generateContent(config('ai.models.default'), 'Write copy.',
            config: ['responseMimeType' => 'application/json', 'responseJsonSchema' => $schema]);
        Http::assertSent(fn ($request) => $request['generationConfig']['responseJsonSchema'] === $schema);
    }

    public function test_initial_and_rank_schemas_preserve_each_platform_and_asset_limit(): void
    {
        $facebook = AdCopyResponseSchema::forRules(config('platform_rules.facebook'));
        $this->assertSame(40, $facebook['properties']['headlines']['items']['maxLength']);
        $this->assertSame(125, $facebook['properties']['descriptions']['items']['maxLength']);
        $this->assertSame(3, $facebook['properties']['headlines']['minItems']);
        $this->assertSame(2, $facebook['properties']['descriptions']['maxItems']);
        $rank = AdCopyResponseSchema::rankRepair();
        $this->assertSame(25, $rank['properties']['callouts']['items']['maxLength']);
        $this->assertSame(35, $rank['properties']['sitelinks']['items']['properties']['description1']['maxLength']);
        $this->assertSame(25, $rank['properties']['structured_snippet']['anyOf'][1]['properties']['values']['items']['maxLength']);
        $this->assertSame(AdCopyResponseSchema::rsa()['properties']['headlines'], $rank['properties']['headlines']);
    }

    public function test_missing_platform_rules_keep_the_existing_json_contract_without_inventing_limits(): void
    {
        $schema = AdCopyResponseSchema::forRules(null);
        $this->assertSame(['headlines', 'descriptions'], $schema['required']);
        foreach ($schema['properties'] as $property) {
            $this->assertSame(['type' => 'array', 'items' => ['type' => 'string']], $property);
        }
    }
}
