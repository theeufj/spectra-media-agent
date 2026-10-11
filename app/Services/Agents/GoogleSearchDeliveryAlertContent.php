<?php

namespace App\Services\Agents;

/** Customer-facing copy; internal diagnosis codes are never email instructions. */
class GoogleSearchDeliveryAlertContent
{
    public static function holdReason(?string $reason): string
    {
        return match ($reason) {
            'approved_bounded_trial', 'active_trial' => 'Automatic changes are on hold to preserve the approved test. Keyword research and delivery checks continue.',
            'spend_safety_hold' => 'Spending is on hold for review. Research will not restart the campaign or increase its spending limits.',
            'campaign_not_active', 'google_campaign_not_enabled' => 'The campaign is inactive or paused. Automatic repairs will not restart it.',
            'automatic_management_not_enabled' => 'Automatic campaign management is not enabled. The diagnosis is available for review.',
            'approved_budget_unverified' => 'The approved daily budget must be verified before automatic repairs can run.',
            'existing_cpc_cap_required' => 'A maximum cost-per-click bid must be approved and verified before a keyword repair can run.',
            'forecasted_relevant_keywords_required' => 'The researched keywords have not demonstrated enough extra reach within the current bid and budget limits.',
            'search_inventory_unavailable' => 'Google Search is disabled for this campaign. Its network settings need review before keywords can restore traffic.',
            'approved_search_ad_required' => 'No enabled, approved Search ad is available. Ad approval needs review before adding keywords.',
            'search_keyword_eligibility_unavailable' => 'Google keyword eligibility could not be verified. A fresh check is needed before making changes.',
            'eligible_search_keyword_required' => 'No enabled keyword is currently eligible to serve. Review keyword eligibility before another repair.',
            'repair_attempt_limit' => 'The automatic repair attempt limit has been reached. Review the results before approving another change.',
            'partial_repair_unresolved' => 'Some keyword changes could not be confirmed. Review the live keywords before another repair is attempted.',
            'repair_did_not_restore_search_traffic' => 'The repair has not restored measured Google Search traffic. Another change needs review.',
            'configuration_changed_during_diagnosis', 'configuration_changed_during_verification' => 'Campaign settings changed during the check. The current settings and repair outcome need review.',
            'reach_evidence_unavailable', 'search_delivery_check_unavailable' => 'The current delivery diagnosis could not be verified. A later check or administrator review is required.',
            default => 'Automatic changes are on hold. Review the delivery report before making changes.',
        };
    }

    public static function forState(array $state): array
    {
        $unavailable = ($state['status'] ?? null) === 'unavailable' || ! empty($state['errors']);
        $attempted = ! empty($state['repair']['started_at']);
        $message = $unavailable
            ? 'We could not verify the current Google Search delivery diagnosis. This alert does not confirm that the campaign is receiving traffic.'
            : ($attempted ? 'A keyword repair was attempted, but traffic recovery has not been confirmed.' : 'Your Google Search campaign needs a delivery review.');
        $evidence = [];
        $measurement = $state['verification'] ?? $state['measurement'] ?? [];
        $hours = $measurement['complete_hours'] ?? null;
        $impressions = $measurement['impressions'] ?? null;
        $clicks = $measurement['clicks'] ?? null;
        if (is_numeric($hours) && $hours > 0 && is_numeric($impressions) && is_numeric($clicks)) {
            $evidence[] = ($unavailable ? 'Last available measured results' : 'Measured results').': '
                .number_format((int) $impressions).' impressions and '.number_format((int) $clicks)
                .' clicks across '.number_format((int) $hours).' complete reporting hours.';
        }
        $currency = $state['currency_code'] ?? null;
        if (is_numeric($hours) && $hours > 0 && isset($measurement['cost_micros'])
            && is_int($measurement['cost_micros']) && $measurement['cost_micros'] >= 0) {
            $evidence[] = ($unavailable ? 'Last available reported ad spend' : 'Reported ad spend for that period')
                .': '.self::money($measurement['cost_micros'], $currency).'.';
        }
        // A failed check must not present an earlier snapshot as today's verified settings.
        $limits = $state['verified_limits'] ?? [];
        if (! $unavailable && ! empty($state['checked_at']) && ($limits['checked_at'] ?? null) === $state['checked_at']) {
            if (is_int($limits['daily_budget_micros'] ?? null) && $limits['daily_budget_micros'] > 0) {
                $evidence[] = 'Daily budget at this check: '.self::money($limits['daily_budget_micros'], $currency).'/day.';
            }
            if (is_int($limits['cpc_bid_ceiling_micros'] ?? null) && $limits['cpc_bid_ceiling_micros'] > 0) {
                $evidence[] = 'Maximum cost-per-click bid at this check: '.self::money($limits['cpc_bid_ceiling_micros'], $currency).'.';
            }
        }
        $evidence[] = self::holdReason($state['blocked_reason'] ?? null);
        $issues = $unavailable ? ($state['errors'] ?? []) : ($state['diagnosis']['issues'] ?? []);
        $actions = match (true) {
            $unavailable => 'Ask your administrator to verify the latest Google Ads status and delivery evidence before approving changes.',
            $attempted => 'Ask your administrator to review the repair outcome and live keyword settings before another change.',
            in_array($state['blocked_reason'] ?? '', ['search_inventory_unavailable', 'approved_search_ad_required', 'search_keyword_eligibility_unavailable', 'eligible_search_keyword_required'], true) => 'Ask your administrator to review the reported serving issue before changing keywords or spending limits.',
            default => 'Ask your administrator to assess a different relevant keyword theme and compare bid-limit forecasts within your approved budget. Approve a specific change before it is applied.',
        };

        return ['message' => $message, 'evidence_lines' => $evidence, 'issues' => $issues,
            'action_required' => $actions, 'action_label' => 'Review delivery report'];
    }

    private static function money(int $micros, ?string $currency): string
    {
        // Format integer micros for display without storing or calculating balances as floats.
        $cents = intdiv($micros + 5_000, 10_000);
        $amount = number_format(intdiv($cents, 100)).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);

        return is_string($currency) && preg_match('/^[A-Z]{3}$/', $currency)
            ? $currency.' '.$amount : $amount.' in ad account currency';
    }
}
