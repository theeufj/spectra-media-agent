<?php

namespace Tests\Feature;

use App\Models\AiCost;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GeminiArgonTest extends TestCase
{
    use DatabaseTransactions;

    private const MODEL = 'gemini-4-argon';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.project_id' => 'test-project',
            'services.google.location' => 'us-central1',
            'ai.text_provider' => 'gemini',
            'services.openrouter.api_key' => null,
        ]);
        Cache::put('gcp_vertex_access_token', 'test-token', 3000);
    }

    private function streamResponse(array $usage = []): array
    {
        return [[
            'candidates' => [[
                'content' => ['parts' => [['text' => '{"approved":true}']]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => $usage,
        ]];
    }

    public function test_argon_streaming_preserves_json_output_and_uses_supported_reasoning_parameters(): void
    {
        Http::fake(['*aiplatform.googleapis.com/*' => Http::response($this->streamResponse())]);
        $schema = ['type' => 'OBJECT', 'properties' => ['approved' => ['type' => 'BOOLEAN']]];

        $result = app(GeminiService::class)->generateContent(
            self::MODEL,
            'Review this plan.',
            config: [
                'temperature' => 0.2,
                'topP' => 0.8,
                'topK' => 20,
                'candidateCount' => 1,
                'frequencyPenalty' => 0.1,
                'presencePenalty' => 0.1,
                'thinkingConfig' => ['thinkingBudget' => 4096],
                'responseMimeType' => 'application/json',
                'responseSchema' => $schema,
            ],
            context: ['task_type' => 'extraction'],
        );

        $this->assertSame('{"approved":true}', $result['text'] ?? null);
        Http::assertSent(function (Request $request) use ($schema): bool {
            $this->assertSame(
                'https://aiplatform.googleapis.com/v1/projects/test-project/locations/global/publishers/google/models/'.self::MODEL.':streamGenerateContent',
                $request->url(),
            );
            $generation = $request->data()['generationConfig'];
            $this->assertSame(32768, $generation['maxOutputTokens']);
            $this->assertSame('MEDIUM', $generation['thinkingConfig']['thinkingLevel']);
            $this->assertArrayNotHasKey('thinkingBudget', $generation['thinkingConfig']);
            $this->assertSame('application/json', $generation['responseMimeType']);
            $this->assertSame($schema, $generation['responseSchema']);
            foreach (['temperature', 'topP', 'topK', 'candidateCount', 'frequencyPenalty', 'presencePenalty'] as $parameter) {
                $this->assertArrayNotHasKey($parameter, $generation);
            }

            return true;
        });
        Http::assertSentCount(1);
    }

    public function test_argon_preserves_an_explicit_high_thinking_level(): void
    {
        $this->assertThinkingLevelIsSent('HIGH');
    }

    public function test_argon_preserves_an_explicit_low_thinking_level(): void
    {
        $this->assertThinkingLevelIsSent('LOW');
    }

    private function assertThinkingLevelIsSent(string $level): void
    {
        Http::fake(['*aiplatform.googleapis.com/*' => Http::response($this->streamResponse())]);

        app(GeminiService::class)->generateContent(self::MODEL, 'Review this plan.', [
            'maxOutputTokens' => 1024,
            'thinkingConfig' => ['thinkingLevel' => $level],
        ]);

        Http::assertSent(function (Request $request) use ($level): bool {
            $this->assertSame(32768, $request->data()['generationConfig']['maxOutputTokens']);

            return $request->data()['generationConfig']['thinkingConfig']['thinkingLevel'] === $level;
        });
    }

    public function test_requesting_extended_thinking_selects_high_for_argon(): void
    {
        Http::fake(['*aiplatform.googleapis.com/*' => Http::response($this->streamResponse())]);

        app(GeminiService::class)->generateContent(self::MODEL, 'Review this plan.', enableThinking: true);

        Http::assertSent(fn (Request $request) => $request->data()['generationConfig']['thinkingConfig']['thinkingLevel'] === 'HIGH');
    }

    public function test_streaming_requests_stay_within_the_supported_single_request_output_limit(): void
    {
        Http::fake(['*aiplatform.googleapis.com/*' => Http::response($this->streamResponse())]);

        app(GeminiService::class)->generateContent(self::MODEL, 'Review this plan.', ['maxOutputTokens' => 100000]);

        Http::assertSent(fn (Request $request) => $request->data()['generationConfig']['maxOutputTokens'] === 65536);
    }

    public function test_function_call_responses_keep_ids_signatures_and_order_with_the_unary_output_limit(): void
    {
        $requests = [];
        $parts = [
            ['functionCall' => ['id' => 'call-one', 'name' => 'search_knowledge_base', 'args' => ['query' => 'pricing']], 'thoughtSignature' => 'signature-one'],
            ['functionCall' => ['id' => 'call-two', 'name' => 'search_knowledge_base', 'args' => ['query' => 'services']], 'thoughtSignature' => 'signature-two'],
        ];
        Http::fake(function (Request $request) use (&$requests, $parts) {
            $requests[] = $request->data();

            return Http::response(count($requests) === 1
                ? ['candidates' => [['content' => ['parts' => $parts]]]]
                : ['candidates' => [['content' => ['parts' => [['text' => 'Complete plan.']]], 'finishReason' => 'STOP']]]);
        });
        $tools = [['name' => 'search_knowledge_base', 'parameters' => [
            'type' => 'OBJECT',
            'properties' => ['query' => ['type' => 'STRING']],
            'required' => ['query'],
        ]]];
        $queries = [];

        $result = app(GeminiService::class)->generateWithFunctionCalling(
            self::MODEL,
            'Create a grounded plan.',
            'Find our pricing and services.',
            $tools,
            function (string $name, array $args) use (&$queries): array {
                $queries[] = $args['query'];

                return ['source' => $args['query']];
            },
            config: ['maxOutputTokens' => 65535, 'temperature' => 0.2],
        );

        $this->assertSame('Complete plan.', $result['text'] ?? null);
        $this->assertCount(2, $requests);
        $this->assertSame(['pricing', 'services'], $queries);
        foreach ($requests as $request) {
            $this->assertSame(32768, $request['generationConfig']['maxOutputTokens']);
            $this->assertSame('MEDIUM', $request['generationConfig']['thinkingConfig']['thinkingLevel']);
            $this->assertArrayNotHasKey('temperature', $request['generationConfig']);
            $this->assertSame($tools, $request['tools'][0]['functionDeclarations']);
        }
        $this->assertSame($parts, $requests[1]['contents'][1]['parts']);
        $responses = $requests[1]['contents'][2]['parts'];
        $this->assertSame(['call-one', 'call-two'], array_column(array_column($responses, 'functionResponse'), 'id'));
        $this->assertSame(['search_knowledge_base', 'search_knowledge_base'], array_column(array_column($responses, 'functionResponse'), 'name'));
        $this->assertSame(['pricing', 'services'], array_map(fn (array $part) => $part['functionResponse']['response']['content']['source'], $responses));
    }

    public function test_argon_rate_limits_use_the_configured_fallback_and_record_the_model_that_answered(): void
    {
        $fallback = config('ai.fallback_chain')[self::MODEL] ?? null;
        $this->assertNotEmpty($fallback, 'Argon needs a configured fallback.');
        Http::fake([
            '*'.self::MODEL.':streamGenerateContent' => Http::response(['error' => ['message' => 'Quota exhausted']], 429),
            '*'.$fallback.':streamGenerateContent' => Http::response($this->streamResponse(['promptTokenCount' => 100, 'candidatesTokenCount' => 25])),
        ]);

        $result = app(GeminiService::class)->generateContent(self::MODEL, 'Review this plan.', context: ['operation' => 'argon-fallback-test']);

        $this->assertSame('{"approved":true}', $result['text'] ?? null);
        Http::assertSentCount(2);
        $cost = AiCost::where('operation', 'argon-fallback-test')->sole();
        $this->assertSame($fallback, $cost->model);
        $this->assertSame(self::MODEL, $cost->metadata['fallback_from']);
        $this->assertEqualsWithDelta(app(GeminiService::class)->calculateCost($fallback, 100, 25), (float) $cost->cost, 0.000001);
    }

    public function test_a_function_call_fallback_rebuilds_generation_options_for_the_fallback_model(): void
    {
        $fallback = config('ai.fallback_chain')[self::MODEL] ?? null;
        $this->assertNotEmpty($fallback);
        $requests = [];
        Http::fake(function (Request $request) use (&$requests) {
            $requests[] = $request->data();

            return count($requests) === 1
                ? Http::response(['error' => ['message' => 'Quota exhausted']], 429)
                : Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Fallback plan.']]], 'finishReason' => 'STOP']]]);
        });

        $result = app(GeminiService::class)->generateWithFunctionCalling(
            self::MODEL,
            'Create a plan.',
            'Review our offer.',
            [['name' => 'search_knowledge_base', 'parameters' => ['type' => 'OBJECT', 'properties' => (object) []]]],
            fn () => [],
            config: ['maxOutputTokens' => 65535, 'temperature' => 0.2],
        );

        $this->assertSame('Fallback plan.', $result['text'] ?? null);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request) => str_contains($request->url(), '/models/'.$fallback.':generateContent'));
        $this->assertSame(32768, $requests[0]['generationConfig']['maxOutputTokens']);
        $this->assertArrayNotHasKey('temperature', $requests[0]['generationConfig']);
        $this->assertSame(65535, $requests[1]['generationConfig']['maxOutputTokens']);
        $this->assertSame(0.2, $requests[1]['generationConfig']['temperature']);
        $this->assertArrayNotHasKey('thinkingConfig', $requests[1]['generationConfig']);
    }

    public function test_paid_tool_turns_and_a_later_fallback_are_billed_to_the_models_that_produced_them(): void
    {
        $fallback = config('ai.fallback_chain')[self::MODEL] ?? null;
        $this->assertNotEmpty($fallback);
        $argonRequests = 0;
        Http::fake(function (Request $request) use (&$argonRequests) {
            if (str_contains($request->url(), '/models/'.self::MODEL.':')) {
                $argonRequests++;

                return $argonRequests === 1
                    ? Http::response([
                        'candidates' => [['content' => ['parts' => [[
                            'functionCall' => ['id' => 'lookup-one', 'name' => 'search_knowledge_base', 'args' => ['query' => 'pricing']],
                            'thoughtSignature' => 'lookup-signature',
                        ]]]]],
                        'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 10, 'thoughtsTokenCount' => 200],
                    ])
                    : Http::response(['error' => ['message' => 'Quota exhausted']], 429);
            }

            return Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'Complete fallback plan.']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 50, 'candidatesTokenCount' => 25, 'thoughtsTokenCount' => 15],
            ]);
        });

        $result = app(GeminiService::class)->generateWithFunctionCalling(
            self::MODEL,
            'Create a plan.',
            'Find our pricing.',
            [['name' => 'search_knowledge_base', 'parameters' => ['type' => 'OBJECT', 'properties' => ['query' => ['type' => 'STRING']]]]],
            function (): array {
                $this->assertSame(1, AiCost::where('operation', 'argon-tool-fallback-test')->count(), 'A paid response must be recorded before running its tool.');

                return ['pricing' => 'Verified pricing'];
            },
            context: ['operation' => 'argon-tool-fallback-test'],
        );

        $this->assertSame('Complete fallback plan.', $result['text'] ?? null);
        Http::assertSentCount(3);
        $costs = AiCost::where('operation', 'argon-tool-fallback-test')->get();
        $this->assertCount(2, $costs);
        $argonCost = $costs->where('model', self::MODEL)->sole();
        $fallbackCost = $costs->where('model', $fallback)->sole();
        $this->assertSame(100, $argonCost->input_tokens);
        $this->assertSame(210, $argonCost->output_tokens);
        $this->assertSame(50, $fallbackCost->input_tokens);
        $this->assertSame(40, $fallbackCost->output_tokens);
        $service = app(GeminiService::class);
        $this->assertEqualsWithDelta($service->calculateCost(self::MODEL, 100, 210), (float) $argonCost->cost, 0.000001);
        $this->assertEqualsWithDelta($service->calculateCost($fallback, 50, 40), (float) $fallbackCost->cost, 0.000001);
    }

    public function test_a_truncated_stream_is_billed_but_replaced_with_a_complete_fallback_response(): void
    {
        $this->assertIncompleteStreamFallsBackAndRecordsCost([['text' => '{"approved":']]);
    }

    public function test_a_thinking_only_stream_is_billed_before_using_a_complete_fallback_response(): void
    {
        $this->assertIncompleteStreamFallsBackAndRecordsCost([['text' => 'Internal reasoning only.', 'thought' => true]]);
    }

    private function assertIncompleteStreamFallsBackAndRecordsCost(array $parts): void
    {
        $fallback = config('ai.fallback_chain')[self::MODEL] ?? null;
        $this->assertNotEmpty($fallback);
        Http::fake([
            '*'.self::MODEL.':streamGenerateContent' => Http::response([[
                'candidates' => [['content' => ['parts' => $parts], 'finishReason' => 'MAX_TOKENS']],
                'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 20, 'thoughtsTokenCount' => 50],
            ]]),
            '*'.$fallback.':streamGenerateContent' => Http::response($this->streamResponse(['promptTokenCount' => 50, 'candidatesTokenCount' => 25])),
        ]);

        $result = app(GeminiService::class)->generateContent(
            self::MODEL,
            'Review this plan.',
            maxRetries: 1,
            context: ['operation' => 'argon-incomplete-stream-test'],
        );

        $this->assertSame('{"approved":true}', $result['text'] ?? null);
        Http::assertSentCount(2);
        $costs = AiCost::where('operation', 'argon-incomplete-stream-test')->get();
        $this->assertCount(2, $costs, 'Both paid responses must remain visible even when the first response cannot be used.');
        $argonCost = $costs->where('model', self::MODEL)->sole();
        $this->assertSame(100, $argonCost->input_tokens);
        $this->assertSame(70, $argonCost->output_tokens);
        $this->assertEqualsWithDelta(app(GeminiService::class)->calculateCost(self::MODEL, 100, 70), (float) $argonCost->cost, 0.000001);
        $this->assertCount(1, $costs->where('model', $fallback));
    }
}
