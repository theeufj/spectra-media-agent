<?php

namespace Tests\Feature;

use App\Jobs\FetchFacebookAdsPerformanceData;
use App\Jobs\FetchGoogleAdsPerformanceData;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Campaigns\CampaignResourceInventory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CampaignResourceInventoryTest extends TestCase
{
    use DatabaseTransactions;

    public function test_two_google_resources_and_meta_are_kept_under_their_strategies(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890', 'facebook_ads_account_id' => 'act_5544']);
        $campaign = Campaign::factory()->for($customer)->create(['google_ads_campaign_id' => '101', 'facebook_ads_campaign_id' => '301']);
        $first = $this->strategy($campaign, 'Google Ads', ['google_ads_campaign_id' => 'customers/1234567890/campaigns/101']);
        $second = $this->strategy($campaign, 'Google Ads', ['google_ads_campaign_id' => '102']);
        $meta = $this->strategy($campaign, 'Facebook Ads', ['facebook_campaign_id' => '301']);
        $entries = CampaignResourceInventory::entries($campaign);

        self::assertSame([$first->id, $second->id, $meta->id], array_column($entries, 'strategy_id'));
        self::assertSame(['101', '102', '301'], array_column($entries, 'resource_id'));
        self::assertSame(['1234567890', '1234567890', '5544'], array_column($entries, 'account_id'));
        $view = CampaignResourceInventory::view($campaign, $entries[2]);
        self::assertNull($view->google_ads_campaign_id);
        self::assertSame('301', $view->facebook_ads_campaign_id);
        self::assertFalse($view->isDirty());
        self::assertSame('101', $campaign->fresh()->google_ads_campaign_id);
        (new FetchGoogleAdsPerformanceData($campaign))->handle();
        Queue::assertPushed(FetchGoogleAdsPerformanceData::class, 2);
        Queue::assertNotPushed(FetchFacebookAdsPerformanceData::class);
    }

    public function test_foreign_accounts_and_ambiguous_claims_cannot_be_used_as_strategy_evidence(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890', 'facebook_ads_account_id' => '5544']);
        $campaign = Campaign::factory()->for($customer)->create(['google_ads_campaign_id' => 'customers/9999999999/campaigns/88', 'facebook_ads_campaign_id' => '888']);
        $this->strategy($campaign, 'Google Ads', ['google_ads_campaign_id' => 'customers/9999999999/campaigns/88']);
        $this->strategy($campaign, 'Google Ads', ['google_ads_campaign_id' => '89', 'launch_intent' => ['accounts' => ['google_ads_customer_id' => '9999999999']]]);
        $this->strategy($campaign, 'Facebook Ads', ['facebook_campaign_id' => '888']);
        $this->strategy($campaign, 'Facebook Ads', ['facebook_campaign_id' => '888']);
        $entries = CampaignResourceInventory::entries($campaign);
        self::assertCount(1, $entries);
        self::assertSame('888', $entries[0]['resource_id']);
        self::assertNull($entries[0]['strategy_id']);
    }

    private function strategy(Campaign $campaign, string $platform, array $attributes): Strategy
    {
        $strategy = Strategy::factory()->for($campaign)->create(['platform' => $platform]);
        $strategy->forceFill($attributes)->save();

        return $strategy;
    }
}
