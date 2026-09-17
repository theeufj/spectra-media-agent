<?php

namespace Tests\Feature;

use App\Services\GeminiService;
use App\Services\XaiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Text goes to Grok on xAI's own API, and falls back rather than failing.
 *
 * Every text path in the product — ad copy, strategy, brand extraction, the
 * copilot, the public demo — arrives at GeminiService::generateContent(), so
 * that is the one place the provider can be chosen without touching the 53
 * files that inject the class by name.
 *
 * Two vendors on two balances is the point. A dry Google balance once returned
 * 403 BILLING_DISABLED on every text call and took strategy, brand extraction,
 * creative, the copilot and the demo down together for a day; the same is now
 * true in reverse, and neither provider being down is an outage on its own.
 */
class XaiRoutingTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.xai.api_key' => 'test-xai-key']);
        \Illuminate\Support\Facades\Cache::forget('xai:unavailable');
    }

    private function xaiAnswers(string $text = 'Grok answered'): void
    {
        Http::fake([
            'api.x.ai/*' => Http::response([
                'choices' => [['message' => ['content' => $text], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ], 200),
        ]);
    }

    public function test_text_goes_to_xai_when_configured(): void
    {
        config(['ai.text_provider' => 'xai']);
        $this->xaiAnswers();

        $result = app(GeminiService::class)->generateContent(
            config('ai.models.default'),
            'Write three headlines.',
        );

        $this->assertSame('Grok answered', $result['text']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.x.ai'));
    }

    public function test_it_uses_xais_own_model_names_not_openrouters(): void
    {
        config(['ai.text_provider' => 'xai']);
        $this->xaiAnswers();

        app(GeminiService::class)->generateContent(config('ai.models.default'), 'Write copy.');

        /*
           OpenRouter namespaces every model ("x-ai/grok-4-fast") and xAI does
           not. Sending one to the other is a 404 at request time and nothing
           sooner, which is why they are separate config keys.
        */
        Http::assertSent(function ($request) {
            $model = $request->data()['model'] ?? '';

            return str_contains($request->url(), 'api.x.ai')
                && $model !== ''
                && ! str_starts_with($model, 'x-ai/');
        });
    }

    public function test_gemini_still_answers_when_that_is_the_setting(): void
    {
        config(['ai.text_provider' => 'gemini']);
        Http::fake(['api.x.ai/*' => Http::response([], 500)]);

        // Nothing should reach xAI at all — the setting is not advisory.
        app(GeminiService::class)->generateContent(config('ai.models.default'), 'Write copy.');

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.x.ai'));
    }

    public function test_a_vision_call_never_routes_to_xai(): void
    {
        config(['ai.text_provider' => 'xai']);
        Http::fake();

        /*
           An image in the prompt is a vision call and this client sends text
           only. Routing one there would drop the picture and answer
           confidently about nothing, which is worse than ignoring the setting.
        */
        app(GeminiService::class)->generateContent(
            config('ai.models.default'),
            'What is in this image?',
            imageBase64: base64_encode('not-really-an-image'),
        );

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.x.ai'));
    }

    public function test_a_grounded_call_never_routes_to_xai(): void
    {
        config(['ai.text_provider' => 'xai']);
        Http::fake();

        // Google Search grounding is Gemini's own feature: a caller asking for
        // it wants answers checked against the live web, and a model that
        // cannot do that returns something fluent and unchecked.
        app(GeminiService::class)->generateContent(
            config('ai.models.default'),
            'What happened this week?',
            enableGoogleSearch: true,
        );

        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.x.ai'));
    }

    public function test_an_empty_balance_stops_us_asking_again(): void
    {
        config(['ai.text_provider' => 'xai']);
        Http::fake(['api.x.ai/*' => Http::response(['error' => 'Insufficient credits'], 402)]);

        $service = app(XaiService::class);

        $this->assertNull($service->generateText('Write copy.'));
        $this->assertTrue(XaiService::isUnavailable());

        // The second call must not reach the network: it is the multiplication
        // that took the other provider down, not the single failure.
        $this->assertNull($service->generateText('Write more copy.'));
        Http::assertSentCount(1);
    }

    public function test_every_call_records_what_it_cost(): void
    {
        config(['ai.text_provider' => 'xai']);

        Http::fake([
            'api.x.ai/*' => Http::response([
                'choices' => [['message' => ['content' => 'Grok answered'], 'finish_reason' => 'stop']],
                // A round million each way, so the expected figure is the
                // published per-million rate itself rather than a rounding of it.
                'usage' => ['prompt_tokens' => 1_000_000, 'completion_tokens' => 1_000_000],
            ], 200),
        ]);

        app(GeminiService::class)->generateContent(config('ai.models.default'), 'Write three headlines.');

        /*
           This is the assertion that was missing, and its absence cost us
           every xAI figure since the provider went live. The write named the
           column 'total_cost'; the column is 'cost'. Eloquent drops an
           unfillable key without complaint, Postgres then refuses the row for
           a null cost, and the catch turns that into a log line — so the calls
           succeeded, the spend was real, and the admin cost dashboard showed
           xAI as costing nothing at all. A test that asserted routing but
           never that a row landed could not see any of it.
        */
        $row = \App\Models\AiCost::where('service', 'xAI')->latest('id')->first();

        $this->assertNotNull($row, 'an xAI call must leave a cost row behind');
        $this->assertSame('grok-4-fast', $row->model);
        $this->assertEqualsWithDelta(0.70, (float) $row->cost, 0.000001);
    }
}
