<?php

namespace App\Services\GoogleAds\KeywordResearch;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V22\Common\DateRange;
use Google\Ads\GoogleAds\V22\Common\KeywordInfo;
use Google\Ads\GoogleAds\V22\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V22\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Google\Ads\GoogleAds\V22\Enums\KeywordPlanNetworkEnum\KeywordPlanNetwork;
use Google\Ads\GoogleAds\V22\Services\BiddableKeyword;
use Google\Ads\GoogleAds\V22\Services\CampaignToForecast;
use Google\Ads\GoogleAds\V22\Services\CampaignToForecast\CampaignBiddingStrategy;
use Google\Ads\GoogleAds\V22\Services\CriterionBidModifier;
use Google\Ads\GoogleAds\V22\Services\ForecastAdGroup;
use Google\Ads\GoogleAds\V22\Services\GenerateKeywordForecastMetricsRequest;
use Google\Ads\GoogleAds\V22\Services\GenerateKeywordForecastMetricsResponse;
use Google\Ads\GoogleAds\V22\Services\ManualCpcBiddingStrategy;
use Google\Ads\GoogleAds\V22\Services\MaximizeClicksBiddingStrategy;

/** Read-only keyword reach estimates; no campaign or keyword is created. */
class GenerateKeywordForecast extends BaseGoogleAdsService
{
    private const MIN_BID_MICROS = 10_000;

    /**
     * String keywords retain the legacy PHRASE convention. Structured keywords
     * preserve their actual match type and ad group. Context carries the live
     * budget, cap, targeting and negatives; omitted/unsupported restrictions are
     * disclosed rather than silently presented as a faithful campaign forecast.
     *
     * @param  list<string|array<string, mixed>>  $keywords
     */
    public function __invoke(string $customerId, array $keywords, float $maxCpc, ?float $conversionRate = null, int $days = 30, array $context = []): array
    {
        try {
            $keywords = $this->normalizeKeywords($keywords);
            if ($keywords === []) {
                return ['success' => false, 'auto_repair_safe' => false, 'error' => 'No keywords supplied to forecast'];
            }
            if (! is_finite($maxCpc) || $days < 1 || $days > 365) {
                return ['success' => false, 'auto_repair_safe' => false, 'error' => 'Invalid bid or forecast period'];
            }
            $suppliedCap = $context['cpc_bid_ceiling_micros'] ?? $context['actual_cpc_ceiling_micros'] ?? null;
            $bidMicros = $suppliedCap !== null ? (int) $suppliedCap
                : max(self::MIN_BID_MICROS, (int) (round($maxCpc * 100) * 10_000));
            if ($bidMicros <= 0 || ($suppliedCap === null && $maxCpc <= 0)) {
                return ['success' => false, 'auto_repair_safe' => false, 'error' => 'A positive CPC cap is required to forecast capped reach'];
            }
            if ($conversionRate !== null && (! is_finite($conversionRate) || $conversionRate < 0 || $conversionRate > 1)) {
                return ['success' => false, 'auto_repair_safe' => false, 'error' => 'Conversion rate must be between zero and one'];
            }

            $caveats = $this->caveats($context);
            $dailyBudget = (int) ($context['daily_budget_micros'] ?? 0);
            if ($dailyBudget <= 0) {
                $caveats[] = 'Daily budget is not supplied; forecast is not constrained to the live campaign budget.';
            }
            $strategy = $context['bidding_strategy'] ?? null;
            $strategyName = is_string($strategy) ? strtoupper(str_replace(['_', ' ', '-'], '', $strategy)) : $strategy;
            $maxClicks = $strategyName === BiddingStrategyType::TARGET_SPEND
                || in_array($strategyName, ['TARGETSPEND', 'MAXIMIZECLICKS'], true);
            if ($maxClicks && $dailyBudget > 0) {
                $bidding = new CampaignBiddingStrategy(['maximize_clicks_bidding_strategy' => new MaximizeClicksBiddingStrategy([
                    'daily_target_spend_micros' => $dailyBudget,
                    'max_cpc_bid_ceiling_micros' => $bidMicros,
                ])]);
                $forecastStrategy = 'MAXIMIZE_CLICKS';
            } else {
                $manual = new ManualCpcBiddingStrategy(['max_cpc_bid_micros' => $bidMicros]);
                if ($dailyBudget > 0) {
                    $manual->setDailyBudgetMicros($dailyBudget);
                }
                $bidding = new CampaignBiddingStrategy(['manual_cpc_bidding_strategy' => $manual]);
                $forecastStrategy = 'MANUAL_CPC';
                if ($strategy !== BiddingStrategyType::MANUAL_CPC && $strategyName !== 'MANUALCPC') {
                    $caveats[] = 'Manual CPC is used to estimate capped reach; this does not reproduce the live automated bidding strategy.';
                }
            }

            $groups = [];
            foreach ($keywords as $keyword) {
                $group = $keyword['ad_group_resource'] ?? 'forecast';
                $groups[$group][] = new BiddableKeyword(['keyword' => $this->keywordInfo($keyword)]);
            }
            $campaignNegatives = [];
            $groupNegatives = [];
            foreach ($context['negative_keywords'] ?? [] as $negative) {
                $normalized = $this->normalizeKeywords([$negative]);
                if ($normalized === []) {
                    continue;
                }
                $info = $this->keywordInfo($normalized[0]);
                if (is_array($negative) && ($negative['scope'] ?? 'campaign') === 'ad_group') {
                    $group = $negative['ad_group_resource'] ?? null;
                    if (! is_string($group) || $group === '') {
                        $caveats[] = 'An ad-group negative could not be assigned to its live ad group.';
                    } elseif (array_key_exists($group, $groups)) {
                        $groupNegatives[$group][] = $info;
                    }
                } else {
                    $campaignNegatives[] = $info;
                }
            }
            $adGroups = [];
            foreach ($groups as $group => $biddable) {
                $data = ['biddable_keywords' => $biddable, 'negative_keywords' => $groupNegatives[$group] ?? []];
                // Keyword/ad-group bid overrides would defeat the campaign-level
                // Maximize Clicks cap being evaluated, so only use them for CPC.
                if ($forecastStrategy === 'MANUAL_CPC') {
                    $data['max_cpc_bid_micros'] = $bidMicros;
                }
                $adGroups[] = new ForecastAdGroup($data);
            }
            $campaign = new CampaignToForecast([
                'keyword_plan_network' => ($context['search_partners_enabled'] ?? false)
                    ? KeywordPlanNetwork::GOOGLE_SEARCH_AND_PARTNERS : KeywordPlanNetwork::GOOGLE_SEARCH,
                'bidding_strategy' => $bidding,
                'ad_groups' => $adGroups,
                'negative_keywords' => $campaignNegatives,
                // v22 uses geo_modifiers, not the newer geo_target_constants.
                'geo_modifiers' => array_map(fn ($resource) => new CriterionBidModifier(['geo_target_constant' => $resource]),
                    $this->constants($context['geo_target_constants'] ?? [], 'geoTargetConstants')),
                'language_constants' => $this->constants($context['language_constants'] ?? [], 'languageConstants'),
            ]);
            if ($conversionRate !== null) {
                // Existing onboarding callers explicitly pass a disclosed
                // assumption. Delivery diagnostics supply only observed rates.
                $campaign->setConversionRate($conversionRate);
            }
            $timezone = $context['time_zone'] ?? $context['timezone'] ?? config('app.timezone', 'UTC');
            $start = CarbonImmutable::now($timezone)->addDay()->startOfDay();
            $end = $start->addDays($days - 1);
            $request = new GenerateKeywordForecastMetricsRequest([
                'customer_id' => $customerId,
                'campaign' => $campaign,
                'forecast_period' => new DateRange(['start_date' => $start->toDateString(), 'end_date' => $end->toDateString()]),
            ]);
            $response = $this->sendRequest($request);
            $metrics = $response->getCampaignForecastMetrics();
            if (! $metrics) {
                return ['success' => false, 'auto_repair_safe' => false, 'error' => 'Google returned no forecast metrics', 'caveats' => $caveats];
            }
            $caveats = array_values(array_unique($caveats));
            $result = [
                'success' => true,
                'source' => 'google_keyword_forecast',
                'impressions' => $metrics->getImpressions(),
                'clicks' => $metrics->getClicks(),
                'cost_micros' => (int) $metrics->getCostMicros(),
                'cost' => $metrics->getCostMicros() / 1_000_000,
                'average_cpc_micros' => (int) $metrics->getAverageCpcMicros(),
                'average_cpc' => $metrics->getAverageCpcMicros() / 1_000_000,
                'ctr' => $metrics->getClickThroughRate(),
                'period_days' => $days,
                'forecast_period' => ['from' => $start->toDateString(), 'through' => $end->toDateString()],
                'caveats' => $caveats,
                'auto_repair_safe' => $caveats === [],
                'context' => ['bidding_strategy' => $forecastStrategy, 'cpc_bid_ceiling_micros' => $bidMicros,
                    'daily_budget_micros' => $dailyBudget, 'currency_code' => $context['currency_code'] ?? null,
                    'time_zone' => $timezone, 'keywords' => $keywords,
                    'geo_target_constants' => $this->constants($context['geo_target_constants'] ?? [], 'geoTargetConstants'),
                    'language_constants' => $this->constants($context['language_constants'] ?? [], 'languageConstants')],
            ];
            if ($conversionRate !== null) {
                $result['conversions'] = $metrics->getConversions();
                $result['conversion_rate_source'] = $context['conversion_rate_source'] ?? 'supplied_assumption';
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logError('GenerateKeywordForecast failed for customer '.$customerId.': '.$e->getMessage());

            return ['success' => false, 'auto_repair_safe' => false, 'error' => $e->getMessage()];
        }
    }

    protected function sendRequest(GenerateKeywordForecastMetricsRequest $request): GenerateKeywordForecastMetricsResponse
    {
        $this->ensureClient();

        return $this->client->getKeywordPlanIdeaServiceClient()->generateKeywordForecastMetrics($request);
    }

    /** @return list<array{text: string, match_type: string, ad_group_resource?: string}> */
    private function normalizeKeywords(array $keywords): array
    {
        $normalized = [];
        foreach ($keywords as $keyword) {
            $text = trim(is_string($keyword) ? $keyword : (string) ($keyword['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $match = is_array($keyword) ? ($keyword['match_type'] ?? 'PHRASE') : 'PHRASE';
            $match = is_int($match) ? KeywordMatchType::name($match) : strtoupper((string) $match);
            if (! in_array($match, ['EXACT', 'PHRASE', 'BROAD'], true)) {
                throw new \InvalidArgumentException('Unknown keyword match type: '.$match);
            }
            $value = ['text' => $text, 'match_type' => $match];
            if (is_array($keyword) && is_string($keyword['ad_group_resource'] ?? null) && $keyword['ad_group_resource'] !== '') {
                $value['ad_group_resource'] = $keyword['ad_group_resource'];
            }
            $key = mb_strtolower($text).'|'.$match.'|'.($value['ad_group_resource'] ?? 'forecast');
            $normalized[$key] = $value;
        }

        return array_values($normalized);
    }

    private function keywordInfo(array $keyword): KeywordInfo
    {
        return new KeywordInfo(['text' => $keyword['text'], 'match_type' => KeywordMatchType::value($keyword['match_type'])]);
    }

    /** @return list<string> */
    private function constants(array $values, string $prefix): array
    {
        $constants = [];
        foreach ($values as $value) {
            if (! is_string($value) || ! preg_match('#^'.preg_quote($prefix, '#').'/[0-9]+$#', $value)) {
                throw new \InvalidArgumentException('Invalid '.$prefix.' resource');
            }
            $constants[] = $value;
        }

        return array_values(array_unique($constants));
    }

    private function caveats(array $context): array
    {
        $caveats = [];
        if (empty($context['geo_target_constants'])) {
            $caveats[] = 'Location targeting is not supplied; forecast cannot establish reach in the campaign\'s actual locations.';
        }
        if (! array_key_exists('language_constants', $context)) {
            $caveats[] = 'Language targeting is not supplied.';
        }
        if (! array_key_exists('negative_keywords', $context)) {
            $caveats[] = 'Negative keywords are not supplied.';
        }
        if (! empty($context['excluded_geo_target_constants'])) {
            $caveats[] = 'Google\'s forecast does not support these excluded locations.';
        }
        if (! empty($context['ad_schedule'])) {
            $caveats[] = 'Google\'s keyword forecast does not reproduce the campaign\'s daypart/ad schedule.';
        }
        $deviceRestrictions = array_filter($context['device_bid_modifiers'] ?? [],
            fn ($modifier) => ! isset($modifier['bid_modifier']) || (float) $modifier['bid_modifier'] !== 1.0);
        if ($deviceRestrictions !== [] || ! empty($context['unsupported_targeting'])) {
            $caveats[] = 'Additional device or targeting restrictions are not represented by this keyword forecast.';
        }
        if ($context['search_partners_enabled'] ?? false) {
            $caveats[] = 'The forecast combines Google Search and partners; it cannot certify Google Search-only reach.';
        }
        if (empty($context['currency_code'])) {
            $caveats[] = 'Account currency is not supplied; amounts use the target Google Ads account\'s currency.';
        }
        if (empty($context['time_zone']) && empty($context['timezone'])) {
            $caveats[] = 'Account timezone is not supplied; forecast dates use the application timezone.';
        }

        return $caveats;
    }
}
