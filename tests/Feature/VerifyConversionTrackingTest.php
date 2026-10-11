<?php

namespace Tests\Feature;

use App\Enums\CampaignStatus;
use App\Jobs\VerifyConversionTracking;
use App\Models\AgentRun;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Strategy;
use App\Models\User;
use App\Notifications\CriticalAgentAlert;
use App\Services\GoogleAds\CampaignConversionTrackingHealth;
use App\Services\GoogleAds\ConversionTrackingService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Tests\TestCase;

class VerifyConversionTrackingTest extends TestCase
{
    use DatabaseTransactions;

    private array $accountReads = [];

    private array $strategyChecks = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Http::fake();
    }

    private function strategy(string $customerId = '1234567890', array $campaignAttributes = [], array $strategyAttributes = []): Strategy
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => $customerId]);
        $campaign = Campaign::factory()->create(array_merge(['customer_id' => $customer->id,
            'status' => CampaignStatus::Active, 'google_ads_campaign_id' => "customers/{$customerId}/campaigns/123",
            'end_date' => now()->addMonth()], $campaignAttributes));

        return Strategy::factory()->create(array_merge(['campaign_id' => $campaign->id, 'platform' => 'Google Ads',
            'deployment_status' => 'active', 'deployed_at' => now()->subDay()], $strategyAttributes))->load('campaign.customer');
    }

    private function activity(array $override = []): array
    {
        return array_merge(['period' => 'LAST_30_DAYS', 'conversions' => 0.0, 'all_conversions' => 6.0,
            'impressions' => 152, 'clicks' => 10], $override);
    }

    private function health(string $status, array $override = []): array
    {
        return array_merge(['status' => $status, 'message' => 'The selected action has not reported a conversion; this does not establish a tracking fault.',
            'configuration_status' => 'ready', 'checked_at' => now()->toIso8601String(), 'issues' => [],
            'selected_action_activity' => ['conversions' => 0.0, 'all_conversions' => 0.0],
            'intent' => ['action_resource' => 'customers/1234567890/conversionActions/456']], $override);
    }

    private function bindEvidence(array $activities, array $healthByStrategy): void
    {
        $this->app->bind(ConversionTrackingService::class, function ($app, $parameters) use ($activities) {
            $customer = $parameters['customer'];
            $service = Mockery::mock(ConversionTrackingService::class);
            $this->expectMockCall($service, 'getAccountActivityLast30Days')->with($customer->cleanGoogleCustomerId())
                ->andReturnUsing(function (string $id) use ($activities): array {
                    $this->accountReads[] = $id;
                    $result = $activities[$id];
                    if ($result instanceof \Throwable) {
                        throw $result;
                    }

                    return $result;
                });

            return $service;
        });
        $health = Mockery::mock(CampaignConversionTrackingHealth::class);
        $this->expectMockCall($health, 'inspect')->andReturnUsing(function (Strategy $strategy, ConversionTrackingService $service, array $account) use ($healthByStrategy): array {
            $this->strategyChecks[] = $strategy->id;
            $result = $healthByStrategy[$strategy->id];
            if ($result instanceof \Throwable) {
                throw $result;
            }

            return array_merge($result, ['account_activity' => $account]);
        });
        $this->app->instance(CampaignConversionTrackingHealth::class, $health);
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

    private function checkTracking(): AgentRun
    {
        (new VerifyConversionTracking)->handle();

        return AgentRun::where('job', 'VerifyConversionTracking')->latest('id')->firstOrFail();
    }

    public function test_zero_selected_conversions_are_unverified_without_a_broken_tracking_email_or_customer_verification(): void
    {
        $strategy = $this->strategy();
        $this->bindEvidence(['1234567890' => $this->activity()], [$strategy->id => $this->health('not_verified')]);

        $run = $this->checkTracking();

        $this->assertSame(AgentRun::STATUS_ATTENTION, $run->status);
        $this->assertSame(1, $run->warnings);
        $this->assertSame(0, $run->errors);
        $this->assertSame('not_verified', $run->details['checks'][0]['status']);
        $this->assertSame(6, $run->details['checks'][0]['account_activity']['all_conversions']);
        $this->assertNull($strategy->campaign->customer->fresh()->conversion_tracking_verified_at);
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_no_traffic_does_not_raise_a_tracking_fault(): void
    {
        $strategy = $this->strategy();
        $this->bindEvidence(['1234567890' => $this->activity(['impressions' => 0, 'clicks' => 0, 'all_conversions' => 0.0])],
            [$strategy->id => $this->health('not_verified')]);

        $run = $this->checkTracking();

        $this->assertSame('not_verified', $run->details['checks'][0]['status']);
        $this->assertSame(0, $run->details['checks'][0]['account_activity']['clicks']);
        $this->assertSame(0, $run->actions_taken);
        Notification::assertNothingSent();
    }

    public function test_observed_selected_action_is_recorded_without_overwriting_customer_setup_timestamp(): void
    {
        $strategy = $this->strategy();
        $verified = now()->subWeek()->startOfSecond();
        $strategy->campaign->customer->update(['conversion_tracking_verified_at' => $verified]);
        $this->bindEvidence(['1234567890' => $this->activity(['conversions' => 2.5])],
            [$strategy->id => $this->health('conversions_observed', ['selected_action_activity' => ['conversions' => 0.5, 'all_conversions' => 0.5]])]);

        $run = $this->checkTracking();

        $this->assertSame('conversions_observed', $run->details['checks'][0]['status']);
        $this->assertSame(0.5, $run->details['checks'][0]['selected_action_activity']['all_conversions']);
        $this->assertSame(0, $run->warnings);
        $this->assertEquals($verified, $strategy->campaign->customer->fresh()->conversion_tracking_verified_at);
        Notification::assertNothingSent();
    }

    public function test_account_api_failure_is_unknown_and_does_not_stop_another_customer_check(): void
    {
        $failed = $this->strategy();
        $good = $this->strategy('9876543210');
        $this->bindEvidence(['1234567890' => new \RuntimeException('Google read unavailable'), '9876543210' => $this->activity()],
            [$good->id => $this->health('not_verified')]);

        $run = $this->checkTracking();

        $this->assertSame(1, $run->errors);
        $this->assertSame(1, $run->warnings);
        $this->assertSame('unknown', $run->details['checks'][0]['status']);
        $this->assertSame([$failed->id], $run->details['checks'][0]['strategy_ids']);
        $this->assertSame('not_verified', $run->details['checks'][1]['status']);
        $this->assertSame([$good->id], $this->strategyChecks);
        Notification::assertNothingSent();
    }

    public function test_campaign_api_failure_is_unknown_and_does_not_stop_other_strategies_in_the_same_account(): void
    {
        $first = $this->strategy();
        $second = Strategy::factory()->create(['campaign_id' => $first->campaign_id, 'platform' => 'Google Ads', 'deployment_status' => 'deployed']);
        $second->forceFill(['google_ads_campaign_id' => 'customers/1234567890/campaigns/999'])->save();
        $this->bindEvidence(['1234567890' => $this->activity()], [$first->id => new \RuntimeException('Goal read unavailable'),
            $second->id => $this->health('conversions_observed')]);

        $run = $this->checkTracking();

        $this->assertSame(['1234567890'], $this->accountReads);
        $this->assertSame([$first->id, $second->id], $this->strategyChecks);
        $this->assertSame(1, $run->errors);
        $this->assertSame('unknown', $run->details['checks'][0]['status']);
        $this->assertSame('conversions_observed', $run->details['checks'][1]['status']);
        Notification::assertNothingSent();
    }

    public function test_paused_ended_undeployed_non_google_and_unidentified_strategies_are_skipped_before_api_calls(): void
    {
        $this->strategy('1234567890', campaignAttributes: ['status' => CampaignStatus::Paused]);
        $this->strategy('1234567891', campaignAttributes: ['end_date' => now()->subDay()]);
        $this->strategy('1234567892', campaignAttributes: ['platform_status' => 'PAUSED']);
        $this->strategy('1234567893', strategyAttributes: ['deployment_status' => 'pending']);
        $this->strategy('1234567894', strategyAttributes: ['platform' => 'Facebook Ads']);
        $this->strategy('1234567895', campaignAttributes: ['google_ads_campaign_id' => null]);
        $this->bindEvidence([], []);

        $run = $this->checkTracking();

        $this->assertSame([], $this->accountReads);
        $this->assertSame([], $this->strategyChecks);
        $this->assertSame([], $run->details['checks']);
        $this->assertSame(AgentRun::STATUS_NO_OP, $run->status);
        Notification::assertNothingSent();
    }

    public function test_remote_inactive_campaign_records_status_without_alert(): void
    {
        $strategy = $this->strategy();
        $this->bindEvidence(['1234567890' => $this->activity()], [$strategy->id => $this->health('inactive')]);

        $run = $this->checkTracking();

        $this->assertSame('inactive', $run->details['checks'][0]['status']);
        $this->assertSame(0, $run->warnings);
        Notification::assertNothingSent();
    }

    public function test_actual_delivery_and_configuration_failures_alert_admins_with_distinct_incidents_and_public_uuid_urls(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::unguarded(fn () => Role::firstOrCreate(['name' => 'admin'])));
        $delivery = $this->strategy();
        $configuration = $this->strategy('9876543210');
        $this->bindEvidence(['1234567890' => $this->activity(), '9876543210' => $this->activity()], [
            $delivery->id => $this->health('delivery_failed', ['message' => 'Recorded signup upload failed.',
                'issues' => [['code' => 'selected_conversion_delivery_failed', 'message' => 'A recorded signup failed processing.']]]),
            $configuration->id => $this->health('needs_review', ['message' => 'The selected goal is disabled.',
                'issues' => [['code' => 'conversion_goal_disabled', 'message' => 'The selected action is disabled.']]])]);

        $run = $this->checkTracking();

        $alerts = Notification::sent($admin, CriticalAgentAlert::class);
        $this->assertCount(2, $alerts);
        $this->assertSame(2, $run->actions_taken);
        $this->assertSame(2, $run->warnings);
        $this->assertSame('conversion_tracking_health', $alerts[0]->alertType);
        $this->assertSame(route('campaigns.direct', $delivery->campaign), $alerts[0]->details['action_url']);
        $this->assertStringContainsString($delivery->campaign->uuid, $alerts[0]->details['action_url']);
        $this->assertNotSame($alerts[0]->details['dedupe_key'], $alerts[1]->details['dedupe_key']);
        Cache::flush();
        $this->assertSame(['mail'], $alerts[0]->via($admin));
        $this->assertSame(['mail'], $alerts[1]->via($admin));
        $this->assertSame([], $alerts[0]->via($admin));
        $mail = $alerts[0]->toMail($admin);
        $body = implode(' ', $mail->introLines);
        $this->assertStringContainsString('10 clicks', $body);
        $this->assertStringContainsString('6 in the All conversions column across all actions', $body);
        $this->assertStringContainsString('selected action in the last 30 days: 0 in the Conversions column', $body);
        $this->assertStringContainsString('server-side', $body);
        $this->assertStringNotContainsString('tracking may be broken', $body);
        Http::assertNothingSent();
    }
}
