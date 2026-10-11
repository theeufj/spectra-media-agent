<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\SpectraConversionEvent;
use App\Models\Strategy;
use App\Models\User;
use App\Services\Agents\AgentIssue;
use App\Services\GoogleAds\CampaignConversionTrackingHealth;
use App\Services\GoogleAds\ConversionTrackingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class CampaignConversionTrackingHealthTest extends TestCase
{
    use DatabaseTransactions;

    private const CUSTOMER_ID = '1234567890';

    private const CAMPAIGN_RESOURCE = 'customers/1234567890/campaigns/61';

    private const ACTION_RESOURCE = 'customers/1234567890/conversionActions/77';

    private Customer $customer;

    private Strategy $strategy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        Cache::flush();
        Http::fake();
        config(['conversions.google_ads_customer_id' => self::CUSTOMER_ID]);
        Setting::set('conversion_resource_name.signup_import', self::ACTION_RESOURCE);
        Setting::set('conversion_resource_name.paid_subscription', 'customers/1234567890/conversionActions/78');
        $this->customer = Customer::factory()->create([
            'google_ads_customer_id' => self::CUSTOMER_ID, 'conversion_tracking_verified_at' => null,
        ]);
        $campaign = Campaign::factory()->create([
            'customer_id' => $this->customer->id, 'status' => 'active',
            'google_ads_campaign_id' => '61', 'platform_status' => 'ENABLED',
        ]);
        $this->strategy = Strategy::factory()->create([
            'campaign_id' => $campaign->id, 'platform' => 'Google Search',
            'google_ads_campaign_id' => self::CAMPAIGN_RESOURCE,
            'conversion_goals' => ['primary_goal' => 'Signup', 'action_resource' => self::ACTION_RESOURCE],
            'execution_result' => ['success' => true, 'metadata' => ['deployment' => ['completed' => true]]],
        ])->load('campaign.customer');
    }

    private function account(array $changes = []): array
    {
        return array_replace(['period' => 'LAST_30_DAYS', 'conversions' => 0.0,
            'all_conversions' => 0.0, 'clicks' => 0, 'impressions' => 0], $changes);
    }

    private function health(array $changes = []): CampaignConversionHealthFixture
    {
        return new CampaignConversionHealthFixture(array_replace([
            'status' => 'ready', 'ready' => true, 'issues' => [],
            'intent' => ['mode' => 'category', 'category' => 'SIGNUP', 'origin' => 'WEBSITE',
                'action_resource' => self::ACTION_RESOURCE],
        ], $changes));
    }

    private function tracking(string $status = 'ENABLED', array $activity = []): ConversionTrackingService
    {
        $tracking = $this->trackingMock();
        $this->expectMockCall($tracking, 'getCampaignStatus')->once()->with(self::CUSTOMER_ID, self::CAMPAIGN_RESOURCE)->andReturn($status);
        if ($status === 'ENABLED') {
            $this->expectMockCall($tracking, 'getCampaignActionConversionsLast30Days')->once()
                ->with(self::CUSTOMER_ID, self::CAMPAIGN_RESOURCE, self::ACTION_RESOURCE)
                ->andReturn(array_replace(['period' => 'LAST_30_DAYS', 'conversions' => 0.0, 'all_conversions' => 0.0], $activity));
        } else {
            $tracking->shouldNotReceive('getCampaignActionConversionsLast30Days');
        }

        return $tracking;
    }

    private function trackingMock(): ConversionTrackingService&\Mockery\MockInterface
    {
        // No service constructor: this fixture cannot obtain OAuth credentials or a Google client.
        $tracking = Mockery::mock(ConversionTrackingService::class);
        if (! $tracking instanceof ConversionTrackingService) {
            throw new \LogicException('The tracking mock must implement its service contract.');
        }

        return $tracking;
    }

    private function expectMockCall(\Mockery\MockInterface $mock, string $method): \Mockery\Expectation
    {
        $mock->shouldReceive($method);
        $expectations = $mock->mockery_getExpectationsFor($method)?->getExpectations() ?? [];
        $expectation = array_pop($expectations);
        if (! $expectation instanceof \Mockery\Expectation) {
            throw new \LogicException('A named mock method must return a concrete expectation.');
        }

        return $expectation;
    }

    private function event(array $changes = []): SpectraConversionEvent
    {
        return SpectraConversionEvent::create(array_replace([
            'event' => 'signup', 'mode' => 'server_google', 'user_id' => User::factory()->create()->id,
            'occurred_at' => now()->subHour(), 'ad_identifiers' => ['gclid' => 'fixture-click'],
            'google_conversion_resource' => self::ACTION_RESOURCE,
            'uploaded_to_google' => false, 'value' => '0.00', 'currency' => 'USD',
        ], $changes));
    }

    public function test_other_account_goals_cannot_verify_the_selected_campaign_action(): void
    {
        $account = $this->account(['conversions' => 12.0, 'all_conversions' => 20.0, 'clicks' => 60, 'impressions' => 1000]);
        $state = $this->health()->inspect($this->strategy, $this->tracking(), $account);

        $this->assertSame('not_verified', $state['status']);
        $this->assertSame(20.0, $state['account_activity']['all_conversions']);
        $this->assertSame(0.0, $state['selected_action_activity']['all_conversions']);
        $this->assertSame([], $state['issues']);
        $this->assertNull($this->customer->fresh()->conversion_tracking_verified_at);
    }

    public function test_ready_configuration_without_events_remains_unverified_without_a_tracking_fault(): void
    {
        $state = $this->health()->inspect($this->strategy, $this->tracking(), $this->account());

        $this->assertSame('not_verified', $state['status']);
        $this->assertSame('ready', $state['configuration_status']);
        $this->assertSame([], $state['delivery']['statuses']);
        $this->assertSame([], $state['issues']);
        $this->assertStringContainsString('does not establish a tracking fault', $state['message']);
    }

    public function test_fractional_selected_all_conversions_are_observed_for_a_custom_goal_with_zero_primary_count(): void
    {
        $health = $this->health(['intent' => ['mode' => 'custom', 'category' => 'SIGNUP',
            'origin' => 'WEBSITE', 'action_resource' => self::ACTION_RESOURCE]]);
        $state = $health->inspect($this->strategy, $this->tracking(activity: ['all_conversions' => 0.25]), $this->account());

        $this->assertSame('conversions_observed', $state['status']);
        $this->assertSame(0.0, $state['selected_action_activity']['conversions']);
        $this->assertSame(0.25, $state['selected_action_activity']['all_conversions']);
        $this->assertSame('custom', $state['intent']['mode']);
        $this->assertNull($this->customer->fresh()->conversion_tracking_verified_at);
    }

    public function test_google_paused_campaigns_do_not_raise_zero_conversion_or_goal_alerts(): void
    {
        $health = $this->health();
        $state = $health->inspect($this->strategy, $this->tracking('PAUSED'), $this->account());

        $this->assertSame('inactive', $state['status']);
        $this->assertSame('PAUSED', $state['campaign_status']);
        $this->assertSame('not_checked', $state['configuration_status']);
        $this->assertSame(0, $health->goalChecks);
        $this->assertNull($state['selected_action_activity']);
        $this->assertSame([], $state['issues']);
    }

    public function test_real_selected_action_upload_and_processing_failures_are_reported(): void
    {
        $this->event(['upload_error' => 'Upload rejected']);
        $this->event(['uploaded_to_google' => true, 'google_request_id' => 'receipt',
            'google_accepted_at' => now()->subHour(), 'google_processing_status' => 'FAILED']);
        $state = $this->health()->inspect($this->strategy, $this->tracking(), $this->account());

        $this->assertSame('delivery_failed', $state['status']);
        $this->assertSame(['upload_failed' => 1, 'processing_failed' => 1], $state['delivery']['statuses']);
        $this->assertSame(self::ACTION_RESOURCE, $state['delivery']['action_resource']);
        $this->assertSame('selected_conversion_delivery_failed', $state['issues'][0]['code']);
        $this->assertStringContainsString('2 real server-side event(s)', $state['issues'][0]['message']);
        $this->assertStringContainsString('GTM change alone will not repair an import failure', $state['message']);
    }

    public function test_unrelated_actions_accounts_modes_events_and_old_records_do_not_trigger_failures(): void
    {
        $this->event(['upload_error' => 'Wrong action', 'google_conversion_resource' => 'customers/1234567890/conversionActions/78']);
        $this->event(['upload_error' => 'Wrong account', 'google_conversion_resource' => 'customers/9876543210/conversionActions/77']);
        $this->event(['upload_error' => 'Wrong event', 'event' => 'paid_subscription']);
        $this->event(['upload_error' => 'Browser record', 'mode' => 'client']);
        $this->event(['upload_error' => 'Old failure'])->forceFill(['created_at' => now()->subDays(31)])->save();
        $state = $this->health()->inspect($this->strategy, $this->tracking(), $this->account());

        $this->assertSame('not_verified', $state['status']);
        $this->assertSame([], $state['delivery']['statuses']);
        $this->assertSame([], $state['issues']);
    }

    public function test_organic_and_legacy_records_cannot_prove_failed_attributable_delivery(): void
    {
        $this->event(['ad_identifiers' => null, 'gclid' => null, 'upload_error' => 'No attributed click']);
        $this->event(['occurred_at' => null, 'upload_error' => 'Legacy timestamp missing']);
        $this->event(['uploaded_to_google' => true, 'google_request_id' => null,
            'google_processing_status' => 'FAILED', 'upload_error' => 'Legacy upload has no receipt']);
        $this->event(['google_conversion_resource' => null, 'upload_error' => 'Unknown historical destination']);
        $state = $this->health()->inspect($this->strategy, $this->tracking(), $this->account());

        $this->assertSame('not_verified', $state['status']);
        $this->assertSame(['legacy_unverified' => 1], $state['delivery']['statuses']);
        $this->assertSame([], $state['issues']);
    }

    public function test_own_site_delivery_evidence_is_not_reused_for_another_customer_account(): void
    {
        config(['conversions.google_ads_customer_id' => '9876543210']);
        $this->event(['upload_error' => 'Other configured website account']);
        $state = $this->health()->inspect($this->strategy, $this->tracking(), $this->account());

        $this->assertSame('not_verified', $state['status']);
        $this->assertNull($state['delivery']);
        $this->assertSame([], $state['issues']);
    }

    public function test_provider_failures_propagate_without_saving_a_false_status(): void
    {
        $tracking = $this->trackingMock();
        $this->expectMockCall($tracking, 'getCampaignStatus')->once()->andThrow(new \RuntimeException('Google unavailable'));
        $tracking->shouldNotReceive('getCampaignActionConversionsLast30Days');
        try {
            $this->health()->inspect($this->strategy, $tracking, $this->account());
            $this->fail('Unavailable Google evidence should throw.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Google unavailable', $e->getMessage());
            $this->assertArrayNotHasKey('conversion_tracking_health', $this->strategy->fresh()->execution_result['metadata']);
        }
    }

    public function test_unknown_goal_readiness_cannot_become_a_tracking_failure_or_review_result(): void
    {
        $tracking = $this->trackingMock();
        $this->expectMockCall($tracking, 'getCampaignStatus')->once()->andReturn('ENABLED');
        $tracking->shouldNotReceive('getCampaignActionConversionsLast30Days');
        $health = $this->health(['status' => 'unknown', 'ready' => false,
            'issues' => [new AgentIssue('conversion_goal_check_unavailable', 'Google unavailable.')]]);
        try {
            $health->inspect($this->strategy, $tracking, $this->account());
            $this->fail('Unknown readiness should throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('evidence is unavailable', $e->getMessage());
            $this->assertArrayNotHasKey('conversion_tracking_health', $this->strategy->fresh()->execution_result['metadata']);
        }
    }

    public function test_selected_conversion_query_failure_remains_unknown(): void
    {
        $tracking = $this->trackingMock();
        $this->expectMockCall($tracking, 'getCampaignStatus')->once()->andReturn('ENABLED');
        $this->expectMockCall($tracking, 'getCampaignActionConversionsLast30Days')->once()
            ->andThrow(new \RuntimeException('Conversion query failed'));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Conversion query failed');
        $this->health()->inspect($this->strategy, $tracking, $this->account());
    }

    public function test_verified_configuration_mismatch_is_reviewed_without_calling_selected_metrics(): void
    {
        $tracking = $this->trackingMock();
        $this->expectMockCall($tracking, 'getCampaignStatus')->once()->andReturn('ENABLED');
        $tracking->shouldNotReceive('getCampaignActionConversionsLast30Days');
        $state = $this->health(['status' => 'needs_review', 'ready' => false,
            'issues' => [new AgentIssue('conversion_goal_mismatch', 'Selected action is not biddable.')]])
            ->inspect($this->strategy, $tracking, $this->account());

        $this->assertSame('needs_review', $state['status']);
        $this->assertSame('conversion_goal_mismatch', $state['issues'][0]['code']);
        $this->assertNull($state['selected_action_activity']);
    }

    public function test_health_observation_preserves_customer_verification_and_google_configuration(): void
    {
        $this->customer->update(['conversion_tracking_verified_at' => now()->subWeek()]);
        $before = $this->customer->fresh()->getAttributes();
        $goals = $this->strategy->conversion_goals;
        $state = $this->health()->inspect($this->strategy, $this->tracking(activity: ['all_conversions' => 1.0]), $this->account());

        $this->assertSame('conversions_observed', $state['status']);
        $this->assertSame($before, $this->customer->fresh()->getAttributes());
        $this->assertSame($goals, $this->strategy->fresh()->conversion_goals);
        $this->assertSame(self::CAMPAIGN_RESOURCE, $this->strategy->fresh()->google_ads_campaign_id);
        Http::assertNothingSent();
    }

    public function test_metadata_merge_preserves_newer_evidence_written_by_other_agents(): void
    {
        $health = $this->health();
        $health->duringInspection = function (): void {
            $fresh = $this->strategy->fresh();
            $execution = $fresh->execution_result;
            $execution['metadata']['search_delivery'] = ['status' => 'approval_required', 'newer' => true];
            $execution['metadata']['deployment']['current_check'] = 'passed';
            $fresh->update(['execution_result' => $execution]);
        };
        $state = $health->inspect($this->strategy, $this->tracking(), $this->account());
        $execution = $this->strategy->fresh()->execution_result;

        $this->assertTrue($execution['success']);
        $this->assertSame(['status' => 'approval_required', 'newer' => true], $execution['metadata']['search_delivery']);
        $this->assertSame(['completed' => true, 'current_check' => 'passed'], $execution['metadata']['deployment']);
        $this->assertEquals($state, $execution['metadata']['conversion_tracking_health']);
    }

    public function test_changed_account_during_remote_reads_cannot_save_old_customer_evidence(): void
    {
        $health = $this->health();
        $health->duringInspection = fn () => $this->customer->fresh()->update(['google_ads_customer_id' => '9876543210']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('intent changed during verification');
        $health->inspect($this->strategy, $this->tracking(), $this->account());
    }

    public function test_changed_conversion_intent_during_remote_reads_cannot_save_old_goal_evidence(): void
    {
        $health = $this->health();
        $health->duringInspection = fn () => $this->strategy->fresh()->update(['conversion_goals' => ['primary_goal' => 'Purchase']]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('intent changed during verification');
        $health->inspect($this->strategy, $this->tracking(), $this->account());
    }
}

class CampaignConversionHealthFixture extends CampaignConversionTrackingHealth
{
    public int $goalChecks = 0;

    public ?\Closure $duringInspection = null;

    public function __construct(private array $readiness) {}

    protected function inspectGoals(Strategy $strategy, string $resource): array
    {
        $this->goalChecks++;
        if ($this->duringInspection) {
            ($this->duringInspection)();
        }

        return $this->readiness;
    }
}
