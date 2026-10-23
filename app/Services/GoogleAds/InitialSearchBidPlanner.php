<?php

namespace App\Services\GoogleAds;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Agents\AgentIssue;
use App\Services\Agents\GoogleSearchReachPlanner;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordForecast;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordHistoricalMetrics;
use App\Services\GoogleAds\Support\CampaignDateTimes;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V26\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;

/** A fresh, read-only initial bid proposal; never an authorization to activate ads. */
class InitialSearchBidPlanner
{
    private const MAX_CANDIDATES = 8;

    public function plan(Campaign $campaign, array $snapshot): array
    {
        $evidence = ['checked_at' => now()->toIso8601String(), 'snapshot' => $snapshot,
            'input_fingerprint' => hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)),
            'historical' => null, 'candidates' => [], 'forecast' => null];
        $campaign = $campaign->exists ? $campaign->fresh() : $campaign;
        $customer = $campaign?->customer;
        if (! $campaign || ! $customer || ! ctype_digit($customer->cleanGoogleCustomerId())) {
            return $this->unavailable('The current Google customer account could not be verified.', $evidence);
        }
        if ($campaign->spend_safety_hold || CampaignSpendGuardrails::activeTrial($campaign)) {
            return $this->unavailable('A spending hold or controlled trial requires review before choosing an initial bid.', $evidence);
        }
        $customerId = $customer->cleanGoogleCustomerId();
        if (($snapshot['status'] ?? null) !== CampaignStatus::PAUSED
            || ($snapshot['channel'] ?? null) !== AdvertisingChannelType::SEARCH
            || ($snapshot['bidding_strategy'] ?? null) !== BiddingStrategyType::MANUAL_CPC
            || ! preg_match('#^customers/'.preg_quote($customerId, '#').'/campaigns/\d+$#', $snapshot['campaign_resource'] ?? '')) {
            return $this->unavailable('Initial bid estimates require this customer’s verified paused Manual CPC Search campaign.', $evidence);
        }
        $approved = CampaignSpendGuardrails::moneyToMicros($campaign->approved_daily_budget);
        $authorized = $snapshot['authorized_daily_budget_micros'] ?? null;
        $actual = $snapshot['daily_budget_micros'] ?? null;
        if (! $campaign->budget_confirmed_at || ! is_int($authorized) || $authorized <= 0 || $authorized > $approved
            || ! is_int($actual) || $actual <= 0) {
            return $this->unavailable('A confirmed daily budget and verified strategy allocation are required for bid estimates.', $evidence);
        }
        if (($snapshot['account_time_zone_verified'] ?? false) !== true
            || ! in_array($snapshot['time_zone'] ?? null, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)
            || ! preg_match('/^[A-Z]{3}$/', $snapshot['currency_code'] ?? '')
            || $snapshot['currency_code'] !== strtoupper((string) $customer->currency_code)) {
            return $this->unavailable('The Google account currency and timezone must match the approved spending context.', $evidence);
        }
        foreach (['geo_target_constants', 'excluded_geo_target_constants', 'language_constants', 'negative_keywords',
            'ad_schedule', 'device_bid_modifiers', 'unsupported_targeting'] as $key) {
            if (! isset($snapshot[$key]) || ! is_array($snapshot[$key])) {
                return $this->unavailable('A complete live targeting and keyword snapshot is required.', $evidence);
            }
        }
        if ($snapshot['geo_target_constants'] === [] || ! array_key_exists('search_partners_enabled', $snapshot)) {
            return $this->unavailable('The campaign’s actual locations and networks must be known before estimating bids.', $evidence);
        }
        if (! isset($snapshot['keywords']) || ! is_array($snapshot['keywords'])) {
            return $this->unavailable('A complete selected keyword snapshot is required.', $evidence);
        }
        $keywords = array_values(array_filter($snapshot['keywords'], fn ($keyword) => is_array($keyword) && ($keyword['status'] ?? null) === 'ENABLED'
            && ($keyword['ad_group_status'] ?? null) === 'ENABLED'));
        if ($keywords === [] || count($keywords) > 100) {
            return $this->unavailable('Between one and 100 active selected keywords are required for complete initial cost estimates.', $evidence);
        }
        $budget = min($actual, $authorized);
        $cap = $budget;
        if (($snapshot['approved_cpc_ceiling_micros'] ?? null) !== null) {
            if (! is_int($snapshot['approved_cpc_ceiling_micros']) || $snapshot['approved_cpc_ceiling_micros'] <= 0) {
                return $this->unavailable('The existing approved CPC limit could not be verified.', $evidence);
            }
            $cap = min($cap, $snapshot['approved_cpc_ceiling_micros']);
        }
        foreach ($keywords as $keyword) {
            if (! is_string($keyword['text'] ?? null) || trim($keyword['text']) === ''
                || ! in_array($keyword['match_type'] ?? null, ['EXACT', 'PHRASE', 'BROAD'], true)
                || ! preg_match('#^customers/'.preg_quote($customerId, '#').'/adGroups/\d+$#', $keyword['ad_group_resource'] ?? '')
                || ! is_int($keyword['keyword_cpc_bid_micros'] ?? null) || $keyword['keyword_cpc_bid_micros'] < 0) {
                return $this->unavailable('Selected keyword match types, ownership or explicit bid limits are unverified.', $evidence);
            }
            if ($keyword['keyword_cpc_bid_micros'] > 0) {
                $cap = min($cap, $keyword['keyword_cpc_bid_micros']);
            }
        }
        // Google accepts whole currency cents here. Round limits down, never up.
        $cap = intdiv($cap, 10_000) * 10_000;
        $evidence += ['authorized_daily_budget_micros' => $authorized, 'actual_daily_budget_micros' => $actual,
            'forecast_daily_budget_micros' => $budget, 'max_cpc_bid_micros' => $cap];
        if ($cap <= 0) {
            return $this->unavailable('The approved spending limits do not permit a positive initial CPC bid.', $evidence);
        }

        return $this->estimateFor($campaign, $customer, $keywords, $snapshot, $budget, $cap, $evidence);
    }

    /** Read-only pre-approval proposal: real account context, no platform resources or spend authorization. */
    public function estimate(Campaign $campaign, array $keywords, array $context, int $dailyBudgetMicros, ?int $cpcLimitMicros = null): array
    {
        return $this->estimateProposal($campaign, $keywords, $context, $dailyBudgetMicros, $cpcLimitMicros);
    }

    /** Compare budgets at the already researched bid, never choose a different bid. */
    public function forecastAtBid(Campaign $campaign, array $keywords, array $context, int $dailyBudgetMicros, int $bidMicros, ?int $cpcLimitMicros = null): array
    {
        return $this->estimateProposal($campaign, $keywords, $context, $dailyBudgetMicros, $cpcLimitMicros ?? $bidMicros, $bidMicros);
    }

    private function estimateProposal(Campaign $campaign, array $keywords, array $context, int $dailyBudgetMicros, ?int $cpcLimitMicros, ?int $exactBidMicros = null): array
    {
        $evidence = ['checked_at' => now()->toIso8601String(), 'proposal_only' => true,
            'snapshot' => $context, 'keywords' => $keywords, 'historical' => null, 'candidates' => [], 'forecast' => null,
            'input_fingerprint' => hash('sha256', json_encode([$keywords, $context, $dailyBudgetMicros, $cpcLimitMicros], JSON_THROW_ON_ERROR))];
        $customer = $campaign->customer;
        if (! $customer || ! ctype_digit($customer->cleanGoogleCustomerId()) || $dailyBudgetMicros <= 0
            || $dailyBudgetMicros % 10_000 !== 0 || ($cpcLimitMicros !== null && $cpcLimitMicros <= 0)
            || ($context['customer_id'] ?? null) !== $customer->cleanGoogleCustomerId()
            || ($context['account_time_zone_verified'] ?? false) !== true
            || ! in_array($context['time_zone'] ?? null, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)
            || ($context['currency_code'] ?? null) !== strtoupper((string) $customer->currency_code)
            || empty($context['geo_target_constants']) || ! is_array($context['language_constants'] ?? null)
            || ! is_array($context['negative_keywords'] ?? null) || $keywords === [] || count($keywords) > 100) {
            return $this->unavailable('Real account currency, timezone, target markets, selected keywords and a positive proposed budget are required.', $evidence);
        }
        foreach ($keywords as $keyword) {
            if (! is_array($keyword) || ! is_string($keyword['text'] ?? null) || trim($keyword['text']) === ''
                || ! in_array($keyword['match_type'] ?? null, ['EXACT', 'PHRASE', 'BROAD'], true)) {
                return $this->unavailable('The proposed keyword set has an unknown match type or empty keyword.', $evidence);
            }
            $limit = $keyword['cpc_bid_micros'] ?? null;
            if ($limit !== null && $limit !== 0 && $limit !== '0') {
                if ((! is_int($limit) && (! is_string($limit) || ! preg_match('/^\d{1,16}$/', $limit))) || (int) $limit <= 0) {
                    return $this->unavailable('An existing keyword CPC limit is invalid and cannot be discarded by research.', $evidence);
                }
                $cpcLimitMicros = min($cpcLimitMicros ?? (int) $limit, (int) $limit);
            }
        }
        $cap = intdiv(min($dailyBudgetMicros, $cpcLimitMicros ?? $dailyBudgetMicros), 10_000) * 10_000;
        if ($cap <= 0) {
            return $this->unavailable('The retained CPC limit does not permit a positive whole-cent proposal.', $evidence);
        }
        if ($exactBidMicros !== null && ($exactBidMicros <= 0 || $exactBidMicros % 10_000 !== 0 || $exactBidMicros > $cap)) {
            return $this->unavailable('The selected bid must fit the retained spending limits exactly.', $evidence);
        }
        $evidence += ['forecast_daily_budget_micros' => $dailyBudgetMicros, 'max_cpc_bid_micros' => $cap];
        $context['research_max_bid_candidates'] = 4;

        return $this->estimateFor($campaign, $customer, $keywords, $context, $dailyBudgetMicros, $cap, $evidence, $exactBidMicros);
    }

    private function estimateFor(Campaign $campaign, Customer $customer, array $keywords, array $snapshot, int $budget, int $cap, array $evidence, ?int $exactBidMicros = null): array
    {
        try {
            $context = $this->context($campaign, $snapshot, $budget);
            if ($context['forecast_period_days'] < 1) {
                return $this->unavailable('No complete future serving day remains within the approved campaign dates.', $evidence);
            }
            // Deliberately bypass diagnostic caches: these observations precede an initial write.
            $historical = ($this->historicalProvider($customer))($customer->cleanGoogleCustomerId(), array_column($keywords, 'text'),
                $context['geo_target_constants'], count($context['language_constants']) === 1 ? $context['language_constants'][0] : null);
            $evidence['historical'] = $historical;
            if (! ($historical['success'] ?? false)) {
                return $this->unavailable('Fresh keyword cost estimates are unavailable; no fallback bid was chosen.', $evidence);
            }
            $bids = [];
            $unknownCosts = [];
            foreach ($keywords as $keyword) {
                $metric = GoogleSearchReachPlanner::historicalMetric($historical['keywords'] ?? [], $keyword['text']);
                $costs = array_filter([$metric['low_top_of_page_bid_micros'] ?? null, $metric['high_top_of_page_bid_micros'] ?? null],
                    fn ($value) => is_int($value) && $value > 0);
                if ($costs === []) {
                    $unknownCosts[] = $keyword['text'];
                }
                foreach ($costs as $cost) {
                    $bids[] = min($cap, intdiv(min($cost, $cap) + 9_999, 10_000) * 10_000);
                }
            }
            $bids = array_values(array_unique($bids));
            sort($bids, SORT_NUMERIC);
            if ($bids === []) {
                return $this->unavailable('Google supplied no positive keyword cost estimates; the initial bid needs review.', $evidence);
            }
            if ($exactBidMicros !== null) {
                $bids = [$exactBidMicros];
            }
            $limit = max(2, min(self::MAX_CANDIDATES, (int) ($snapshot['research_max_bid_candidates'] ?? self::MAX_CANDIDATES)));
            if (count($bids) > $limit) {
                $all = $bids;
                $bids = [];
                for ($index = 0; $index < $limit; $index++) {
                    $bids[] = $all[intdiv($index * (count($all) - 1), $limit - 1)];
                }
            }
            $evidence['keywords_with_unknown_costs'] = $unknownCosts;
            $minimumClicks = max(1, (int) config('optimization.search_delivery.min_forecast_clicks', 30));
            foreach ($bids as $bid) {
                $proposed = array_map(fn ($keyword) => array_replace($keyword, ['cpc_bid_micros' => $bid]), $keywords);
                $candidateContext = $context + ['cpc_bid_ceiling_micros' => $bid];
                // The existing provider takes currency units; exact money limits remain integer micros in context.
                $forecast = ($this->forecastProvider($customer))($customer->cleanGoogleCustomerId(), $proposed, $bid / 1_000_000, null,
                    $context['forecast_period_days'], $candidateContext);
                $clicks = $forecast['clicks'] ?? null;
                $cost = $forecast['cost_micros'] ?? null;
                $credible = ($forecast['success'] ?? false) && is_numeric($clicks) && is_finite((float) $clicks)
                    && $clicks >= 1 && $clicks * 30 >= $minimumClicks * $context['forecast_period_days']
                    && is_int($cost) && $cost > 0 && $cost <= $budget * $context['forecast_period_days']
                    && $this->matchesForecast($forecast, $proposed, $candidateContext);
                $evidence['candidates'][] = ['cpc_bid_micros' => $bid, 'credible_reach' => $credible, 'forecast' => $forecast];
            }
            $chosen = self::bestForecast(array_values(array_filter($evidence['candidates'], fn ($candidate) => $candidate['credible_reach'])));
            if ($chosen !== null) {
                $forecast = $chosen['forecast'];
                $evidence['forecast'] = $forecast;
                $caveats = array_values(array_unique([...(array) ($forecast['caveats'] ?? []),
                    'Historical bids and forecast clicks are estimates, not minimum bids or guaranteed delivery.',
                    'This initial bid proposal does not approve activation, billing, tracking or Google policy readiness.',
                    ...($unknownCosts !== [] ? ['Some selected keywords have no historical cost estimate; the forecast covers the complete selected keyword set.'] : []),
                    ...(count($context['language_constants']) > 1 ? ['Historical keyword costs do not model the complete multi-language selection; the reach forecast retains every selected language.'] : []),
                ]));

                return ['ready' => true, 'status' => 'estimated', 'cpc_bid_micros' => $chosen['cpc_bid_micros'],
                    'reason' => $exactBidMicros === null
                        ? 'The tested bid with the most useful forecast traffic within retained spending limits; similar traffic favors lower estimated cost and bid.'
                        : 'Fresh forecast reach at the exact researched bid and this proposed daily budget.',
                    'issues' => [], 'caveats' => $caveats, 'evidence' => $evidence, 'activation_ready' => false, 'auto_repair_safe' => false];
            }

            return $this->unavailable('No tested keyword-cost estimate demonstrated sufficient reach within the approved spending limits.', $evidence);
        } catch (\Throwable $e) {
            report($e);

            return $this->unavailable('Fresh keyword cost and reach estimates could not be verified. No fallback bid was chosen.', $evidence);
        }
    }

    /** All candidates must already have complete, credible forecasts for the same period and budget. */
    public static function bestForecast(array $candidates): ?array
    {
        if ($candidates === []) {
            return null;
        }
        $maximum = max(array_map(fn ($candidate) => $candidate['forecast']['clicks'], $candidates));
        // Forecast noise must not justify a more expensive bid for a fractional
        // click. Compare every candidate to the maximum to avoid pairwise ties.
        $nearBest = array_values(array_filter($candidates, fn ($candidate) => $candidate['forecast']['clicks'] >= $maximum - max(0.1, $maximum * 0.01)));
        usort($nearBest, fn ($a, $b) => [$a['forecast']['cost_micros'], $a['cpc_bid_micros'], -$a['forecast']['clicks']]
            <=> [$b['forecast']['cost_micros'], $b['cpc_bid_micros'], -$b['forecast']['clicks']]);

        return $nearBest[0];
    }

    private function context(Campaign $campaign, array $snapshot, int $budget): array
    {
        $context = array_intersect_key($snapshot, array_flip(['currency_code', 'time_zone', 'geo_target_constants',
            'excluded_geo_target_constants', 'language_constants', 'negative_keywords', 'ad_schedule',
            'search_partners_enabled', 'device_bid_modifiers', 'unsupported_targeting']));
        $context['bidding_strategy'] = BiddingStrategyType::MANUAL_CPC;
        $context['daily_budget_micros'] = $budget;
        $today = CarbonImmutable::now($context['time_zone'])->startOfDay();
        $start = $today->addDay();
        $end = $today->addYear();
        foreach (['start' => $campaign->getAttribute('start_date'), 'end' => $campaign->getAttribute('end_date')] as $kind => $date) {
            if ($date) {
                $date = CarbonImmutable::parse($date->toDateString(), $context['time_zone']);
                $kind === 'start' ? $start = $start->max($date) : $end = $end->min($date);
            }
        }
        foreach (['start', 'end'] as $kind) {
            $value = $snapshot[$kind.'_date_time'] ?? $snapshot[$kind.'_date'] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            if (! is_string($value) || CampaignDateTimes::calendarDate($value) === null) {
                throw new \InvalidArgumentException('The actual campaign serving dates are invalid.');
            }
            $date = CarbonImmutable::parse(CampaignDateTimes::fullServingDay($value, $kind === 'start'), $context['time_zone']);
            $kind === 'start' ? $start = $start->max($date) : $end = $end->min($date);
        }
        $context['forecast_start_date'] = $start->toDateString();
        $context['forecast_end_date_limit'] = $end->toDateString();
        $context['forecast_period_days'] = max(0, min(30, (int) $start->diffInDays($end, false) + 1));

        return $context;
    }

    protected function historicalProvider(Customer $customer): GenerateKeywordHistoricalMetrics
    {
        return new GenerateKeywordHistoricalMetrics($customer);
    }

    private function matchesForecast(array $forecast, array $keywords, array $context): bool
    {
        $actual = $forecast['context'] ?? [];
        foreach (['cpc_bid_ceiling_micros', 'daily_budget_micros', 'currency_code', 'time_zone',
            'geo_target_constants', 'language_constants', 'negative_keywords'] as $key) {
            if (! array_key_exists($key, $actual) || $actual[$key] !== $context[$key]) {
                return false;
            }
        }
        $normalize = function (array $values): array {
            $result = array_map(function ($keyword) {
                $value = array_intersect_key($keyword, array_flip(['text', 'match_type', 'ad_group_resource', 'cpc_bid_micros']));
                ksort($value);

                return $value;
            }, $values);
            usort($result, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));

            return $result;
        };
        $days = $context['forecast_period_days'];

        return is_array($actual['keywords'] ?? null) && $normalize($actual['keywords']) === $normalize($keywords)
            && ($forecast['period_days'] ?? null) === $days
            && ($forecast['forecast_period']['from'] ?? null) === $context['forecast_start_date']
            && ($forecast['forecast_period']['through'] ?? null) === CarbonImmutable::parse($context['forecast_start_date'], $context['time_zone'])->addDays($days - 1)->toDateString();
    }

    protected function forecastProvider(Customer $customer): GenerateKeywordForecast
    {
        return new GenerateKeywordForecast($customer);
    }

    private function unavailable(string $reason, array $evidence): array
    {
        return ['ready' => false, 'status' => 'needs_review', 'reason' => $reason,
            'issues' => [(new AgentIssue('initial_search_bid_needs_review', $reason))->jsonSerialize()],
            'caveats' => [], 'evidence' => $evidence, 'activation_ready' => false, 'auto_repair_safe' => false];
    }
}
