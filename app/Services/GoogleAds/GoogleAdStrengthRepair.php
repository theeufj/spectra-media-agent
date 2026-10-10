<?php

namespace App\Services\GoogleAds;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Notification;
use App\Notifications\CriticalAgentAlert;
use Google\Ads\GoogleAds\V22\Enums\AdStrengthEnum\AdStrength;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** Durable, per-ad repair state shared by hourly checks and delayed verification. */
class GoogleAdStrengthRepair
{
    public const MAX_REPAIRS = 3;

    public const MAX_VERIFICATION_READS = 6;

    /** Resolve only the signed strategy which owns this Google campaign target. */
    public function strategyForCampaign(Campaign $campaign): ?\App\Models\Strategy
    {
        $target = basename((string) $campaign->googleAdsResourceName());
        foreach ($campaign->strategies()->where(fn ($q) => $q->where('platform', 'like', '%google%')->orWhere('platform', 'like', '%Google%'))->orderBy('id')->get() as $strategy) {
            $resource = $strategy->reusableGoogleCampaignId();
            if ($resource && basename($resource) === $target) {
                return $strategy;
            }
        }

        return null;
    }

    /** All automated extension paths must also observe Google's current enabled state. */
    public function campaignMutationBlocked(Campaign $campaign, \App\Models\Strategy $strategy, ?\App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration $reader = null): ?string
    {
        if ($blocked = $this->mutationBlocked($campaign, $strategy)) {
            return $blocked;
        }
        $customer = $campaign->customer;
        $reader ??= app(\App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration::class, ['customer' => $customer]);
        $status = $reader->campaignStatus($customer->cleanGoogleCustomerId(), $campaign->googleAdsResourceName());

        return $status === 'ENABLED' ? null : 'google_campaign_'.strtolower($status);
    }

    /** Re-read local approval and money holds before every repair write. */
    public function mutationBlocked(Campaign $campaign, \App\Models\Strategy $strategy): ?string
    {
        $fresh = Campaign::with('customer')->find($campaign->id);
        $customer = $fresh?->customer;
        if (! $fresh || ! $customer) {
            return 'customer_or_campaign_missing';
        }
        $approved = $fresh->strategies()->find($strategy->id);
        // Horizon jobs can outlive a flag change made by another process.
        \Laravel\Pennant\Feature::flushCache();

        return match (true) {
            $customer->is_sandbox => 'sandbox',
            ! \App\Models\EnabledPlatform::isEnabled('google') => 'google_disabled',
            ! $customer->google_ads_customer_id => 'missing_google_account',
            ! str_starts_with((string) $campaign->googleAdsResourceName(), 'customers/'.$customer->cleanGoogleCustomerId().'/') => 'google_account_changed',
            in_array($customer->google_ads_link_status, ['pending', 'refused', 'cancelled', 'failed', 'revoked'], true) => 'google_management_unavailable',
            $fresh->status !== \App\Enums\CampaignStatus::Active => 'campaign_not_active',
            strtoupper((string) $fresh->platform_status) === 'PAUSED' => 'google_campaign_paused',
            $fresh->hasPassedEndDate() => 'campaign_ended',
            \App\Services\Campaigns\CampaignSpendGuardrails::automaticChangesSuspended($fresh) => \App\Services\Campaigns\CampaignSpendGuardrails::activeTrial($fresh) ? 'approved_bounded_trial' : 'spend_safety_hold',
            $customer->service_type === 'setup_only' => 'setup_only',
            ! $approved?->signed_off_at => 'strategy_not_approved',
            basename((string) $approved->reusableGoogleCampaignId()) !== basename((string) $campaign->googleAdsResourceName()) => 'google_strategy_target_changed',
            ! \Laravel\Pennant\Feature::for($customer)->active(\App\Features\AutoHealing::class) => 'auto_healing_disabled',
            \App\Models\Setting::get('managed_billing_enabled', true) && ! $customer->isSelfFundedAds()
                && ! $customer->adSpendCredit()->first()?->canRunCampaigns() => 'ad_spend_unfunded',
            default => null,
        };
    }

    public static function copy(array $headlines, array $descriptions): array
    {
        $normalize = function (array $texts, int $min, int $max, int $length): array {
            $result = [];
            $seen = [];
            foreach ($texts as $text) {
                if (! is_string($text) || ! mb_check_encoding($text, 'UTF-8')) {
                    throw new \InvalidArgumentException('RSA assets must be valid UTF-8 text.');
                }
                $text = trim($text);
                if ($text === '' || mb_strlen($text) > $length) {
                    throw new \InvalidArgumentException("RSA assets must contain 1–{$length} characters.");
                }
                $key = mb_strtolower($text);
                if (! isset($seen[$key])) {
                    $result[] = $text;
                    $seen[$key] = true;
                }
            }
            if (count($result) < $min || count($result) > $max) {
                throw new \InvalidArgumentException("RSA requires {$min}–{$max} distinct assets.");
            }

            return $result;
        };

        return ['headlines' => $normalize($headlines, 3, 15, 30), 'descriptions' => $normalize($descriptions, 2, 4, 90)];
    }

    public static function ad(array $row): array
    {
        $ad = $row['adGroupAd'] ?? [];
        $rsa = $ad['ad']['responsiveSearchAd'] ?? null;
        $strength = $ad['adStrength'] ?? 'UNKNOWN';
        if (is_int($strength)) {
            $strength = AdStrength::name($strength);
        }

        return [
            'resource_name' => $ad['resourceName'] ?? '',
            'ad_id' => $ad['ad']['id'] ?? '',
            'status' => $ad['status'] ?? 'UNKNOWN',
            'ad_group_status' => $row['adGroup']['status'] ?? 'UNKNOWN',
            'campaign_status' => $row['campaign']['status'] ?? 'UNKNOWN',
            'is_rsa' => is_array($rsa),
            'ad_strength' => in_array($strength, ['POOR', 'AVERAGE', 'GOOD', 'EXCELLENT', 'PENDING', 'NO_ADS'], true) ? $strength : 'UNKNOWN',
            'approval_status' => $ad['policySummary']['approvalStatus'] ?? 'UNKNOWN',
            'review_status' => $ad['policySummary']['reviewStatus'] ?? 'UNKNOWN',
            'action_items' => $ad['actionItems'] ?? [],
            'asset_policy_summaries' => array_values(array_filter(array_column(array_merge($rsa['headlines'] ?? [], $rsa['descriptions'] ?? []), 'policySummaryInfo'), 'is_array')),
            'headlines' => array_column($rsa['headlines'] ?? [], 'text'),
            'descriptions' => array_column($rsa['descriptions'] ?? [], 'text'),
        ];
    }

    public static function reviewed(array $ad): bool
    {
        if (! in_array($ad['approval_status'], ['APPROVED', 'APPROVED_LIMITED'], true)
            || ! in_array($ad['review_status'], ['REVIEWED', 'ELIGIBLE_MAY_SERVE'], true)) {
            return false;
        }
        // Google can retain the old top-level approval while new text assets
        // are still under review. Do not publish that as a verified repair.
        foreach ($ad['asset_policy_summaries'] ?? [] as $policy) {
            if (! in_array($policy['approvalStatus'] ?? 'UNKNOWN', ['APPROVED', 'APPROVED_LIMITED'], true)
                || ! in_array($policy['reviewStatus'] ?? 'UNKNOWN', ['REVIEWED', 'ELIGIBLE_MAY_SERVE'], true)) {
                return false;
            }
        }

        return true;
    }

    public static function healthy(array $ad): bool
    {
        return self::reviewed($ad) && in_array($ad['ad_strength'], ['GOOD', 'EXCELLENT'], true);
    }

    /** Explicit provider signals distinguish waiting from missing or rejected evidence. */
    public static function statusReason(array $ad): ?string
    {
        $policies = array_merge([['approvalStatus' => $ad['approval_status'] ?? 'UNKNOWN',
            'reviewStatus' => $ad['review_status'] ?? 'UNKNOWN']], $ad['asset_policy_summaries'] ?? []);
        if (in_array('DISAPPROVED', array_column($policies, 'approvalStatus'), true)) {
            return 'policy_disapproved';
        }
        if (in_array('AREA_OF_INTEREST_ONLY', array_column($policies, 'approvalStatus'), true)) {
            return 'policy_targeting_restricted';
        }
        $reviewPending = false;
        foreach ($policies as $policy) {
            $pending = in_array($policy['reviewStatus'] ?? 'UNKNOWN', ['REVIEW_IN_PROGRESS', 'UNDER_APPEAL'], true);
            // A new ad may not have an approval decision yet. An explicit
            // review signal proves waiting; unknown review does not.
            $allowedApprovals = $pending ? ['APPROVED', 'APPROVED_LIMITED', 'UNKNOWN', 'UNSPECIFIED'] : ['APPROVED', 'APPROVED_LIMITED'];
            if (! in_array($policy['approvalStatus'] ?? 'UNKNOWN', $allowedApprovals, true)
                || ! in_array($policy['reviewStatus'] ?? 'UNKNOWN', ['REVIEWED', 'ELIGIBLE_MAY_SERVE', 'REVIEW_IN_PROGRESS', 'UNDER_APPEAL'], true)) {
                return 'ad_status_unknown';
            }
            $reviewPending = $reviewPending || $pending;
        }
        if (! in_array($ad['ad_strength'] ?? 'UNKNOWN', ['POOR', 'AVERAGE', 'GOOD', 'EXCELLENT', 'PENDING'], true)) {
            return 'ad_status_unknown';
        }
        if ($reviewPending) {
            return 'review_pending';
        }

        return $ad['ad_strength'] === 'PENDING' ? 'strength_pending' : null;
    }

    /** A stable identity for the exact observed copy, independent of review/rating changes. */
    public static function observation(array $ad): array
    {
        return ['ad_resource' => $ad['resource_name'], 'observed_at' => now()->toIso8601String(),
            'copy_fingerprint' => self::copyFingerprint($ad), 'copy_identity_source' => 'observed',
            'evidence' => ['ad_strength' => $ad['ad_strength'], 'approval_status' => $ad['approval_status'],
                'review_status' => $ad['review_status'], 'asset_policy_summaries' => $ad['asset_policy_summaries'] ?? []]];
    }

    public static function copyFingerprint(array $ad): string
    {
        return hash('sha256', json_encode([$ad['resource_name'], $ad['headlines'], $ad['descriptions']], JSON_THROW_ON_ERROR));
    }

    public function latest(Campaign $campaign, string $resource): ?AgentActivity
    {
        return AgentActivity::where('campaign_id', $campaign->id)
            ->whereIn('action', ['ad_strength_repair_attempt', 'ad_copy_update_submitted', 'ad_copy_update_verified', 'ad_strength_improved'])
            ->where('details->ad_resource', $resource)->latest('id')->first();
    }

    public function attemptCount(Campaign $campaign, string $resource): int
    {
        return AgentActivity::where('campaign_id', $campaign->id)
            ->whereIn('action', ['ad_strength_repair_attempt', 'ad_copy_update_submitted', 'ad_copy_update_verified', 'ad_strength_improved'])
            ->where('status', '!=', 'skipped')->where('details->ad_resource', $resource)->where('created_at', '>=', now()->subDays(7))->count();
    }

    public function start(Campaign $campaign, array $ad): AgentActivity
    {
        return DB::transaction(function () use ($campaign, $ad) {
            Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();

            return AgentActivity::record('quality_score', 'ad_strength_repair_attempt', 'Preparing a reviewed RSA repair.',
                $campaign->customer_id, $campaign->id, [
                    'ad_resource' => $ad['resource_name'], 'strength_before' => $ad['ad_strength'],
                    'action_items' => $ad['action_items'], 'started_at' => now()->toIso8601String(),
                ], 'running');
        });
    }

    public function failedAttempt(Campaign $campaign, AgentActivity $attempt, string $reason): void
    {
        $attempt->update(['status' => 'needs_review', 'description' => $reason, 'details' => array_merge($attempt->details ?? [], [
            'reason' => 'repair_failed', 'error' => $reason, 'retry_after' => now()->addHour()->toIso8601String(),
        ])]);
        if ($this->attemptCount($campaign, $attempt->details['ad_resource']) >= self::MAX_REPAIRS) {
            $this->escalate($campaign, $attempt->details['ad_resource'], $reason);
        }
    }

    public function submitted(AgentActivity $attempt, array $copy): void
    {
        $attempt->update(['action' => 'ad_copy_update_submitted', 'status' => 'pending',
            'description' => 'Submitted reviewed RSA copy; awaiting Google review and strength verification.',
            'details' => array_merge($attempt->details ?? [], $copy, [
                'submitted_at' => now()->toIso8601String(), 'verification_reads' => 0,
                'next_verification_at' => now()->addHour()->toIso8601String(), 'reason' => 'verification_pending',
            ])]);
    }

    /** Only a matching, reviewed ad with a higher rating completes a repair. */
    public function verify(Campaign $campaign, AgentActivity $attempt, ?array $ad): array
    {
        $result = DB::transaction(function () use ($campaign, $attempt, $ad) {
            Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            $locked = AgentActivity::whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $details = $locked->details ?? [];
            if ($this->latest($campaign, $details['ad_resource'])?->id !== $locked->id) {
                return ['status' => 'superseded', 'reason' => 'newer_repair', 'copy_matches' => false];
            }
            if (! isset($details['submitted_at'])) {
                return ['status' => $locked->status, 'reason' => $details['reason'] ?? 'not_submitted'];
            }
            $matched = $ad !== null && $this->sameCopy($ad, $details);
            $before = AdStrength::value($details['strength_before'] ?? 'UNKNOWN');
            $after = AdStrength::value($ad['ad_strength'] ?? 'UNKNOWN');
            if ($matched && self::reviewed($ad) && (($details['review']['overall_status'] ?? null) === 'approved' || ($details['legacy_reviewed_job'] ?? false))) {
                $this->syncAdBaseline($campaign, $details);
            }
            $improved = $matched && self::reviewed($ad) && $after > $before && in_array($ad['ad_strength'], ['AVERAGE', 'GOOD', 'EXCELLENT'], true);
            $details = array_merge($details, ['copy_matches' => $matched, 'strength_after' => $ad['ad_strength'] ?? 'UNKNOWN',
                'approval_status' => $ad['approval_status'] ?? 'UNKNOWN', 'review_status' => $ad['review_status'] ?? 'UNKNOWN',
                'asset_policy_summaries' => $ad['asset_policy_summaries'] ?? []]);
            if ($improved) {
                $locked->update(['action' => 'ad_strength_improved', 'status' => 'completed',
                    'description' => 'Google confirmed matching, approved copy and an improved ad-strength rating.',
                    'details' => array_merge($details, ['reason' => 'improved', 'verified_at' => now()->toIso8601String()])]);

                return ['status' => 'completed', 'reason' => 'improved', 'copy_matches' => true];
            }
            if ($locked->status !== 'pending') {
                // A previously unknown/missing review can recover on a later read.
                // Keep retryable uncertainty distinct from an external copy edit.
                if ($matched && self::reviewed($ad) && in_array($ad['ad_strength'], ['POOR', 'AVERAGE'], true)
                    && in_array($details['reason'] ?? '', ['verification_failed', 'ad_missing', 'review_pending', 'strength_pending', 'policy_disapproved', 'policy_targeting_restricted', 'ad_status_unknown'], true)) {
                    $details = array_merge($details, ['reason' => 'still_weak', 'retry_after' => now()->addHour()->toIso8601String()]);
                    $locked->update(['details' => $details]);
                }

                return ['status' => $locked->status, 'reason' => $details['reason'] ?? 'unresolved', 'copy_matches' => $matched];
            }
            $reason = $ad === null ? 'ad_missing' : (! $matched ? 'copy_changed' : (self::statusReason($ad) ?? 'still_weak'));
            // A scheduled waiting period must not hide a new rejection, lost
            // ad, changed copy, or unknown provider state observed meanwhile.
            if (Carbon::parse($details['next_verification_at'] ?? now())->isFuture()
                && in_array($reason, ['review_pending', 'strength_pending', 'still_weak'], true)) {
                return ['status' => 'pending', 'reason' => 'verification_pending', 'copy_matches' => $matched];
            }
            $reads = (int) ($details['verification_reads'] ?? 0) + 1;
            $terminal = in_array($reason, ['copy_changed', 'policy_disapproved', 'policy_targeting_restricted'], true)
                || $reads >= self::MAX_VERIFICATION_READS || Carbon::parse($details['submitted_at'])->lt(now()->subDay());
            $details = array_merge($details, ['reason' => $reason, 'verification_reads' => $reads,
                'last_verified_at' => now()->toIso8601String(), 'next_verification_at' => now()->addHour()->toIso8601String()]);
            if ($terminal && $reason === 'still_weak') {
                $details['retry_after'] = now()->addHour()->toIso8601String();
            }
            $locked->update(['action' => $terminal ? 'ad_copy_update_verified' : 'ad_copy_update_submitted',
                'status' => $terminal ? 'needs_review' : 'pending',
                'description' => $terminal ? 'RSA repair remains unresolved: '.str_replace('_', ' ', $reason).'.'
                    : 'RSA repair is awaiting Google review or strength confirmation.', 'details' => $details]);

            return ['status' => $terminal ? 'needs_review' : 'pending', 'reason' => $reason, 'copy_matches' => $matched];
        });
        $attempt->refresh();
        if ($result['status'] === 'needs_review'
            && ($result['reason'] !== 'still_weak' || $this->attemptCount($campaign, $attempt->details['ad_resource']) >= self::MAX_REPAIRS)) {
            $this->escalate($campaign, $attempt->details['ad_resource'], $attempt->description);
        }

        return $result;
    }

    private function syncAdBaseline(Campaign $campaign, array $details): void
    {
        foreach ($campaign->strategies()->lockForUpdate()->get() as $strategy) {
            $execution = $strategy->execution_result ?? [];
            $changed = false;
            foreach ($execution['metadata']['google_search_baseline']['ads'] ?? [] as $index => $expected) {
                if (($expected['resource'] ?? '') !== $details['ad_resource']) {
                    continue;
                }
                $execution['metadata']['google_search_baseline']['ads'][$index]['headlines'] = $details['headlines'];
                $execution['metadata']['google_search_baseline']['ads'][$index]['descriptions'] = $details['descriptions'];
                if (isset($expected['ad_copy_id'])) {
                    $strategy->adCopies()->whereKey($expected['ad_copy_id'])->update(['headlines' => $details['headlines'], 'descriptions' => $details['descriptions']]);
                }
                $changed = true;
            }
            if ($changed) {
                $strategy->update(['execution_result' => $execution]);
            }
        }
    }

    /** Extend the expected baseline only for our acknowledged, evidenced asset writes. */
    public function syncSitelinks(\App\Models\Strategy $strategy, array $created): void
    {
        if ($created === []) {
            return;
        }
        DB::transaction(function () use ($strategy, $created) {
            $locked = \App\Models\Strategy::whereKey($strategy->id)->lockForUpdate()->firstOrFail();
            $execution = $locked->execution_result ?? [];
            if (empty($execution['metadata']['google_search_baseline'])) {
                return;
            }
            $baseline = $execution['metadata']['google_search_baseline'];
            foreach ($created as $asset) {
                $baseline['sitelink_urls'][] = $asset['url'];
                $baseline['asset_resources'][] = $asset['asset_resource'];
            }
            $baseline['sitelink_urls'] = array_values(array_unique($baseline['sitelink_urls']));
            $baseline['asset_resources'] = array_values(array_unique($baseline['asset_resources']));
            $execution['metadata']['google_search_baseline'] = $baseline;
            $locked->update(['execution_result' => $execution]);
        });
    }

    public function sameCopy(array $ad, array $expected): bool
    {
        $actualHeadlines = $ad['headlines'];
        $actualDescriptions = $ad['descriptions'];
        $headlines = $expected['headlines'] ?? [];
        $descriptions = $expected['descriptions'] ?? [];
        sort($actualHeadlines);
        sort($actualDescriptions);
        sort($headlines);
        sort($descriptions);

        return $actualHeadlines === $headlines && $actualDescriptions === $descriptions;
    }

    public function escalate(Campaign $campaign, string $resource, string $message): void
    {
        $event = DB::transaction(function () use ($campaign, $resource, $message) {
            Campaign::whereKey($campaign->id)->lockForUpdate()->firstOrFail();
            if (AgentActivity::where('campaign_id', $campaign->id)->where('action', 'ad_strength_escalated')
                ->where('details->ad_resource', $resource)->where('created_at', '>=', now()->subDays(7))->exists()) {
                return null;
            }
            $event = AgentActivity::record('quality_score', 'ad_strength_escalated', $message,
                $campaign->customer_id, $campaign->id, ['ad_resource' => $resource, 'action_required' => 'Review the unresolved RSA copy, policy review and Google action items.'], 'needs_review');
            foreach ($campaign->customer->users as $user) {
                Notification::notify($user, 'ad_strength_unresolved', 'Ad strength repair needs review', $message,
                    route('campaigns.show', $campaign), 'Review campaign', $campaign->customer, ['ad_resource' => $resource]);
            }

            return $event;
        });
        if ($event) {
            CriticalAgentAlert::deliver('ad_strength_unresolved', 'Ad strength repair needs review', $message,
                ['campaign_id' => $campaign->id, 'campaign_name' => $campaign->name, 'ad_resource' => $resource,
                    'action_required' => 'Review the unresolved RSA copy, policy review and Google action items.', 'dedupe_key' => (string) $event->id],
                CriticalAgentAlert::RECIPIENTS_BOTH, $campaign->customer);
        }
    }
}
