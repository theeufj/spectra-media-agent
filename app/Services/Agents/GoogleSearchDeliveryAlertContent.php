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
            'ad_rank_diagnosis_pending' => 'A complete reporting period is needed to assess auction competitiveness after the last change.',
            'ad_rank_evidence_unavailable' => 'Current auction evidence could not be verified. A fresh check is needed before an automatic ad repair.',
            'ad_rank_creative_permission_required' => 'Ad-copy repairs need authorization for this controlled test. Its spending limits remain in place.',
            'ad_rank_repair_attempt_limit' => 'The ad repair attempt limit has been reached. Review actual traffic before another change.',
            'ad_rank_recovery_not_confirmed' => 'The ad repair has not yet restored useful traffic or confirmed improved auction competitiveness.',
            'ad_rank_repair_held' => 'The ad repair is on hold. Review its evidence and the approved spending limits.',
            'awaiting_policy_review' => 'Google is reviewing the replacement ad and assets. The working approved ad continues serving.',
            'observing_delivery' => 'Google approved the replacement. Fresh auction and click evidence is needed to confirm its effect.',
            'weekly_creative_repair_limit' => 'The weekly ad repair limit has been reached. Review the measured outcome before another change.',
            'rank_improvement_not_verified' => 'The replacement has not demonstrated improved auction competitiveness and useful clicks within the observation period.',
            'replacement_policy_not_approved', 'replacement_asset_policy_not_approved' => 'Google has not approved the complete replacement. Review its policy status before activating the repair.',
            'creative_repair_or_readback_failed' => 'Some ad repair changes could not be confirmed. Review the recorded live status before another attempt.',
            'creative_repair_trial_limits_or_remaining_spend_unverified' => 'The approved trial limits or remaining spend could not be verified. The ad repair is on hold.',
            'staging_assets' => 'Preparing paused replacement assets. The working approved ad continues serving.',
            'interrupted_asset_staging' => 'An asset submission could not be confirmed. The working ad has been preserved; review the recorded result before another attempt.',
            'creative_staging_interrupted' => 'Replacement preparation was interrupted. The working ad has been preserved; review the recorded result before another attempt.',
            'replacement_copy_review_failed' => 'The proposed replacement did not pass ad review. Review its feedback before another attempt; the working ad has been preserved.',
            'replacement_asset_evidence_review_failed' => 'The proposed assets did not pass the website evidence review. The working ad has been preserved.',
            'google_api_quota_cooldown' => 'Google temporarily limited API requests. The saved repair will resume after the cooldown; spending limits remain in place.',
            'google_read_quota_after_creative_write' => 'Google limited verification after a creative change. Review the recorded status before another change.',
            'activating' => 'Google approved the replacements. Their activation is being verified in batches within the existing spending limits.',
            default => 'Automatic changes are on hold. Review the delivery report before making changes.',
        };
    }

    public static function forState(array $state): array
    {
        $unavailable = ($state['status'] ?? null) === 'unavailable' || ! empty($state['errors']);
        $attempted = ! empty($state['repair']['started_at']);
        $rankRepair = $state['ad_rank']['repair'] ?? [];
        $quotaCooldown = ($rankRepair['status'] ?? null) === 'verifying'
            && ($rankRepair['metadata']['reason'] ?? null) === 'google_api_quota_cooldown';
        $rankIncident = ($state['ad_rank']['diagnosis']['status'] ?? null) === 'rank_limited'
            || ! empty($rankRepair['started_at']) || in_array($rankRepair['status'] ?? null, ['verifying', 'needs_review'], true);
        $message = $unavailable
            ? 'We could not verify the current Google Search delivery diagnosis. This alert does not confirm that the campaign is receiving traffic.'
            : ($attempted ? 'A keyword repair was attempted, but traffic recovery has not been confirmed.' : 'Your Google Search campaign needs a delivery review.');
        if (! $unavailable && $rankIncident) {
            $message = $quotaCooldown ? self::holdReason('google_api_quota_cooldown')
                : (! empty($rankRepair['started_at']) || ($rankRepair['status'] ?? null) === 'verifying'
                ? 'An ad repair is being verified. Improved auction competitiveness and useful traffic have not yet been confirmed.'
                : 'Your Google Search campaign needs an auction competitiveness review.');
        }
        $evidence = [];
        $measurement = $state['verification'] ?? $state['measurement'] ?? [];
        $hours = $measurement['complete_hours'] ?? null;
        $impressions = $measurement['impressions'] ?? null;
        $clicks = $measurement['clicks'] ?? null;
        if (is_numeric($hours) && $hours > 0 && is_numeric($impressions) && is_numeric($clicks)) {
            $evidence[] = ($unavailable || $quotaCooldown ? 'Last available measured results' : 'Measured results').': '
                .number_format((int) $impressions).' impressions and '.number_format((int) $clicks)
                .' clicks across '.number_format((int) $hours).' complete reporting hours.';
        }
        $currency = $state['currency_code'] ?? null;
        if (is_numeric($hours) && $hours > 0 && isset($measurement['cost_micros'])
            && is_int($measurement['cost_micros']) && $measurement['cost_micros'] >= 0) {
            $evidence[] = ($unavailable || $quotaCooldown ? 'Last available reported ad spend' : 'Reported ad spend for that period')
                .': '.self::money($measurement['cost_micros'], $currency).'.';
        }
        // A failed check must not present an earlier snapshot as today's verified settings.
        $limits = $state['verified_limits'] ?? [];
        if (! $unavailable && ! $quotaCooldown && ! empty($state['checked_at']) && ($limits['checked_at'] ?? null) === $state['checked_at']) {
            if (is_int($limits['daily_budget_micros'] ?? null) && $limits['daily_budget_micros'] > 0) {
                $evidence[] = 'Daily budget at this check: '.self::money($limits['daily_budget_micros'], $currency).'/day.';
            }
            if (is_int($limits['cpc_bid_ceiling_micros'] ?? null) && $limits['cpc_bid_ceiling_micros'] > 0) {
                $evidence[] = 'Maximum cost-per-click bid at this check: '.self::money($limits['cpc_bid_ceiling_micros'], $currency).'.';
            }
        }
        $rankEvidence = $state['ad_rank']['diagnosis']['evidence'] ?? [];
        if (! $unavailable && ! $quotaCooldown && ($rankEvidence['status'] ?? null) === 'verified'
            && is_numeric($rankEvidence['lost_to_rank'] ?? null) && $rankEvidence['lost_to_rank'] >= 0 && $rankEvidence['lost_to_rank'] <= 1) {
            $loss = (float) $rankEvidence['lost_to_rank'] === 0.9001 ? 'over 90%' : round($rankEvidence['lost_to_rank'] * 100, 1).'%';
            $evidence[] = 'Google estimates '.$loss.' of available Search impressions were lost to Ad Rank in the measured account-day window.';
        }
        if (! empty($state['blocked_reason'])) {
            $evidence[] = self::holdReason($state['blocked_reason']);
        }
        if ($rankIncident) {
            $evidence[] = 'Ad copy and asset improvements do not by themselves prove improved Ad Rank. The approved spending limits remain in place.';
        }
        $issues = $unavailable ? ($state['errors'] ?? []) : ($state['diagnosis']['issues'] ?? []);
        $actions = match (true) {
            $quotaCooldown => 'No approval is needed for this temporary API cooldown. The agent will check again and verify the saved repair within the existing spending limits.',
            $unavailable => 'Ask your administrator to verify the latest Google Ads status and delivery evidence before approving changes.',
            $rankIncident => 'Review the current ad relevance, landing page and verified repair outcome. Assess fresh auction and click evidence before another change or any increase to spending limits.',
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
