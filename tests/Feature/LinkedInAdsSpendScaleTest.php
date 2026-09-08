<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\EnabledPlatform;
use App\Models\LinkedInAdsPerformanceData;
use App\Services\LinkedInAds\CampaignService;
use App\Services\LinkedInAds\PerformanceService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * LinkedIn's `AdBudget`/`MoneyAmount` amount is a decimal string in the account
 * currency — "50" is fifty dollars. Only Facebook takes minor units. Budgets
 * were sent multiplied by 100 and spend was read back divided by 100, so a
 * linked campaign would burn 100x its budget while
 * AdSpendBillingService::getLinkedInAdsSpend() charged a hundredth of what it
 * cost against prepaid credit.
 */
class LinkedInAdsSpendScaleTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'linkedinads.client_id' => 'test-id',
            'linkedinads.client_secret' => 'test-secret',
            'linkedinads.refresh_token' => 'test-refresh',
        ]);

        EnabledPlatform::updateOrCreate(['slug' => 'linkedin'], ['name' => 'LinkedIn', 'is_enabled' => true]);
        Cache::forget('enabled_platform_slugs');

        Http::fake([
            'www.linkedin.com/oauth/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
            'api.linkedin.com/*' => Http::response(['id' => 'urn:li:sponsoredCampaign:1'], 200),
        ]);
    }

    private function customer(): Customer
    {
        return Customer::factory()->create(['linkedin_ads_account_id' => '508123456']);
    }

    public function test_a_budget_update_sends_whole_currency(): void
    {
        (new CampaignService($this->customer()))->updateBudget('123', 75.5);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), '/rest/adCampaigns/123')) {
                return false;
            }

            return $request->data()['patch']['$set']['dailyBudget']
                === ['currencyCode' => 'USD', 'amount' => '75.50'];
        });
    }

    public function test_a_message_ads_budget_also_sends_whole_currency(): void
    {
        (new CampaignService($this->customer()))->createMessageAdsCampaign([
            'name' => 'InMail',
            'daily_budget' => 120,
        ]);

        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/rest/adCampaigns')
            && $request->data()['dailyBudget']['amount'] === '120.00');
    }

    public function test_reported_spend_is_stored_as_the_dollars_linkedin_reported(): void
    {
        $customer = $this->customer();
        $campaign = Campaign::factory()->create([
            'customer_id' => $customer->id,
            'linkedin_campaign_id' => 'urn:li:sponsoredCampaign:1',
        ]);

        $service = new PerformanceService($customer);

        $method = new ReflectionMethod(PerformanceService::class, 'storePerformanceData');
        $method->setAccessible(true);

        $stored = $method->invoke($service, $campaign, [[
            'dateRange' => ['start' => ['year' => 2026, 'month' => 9, 'day' => 2]],
            'impressions' => 1000,
            'clicks' => 40,
            'costInLocalCurrency' => '86.40',
            'externalWebsiteConversions' => 4,
        ]]);

        $this->assertSame(1, $stored);

        $row = LinkedInAdsPerformanceData::where('campaign_id', $campaign->id)->firstOrFail();

        // Dividing by 100 stored $0.86 for $86.40 of real spend.
        $this->assertEqualsWithDelta(86.40, $row->cost, 0.001);
        $this->assertEqualsWithDelta(2.16, $row->cpc, 0.001);
        $this->assertEqualsWithDelta(21.60, $row->cpa, 0.001);
    }
}
