<?php

namespace App\Services\GoogleAds;

use App\Models\Campaign;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V26\Common\Metrics;
use Google\Ads\GoogleAds\V26\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;
use Illuminate\Support\Facades\Log;

/** Observation only. Report failures never pause a campaign or modify its approved configuration. */
class GetAiMaxPilotPerformance extends BaseGoogleAdsService
{
    public function inspect(Campaign $campaign): array
    {
        $result = ['status' => 'collecting_evidence', 'source' => 'google_ads_api_v26',
            'scope' => 'campaign_during_ai_max_pilot', 'checked_at' => now()->toIso8601String(),
            'time_zone' => null, 'currency_code' => null, 'activation_verified_at' => null,
            'period' => null, 'metrics' => null,
            'combinations' => ['status' => 'not_checked', 'source' => 'ai_max_search_term_ad_combination_view', 'limit' => 20, 'rows' => []],
            'notes' => ['Campaign totals are observations after activation, not a causal estimate of AI Max lift.',
                'Conversion attribution can change as delayed conversions arrive. Native combinations may not include every campaign click.'],
            'issues' => []];
        $phase = 'scope';
        try {
            if ($campaign->exists) {
                $fresh = $campaign->fresh(['customer']);
                if (! $fresh) {
                    throw new \LogicException('The campaign is unavailable in the current customer scope.');
                }
                $campaign = $fresh;
            }
            $customerId = $this->customer?->cleanGoogleCustomerId();
            $resource = $campaign->googleAdsResourceName();
            if (! $customerId || $campaign->customer_id !== $this->customer->id
                || $campaign->customer->cleanGoogleCustomerId() !== $customerId
                || $resource !== "customers/{$customerId}/campaigns/{$campaign->googleCampaignNumericId()}") {
                throw new \LogicException('AI Max reporting requires the campaign customer account.');
            }
            $setup = $campaign->search_delivery_state['ai_max_setup'] ?? [];
            $pilot = $campaign->spend_guardrails['ai_max_pilot'] ?? [];
            if (empty($setup['activated_at']) || empty($setup['configuration_verified_at'])) {
                return [...$result, 'reason' => 'activation_not_verified'];
            }
            if (! is_string($setup['activated_at']) || ! is_string($pilot['starts_at'] ?? null)
                || ! is_string($pilot['ends_at'] ?? null)
                || ($setup['customer_id'] ?? null) !== $customerId || ($setup['campaign_resource'] ?? null) !== $resource) {
                throw new \RuntimeException('The activation record is incomplete or belongs to another campaign.');
            }
            $phase = 'account_context';
            $contexts = array_values(iterator_to_array($this->rows($customerId, "SELECT campaign.resource_name,
                campaign.ai_max_setting.enable_ai_max, campaign.bidding_strategy_type,
                customer.currency_code, customer.time_zone FROM campaign WHERE campaign.resource_name = '{$resource}'")));
            if (count($contexts) !== 1) {
                throw new \RuntimeException('Google account reporting context is unavailable.');
            }
            $row = $contexts[0];
            $remote = $row->getCampaign();
            $account = $row->getCustomer();
            $timezone = $account?->getTimeZone();
            $currency = $account?->getCurrencyCode();
            if ($remote?->getResourceName() !== $resource || $remote->getAiMaxSetting()?->getEnableAiMax() !== true
                || $remote->getBiddingStrategyType() !== BiddingStrategyType::MAXIMIZE_CONVERSIONS
                || ! is_string($timezone) || ! in_array($timezone, timezone_identifiers_list(\DateTimeZone::ALL_WITH_BC), true)
                || ! is_string($currency) || ! preg_match('/^[A-Z]{3}$/D', $currency)) {
                throw new \RuntimeException('The actual native campaign mode, currency or timezone is unverified.');
            }
            $activation = CarbonImmutable::parse($setup['activated_at'])->setTimezone($timezone);
            $start = CarbonImmutable::parse($pilot['starts_at'])->setTimezone($timezone);
            $end = CarbonImmutable::parse($pilot['ends_at'])->setTimezone($timezone);
            if ($activation->gt(CarbonImmutable::now()) || $activation->lt($start) || $activation->gte($end)) {
                throw new \RuntimeException('The verified activation is outside this pilot window.');
            }
            $lag = max(0, (int) config('optimization.search_delivery.reporting_lag_hours', 3));
            $from = $activation->startOfDay()->addDay(); // Never mix the preceding manual setup/activation day into the pilot.
            $through = CarbonImmutable::now($timezone)->subHours($lag)->startOfDay()->subDay();
            $lastFullPilotDay = $end->format('H:i:s') === '23:59:59' ? $end->startOfDay() : $end->startOfDay()->subDay();
            $through = $through->min($lastFullPilotDay);
            $result = [...$result, 'time_zone' => $timezone, 'currency_code' => $currency,
                'activation_verified_at' => $activation->toIso8601String(),
                'period' => ['from' => $from->toDateString(), 'through' => $through->gte($from) ? $through->toDateString() : null,
                    'complete_days' => $through->gte($from) ? (int) $from->diffInDays($through) + 1 : 0,
                    'reporting_lag_hours' => $lag, 'activation_day_excluded' => true, 'current_day_excluded' => true]];
            if ($through->lt($from)) {
                return [...$result, 'reason' => 'no_complete_post_activation_day'];
            }
            $dates = "segments.date BETWEEN '{$from->toDateString()}' AND '{$through->toDateString()}'";
            $phase = 'campaign_metrics';
            $totals = $this->emptyMetrics();
            $days = [];
            foreach ($this->rows($customerId, "SELECT campaign.resource_name, segments.date, metrics.impressions,
                metrics.clicks, metrics.cost_micros, metrics.conversions, metrics.conversions_value
                FROM campaign WHERE campaign.resource_name = '{$resource}' AND {$dates}") as $daily) {
                $date = $daily->getSegments()?->getDate();
                if ($daily->getCampaign()?->getResourceName() !== $resource || ! is_string($date)
                    || $date < $from->toDateString() || $date > $through->toDateString() || isset($days[$date])) {
                    throw new \RuntimeException('Google returned unexpected campaign reporting rows.');
                }
                $days[$date] = true;
                $metrics = $this->metrics($daily->getMetrics());
                foreach (array_keys($totals) as $key) {
                    $totals[$key] += $metrics[$key];
                }
            }
            // Google suppresses all-zero date rows. An API failure propagates;
            // only a successful scoped query can establish no reported activity.
            $result['metrics'] = $this->ratios($totals) + ['reported_day_rows' => count($days)];
            $result['status'] = 'observed';
            $phase = 'native_combinations';
            try {
                $combinations = [];
                foreach ($this->rows($customerId, "SELECT campaign.resource_name,
                    ai_max_search_term_ad_combination_view.search_term, ai_max_search_term_ad_combination_view.headline,
                    ai_max_search_term_ad_combination_view.landing_page, metrics.impressions, metrics.clicks,
                    metrics.cost_micros, metrics.conversions, metrics.conversions_value
                    FROM ai_max_search_term_ad_combination_view WHERE campaign.resource_name = '{$resource}'
                    AND {$dates} ORDER BY metrics.cost_micros DESC LIMIT 20") as $combination) {
                    if ($combination->getCampaign()?->getResourceName() !== $resource || ! $combination->hasAiMaxSearchTermAdCombinationView()) {
                        throw new \RuntimeException('Google returned an unscoped native combination.');
                    }
                    $view = $combination->getAiMaxSearchTermAdCombinationView();
                    if (! $view) {
                        throw new \RuntimeException('Google did not return the native combination fields.');
                    }
                    $combinations[] = ['search_term' => $this->displayText($view->getSearchTerm()),
                        'headline' => $this->displayText($view->getHeadline()), 'landing_page' => $this->displayUrl($view->getLandingPage()),
                        'metrics' => $this->ratios($this->metrics($combination->getMetrics()))];
                    if (count($combinations) >= 20) {
                        break;
                    }
                }
                $result['combinations'] = [...$result['combinations'], 'status' => $combinations ? 'observed' : 'no_reported_combinations', 'rows' => $combinations];
            } catch (\Throwable $e) {
                $this->unavailable($phase, $e);
                $result['status'] = 'partial';
                $result['combinations']['status'] = 'unavailable';
                $result['issues'][] = 'Native search-term/ad combinations are temporarily unavailable; campaign totals remain separately observed.';
            }
        } catch (\Throwable $e) {
            $this->unavailable($phase, $e);
            $result['status'] = 'unavailable';
            $result['metrics'] = null;
            $result['issues'][] = 'Fresh AI Max pilot performance could not be observed.';
        }

        return $result;
    }

    private function emptyMetrics(): array
    {
        return ['impressions' => 0, 'clicks' => 0, 'cost_micros' => 0, 'conversions' => 0.0, 'conversion_value_micros' => 0];
    }

    private function metrics(?Metrics $metrics): array
    {
        if (! $metrics || ! $metrics->hasImpressions() || ! $metrics->hasClicks() || ! $metrics->hasCostMicros()
            || ! $metrics->hasConversions() || ! $metrics->hasConversionsValue()) {
            throw new \RuntimeException('Google returned incomplete requested metrics.');
        }
        $value = $metrics->getConversionsValue();
        $conversions = $metrics->getConversions();
        if (! is_finite($value) || $value < 0 || $value > PHP_INT_MAX / 1_000_000
            || ! is_finite($conversions) || $conversions < 0 || $metrics->getCostMicros() < 0) {
            throw new \RuntimeException('Google returned invalid performance values.');
        }

        return ['impressions' => (int) $metrics->getImpressions(), 'clicks' => (int) $metrics->getClicks(),
            'cost_micros' => (int) $metrics->getCostMicros(), 'conversions' => $conversions,
            'conversion_value_micros' => (int) round($value * 1_000_000)];
    }

    private function ratios(array $metrics): array
    {
        return $metrics + ['average_cpc_micros' => $metrics['clicks'] > 0 ? (int) round($metrics['cost_micros'] / $metrics['clicks']) : null,
            'cpa_micros' => $metrics['conversions'] > 0 ? (int) round($metrics['cost_micros'] / $metrics['conversions']) : null];
    }

    private function displayText(string $text): string
    {
        if (preg_match('/[\w.+-]+@[\w.-]+\.[a-z]{2,}|\+?\d[\d\s().-]{7,}\d|(?:gclid|gbraid|wbraid|fbclid|user_id|transaction_id)\s*[=:]|\b[a-f0-9]{32,}\b/i', $text)) {
            return '[redacted]';
        }

        return mb_substr($text, 0, 300);
    }

    private function displayUrl(string $url): ?string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array($parts['scheme'] ?? null, ['https', 'http'], true) || empty($parts['host'])) {
            return null;
        }
        $path = $parts['path'] ?? '/';
        if ($this->displayText($path) === '[redacted]') {
            return null;
        }

        return $parts['scheme'].'://'.mb_strtolower($parts['host']).$path;
    }

    private function unavailable(string $phase, \Throwable $error): void
    {
        // This optional read-only reporter never changes model state, pauses
        // ads, or writes exception-dashboard records itself.
        Log::warning('AI Max performance observation unavailable.', ['phase' => $phase, 'error_class' => get_class($error)]);
    }

    /** @return iterable<\Google\Ads\GoogleAds\V26\Services\GoogleAdsRow> */
    protected function rows(string $customerId, string $query): iterable
    {
        $this->ensureClient();

        return $this->searchQuery($customerId, $query)->iterateAllElements();
    }
}
