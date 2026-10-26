<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordForecast;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordHistoricalMetrics;
use App\Services\GoogleAds\KeywordResearch\KeywordResearchService;
use App\Services\GoogleAds\Support\CampaignDateTimes;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Illuminate\Support\Facades\Cache;

/** Research is read-only, including during spending holds and controlled trials. */
class GoogleSearchReachPlanner
{
    /** A mutation must use new provider observations for the complete prospective keyword set. */
    public function assessMutation(Campaign $campaign, array $snapshot, array $keywords): array
    {
        $proposed = $snapshot;
        $proposed['keywords'] = array_map(fn ($keyword) => $keyword + ['status' => 'ENABLED', 'ad_group_status' => 'ENABLED'], $keywords);
        $context = $this->campaignContext($campaign, $proposed);
        $customer = $campaign->customer;
        if (! $customer) {
            return $this->unavailable('The campaign customer is unavailable.');
        }
        $keywords = $proposed['keywords'];
        $texts = array_column($keywords, 'text');
        Cache::forget($this->historicalKey($customer, $texts, $context));
        Cache::forget($this->forecastKey($customer, $keywords, (int) ($context['cpc_bid_ceiling_micros'] ?? 0), $context));

        return $this->assess($campaign, $proposed);
    }

    public function assess(Campaign $campaign, array $snapshot, bool $research = false): array
    {
        $customer = $campaign->customer;
        if (! $customer || ! $customer->cleanGoogleCustomerId()) {
            return $this->unavailable('The Google customer account could not be identified.');
        }
        $keywords = array_values(array_filter($snapshot['keywords'] ?? [], fn ($keyword) => ($keyword['status'] ?? '') === 'ENABLED' && ($keyword['ad_group_status'] ?? 'ENABLED') === 'ENABLED'));
        $context = $this->campaignContext($campaign, $snapshot);
        $ceiling = (int) ($snapshot['cpc_bid_ceiling_micros'] ?? 0);
        $issues = [];
        if ($keywords === []) {
            $issues[] = new AgentIssue('no_active_keywords', 'No active Search keywords are available to attract traffic.');
        }
        try {
            $historical = $this->historical($customer, array_column($keywords, 'text'), $context);
            if (! ($historical['success'] ?? false)) {
                return $this->unavailable('Keyword demand estimates could not be verified.');
            }
            $metrics = $historical['keywords'] ?? [];
            $estimated = array_values(array_filter($metrics, fn ($row) => ($row['avg_monthly_searches'] ?? null) !== null));
            if ($estimated !== [] && max(array_column($estimated, 'avg_monthly_searches')) < 100) {
                $issues[] = new AgentIssue('limited_keyword_demand', 'The current keywords have low estimated search demand in the selected markets.');
            }
            $bids = array_values(array_filter(array_column($metrics, 'low_top_of_page_bid_micros'), fn ($value) => is_int($value) && $value > 0));
            if ($ceiling > 0 && $bids !== [] && min($bids) > $ceiling) {
                $issues[] = new AgentIssue('bid_ceiling_below_estimates', 'The bid ceiling is below the historical lower top-of-page estimates for the measured keywords. This may limit reach; these estimates are not minimum bids.');
            }
            $forecast = $ceiling > 0 && $keywords !== [] ? $this->forecast($customer, $keywords, $ceiling, $context) : null;
            if ($ceiling <= 0) {
                $issues[] = new AgentIssue('cpc_ceiling_unverified', 'There is no verified campaign CPC ceiling for a bounded keyword repair. A bid limit needs review.');
            } elseif ($forecast && ! ($forecast['success'] ?? false)) {
                return $this->unavailable('Traffic at the current bid and targeting could not be forecast.', $historical);
            } elseif ($forecast && $this->monthlyClicks($forecast) < $this->minimumClicks()) {
                $issues[] = new AgentIssue('insufficient_forecast_reach', 'The current keyword set is forecast to attract fewer than '.$this->minimumClicks().' clicks over 30 days at the current bid and targeting.');
            }
            $result = [
                'status' => 'evidence_ready', 'checked_at' => now()->toIso8601String(),
                'issues' => $this->issues($issues), 'historical' => $historical, 'forecast' => $forecast,
                'candidate_keywords' => [], 'auto_repair_safe' => false,
                'proposal' => ['summary' => 'Review actual delivery and keyword reach at the current spending limits.',
                    'candidate_keywords' => [], 'blocked_by' => [], 'suggested_actions' => []],
            ];
            if ($research) {
                $result = $this->research($campaign, $snapshot, $keywords, $context, $ceiling, $result);
            }

            return $result;
        } catch (\Throwable $e) {
            report($e);

            return $this->unavailable('Keyword reach research is temporarily unavailable. Existing campaign settings have been preserved.');
        }
    }

    private function research(Campaign $campaign, array $snapshot, array $existing, array $context, int $ceiling, array $result): array
    {
        $customer = $campaign->customer;
        $groups = array_values(array_unique(array_filter(array_column($existing, 'ad_group_resource'))));
        if (count($groups) !== 1) {
            $result['proposal']['blocked_by'][] = 'An operator needs to select the relevant ad group for the repair.';

            return $result;
        }
        $manual = in_array($context['bidding_strategy'] ?? null, [BiddingStrategyType::MANUAL_CPC, 'MANUAL_CPC', 'ManualCpc'], true);
        $groupBids = array_values(array_unique(array_column($existing, 'ad_group_cpc_bid_micros')));
        $inherited = $manual ? ($groupBids[0] ?? null) : null;
        if ($manual && (count($groupBids) !== 1 || ! is_int($inherited) || $inherited <= 0 || $inherited > $ceiling)) {
            $result['proposal']['blocked_by'][] = 'The new keyword’s inherited Manual CPC bid must be verified within the approved ceiling.';

            return $result;
        }
        $business = ['offer' => $campaign->product_focus, 'audience' => $campaign->target_market,
            'goals' => $campaign->goals, 'existing_keywords' => array_column($existing, 'text'),
            'reviewed_business_profile' => $customer->exists ? $customer->brandGuideline?->only(['unique_selling_propositions', 'target_audience', 'service_lines']) : null,
            'daily_budget_micros' => $context['daily_budget_micros'], 'max_cpc_bid_micros' => $ceiling];
        $research = $this->discover($campaign, $existing, $context, $business);
        $limit = max(1, min(3, (int) config('optimization.search_delivery.max_keywords_per_repair', 3)));
        $poolSize = max($limit, min(12, (int) config('optimization.search_delivery.max_candidates_to_forecast', 6)));
        $candidates = $viable = $portfolios = [];
        $combined = $candidateHistorical = null;
        $baseline = $this->monthlyClicks($result['forecast'] ?? []);
        $improved = false;
        // One initial pool and one independent alternative-theme pass. A long
        // list of expensive terms or a tiny positive forecast cannot end discovery.
        foreach ([0, 1] as $pass) {
            $pool = $this->candidatePool($research, $existing, $snapshot, $groups[0], $inherited,
                $pass === 0 ? 'initial_research' : 'affordable_alternatives', $candidates);
            if ($pool !== [] && $ceiling > 0) {
                $history = $this->historical($customer, array_column($pool, 'text'), $context);
                $candidateHistorical = ['success' => ($history['success'] ?? false) === true,
                    'checked_at' => $history['checked_at'] ?? null,
                    'keywords' => [...($candidateHistorical['keywords'] ?? []), ...($history['keywords'] ?? [])]];
                $pool = $this->rankCandidatePool($pool, $history, $ceiling);
                foreach (array_slice($pool, 0, $poolSize) as $candidate) {
                    $bid = $manual ? $candidate['cpc_bid_micros'] : $ceiling;
                    $forecastContext = $manual ? array_replace($context, ['cpc_bid_ceiling_micros' => $bid]) : $context;
                    $candidate['forecast'] = $this->forecast($customer, [$candidate], $bid, $forecastContext);
                    $candidate['forecasted'] = ($candidate['forecast']['success'] ?? false)
                        && ($candidate['forecast']['clicks'] ?? 0) > 0;
                    $candidates[] = $candidate;
                }
            }
            $available = array_values(array_filter($candidates, fn ($candidate) => $candidate['forecasted']
                && $this->monthlyClicks($candidate['forecast']) >= 1
                && ($candidate['historical_metrics']['avg_monthly_searches'] ?? 0) > 0));
            $cohorts = $this->candidateCohorts($available, $limit);
            foreach ($cohorts as $cohort) {
                $key = implode('|', array_column($cohort, 'text'));
                if (isset($portfolios[$key])) {
                    continue;
                }
                $forecast = $this->forecast($customer, [...$existing, ...$cohort], $ceiling, $context);
                $portfolios[$key] = ['keywords' => $cohort, 'forecast' => $forecast];
            }
            $best = $this->bestPortfolio(array_values($portfolios), $baseline, $context);
            if ($best !== null) {
                $viable = $best['keywords'];
                $combined = $best['forecast'];
                $improved = $this->usefulImprovement($combined, $baseline, $context);
            }
            if ($improved || $pass === 1 || $ceiling <= 0) {
                break;
            }
            $failed = array_map(fn ($candidate) => ['text' => $candidate['text'],
                'forecast_clicks' => $candidate['forecast']['clicks'] ?? null,
                'forecast_period_days' => $candidate['forecast']['period_days'] ?? null,
                'historical_cost' => $candidate['cost_evidence'] ?? null], $candidates);
            try {
                $research = $this->discover($campaign, $existing, $context,
                    $business + ['failed_candidates' => $failed, 'minimum_monthly_forecast_clicks' => $this->minimumClicks()], true);
            } catch (\Throwable $e) {
                report($e);
                $result['affordable_discovery'] = ['attempted' => true, 'status' => 'unavailable',
                    'checked_at' => now()->toIso8601String(), 'max_passes' => 2];

                break; // An optional discovery failure cannot erase the verified baseline.
            }
            $result['affordable_discovery'] = ['attempted' => true, 'checked_at' => now()->toIso8601String(),
                'discovery' => $research['discovery'] ?? [], 'candidate_limit_per_pass' => $poolSize, 'max_passes' => 2];
        }
        $blocked = [];
        if (CampaignSpendGuardrails::automaticChangesSuspended($campaign)) {
            $blocked[] = $campaign->spend_safety_hold ? 'The campaign has a spending hold.' : 'This controlled test requires review before keyword changes.';
        }
        if ($ceiling <= 0) {
            $blocked[] = 'A positive campaign CPC ceiling must be approved and verified.';
        }
        if (! $improved) {
            $blocked[] = 'No researched addition has demonstrated sufficient extra reach at the current bid and targeting.';
        }
        if ($combined && ! ($combined['auto_repair_safe'] ?? false)) {
            $blocked[] = 'The forecast cannot faithfully model all current delivery restrictions.';
        }
        $result['researched_candidates'] = $candidates;
        $result['candidate_keywords'] = $viable;
        $result['combined_forecast'] = $combined;
        $result['candidate_historical'] = $candidateHistorical;
        $result['auto_repair_safe'] = $improved && ($combined['auto_repair_safe'] ?? false) && $blocked === [];
        $result['bounded_expansion_candidate'] = $improved && ! CampaignSpendGuardrails::automaticChangesSuspended($campaign)
            && $ceiling > 0 && $this->soleNegativeLimitation($combined ?? []);
        $result['tested_portfolios'] = array_values($portfolios);
        $result['proposal'] = [
            'summary' => $improved
                ? 'Add '.count($viable).' relevant keywords at the existing CPC ceiling, then verify that Google Search traffic follows.'
                : 'The current limits do not yet support a verified keyword repair. Review the keyword set and bid ceiling together.',
            'candidate_keywords' => $viable, 'blocked_by' => $blocked,
            'suggested_actions' => $improved ? ['Review the forecasted keyword additions.']
                : ['Research a different relevant keyword theme.', 'Review a bid ceiling supported by forecasts within the approved spending budget.'],
        ];

        return $result;
    }

    private function discover(Campaign $campaign, array $existing, array $context, array $business, bool $affordable = false): array
    {
        $customer = $campaign->customer;
        $key = 'search_reach:research:v2:'.$campaign->id.':'.hash('sha256', json_encode([$existing, $context, $business, $affordable]));

        return Cache::remember($key, now()->addHours(24), function () use ($campaign, $customer, $context, $business, $affordable): array {
            $provider = $this->researchProvider($customer);
            $arguments = [$customer->cleanGoogleCustomerId(), $customer->name, $customer->business_type,
                $campaign->landing_page_url ?: $customer->website,
                count($context['language_constants']) === 1 ? $context['language_constants'][0] : null,
                $context['geo_target_constants'], 15, [], $business];

            return $affordable ? $provider->researchAffordableAlternatives(...$arguments) : $provider->research(...$arguments);
        });
    }

    private function candidatePool(array $research, array $existing, array $snapshot, string $group, ?int $manualBid, string $origin, array $tested): array
    {
        $excluded = array_map(fn ($keyword) => mb_strtolower(trim($keyword['text'])), [...$existing, ...$tested]);
        $pool = [];
        foreach ([...($research['cost_candidates'] ?? []), ...($research['keywords'] ?? [])] as $keyword) {
            $text = is_string($keyword['text'] ?? null) ? trim($keyword['text']) : '';
            $match = strtoupper($keyword['match_type'] ?? '');
            if ($text === '' || ! in_array($match, ['EXACT', 'PHRASE'], true)
                || ! is_string($keyword['selection_reason'] ?? null) || trim($keyword['selection_reason']) === ''
                || in_array(mb_strtolower($text), $excluded, true)
                || self::negativeConflict($text, $snapshot['negative_keywords'] ?? [], $group)) {
                continue;
            }
            $candidate = ['text' => $text, 'match_type' => $match, 'ad_group_resource' => $group,
                'relevant' => true, 'negative_conflict' => false, 'selection_reason' => $keyword['selection_reason'],
                'forecasted' => false, 'origin' => $origin];
            if ($manualBid !== null) {
                $candidate += ['cpc_bid_micros' => $manualBid, 'set_keyword_bid' => true];
            }
            foreach (['average_cpc_micros', 'low_top_of_page_bid_micros', 'high_top_of_page_bid_micros'] as $basis) {
                $cost = $keyword[$basis] ?? null;
                if (is_int($cost) && $cost > 0) {
                    $candidate['cost_evidence'] = ['basis' => $basis, 'amount_micros' => $cost];
                    break;
                }
            }
            $pool[mb_strtolower($text)] ??= $candidate;
        }

        return array_values($pool);
    }

    /** Rank known prices before a bounded forecast quota, merging Google's canonical close variants. */
    private function rankCandidatePool(array $pool, array $historical, int $ceiling): array
    {
        foreach ($pool as &$candidate) {
            $metric = self::historicalMetric($historical['keywords'] ?? [], $candidate['text']);
            $candidate['historical_metrics'] = $metric;
            $candidate['historical_checked_at'] = $historical['checked_at'] ?? null;
            if (! isset($candidate['cost_evidence'])) {
                foreach (['average_cpc_micros', 'low_top_of_page_bid_micros', 'high_top_of_page_bid_micros'] as $basis) {
                    if (is_int($metric[$basis] ?? null) && $metric[$basis] > 0) {
                        $candidate['cost_evidence'] = ['basis' => $basis, 'amount_micros' => $metric[$basis]];
                        break;
                    }
                }
            }
        }
        unset($candidate);
        usort($pool, function ($a, $b) use ($ceiling): int {
            $costA = $a['cost_evidence']['amount_micros'] ?? PHP_INT_MAX;
            $costB = $b['cost_evidence']['amount_micros'] ?? PHP_INT_MAX;

            return [$costA > $ceiling, $costA, -($a['historical_metrics']['avg_monthly_searches'] ?? 0), mb_strtolower($a['text'])]
                <=> [$costB > $ceiling, $costB, -($b['historical_metrics']['avg_monthly_searches'] ?? 0), mb_strtolower($b['text'])];
        });
        $distinct = [];
        foreach ($pool as $candidate) {
            $canonical = mb_strtolower(trim($candidate['historical_metrics']['text'] ?? $candidate['text']));
            $distinct[$canonical] ??= $candidate;
        }

        return array_values($distinct);
    }

    /** Test both a cheaper cohort and a traffic cohort as complete portfolios, never sum isolated estimates. */
    private function candidateCohorts(array $candidates, int $limit): array
    {
        if ($candidates === []) {
            return [];
        }
        $traffic = $candidates;
        usort($traffic, fn ($a, $b) => $this->monthlyClicks($b['forecast']) <=> $this->monthlyClicks($a['forecast']));
        usort($candidates, function ($a, $b): int {
            $clicksA = $this->monthlyClicks($a['forecast']);
            $clicksB = $this->monthlyClicks($b['forecast']);
            $costA = $this->forecastCpc($a['forecast']);
            $costB = $this->forecastCpc($b['forecast']);

            return [$clicksA < $this->minimumClicks(), $costA, -$clicksA]
                <=> [$clicksB < $this->minimumClicks(), $costB, -$clicksB];
        });

        return [array_slice($candidates, 0, $limit), array_slice($traffic, 0, $limit)];
    }

    private function bestPortfolio(array $portfolios, float $baseline, array $context): ?array
    {
        $portfolios = array_values(array_filter($portfolios, fn ($portfolio) => ($portfolio['forecast']['success'] ?? false)
            && ($portfolio['forecast']['clicks'] ?? 0) > 0));
        usort($portfolios, function ($a, $b) use ($baseline, $context): int {
            return [! $this->usefulImprovement($a['forecast'], $baseline, $context), $this->forecastCpc($a['forecast']), -$this->monthlyClicks($a['forecast'])]
                <=> [! $this->usefulImprovement($b['forecast'], $baseline, $context), $this->forecastCpc($b['forecast']), -$this->monthlyClicks($b['forecast'])];
        });

        return $portfolios[0] ?? null;
    }

    private function usefulImprovement(array $forecast, float $baseline, array $context): bool
    {
        $cost = $forecast['cost_micros'] ?? null;
        $days = $forecast['period_days'] ?? null;
        $budget = $context['daily_budget_micros'] ?? null;

        return ($forecast['success'] ?? false) && $this->monthlyClicks($forecast) >= $this->minimumClicks()
            && $this->monthlyClicks($forecast) >= $baseline + max(1, $baseline * 0.2)
            && is_int($cost) && $cost > 0 && is_int($days) && $days > 0
            && is_int($budget) && $budget > 0 && $cost <= $budget * $days;
    }

    private function forecastCpc(array $forecast): float
    {
        $cost = $forecast['cost_micros'] ?? null;
        $clicks = $forecast['clicks'] ?? null;

        return is_int($cost) && $cost > 0 && is_numeric($clicks) && $clicks > 0 ? $cost / $clicks : (float) PHP_INT_MAX;
    }

    private function soleNegativeLimitation(array $forecast): bool
    {
        $limitations = $forecast['limitations'] ?? [];

        return ($forecast['auto_repair_safe'] ?? null) === false && count($limitations) === 1
            && ($limitations[0]['code'] ?? null) === 'negative_keywords_unmodeled'
            && is_string($limitations[0]['message'] ?? null) && trim($limitations[0]['message']) !== ''
            && ($forecast['caveats'] ?? null) === [$limitations[0]['message']];
    }

    /** Google can group close variants under one canonical text; keyed lookups retain the requested term. */
    public static function historicalMetric(array $rows, string $text): ?array
    {
        $needle = mb_strtolower(trim($text));
        foreach ($rows as $key => $row) {
            if (is_array($row) && is_string($key) && mb_strtolower(trim($key)) === $needle) {
                return $row;
            }
        }
        foreach ($rows as $row) {
            if (is_array($row) && mb_strtolower(trim($row['text'] ?? '')) === $needle) {
                return $row;
            }
        }

        return null;
    }

    /** Conservative negative matching; unknown match types block rather than silently permit. */
    public static function negativeConflict(string $text, array $negatives, string $adGroup): bool
    {
        $normalize = fn ($value) => trim(preg_replace('/[^\pL\pN]+/u', ' ', mb_strtolower($value)) ?? '');
        $positive = $normalize($text);
        foreach ($negatives as $negative) {
            if (($negative['scope'] ?? 'campaign') === 'ad_group' && ($negative['ad_group_resource'] ?? null) !== $adGroup) {
                continue;
            }
            $value = $normalize($negative['text'] ?? '');
            if ($value === '') {
                continue;
            }
            $match = strtoupper($negative['match_type'] ?? '');
            if (($match === 'EXACT' && $positive === $value)
                || ($match === 'PHRASE' && str_contains(' '.$positive.' ', ' '.$value.' '))
                || ($match === 'BROAD' && array_diff(explode(' ', $value), explode(' ', $positive)) === [])
                || ! in_array($match, ['EXACT', 'PHRASE', 'BROAD'], true)) {
                return true;
            }
        }

        return false;
    }

    private function context(array $snapshot): array
    {
        return array_intersect_key($snapshot, array_flip(['bidding_strategy', 'currency_code', 'time_zone',
            'cpc_bid_ceiling_micros', 'daily_budget_micros', 'geo_target_constants', 'excluded_geo_target_constants',
            'language_constants', 'negative_keywords', 'ad_schedule', 'search_partners_enabled',
            'device_bid_modifiers', 'unsupported_targeting'])) + ['geo_target_constants' => [],
                'language_constants' => [], 'daily_budget_micros' => 0];
    }

    private function campaignContext(Campaign $campaign, array $snapshot): array
    {
        $context = $this->context($snapshot);
        try {
            $timezone = $context['time_zone'] ?? 'UTC';
            $today = CarbonImmutable::now($timezone)->startOfDay();
            $start = $today->addDay();
            $end = $today->addYear();
            foreach (['start_date_time', 'end_date_time'] as $field) {
                if (isset($snapshot[$field]) && $snapshot[$field] !== ''
                    && (! is_string($snapshot[$field]) || CampaignDateTimes::calendarDate($snapshot[$field]) === null)) {
                    throw new \InvalidArgumentException('A live campaign serving timestamp is invalid.');
                }
            }
            foreach ([$campaign->getAttribute('start_date'), isset($snapshot['start_date_time'])
                ? CampaignDateTimes::fullServingDay($snapshot['start_date_time'], true) : ($snapshot['start_date'] ?? null)] as $date) {
                $date = $this->servingDate($date, $timezone);
                if ($date !== null && $date->gt($start)) {
                    $start = $date;
                }
            }
            foreach ([$campaign->getAttribute('end_date'), isset($snapshot['end_date_time'])
                ? CampaignDateTimes::fullServingDay($snapshot['end_date_time'], false) : ($snapshot['end_date'] ?? null)] as $date) {
                $date = $this->servingDate($date, $timezone);
                if ($date !== null && $date->lt($end)) {
                    $end = $date;
                }
            }
            $context['forecast_start_date'] = $start->toDateString();
            $context['forecast_end_date_limit'] = $end->toDateString();
            $context['forecast_period_days'] = max(0, min(30, (int) $start->diffInDays($end, false) + 1));
        } catch (\Throwable) {
            $context['forecast_period_days'] = 0;
            $context['forecast_window_error'] = 'The approved or live campaign serving dates could not be verified.';
        }
        $trial = CampaignSpendGuardrails::activeTrial($campaign);
        if ($trial && isset($snapshot['reported_lifetime_cost_micros'])) {
            $spent = max(0, (int) $snapshot['reported_lifetime_cost_micros'] - (int) ($trial['baseline_cost_micros'] ?? 0));
            $context['max_forecast_cost_micros'] = max(0, (int) ($trial['max_spend_micros'] ?? 0) - $spent);
        }

        return $context;
    }

    private function servingDate(mixed $value, string $timezone): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            $value = $value->format('Y-m-d');
        }
        if (! is_string($value)) {
            throw new \InvalidArgumentException('A campaign serving date must be a calendar date.');
        }
        $value = preg_replace('/^(\d{4})(\d{2})(\d{2})$/', '$1-$2-$3', $value) ?? '';
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new \InvalidArgumentException('A campaign serving date must have a verified date format.');
        }
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);
        if ($date === null || $date->toDateString() !== $value) {
            throw new \InvalidArgumentException('A campaign serving date is invalid.');
        }

        return $date;
    }

    protected function historical(Customer $customer, array $texts, array $context): array
    {
        $key = $this->historicalKey($customer, $texts, $context);

        return Cache::remember($key, now()->addHours(24), fn () => ($this->historicalProvider($customer))(
            $customer->cleanGoogleCustomerId(), $texts, $context['geo_target_constants'],
            count($context['language_constants']) === 1 ? $context['language_constants'][0] : null,
        ));
    }

    protected function forecast(Customer $customer, array $keywords, int $ceiling, array $context): array
    {
        $key = $this->forecastKey($customer, $keywords, $ceiling, $context);

        return Cache::remember($key, now()->addHours(6), function () use ($customer, $keywords, $ceiling, $context) {
            $days = (int) ($context['forecast_period_days'] ?? 30);
            $remaining = $context['max_forecast_cost_micros'] ?? null;
            if ($days < 1 || $remaining === 0) {
                return ['success' => false, 'auto_repair_safe' => false, 'error' => $context['forecast_window_error'] ?? 'No remaining approved campaign horizon or reported-spend envelope.'];
            }
            $provider = $this->forecastProvider($customer);
            for ($attempt = 0; $attempt < 4; $attempt++) {
                $forecast = $provider($customer->cleanGoogleCustomerId(), $keywords, $ceiling / 1_000_000, null, $days, $context);
                if (! ($forecast['success'] ?? false) || $remaining === null || ($forecast['cost_micros'] ?? PHP_INT_MAX) <= $remaining || $days === 1) {
                    return $forecast;
                }
                // Choose a smaller period, then ask Google again; scaled estimates never authorize a write.
                $days = max(1, min($days - 1, (int) floor($days * $remaining / $forecast['cost_micros'])));
            }

            return $forecast;
        });
    }

    private function historicalKey(Customer $customer, array $texts, array $context): string
    {
        return 'search_reach:historical:'.$customer->id.':'.hash('sha256', json_encode([$texts, $context['geo_target_constants'], $context['language_constants']]));
    }

    private function forecastKey(Customer $customer, array $keywords, int $ceiling, array $context): string
    {
        return 'search_reach:forecast:v26:'.$customer->id.':'.hash('sha256', json_encode([$keywords, $ceiling, $context, now($context['time_zone'] ?? 'UTC')->toDateString()]));
    }

    protected function historicalProvider(Customer $customer): GenerateKeywordHistoricalMetrics
    {
        return new GenerateKeywordHistoricalMetrics($customer);
    }

    protected function forecastProvider(Customer $customer): GenerateKeywordForecast
    {
        return new GenerateKeywordForecast($customer);
    }

    protected function researchProvider(Customer $customer): KeywordResearchService
    {
        return new KeywordResearchService($customer);
    }

    private function minimumClicks(): int
    {
        return max(1, (int) config('optimization.search_delivery.min_forecast_clicks', 30));
    }

    private function monthlyClicks(array $forecast): float
    {
        return (float) ($forecast['clicks'] ?? 0) * 30 / max(1, (int) ($forecast['period_days'] ?? 30));
    }

    private function issues(array $issues): array
    {
        return array_map(fn (AgentIssue $issue) => $issue->jsonSerialize(), $issues);
    }

    private function unavailable(string $message, ?array $historical = null): array
    {
        return ['status' => 'unavailable', 'checked_at' => now()->toIso8601String(),
            'issues' => $this->issues([new AgentIssue('reach_evidence_unavailable', $message)]),
            'historical' => $historical, 'forecast' => null, 'candidate_keywords' => [], 'auto_repair_safe' => false,
            'proposal' => ['summary' => $message, 'candidate_keywords' => [], 'blocked_by' => ['Fresh reach evidence is unavailable.'], 'suggested_actions' => ['Retry the delivery diagnosis.']]];
    }
}
