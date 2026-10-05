<?php

namespace Tests\Feature;

use App\Features\AutoHealing;
use App\Jobs\CheckGoogleCampaignReadiness;
use App\Jobs\Scheduled\DispatchGoogleCampaignReadinessChecks;
use App\Models\AdSpendCredit;
use App\Models\AgentActivity;
use App\Models\AgentRun;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EnabledPlatform;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Strategy;
use App\Models\User;
use App\Notifications\CriticalAgentAlert;
use App\Services\Agents\QualityScoreImprovementAgent;
use App\Services\Deployment\DeploymentVerifier;
use App\Services\GoogleAds\ReconcileCampaignConversionGoals;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Tests\TestCase;

class GoogleCampaignReadinessTest extends TestCase
{
    use DatabaseTransactions;

    private int $customerSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Cache::forget('enabled_platform_slugs');
        EnabledPlatform::updateOrCreate(['slug' => 'google'], ['name' => 'Google Ads', 'is_enabled' => true]);
        Setting::set('managed_billing_enabled', true, 'boolean');
    }

    private function workspace(array $campaignAttributes = [], array $customerAttributes = [], array $strategyAttributes = []): array
    {
        $account = (string) (1234567890 + $this->customerSequence++);
        $resource = 'customers/'.$account.'/campaigns/61';
        $customer = Customer::factory()->create(array_merge(['website' => null, 'service_type' => 'managed', 'google_ads_customer_id' => $account, 'google_ads_link_status' => null], $customerAttributes));
        Feature::for($customer)->activate(AutoHealing::class);
        AdSpendCredit::factory()->create(['customer_id' => $customer->id]);
        $campaign = Campaign::factory()->create(array_merge(['customer_id' => $customer->id, 'status' => 'active', 'platform_status' => 'ENABLED', 'primary_status' => 'NOT_ELIGIBLE', 'google_ads_campaign_id' => $resource, 'daily_budget' => 50, 'end_date' => now()->addMonth()], $campaignAttributes));
        $strategy = Strategy::factory()->create(array_merge(['campaign_id' => $campaign->id, 'platform' => 'Google Ads (SEM)', 'campaign_type' => 'search', 'signed_off_at' => now(), 'deployment_status' => 'verified', 'google_ads_campaign_id' => '61', 'conversion_goals' => ['primary_goal' => 'Sign-up'], 'execution_result' => ['platform_ids' => ['campaign' => $resource], 'metadata' => ['search_campaign_baseline' => ['approved_budget' => 50], 'conversion_goal_readiness' => ['old' => true]]]], $strategyAttributes));

        return [$campaign, $customer, $strategy];
    }

    private function goals(array $overrides = []): array
    {
        return array_merge(['status' => 'ready', 'ready' => true, 'intent' => ['category' => 'SIGNUP'], 'actions' => [], 'issues' => [], 'repairable' => false], $overrides);
    }

    private function strength(array $overrides = []): array
    {
        return array_merge(['checked' => true, 'verified' => true, 'actions' => [], 'errors' => [], 'unresolved' => []], $overrides);
    }

    private function services(): array
    {
        $goals = $this->createMock(ReconcileCampaignConversionGoals::class);
        // Explicit constructor parameters in app() require a binding, rather
        // than instance(), to prevent construction of the real Google client.
        $this->app->bind(ReconcileCampaignConversionGoals::class, fn () => $goals);

        return [$goals, $this->createMock(QualityScoreImprovementAgent::class), $this->createMock(DeploymentVerifier::class)];
    }

    private function snapshot(Strategy $strategy): array
    {
        return $strategy->fresh()->execution_result['metadata']['google_readiness'];
    }

    private function expectReportedError(string $message): void
    {
        $handler = $this->createMock(ExceptionHandler::class);
        $handler->expects($this->once())->method('report')->with($this->callback(fn (\Throwable $error) => $error->getMessage() === $message));
        $this->app->instance(ExceptionHandler::class, $handler);
    }

    public function test_dispatcher_includes_paused_and_ineligible_campaigns_and_strategy_only_ids(): void
    {
        [$paused] = $this->workspace(['status' => 'paused', 'platform_status' => 'PAUSED']);
        [$ineligible] = $this->workspace();
        [$strategyOnly] = $this->workspace(['google_ads_campaign_id' => null]);
        $notDeployed = Campaign::factory()->create(['google_ads_campaign_id' => null]);
        [$deleted, $customer] = $this->workspace();
        $customer->delete();

        (new DispatchGoogleCampaignReadinessChecks)->handle();

        Queue::assertPushed(CheckGoogleCampaignReadiness::class, 3);
        foreach ([$paused, $ineligible, $strategyOnly] as $campaign) {
            Queue::assertPushed(CheckGoogleCampaignReadiness::class, fn ($job) => $job->campaignId === $campaign->id);
        }
        Queue::assertNotPushed(CheckGoogleCampaignReadiness::class, fn ($job) => in_array($job->campaignId, [$notDeployed->id, $deleted->id], true));
        $this->assertStringContainsString("DispatchGoogleCampaignReadinessChecks)->name('check-google-campaign-readiness')->hourly()", file_get_contents(base_path('routes/console.php')));
        Http::assertNothingSent();
    }

    public function test_conversion_failure_cannot_skip_ad_strength_and_metadata_is_preserved(): void
    {
        [$campaign, $customer, $strategy] = $this->workspace();
        [$goals, $strength, $verifier] = $this->services();
        $goals->expects($this->once())->method('reconcile')->with($this->isInstanceOf(Strategy::class), 'customers/1234567890/campaigns/61')->willThrowException(new \RuntimeException('goals offline'));
        $strength->expects($this->once())->method('checkAdStrength')->willReturn($this->strength(['actions' => ['repaired headlines']]));
        $this->expectReportedError('goals offline');

        (new CheckGoogleCampaignReadiness($campaign->id))->handle($strength, $verifier);

        $state = $this->snapshot($strategy);
        $this->assertSame('unknown', $state['status']);
        $this->assertFalse($state['ready']);
        $this->assertTrue($state['ad_strength']['verified']);
        $this->assertSame('conversion_readiness_unavailable', $state['errors'][0]['code']);
        $execution = $strategy->fresh()->execution_result;
        $this->assertSame(['campaign' => 'customers/1234567890/campaigns/61'], $execution['platform_ids']);
        $this->assertSame(['approved_budget' => 50], $execution['metadata']['search_campaign_baseline']);
        $this->assertSame(['old' => true], $execution['metadata']['conversion_goal_readiness']);
        Http::assertNothingSent();
    }

    public function test_strength_failure_preserves_completed_goal_work_and_is_reported(): void
    {
        [$campaign, $customer, $strategy] = $this->workspace();
        [$goals, $strength, $verifier] = $this->services();
        $goals->expects($this->once())->method('reconcile')->willReturn($this->goals(['actions' => [['type' => 'set_campaign_custom_goal']]]));
        $strength->expects($this->once())->method('checkAdStrength')->willThrowException(new \RuntimeException('strength offline'));
        $this->expectReportedError('strength offline');

        (new CheckGoogleCampaignReadiness($campaign->id))->handle($strength, $verifier);

        $state = $this->snapshot($strategy);
        $this->assertSame('unknown', $state['status']);
        $this->assertFalse($state['ready']);
        $this->assertTrue($state['conversion_goals']['ready']);
        $this->assertSame('set_campaign_custom_goal', $state['conversion_goals']['actions'][0]['type']);
        $this->assertSame('ad_strength_unavailable', $state['errors'][0]['code']);
    }

    public function test_mutation_guards_use_read_only_goal_checks_and_preserve_pause_and_budget(): void
    {
        $cases = ['local_pause' => 'campaign_not_active', 'google_pause' => 'google_campaign_paused', 'setup_only' => 'setup_only', 'billing_paused' => 'ad_spend_unfunded', 'suspended' => 'ad_spend_unfunded', 'depleted' => 'ad_spend_unfunded', 'auto_healing_disabled' => 'auto_healing_disabled', 'ended' => 'campaign_ended', 'unsigned' => 'strategy_not_approved'];
        foreach ($cases as $case => $reason) {
            [$campaign, $customer, $strategy] = $this->workspace();
            $job = new CheckGoogleCampaignReadiness($campaign->id);
            match ($case) {
                'local_pause' => $campaign->update(['status' => 'paused']),
                'google_pause' => $campaign->update(['platform_status' => 'PAUSED']),
                'setup_only' => $customer->update(['service_type' => 'setup_only']),
                'billing_paused' => $customer->adSpendCredit()->update(['payment_status' => AdSpendCredit::PAYMENT_PAUSED]),
                'suspended' => $customer->adSpendCredit()->update(['status' => AdSpendCredit::STATUS_SUSPENDED]),
                'depleted' => $customer->adSpendCredit()->update(['current_balance' => 0]),
                'auto_healing_disabled' => Feature::for($customer)->deactivate(AutoHealing::class),
                'ended' => $campaign->update(['end_date' => now()->subDays(2)]),
                'unsigned' => $strategy->update(['signed_off_at' => null]),
            };
            $before = $campaign->fresh();
            [$goals, $strength, $verifier] = $this->services();
            $goals->expects($this->never())->method('reconcile');
            $goals->expects($this->once())->method('inspect')->willReturn($this->goals());
            $strength->expects($this->never())->method('checkAdStrength');
            $verifier->expects($this->never())->method('verify');
            $job->handle($strength, $verifier);
            $state = $this->snapshot($strategy);
            $this->assertFalse($state['mutation_allowed'], $case);
            $this->assertFalse($state['ready'], $case);
            $this->assertSame($reason, $state['skip_reason'], $case);
            $this->assertSame($before->status, $campaign->fresh()->status, $case);
            $this->assertSame($before->platform_status, $campaign->fresh()->platform_status, $case);
            $this->assertEquals(50, $campaign->fresh()->daily_budget, $case);
        }
        Http::assertNothingSent();
    }

    public function test_sandbox_revoked_missing_account_and_disabled_platform_skip_api_calls(): void
    {
        foreach (['sandbox', 'google_management_unavailable', 'missing_google_account', 'google_disabled'] as $reason) {
            [$campaign, $customer, $strategy] = $this->workspace();
            match ($reason) {
                'sandbox' => $customer->update(['is_sandbox' => true]),
                'google_management_unavailable' => $customer->update(['google_ads_link_status' => 'revoked']),
                'missing_google_account' => $customer->update(['google_ads_customer_id' => null]),
                'google_disabled' => EnabledPlatform::where('slug', 'google')->firstOrFail()->update(['is_enabled' => false]),
            };
            [$goals, $strength, $verifier] = $this->services();
            $goals->expects($this->never())->method('reconcile');
            $goals->expects($this->never())->method('inspect');
            $strength->expects($this->never())->method('checkAdStrength');
            (new CheckGoogleCampaignReadiness($campaign->id))->handle($strength, $verifier);
            $this->assertSame($reason, $this->snapshot($strategy)['skip_reason']);
            $this->assertSame('skipped', $this->snapshot($strategy)['status']);
        }
        Http::assertNothingSent();
    }

    public function test_self_funded_customer_does_not_need_prepaid_ad_credit(): void
    {
        [$campaign, $customer, $strategy] = $this->workspace([], ['google_ads_link_status' => 'active']);
        $customer->adSpendCredit()->delete();
        [$goals, $strength, $verifier] = $this->services();
        $goals->expects($this->once())->method('reconcile')->willReturn($this->goals());
        $strength->expects($this->once())->method('checkAdStrength')->willReturn($this->strength());
        (new CheckGoogleCampaignReadiness($campaign->id))->handle($strength, $verifier);
        $this->assertSame('ready', $this->snapshot($strategy)['status']);
        $this->assertTrue($this->snapshot($strategy)['ready']);
    }

    public function test_eligibility_is_refreshed_between_goal_and_strength_repairs(): void
    {
        [$campaign, $customer, $strategy] = $this->workspace();
        [$goals, $strength, $verifier] = $this->services();
        $goals->expects($this->once())->method('reconcile')->willReturnCallback(function () use ($campaign) {
            $campaign->update(['status' => 'paused']);

            return $this->goals();
        });
        $strength->expects($this->never())->method('checkAdStrength');
        (new CheckGoogleCampaignReadiness($campaign->id))->handle($strength, $verifier);
        $this->assertFalse($this->snapshot($strategy)['ready']);
        $this->assertSame('campaign_not_active', $this->snapshot($strategy)['skip_reason']);
        $this->assertSame('paused', $campaign->fresh()->status->value);
    }

    public function test_each_google_strategy_checks_its_own_campaign_resource(): void
    {
        [$campaign, $customer, $first] = $this->workspace();
        $second = Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Google Ads (SEM)', 'campaign_type' => 'search', 'google_ads_campaign_id' => '222', 'deployment_status' => 'verified', 'signed_off_at' => now(), 'conversion_goals' => ['primary_goal' => 'Sign-up']]);
        Strategy::factory()->create(['campaign_id' => $campaign->id, 'platform' => 'Facebook Ads', 'deployment_status' => 'verified']);
        [$goals, $strength, $verifier] = $this->services();
        $goalTargets = $strengthTargets = [];
        $goals->expects($this->exactly(2))->method('reconcile')->willReturnCallback(function (Strategy $strategy, ?string $resource) use (&$goalTargets) {
            $goalTargets[$strategy->id] = $resource;

            return $this->goals();
        });
        $strength->expects($this->exactly(2))->method('checkAdStrength')->willReturnCallback(function (Campaign $target) use (&$strengthTargets) {
            $strengthTargets[$target->strategies->sole()->id] = $target->google_ads_campaign_id;

            return $this->strength();
        });
        (new CheckGoogleCampaignReadiness($campaign->id))->handle($strength, $verifier);
        $expected = [$first->id => 'customers/1234567890/campaigns/61', $second->id => 'customers/1234567890/campaigns/222'];
        $this->assertSame($expected, $goalTargets);
        $this->assertSame($expected, $strengthTargets);
        $this->assertSame('customers/1234567890/campaigns/61', $campaign->fresh()->google_ads_campaign_id);
    }

    public function test_repaired_unverified_deployment_requires_configuration_verification(): void
    {
        foreach ([false, true] as $verified) {
            [$campaign, $customer, $strategy] = $this->workspace([], [], ['deployment_status' => 'deploy_unverified']);
            [$goals, $strength, $verifier] = $this->services();
            $goals->expects($this->once())->method('reconcile')->willReturn($this->goals());
            $strength->expects($this->once())->method('checkAdStrength')->willReturn($this->strength());
            $verifier->expects($this->once())->method('supports')->willReturn(true);
            $verifier->expects($this->once())->method('verify')->willReturn($verified);
            (new CheckGoogleCampaignReadiness($campaign->id))->handle($strength, $verifier);
            $this->assertSame($verified ? 'verified' : 'deploy_unverified', $strategy->fresh()->deployment_status);
            $this->assertSame($verified, $this->snapshot($strategy)['ready']);
            $this->assertSame($verified ? 'ready' : 'needs_review', $this->snapshot($strategy)['status']);
        }
    }

    public function test_unresolved_ad_strength_keeps_readiness_unverified_and_alerts_once_per_issue(): void
    {
        [$campaign, $customer, $strategy] = $this->workspace();
        $role = Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin']));
        $admin = User::factory()->create();
        $admin->roles()->attach($role);
        [$goals, $strength, $verifier] = $this->services();
        $goals->expects($this->exactly(2))->method('reconcile')->willReturn($this->goals());
        $strength->expects($this->exactly(2))->method('checkAdStrength')->willReturn($this->strength(['verified' => false, 'unresolved' => [['ad_resource' => 'customers/1234567890/adGroupAds/1~2', 'reason' => 'rating_pending', 'rating' => 'PENDING']]]));
        $job = new CheckGoogleCampaignReadiness($campaign->id);
        $job->handle($strength, $verifier);
        $job->handle($strength, $verifier);
        $state = $this->snapshot($strategy);
        $this->assertFalse($state['ready']);
        $this->assertSame('needs_review', $state['status']);
        $this->assertSame('ad_strength_rating_pending', $state['issues'][0]['code']);
        $this->assertSame('customers/1234567890/adGroupAds/1~2', $state['ad_strength']['unresolved'][0]['ad_resource']);
        $this->assertSame(1, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'google_readiness_alerted')->count());
        Notification::assertSentToTimes($admin, CriticalAgentAlert::class, 1);
    }

    public function test_campaign_lock_prevents_overlapping_checks(): void
    {
        [$campaign, $customer, $strategy] = $this->workspace();
        $lock = Cache::lock('google-readiness:campaign:'.$campaign->id, 650);
        $this->assertTrue($lock->get());
        try {
            [$goals, $strength, $verifier] = $this->services();
            $goals->expects($this->never())->method('reconcile');
            $strength->expects($this->never())->method('checkAdStrength');
            (new CheckGoogleCampaignReadiness($campaign->id))->handle($strength, $verifier);
            $this->assertArrayNotHasKey('google_readiness', $strategy->fresh()->execution_result['metadata']);
        } finally {
            $lock->release();
        }
    }

    public function test_pausing_preserves_unresolved_strength_evidence_without_repairing_or_claiming_readiness(): void
    {
        [$campaign, $customer, $strategy] = $this->workspace();
        [$goals, $strength, $verifier] = $this->services();
        $goals->expects($this->once())->method('reconcile')->willReturn($this->goals());
        $goals->expects($this->exactly(2))->method('inspect')->willReturn($this->goals());
        $strength->expects($this->once())->method('checkAdStrength')->willReturn($this->strength(['verified' => false, 'unresolved' => [['ad_resource' => 'customers/1234567890/adGroupAds/1~2', 'reason' => 'rating_pending']]]));
        $job = new CheckGoogleCampaignReadiness($campaign->id);
        $job->handle($strength, $verifier);
        $firstCheckedAt = $this->snapshot($strategy)['checked_at'];
        $campaign->update(['status' => 'paused']);
        $this->travel(1)->hours();
        $job->handle($strength, $verifier);
        $this->travel(1)->hours();
        $job->handle($strength, $verifier);
        $state = $this->snapshot($strategy);
        $this->assertFalse($state['ready']);
        $this->assertSame('needs_review', $state['status']);
        $this->assertSame('campaign_not_active', $state['skip_reason']);
        $this->assertSame('ad_strength_rating_pending', $state['issues'][0]['code']);
        $this->assertSame('customers/1234567890/adGroupAds/1~2', $state['ad_strength']['last_known']['unresolved'][0]['ad_resource']);
        $this->assertSame($firstCheckedAt, $state['ad_strength']['last_known_checked_at']);
        $this->assertArrayNotHasKey('last_known', $state['ad_strength']['last_known'], 'Repeated skips must not grow nested snapshots.');
        $this->assertSame(1, AgentActivity::where('campaign_id', $campaign->id)->where('action', 'google_readiness_alerted')->count());
    }

    public function test_read_only_goal_needs_review_is_not_overridden_by_skipped_strength(): void
    {
        [$campaign, $customer, $strategy] = $this->workspace(['status' => 'paused']);
        [$goals, $strength, $verifier] = $this->services();
        $goals->expects($this->once())->method('inspect')->willReturn($this->goals(['status' => 'needs_review', 'ready' => false]));
        $strength->expects($this->never())->method('checkAdStrength');
        (new CheckGoogleCampaignReadiness($campaign->id))->handle($strength, $verifier);
        $this->assertSame('needs_review', $this->snapshot($strategy)['status']);
        $this->assertFalse($this->snapshot($strategy)['ready']);
    }

    public function test_dispatcher_records_empty_scans_and_health_registry_checks_its_hourly_cadence(): void
    {
        (new DispatchGoogleCampaignReadinessChecks)->handle();
        Queue::assertNotPushed(CheckGoogleCampaignReadiness::class);
        $run = AgentRun::where('job', 'DispatchGoogleCampaignReadinessChecks')->sole();
        $this->assertSame(AgentRun::STATUS_NO_OP, $run->status);
        $this->assertSame(0, $run->actions_taken);
        $this->assertSame(3, \App\Jobs\MonitorAgentHealth::EXPECTED_MAX_GAP_HOURS['DispatchGoogleCampaignReadinessChecks']);
    }

    public function test_dispatcher_reports_a_failed_item_and_continues_queueing_other_campaigns(): void
    {
        [$first] = $this->workspace();
        [$second] = $this->workspace();
        $job = new class($first->id) extends DispatchGoogleCampaignReadinessChecks
        {
            public function __construct(private int $failCampaignId) {}

            protected function dispatchCampaign(int $campaignId): void
            {
                if ($campaignId === $this->failCampaignId) {
                    throw new \RuntimeException('queue admission failed');
                }
                parent::dispatchCampaign($campaignId);
            }
        };
        $this->expectReportedError('queue admission failed');
        $job->handle();
        Queue::assertPushed(CheckGoogleCampaignReadiness::class, 1);
        Queue::assertPushed(CheckGoogleCampaignReadiness::class, fn ($queued) => $queued->campaignId === $second->id);
        $run = AgentRun::latest('id')->firstOrFail();
        $this->assertSame(AgentRun::STATUS_PARTIAL, $run->status);
        $this->assertSame(1, $run->errors);
        $this->assertSame(1, $run->actions_taken);
    }

    public function test_explicit_non_search_types_skip_rsa_strength_but_require_verified_conversion_goals(): void
    {
        foreach (['display', 'performance_max', 'video', 'demand_gen', 'shopping', 'local_services', 'app'] as $type) {
            [$campaign, $customer, $strategy] = $this->workspace([], [], ['campaign_type' => $type]);
            [$goals, $strength, $verifier] = $this->services();
            $goals->expects($this->exactly(2))->method('reconcile')->willReturnOnConsecutiveCalls($this->goals(), $this->goals(['status' => 'needs_review', 'ready' => false]));
            $strength->expects($this->never())->method('checkAdStrength');
            $job = new CheckGoogleCampaignReadiness($campaign->id);
            $job->handle($strength, $verifier);
            $state = $this->snapshot($strategy);
            $this->assertSame('not_applicable', $state['ad_strength']['status'], $type);
            $this->assertFalse($state['ad_strength']['verified'], 'Skipping RSA checks must not claim verified RSA strength.');
            $this->assertTrue($state['ready'], $type);
            $job->handle($strength, $verifier);
            $this->assertFalse($this->snapshot($strategy)['ready'], $type);
            $this->assertSame('needs_review', $this->snapshot($strategy)['status'], $type);
        }
    }

    public function test_missing_or_unknown_campaign_types_do_not_hide_missing_rsa_issues(): void
    {
        foreach ([null, 'unknown'] as $type) {
            [$campaign, $customer, $stored] = $this->workspace();
            // Current Postgres constraints prevent unknown types. A legacy or
            // partially hydrated strategy still must not bypass RSA diagnosis.
            $strategy = new class extends Strategy
            {
                public function refresh()
                {
                    return $this;
                }
            };
            $strategy->setRawAttributes($stored->getAttributes(), true);
            $strategy->campaign_type = $type;
            $strategy->setRelation('campaign', $campaign);
            [$goals, $strength, $verifier] = $this->services();
            $goals->expects($this->once())->method('reconcile')->willReturn($this->goals());
            $strength->expects($this->once())->method('checkAdStrength')->willReturn($this->strength(['verified' => false, 'unresolved' => [['reason' => 'no_enabled_rsa']]]));
            $check = new \ReflectionMethod(CheckGoogleCampaignReadiness::class, 'checkStrategy');
            $state = $check->invoke(new CheckGoogleCampaignReadiness($campaign->id), $campaign, $strategy, '61', $strength, $verifier);
            $this->assertFalse($state['ready']);
            $this->assertSame('needs_review', $state['status']);
            $this->assertSame('ad_strength_no_enabled_rsa', $state['issues'][0]['code']);
        }
    }

    public function test_executed_search_baseline_overrides_the_legacy_display_default_for_rsa_checks(): void
    {
        [$campaign, $customer, $strategy] = $this->workspace([], [], ['campaign_type' => 'display', 'execution_result' => ['metadata' => ['google_search_baseline' => ['version' => 1, 'keywords' => [['text' => 'service']], 'ads' => [['resource_name' => 'customers/1234567890/adGroupAds/1~2']]]]]]);
        [$goals, $strength, $verifier] = $this->services();
        $goals->expects($this->once())->method('reconcile')->willReturn($this->goals());
        $strength->expects($this->once())->method('checkAdStrength')->willReturn($this->strength());
        (new CheckGoogleCampaignReadiness($campaign->id))->handle($strength, $verifier);
        $this->assertTrue($this->snapshot($strategy)['ad_strength']['verified']);
        $this->assertTrue($this->snapshot($strategy)['ready']);
    }
}
