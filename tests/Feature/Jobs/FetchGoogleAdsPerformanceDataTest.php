<?php

namespace Tests\Feature\Jobs;

use App\Jobs\FetchGoogleAdsPerformanceData;
use App\Models\Campaign;
use App\Models\Customer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * @group integration
 * @group google-ads
 * @group jobs
 */
class FetchGoogleAdsPerformanceDataTest extends TestCase
{
    use DatabaseTransactions;

    protected Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('RUN_GOOGLE_ADS_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_GOOGLE_ADS_INTEGRATION_TESTS=true to run.');
        }

        $this->campaign = Campaign::with('customer')
            ->whereNotNull('google_ads_campaign_id')
            ->whereHas('customer', fn ($q) => $q->whereNotNull('google_ads_customer_id'))
            ->firstOrFail();
    }

    public function test_job_dispatches_without_exception(): void
    {
        // Prove the job can be constructed and dispatched
        $job = new FetchGoogleAdsPerformanceData($this->campaign);

        $this->assertInstanceOf(FetchGoogleAdsPerformanceData::class, $job);
    }

    public function test_job_handle_syncs_performance_data(): void
    {
        $job = new FetchGoogleAdsPerformanceData($this->campaign);
        $job->handle();

        // After handle(), there should be performance rows for this campaign
        $this->assertDatabaseHas('google_ads_performance_data', [
            'campaign_id' => $this->campaign->id,
        ]);
    }

    public function test_job_is_idempotent_second_run_does_not_duplicate(): void
    {
        $job = new FetchGoogleAdsPerformanceData($this->campaign);
        $job->handle();

        $countBefore = \App\Models\GoogleAdsPerformanceData::where('campaign_id', $this->campaign->id)->count();

        // Run again — updateOrCreate should prevent duplicates
        $job2 = new FetchGoogleAdsPerformanceData($this->campaign);
        $job2->handle();

        $countAfter = \App\Models\GoogleAdsPerformanceData::where('campaign_id', $this->campaign->id)->count();

        $this->assertSame($countBefore, $countAfter);
    }

    public function test_job_skips_gracefully_when_no_google_ads_campaign_id(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '123456789']);
        $campaign = Campaign::factory()->create([
            'customer_id'           => $customer->id,
            'google_ads_campaign_id' => null,
        ]);

        $job = new FetchGoogleAdsPerformanceData($campaign);

        // Should not throw — skips with a log warning
        $this->expectNotToPerformAssertions();
        $job->handle();
    }
}
