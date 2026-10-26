<?php

namespace Tests\Unit;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Campaigns\CampaignKeywordResearch;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CampaignAffordableDiscoveryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_affordable_discovery_receives_the_actual_offer_bounds_market_and_failed_forecasts(): void
    {
        $customer = (new Customer(['name' => 'Actual business', 'business_type' => 'Paid search setup',
            'website' => 'https://example.test', 'currency_code' => 'AUD']))->setRelation('brandGuideline', null);
        $service = new CapturingAffordableCampaignKeywordResearch($customer);
        $campaign = new Campaign(['product_focus' => 'One-time campaign setup', 'target_market' => 'Small businesses',
            'landing_page_url' => 'https://example.test/setup', 'geographic_targeting' => ['United States']]);
        $strategy = (new Strategy(['platform' => 'Google Ads', 'campaign_type' => 'search']))->setRelation('targetingConfig', null);
        $failedForecasts = [['kind' => 'specific', 'observations' => [['forecast' => ['clicks' => 0.1, 'period_days' => 4]]]]];
        $context = ['customer_id' => '1234567890', 'currency_code' => 'AUD',
            'geo_target_constants' => ['geoTargetConstants/2840'], 'language_constants' => ['languageConstants/1000'],
            'daily_budget_micros' => 50_000_000, 'max_cpc_bid_micros' => 10_000_000,
            'failed_candidates' => [['kind' => 'specific', 'keywords' => ['narrow setup phrase']]],
            'failed_forecasts' => $failedForecasts];

        $result = $service->discoverAffordableAlternatives($campaign, $strategy, $context,
            [['text' => 'narrow setup phrase', 'match_type' => 'EXACT']]);

        $this->assertSame('https://example.test/setup', $service->captured['landing_page_url']);
        $this->assertSame(['geoTargetConstants/2840'], $service->captured['geo_targets']);
        $this->assertSame('languageConstants/1000', $service->captured['language']);
        $business = $service->captured['business_context'];
        $this->assertSame('One-time campaign setup', $business['offer']);
        $this->assertSame('Small businesses', $business['audience']);
        $this->assertSame(50_000_000, $business['daily_budget_micros']);
        $this->assertSame(10_000_000, $business['max_cpc_bid_micros']);
        $this->assertSame('AUD', $business['currency_code']);
        $this->assertSame($failedForecasts, $business['failed_forecasts']);
        $this->assertSame(['narrow setup phrase'], $business['existing_keywords']);
        $this->assertTrue($result['discovery']['affordable_recovery_attempted']);
        $this->assertSame('affordable commercial task', $result['cost_candidates'][0]['text']);
        Http::assertNothingSent();
    }

    public function test_missing_platform_account_does_not_request_or_invent_affordable_market_evidence(): void
    {
        $service = new CapturingAffordableCampaignKeywordResearch(new Customer);

        $result = $service->discoverAffordableAlternatives(new Campaign, new Strategy, [], []);

        $this->assertSame([], $service->captured);
        $this->assertSame([], $result['keywords']);
        $this->assertSame([], $result['cost_candidates']);
        $this->assertTrue($result['discovery']['affordable_recovery_attempted']);
        Http::assertNothingSent();
    }
}

class CapturingAffordableCampaignKeywordResearch extends CampaignKeywordResearch
{
    public array $captured = [];

    public function researchAffordableAlternatives(
        string $customerId,
        string $businessName,
        ?string $industry = null,
        ?string $landingPageUrl = null,
        ?string $language = 'languageConstants/1000',
        array $geoTargets = [],
        int $maxKeywords = 20,
        array $userSeedKeywords = [],
        array $businessContext = []
    ): array {
        $this->captured = ['landing_page_url' => $landingPageUrl, 'language' => $language,
            'geo_targets' => $geoTargets, 'business_context' => $businessContext];

        return ['keywords' => [], 'cost_candidates' => [['text' => 'affordable commercial task', 'match_type' => 'PHRASE',
            'selection_reason' => 'Same supported buying task with measured demand and lower historical cost']],
            'negative_keywords' => [], 'discovery' => ['affordable_recovery_attempted' => true]];
    }
}
