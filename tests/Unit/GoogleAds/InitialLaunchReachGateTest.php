<?php

namespace Tests\Unit\GoogleAds;

use App\Models\Campaign;
use App\Services\Agents\GoogleSearchReachGate;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Tests\TestCase;

class InitialLaunchReachGateTest extends TestCase
{
    private const LIMITATION = 'Google’s forecast no longer supports negative keywords; live campaign or ad-group negatives are not modeled.';

    private function evidence(): array
    {
        $campaign = new Campaign(['approved_daily_budget' => '10.00', 'budget_confirmed_at' => now()]);
        $snapshot = ['status' => 3, 'bidding_strategy' => BiddingStrategyType::MANUAL_CPC,
            'start_date' => now('UTC')->toDateString(), 'daily_budget_micros' => 10_000_000,
            'actual_cpc_ceiling_micros' => 2_000_000, 'cpc_bid_ceiling_micros' => 2_000_000,
            'currency_code' => 'AUD', 'time_zone' => 'UTC', 'geo_target_constants' => ['geoTargetConstants/2036'],
            'language_constants' => [], 'excluded_geo_target_constants' => [], 'ad_schedule' => [],
            'device_bid_modifiers' => [], 'unsupported_targeting' => [], 'search_partners_enabled' => false,
            'negative_keywords' => [['text' => 'jobs', 'match_type' => 'PHRASE', 'scope' => 'campaign']],
            'keywords' => [['text' => 'google ads management', 'match_type' => 'PHRASE', 'status' => 'ENABLED',
                'ad_group_status' => 'ENABLED', 'ad_group_resource' => 'customers/1234567890/adGroups/42', 'cpc_bid_micros' => 2_000_000]]];
        $assessment = ['status' => 'evidence_ready', 'historical' => ['success' => true, 'checked_at' => now()->toIso8601String(),
            'keywords' => ['google ads management' => ['avg_monthly_searches' => 100]]],
            'forecast' => ['success' => true, 'auto_repair_safe' => false, 'source' => 'google_keyword_forecast',
                'checked_at' => now()->toIso8601String(), 'caveats' => [self::LIMITATION],
                'limitations' => [['code' => 'negative_keywords_unmodeled', 'message' => self::LIMITATION]],
                'period_days' => 6, 'clicks' => 8.15, 'cost_micros' => 42_000_000,
                'forecast_period' => ['from' => now('UTC')->addDay()->toDateString(), 'through' => now('UTC')->addDays(6)->toDateString()],
                'context' => $snapshot]];

        return [$campaign, $snapshot, $assessment];
    }

    public function test_initial_launch_can_use_a_disclosed_negative_only_estimate_without_authorizing_automatic_repairs(): void
    {
        [$campaign, $snapshot, $assessment] = $this->evidence();
        $gate = new GoogleSearchReachGate;
        $launch = $gate->evaluateInitialLaunch($campaign, $snapshot, $assessment);
        $this->assertTrue($launch['ready'], json_encode($launch));
        $this->assertSame('estimated_with_limitations', $launch['status']);
        $this->assertSame($assessment['forecast']['limitations'], $launch['metadata']['forecast_limitations']);
        $this->assertFalse($launch['metadata']['negative_keyword_effect_verified']);
        $this->assertContains('search_reach_negative_keywords_unmodeled', array_column($launch['warnings'], 'code'));
        $this->assertFalse($gate->evaluate($campaign, $snapshot, $assessment)['ready']);
        $this->assertFalse($assessment['forecast']['auto_repair_safe']);
        $this->assertSame([self::LIMITATION], $assessment['forecast']['caveats']);
    }

    public function test_initial_launch_still_rejects_unknown_caveats_stale_mismatched_and_unsafe_evidence(): void
    {
        foreach (['unmarked', 'unknown_code', 'extra_caveat', 'stale', 'budget', 'context', 'zero', 'targeting', 'trial', 'conflict', 'scope', 'group', 'unknown_match'] as $case) {
            [$campaign, $snapshot, $assessment] = $this->evidence();
            switch ($case) {
                case 'unmarked': $assessment['forecast']['limitations'] = [];
                    break;
                case 'unknown_code': $assessment['forecast']['limitations'][0]['code'] = 'unknown';
                    break;
                case 'extra_caveat': $assessment['forecast']['caveats'][] = 'Another restriction is not modeled.';
                    break;
                case 'stale': $assessment['forecast']['checked_at'] = now()->subDay()->toIso8601String();
                    break;
                case 'budget': $campaign->approved_daily_budget = '5.00';
                    break;
                case 'context': $assessment['forecast']['context']['cpc_bid_ceiling_micros'] = 3_000_000;
                    break;
                case 'zero': $assessment['forecast']['clicks'] = 0;
                    break;
                case 'targeting': $snapshot['unsupported_targeting'] = ['unknown audience'];
                    break;
                case 'trial': $campaign->spend_guardrails = ['enabled' => true, 'started_at' => now()->toIso8601String()];
                    break;
                case 'conflict': $snapshot['negative_keywords'][0]['text'] = 'google ads';
                    break;
                case 'scope': $snapshot['negative_keywords'][0]['scope'] = 'unknown';
                    break;
                case 'group': $snapshot['negative_keywords'][0] = ['text' => 'jobs', 'match_type' => 'PHRASE', 'scope' => 'ad_group', 'ad_group_resource' => 'customers/9999999999/adGroups/42'];
                    break;
                case 'unknown_match': $snapshot['negative_keywords'][0]['match_type'] = 'UNKNOWN';
                    break;
            }
            if (in_array($case, ['conflict', 'scope', 'group', 'unknown_match'], true)) {
                $assessment['forecast']['context']['negative_keywords'] = $snapshot['negative_keywords'];
            }
            $result = (new GoogleSearchReachGate)->evaluateInitialLaunch($campaign, $snapshot, $assessment);
            $this->assertFalse($result['ready'], $case.' must remain blocked');
            $this->assertNotEmpty($result['issues']);
        }
    }

    public function test_initial_launch_reports_positive_low_reach_as_a_warning_while_repairs_and_trials_remain_strict(): void
    {
        [$campaign, $snapshot, $assessment] = $this->evidence();
        $assessment['forecast']['clicks'] = 0.25;
        $assessment['forecast']['cost_micros'] = 1_000_000;
        $gate = new GoogleSearchReachGate;
        $result = $gate->evaluateInitialLaunch($campaign, $snapshot, $assessment);
        $this->assertTrue($result['ready'], json_encode($result));
        $this->assertSame('limited', $result['metadata']['traffic_status']);
        $this->assertFalse($result['metadata']['meets_traffic_threshold']);
        $this->assertContains('search_reach_below_useful_threshold', array_column($result['warnings'], 'code'));
        $this->assertNotContains('search_reach_below_useful_threshold', array_column($result['issues'], 'code'));
        $this->assertFalse($gate->evaluate($campaign, $snapshot, $assessment)['ready']);

        // Remove the negative limitation to isolate the controlled-trial threshold.
        $snapshot['negative_keywords'] = $assessment['forecast']['context']['negative_keywords'] = [];
        $assessment['forecast']['caveats'] = $assessment['forecast']['limitations'] = [];
        $assessment['forecast']['auto_repair_safe'] = true;
        $campaign->spend_guardrails = ['enabled' => true, 'started_at' => now()->toIso8601String()];
        $result = $gate->evaluateInitialLaunch($campaign, $snapshot, $assessment);
        $this->assertFalse($result['ready']);
        $this->assertContains('search_reach_below_useful_threshold', array_column($result['issues'], 'code'));
    }

    public function test_a_known_nonconflicting_ad_group_negative_can_use_the_initial_launch_estimate(): void
    {
        [$campaign, $snapshot, $assessment] = $this->evidence();
        $snapshot['negative_keywords'][0]['scope'] = 'ad_group';
        $snapshot['negative_keywords'][0]['ad_group_resource'] = 'customers/1234567890/adGroups/42';
        $assessment['forecast']['context']['negative_keywords'] = $snapshot['negative_keywords'];
        $this->assertTrue((new GoogleSearchReachGate)->evaluateInitialLaunch($campaign, $snapshot, $assessment)['ready']);
        $this->assertFalse((new GoogleSearchReachGate)->evaluate($campaign, $snapshot, $assessment)['ready']);
    }

    public function test_unknown_structured_limitations_cannot_hide_behind_a_safe_flag_and_empty_caveats(): void
    {
        [$campaign, $snapshot, $assessment] = $this->evidence();
        $assessment['forecast']['auto_repair_safe'] = true;
        $assessment['forecast']['caveats'] = [];
        $assessment['forecast']['limitations'] = [['code' => 'unknown', 'message' => 'Unsupported audience']];
        $gate = new GoogleSearchReachGate;
        $this->assertFalse($gate->evaluate($campaign, $snapshot, $assessment)['ready']);
        $this->assertFalse($gate->evaluateInitialLaunch($campaign, $snapshot, $assessment)['ready']);
    }
}
