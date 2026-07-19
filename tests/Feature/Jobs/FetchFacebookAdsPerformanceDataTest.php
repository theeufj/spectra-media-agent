<?php

namespace Tests\Feature\Jobs;

use App\Jobs\FetchFacebookAdsPerformanceData;
use App\Models\Campaign;
use App\Models\Customer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * @group integration
 * @group facebook
 * @group jobs
 */
class FetchFacebookAdsPerformanceDataTest extends TestCase
{
    use DatabaseTransactions;

    protected Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('RUN_FACEBOOK_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_FACEBOOK_INTEGRATION_TESTS=true to run.');
        }

        config(['services.facebook.system_user_token' => env('FACEBOOK_SYSTEM_USER_TOKEN')]);

        $this->campaign = Campaign::with('customer')
            ->whereNotNull('facebook_ads_campaign_id')
            ->whereHas('customer', fn ($q) => $q->whereNotNull('facebook_ads_account_id'))
            ->firstOrFail();
    }

    public function test_job_can_be_constructed(): void
    {
        $job = new FetchFacebookAdsPerformanceData($this->campaign);

        $this->assertInstanceOf(FetchFacebookAdsPerformanceData::class, $job);
    }

    public function test_job_handle_syncs_performance_data(): void
    {
        $job = new FetchFacebookAdsPerformanceData($this->campaign);
        $job->handle();

        $this->assertDatabaseHas('facebook_ads_performance_data', [
            'campaign_id' => $this->campaign->id,
        ]);
    }

    public function test_job_is_idempotent(): void
    {
        $job = new FetchFacebookAdsPerformanceData($this->campaign);
        $job->handle();

        $countBefore = \App\Models\FacebookAdsPerformanceData::where('campaign_id', $this->campaign->id)->count();

        $job2 = new FetchFacebookAdsPerformanceData($this->campaign);
        $job2->handle();

        $countAfter = \App\Models\FacebookAdsPerformanceData::where('campaign_id', $this->campaign->id)->count();

        $this->assertSame($countBefore, $countAfter);
    }

    public function test_job_skips_gracefully_when_no_facebook_campaign_id(): void
    {
        $customer = Customer::factory()->create(['facebook_ads_account_id' => '987654321']);
        $campaign = Campaign::factory()->create([
            'customer_id'              => $customer->id,
            'facebook_ads_campaign_id' => null,
        ]);

        $job = new FetchFacebookAdsPerformanceData($campaign);

        $this->expectNotToPerformAssertions();
        $job->handle();
    }
}
