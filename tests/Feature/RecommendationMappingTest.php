<?php

namespace Tests\Feature;

use App\Jobs\FetchGoogleAdsPerformanceData;
use App\Models\Campaign;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The job stored recommendations under keys nothing produced.
 *
 * RecommendationGenerationService emits target_campaign_id / new_budget_amount
 * for a budget change and keyword_text for a keyword one, plus whatever shape
 * the LLM path returns. This job read 'target_entity' and 'parameters' — keys
 * none of those produce — so every run threw "Undefined array key", released,
 * and burned all five retries. Fourteen failed jobs in a single day, and not one
 * recommendation was ever stored.
 *
 * The mapping is deliberately tolerant. A shape this job does not recognise is
 * still worth keeping; dropping it silently is how the original assumption
 * survived long enough to fail every night.
 */
class RecommendationMappingTest extends TestCase
{
    use RefreshDatabase;

    private ?FetchGoogleAdsPerformanceData $job = null;

    private function job(): FetchGoogleAdsPerformanceData
    {
        // Built once per test: google_ads_customer_id is unique, so creating a
        // customer per call collides on the second one.
        if ($this->job !== null) {
            return $this->job;
        }

        $customer = Customer::factory()->create(['google_ads_customer_id' => '3598653839']);

        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'google_ads_campaign_id' => 'customers/3598653839/campaigns/24045681965',
        ]);

        return $this->job = new FetchGoogleAdsPerformanceData($campaign);
    }

    private function mapper(string $method, array $rec): array
    {
        $reflection = new \ReflectionMethod(FetchGoogleAdsPerformanceData::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($this->job(), $rec);
    }

    public function test_a_budget_recommendation_maps_without_throwing(): void
    {
        // The exact shape that was failing nightly.
        $rec = [
            'type' => 'BUDGET_INCREASE',
            'target_campaign_id' => '24045681965',
            'new_budget_amount' => 110.0,
            'rationale' => 'Hitting the cap.',
        ];

        $this->assertSame(['target_campaign_id' => '24045681965'], $this->mapper('recommendationTarget', $rec));
        $this->assertSame(110.0, $this->mapper('recommendationParameters', $rec)['new_budget_amount']);
    }

    public function test_a_keyword_recommendation_maps_to_its_keyword(): void
    {
        $rec = ['type' => 'KEYWORD', 'keyword_text' => 'plumber sydney', 'rationale' => 'High intent.'];

        $this->assertSame(['keyword_text' => 'plumber sydney'], $this->mapper('recommendationTarget', $rec));
    }

    public function test_an_unrecognised_shape_is_kept_rather_than_dropped(): void
    {
        // The LLM path returns whatever the model produced. Storing it is more
        // useful than discarding it, and far more useful than throwing.
        $rec = ['type' => 'SOMETHING_NEW', 'unexpected_field' => 'value', 'rationale' => 'Because.'];

        $parameters = $this->mapper('recommendationParameters', $rec);

        $this->assertSame('value', $parameters['unexpected_field']);
        $this->assertArrayNotHasKey('rationale', $parameters, 'narrative is not a parameter');
        $this->assertArrayNotHasKey('type', $parameters);
    }

    public function test_a_recommendation_with_no_target_falls_back_to_the_campaign(): void
    {
        // Better a recommendation attached to the campaign than an exception
        // that costs five retries.
        $target = $this->mapper('recommendationTarget', ['type' => 'X', 'rationale' => 'Y']);

        $this->assertSame(
            ['campaign_id' => 'customers/3598653839/campaigns/24045681965'],
            $target
        );
    }

    public function test_an_explicit_parameters_array_is_respected(): void
    {
        $rec = ['type' => 'X', 'parameters' => ['a' => 1], 'other' => 'ignored'];

        $this->assertSame(['a' => 1], $this->mapper('recommendationParameters', $rec));
    }
}
