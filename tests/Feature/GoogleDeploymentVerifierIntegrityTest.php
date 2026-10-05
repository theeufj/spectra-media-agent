<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Deployment\DeploymentVerifier;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleDeploymentVerifierIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    private function workspace(array $attributes = []): array
    {
        $customer = Customer::factory()->create(['website' => null, 'google_ads_customer_id' => '1234567890']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'google_ads_campaign_id' => 'customers/1234567890/campaigns/61']);
        $strategy = Strategy::factory()->create(array_merge(['campaign_id' => $campaign->id, 'platform' => 'Google Ads (SEM)', 'campaign_type' => 'search', 'google_ads_campaign_id' => '222', 'deployment_status' => 'deploy_unverified'], $attributes));

        return [$customer, $campaign, $strategy];
    }

    private function statusService(string $resource): void
    {
        $service = $this->createMock(GetCampaignStatus::class);
        $service->expects($this->once())->method('__invoke')->with('1234567890', $resource)->willReturn(['status' => 2]);
        $this->app->bind(GetCampaignStatus::class, fn () => $service);
    }

    private function configuration(): array
    {
        $expected = ['version' => 1, 'keywords' => [['text' => 'service', 'match_type' => 'EXACT']],
            'ads' => [['headlines' => ['Service'], 'descriptions' => ['Book service'], 'final_urls' => ['https://example.com/service']]],
            'networks' => ['targetGoogleSearch' => true, 'targetSearchNetwork' => false, 'targetContentNetwork' => false],
            'locations' => [], 'location_mode' => 'PRESENCE', 'budget_micros' => 50000000, 'bidding' => 'MANUAL_CPC',
            'conversion_goal' => ['mode' => 'category', 'category' => 'SIGNUP', 'origin' => 'WEBSITE', 'action_resource' => 'customers/1234567890/conversionActions/7']];
        $actual = ['campaign' => ['campaign' => ['advertisingChannelType' => 'SEARCH', 'networkSettings' => ['targetGoogleSearch' => true], 'geoTargetTypeSetting' => ['positiveGeoTargetType' => 'PRESENCE'], 'biddingStrategyType' => 'MANUAL_CPC'], 'campaignBudget' => ['amountMicros' => 50000000]],
            'keywords' => [['adGroupCriterion' => ['keyword' => ['text' => 'service', 'matchType' => 'EXACT']]]],
            'ads' => [['adGroupAd' => ['ad' => ['responsiveSearchAd' => ['headlines' => [['text' => 'Service']], 'descriptions' => [['text' => 'Book service']]], 'finalUrls' => ['https://example.com/service']], 'policySummary' => ['approvalStatus' => 'APPROVED']]]],
            'criteria' => [], 'assets' => [],
            'goals' => [['campaignConversionGoal' => ['category' => 'SIGNUP', 'origin' => 'WEBSITE', 'biddable' => true]]],
            'conversion_goal_config' => ['conversionGoalCampaignConfig' => ['goalConfigLevel' => 'CAMPAIGN']],
            'conversion_actions' => [['conversionAction' => ['resourceName' => 'customers/1234567890/conversionActions/7', 'status' => 'ENABLED', 'category' => 'SIGNUP', 'origin' => 'WEBSITE', 'primaryForGoal' => true]]]];

        return [$expected, $actual];
    }

    private function reader(callable $read): void
    {
        $service = $this->createMock(ReadCampaignConfiguration::class);
        $service->expects($this->once())->method('read')->with('1234567890', 'customers/1234567890/campaigns/222')->willReturnCallback($read);
        $this->app->bind(ReadCampaignConfiguration::class, fn () => $service);
    }

    public function test_strategy_campaign_id_wins_over_parent_and_stale_result_ids(): void
    {
        [$customer, $campaign, $strategy] = $this->workspace(['execution_result' => ['platform_ids' => ['campaign' => 'customers/1234567890/campaigns/111']]]);
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads', 'google_ads_campaign_id' => '333']);
        $this->statusService('customers/1234567890/campaigns/222');
        $this->assertTrue((new DeploymentVerifier)->verify($strategy, $customer, false));
        Http::assertNothingSent();
    }

    public function test_recorded_result_id_wins_over_legacy_parent_fallback(): void
    {
        [$customer, $campaign, $strategy] = $this->workspace(['google_ads_campaign_id' => null, 'execution_result' => ['platform_ids' => ['campaign' => '999']]]);
        $this->statusService('customers/1234567890/campaigns/999');
        $this->assertTrue((new DeploymentVerifier)->verify($strategy, $customer, false));
    }

    public function test_only_first_google_strategy_can_verify_the_legacy_parent_campaign(): void
    {
        [$customer, $campaign, $first] = $this->workspace(['google_ads_campaign_id' => null]);
        $second = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads (SEM)', 'google_ads_campaign_id' => null]);
        $this->statusService('customers/1234567890/campaigns/61');
        $this->assertTrue((new DeploymentVerifier)->verify($first, $customer, false));
        $this->assertFalse((new DeploymentVerifier)->verify($second, $customer, false));
    }

    public function test_legacy_display_default_with_executed_search_baseline_receives_strict_configuration_check(): void
    {
        [$baseline, $actual] = $this->configuration();
        [$customer, $campaign, $strategy] = $this->workspace(['campaign_type' => 'display', 'execution_result' => ['metadata' => ['google_search_baseline' => $baseline]]]);
        $actual['campaign']['campaignBudget']['amountMicros'] = 10000000;
        $this->statusService('customers/1234567890/campaigns/222');
        $this->reader(fn () => $actual);
        $this->assertFalse((new DeploymentVerifier)->verify($strategy, $customer));
        $state = $strategy->fresh()->execution_result['metadata']['configuration_verification'];
        $this->assertFalse($state['passed']);
        $this->assertStringContainsString('daily budget', implode(' ', $state['issues']));
    }

    public function test_verifier_preserves_concurrent_goal_ownership_and_readiness_written_during_api_read(): void
    {
        [$baseline, $actual] = $this->configuration();
        [$customer, $campaign, $strategy] = $this->workspace(['execution_result' => ['metadata' => ['google_search_baseline' => $baseline, 'google_readiness' => ['status' => 'ready', 'ready' => true]]]]);
        $this->statusService('customers/1234567890/campaigns/222');
        $this->reader(function () use ($strategy, $actual) {
            $concurrent = $strategy->fresh();
            $execution = $concurrent->execution_result;
            $execution['metadata']['google_readiness'] = ['status' => 'unknown', 'ready' => false, 'issues' => [['code' => 'conversion_check_failed', 'message' => 'Current goal readiness is unknown.']]];
            $execution['metadata']['conversion_goal_readiness'] = ['status' => 'unknown', 'ready' => false, 'managed_custom_goal' => 'customers/1234567890/customConversionGoals/123'];
            $concurrent->update(['execution_result' => $execution]);

            return $actual;
        });
        $this->assertTrue((new DeploymentVerifier)->verify($strategy, $customer));
        $metadata = $strategy->fresh()->execution_result['metadata'];
        $this->assertSame('unknown', $metadata['google_readiness']['status']);
        $this->assertSame('unknown', $metadata['conversion_goal_readiness']['status']);
        $this->assertSame('customers/1234567890/customConversionGoals/123', $metadata['conversion_goal_readiness']['managed_custom_goal']);
        $this->assertTrue($metadata['configuration_verification']['passed']);
        $this->assertEquals($baseline, $metadata['google_search_baseline']);
        Http::assertNothingSent();
    }

    public function test_changed_baseline_during_api_read_cannot_be_marked_verified(): void
    {
        [$baseline, $actual] = $this->configuration();
        [$customer, $campaign, $strategy] = $this->workspace(['execution_result' => ['metadata' => ['google_search_baseline' => $baseline]]]);
        $this->statusService('customers/1234567890/campaigns/222');
        $this->reader(function () use ($strategy, $actual) {
            $concurrent = $strategy->fresh();
            $execution = $concurrent->execution_result;
            $execution['metadata']['google_search_baseline']['budget_micros'] = 70000000;
            $concurrent->update(['execution_result' => $execution]);

            return $actual;
        });
        $this->assertFalse((new DeploymentVerifier)->verify($strategy, $customer));
        $metadata = $strategy->fresh()->execution_result['metadata'];
        $this->assertSame(70000000, $metadata['google_search_baseline']['budget_micros']);
        $this->assertFalse($metadata['configuration_verification']['passed']);
        $this->assertStringContainsString('changed during verification', implode(' ', $metadata['configuration_verification']['issues']));
    }
}
