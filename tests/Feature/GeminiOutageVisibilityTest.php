<?php

namespace Tests\Feature;

use App\Exceptions\GeminiUnavailable;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A total AI outage has to reach the admin dashboard.
 *
 * On 2026-09-12 every Gemini text call returned 403 BILLING_DISABLED for the
 * GCP project. Strategy generation, brand extraction, creative, the copilot and
 * the public demo were all dead for at least a day. `runtime_exceptions` — what
 * the dashboard reads — had nothing newer than the 10th, because GeminiService
 * wrote Log::error and never called report(). The product looked healthy. It
 * was found from a screenshot of the demo writing no ad copy.
 *
 * That is the rule in CLAUDE.md, broken in the one service nothing else works
 * without: Log::error() alone does not reach the dashboard.
 */
class GeminiOutageVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'ai.models.default' => 'gemini-test-primary',
            'ai.fallback_chain' => ['gemini-test-primary' => 'gemini-test-fallback'],
            'services.gemini.api_key' => 'test-key',
        ]);
    }

    private function billingDisabled(): void
    {
        Http::fake([
            '*generativelanguage*' => Http::response([
                'error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'This API method requires billing to be enabled.'],
            ], 403),
            '*aiplatform*' => Http::response([
                'error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'This API method requires billing to be enabled.'],
            ], 403),
            '*' => Http::response([
                'error' => ['code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'This API method requires billing to be enabled.'],
            ], 403),
        ]);
    }

    public function test_an_outage_is_reported_not_only_logged(): void
    {
        $this->billingDisabled();
        Exceptions::fake();

        // One attempt, no retry. Not 0 — `while ($attempt < $maxRetries)` means
        // 0 makes no request at all, which would pass this test without ever
        // reaching the API.
        $result = app(GeminiService::class)->generateContent('gemini-test-primary', 'anything', [], null, false, false, 1);

        // Callers still get null — the contract has not changed.
        $this->assertNull($result);

        Exceptions::assertReported(GeminiUnavailable::class);
    }

    public function test_the_report_carries_the_upstream_reason(): void
    {
        $this->billingDisabled();
        Exceptions::fake();

        app(GeminiService::class)->generateContent('gemini-test-primary', 'anything', [], null, false, false, 1);

        /*
           "Generation failed" is the difference between "enable billing" and a
           day of guessing, so whatever the upstream said travels with it.

           Asserted as "names the model and says something specific" rather than
           matching on "403": where this stops depends on the environment — with
           no credentials it never reaches the API and fails at the token
           exchange instead, which is just as much an outage and just as much
           worth seeing.
        */
        Exceptions::assertReported(function (GeminiUnavailable $e) {
            $this->assertStringContainsString('gemini-test-primary', $e->getMessage());
            $this->assertGreaterThan(
                80,
                strlen($e->getMessage()),
                'the report must carry the upstream reason, not just "it failed"',
            );

            return true;
        });
    }

    public function test_a_sustained_outage_does_not_bury_the_dashboard(): void
    {
        $this->billingDisabled();
        Exceptions::fake();

        // The demo alone makes several calls per visitor. One row per request
        // is its own kind of silence.
        for ($i = 0; $i < 5; $i++) {
            app(GeminiService::class)->generateContent('gemini-test-primary', 'anything', [], null, false, false, 1);
        }

        /*
           Counting only our own. The service also reports the individual
           exceptions behind each attempt — existing behaviour, and not what the
           throttle governs.

           Counted by asserting twice rather than by reading the handler's
           reported() list: that method belongs to the fake, and the container
           binding is typed as the real handler, which has no such method.
        */
        $outages = 0;
        Exceptions::assertReported(function (GeminiUnavailable $e) use (&$outages) {
            $outages++;

            return true;
        });

        $this->assertSame(1, $outages, 'a sustained outage should report once per window, not once per call');
    }

    public function test_a_successful_call_reports_nothing(): void
    {
        // The token exchange goes through google/auth, not Laravel's HTTP
        // client, so Http::fake() cannot reach it and a machine without
        // credentials would fail before the request. Seeding the cached token
        // is what lets this assert the happy path anywhere.
        Cache::put('gcp_vertex_access_token', 'test-token', 600);

        // streamGenerateContent returns a LIST of chunks, not one object — a
        // single-object fake iterates its keys and finds no text, which looks
        // exactly like a failure.
        Http::fake(['*' => Http::response([
            [
                'candidates' => [['content' => ['parts' => [['text' => 'hello']]]]],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5],
            ],
        ], 200)]);
        Exceptions::fake();

        app(GeminiService::class)->generateContent('gemini-test-primary', 'anything', [], null, false, false, 1);

        Exceptions::assertNotReported(GeminiUnavailable::class);
    }
}
