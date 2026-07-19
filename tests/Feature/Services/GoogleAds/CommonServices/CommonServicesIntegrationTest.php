<?php

namespace Tests\Feature\Services\GoogleAds\CommonServices;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GoogleAds\CommonServices\GetAdStatus;
use App\Services\GoogleAds\CommonServices\GetCampaignKeywords;
use App\Services\GoogleAds\CommonServices\GetGoogleAdsRecommendations;
use App\Services\GoogleAds\CommonServices\GetSearchTermsReport;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * @group integration
 * @group google-ads
 */
class CommonServicesIntegrationTest extends TestCase
{
    use DatabaseTransactions;

    protected Customer $customer;
    protected string $customerId;
    protected Campaign $liveCampaign;

    protected function setUp(): void
    {
        parent::setUp();

        if (!env('RUN_GOOGLE_ADS_INTEGRATION_TESTS')) {
            $this->markTestSkipped('Set RUN_GOOGLE_ADS_INTEGRATION_TESTS=true to run.');
        }

        $this->customer = Customer::whereNotNull('google_ads_customer_id')->firstOrFail();
        $this->customerId = $this->customer->cleanGoogleCustomerId();

        $live = Campaign::where('customer_id', $this->customer->id)
            ->whereNotNull('google_ads_campaign_id')
            ->first();

        if (!$live) {
            $this->markTestSkipped('No deployed Google Ads campaign found for this customer.');
        }

        $this->liveCampaign = $live;
    }

    public function test_get_ad_status_returns_array_for_campaign(): void
    {
        $service = new GetAdStatus($this->customer);
        $result  = $service->get($this->customerId, $this->liveCampaign->google_ads_campaign_id);

        $this->assertIsArray($result);
        // Each entry should have status and approval_status
        foreach ($result as $ad) {
            $this->assertArrayHasKey('ad_id', $ad);
            $this->assertArrayHasKey('status', $ad);
        }
    }

    public function test_get_search_terms_report_returns_array(): void
    {
        $service = new GetSearchTermsReport($this->customer);
        $result  = $service->get(
            $this->customerId,
            $this->liveCampaign->google_ads_campaign_id,
            now()->subDays(30)->toDateString(),
            now()->toDateString(),
        );

        $this->assertIsArray($result);
        // May be empty if no search terms yet — that's valid
    }

    public function test_get_campaign_keywords_returns_array(): void
    {
        $service = new GetCampaignKeywords($this->customer);
        $result  = $service->get($this->customerId, $this->liveCampaign->google_ads_campaign_id);

        $this->assertIsArray($result);
    }

    public function test_get_google_ads_recommendations_returns_array(): void
    {
        $service = new GetGoogleAdsRecommendations($this->customer);
        $result  = $service->get($this->customerId);

        $this->assertIsArray($result);
    }

    public function test_get_ad_status_returns_approval_status_field(): void
    {
        $service = new GetAdStatus($this->customer);
        $result  = $service->get($this->customerId, $this->liveCampaign->google_ads_campaign_id);

        foreach ($result as $ad) {
            $this->assertArrayHasKey('approval_status', $ad);
            $this->assertContains($ad['approval_status'], [
                'APPROVED', 'APPROVED_LIMITED', 'DISAPPROVED', 'AREA_OF_INTEREST_ONLY', 'UNKNOWN',
            ]);
        }
    }
}
