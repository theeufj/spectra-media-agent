<?php

namespace App\Services\Agents\Optimization;

/** Reject ineffective bidding changes using campaign-scoped platform truth. */
class BiddingRecommendationGuard
{
    public static function keywordBidChange(array $recommendation): bool
    {
        $type = RecommendationScorer::canonicalType($recommendation['type'] ?? '');

        return ($type === 'BIDDING' && strtolower((string) ($recommendation['sub_type'] ?? '')) === 'keyword_cpc')
            || ($type === 'KEYWORDS' && in_array(strtolower((string) ($recommendation['direction'] ?? $recommendation['action'] ?? '')), ['increase', 'decrease'], true));
    }

    public static function rejectionReason(array $recommendation, array $state): ?string
    {
        $strategy = self::strategy((string) ($state['bidding_strategy_type'] ?? 'UNKNOWN'));

        if (self::keywordBidChange($recommendation)) {
            if ($strategy !== 'MANUAL_CPC') {
                return "Keyword CPC changes require confirmed MANUAL_CPC bidding; current strategy is {$strategy}. Automated bidding does not use keyword CPC overrides.";
            }

            $resource = $recommendation['keyword_resource'] ?? $recommendation['criterion_resource_name'] ?? null;
            $keyword = collect($state['keywords'] ?? [])->first(fn ($keyword) => ($keyword['resource'] ?? null) === $resource && $resource !== null);
            if (! $keyword) {
                return 'The keyword resource was not found in this campaign’s current Google configuration.';
            }

            $bid = $recommendation['suggested_value'] ?? null;
            if (! is_numeric($bid) || ! is_finite((float) $bid) || (float) $bid < 1
                || (float) $bid >= PHP_INT_MAX || (float) $bid !== (float) (int) $bid) {
                return 'Keyword CPC must be a positive whole number of micros.';
            }

            $currentBid = (int) ($keyword['cpc_bid_micros'] ?? 0);
            if ($currentBid === 0) {
                $currentBid = (int) ($keyword['ad_group_cpc_bid_micros'] ?? 0);
            }
            if ($currentBid === (int) $bid) {
                return 'The keyword already uses the proposed CPC, including its inherited ad group bid.';
            }

            return null;
        }

        if (RecommendationScorer::canonicalType($recommendation['type'] ?? '') === 'BIDDING') {
            $subType = strtolower((string) ($recommendation['sub_type'] ?? ''));
            // A target adjustment may retain the same strategy while changing
            // its CPA/ROAS. Only discard a redundant strategy-only proposal.
            if (in_array($subType, ['target_cpa', 'target_roas', 'cpc_ceiling'], true)) {
                return null;
            }

            $proposed = $recommendation['suggested_strategy'] ?? $recommendation['new_strategy']
                ?? $recommendation['bidding_strategy'] ?? $recommendation['strategy']
                ?? data_get($recommendation, 'parameters.bidding_strategy')
                ?? data_get($recommendation, 'parameters.strategy')
                ?? data_get($recommendation, 'parameters.new_strategy')
                ?? data_get($recommendation, 'parameters.strategy_name')
                ?? data_get($recommendation, 'params.bidding_strategy')
                ?? data_get($recommendation, 'params.strategy')
                ?? data_get($recommendation, 'params.new_strategy')
                ?? $recommendation['suggested_value'] ?? null;
            if (is_array($proposed)) {
                $proposed = $proposed['name'] ?? $proposed['type'] ?? null;
            }
            if (is_string($proposed) && ! in_array($strategy, ['UNKNOWN', 'UNSPECIFIED', 'INVALID'], true)
                && self::strategy($proposed) === $strategy) {
                return "The campaign already uses {$strategy}; changing to the same bidding strategy has no effect.";
            }
        }

        return null;
    }

    private static function strategy(string $value): string
    {
        $value = preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', '_', trim($value));
        $value = strtoupper(str_replace([' ', '-'], '_', $value));

        // Google Ads API calls Maximize Clicks TARGET_SPEND.
        return $value === 'MAXIMIZE_CLICKS' ? 'TARGET_SPEND' : $value;
    }
}
