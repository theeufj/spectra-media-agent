<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GoogleAds\CommonServices\GetCampaignSpendSafety;
use Google\Ads\GoogleAds\V22\Common\Metrics;
use Google\Ads\GoogleAds\V22\Common\TargetSpend;
use Google\Ads\GoogleAds\V22\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V22\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V22\Resources\AdGroupCriterion;
use Google\Ads\GoogleAds\V22\Resources\Campaign as GoogleCampaign;
use Google\Ads\GoogleAds\V22\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V22\Resources\Customer as GoogleCustomer;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsRow;
use Google\ApiCore\PagedListResponse;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GoogleSpendSafetySnapshotTest extends TestCase
{
    use DatabaseTransactions;

    public function test_the_snapshot_uses_the_google_account_day_and_unsegmented_integer_lifetime_cost(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-10 00:30:00', 'UTC'));
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890', 'timezone' => 'Australia/Sydney']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'google_ads_campaign_id' => '999']);
        $reader = new FakeCampaignSpendSafetyReader($customer);
        $reader->lifetimeCost = 194_450_123;
        $snapshot = $reader->forCampaign($campaign);

        $this->assertSame(194_450_123, $snapshot['cost_micros']);
        $this->assertSame('America/Los_Angeles', $snapshot['account_timezone']);
        $this->assertSame('AUD', $snapshot['currency_code']);
        $this->assertSame('2026-10-07', $snapshot['matured_through']);
        $this->assertSame('2026-09-09', $snapshot['window_start']);
        $this->assertCount(8, $reader->queries);
        $this->assertStringNotContainsString('segments.date', $reader->queries[1]);
        $this->assertStringContainsString("segments.date <= '2026-10-07'", $reader->queries[2]);
        $this->assertStringContainsString("BETWEEN '2026-09-09' AND '2026-10-09'", $reader->queries[4]);
        $this->assertStringNotContainsString('ad_group', implode(' ', array_slice($reader->queries, 0, 5)));
        $this->assertSame([], $snapshot['amplifying_bid_modifiers']);
    }

    public function test_a_campaign_that_has_never_spent_still_has_a_zero_baseline(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'google_ads_campaign_id' => '999']);
        $reader = new FakeCampaignSpendSafetyReader($customer);
        $reader->omitZeroMetrics = true;
        $snapshot = $reader->forCampaign($campaign);
        $this->assertSame(0, $snapshot['cost_micros']);
        $this->assertSame(0.0, $snapshot['conversions']);
        $this->assertSame(0, $snapshot['window_matured_cost_micros']);
    }

    public function test_demographic_and_audience_criterion_bid_boosts_are_detected_within_the_campaign(): void
    {
        $customer = Customer::factory()->create(['google_ads_customer_id' => '1234567890']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id, 'google_ads_campaign_id' => '999']);
        $reader = new FakeCampaignSpendSafetyReader($customer);
        $reader->criterionBidBoost = 1.4;
        $snapshot = $reader->forCampaign($campaign);
        $this->assertSame(1.4, $snapshot['amplifying_bid_modifiers'][0]['multiplier']);
        $this->assertStringContainsString("campaign.resource_name = 'customers/1234567890/campaigns/999'", $reader->queries[7]);
        $this->assertStringContainsString("ad_group_criterion.status = 'ENABLED'", $reader->queries[7]);
    }
}

class FakeCampaignSpendSafetyReader extends GetCampaignSpendSafety
{
    public array $queries = [];

    public int $lifetimeCost = 0;

    public bool $omitZeroMetrics = false;

    public float $criterionBidBoost = 0.0;

    public function __construct(Customer $customer)
    {
        $this->customer = $customer;
    }

    protected function ensureClient(): void {}

    protected function searchQuery(string $customerId, string $query): PagedListResponse
    {
        $this->queries[] = $query;
        if (str_contains($query, 'customer.time_zone')) {
            $rows = [new GoogleAdsRow([
                'customer' => new GoogleCustomer(['time_zone' => 'America/Los_Angeles', 'currency_code' => 'AUD']),
                'campaign' => new GoogleCampaign([
                    'status' => CampaignStatus::ENABLED, 'advertising_channel_type' => AdvertisingChannelType::SEARCH,
                    'bidding_strategy_type' => BiddingStrategyType::TARGET_SPEND,
                    'target_spend' => new TargetSpend(['cpc_bid_ceiling_micros' => 3_000_000]),
                ]),
                'campaign_budget' => new CampaignBudget(['amount_micros' => 10_000_000]),
            ])];
        } elseif (str_contains($query, 'FROM ad_group_criterion') && $this->criterionBidBoost > 1.0) {
            $rows = [new GoogleAdsRow(['ad_group_criterion' => new AdGroupCriterion([
                'resource_name' => 'customers/1234567890/adGroupCriteria/1~2', 'bid_modifier' => $this->criterionBidBoost,
            ])])];
        } elseif (str_contains($query, 'bid_modifier')) {
            $rows = [];
        } else {
            $rows = $this->omitZeroMetrics ? [] : [new GoogleAdsRow(['metrics' => new Metrics([
                'cost_micros' => $this->lifetimeCost, 'conversions' => 0.0,
            ])])];
        }

        return new class($rows) extends PagedListResponse
        {
            public function __construct(private array $rows) {}

            public function getIterator(): \Generator
            {
                yield from $this->rows;
            }
        };
    }
}
