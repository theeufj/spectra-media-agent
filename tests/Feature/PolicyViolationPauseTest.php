<?php

namespace Tests\Feature;

use App\Jobs\CheckCampaignPolicyViolations;
use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Customers\DeactivateCustomerService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * A policy violation has to stop the ads, and the pause has to survive the
 * hourly monitor.
 *
 * It did neither. Both branches of CheckCampaignPolicyViolations logged
 * "Pausing campaign" and then wrote `status` alone: no status mutation was ever
 * sent to Google or Facebook, so the disapproved ad kept serving — and because
 * `platform_status` still read ENABLED, the next MonitorCampaignStatus run
 * reconciled `status` back to Active and recorded a false status_reconciled
 * AgentActivity for it, every hour, indefinitely.
 */
class PolicyViolationPauseTest extends TestCase
{
    use DatabaseTransactions;

    private function pause(Campaign $campaign, string $reason): void
    {
        $method = new \ReflectionMethod(CheckCampaignPolicyViolations::class, 'pauseCampaign');
        $method->setAccessible(true);
        $method->invoke(new CheckCampaignPolicyViolations($campaign->id), $campaign, $reason);
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

    public function test_a_policy_violation_pauses_through_the_shared_platform_sweep(): void
    {
        $campaign = $this->liveCampaign();

        $deactivator = $this->fakeDeactivator(true);
        $this->app->instance(DeactivateCustomerService::class, $deactivator);

        $this->pause($campaign, 'google_disapproved_ad');

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

    public function test_a_platform_refusing_the_pause_is_reported_and_leaves_the_columns_alone(): void
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

        $this->pause($campaign, 'google_disapproved_ad');

        $this->assertCount(1, $reported);
        $this->assertStringContainsString('PERMISSION_DENIED', $reported[0]->getMessage());

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
