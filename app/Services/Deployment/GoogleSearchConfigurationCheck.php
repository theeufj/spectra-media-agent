<?php

namespace App\Services\Deployment;

/** Compares the intended deployment with read-back from Google. No API mutations. */
class GoogleSearchConfigurationCheck
{
    public function compare(array $expected, array $actual): array
    {
        if (empty($expected['keywords']) || empty($expected['ads'])) {
            return ['The deployment has no complete verification baseline.'];
        }
        $issues = [];
        $campaign = $actual['campaign']['campaign'] ?? [];
        if (($campaign['advertisingChannelType'] ?? '') !== 'SEARCH') {
            $issues[] = 'The deployed campaign is not Search.';
        }
        if (($campaign['networkSettings'] ?? []) != ($expected['networks'] ?? [])) {
            // Protobuf omits false values when serializing.
            foreach ($expected['networks'] ?? [] as $key => $value) {
                if (($campaign['networkSettings'][$key] ?? false) !== $value) {
                    $issues[] = 'Search network settings differ from the intended campaign.';
                    break;
                }
            }
        }
        if (($campaign['geoTargetTypeSetting']['positiveGeoTargetType'] ?? '') !== ($expected['location_mode'] ?? 'PRESENCE')) {
            $issues[] = 'Location presence settings differ from the intended campaign.';
        }
        if (isset($expected['bidding']) && ($campaign['biddingStrategyType'] ?? '') !== $expected['bidding']) {
            $issues[] = 'The intended bidding strategy was not applied.';
        }
        if (isset($expected['budget_micros']) && (int) ($actual['campaign']['campaignBudget']['amountMicros'] ?? 0) !== (int) $expected['budget_micros']) {
            $issues[] = 'The deployed daily budget differs from the intended budget.';
        }
        $keywords = [];
        foreach ($actual['keywords'] ?? [] as $row) {
            $criterion = $row['adGroupCriterion'] ?? [];
            if (! ($criterion['negative'] ?? false)) {
                $keywords[] = $this->keyword($criterion['keyword']['text'] ?? '', $criterion['keyword']['matchType'] ?? '');
            }
        }
        $wanted = array_map(fn ($k) => $this->keyword($k['text'], $k['match_type']), $expected['keywords']);
        sort($keywords);
        sort($wanted);
        if ($keywords !== $wanted) {
            $issues[] = 'Deployed keywords or match types differ from the selected set.';
        }
        $locations = [];
        foreach ($actual['criteria'] ?? [] as $row) {
            $criterion = $row['campaignCriterion'] ?? [];
            if (! ($criterion['negative'] ?? false) && isset($criterion['location']['geoTargetConstant'])) {
                $locations[] = (int) basename($criterion['location']['geoTargetConstant']);
            }
        }
        $wantedLocations = $expected['locations'] ?? [];
        sort($locations);
        sort($wantedLocations);
        if ($locations !== $wantedLocations) {
            $issues[] = 'Deployed locations differ from the selected locations.';
        }
        foreach ($expected['ads'] as $ad) {
            $matched = false;
            foreach ($actual['ads'] ?? [] as $row) {
                $remote = $row['adGroupAd']['ad'] ?? [];
                $headlines = array_column($remote['responsiveSearchAd']['headlines'] ?? [], 'text');
                $descriptions = array_column($remote['responsiveSearchAd']['descriptions'] ?? [], 'text');
                if ($this->sameSet($ad['headlines'], $headlines) && $this->sameSet($ad['descriptions'], $descriptions)
                    && $this->sameSet($ad['final_urls'], $remote['finalUrls'] ?? [])
                    && ($row['adGroupAd']['policySummary']['approvalStatus'] ?? '') !== 'DISAPPROVED') {
                    $matched = true;
                }
            }
            if (! $matched) {
                $issues[] = 'A reviewed ad or its landing-page destination is missing, changed or disapproved.';
            }
        }
        $assets = array_column(array_column($actual['assets'] ?? [], 'campaignAsset'), 'asset');
        foreach ($expected['asset_resources'] ?? [] as $resource) {
            if (! in_array($resource, $assets, true)) {
                $issues[] = 'An intended ad extension was not linked to the campaign.';
            }
        }
        foreach ($actual['assets'] ?? [] as $row) {
            $kind = $row['campaignAsset']['fieldType'] ?? '';
            $details = $expected['verified_offer_details'][$row['campaignAsset']['asset'] ?? ''] ?? [];
            if (in_array($kind, ['PRICE', 'PROMOTION'], true)
                && ! in_array($row['campaignAsset']['asset'] ?? '', $expected['verified_offer_assets'] ?? [], true)) {
                $issues[] = 'A price or promotion asset lacks verified offer evidence.';
            }
            if ($kind === 'PRICE') {
                $prices = array_map(fn ($offer) => [(string) ($offer['header'] ?? ''), (int) ($offer['price']['amountMicros'] ?? 0),
                    (string) ($offer['price']['currencyCode'] ?? ''), (string) ($offer['finalUrl'] ?? '')], $row['asset']['priceAsset']['priceOfferings'] ?? []);
                $wantedPrices = array_map(fn ($offer) => [$offer['header'], (int) $offer['price_micros'], $offer['currency_code'], $offer['final_url']], $details['offerings'] ?? []);
                if ($wantedPrices === [] || ! $this->sameSet($prices, $wantedPrices)) {
                    $issues[] = 'An advertised price, currency or product differs from verified offer data.';
                }
            }
            if ($kind === 'PROMOTION') {
                $promotion = $row['asset']['promotionAsset'] ?? [];
                $offer = $details['offer'] ?? [];
                if (! $offer || ($promotion['promotionTarget'] ?? '') !== ($offer['promotion_target'] ?? '')
                    || (int) ($promotion['percentOff'] ?? 0) !== 0
                    || (int) ($promotion['moneyAmountOff']['amountMicros'] ?? 0) !== ($offer['money_amount_off']['amount_micros'] ?? null)
                    || ($promotion['moneyAmountOff']['currencyCode'] ?? '') !== ($offer['money_amount_off']['currency_code'] ?? null)
                    || ! in_array($offer['final_url'] ?? '', $row['asset']['finalUrls'] ?? [], true)) {
                    $issues[] = 'An advertised promotion differs from the verified catalogue sale.';
                }
            }
            if ($kind === 'SITELINK') {
                foreach ($row['asset']['finalUrls'] ?? [] as $url) {
                    if (! in_array($url, $expected['sitelink_urls'] ?? [], true)) {
                        $issues[] = 'A sitelink destination differs from the verified pages.';
                    }
                }
            }
        }
        if ($intent = $expected['conversion_goal'] ?? null) {
            $issues = array_merge($issues, $this->conversionGoalIssues($intent, $actual));
        } elseif ($goal = $expected['conversion_category'] ?? null) {
            $primaries = array_values(array_filter(array_column($actual['conversion_actions'] ?? [], 'conversionAction'),
                fn ($action) => ($action['status'] ?? '') === 'ENABLED' && ($action['category'] ?? '') === $goal && ($action['primaryForGoal'] ?? false)));
            if (count($primaries) !== 1 || empty($primaries[0]['origin']) || empty($primaries[0]['resourceName'])) {
                $issues[] = 'The intended conversion category needs an explicit enabled action and origin to verify its bidding goal.';
            } else {
                $issues = array_merge($issues, $this->conversionGoalIssues(['mode' => 'category', 'category' => $goal,
                    'origin' => $primaries[0]['origin'], 'action_resource' => $primaries[0]['resourceName']], $actual));
            }
        } else {
            $issues[] = 'The campaign has no explicit conversion goal to verify.';
        }

        return array_values(array_unique($issues));
    }

    public function conversionGoalIssues(array $intent, array $actual): array
    {
        if (empty($actual['conversion_goal_config']['conversionGoalCampaignConfig'])) {
            return ['Campaign conversion goal configuration is unavailable for verification.'];
        }
        if (! in_array($intent['mode'] ?? '', ['category', 'custom'], true)) {
            return ['The intended conversion goal mode is unavailable for verification.'];
        }
        $custom = $actual['conversion_goal_config']['conversionGoalCampaignConfig']['customConversionGoal'] ?? null;
        $goals = array_column($actual['goals'] ?? [], 'campaignConversionGoal');
        $actions = array_column($actual['conversion_actions'] ?? [], 'conversionAction');
        $action = collect($actions)->firstWhere('resourceName', $intent['action_resource'] ?? null);
        if (! $action || ($action['status'] ?? '') !== 'ENABLED' || ($action['category'] ?? null) !== ($intent['category'] ?? null)
            || ($action['origin'] ?? null) !== ($intent['origin'] ?? null)) {
            return ['The intended conversion action is missing, disabled or has a different category or origin.'];
        }
        if ($intent['mode'] === 'custom') {
            $definition = collect(array_column($actual['custom_conversion_goals'] ?? [], 'customConversionGoal'))->firstWhere('resourceName', $custom);
            if (! $custom || $custom !== ($intent['custom_goal'] ?? null) || ($definition['status'] ?? '') !== 'ENABLED'
                || ($definition['conversionActions'] ?? []) !== [$intent['action_resource']]) {
                return ['The campaign custom goal does not match the selected conversion action.'];
            }
            if (collect($goals)->contains(fn ($goal) => $goal['biddable'] ?? false)) {
                return ['Additional campaign conversion goals are being used alongside the intended custom goal.'];
            }

            return [];
        }
        if ($custom) {
            return ['An unexpected custom conversion goal is being used for campaign bidding.'];
        }
        if (! ($action['primaryForGoal'] ?? false)) {
            return ['The intended conversion action is secondary and is not selected by a campaign custom goal.'];
        }
        if (count(array_filter($actions, fn ($other) => ($other['status'] ?? '') === 'ENABLED'
            && ($other['primaryForGoal'] ?? false) && ($other['category'] ?? '') === $intent['category']
            && ($other['origin'] ?? '') === $intent['origin'])) !== 1) {
            return ['Several primary conversion actions share the intended category and origin. Select the intended action with a campaign custom goal.'];
        }
        $matching = fn ($goal) => ($goal['category'] ?? '') === $intent['category'] && ($goal['origin'] ?? '') === $intent['origin'];
        if (! collect($goals)->contains(fn ($goal) => $matching($goal) && ($goal['biddable'] ?? false))) {
            return ['The intended conversion category and origin are not enabled for campaign bidding.'];
        }
        if (collect($goals)->contains(fn ($goal) => ! $matching($goal) && ($goal['biddable'] ?? false))) {
            return ['An additional conversion category or origin is being used for bidding.'];
        }

        return [];
    }

    private function keyword(string $text, string $match): string
    {
        return mb_strtolower(trim($text)).'|'.strtoupper($match);
    }

    private function sameSet(array $a, array $b): bool
    {
        sort($a);
        sort($b);

        return $a === $b;
    }
}
