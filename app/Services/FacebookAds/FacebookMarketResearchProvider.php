<?php

namespace App\Services\FacebookAds;

use App\Models\Campaign;
use App\Models\Strategy;
use App\Services\Campaigns\CampaignMarketResearchProvider;
use App\Services\GoogleAds\GeoTargets;
use Illuminate\Support\Facades\Http;

class FacebookMarketResearchProvider implements CampaignMarketResearchProvider
{
    public function supports(Strategy $strategy): bool
    {
        return (bool) preg_match('/\b(facebook|meta)\b/i', $strategy->platform);
    }

    public function propose(Campaign $campaign, Strategy $strategy, array $businessEvidence, array $inputs): array
    {
        $customer = $campaign->customer;
        $evidence = ['coverage' => 'platform_cost_and_reach_unverified', 'business_research' => $businessEvidence];
        $unverified = static fn (string $reason): array => ['status' => 'needs_review', 'evidence' => $evidence, 'reasons' => [$reason]];
        $accountId = preg_replace('/^act_/', '', (string) $customer?->facebook_ads_account_id);
        $token = config('services.facebook.system_user_token');
        $version = config('services.facebook.graph_version');
        if (! $customer?->facebook_bm_owned || ! ctype_digit($accountId ?? '') || ! $token || ! $version) {
            return $unverified('Link a Meta Business ad account to this workspace before checking its audience and budget.');
        }

        $locations = $campaign->geographic_targeting ?: $strategy->targetingConfig?->geo_locations;
        $countries = $this->countries($locations ?: [$customer->country]);
        if ($countries === []) {
            return $unverified('Select a supported country for the Meta audience estimate.');
        }

        $client = Http::withToken($token)->connectTimeout(3)->timeout(10);
        $accountUrl = "https://graph.facebook.com/{$version}/act_{$accountId}";
        $account = $client->get($accountUrl, ['fields' => 'id,account_status,currency,timezone_name']);
        $currency = $account->json('currency');
        if (! $account->successful() || $account->json('id') !== 'act_'.$accountId || $account->json('account_status') !== 1
            || ! is_string($currency) || $currency !== strtoupper((string) $customer->currency_code)) {
            return $unverified('Meta could not verify an active ad account in the workspace currency.');
        }

        // The execution service bills impressions. Use Meta’s matching minimum and
        // the app’s existing minimum without increasing the proposed allocation.
        $minimum = $client->get($accountUrl.'/minimum_budgets');
        $currencyMinimum = collect($minimum->json('data', []))->firstWhere('currency', $currency);
        $minimumCents = $currencyMinimum['min_daily_budget_imp'] ?? null;
        if (! $minimum->successful() || ! is_int($minimumCents) || $minimumCents <= 0) {
            return $unverified('Meta did not return a valid minimum daily budget for this account.');
        }

        $audience = $client->get($accountUrl.'/delivery_estimate', [
            'targeting_spec' => json_encode(['geo_locations' => ['countries' => $countries]], JSON_THROW_ON_ERROR),
            'optimization_goal' => 'LINK_CLICKS',
        ]);
        $lower = $audience->json('data.0.estimate_mau_lower_bound');
        $upper = $audience->json('data.0.estimate_mau_upper_bound');
        if (! $audience->successful() || $audience->json('data.0.estimate_ready') !== true
            || ! is_int($lower) || ! is_int($upper) || $lower < 0 || $upper <= 0 || $lower > $upper) {
            return $unverified('Meta has not returned an audience estimate for these countries.');
        }

        $minimumMicros = max(500, $minimumCents) * 10_000;
        $budget = $inputs['daily_budget_micros'] ?? null;
        $valid = is_int($budget) && $budget >= $minimumMicros && $budget <= 10_000_000_000;

        return [
            'status' => $valid ? 'ready' : 'needs_review',
            'currency_code' => $currency,
            'time_zone' => $account->json('timezone_name'),
            'evidence' => [
                ...$evidence,
                'coverage' => 'meta_audience_and_minimum_budget',
                'meta' => ['countries' => $countries, 'monthly_audience_lower_bound' => $lower, 'monthly_audience_upper_bound' => $upper,
                    'minimum_daily_budget_micros' => $minimumMicros],
            ],
            'reasons' => $valid ? [] : ['Research a daily allocation at or above the minimum shown for this Meta placement.'],
            'caveats' => ['Meta estimates the broad monthly audience in these countries. This is not a forecast of clicks, ad spend or conversions.'],
        ];
    }

    /** @return list<string> */
    private function countries(array $locations): array
    {
        $countries = [];
        foreach ($locations['countries'] ?? $locations as $location) {
            $country = is_array($location) ? ($location['country_code'] ?? $location['country'] ?? null) : $location;
            if (! is_string($country)) {
                return [];
            }
            $country = GeoTargets::countryCodeFor($country) ?? trim($country);
            if (! preg_match('/^[a-z]{2}$/iD', $country)) {
                return [];
            }
            $countries[strtoupper($country)] = true;
        }

        return array_keys($countries);
    }
}
