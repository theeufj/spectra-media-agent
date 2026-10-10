<?php

namespace Tests\Feature;

use App\Services\GoogleAds\CommonServices\GetCompetitiveCampaignState;
use Google\Ads\GoogleAds\V22\Common\TargetSpend;
use Google\Ads\GoogleAds\V22\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V22\Resources\AdGroup;
use Google\Ads\GoogleAds\V22\Resources\AdGroupCriterion;
use Google\Ads\GoogleAds\V22\Resources\Campaign;
use Google\Ads\GoogleAds\V22\Resources\CampaignBudget;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsRow;
use Google\ApiCore\PagedListResponse;
use Tests\TestCase;

class OptimizationPlatformStateTest extends TestCase
{
    public function test_current_bidding_controls_and_inherited_keyword_bid_have_google_provenance(): void
    {
        $reader = $this->createPartialMock(GetCompetitiveCampaignState::class, ['ensureClient', 'searchQuery']);
        $reader->method('ensureClient')->willReturnCallback(fn () => null);
        $reader->expects($this->exactly(3))->method('searchQuery')->willReturnCallback(function ($account, $query) {
            $this->assertSame('123', $account);
            [$fields] = preg_split('/\s+FROM\s+/', $query, 2);
            $this->assertStringContainsString('campaign.resource_name', $fields);
            $rows = [];
            if (str_contains($query, 'FROM campaign WHERE')) {
                $this->assertStringContainsString('campaign.bidding_strategy_type', $fields);
                $this->assertStringContainsString('campaign.target_spend.cpc_bid_ceiling_micros', $fields);
                $rows = [new GoogleAdsRow(['campaign' => new Campaign(['status' => 2, 'advertising_channel_type' => 2,
                    'bidding_strategy_type' => BiddingStrategyType::TARGET_SPEND,
                    'target_spend' => new TargetSpend(['cpc_bid_ceiling_micros' => 2000000])]),
                    'campaign_budget' => new CampaignBudget(['amount_micros' => 10000000])])];
            } elseif (str_contains($query, 'FROM ad_group_criterion')) {
                $this->assertStringContainsString('ad_group.cpc_bid_micros', $fields);
                $rows = [new GoogleAdsRow(['ad_group' => new AdGroup(['resource_name' => 'customers/123/adGroups/12', 'cpc_bid_micros' => 1500000]),
                    'ad_group_criterion' => new AdGroupCriterion(['resource_name' => 'customers/123/adGroupCriteria/12~45',
                        'status' => 2, 'cpc_bid_micros' => 0, 'keyword' => new \Google\Ads\GoogleAds\V22\Common\KeywordInfo(['text' => 'ads management', 'match_type' => 2])])])];
            }
            $response = $this->createMock(PagedListResponse::class);
            $response->method('getIterator')->willReturn(new \ArrayIterator($rows));

            return $response;
        });

        $state = $reader('123', 'customers/123/campaigns/9');

        $this->assertSame('google_ads_api', $state['source']);
        $this->assertSame('customers/123/campaigns/9', $state['campaign_resource']);
        $this->assertNotEmpty($state['observed_at']);
        $this->assertSame('TARGET_SPEND', $state['bidding_strategy_type']);
        $this->assertFalse($state['keyword_bids_supported']);
        $this->assertSame(2000000, $state['bidding_controls']['cpc_bid_ceiling_micros']);
        $this->assertSame(0, $state['keywords'][0]['cpc_bid_micros']);
        $this->assertSame(1500000, $state['keywords'][0]['ad_group_cpc_bid_micros']);
    }
}
