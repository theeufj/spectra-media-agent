<?php

namespace Tests\Feature;

use App\Jobs\FetchFacebookAdsPerformanceData;
use App\Jobs\FetchLinkedInAdsPerformanceData;
use App\Jobs\FetchMicrosoftAdsPerformanceData;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Recommendation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * The Facebook, Microsoft and LinkedIn fetch jobs stored recommendations under
 * keys nothing produces.
 *
 * RecommendationGenerationService's deterministic branch emits
 * type / target_campaign_id / new_budget_amount / rationale, and the LLM branch
 * emits whatever the model returned. All three jobs read `$rec['target_entity']`
 * and `$rec['parameters']` with no guard, so an "Undefined array key" warning
 * became an ErrorException: Facebook rethrew, Microsoft and LinkedIn released
 * against $tries = 5. The mapping FetchGoogleAdsPerformanceData already carried
 * had never been applied to the other three.
 *
 * @see RecommendationMappingTest for the Google copy of these helpers.
 */
class PlatformRecommendationMappingTest extends TestCase
{
    use DatabaseTransactions;

    private ?Campaign $campaign = null;

    public static function jobProvider(): array
    {
        return [
            'facebook' => [FetchFacebookAdsPerformanceData::class, '120210000000000001'],
            'microsoft' => [FetchMicrosoftAdsPerformanceData::class, '512345678'],
            'linkedin' => [FetchLinkedInAdsPerformanceData::class, '987654321'],
        ];
    }

    /** @dataProvider jobProvider */
    public function test_the_deterministic_budget_shape_maps_without_throwing(string $class, string $platformCampaignId): void
    {
        // The exact shape the deterministic branch emits — no target_entity key,
        // no parameters key.
        $rec = [
            'type' => 'BUDGET_INCREASE',
            'target_campaign_id' => $platformCampaignId,
            'new_budget_amount' => 110.0,
            'rationale' => 'Hitting the cap.',
        ];

        $this->assertSame(
            ['target_campaign_id' => $platformCampaignId],
            $this->mapper($class, 'recommendationTarget', $rec)
        );
        $this->assertSame(
            110.0,
            $this->mapper($class, 'recommendationParameters', $rec)['new_budget_amount']
        );
    }

    /** @dataProvider jobProvider */
    public function test_a_recommendation_with_no_target_falls_back_to_this_platforms_campaign(string $class, string $platformCampaignId): void
    {
        // Better a recommendation attached to the campaign than an exception
        // that costs five retries.
        $target = $this->mapper($class, 'recommendationTarget', ['type' => 'X', 'rationale' => 'Y']);

        $this->assertSame(['campaign_id' => $platformCampaignId], $target);
    }

    /** @dataProvider jobProvider */
    public function test_an_unrecognised_llm_shape_is_kept_rather_than_dropped(string $class, string $platformCampaignId): void
    {
        $rec = ['type' => 'SOMETHING_NEW', 'unexpected_field' => 'value', 'rationale' => 'Because.'];

        $parameters = $this->mapper($class, 'recommendationParameters', $rec);

        $this->assertSame('value', $parameters['unexpected_field']);
        $this->assertArrayNotHasKey('rationale', $parameters, 'narrative is not a parameter');
        $this->assertArrayNotHasKey('type', $parameters);
    }

    /** @dataProvider jobProvider */
    public function test_the_mapped_values_persist_as_a_recommendation(string $class, string $platformCampaignId): void
    {
        $rec = [
            'type' => 'BUDGET_INCREASE',
            'target_campaign_id' => $platformCampaignId,
            'new_budget_amount' => 110.0,
            'rationale' => 'Hitting the cap.',
        ];

        $recommendation = Recommendation::create([
            'campaign_id' => $this->campaign()->id,
            'type' => $rec['type'],
            'target_entity' => $this->mapper($class, 'recommendationTarget', $rec),
            'parameters' => $this->mapper($class, 'recommendationParameters', $rec),
            'rationale' => $rec['rationale'],
            'status' => 'pending',
        ])->fresh();

        $this->assertSame(['target_campaign_id' => $platformCampaignId], $recommendation->target_entity);
        // Cast: parameters is a json column, and JSON has one number type — a
        // budget of 110.0 comes back as int 110. The value is what matters here,
        // not which PHP type it survived the round trip as.
        $this->assertSame(110.0, (float) $recommendation->parameters['new_budget_amount']);
    }

    private function campaign(): Campaign
    {
        // Built once per test: a second customer would collide on the unique
        // platform account identifiers.
        if ($this->campaign !== null) {
            return $this->campaign;
        }

        $customer = Customer::factory()->create();

        return $this->campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'facebook_ads_campaign_id' => '120210000000000001',
            'microsoft_ads_campaign_id' => '512345678',
            'linkedin_campaign_id' => '987654321',
        ]);
    }

    private function mapper(string $class, string $method, array $rec): array
    {
        $reflection = new \ReflectionMethod($class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(new $class($this->campaign()), $rec);
    }
}
