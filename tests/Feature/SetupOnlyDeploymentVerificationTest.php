<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Deployment\DeploymentVerifier;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SetupOnlyDeploymentVerificationTest extends TestCase
{
    use DatabaseTransactions;

    private const RESOURCE = 'customers/1234567890/campaigns/222';

    private function workspace(array $customerAttributes = []): array
    {
        $customer = Customer::factory()->create(array_merge([
            'website' => null, 'google_ads_customer_id' => '1234567890',
            'service_type' => 'setup_only', 'setup_fee_paid_at' => now(),
        ], $customerAttributes));
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id, 'daily_budget' => '5.00',
            'approved_daily_budget' => '5.00', 'budget_confirmed_at' => now(),
        ]);
        $baseline = ['version' => 1, 'keywords' => [['text' => 'service', 'match_type' => 'EXACT']],
            'ads' => [['headlines' => ['Service'], 'descriptions' => ['Book service'], 'final_urls' => ['https://example.com/service']]],
            'networks' => ['targetGoogleSearch' => true, 'targetSearchNetwork' => false, 'targetContentNetwork' => false],
            'locations' => [], 'location_mode' => 'PRESENCE', 'budget_micros' => 5_000_000, 'bidding' => 'MANUAL_CPC',
            'conversion_category' => 'LEAD', 'conversion_goal' => null];
        $strategy = Strategy::factory()->create([
            'campaign_id' => $campaign->id, 'platform' => 'Google Ads (SEM)', 'campaign_type' => 'search',
            'daily_budget' => '5.00', 'signed_off_at' => now(), 'google_ads_campaign_id' => self::RESOURCE,
            'deployment_status' => 'deployed', 'execution_result' => ['metadata' => [
                'google_search_baseline' => $baseline,
                'google_search_staging' => ['mode' => 'setup_only_paused_handover', 'campaign_resource' => self::RESOURCE,
                    'verified_platform_status' => 'PAUSED', 'enable_after_success' => false],
                'conversion_goal_readiness' => ['ready' => false, 'status' => 'needs_review',
                    'issues' => [['code' => 'conversion_action_missing', 'message' => 'The reviewed lead action does not exist.']]],
                'search_reach_preflight' => ['status' => 'not_checked', 'gate' => ['ready' => false, 'status' => 'needs_review',
                    'issues' => [['code' => 'setup_search_reach_activation_required', 'message' => 'Reach must be checked before activation.']]]],
            ]],
        ]);
        $actual = ['campaign' => ['campaign' => ['advertisingChannelType' => 'SEARCH',
            'networkSettings' => ['targetGoogleSearch' => true], 'geoTargetTypeSetting' => ['positiveGeoTargetType' => 'PRESENCE'],
            'biddingStrategyType' => 'MANUAL_CPC'], 'campaignBudget' => ['amountMicros' => 5_000_000]],
            'keywords' => [['adGroupCriterion' => ['keyword' => ['text' => 'service', 'matchType' => 'EXACT']]]],
            'ads' => [['adGroupAd' => ['ad' => ['responsiveSearchAd' => ['headlines' => [['text' => 'Service']],
                'descriptions' => [['text' => 'Book service']]], 'finalUrls' => ['https://example.com/service']],
                'policySummary' => ['approvalStatus' => 'APPROVED']]]],
            'criteria' => [], 'assets' => [], 'goals' => [], 'conversion_actions' => [], 'conversion_goal_config' => [],
        ];

        return [$customer, $campaign, $strategy, $actual];
    }

    private function readback(?array $status, array $configuration, ?callable $duringRead = null): void
    {
        $statuses = $this->createMock(GetCampaignStatus::class);
        $statuses->method('__invoke')->with('1234567890', self::RESOURCE)->willReturn($status);
        $reader = $this->createMock(ReadCampaignConfiguration::class);
        $reader->method('read')->with('1234567890', self::RESOURCE)->willReturnCallback(function () use ($configuration, $duringRead) {
            if ($duringRead) {
                $duringRead();
            }

            return $configuration;
        });
        $this->app->bind(GetCampaignStatus::class, fn () => $statuses);
        $this->app->bind(ReadCampaignConfiguration::class, fn () => $reader);
    }

    public function test_a_paid_setup_can_verify_paused_objects_without_certifying_activation(): void
    {
        [$customer, , $strategy, $actual] = $this->workspace();
        $before = $strategy->execution_result['metadata'];
        $this->readback(['status' => CampaignStatus::PAUSED], $actual);

        $this->assertTrue((new DeploymentVerifier)->verify($strategy, $customer));

        $metadata = $strategy->fresh()->execution_result['metadata'];
        $this->assertTrue($metadata['configuration_verification']['passed']);
        $this->assertSame('paused_objects', $metadata['configuration_verification']['scope']);
        $this->assertFalse($metadata['activation_verification']['ready']);
        $this->assertNotEmpty($metadata['activation_verification']['conversion_goal_issues']);
        $this->assertSame($before['conversion_goal_readiness'], $metadata['conversion_goal_readiness']);
        $this->assertSame($before['search_reach_preflight'], $metadata['search_reach_preflight']);
        $this->assertFalse($metadata['activation_verification']['search_reach']['ready']);
        Http::assertNothingSent();
    }

    public static function strictCustomers(): array
    {
        return ['managed' => [['service_type' => 'managed']], 'unpaid setup' => [['setup_fee_paid_at' => null]]];
    }

    #[DataProvider('strictCustomers')]
    public function test_other_customers_still_require_the_actual_conversion_goal(array $attributes): void
    {
        [$customer, , $strategy, $actual] = $this->workspace($attributes);
        $this->readback(['status' => CampaignStatus::PAUSED], $actual);

        $this->assertFalse((new DeploymentVerifier)->verify($strategy, $customer));
        $this->assertFalse($strategy->fresh()->execution_result['metadata']['configuration_verification']['passed']);
    }

    public function test_an_arbitrary_paused_setup_without_the_explicit_handover_marker_remains_strict(): void
    {
        [$customer, , $strategy, $actual] = $this->workspace();
        $execution = $strategy->execution_result;
        unset($execution['metadata']['google_search_staging']['mode']);
        $strategy->update(['execution_result' => $execution]);
        $this->readback(['status' => CampaignStatus::PAUSED], $actual);

        $this->assertFalse((new DeploymentVerifier)->verify($strategy, $customer));
    }

    public static function unsafeStatuses(): array
    {
        return ['enabled' => [['status' => CampaignStatus::ENABLED]], 'unknown' => [['status' => CampaignStatus::UNKNOWN]],
            'absent status' => [[]], 'not found' => [null]];
    }

    #[DataProvider('unsafeStatuses')]
    public function test_enabled_or_unknown_campaigns_cannot_be_verified_as_a_paused_handover(?array $status): void
    {
        [$customer, , $strategy, $actual] = $this->workspace();
        $this->readback($status, $actual);

        $this->assertFalse((new DeploymentVerifier)->verify($strategy, $customer));
    }

    public static function changedConfiguration(): array
    {
        return ['budget' => ['budget'], 'keywords' => ['keywords'], 'reviewed ad' => ['ads'], 'bidding' => ['bidding']];
    }

    #[DataProvider('changedConfiguration')]
    public function test_paused_handover_still_requires_the_reviewed_objects_and_configuration(string $change): void
    {
        [$customer, , $strategy, $actual] = $this->workspace();
        if ($change === 'budget') {
            $actual['campaign']['campaignBudget']['amountMicros'] = 50_000_000;
        } elseif ($change === 'bidding') {
            $actual['campaign']['campaign']['biddingStrategyType'] = 'MAXIMIZE_CONVERSIONS';
        } else {
            $actual[$change] = [];
        }
        $this->readback(['status' => CampaignStatus::PAUSED], $actual);

        $this->assertFalse((new DeploymentVerifier)->verify($strategy, $customer));
        $this->assertFalse($strategy->fresh()->execution_result['metadata']['configuration_verification']['passed']);
    }

    public function test_customer_eligibility_is_reloaded_after_readback(): void
    {
        [$customer, , $strategy, $actual] = $this->workspace();
        $this->readback(['status' => CampaignStatus::PAUSED], $actual,
            fn () => Customer::whereKey($customer->id)->update(['service_type' => 'managed']));

        $this->assertFalse((new DeploymentVerifier)->verify($strategy, $customer));
        $this->assertFalse($strategy->fresh()->execution_result['metadata']['configuration_verification']['passed']);
    }

    public function test_unready_goals_cannot_be_handed_over_on_smart_bidding_even_when_it_matches_the_baseline(): void
    {
        [$customer, , $strategy, $actual] = $this->workspace();
        $execution = $strategy->execution_result;
        $execution['metadata']['google_search_baseline']['bidding'] = 'MAXIMIZE_CONVERSIONS';
        $strategy->update(['execution_result' => $execution]);
        $actual['campaign']['campaign']['biddingStrategyType'] = 'MAXIMIZE_CONVERSIONS';
        $this->readback(['status' => CampaignStatus::PAUSED], $actual);

        $this->assertFalse((new DeploymentVerifier)->verify($strategy, $customer));

        $metadata = $strategy->fresh()->execution_result['metadata'];
        $this->assertStringContainsString('must use Manual CPC', implode(' ', $metadata['configuration_verification']['issues']));
    }

    public function test_the_final_platform_read_must_still_confirm_the_campaign_is_paused(): void
    {
        [$customer, , $strategy, $actual] = $this->workspace();
        $this->readback(['status' => CampaignStatus::PAUSED], $actual);
        $statuses = $this->createMock(GetCampaignStatus::class);
        $statuses->expects($this->exactly(2))->method('__invoke')->with('1234567890', self::RESOURCE)
            ->willReturnOnConsecutiveCalls(['status' => CampaignStatus::PAUSED], ['status' => CampaignStatus::ENABLED]);
        $this->app->bind(GetCampaignStatus::class, fn () => $statuses);

        $this->assertFalse((new DeploymentVerifier)->verify($strategy, $customer));

        $metadata = $strategy->fresh()->execution_result['metadata'];
        $this->assertFalse($metadata['configuration_verification']['passed']);
        $this->assertStringContainsString('verified this handover campaign as paused', implode(' ', $metadata['configuration_verification']['issues']));
    }

    public function test_an_approval_changed_during_readback_cannot_be_verified_from_the_old_baseline(): void
    {
        [$customer, $campaign, $strategy, $actual] = $this->workspace();
        $this->readback(['status' => CampaignStatus::PAUSED], $actual,
            fn () => Campaign::whereKey($campaign->id)->update(['approved_daily_budget' => '4.00']));

        $this->assertFalse((new DeploymentVerifier)->verify($strategy, $customer));

        $metadata = $strategy->fresh()->execution_result['metadata'];
        $this->assertStringContainsString('current reviewed strategy allocation and approval', implode(' ', $metadata['configuration_verification']['issues']));
    }

    public function test_paused_object_verification_preserves_concurrent_conversion_readiness_and_ownership(): void
    {
        [$customer, , $strategy, $actual] = $this->workspace();
        $latest = ['ready' => false, 'status' => 'unknown', 'managed_custom_goal' => 'customers/1234567890/customConversionGoals/7',
            'issues' => [['code' => 'conversion_readiness_unavailable', 'message' => 'Fresh conversion readiness is unknown.']]];
        $this->readback(['status' => CampaignStatus::PAUSED], $actual, function () use ($strategy, $latest) {
            $execution = $strategy->fresh()->execution_result;
            $execution['metadata']['conversion_goal_readiness'] = $latest;
            $strategy->fresh()->update(['execution_result' => $execution]);
        });

        $this->assertTrue((new DeploymentVerifier)->verify($strategy, $customer));

        $metadata = $strategy->fresh()->execution_result['metadata'];
        $this->assertSame($latest, $metadata['conversion_goal_readiness']);
        $this->assertSame($latest, $metadata['activation_verification']['conversion_goals']);
        $this->assertFalse($metadata['activation_verification']['ready']);
    }
}
