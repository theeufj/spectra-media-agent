<?php

namespace Tests\Feature;

use App\Features\AutoHealing;
use App\Jobs\CheckGoogleCampaignReadiness;
use App\Models\AdSpendCredit;
use App\Models\AgentActivity;
use App\Models\AgentRun;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EnabledPlatform;
use App\Models\Setting;
use App\Models\Strategy;
use App\Services\Agents\AgentIssue;
use App\Services\Agents\QualityScoreImprovementAgent;
use App\Services\Deployment\DeploymentVerifier;
use App\Services\GoogleAds\ReconcileCampaignConversionGoals;
use App\Services\GoogleAds\ReconcileSearchAudienceObservation;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class GoogleSearchAudienceReadinessTest extends TestCase
{
    use DatabaseTransactions;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::forget('enabled_platform_slugs');
        EnabledPlatform::updateOrCreate(['slug' => 'google'], ['name' => 'Google Ads', 'is_enabled' => true]);
        Setting::set('managed_billing_enabled', true, 'boolean');
    }

    /** @return array{Campaign, Customer, Strategy} */
    private function workspace(array $strategyAttributes = []): array
    {
        $account = (string) (1234500000 + $this->sequence++);
        $resource = 'customers/'.$account.'/campaigns/61';
        $customer = Customer::factory()->create(['website' => null, 'service_type' => 'managed', 'google_ads_customer_id' => $account, 'google_ads_link_status' => null]);
        Feature::for($customer)->activate(AutoHealing::class);
        AdSpendCredit::factory()->create(['customer_id' => $customer->id]);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'status' => 'active', 'platform_status' => 'ENABLED',
            'google_ads_campaign_id' => $resource, 'daily_budget' => 50, 'end_date' => now()->addMonth()]);
        $strategy = Strategy::factory()->create(array_merge(['campaign_id' => $campaign->id, 'platform' => 'Google Ads (SEM)',
            'campaign_type' => 'search', 'signed_off_at' => now(), 'deployment_status' => 'verified', 'google_ads_campaign_id' => '61',
            'execution_result' => ['platform_ids' => ['campaign' => $resource], 'metadata' => ['unrelated_evidence' => ['preserve' => true]]]], $strategyAttributes));

        return [$campaign, $customer, $strategy];
    }

    private function goals(array $overrides = []): array
    {
        return array_merge(['status' => 'ready', 'ready' => true, 'intent' => ['category' => 'SIGNUP'], 'actions' => [], 'issues' => []], $overrides);
    }

    private function audience(array $overrides = []): array
    {
        return array_merge(['status' => 'ready', 'ready' => true, 'applicable' => true, 'checked_at' => now()->toIso8601String(),
            'actions' => [], 'issues' => [], 'ad_groups' => []], $overrides);
    }

    /** @return array{ReconcileCampaignConversionGoals&\PHPUnit\Framework\MockObject\MockObject, ReconcileSearchAudienceObservation&\PHPUnit\Framework\MockObject\MockObject, QualityScoreImprovementAgent&\PHPUnit\Framework\MockObject\MockObject, DeploymentVerifier&\PHPUnit\Framework\MockObject\MockObject} */
    private function services(): array
    {
        $goals = $this->createMock(ReconcileCampaignConversionGoals::class);
        $audience = $this->createMock(ReconcileSearchAudienceObservation::class);
        $this->app->bind(ReconcileCampaignConversionGoals::class, fn () => $goals);
        $this->app->bind(ReconcileSearchAudienceObservation::class, fn () => $audience);

        return [$goals, $audience, $this->createMock(QualityScoreImprovementAgent::class), $this->createMock(DeploymentVerifier::class)];
    }

    private function strength(): array
    {
        return ['checked' => true, 'verified' => true, 'actions' => [], 'errors' => [], 'unresolved' => []];
    }

    private function snapshot(Strategy $strategy): array
    {
        return $strategy->fresh()->execution_result['metadata']['google_readiness'];
    }

    public function test_missing_audience_mode_repair_becomes_visible_hourly_work_and_preserves_other_metadata(): void
    {
        [$campaign, $customer, $strategy] = $this->workspace();
        [$goals, $audience, $strength, $verifier] = $this->services();
        $goals->expects($this->once())->method('reconcile')->willReturn($this->goals());
        $audience->expects($this->once())->method('reconcile')->with($campaign->google_ads_campaign_id)->willReturn($this->audience([
            'actions' => [['type' => 'audience_observation_repaired', 'ad_group_resource' => 'customers/1234500000/adGroups/2']],
            'ad_groups' => [['resource' => 'customers/1234500000/adGroups/2', 'audience_bid_only' => true]],
        ]));
        $strength->expects($this->once())->method('checkAdStrength')->willReturn($this->strength());
        $verifier->expects($this->never())->method('verify');

        (new CheckGoogleCampaignReadiness($campaign->id))->handle($strength, $verifier);

        $saved = $strategy->fresh()->execution_result;
        $state = $this->snapshot($strategy);
        $this->assertTrue($state['ready']);
        $this->assertSame('ready', $state['status']);
        $this->assertTrue($state['audience_observation']['ad_groups'][0]['audience_bid_only']);
        $this->assertSame(['preserve' => true], $saved['metadata']['unrelated_evidence']);
        $this->assertSame(['campaign' => $campaign->google_ads_campaign_id], $saved['platform_ids']);
        $this->assertSame(1, AgentRun::where('details->campaign_id', $campaign->id)->latest('id')->value('actions_taken'));
        $activity = AgentActivity::where('campaign_id', $campaign->id)->where('action', 'google_readiness_checked')->latest('id')->firstOrFail();
        $this->assertSame('audience_observation_repaired', $activity->details['readiness']['audience_observation']['actions'][0]['type']);
        $this->assertSame('active', $campaign->fresh()->status->value);
        $this->assertEquals(50, $campaign->fresh()->daily_budget);
        Http::assertNothingSent();
    }

    public function test_pause_approval_billing_dates_and_automation_holds_inspect_audiences_without_mutating(): void
    {
        foreach (['paused', 'unsigned', 'billing', 'ended', 'automation', 'setup_only'] as $hold) {
            [$campaign, $customer, $strategy] = $this->workspace();
            match ($hold) {
                'paused' => $campaign->update(['status' => 'paused']),
                'unsigned' => $strategy->update(['signed_off_at' => null]),
                'billing' => $customer->adSpendCredit()->update(['payment_status' => 'paused']),
                'ended' => $campaign->update(['end_date' => now()->subDays(2)]),
                'automation' => Feature::for($customer)->deactivate(AutoHealing::class),
                'setup_only' => $customer->update(['service_type' => 'setup_only']),
            };
            [$goals, $audience, $strength, $verifier] = $this->services();
            $goals->expects($this->once())->method('inspect')->willReturn($this->goals());
            $audience->expects($this->never())->method('reconcile');
            $audience->expects($this->once())->method('inspect')->with($campaign->google_ads_campaign_id)->willReturn($this->audience([
                'status' => 'needs_review', 'ready' => false,
                'issues' => [new AgentIssue('search_audience_mode_missing', 'An attached Search audience has no explicit Observation setting.')],
            ]));
            $strength->expects($this->never())->method('checkAdStrength');
            $verifier->expects($this->never())->method('verify');
            (new CheckGoogleCampaignReadiness($campaign->id))->handle($strength, $verifier);
            $state = $this->snapshot($strategy);
            $this->assertFalse($state['mutation_allowed'], $hold);
            $this->assertFalse($state['ready'], $hold);
            $this->assertSame('search_audience_mode_missing', $state['issues'][0]['code'], $hold);
            $this->assertEquals(50, $campaign->fresh()->daily_budget, $hold);
        }
        Http::assertNothingSent();
    }

    public function test_goal_failure_cannot_skip_audience_or_strength_checks(): void
    {
        [$campaign, $customer, $strategy] = $this->workspace();
        [$goals, $audience, $strength, $verifier] = $this->services();
        $goals->expects($this->once())->method('reconcile')->willThrowException(new \RuntimeException('goal read failed'));
        $audience->expects($this->once())->method('reconcile')->willReturn($this->audience());
        $strength->expects($this->once())->method('checkAdStrength')->willReturn($this->strength());
        $handler = $this->createMock(ExceptionHandler::class);
        $handler->expects($this->once())->method('report')->with($this->isInstanceOf(\RuntimeException::class));
        $this->app->instance(ExceptionHandler::class, $handler);
        (new CheckGoogleCampaignReadiness($campaign->id))->handle($strength, $verifier);
        $state = $this->snapshot($strategy);
        $this->assertSame('unknown', $state['status']);
        $this->assertTrue($state['audience_observation']['ready']);
        $this->assertTrue($state['ad_strength']['verified']);
        $this->assertSame('conversion_readiness_unavailable', $state['errors'][0]['code']);
    }

    public function test_audience_exception_cannot_erase_goal_results_or_skip_strength(): void
    {
        [$campaign, $customer, $strategy] = $this->workspace();
        [$goals, $audience, $strength, $verifier] = $this->services();
        $goals->expects($this->once())->method('reconcile')->willReturn($this->goals());
        $audience->expects($this->once())->method('reconcile')->willThrowException(new \RuntimeException('audience read failed'));
        $strength->expects($this->once())->method('checkAdStrength')->willReturn($this->strength());
        $handler = $this->createMock(ExceptionHandler::class);
        $handler->expects($this->once())->method('report')->with($this->isInstanceOf(\RuntimeException::class));
        $this->app->instance(ExceptionHandler::class, $handler);
        (new CheckGoogleCampaignReadiness($campaign->id))->handle($strength, $verifier);
        $state = $this->snapshot($strategy);
        $this->assertSame('unknown', $state['status']);
        $this->assertFalse($state['ready']);
        $this->assertTrue($state['conversion_goals']['ready']);
        $this->assertTrue($state['ad_strength']['verified']);
        $this->assertSame('audience_observation_unavailable', $state['errors'][0]['code']);
    }

    public function test_unknown_audience_read_retains_prior_issue_and_is_counted_as_failed_work(): void
    {
        [$campaign, $customer, $strategy] = $this->workspace();
        [$goals, $audience, $strength, $verifier] = $this->services();
        $oldIssue = new AgentIssue('search_audience_mode_missing', 'Attached audiences restricted Search reach.');
        $goals->expects($this->exactly(2))->method('reconcile')->willReturn($this->goals());
        $strength->expects($this->exactly(2))->method('checkAdStrength')->willReturn($this->strength());
        $audience->expects($this->exactly(2))->method('reconcile')->willReturnOnConsecutiveCalls(
            $this->audience(['status' => 'needs_review', 'ready' => false, 'issues' => [$oldIssue], 'ad_groups' => [['resource' => 'customers/1234500000/adGroups/2']]]),
            $this->audience(['status' => 'unknown', 'ready' => false, 'issues' => [new AgentIssue('search_audience_read_unavailable', 'Google audience mode could not be read.')]]),
        );
        $job = new CheckGoogleCampaignReadiness($campaign->id);
        $job->handle($strength, $verifier);
        $first = $this->snapshot($strategy);
        $this->travel(1)->hours();
        $job->handle($strength, $verifier);
        $state = $this->snapshot($strategy);
        $this->assertSame('unknown', $state['status']);
        $this->assertFalse($state['ready']);
        $this->assertSame('search_audience_mode_missing', $state['audience_observation']['last_known']['issues'][0]['code']);
        $this->assertSame($first['checked_at'], $state['audience_observation']['last_known_checked_at']);
        $this->assertContains('search_audience_mode_missing', array_column($state['issues'], 'code'));
        $this->assertSame(1, AgentRun::where('details->campaign_id', $campaign->id)->latest('id')->value('errors'));
    }

    public function test_explicit_non_search_does_not_invent_audience_or_rsa_verification(): void
    {
        [$campaign, $customer, $strategy] = $this->workspace(['campaign_type' => 'performance_max']);
        [$goals, $audience, $strength, $verifier] = $this->services();
        $goals->expects($this->once())->method('reconcile')->willReturn($this->goals());
        $audience->expects($this->never())->method('inspect');
        $audience->expects($this->never())->method('reconcile');
        $strength->expects($this->never())->method('checkAdStrength');
        (new CheckGoogleCampaignReadiness($campaign->id))->handle($strength, $verifier);
        $state = $this->snapshot($strategy);
        $this->assertSame('not_applicable', $state['audience_observation']['status']);
        $this->assertFalse($state['audience_observation']['applicable']);
        $this->assertFalse($state['ad_strength']['verified']);
        $this->assertTrue($state['ready']);
    }

    public function test_pause_during_goals_is_seen_before_audience_write(): void
    {
        [$campaign, $customer, $strategy] = $this->workspace();
        [$goals, $audience, $strength, $verifier] = $this->services();
        $goals->expects($this->once())->method('reconcile')->willReturnCallback(function () use ($campaign) {
            $campaign->update(['status' => 'paused']);

            return $this->goals();
        });
        $audience->expects($this->never())->method('reconcile');
        $audience->expects($this->once())->method('inspect')->willReturn($this->audience());
        $strength->expects($this->never())->method('checkAdStrength');
        (new CheckGoogleCampaignReadiness($campaign->id))->handle($strength, $verifier);
        $this->assertFalse($this->snapshot($strategy)['mutation_allowed']);
        $this->assertSame('paused', $campaign->fresh()->status->value);
    }

    public function test_legacy_display_with_executed_search_baseline_still_checks_observation(): void
    {
        [$campaign, $customer, $strategy] = $this->workspace(['campaign_type' => 'display', 'execution_result' => ['metadata' => ['google_search_baseline' => ['version' => 1]]]]);
        [$goals, $audience, $strength, $verifier] = $this->services();
        $goals->expects($this->once())->method('reconcile')->willReturn($this->goals());
        $audience->expects($this->once())->method('reconcile')->willReturn($this->audience());
        $strength->expects($this->once())->method('checkAdStrength')->willReturn($this->strength());
        (new CheckGoogleCampaignReadiness($campaign->id))->handle($strength, $verifier);
        $this->assertTrue($this->snapshot($strategy)['audience_observation']['ready']);
    }

    public function test_successful_audience_repair_does_not_clear_an_independent_goal_failure(): void
    {
        [$campaign, $customer, $strategy] = $this->workspace(['deployment_error' => 'An independent conversion configuration needs review.']);
        [$goals, $audience, $strength, $verifier] = $this->services();
        $goals->expects($this->once())->method('reconcile')->willReturn($this->goals(['status' => 'needs_review', 'ready' => false,
            'issues' => [new AgentIssue('conversion_goal_unverified', 'The conversion goal remains unverified.')]]));
        $audience->expects($this->once())->method('reconcile')->willReturn($this->audience(['actions' => [['type' => 'audience_observation_repaired']]]));
        $strength->expects($this->once())->method('checkAdStrength')->willReturn($this->strength());
        $verifier->expects($this->never())->method('verify');
        (new CheckGoogleCampaignReadiness($campaign->id))->handle($strength, $verifier);
        $state = $this->snapshot($strategy);
        $this->assertFalse($state['ready']);
        $this->assertSame('needs_review', $state['status']);
        $this->assertSame('conversion_goal_unverified', $state['issues'][0]['code']);
        $this->assertSame('An independent conversion configuration needs review.', $strategy->fresh()->deployment_error);
    }

    public function test_audience_repair_rechecks_previously_failed_configuration_without_changing_active_deployment_status(): void
    {
        [$campaign, $customer, $strategy] = $this->workspace(['deployment_status' => 'active', 'execution_result' => ['metadata' => ['configuration_verification' => ['passed' => false]]]]);
        [$goals, $audience, $strength, $verifier] = $this->services();
        $goals->expects($this->once())->method('reconcile')->willReturn($this->goals());
        $audience->expects($this->once())->method('reconcile')->willReturn($this->audience(['actions' => [['type' => 'audience_observation_repaired']]]));
        $strength->expects($this->once())->method('checkAdStrength')->willReturn($this->strength());
        $verifier->expects($this->once())->method('supports')->willReturn(true);
        $verifier->expects($this->once())->method('verify')->willReturn(true);
        (new CheckGoogleCampaignReadiness($campaign->id))->handle($strength, $verifier);
        $this->assertTrue($this->snapshot($strategy)['deployment_verified']);
        $this->assertSame('active', $strategy->fresh()->deployment_status);
    }
}
