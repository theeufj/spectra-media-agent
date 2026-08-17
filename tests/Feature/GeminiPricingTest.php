<?php

namespace Tests\Feature;

use App\Services\GeminiService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * A model with no pricing entry costs nothing, as far as this platform knows.
 *
 * calculateCost returns 0.0 for an unrecognised model, so swapping the default
 * model without adding its rates turns the entire AI spend figure into zero
 * while every call keeps working. Nothing else in the system would contradict
 * it — the dashboard would simply report that the platform is free to run.
 *
 * gemini-3.7-flash also carries introductory pricing that ends on 1 Jan 2027 and
 * then doubles. Config is cached in production, so the switch has to be decided
 * per call rather than when the cache was written.
 */
class GeminiPricingTest extends TestCase
{
    private function service(): GeminiService
    {
        return app(GeminiService::class);
    }

    public function test_the_default_model_has_pricing(): void
    {
        // The check that would have caught a model swap leaving spend at zero.
        $model = config('ai.models.default');

        $this->assertArrayHasKey(
            $model,
            config('ai.pricing'),
            "default model {$model} has no pricing entry, so all AI spend records as zero"
        );
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
