<?php

namespace Tests\Unit\GoogleAds;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\GoogleAds\CommonServices\ReadCreativeStagingSafety;
use Google\Ads\GoogleAds\V26\Common\Metrics;
use Google\Ads\GoogleAds\V26\Common\TargetSpend;
use Google\Ads\GoogleAds\V26\Resources\Campaign as GoogleCampaign;
use Google\Ads\GoogleAds\V26\Resources\Campaign\GeoTargetTypeSetting;
use Google\Ads\GoogleAds\V26\Resources\Campaign\NetworkSettings;
use Google\Ads\GoogleAds\V26\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V26\Resources\Customer as GoogleCustomer;
use Google\Ads\GoogleAds\V26\Services\GoogleAdsRow;
use Tests\TestCase;

class CreativeStagingSafetyReadTest extends TestCase
{
    private function fixture(?Metrics $metrics = null): array
    {
        $customer = new Customer(['google_ads_customer_id' => '1234567890']);
        $campaign = new Campaign(['google_ads_campaign_id' => 'customers/1234567890/campaigns/61']);
        $campaign->setRelation('customer', $customer);
        $row = new GoogleAdsRow(['campaign' => new GoogleCampaign(['resource_name' => $campaign->googleAdsResourceName(),
            'campaign_budget' => 'customers/1234567890/campaignBudgets/90', 'status' => 2, 'advertising_channel_type' => 2,
            'bidding_strategy_type' => 9, 'start_date_time' => '2026-10-04 15:30:00', 'end_date_time' => '',
            'target_spend' => new TargetSpend(['cpc_bid_ceiling_micros' => 3_000_000]),
            'network_settings' => new NetworkSettings(['target_google_search' => true]),
            'geo_target_type_setting' => new GeoTargetTypeSetting(['positive_geo_target_type' => 7])]),
            'campaign_budget' => new CampaignBudget(['resource_name' => 'customers/1234567890/campaignBudgets/90', 'amount_micros' => 10_000_000]),
            'customer' => new GoogleCustomer(['currency_code' => 'AUD', 'time_zone' => 'Australia/Sydney']),
            'metrics' => $metrics ?? new Metrics(['cost_micros' => 0])]);

        return [$campaign, new ScriptedCreativeStagingRead($customer, $row)];
    }

    public function test_four_scoped_queries_preserve_known_zero_lifetime_spend_and_complete_modifier_proof(): void
    {
        [$campaign, $reader] = $this->fixture();
        $proof = $reader->forCampaign($campaign);

        $this->assertCount(4, $reader->queries);
        $this->assertSame(0, $proof['cost_micros']);
        $this->assertSame('2026-10-04', $proof['start_date']);
        $this->assertNull($proof['end_date']);
        $this->assertSame([], $proof['amplifying_bid_modifiers']);
        $this->assertTrue($proof['modifier_reads_complete']);
        $this->assertSame(3_000_000, $proof['actual_cpc_ceiling_micros']);
        $this->assertSame(10_000_000, $proof['daily_budget_micros']);
        $this->assertStringContainsString('metrics.cost_micros', $reader->queries[0]);
        $this->assertStringNotContainsString('segments.date', $reader->queries[0]);
        foreach ($reader->queries as $query) {
            $this->assertStringContainsString("campaign.resource_name = 'customers/1234567890/campaigns/61'", $query);
        }
    }

    public function test_missing_reported_spend_never_becomes_zero(): void
    {
        [$campaign, $reader] = $this->fixture(new Metrics);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('lifetime spend is unavailable');
        $reader->forCampaign($campaign);
    }

    public function test_every_modifier_domain_is_read_and_a_failed_read_cannot_certify_no_amplifiers(): void
    {
        [$campaign, $reader] = $this->fixture();
        $reader->modifierRows = [
            'campaign_criterion' => new GoogleAdsRow(['campaign_criterion' => new \Google\Ads\GoogleAds\V26\Resources\CampaignCriterion([
                'resource_name' => 'customers/1234567890/campaignCriteria/61~1', 'bid_modifier' => 1.2])]),
            'ad_group_bid_modifier' => new GoogleAdsRow(['ad_group_bid_modifier' => new \Google\Ads\GoogleAds\V26\Resources\AdGroupBidModifier([
                'resource_name' => 'customers/1234567890/adGroupBidModifiers/7~2', 'bid_modifier' => 1.3])]),
            'ad_group_criterion' => new GoogleAdsRow(['ad_group_criterion' => new \Google\Ads\GoogleAds\V26\Resources\AdGroupCriterion([
                'resource_name' => 'customers/1234567890/adGroupCriteria/7~3', 'bid_modifier' => 1.4])]),
        ];
        $proof = $reader->forCampaign($campaign);
        $this->assertCount(3, $proof['amplifying_bid_modifiers']);
        $reader->failAt = 3;
        $reader->queries = [];
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Modifier read failed');
        $reader->forCampaign($campaign);
    }
}

class ScriptedCreativeStagingRead extends ReadCreativeStagingSafety
{
    public array $queries = [];

    public array $modifierRows = [];

    public ?int $failAt = null;

    public function __construct(Customer $customer, private GoogleAdsRow $campaignRow)
    {
        $this->customer = $customer;
    }

    protected function rows(string $customerId, string $query): iterable
    {
        if ($customerId !== '1234567890') {
            throw new \RuntimeException('Wrong Google account');
        }
        $this->queries[] = $query;
        if ($this->failAt === count($this->queries)) {
            throw new \RuntimeException('Modifier read failed');
        }
        if (str_contains($query, 'FROM campaign WHERE')) {
            return [$this->campaignRow];
        }
        foreach ($this->modifierRows as $entity => $row) {
            if (str_contains($query, "FROM {$entity} ")) {
                return [$row];
            }
        }

        return [];
    }
}
