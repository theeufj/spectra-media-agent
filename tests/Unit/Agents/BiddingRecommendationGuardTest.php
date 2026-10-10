<?php

namespace Tests\Unit\Agents;

use App\Services\Agents\Optimization\BiddingRecommendationGuard;
use Tests\TestCase;

class BiddingRecommendationGuardTest extends TestCase
{
    private function state(string $strategy = 'MANUAL_CPC'): array
    {
        return ['bidding_strategy_type' => $strategy, 'keywords' => [['resource' => 'customers/123/adGroupCriteria/12~45',
            'cpc_bid_micros' => 0, 'ad_group_cpc_bid_micros' => 1000000]]];
    }

    private function recommendation(): array
    {
        return ['type' => 'BIDDING', 'sub_type' => 'keyword_cpc',
            'keyword_resource' => 'customers/123/adGroupCriteria/12~45', 'suggested_value' => 1500000];
    }

    public function test_automated_and_unknown_strategies_do_not_accept_keyword_bid_changes(): void
    {
        foreach (['TARGET_SPEND', 'MAXIMIZE_CLICKS', 'MAXIMIZE_CONVERSIONS', 'MAXIMIZE_CONVERSION_VALUE', 'TARGET_CPA', 'TARGET_ROAS', 'UNKNOWN'] as $strategy) {
            $reason = BiddingRecommendationGuard::rejectionReason($this->recommendation(), $this->state($strategy));
            $this->assertStringContainsString('require confirmed MANUAL_CPC', $reason);
        }
    }

    public function test_a_zero_keyword_bid_inherits_manual_ad_group_cpc_and_unchanged_bids_are_redundant(): void
    {
        $this->assertNull(BiddingRecommendationGuard::rejectionReason($this->recommendation(), $this->state()));
        $reason = BiddingRecommendationGuard::rejectionReason([...$this->recommendation(), 'suggested_value' => 1000000], $this->state());
        $this->assertStringContainsString('inherited ad group bid', $reason);
    }

    public function test_invalid_bid_values_are_rejected_before_integer_coercion(): void
    {
        foreach ([0, -1, 1.5, 'invalid', null, INF, PHP_INT_MAX] as $value) {
            $reason = BiddingRecommendationGuard::rejectionReason([...$this->recommendation(), 'suggested_value' => $value], $this->state());
            $this->assertSame('Keyword CPC must be a positive whole number of micros.', $reason);
        }
    }

    public function test_redundant_strategy_aliases_are_rejected_but_a_target_adjustment_can_be_reviewed(): void
    {
        $reason = BiddingRecommendationGuard::rejectionReason(['type' => 'BIDDING_STRATEGY_CHANGE',
            'suggested_strategy' => 'Maximize Clicks'], $this->state('TARGET_SPEND'));
        $this->assertStringContainsString('already uses TARGET_SPEND', $reason);
        $this->assertNull(BiddingRecommendationGuard::rejectionReason(['type' => 'ADJUST_TARGET_CPA',
            'sub_type' => 'target_cpa', 'suggested_strategy' => 'TARGET_CPA', 'suggested_value' => 50000000], $this->state('TARGET_CPA')));
    }

    public function test_camel_case_and_nested_strategy_names_cannot_bypass_duplicate_detection(): void
    {
        foreach ([
            ['suggested_strategy' => 'MaximizeConversions'],
            ['bidding_strategy' => ['name' => 'MaximizeConversions', 'parameters' => []]],
            ['parameters' => ['bidding_strategy' => ['name' => 'MaximizeConversions']]],
            ['params' => ['strategy' => 'MaximizeConversions']],
            ['parameters' => ['new_strategy' => 'MaximizeConversions']],
        ] as $proposal) {
            $this->assertStringContainsString('already uses MAXIMIZE_CONVERSIONS',
                BiddingRecommendationGuard::rejectionReason(['type' => 'BIDDING_STRATEGY_CHANGE', ...$proposal], $this->state('MAXIMIZE_CONVERSIONS')));
        }
        $this->assertStringContainsString('already uses TARGET_SPEND',
            BiddingRecommendationGuard::rejectionReason(['type' => 'BIDDING_STRATEGY_CHANGE', 'suggested_value' => 'MaximizeClicks'], $this->state('TARGET_SPEND')));
        $this->assertStringContainsString('already uses TARGET_CPA',
            BiddingRecommendationGuard::rejectionReason(['type' => 'BIDDING_STRATEGY_CHANGE', 'suggested_value' => 'TargetCpa'], $this->state('TARGET_CPA')));
    }
}
