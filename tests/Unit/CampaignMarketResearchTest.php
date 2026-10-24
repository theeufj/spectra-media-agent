<?php

namespace Tests\Unit;

use App\Models\BrandGuideline;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\TargetingConfig;
use App\Services\Campaigns\CampaignMarketResearch;
use App\Services\Campaigns\SearchMarketInputs;
use App\Services\GoogleAds\InitialSearchBidPlanner;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

class CampaignMarketResearchTest extends TestCase
{
    public function test_research_is_automatic_proposal_only_and_never_changes_approval_spend_keywords_or_status(): void
    {
        $campaign = $this->campaign();
        $before = [$campaign->getAttributes(), $campaign->strategies[0]->getAttributes(), $campaign->customer->getAttributes()];
        $calls = [];
        $planner = $this->planner(function ($keywords, $budget, $cap) use (&$calls) {
            $calls[] = [$keywords, $budget, $cap];

            return $this->estimate(true, $budget);
        });
        $service = $this->service($planner);

        $result = $service->research($campaign);

        $this->assertSame('ready', $result['status']);
        $this->assertSame(5_000_000, $result['proposed_daily_budget_micros']);
        $this->assertFalse($result['activation_ready']);
        $this->assertFalse($result['strategies'][0]['auto_repair_safe']);
        $this->assertFalse($result['strategies'][0]['forecast']['auto_repair_safe']);
        $this->assertSame($campaign->strategies[0]->id, $result['strategies'][0]['strategy_id']);
        $this->assertSame('AUD', $result['strategies'][0]['currency_code']);
        $this->assertSame('Australia/Sydney', $result['strategies'][0]['time_zone']);
        $this->assertNotEmpty($service->businessCalls);
        $this->assertSame($before, [$campaign->getAttributes(), $campaign->strategies[0]->getAttributes(), $campaign->customer->getAttributes()]);
        $this->assertStringContainsString('do not guarantee purchases', implode(' ', $result['strategies'][0]['caveats']));
        $this->assertNotEmpty($calls);
    }

    public function test_relevant_alternatives_are_tested_at_current_budget_before_an_expensive_original_set_even_with_campaign_keywords(): void
    {
        $campaign = $this->campaign();
        $campaign->keywords = [['text' => 'expensive original', 'match_type' => 'EXACT']];
        $seen = [];
        $planner = $this->planner(function ($keywords, $budget) use (&$seen) {
            $seen[] = [$keywords[0]['text'], $budget];

            return $this->estimate($keywords[0]['text'] === 'relevant alternative' || $budget >= 10_000_000, $budget);
        });

        $result = $this->service($planner, true)->research($campaign);

        $this->assertSame('ready', $result['status']);
        $this->assertSame([['expensive original', 5_000_000], ['relevant alternative', 5_000_000]], array_slice($seen, 0, 2));
        $this->assertSame(5_000_000, $result['strategies'][0]['budget_micros']);
        $this->assertSame('relevant alternative', $result['strategies'][0]['proposed_keywords'][0]['text']);
        $this->assertSame('expensive original', $campaign->keywords[0]['text']);
    }

    public function test_low_reach_comparisons_keep_the_reference_and_research_lower_and_higher_amounts_without_approving_spend(): void
    {
        $campaign = $this->campaign();
        $before = [$campaign->getAttributes(), $campaign->strategies[0]->getAttributes()];
        $calls = [];
        $service = $this->service($this->planner(function ($keywords, $budget) use (&$calls) {
            $calls[] = $budget;
            $result = $this->estimate(true, $budget);
            $result['evidence']['forecast']['period_days'] = 6;
            $result['evidence']['forecast']['clicks'] = match ($budget) {
                25_000_000 => 1.2, 50_000_000 => 3.9, default => 5.8,
            };

            return $result;
        }));
        $result = $service->research($campaign, 50_000_000);
        $this->assertSame('ready', $result['status']);
        $this->assertSame([50_000_000, 25_000_000, 100_000_000], $calls);
        $this->assertSame([25_000_000, 50_000_000, 100_000_000], array_column($result['budget_options'], 'daily_budget_micros'));
        $this->assertSame(50_000_000, $result['reference_daily_budget_micros']);
        $this->assertSame(50_000_000, $result['proposed_daily_budget_micros']);
        $this->assertSame(50_000_000, collect($result['budget_options'])->firstWhere('id', $result['default_budget_option_id'])['daily_budget_micros']);
        foreach ($result['budget_options'] as $option) {
            $this->assertSame('limited', $option['traffic_status']);
            $this->assertFalse($option['meets_traffic_threshold']);
            $this->assertSame($option['forecast']['clicks'] * 5, $option['forecast_clicks_per_30_days']);
            $this->assertStringContainsString('below', $option['proposal']['strategies'][0]['traffic_warning']);
            $this->assertSame('limited_reach_comparison', $option['proposal']['strategies'][0]['evidence']['selection_policy']);
        }
        $this->assertSame($before, [$campaign->getAttributes(), $campaign->strategies[0]->getAttributes()]);
    }

    public function test_limited_keyword_sets_compete_for_reach_without_calling_tiny_cheap_traffic_viable(): void
    {
        $service = $this->service($this->planner(function ($keywords, $budget) {
            $result = $this->estimate(true, $budget);
            $alternative = $keywords[0]['text'] === 'relevant alternative';
            $result['evidence']['forecast'] = [...$result['evidence']['forecast'], 'period_days' => 6,
                'clicks' => $alternative ? 0.29 : 3.92, 'cost_micros' => $alternative ? 1_000_000 : 114_000_000];

            return $result;
        }), true);
        $result = $service->research($this->campaign(), 50_000_000);
        $this->assertSame('expensive original', $result['strategies'][0]['proposed_keywords'][0]['text']);
        $this->assertSame(3.92, $result['strategies'][0]['forecast']['clicks']);
        $this->assertSame('limited', $result['strategies'][0]['traffic_status']);
    }

    public function test_budget_comparisons_are_anchored_to_the_reference_without_historical_price_jumps(): void
    {
        $seen = [];
        $planner = $this->planner(function ($keywords, $budget) use (&$seen) {
            $seen[] = $budget;

            return $this->estimate($budget >= 8_000_000, $budget);
        });

        $result = $this->service($planner)->research($this->campaign());

        $this->assertSame([5_000_000, 10_000_000, 15_000_000], $seen);
        $this->assertSame([5_000_000, 10_000_000, 15_000_000], array_column($result['budget_comparisons'], 'daily_budget_micros'));
        $this->assertNull($result['default_budget_option_id']);
        $this->assertNull($result['selected_budget_option_id']);
        $this->assertTrue($result['requires_explicit_option_selection']);
        $this->assertSame('ready', $result['status']);
        $this->assertSame(10_000_000, $result['proposed_daily_budget_micros']);
        $this->assertSame(5_000_000, $result['strategies'][0]['current_budget_micros']);
    }

    public function test_existing_cpc_caps_are_retained_in_every_candidate_and_never_replaced_by_a_large_budget(): void
    {
        $campaign = $this->campaign();
        $strategy = $campaign->strategies[0];
        $strategy->bidding_strategy = ['keywords' => $this->selected(), 'parameters' => ['cpcBidCeilingMicros' => 250_000]];
        $seen = [];
        $planner = $this->planner(function ($keywords, $budget, $cap) use (&$seen) {
            $seen[] = $cap;

            return $this->estimate(false, $budget);
        });

        $result = $this->service($planner, true)->research($campaign);

        $this->assertSame('needs_review', $result['status']);
        $this->assertNotEmpty($seen);
        $this->assertSame([250_000], array_values(array_unique($seen)));
        $this->assertLessThanOrEqual(6, count($seen));
        $this->assertSame(250_000, $strategy->bidding_strategy['parameters']['cpcBidCeilingMicros']);
    }

    public function test_lower_selected_keyword_cpc_limits_survive_research_and_bound_original_and_alternative_proposals(): void
    {
        foreach ([false, true] as $chooseAlternative) {
            $campaign = $this->campaign();
            $campaign->keywords = [[...$this->selected()[0], 'cpc_bid_micros' => 250_000]];
            $strategy = $campaign->strategies[0];
            $strategy->bidding_strategy = ['parameters' => ['cpcBidCeilingMicros' => 1_000_000]];
            $seen = [];
            $planner = $this->planner(function ($keywords, $budget, $cap) use (&$seen, $chooseAlternative) {
                $seen[] = $cap;
                $ready = ! $chooseAlternative || $keywords[0]['text'] === 'relevant alternative';

                return array_replace($this->estimate($ready, $budget), ['cpc_bid_micros' => 200_000]);
            });

            $result = $this->service($planner, true)->research($campaign);

            $this->assertSame('ready', $result['status']);
            $this->assertSame([250_000], array_values(array_unique($seen)));
            $this->assertSame(250_000, $result['strategies'][0]['current_keywords'][0]['cpc_bid_micros']);
            $this->assertSame(250_000, $result['strategies'][0]['proposed_keywords'][0]['cpc_bid_micros']);
            $this->assertSame(250_000, $result['strategies'][0]['cpc_ceiling_micros']);
            $this->assertLessThanOrEqual(250_000, $result['strategies'][0]['cpc_bid_micros']);
            $this->assertSame($chooseAlternative ? 'relevant alternative' : 'expensive original', $result['strategies'][0]['proposed_keywords'][0]['text']);
            $this->assertSame(250_000, $campaign->keywords[0]['cpc_bid_micros']);
            $this->assertSame(1_000_000, $strategy->bidding_strategy['parameters']['cpcBidCeilingMicros']);
        }
    }

    public function test_invalid_selected_keyword_cpc_limits_require_review_instead_of_being_stripped(): void
    {
        $campaign = $this->campaign();
        $campaign->keywords = [[...$this->selected()[0], 'cpc_bid_micros' => 'unknown']];
        $planner = $this->createMock(InitialSearchBidPlanner::class);
        $planner->expects($this->never())->method('estimate');

        $result = $this->service($planner, true)->research($campaign);

        $this->assertSame('needs_review', $result['status']);
        $this->assertSame('unknown', $result['strategies'][0]['current_keywords'][0]['cpc_bid_micros']);
        $this->assertStringContainsString('keyword CPC limit is invalid', implode(' ', $result['strategies'][0]['reasons']));
    }

    public function test_every_platform_gets_business_research_but_google_proxy_data_cannot_prove_social_reach(): void
    {
        $campaign = $this->campaign();
        $strategies = [];
        foreach (['Facebook Ads', 'Microsoft Ads', 'LinkedIn Ads', 'Google Ads'] as $index => $platform) {
            $strategy = $this->strategy($index + 1);
            $strategy->platform = $platform;
            $strategy->campaign_type = 'display';
            $strategies[] = $strategy;
        }
        $campaign->setRelation('strategies', new Collection($strategies));
        $planner = $this->createMock(InitialSearchBidPlanner::class);
        $planner->expects($this->never())->method('estimate');
        $service = $this->service($planner);

        $result = $service->research($campaign);

        $this->assertCount(4, $service->businessCalls);
        $this->assertCount(4, $result['strategies']);
        $this->assertSame('needs_review', $result['status']);
        foreach ($result['strategies'] as $row) {
            $this->assertSame('needs_review', $row['status']);
            $this->assertSame('platform_cost_and_reach_unverified', $row['evidence']['coverage']);
            $this->assertNull($row['forecast']);
        }
    }

    public function test_missing_customer_account_still_attempts_business_research_but_never_fabricates_forecast_identity(): void
    {
        $campaign = $this->campaign();
        $campaign->customer->google_ads_customer_id = null;
        $planner = $this->createMock(InitialSearchBidPlanner::class);
        $planner->expects($this->never())->method('estimate');
        $service = $this->service($planner);

        $result = $service->research($campaign);

        $this->assertCount(1, $service->businessCalls);
        $this->assertSame('needs_review', $result['status']);
        $this->assertSame([], $result['strategies'][0]['evidence']['account']);
        $this->assertStringContainsString('real customer Google Ads account', implode(' ', $result['strategies'][0]['reasons']));
    }

    public function test_existing_footprint_can_receive_recommendations_but_cannot_be_adopted_as_a_new_draft(): void
    {
        $campaign = $this->campaign();
        $campaign->strategies[0]->google_ads_campaign_id = 'customers/1234567890/campaigns/123';
        $result = $this->service($this->planner(fn ($keywords, $budget) => $this->estimate(true, $budget)))->research($campaign);

        $this->assertSame('needs_review', $result['status']);
        $this->assertSame('ready', $result['strategies'][0]['proposal_status']);
        $this->assertTrue($result['strategies'][0]['requires_live_review']);
        $this->assertSame('customers/1234567890/campaigns/123', $campaign->strategies[0]->google_ads_campaign_id);
    }

    public function test_failed_forecasts_recover_once_even_with_several_demand_backed_terms_while_preserving_caps_and_existing_objects(): void
    {
        foreach ([false, true] as $footprint) {
            $campaign = $this->campaign();
            $campaign->strategies[0]->bidding_strategy = ['keywords' => $this->selected(), 'parameters' => ['cpcBidCeilingMicros' => 250_000]];
            if ($footprint) {
                $campaign->strategies[0]->google_ads_campaign_id = 'customers/1234567890/campaigns/123';
            }
            $before = $campaign->strategies[0]->getAttributes();
            $seen = [];
            $planner = $this->planner(function ($keywords, $budget, $cap) use (&$seen) {
                $seen[] = [count($keywords), $keywords[0]['text'], $budget, $cap];

                return $this->estimate($keywords[0]['text'] === 'ordinary core service', $budget);
            });
            $service = $this->service($planner, true);
            $service->recoverCategories = true;
            $service->alternativeKeywords = array_map(fn ($id) => ['text' => 'narrow niche '.$id, 'match_type' => 'PHRASE',
                'selection_reason' => 'Relevant but narrow offer term'], [1, 2, 3]);
            $result = $service->research($campaign);
            $row = $result['strategies'][0];
            $this->assertSame(1, $service->recoveryCalls);
            $this->assertSame([250_000], array_values(array_unique(array_column($seen, 3))));
            $this->assertContains(3, array_column($seen, 0));
            $this->assertSame('ordinary core service', $row['proposed_keywords'][0]['text']);
            $this->assertSame(5_000_000, $row['budget_micros']);
            $this->assertSame($footprint ? 'needs_review' : 'ready', $result['status']);
            if ($footprint) {
                $this->assertSame('ready', $row['proposal_status']);
                $this->assertTrue($row['requires_live_review']);
            }
            $this->assertSame($before, $campaign->strategies[0]->getAttributes());
            $this->assertCount(3, $row['evidence']['tested_sets']);
        }
    }

    public function test_category_recovery_is_bounded_when_its_forecasts_also_fail(): void
    {
        $service = $this->service($this->planner(fn ($keywords, $budget) => $this->estimate(false, $budget)), true);
        $service->recoverCategories = true;
        $result = $service->research($this->campaign());
        $this->assertSame(1, $service->recoveryCalls);
        $this->assertSame('needs_review', $result['status']);
        $this->assertFalse($result['activation_ready']);
    }

    public function test_category_terms_compete_at_current_budget_before_a_higher_budget_recommendation_and_do_not_lose_valid_fallbacks(): void
    {
        foreach ([false, true] as $recoveryWorks) {
            $seen = [];
            $service = $this->service($this->planner(function ($keywords, $budget) use (&$seen, $recoveryWorks) {
                $recovered = $keywords[0]['text'] === 'ordinary core service';
                $seen[] = [$recovered, $budget];

                return $this->estimate($recovered ? $recoveryWorks : $budget >= 8_000_000, $budget);
            }), true);
            $service->recoverCategories = true;
            $result = $service->research($this->campaign());
            $this->assertSame('ready', $result['status']);
            $this->assertSame(1, $service->recoveryCalls);
            $this->assertSame([true, 5_000_000], $seen[2]);
            $this->assertSame($recoveryWorks ? 5_000_000 : 10_000_000, $result['proposed_daily_budget_micros']);
            $this->assertSame($recoveryWorks ? 'ordinary core service' : 'expensive original', $result['strategies'][0]['proposed_keywords'][0]['text']);
        }
    }

    public function test_each_budget_option_researches_its_own_bid_within_owner_limits_without_scaling_forecasts(): void
    {
        $campaign = $this->campaign();
        $campaign->strategies[0]->bidding_strategy = ['keywords' => $this->selected(), 'parameters' => ['cpcBidCeilingMicros' => 2_000_000]];
        $calls = [];
        $planner = $this->planner(function ($keywords, $budget, $cap) use (&$calls) {
            $calls[] = [$budget, $cap, $keywords];
            $result = $this->estimate(true, $budget);
            $result['cpc_bid_micros'] = intdiv($budget, 10);
            $result['evidence']['forecast']['clicks'] = match ($budget) {
                5_000_000 => 40, 10_000_000 => 98, default => 100,
            };

            return $result;
        });
        $result = $this->service($planner)->research($campaign);
        $options = $result['budget_options'];
        $this->assertSame([5_000_000, 10_000_000, 15_000_000], array_column($options, 'daily_budget_micros'));
        $this->assertSame([40, 98, 100], array_column(array_column($options, 'forecast'), 'clicks'));
        $this->assertSame([500_000, 1_000_000, 1_500_000], array_column($options, 'cpc_bid_micros'));
        $this->assertSame([2_000_000], array_values(array_unique(array_column($calls, 1))));
        $this->assertSame($options[0]['id'], $result['default_budget_option_id']);
        $this->assertSame($options[0]['id'], $result['selected_budget_option_id']);
        $this->assertCount(3, $calls);
        foreach ($options as $option) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $option['id']);
            $this->assertSame(hash('sha256', json_encode($option['proposal'], JSON_THROW_ON_ERROR)), $option['id']);
            $this->assertSame($option['daily_budget_micros'], $option['proposal']['strategies'][0]['budget_micros']);
            $this->assertSame($option['cpc_bid_micros'], $option['proposal']['strategies'][0]['cpc_bid_micros']);
            $this->assertSame($options[0]['proposal']['strategies'][0]['proposed_keywords'], $option['proposal']['strategies'][0]['proposed_keywords']);
            $this->assertArrayNotHasKey('budget_options', $option['proposal']);
        }
        $this->assertEquals('5.00', $campaign->daily_budget);
    }

    public function test_custom_reference_is_included_with_comparisons_and_existing_campaign_options_remain_review_only(): void
    {
        foreach ([false, true] as $footprint) {
            $campaign = $this->campaign();
            if ($footprint) {
                $campaign->strategies[0]->google_ads_campaign_id = 'customers/1234567890/campaigns/123';
            }
            $planner = $this->planner(fn ($keywords, $budget) => $this->estimate(true, $budget));
            $planner->expects($this->never())->method('forecastAtBid');
            $result = $this->service($planner)->research($campaign, 5_000_000);
            $this->assertCount(3, $result['budget_options']);
            $this->assertSame(5_000_000, $result['budget_options'][0]['daily_budget_micros']);
            $this->assertSame($footprint ? 'needs_review' : 'ready', $result['budget_options'][0]['proposal']['status']);
            $this->assertSame($footprint ? 'needs_review' : 'ready', $result['status']);
        }
    }

    public function test_unviable_options_are_omitted_and_multi_strategy_forecasts_are_not_combined(): void
    {
        foreach ([false, true] as $otherStrategy) {
            $campaign = $this->campaign();
            if ($otherStrategy) {
                $campaign->strategies->push($this->strategy(20));
            }
            $planner = $this->planner(function ($keywords, $budget) {
                $result = $this->estimate($budget !== 10_000_000, $budget);
                $result['cpc_bid_micros'] = 600_000;

                return $result;
            });
            $result = $this->service($planner)->research($campaign);
            $this->assertCount($otherStrategy ? 0 : 2, $result['budget_options']);
        }
    }

    public function test_a_dominant_keyword_forecast_is_not_discarded_because_the_original_selection_came_first(): void
    {
        $planner = $this->planner(function ($keywords, $budget) {
            $result = $this->estimate(true, $budget);
            $alternative = $keywords[0]['text'] === 'relevant alternative';
            $result['evidence']['forecast']['clicks'] = $alternative ? 70 : 40;
            $result['evidence']['forecast']['cost_micros'] = $alternative ? 1_000_000 : 2_000_000;

            return $result;
        });
        $result = $this->service($planner, true)->research($this->campaign());
        $this->assertSame('relevant alternative', $result['strategies'][0]['proposed_keywords'][0]['text']);
        $this->assertSame(70, $result['strategies'][0]['forecast']['clicks']);
    }

    public function test_category_and_specific_keyword_profiles_are_both_forecast_before_selecting_a_plan(): void
    {
        $campaign = $this->campaign();
        $campaign->keywords = [[...$this->selected()[0], 'cpc_bid_micros' => 250_000]];
        $caps = [];
        $service = $this->service($this->planner(function ($keywords, $budget, $cap) use (&$caps) {
            $caps[] = $cap;
            $estimate = $this->estimate(true, $budget);
            $estimate['cpc_bid_micros'] = 200_000;
            $estimate['evidence']['forecast']['clicks'] = match ($keywords[0]['text']) {
                'ordinary category term' => 70, 'specific buyer term' => 90, default => 40,
            };

            return $estimate;
        }), true);
        $service->keywordSets = [
            ['kind' => 'category', 'keywords' => [['text' => 'ordinary category term', 'match_type' => 'PHRASE', 'selection_reason' => 'Matches the actual commercial category']]],
            ['kind' => 'specific', 'keywords' => [['text' => 'specific buyer term', 'match_type' => 'EXACT', 'selection_reason' => 'Matches the specific offer and buyer']]],
        ];
        $result = $service->research($campaign, 5_000_000);
        $this->assertSame(['selected', 'combined', 'category', 'specific'], array_column($result['strategies'][0]['evidence']['tested_sets'], 'kind'));
        $this->assertSame(array_fill(0, 6, 250_000), $caps);
        $this->assertSame('specific buyer term', $result['strategies'][0]['proposed_keywords'][0]['text']);
        $this->assertSame(250_000, $result['strategies'][0]['proposed_keywords'][0]['cpc_bid_micros']);
        $this->assertSame(90, $result['strategies'][0]['forecast']['clicks']);
    }

    public function test_explicit_two_pass_keyword_profiles_never_trigger_a_third_discovery_recovery(): void
    {
        $service = $this->service($this->planner(fn ($keywords, $budget) => $this->estimate(false, $budget)), true);
        $service->recoverCategories = true;
        $service->keywordSets = [['kind' => 'category', 'keywords' => []], ['kind' => 'specific', 'keywords' => []]];
        $result = $service->research($this->campaign(), 5_000_000);
        $this->assertSame('needs_review', $result['status']);
        $this->assertSame(0, $service->recoveryCalls);
        $this->assertCount(2, $result['strategies'][0]['evidence']['tested_sets']);
    }

    public function test_lower_cost_relevant_profile_beats_more_expensive_traffic_without_relaxing_a_keyword_cap(): void
    {
        $campaign = $this->campaign();
        $campaign->keywords = [[...$this->selected()[0], 'cpc_bid_micros' => 4_000_000]];
        $calls = [];
        $service = $this->service($this->planner(function ($keywords, $budget, $cap) use (&$calls) {
            $calls[] = [$keywords[0]['text'], $cap];
            $estimate = $this->estimate(true, $budget);
            $economic = $keywords[0]['text'] === 'relevant setup service';
            $estimate['cpc_bid_micros'] = $economic ? 2_000_000 : 4_000_000;
            $estimate['evidence']['forecast']['clicks'] = $economic ? 35 : 90;
            $estimate['evidence']['forecast']['cost_micros'] = $economic ? 35_000_000 : 135_000_000;

            return $estimate;
        }), true);
        $service->keywordSets = [
            ['kind' => 'category', 'keywords' => [['text' => 'ordinary category term', 'match_type' => 'PHRASE', 'selection_reason' => 'Commercial category match']]],
            ['kind' => 'specific', 'keywords' => [['text' => 'specific buyer term', 'match_type' => 'EXACT', 'selection_reason' => 'Buyer intent match']]],
            ['kind' => 'cost_efficient', 'keywords' => [['text' => 'relevant setup service', 'match_type' => 'PHRASE', 'selection_reason' => 'Relevant service with measured demand and lower historical cost']]],
        ];
        $result = $service->research($campaign, 5_000_000);
        $row = $result['strategies'][0];
        $this->assertSame(['selected', 'combined', 'category', 'specific', 'cost_efficient'], array_column($row['evidence']['tested_sets'], 'kind'));
        $this->assertCount(7, $calls);
        $this->assertSame([4_000_000], array_values(array_unique(array_column($calls, 1))));
        $this->assertSame('relevant setup service', $row['proposed_keywords'][0]['text']);
        $this->assertSame(4_000_000, $row['proposed_keywords'][0]['cpc_bid_micros']);
        $this->assertSame(35, $row['forecast']['clicks']);
        $this->assertSame('cost_efficient_reach', $row['evidence']['selection_policy']);
        $this->assertSame('5.00', $campaign->daily_budget);
    }

    public function test_fingerprint_binds_business_account_dates_caps_keywords_and_full_targeting_but_approval_survives_lifecycle_changes(): void
    {
        $campaign = $this->campaign();
        $service = new CampaignMarketResearch;
        $original = $service->fingerprint($campaign);
        $approval = $service->approvalFingerprint($campaign);
        $campaign->search_delivery_state = ['market_research' => ['status' => 'researching']];
        $this->assertSame($original, $service->fingerprint($campaign));
        $campaign->strategies[0]->signed_off_at = now();
        $campaign->strategies[0]->deployment_status = 'deployed';
        $campaign->strategies[0]->google_ads_campaign_id = 'customers/1234567890/campaigns/123';
        $this->assertNotSame($original, $service->fingerprint($campaign));
        $this->assertSame($approval, $service->approvalFingerprint($campaign));

        foreach (['account', 'timezone', 'geo', 'cap', 'date', 'brand'] as $field) {
            $campaign = $this->campaign();
            $before = $service->approvalFingerprint($campaign);
            match ($field) {
                'account' => $campaign->customer->setRawAttributes([...$campaign->customer->getAttributes(), 'google_ads_customer_id' => '9876543210']),
                'timezone' => $campaign->customer->timezone = 'America/New_York',
                'geo' => $campaign->strategies[0]->targetingConfig->google_options = ['excluded_locations' => ['2840']],
                'cap' => $campaign->strategies[0]->bidding_strategy = ['parameters' => ['cpcBidCeilingMicros' => 100_000]],
                'date' => $campaign->setAttribute('end_date', '2027-01-01'),
                'brand' => $campaign->customer->brandGuideline->service_lines = ['different offer'],
            };
            $this->assertNotSame($before, $service->approvalFingerprint($campaign), $field);
        }
    }

    public function test_optimizer_version_invalidates_legacy_quotes_without_changing_existing_approval_hashes(): void
    {
        $campaign = $this->campaign();
        $research = new CampaignMarketResearch;
        $input = new \ReflectionMethod(CampaignMarketResearch::class, 'inputFingerprint');
        $oldInputs = $input->invoke($research, $campaign, true);
        foreach ([null, 50_000_000] as $requestedBudget) {
            $legacy = hash('sha256', json_encode([$oldInputs, $requestedBudget], JSON_THROW_ON_ERROR));
            $previousMaxClicksQuote = hash('sha256', json_encode([2, $oldInputs, $requestedBudget], JSON_THROW_ON_ERROR));
            $current = $research->fingerprint($campaign, $requestedBudget);
            $this->assertNotSame($legacy, $current);
            $this->assertNotSame($previousMaxClicksQuote, $current);
            $campaign->search_delivery_state = ['market_research' => ['input_fingerprint' => $current, 'status' => 'ready']];
            $this->assertSame($current, $research->fingerprint($campaign, $requestedBudget));
        }
        $this->assertSame($input->invoke($research, $campaign, false), $research->approvalFingerprint($campaign));
    }

    public function test_draft_inputs_share_executor_precedence_and_refuse_partial_or_unknown_markets(): void
    {
        $campaign = $this->campaign();
        $campaign->keywords = [['text' => 'owner phrase', 'match_type' => 'EXACT']];
        $inputs = (new SearchMarketInputs($campaign->customer))->forStrategy($campaign, $campaign->strategies[0]);
        $this->assertSame('owner phrase', $inputs['keywords'][0]['text']);
        $this->assertSame(['geoTargetConstants/2036'], $inputs['geo_target_constants']);

        $campaign->strategies[0]->targetingConfig->geo_locations = ['Australia', 'Not a real location'];
        $this->expectException(\RuntimeException::class);
        (new SearchMarketInputs($campaign->customer))->forStrategy($campaign, $campaign->strategies[0]);
    }

    public function test_custom_budget_is_a_hypothetical_reference_that_does_not_save_or_approve_any_comparison(): void
    {
        $campaign = $this->campaign();
        $seen = [];
        $service = $this->service($this->planner(function ($keywords, $budget) use (&$seen) {
            $seen[] = $budget;

            return $this->estimate(true, $budget);
        }));

        $result = $service->research($campaign, 20_000_000);

        $this->assertSame('ready', $result['status']);
        $this->assertSame([20_000_000, 10_000_000, 40_000_000], $seen);
        $this->assertSame(20_000_000, collect($result['budget_options'])->firstWhere('id', $result['default_budget_option_id'])['daily_budget_micros']);
        $this->assertSame(20_000_000, $result['proposed_daily_budget_micros']);
        $this->assertSame('5.00', $campaign->daily_budget);
        $this->assertSame('5.00', $campaign->strategies[0]->daily_budget);
        $this->assertSame('MANUAL_CPC', $result['strategies'][0]['bidding_strategy']);
        $this->assertNotSame($service->fingerprint($campaign, 5_000_000), $service->fingerprint($campaign, 20_000_000));
    }

    public function test_requested_projection_reserves_footprint_allocation_and_splits_only_unbuilt_remainder(): void
    {
        $campaign = $this->campaign();
        $campaign->strategies[0]->google_ads_campaign_id = 'customers/1234567890/campaigns/123';
        $campaign->strategies->push($this->strategy(2));
        $result = $this->service($this->planner(fn ($keywords, $budget) => $this->estimate(true, $budget)))->research($campaign, 15_000_000);

        $this->assertSame('ready', $result['status']);
        $this->assertSame([5_000_000, 10_000_000], array_column($result['strategies'], 'budget_micros'));
        $this->assertSame('preserved', $result['strategies'][0]['status']);
        $this->assertNull($result['strategies'][0]['forecast']);
        $this->assertNull($result['strategies'][0]['cpc_bid_micros']);
        $this->assertSame('unbuilt_strategies', $result['approval_scope']);
        $this->assertSame('5.00', $campaign->strategies[0]->daily_budget);
        $this->assertSame('5.00', $campaign->strategies[1]->daily_budget);
    }

    public function test_custom_amount_forecasts_a_single_existing_search_without_changing_its_approved_allocation(): void
    {
        foreach (['5.00', '75.00'] as $actualBudget) {
            $campaign = $this->campaign();
            $campaign->fill(['daily_budget' => $actualBudget, 'approved_daily_budget' => $actualBudget,
                'status' => \App\Enums\CampaignStatus::Paused]);
            $strategy = $campaign->strategies[0];
            $strategy->daily_budget = $actualBudget;
            $strategy->google_ads_campaign_id = 'customers/1234567890/campaigns/123';
            $before = [$campaign->getAttributes(), $strategy->getAttributes()];
            $seen = [];
            $planner = $this->planner(function ($keywords, $budget) use (&$seen) {
                $seen[] = $budget;

                return $this->estimate(true, $budget);
            });
            $planner->expects($this->never())->method('forecastAtBid');
            $result = $this->service($planner)->research($campaign, 50_000_000);
            $this->assertSame([50_000_000, 25_000_000, 100_000_000], $seen);
            $this->assertSame(50_000_000, $result['requested_daily_budget_micros']);
            $this->assertSame(50_000_000, $result['proposed_daily_budget_micros']);
            $this->assertSame('needs_review', $result['status']);
            $this->assertSame('ready', $result['strategies'][0]['proposal_status']);
            $this->assertTrue($result['strategies'][0]['requires_live_review']);
            $this->assertSame('existing_campaign_research_only', $result['approval_scope']);
            $this->assertCount(3, $result['budget_options']);
            $this->assertSame(50_000_000, collect($result['budget_options'])->firstWhere('id', $result['default_budget_option_id'])['daily_budget_micros']);
            $this->assertFalse($result['activation_ready']);
            $this->assertSame($before, [$campaign->getAttributes(), $strategy->getAttributes()]);
        }
    }

    public function test_custom_total_for_multiple_existing_placements_requires_allocation_review_without_combining_forecasts(): void
    {
        $campaign = $this->campaign();
        $campaign->strategies[0]->google_ads_campaign_id = 'customers/1234567890/campaigns/123';
        $second = $this->strategy(2);
        $second->google_ads_campaign_id = 'customers/1234567890/campaigns/124';
        $campaign->strategies->push($second);
        $planner = $this->createMock(InitialSearchBidPlanner::class);
        $planner->expects($this->never())->method('estimate');
        $planner->expects($this->never())->method('forecastAtBid');
        $result = $this->service($planner)->research($campaign, 50_000_000);
        $this->assertSame('needs_review', $result['status']);
        $this->assertSame([], $result['budget_options']);
        $this->assertSame([5_000_000, 5_000_000], array_column($result['strategies'], 'budget_micros'));
        $this->assertStringContainsString('per-placement allocations', implode(' ', $result['strategies'][0]['reasons']));
        $this->assertArrayNotHasKey('forecast', $result);
        $this->assertSame('5.00', $second->daily_budget);
    }

    public function test_aggregate_recommendations_outside_confirmation_range_are_never_ready(): void
    {
        foreach (['2.00', '10001.00'] as $budget) {
            $campaign = $this->campaign();
            $campaign->strategies[0]->daily_budget = $budget;
            $result = $this->service($this->planner(fn ($keywords, $amount) => $this->estimate(true, $amount)))->research($campaign);

            $this->assertSame('needs_review', $result['status']);
            $this->assertStringContainsString('supported approval range', implode(' ', $result['reasons']));
        }
    }

    public function test_new_alternatives_that_would_change_a_live_siblings_shared_keyword_scope_are_not_approvable(): void
    {
        $campaign = $this->campaign();
        $campaign->keywords = $this->selected();
        $campaign->strategies[0]->google_ads_campaign_id = 'customers/1234567890/campaigns/123';
        $campaign->strategies->push($this->strategy(2));
        $planner = $this->planner(fn ($keywords, $amount) => $this->estimate($keywords[0]['text'] === 'relevant alternative', $amount));

        $result = $this->service($planner, true)->research($campaign, 10_000_000);

        $this->assertSame('needs_review', $result['status']);
        $this->assertSame('preserved', $result['strategies'][0]['status']);
        $this->assertSame('ready', $result['strategies'][1]['proposal_status']);
        $this->assertSame('needs_review', $result['strategies'][1]['status']);
        $this->assertTrue($result['strategies'][1]['requires_shared_keyword_review']);
        $this->assertSame($this->selected(), $campaign->keywords);
    }

    private function campaign(): Campaign
    {
        $customer = (new Customer(['name' => 'Real offer', 'website' => 'https://example.test', 'business_type' => 'Agency',
            'description' => 'Paid search services', 'currency_code' => 'AUD', 'timezone' => 'Australia/Sydney']))
            ->setRelation('brandGuideline', new BrandGuideline(['service_lines' => ['Paid search services']]));
        $customer->setRawAttributes([...$customer->getAttributes(), 'google_ads_customer_id' => '1234567890']);
        $customer->id = 2;

        return (new Campaign(['customer_id' => 2, 'daily_budget' => '5.00', 'status' => 'draft',
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays(30)->toDateString()]))
            ->setRelation('customer', $customer)->setRelation('strategies', new Collection([$this->strategy(1)]));
    }

    private function strategy(int $id): Strategy
    {
        $strategy = new Strategy(['platform' => 'Google Ads', 'campaign_type' => 'search', 'daily_budget' => '5.00',
            'generation_id' => 'generation-one', 'bidding_strategy' => ['keywords' => $this->selected()]]);
        $strategy->id = $id;

        return $strategy->setRelation('targetingConfig', new TargetingConfig(['geo_locations' => ['Australia']]));
    }

    private function selected(): array
    {
        return [['text' => 'expensive original', 'match_type' => 'EXACT', 'selection_reason' => 'Matches the reviewed offer']];
    }

    private function estimate(bool $ready, int $budget): array
    {
        return ['ready' => $ready, 'cpc_bid_micros' => 500_000, 'caveats' => [], 'evidence' => [
            'historical' => ['keywords' => ['expensive original' => ['low_top_of_page_bid_micros' => 8_000_000, 'high_top_of_page_bid_micros' => 12_000_000]]],
            'forecast' => $ready ? ['success' => true, 'clicks' => 40, 'cost_micros' => $budget, 'period_days' => 30,
                'forecast_period' => ['from' => now()->addDay()->toDateString(), 'through' => now()->addDays(30)->toDateString()]] : null,
        ]];
    }

    private function planner(\Closure $callback): InitialSearchBidPlanner&\PHPUnit\Framework\MockObject\MockObject
    {
        $planner = $this->createMock(InitialSearchBidPlanner::class);
        $planner->method('estimate')->willReturnCallback(fn ($campaign, $keywords, $context, $budget, $cap) => $callback($keywords, $budget, $cap));

        return $planner;
    }

    private function service(InitialSearchBidPlanner $planner, bool $alternatives = false): CampaignMarketResearchFixture
    {
        return new CampaignMarketResearchFixture($planner, $alternatives);
    }
}

class CampaignMarketResearchFixture extends CampaignMarketResearch
{
    public array $businessCalls = [];

    public array $alternativeKeywords = [];

    public bool $recoverCategories = false;

    public int $recoveryCalls = 0;

    public ?array $keywordSets = null;

    public function __construct(private InitialSearchBidPlanner $planner, private bool $alternatives) {}

    protected function accountContext(Customer $customer): array
    {
        return ['customer_id' => $customer->cleanGoogleCustomerId(), 'currency_code' => 'AUD',
            'time_zone' => 'Australia/Sydney', 'account_time_zone_verified' => true];
    }

    protected function businessResearch(Campaign $campaign, Strategy $strategy, array $context, array $selected): array
    {
        $this->businessCalls[] = $strategy->id;

        return ['source' => 'google_keyword_planner_and_business_relevance',
            ...($this->keywordSets !== null ? ['keyword_sets' => $this->keywordSets] : []),
            ...($this->recoverCategories ? ['discovery' => ['category_recovery_attempted' => false]] : []),
            'keywords' => $this->alternatives
                ? ($this->alternativeKeywords ?: [['text' => 'relevant alternative', 'match_type' => 'PHRASE', 'selection_reason' => 'Same actual offer and buying intent']])
                : $selected];
    }

    protected function businessResearchRecovery(Campaign $campaign, Strategy $strategy, array $context, array $selected): array
    {
        $this->recoveryCalls++;

        return ['keywords' => [['text' => 'ordinary core service', 'match_type' => 'PHRASE', 'selection_reason' => 'Same reviewed service in ordinary buyer language']],
            'discovery' => ['category_recovery_attempted' => true, 'category_only' => true]];
    }

    protected function bidPlanner(): InitialSearchBidPlanner
    {
        return $this->planner;
    }
}
