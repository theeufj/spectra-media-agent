<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Services\Campaigns\CampaignSpendGuardrails;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V22\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;

/** Read-only decision: verified reach is required; unknown evidence never authorizes serving ads. */
class GoogleSearchReachGate
{
    /** Stable serving settings only; reporting lag and keyword eligibility are not configuration changes. */
    public static function settingsFingerprint(array $snapshot): string
    {
        $settings = array_intersect_key($snapshot, array_flip([
            'status', 'start_date', 'end_date', 'channel', 'bidding_strategy', 'portfolio_bidding_strategy', 'target_cpa_micros',
            'google_search_enabled', 'search_partners_enabled', 'display_enabled', 'positive_geo_target_type',
            'negative_geo_target_type', 'daily_budget_micros', 'actual_cpc_ceiling_micros', 'cpc_bid_ceiling_micros',
            'currency_code', 'time_zone', 'approved_ads', 'negative_keywords', 'geo_target_constants',
            'excluded_geo_target_constants', 'language_constants', 'ad_schedule', 'device_bid_modifiers',
            'unsupported_targeting', 'targeting_setting', 'ad_group_targeting_modes', 'audience_criteria',
        ]));
        $manual = in_array($snapshot['bidding_strategy'] ?? null, [BiddingStrategyType::MANUAL_CPC, 'MANUAL_CPC', 'ManualCpc'], true);
        $settings['keywords'] = array_map(function (array $keyword) use ($manual): array {
            $row = array_intersect_key($keyword, array_flip(['resource_name', 'resource', 'text', 'match_type',
                'status', 'ad_group_resource', 'ad_group_status']));
            if ($manual) {
                $row['cpc_bid_micros'] = $keyword['cpc_bid_micros'] ?? null;
            }

            return $row;
        }, $snapshot['keywords'] ?? []);
        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }
            $value = array_map($sort, $value);
            if (array_is_list($value)) {
                usort($value, fn ($left, $right) => strcmp(json_encode($left), json_encode($right)));
            } else {
                ksort($value);
            }

            return $value;
        };

        return hash('sha256', json_encode($sort($settings)));
    }

    public function evaluate(Campaign $campaign, array $snapshot, array $assessment, ?array $forecast = null): array
    {
        $forecast ??= $assessment['forecast'] ?? [];
        $issues = [];
        $unknown = false;
        $add = function (string $code, string $message, bool $unavailable = false) use (&$issues, &$unknown): void {
            $issues[] = (new AgentIssue($code, $message))->jsonSerialize();
            $unknown = $unknown || $unavailable;
        };
        $metadata = ['min_forecast_clicks_per_30_days' => max(1, (int) config('optimization.search_delivery.min_forecast_clicks', 30)),
            'currency_code' => $snapshot['currency_code'] ?? null,
            'daily_budget_micros' => $snapshot['daily_budget_micros'] ?? null,
            'cpc_bid_ceiling_micros' => $snapshot['actual_cpc_ceiling_micros'] ?? $snapshot['cpc_bid_ceiling_micros'] ?? null];
        if (($assessment['status'] ?? null) !== 'evidence_ready') {
            $add('search_reach_evidence_unavailable', 'Fresh keyword demand and reach evidence must be verified before serving ads are added.', true);
        }
        $manual = $this->strategy($snapshot['bidding_strategy'] ?? null) === 'MANUAL_CPC';
        $keywords = $this->keywords($snapshot['keywords'] ?? [], $manual);
        if ($keywords === []) {
            $add('search_reach_keywords_missing', 'No enabled Search keywords with verified match types are available.');
        }
        $historical = $assessment['historical'] ?? [];
        if (($historical['success'] ?? null) !== true || ! $this->recent($historical['checked_at'] ?? null, 26 * 60)) {
            $add('search_demand_unavailable', 'Fresh Google keyword demand estimates are required; missing estimates do not mean zero demand.', true);
        }
        $rows = [];
        foreach ($historical['keywords'] ?? [] as $key => $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach ([is_string($key) ? $key : null, $row['text'] ?? null] as $text) {
                if (is_string($text) && trim($text) !== '') {
                    $rows[mb_strtolower(trim($text))] = $row;
                }
            }
        }
        $knownDemand = [];
        $unknownDemand = [];
        foreach ($keywords as $keyword) {
            $text = $keyword['text'];
            $volume = $rows[$text]['avg_monthly_searches'] ?? null;
            if (! $this->number($volume)) {
                $unknownDemand[] = $text;
            } else {
                $knownDemand[$text] = $volume;
            }
        }
        $metadata['verified_keyword_demand'] = $knownDemand;
        $metadata['unknown_keyword_demand'] = array_values(array_unique($unknownDemand));
        if ($knownDemand === []) {
            $add('search_demand_unverified', 'Search demand for the selected keyword set is unknown. Verify demand before launching.', true);
        } elseif (max($knownDemand) <= 0) {
            $add('search_demand_not_demonstrated', 'The selected keyword set has no verified positive search demand. Review the keyword theme before launching.');
        }
        $budget = $snapshot['daily_budget_micros'] ?? null;
        $cap = $metadata['cpc_bid_ceiling_micros'];
        $approved = CampaignSpendGuardrails::moneyToMicros($campaign->approved_daily_budget);
        $metadata['approved_daily_budget_micros'] = $approved;
        if (! $campaign->budget_confirmed_at || $approved <= 0 || ! is_int($budget) || $budget <= 0) {
            $add('search_reach_approved_budget_unverified', 'A positive approved daily budget and its live Google setting must be verified.', true);
        } elseif ($budget > $approved) {
            $add('search_reach_approved_budget_exceeded', 'The live daily budget exceeds the customer-approved daily budget.');
        }
        if (! is_int($cap) || $cap <= 0) {
            $add('search_reach_cpc_limit_unverified', 'A positive live CPC bid limit must be verified for this bounded reach check.', true);
        }
        if ($manual && array_filter($keywords, fn ($keyword) => ! isset($keyword['cpc_bid_micros'])) !== []) {
            $add('search_reach_manual_keyword_bid_unverified', 'Each enabled Manual CPC keyword needs a verified effective bid; the aggregate ceiling cannot stand in for a missing bid.', true);
        }
        if ($campaign->spend_safety_hold) {
            $add('search_reach_spend_hold', 'A spending safety hold is active. It must be reviewed before serving ads are added.');
        }
        if (! empty($campaign->search_delivery_state['repair']['errors']) || ! empty($campaign->search_delivery_state['verification']['errors'])) {
            $add('search_reach_partial_repair_unresolved', 'A previous keyword repair has unresolved read-back errors. Review its live outcome before launching.');
        }
        foreach (['geo_target_constants', 'language_constants', 'negative_keywords', 'excluded_geo_target_constants',
            'ad_schedule', 'device_bid_modifiers', 'unsupported_targeting', 'search_partners_enabled'] as $field) {
            if (! array_key_exists($field, $snapshot)) {
                $add('search_reach_targeting_unverified', 'Live targeting and negative-keyword inspection is incomplete.', true);
                break;
            }
        }
        if (empty($snapshot['geo_target_constants']) || ! preg_match('/^[A-Z]{3}$/', (string) ($snapshot['currency_code'] ?? ''))
            || ! is_string($snapshot['time_zone'] ?? null) || $snapshot['time_zone'] === '') {
            $add('search_reach_account_context_unverified', 'Actual campaign markets, account currency and timezone must be verified.', true);
        }
        $devices = array_filter($snapshot['device_bid_modifiers'] ?? [],
            fn ($device) => ! isset($device['bid_modifier']) || (float) $device['bid_modifier'] !== 1.0);
        if (! empty($snapshot['unsupported_targeting']) || ! empty($snapshot['excluded_geo_target_constants'])
            || ! empty($snapshot['ad_schedule']) || $devices !== [] || ($snapshot['search_partners_enabled'] ?? false)) {
            $add('search_reach_restrictions_unmodeled', 'The forecast does not faithfully represent all live delivery restrictions. Review them before launching.', true);
        }
        $period = $forecast['period_days'] ?? null;
        $clicks = $forecast['clicks'] ?? null;
        $cost = $forecast['cost_micros'] ?? null;
        $forecastValid = ($forecast['success'] ?? null) === true && ($forecast['auto_repair_safe'] ?? null) === true
            && ($forecast['source'] ?? null) === 'google_keyword_forecast' && isset($forecast['caveats']) && $forecast['caveats'] === []
            && $this->recent($forecast['checked_at'] ?? null, 6 * 60)
            && is_int($period) && $period > 0 && $period <= 365
            && $this->number($clicks) && $this->number($forecast['impressions'] ?? null)
            && is_int($cost) && $cost >= 0 && $this->period($forecast['forecast_period'] ?? [], $period, $snapshot['time_zone'] ?? 'UTC');
        if (! $forecastValid) {
            $add('search_reach_forecast_unverified', 'A fresh, uncaveated Google traffic forecast with its period and actual spending settings is required.', true);
        }
        $context = $forecast['context'] ?? [];
        $matches = is_array($context) && ($context['daily_budget_micros'] ?? null) === $budget
            && ($context['cpc_bid_ceiling_micros'] ?? null) === $cap
            && ($context['currency_code'] ?? null) === ($snapshot['currency_code'] ?? null)
            && ($context['time_zone'] ?? null) === ($snapshot['time_zone'] ?? null)
            && $this->strategy($context['bidding_strategy'] ?? null) !== null
            && $this->strategy($context['bidding_strategy'] ?? null) === $this->strategy($snapshot['bidding_strategy'] ?? null)
            && $keywords !== [] && $this->keywords($context['keywords'] ?? [], $manual) === $keywords;
        foreach (['geo_target_constants', 'language_constants', 'negative_keywords'] as $field) {
            $matches = $matches && array_key_exists($field, $context) && array_key_exists($field, $snapshot)
                && $this->canonical($field === 'negative_keywords' ? $this->negatives($context[$field]) : $context[$field])
                    === $this->canonical($field === 'negative_keywords' ? $this->negatives($snapshot[$field]) : $snapshot[$field]);
        }
        if (! $matches) {
            $add('search_reach_forecast_context_mismatch', 'The forecast does not match the live keyword match types, bidding, budget, currency, markets, languages or negatives.', true);
        }
        if ($forecastValid) {
            $monthlyClicks = $clicks * 30 / $period;
            $metadata += ['forecast_period' => $forecast['forecast_period'], 'forecast_period_days' => $period,
                'forecast_clicks' => $clicks, 'forecast_clicks_per_30_days' => $monthlyClicks,
                'forecast_cost_micros' => $cost, 'forecast_note' => 'Forecast totals are estimates for the stated period, not guaranteed traffic or a hard billing cap.'];
            if ($forecast['impressions'] <= 0) {
                $add('search_reach_no_forecast_impressions', 'No impressions are forecast at the staged Search settings. Review the keyword set, bid and targeting before launching.');
            }
            if ($monthlyClicks < $metadata['min_forecast_clicks_per_30_days']) {
                $add('search_reach_below_useful_threshold', 'The keyword set is forecast to attract fewer than '.$metadata['min_forecast_clicks_per_30_days'].' clicks per 30 days at the current bid and targeting.');
            }
            if (is_int($budget) && $budget > 0 && $cost > $budget * $period) {
                $add('search_reach_forecast_budget_exceeded', 'Forecast ad spend exceeds the approved daily-budget envelope for the stated period.');
            }
            $endDate = $campaign->getAttributes()['end_date'] ?? null;
            if (is_string($endDate) && $endDate !== '' && $forecast['forecast_period']['through'] > substr($endDate, 0, 10)) {
                $add('search_reach_forecast_after_campaign_end', 'The forecast extends beyond the campaign’s approved end date. Assess the remaining campaign period.');
            }
            foreach (['start_date' => 'from', 'end_date' => 'through'] as $field => $boundary) {
                $liveDate = $snapshot[$field] ?? null;
                if (! is_string($liveDate) || $liveDate === '') {
                    continue;
                }
                $liveDate = preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', $liveDate) ?? '';
                if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $liveDate)) {
                    $add('search_reach_live_dates_unverified', 'Google’s campaign serving dates could not be verified.', true);
                } elseif (($field === 'start_date' && $forecast['forecast_period'][$boundary] < $liveDate)
                    || ($field === 'end_date' && $forecast['forecast_period'][$boundary] > $liveDate)) {
                    $add('search_reach_forecast_outside_live_dates', 'The forecast extends outside Google’s actual campaign serving dates. Review the remaining serving period.');
                }
            }
        }
        $trial = CampaignSpendGuardrails::activeTrial($campaign);
        if ($trial) {
            $total = $snapshot['reported_lifetime_cost_micros'] ?? null;
            $baseline = $trial['baseline_cost_micros'] ?? null;
            $stop = $trial['max_spend_micros'] ?? null;
            $daily = $trial['max_daily_budget_micros'] ?? null;
            $bid = $trial['max_cpc_bid_micros'] ?? null;
            if (! is_int($baseline) || $baseline < 0 || ! is_int($stop) || $stop <= 0
                || ! is_int($daily) || $daily <= 0 || ! is_int($bid) || $bid <= 0
                || ! is_int($total) || $total < $baseline || ! $this->recent($snapshot['reported_spend_checked_at'] ?? null, 30)) {
                $add('search_reach_trial_envelope_unverified', 'Fresh lifetime reported spend and the approved test limits are required to verify its remaining spending envelope.', true);
            } else {
                $remaining = max(0, $stop - ($total - $baseline));
                $metadata += ['trial_reported_spend_micros' => $total - $baseline, 'trial_remaining_reported_spend_micros' => $remaining,
                    'trial_max_daily_budget_micros' => $daily, 'trial_max_cpc_bid_micros' => $bid,
                    'trial_spend_note' => 'Google reports spend with a delay. The review stop threshold is not a hard billing cap.'];
                if (! is_int($budget) || $budget > $daily || ! is_int($cap) || $cap > $bid) {
                    $add('search_reach_trial_limit_exceeded', 'The staged bid or daily budget exceeds the approved test limits.');
                }
                if ($remaining <= 0 || ($forecastValid && $cost > $remaining)) {
                    $add('search_reach_trial_remaining_spend_exceeded', 'Forecast ad spend does not fit within the test’s remaining reported-spend review threshold.');
                }
                if ($this->strategy($snapshot['bidding_strategy'] ?? null) !== 'MAXIMIZE_CLICKS') {
                    $add('search_reach_trial_bidding_mismatch', 'The approved test requires the capped Maximize Clicks bidding strategy.');
                }
            }
        }

        return ['ready' => $issues === [], 'status' => $issues === [] ? 'ready' : ($unknown ? 'unavailable' : 'blocked'),
            'issues' => $issues, 'metadata' => $metadata];
    }

    private function recent(mixed $time, int $minutes): bool
    {
        if (! is_string($time) || trim($time) === '') {
            return false;
        }
        try {
            $checked = CarbonImmutable::parse($time);

            return $checked->gte(now()->subMinutes($minutes)) && $checked->lte(now()->addMinutes(5));
        } catch (\Throwable) {
            return false;
        }
    }

    private function period(array $period, int $days, string $timezone): bool
    {
        try {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $period['from'] ?? '', $timezone);
            $through = CarbonImmutable::createFromFormat('!Y-m-d', $period['through'] ?? '', $timezone);

            return $from !== null && $through !== null && $from->toDateString() === ($period['from'] ?? null)
                && $through->toDateString() === ($period['through'] ?? null) && $from->gte(CarbonImmutable::now($timezone)->startOfDay())
                && $through->toDateString() === $from->addDays($days - 1)->toDateString();
        } catch (\Throwable) {
            return false;
        }
    }

    private function number(mixed $value): bool
    {
        return (is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0;
    }

    private function strategy(mixed $strategy): ?string
    {
        return match ($strategy) {
            BiddingStrategyType::TARGET_SPEND, 'MAXIMIZE_CLICKS', 'MaximizeClicks', 'TARGET_SPEND' => 'MAXIMIZE_CLICKS',
            BiddingStrategyType::MANUAL_CPC, 'MANUAL_CPC', 'ManualCpc' => 'MANUAL_CPC',
            default => null,
        };
    }

    private function keywords(array $keywords, bool $manual = false): array
    {
        $result = [];
        foreach ($keywords as $keyword) {
            if (! is_array($keyword) || (isset($keyword['status']) && $keyword['status'] !== 'ENABLED')
                || (isset($keyword['ad_group_status']) && $keyword['ad_group_status'] !== 'ENABLED')) {
                continue;
            }
            $text = mb_strtolower(trim((string) ($keyword['text'] ?? '')));
            $match = strtoupper((string) ($keyword['match_type'] ?? ''));
            if ($text === '' || ! in_array($match, ['EXACT', 'PHRASE', 'BROAD'], true)) {
                continue;
            }
            $row = ['text' => $text, 'match_type' => $match, 'ad_group_resource' => $keyword['ad_group_resource'] ?? null];
            if ($manual && is_int($keyword['cpc_bid_micros'] ?? null) && $keyword['cpc_bid_micros'] > 0) {
                $row['cpc_bid_micros'] = $keyword['cpc_bid_micros'];
            }
            $result[] = $row;
        }

        return $this->canonical($result);
    }

    private function negatives(array $negatives): array
    {
        return array_map(fn ($negative) => ['text' => mb_strtolower(trim((string) ($negative['text'] ?? ''))),
            'match_type' => strtoupper((string) ($negative['match_type'] ?? '')),
            'scope' => $negative['scope'] ?? 'campaign', 'ad_group_resource' => $negative['ad_group_resource'] ?? null], $negatives);
    }

    private function canonical(array $values): array
    {
        usort($values, fn ($a, $b) => strcmp(json_encode($a, JSON_THROW_ON_ERROR), json_encode($b, JSON_THROW_ON_ERROR)));

        return $values;
    }
}
