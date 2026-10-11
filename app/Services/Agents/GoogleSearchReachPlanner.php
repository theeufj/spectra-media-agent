<?php

namespace App\Services\Agents;

use App\Models\Campaign;
use App\Models\Customer;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordForecast;
use App\Services\GoogleAds\KeywordResearch\GenerateKeywordHistoricalMetrics;
use App\Services\GoogleAds\KeywordResearch\KeywordResearchService;
use Illuminate\Support\Facades\Cache;

/** Research is read-only, including during spending holds and controlled trials. */
class GoogleSearchReachPlanner
{
    public function assess(Campaign $campaign, array $snapshot, bool $research = false): array
    {
        $customer = $campaign->customer;
        if (! $customer || ! $customer->cleanGoogleCustomerId()) {
            return $this->unavailable('The Google customer account could not be identified.');
        }
        $keywords = array_values(array_filter($snapshot['keywords'] ?? [], fn ($keyword) => ($keyword['status'] ?? '') === 'ENABLED' && ($keyword['ad_group_status'] ?? 'ENABLED') === 'ENABLED'));
        $context = $this->context($snapshot);
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
            } elseif ($forecast && ($forecast['clicks'] ?? 0) < $this->minimumClicks()) {
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
        $cacheKey = 'search_reach:research:'.$campaign->id.':'.hash('sha256', json_encode([$existing, $context, $campaign->product_focus, $campaign->target_market, $customer->description]));
        $research = Cache::remember($cacheKey, now()->addHours(24), fn () => $this->researchProvider($customer)->research(
            $customer->cleanGoogleCustomerId(), $customer->name, $customer->business_type,
            $campaign->landing_page_url ?: $customer->website,
            language: count($context['language_constants']) === 1 ? $context['language_constants'][0] : null,
            geoTargets: $context['geo_target_constants'], maxKeywords: 15,
            businessContext: ['offer' => $campaign->product_focus, 'audience' => $campaign->target_market,
                'goals' => $campaign->goals, 'existing_keywords' => array_column($existing, 'text'),
                'daily_budget_micros' => $context['daily_budget_micros'], 'max_cpc_bid_micros' => $ceiling],
        ));
        $existingKeys = array_map(fn ($keyword) => mb_strtolower(trim($keyword['text'])), $existing);
        $candidates = [];
        foreach ($research['keywords'] ?? [] as $keyword) {
            $text = trim($keyword['text'] ?? '');
            $match = strtoupper($keyword['match_type'] ?? '');
            // A relevance review and commercial connection are required, never a volume-only selection.
            if ($text === '' || ! in_array($match, ['EXACT', 'PHRASE'], true)
                || ! is_string($keyword['selection_reason'] ?? null) || trim($keyword['selection_reason']) === ''
                || in_array(mb_strtolower($text), $existingKeys, true)
                || self::negativeConflict($text, $snapshot['negative_keywords'] ?? [], $groups[0])) {
                continue;
            }
            $candidates[mb_strtolower($text)] = ['text' => $text, 'match_type' => $match,
                'ad_group_resource' => $groups[0], 'relevant' => true, 'negative_conflict' => false,
                'selection_reason' => $keyword['selection_reason'], 'forecasted' => false];
        }
        $limit = max(1, min(3, (int) config('optimization.search_delivery.max_keywords_per_repair', 3)));
        // Investigate more terms than we can add. Early candidates may have no
        // affordable reach; that must not hide a viable later keyword.
        $poolSize = max($limit, min(12, (int) config('optimization.search_delivery.max_candidates_to_forecast', 6)));
        $candidates = array_values(array_slice($candidates, 0, $poolSize));
        foreach ($candidates as &$candidate) {
            if ($ceiling > 0) {
                $candidate['forecast'] = $this->forecast($customer, [$candidate], $ceiling, $context);
                $candidate['forecasted'] = ($candidate['forecast']['success'] ?? false)
                    && ($candidate['forecast']['clicks'] ?? 0) > 0;
            }
        }
        unset($candidate);
        $viable = array_values(array_filter($candidates, fn ($candidate) => $candidate['forecasted']));
        usort($viable, fn ($a, $b) => ($b['forecast']['clicks'] ?? 0) <=> ($a['forecast']['clicks'] ?? 0));
        $viable = array_slice($viable, 0, $limit);
        $combined = $viable !== [] ? $this->forecast($customer, [...$existing, ...$viable], $ceiling, $context) : null;
        $baseline = (float) ($result['forecast']['clicks'] ?? 0);
        $improved = ($combined['success'] ?? false) && ($combined['clicks'] ?? 0) >= $this->minimumClicks()
            && ($combined['clicks'] ?? 0) >= $baseline + max(1, $baseline * 0.2);
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
        $result['auto_repair_safe'] = $improved && ($combined['auto_repair_safe'] ?? false) && $blocked === [];
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

    protected function historical(Customer $customer, array $texts, array $context): array
    {
        $key = 'search_reach:historical:'.$customer->id.':'.hash('sha256', json_encode([$texts, $context['geo_target_constants'], $context['language_constants']]));

        return Cache::remember($key, now()->addHours(24), fn () => (new GenerateKeywordHistoricalMetrics($customer))(
            $customer->cleanGoogleCustomerId(), $texts, $context['geo_target_constants'],
            count($context['language_constants']) === 1 ? $context['language_constants'][0] : null,
        ));
    }

    protected function forecast(Customer $customer, array $keywords, int $ceiling, array $context): array
    {
        $key = 'search_reach:forecast:'.$customer->id.':'.hash('sha256', json_encode([$keywords, $ceiling, $context, now($context['time_zone'] ?? 'UTC')->toDateString()]));

        return Cache::remember($key, now()->addHours(6), fn () => (new GenerateKeywordForecast($customer))(
            $customer->cleanGoogleCustomerId(), $keywords, $ceiling / 1_000_000, null, 30, $context,
        ));
    }

    protected function researchProvider(Customer $customer): KeywordResearchService
    {
        return new KeywordResearchService($customer);
    }

    private function minimumClicks(): int
    {
        return max(1, (int) config('optimization.search_delivery.min_forecast_clicks', 30));
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
