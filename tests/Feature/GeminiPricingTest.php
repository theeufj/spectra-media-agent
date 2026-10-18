<?php

namespace Tests\Feature;

use App\Models\AiCost;
use App\Services\GeminiService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A model with no pricing entry costs nothing, as far as this platform knows.
 *
 * calculateCost returns 0.0 for an unrecognised model, so swapping the default
 * model without adding its rates turns the entire AI spend figure into zero
 * while every call keeps working. Nothing else in the system would contradict
 * it — the dashboard would simply report that the platform is free to run.
 *
 * Introductory rates also expire while production config remains cached, so
 * each call must choose the rates for the date on which it was made.
 */
class GeminiPricingTest extends TestCase
{
    use DatabaseTransactions;

    private function service(): GeminiService
    {
        return app(GeminiService::class);
    }

    public function test_every_configured_text_tier_has_pricing(): void
    {
        // The check that would have caught a model swap leaving spend at zero.
        foreach (['pro', 'default', 'lite'] as $tier) {
            $model = config("ai.models.{$tier}");

            $this->assertArrayHasKey(
                $model,
                config('ai.pricing'),
                "{$tier} model {$model} has no pricing entry, so its AI spend records as zero"
            );
        }
    }

    public function test_every_model_in_the_fallback_chain_has_pricing(): void
    {
        $pricing = config('ai.pricing');

        foreach (config('ai.fallback_chain') as $from => $to) {
            $this->assertArrayHasKey($from, $pricing, "no pricing for {$from}");
            $this->assertArrayHasKey($to, $pricing, "no pricing for fallback target {$to}");
        }
    }

    public function test_introductory_rates_apply_before_they_expire(): void
    {
        Carbon::setTestNow('2026-08-17');

        // 1M input + 1M output at the introductory rate.
        $cost = $this->service()->calculateCost('gemini-3.7-flash', 1_000_000, 1_000_000);

        $this->assertEqualsWithDelta(0.75 + 3.75, $cost, 0.0001);
    }

    public function test_standard_rates_apply_once_they_start(): void
    {
        // The day the introductory period ends. Without a per-call check this
        // would keep reporting half the real cost.
        Carbon::setTestNow('2027-01-01');

        $cost = $this->service()->calculateCost('gemini-3.7-flash', 1_000_000, 1_000_000);

        $this->assertEqualsWithDelta(1.50 + 7.50, $cost, 0.0001);
    }

    public function test_argon_introductory_rates_apply_through_november(): void
    {
        Carbon::setTestNow('2026-11-30 23:59:59');

        $cost = $this->service()->calculateCost('gemini-4-argon', 1_000_000, 1_000_000, 1_000_000);

        $this->assertEqualsWithDelta(2.00 + 10.00 + 0.10, $cost, 0.000001);
    }

    public function test_argon_standard_rates_start_in_december_without_reloading_config(): void
    {
        Carbon::setTestNow('2026-11-30 23:59:59');
        $service = $this->service();
        $cachedPricing = config('ai.pricing');

        $this->assertEqualsWithDelta(
            12.10,
            $service->calculateCost('gemini-4-argon', 1_000_000, 1_000_000, 1_000_000),
            0.000001
        );

        Carbon::setTestNow('2026-12-01 00:00:00');

        $this->assertSame($cachedPricing, config('ai.pricing'));
        $this->assertEqualsWithDelta(
            4.00 + 20.00 + 0.20,
            $service->calculateCost('gemini-4-argon', 1_000_000, 1_000_000, 1_000_000),
            0.000001
        );
    }

    public function test_streamed_argon_usage_bills_cached_input_once_and_includes_thinking_output(): void
    {
        Carbon::setTestNow('2026-10-18');
        $this->fakeArgonStream([
            'promptTokenCount' => 1_000_000,
            'cachedContentTokenCount' => 250_000,
            'candidatesTokenCount' => 100_000,
            'thoughtsTokenCount' => 200_000,
            'totalTokenCount' => 1_300_000,
        ]);

        $result = $this->service()->generateContent('gemini-4-argon', 'Plan the campaign', maxRetries: 1);

        $this->assertSame(['text' => 'Campaign plan'], $result);
        Http::assertSentCount(1);

        $cost = AiCost::where('model', 'gemini-4-argon')->sole();
        $this->assertSame(1_000_000, $cost->input_tokens);
        $this->assertSame(300_000, $cost->output_tokens);
        $this->assertSame(250_000, $cost->cached_tokens);
        // 750K fresh input ($1.50) + 300K output including thinking ($3)
        // + 250K cached input ($0.025). Cached input is not charged twice.
        $this->assertEqualsWithDelta(4.525, (float) $cost->cost, 0.000001);
    }

    public function test_inconsistent_cached_usage_cannot_create_negative_uncached_input_cost(): void
    {
        Carbon::setTestNow('2026-10-18');
        $this->fakeArgonStream([
            'promptTokenCount' => 100,
            'cachedContentTokenCount' => 150,
            'candidatesTokenCount' => 20,
            'thoughtsTokenCount' => 30,
        ]);

        $result = $this->service()->generateContent('gemini-4-argon', 'Plan the campaign', maxRetries: 1);

        $this->assertSame(['text' => 'Campaign plan'], $result);

        $cost = AiCost::where('model', 'gemini-4-argon')->sole();
        $this->assertSame(100, $cost->input_tokens);
        $this->assertSame(50, $cost->output_tokens);
        $this->assertSame(150, $cost->cached_tokens);
        $this->assertEqualsWithDelta(0.000515, (float) $cost->cost, 0.0000001);
    }

    private function fakeArgonStream(array $usage): void
    {
        config([
            'ai.text_provider' => 'gemini',
            'services.google.project_id' => 'test-project',
            'services.google.location' => 'us-central1',
            'services.google.credentials_path' => null,
        ]);
        Cache::put('gcp_vertex_access_token', 'test-token', 3000);

        Http::fake([
            'https://aiplatform.googleapis.com/*/gemini-4-argon:streamGenerateContent' => Http::response([
                [
                    'candidates' => [[
                        'content' => ['parts' => [['text' => 'Campaign ']]],
                    ]],
                ],
                [
                    'candidates' => [['content' => ['parts' => [
                        ['text' => 'Do not expose reasoning', 'thought' => true],
                        ['text' => 'plan'],
                    ]]]],
                    'usageMetadata' => $usage,
                ],
            ]),
        ]);
    }

    public function test_an_unknown_model_still_returns_zero_rather_than_failing(): void
    {
        // Zero is the wrong number but the right behaviour: a missing rate must
        // not take down the call that was already made and already billed.
        $this->assertSame(0.0, $this->service()->calculateCost('some-future-model', 1000, 1000));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }
}
