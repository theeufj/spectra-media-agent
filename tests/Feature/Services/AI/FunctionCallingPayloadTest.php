<?php

namespace Tests\Feature\Services\Ai;

use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression: a tool that takes no arguments broke the whole function-calling
 * loop.
 *
 * Gemini returns "args": {} for a parameterless call. json_decode turns that
 * into an empty PHP array, and json_encode turns the array back into [] — a
 * JSON list. The proto field is a Struct, so echoing the model's turn back
 * produced:
 *
 *   Invalid JSON payload received. Unknown name "args" at
 *   'contents[1].parts[0].function_call': Proto field is not repeating,
 *   cannot start list.
 *
 * and the call returned null. It went unnoticed because the only other caller
 * (GenerateStrategy) declares tools that all take arguments; the support
 * assistant's get_account_overview and list_campaigns take none, so it failed
 * every single time.
 */
class FunctionCallingPayloadTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        // getAccessToken() reads a real Google service-account file and caches
        // the token. Seeding the cache short-circuits it, so the test exercises
        // the payload construction rather than the auth plumbing.
        \Illuminate\Support\Facades\Cache::put('gcp_vertex_access_token', 'test-token', 3000);
    }

    public function test_a_parameterless_tool_call_is_echoed_back_as_an_object_not_a_list(): void
    {
        $sentBodies = [];

        Http::fake(function (Request $request) use (&$sentBodies) {
            $sentBodies[] = $request->body();

            // First turn: the model asks for a tool, with no arguments.
            if (count($sentBodies) === 1) {
                return Http::response([
                    'candidates' => [[
                        'content' => ['parts' => [[
                            'functionCall' => ['name' => 'get_account_overview', 'args' => []],
                        ]]],
                    ]],
                ]);
            }

            // Second turn: having received the tool result, it answers.
            return Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => 'You have 3 campaigns.']]],
                    'finishReason' => 'STOP',
                ]],
            ]);
        });

        $result = app(GeminiService::class)->generateWithFunctionCalling(
            model: 'gemini-3.7-flash',
            systemInstruction: 'You are a test.',
            prompt: 'How is my account?',
            tools: [['name' => 'get_account_overview', 'description' => 'x', 'parameters' => ['type' => 'object', 'properties' => (object) []]]],
            toolHandler: fn () => ['campaigns_total' => 3],
        );

        $this->assertSame('You have 3 campaigns.', $result['text'] ?? null);
        $this->assertCount(2, $sentBodies, 'the loop did not survive the tool call');

        // The wire format is the actual subject of this test: "args":{} is
        // accepted, "args":[] is a 400.
        $this->assertStringContainsString('"args":{}', $sentBodies[1]);
        $this->assertStringNotContainsString('"args":[]', $sentBodies[1]);
    }

    public function test_an_empty_tool_result_is_also_sent_as_an_object(): void
    {
        $sentBodies = [];

        Http::fake(function (Request $request) use (&$sentBodies) {
            $sentBodies[] = $request->body();

            if (count($sentBodies) === 1) {
                return Http::response([
                    'candidates' => [[
                        'content' => ['parts' => [[
                            'functionCall' => ['name' => 'get_account_overview', 'args' => []],
                        ]]],
                    ]],
                ]);
            }

            return Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'Nothing to report.']]]]],
            ]);
        });

        app(GeminiService::class)->generateWithFunctionCalling(
            model: 'gemini-3.7-flash',
            systemInstruction: 'You are a test.',
            prompt: 'How is my account?',
            tools: [['name' => 'get_account_overview', 'description' => 'x', 'parameters' => ['type' => 'object', 'properties' => (object) []]]],
            // A tool returning nothing at all hits the same list-vs-object trap
            // on the way back.
            toolHandler: fn () => [],
        );

        $this->assertStringContainsString('"content":{}', $sentBodies[1]);
    }
}
