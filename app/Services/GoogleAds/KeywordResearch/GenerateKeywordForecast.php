<?php

namespace App\Services\GoogleAds\KeywordResearch;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V26\Common\DateRange;
use Google\Ads\GoogleAds\V26\Common\KeywordInfo;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Google\Ads\GoogleAds\V26\Enums\KeywordMatchTypeEnum\KeywordMatchType;
use Google\Ads\GoogleAds\V26\Services\CampaignToForecast;
use Google\Ads\GoogleAds\V26\Services\CampaignToForecast\CampaignBiddingStrategy;
use Google\Ads\GoogleAds\V26\Services\ForecastAdGroup;
use Google\Ads\GoogleAds\V26\Services\GenerateKeywordForecastMetricsRequest;
use Google\Ads\GoogleAds\V26\Services\GenerateKeywordForecastMetricsResponse;
use Google\Ads\GoogleAds\V26\Services\ManualCpcBiddingStrategy;
use Google\Ads\GoogleAds\V26\Services\MaximizeClicksBiddingStrategy;

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
            $timezone = $context['time_zone'] ?? $context['timezone'] ?? config('app.timezone', 'UTC');
            $today = CarbonImmutable::now($timezone)->startOfDay();
            $start = $today->addDay();
            if (array_key_exists('forecast_start_date', $context)) {
                $start = $this->forecastDate($context['forecast_start_date'], $timezone);
                if ($start === null) {
                    return ['success' => false, 'auto_repair_safe' => false, 'error' => 'A valid forecast start date is required.'];
                }
            }
            $end = $start->addDays($days - 1);
            // Google uses the account timezone and permits future dates within one year only.
            if ($start->lte($today) || $end->gt($today->addYear())) {
                return ['success' => false, 'auto_repair_safe' => false, 'error' => 'The forecast must start in the future and end within one year in the account timezone.'];
            }
            if (array_key_exists('forecast_end_date_limit', $context)) {
                $limit = $this->forecastDate($context['forecast_end_date_limit'], $timezone);
                if ($limit === null || $end->gt($limit)) {
                    return ['success' => false, 'auto_repair_safe' => false, 'error' => 'The forecast extends beyond the verified campaign serving window.'];
                }
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
            $limitations = [];
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
                $groups[$group][] = $this->keywordInfo($keyword);
            }
            // v26 accepts only a uniform Manual CPC bid. Keeping a lower live
            // keyword bid in metadata cannot make an aggregate-cap forecast faithful.
            if ($forecastStrategy === 'MANUAL_CPC') {
                foreach ($keywords as $keyword) {
                    if (($keyword['cpc_bid_micros'] ?? null) !== $bidMicros) {
                        $caveats[] = 'Google’s forecast cannot reproduce missing or unequal Manual CPC keyword bids.';
                        break;
                    }
                }
            }
            if (! empty($context['negative_keywords'])) {
                $limitations[] = ['code' => 'negative_keywords_unmodeled',
                    'message' => 'Google’s forecast no longer supports negative keywords; live campaign or ad-group negatives are not modeled.'];
                $caveats[] = $limitations[0]['message'];
            }
            $campaign = new CampaignToForecast([
                'bidding_strategy' => $bidding,
                'ad_groups' => array_map(fn (array $keywords) => new ForecastAdGroup(['keywords' => $keywords]), array_values($groups)),
                'geo_target_constants' => $this->constants($context['geo_target_constants'] ?? [], 'geoTargetConstants'),
                'language_constants' => $this->constants($context['language_constants'] ?? [], 'languageConstants'),
            ]);
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
            if (! $metrics->hasClicks() || ! $metrics->hasCostMicros()) {
                $caveats[] = 'Google did not return both click and cost forecast metrics.';
            }
            $caveats = array_values(array_unique($caveats));
            $result = [
                'success' => true,
                'source' => 'google_keyword_forecast',
                'checked_at' => now()->toIso8601String(),
                // v26 no longer returns impressions or CTR; absence is not zero.
                'impressions' => null,
                'clicks' => $metrics->hasClicks() ? $metrics->getClicks() : null,
                'cost_micros' => $metrics->hasCostMicros() ? (int) $metrics->getCostMicros() : null,
                'cost' => $metrics->hasCostMicros() ? $metrics->getCostMicros() / 1_000_000 : null,
                'average_cpc_micros' => $metrics->hasAverageCpcMicros() ? (int) $metrics->getAverageCpcMicros() : null,
                'average_cpc' => $metrics->hasAverageCpcMicros() ? $metrics->getAverageCpcMicros() / 1_000_000 : null,
                'ctr' => null,
                'period_days' => $days,
                'forecast_period' => ['from' => $start->toDateString(), 'through' => $end->toDateString()],
                'caveats' => $caveats,
                'limitations' => $limitations,
                'auto_repair_safe' => $caveats === [],
                'context' => ['bidding_strategy' => $forecastStrategy, 'cpc_bid_ceiling_micros' => $bidMicros,
                    'daily_budget_micros' => $dailyBudget, 'currency_code' => $context['currency_code'] ?? null,
                    'time_zone' => $timezone, 'keywords' => $keywords,
                    'geo_target_constants' => $this->constants($context['geo_target_constants'] ?? [], 'geoTargetConstants'),
                    'language_constants' => $this->constants($context['language_constants'] ?? [], 'languageConstants'),
                    'negative_keywords' => $context['negative_keywords'] ?? []],
            ];
            if ($conversionRate !== null) {
                // This caller-supplied assumption is separate from Google's CPC
                // forecast, which does not return conversions in v26.
                $result['conversions'] = $metrics->hasClicks() ? $metrics->getClicks() * $conversionRate : null;
                $result['conversion_rate_assumption'] = $conversionRate;
                $result['conversions_source'] = 'click_forecast_times_supplied_assumption';
                $result['conversion_rate_source'] = $context['conversion_rate_source'] ?? 'supplied_assumption';
            }

            return $result;
        } catch (\Throwable $e) {
            $this->logError('GenerateKeywordForecast failed for customer '.$customerId.': '.$e->getMessage());

            return ['success' => false, 'auto_repair_safe' => false, 'error' => $e->getMessage()];
        }
    }

    private function forecastDate(mixed $value, string $timezone): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, $timezone);

            return $date !== null && $date->toDateString() === $value ? $date : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function sendRequest(GenerateKeywordForecastMetricsRequest $request): GenerateKeywordForecastMetricsResponse
    {
        $this->ensureClient();

        return $this->client->getKeywordPlanIdeaServiceClient()->generateKeywordForecastMetrics($request);
    }

    /** @return list<array{text: string, match_type: string, ad_group_resource?: string, cpc_bid_micros?: positive-int}> */
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
            if (is_array($keyword) && isset($keyword['cpc_bid_micros']) && $keyword['cpc_bid_micros'] !== 0) {
                if (! is_int($keyword['cpc_bid_micros']) || $keyword['cpc_bid_micros'] <= 0) {
                    throw new \InvalidArgumentException('Keyword forecast CPC must be positive integer micros.');
                }
                $value['cpc_bid_micros'] = $keyword['cpc_bid_micros'];
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
            $caveats[] = 'Google’s forecast no longer offers network selection; it cannot reproduce a campaign with Search partners enabled.';
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
