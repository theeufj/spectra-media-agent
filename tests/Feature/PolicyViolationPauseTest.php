<?php

namespace Tests\Feature;

use App\Jobs\CheckCampaignPolicyViolations;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\CampaignAlertService;
use App\Services\Agents\SelfHealingAgent;
use App\Services\Customers\DeactivateCustomerService;
use Google\Ads\GoogleAds\V22\Enums\AdGroupAdStatusEnum\AdGroupAdStatus;
use Google\Ads\GoogleAds\V22\Enums\AdGroupStatusEnum\AdGroupStatus;
use Google\Ads\GoogleAds\V22\Enums\PolicyApprovalStatusEnum\PolicyApprovalStatus;
use Google\Ads\GoogleAds\V22\Enums\PolicyReviewStatusEnum\PolicyReviewStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Policy checks alert without pausing campaigns. The customer-approved end
 * date still stops spend through the shared platform sweep.
 */
class PolicyViolationPauseTest extends TestCase
{
    use DatabaseTransactions;

    private function pauseExpiredCampaign(Campaign $campaign): void
    {
        $method = new \ReflectionMethod(CheckCampaignPolicyViolations::class, 'pauseExpiredCampaign');
        $method->setAccessible(true);
        $method->invoke(new CheckCampaignPolicyViolations($campaign->id), $campaign);
    }

    /**
     * The pause sweep, stubbed at the seam the job now goes through.
     *
     * No return type: the caller reads $pausedCampaignIds off the anonymous
     * class, which a DeactivateCustomerService hint would hide.
     *
     * @param  true|string|null  $outcome  what the platforms said
     */
    private function fakeDeactivator(true|string|null $outcome)
    {
        return new class($outcome) extends DeactivateCustomerService
        {
            /** @var list<int> */
            public array $pausedCampaignIds = [];

            public function __construct(private readonly true|string|null $outcome) {}

            public function pauseCampaign(Customer $customer, Campaign $campaign): true|string|null
            {
                $this->pausedCampaignIds[] = $campaign->id;

                if ($this->outcome === true) {
                    // What the real implementation does once a platform accepts.
                    $campaign->applyPlatformStatus('PAUSED');
                }

                return $this->outcome;
            }
        };
    }

    private function liveCampaign(): Campaign
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123-456-7890']);

        return Campaign::factory()->create([
            'customer_id' => $customer->id,
            'status' => 'active',
            'platform_status' => 'ENABLED',
            'google_ads_campaign_id' => 'customers/1234567890/campaigns/999',
        ]);
    }

    private function checkGooglePolicy(Campaign $campaign, array $ads): bool
    {
        $job = new class($campaign->id, $ads) extends CheckCampaignPolicyViolations
        {
            public function __construct(int $campaignId, private array $ads)
            {
                parent::__construct($campaignId);
            }

            protected function googleAds(Campaign $campaign, string $campaignResourceName): array
            {
                return $this->ads;
            }
        };

        return (new \ReflectionMethod(CheckCampaignPolicyViolations::class, 'checkGoogleAdsPolicyViolations'))
            ->invoke($job, $campaign);
    }

    private function googleAd(int $approval, int $status = AdGroupAdStatus::ENABLED, int $groupStatus = AdGroupStatus::ENABLED): array
    {
        return [
            'resource_name' => 'customers/1234567890/adGroupAds/1~2',
            'status' => $status,
            'ad_group_status' => $groupStatus,
            'approval_status' => $approval,
            'review_status' => PolicyReviewStatus::REVIEWED,
        ];
    }

    public function test_one_disapproved_ad_does_not_pause_a_campaign_with_an_approved_enabled_ad(): void
    {
        $campaign = $this->liveCampaign();
        $deactivator = $this->fakeDeactivator(true);
        $this->app->instance(DeactivateCustomerService::class, $deactivator);

        $found = $this->checkGooglePolicy($campaign, [
            $this->googleAd(PolicyApprovalStatus::DISAPPROVED),
            $this->googleAd(PolicyApprovalStatus::APPROVED),
        ]);

        $this->assertTrue($found, 'The rejected ad still needs healing.');
        $this->assertSame([], $deactivator->pausedCampaignIds);
        $this->assertSame('active', $campaign->fresh()->status->value);
    }

    public function test_all_disapproved_enabled_ads_alert_without_pausing_the_campaign(): void
    {
        $campaign = $this->liveCampaign();
        $deactivator = $this->fakeDeactivator(true);
        $this->app->instance(DeactivateCustomerService::class, $deactivator);

        $this->assertTrue($this->checkGooglePolicy($campaign, [
            $this->googleAd(PolicyApprovalStatus::DISAPPROVED),
            array_replace($this->googleAd(PolicyApprovalStatus::DISAPPROVED), ['resource_name' => 'customers/1234567890/adGroupAds/1~3']),
        ]));

        $this->assertSame([], $deactivator->pausedCampaignIds);
        $campaign->refresh();
        $this->assertSame('active', $campaign->status->value);
        $this->assertSame('ENABLED', $campaign->platform_status);
        $this->assertSame('issues', CampaignAlertService::policyStatus($campaign)['status']);
        $this->assertCount(2, CampaignAlertService::policyStatus($campaign)['issues']);
        $this->assertDatabaseMissing('agent_activities', [
            'campaign_id' => $campaign->id,
            'action' => 'policy_paused_campaign',
        ]);
    }

    public function test_approved_ads_cannot_keep_spending_after_the_customer_end_date(): void
    {
        $campaign = $this->liveCampaign();
        $campaign->update(['end_date' => now()->subDay()]);
        $deactivator = $this->fakeDeactivator(true);
        $this->app->instance(DeactivateCustomerService::class, $deactivator);

        (new CheckCampaignPolicyViolations($campaign->id))->handle($this->createMock(SelfHealingAgent::class));

        $this->assertSame([$campaign->id], $deactivator->pausedCampaignIds);
        $this->assertSame('paused', $campaign->fresh()->status->value);
        $this->assertDatabaseHas('agent_activities', [
            'campaign_id' => $campaign->id,
            'action' => 'campaign_end_date_paused',
        ]);
    }

    public function test_an_end_date_stop_pauses_through_the_shared_platform_sweep(): void
    {
        $campaign = $this->liveCampaign();

        $deactivator = $this->fakeDeactivator(true);
        $this->app->instance(DeactivateCustomerService::class, $deactivator);

        $this->pauseExpiredCampaign($campaign);

        $this->assertSame(
            [$campaign->id],
            $deactivator->pausedCampaignIds,
            'The job must hand the campaign to the one implementation that calls the platforms.'
        );

        $campaign->refresh();

        $this->assertSame('paused', $campaign->status->value);
        $this->assertSame(
            'PAUSED',
            $campaign->platform_status,
            'Without this column the hourly monitor reads ENABLED and flips `status` straight back to active.'
        );
    }

    public function test_a_platform_refusing_the_end_date_stop_is_reported_and_leaves_the_columns_alone(): void
    {
        // A campaign marked paused while its ads keep running is the worse of
        // the two states: billing filters on `status`, so we would stop charging
        // for spend that carries on. The refusal has to reach the exception
        // dashboard instead — Log::error alone never does.
        $campaign = $this->liveCampaign();

        $this->app->instance(DeactivateCustomerService::class, $this->fakeDeactivator('Google: PERMISSION_DENIED'));

        $reported = [];
        $handler = \Mockery::mock(\Illuminate\Contracts\Debug\ExceptionHandler::class);
        $handler->shouldIgnoreMissing();
        /** @var \Mockery\Expectation $expectation */
        $expectation = $handler->shouldReceive('report');
        $expectation->andReturnUsing(function (\Throwable $e) use (&$reported) {
            $reported[] = $e;
        });
        $this->app->instance(\Illuminate\Contracts\Debug\ExceptionHandler::class, $handler);

        $this->pauseExpiredCampaign($campaign);

        $this->assertCount(1, $reported);
        $this->assertStringContainsString('PERMISSION_DENIED', $reported[0]->getMessage());
        $this->assertStringContainsString('End-date pause refused', $reported[0]->getMessage());

        $campaign->refresh();

        $this->assertSame('active', $campaign->status->value);
        $this->assertSame('ENABLED', $campaign->platform_status);
    }

    public function test_the_job_no_longer_writes_the_lifecycle_status_on_its_own(): void
    {
        // The regression this replaced was a bare update(['status' => Paused]).
        // Nothing else in the file writes a status, so its absence is the check
        // that no third branch quietly reintroduces the half-pause.
        $source = file_get_contents(app_path('Jobs/CheckCampaignPolicyViolations.php'));

        $this->assertStringNotContainsString(
            "update(['status'",
            $source,
            'Pausing must go through DeactivateCustomerService, which writes both status columns.'
        );
    }
}
