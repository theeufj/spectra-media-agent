<?php

namespace App\Services\Agents;

use App\Enums\CampaignStatus;
use App\Models\AdCopy;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\KeywordQualityScore;
use App\Models\Strategy;
use App\Notifications\CriticalAgentAlert;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\CommonServices\UpdateKeywordStatus;
use App\Services\GoogleAds\CommonServices\UpdateResponsiveSearchAd;
use App\Services\GoogleAds\GenerateReviewedRsaCopy;
use App\Services\GoogleAds\InvalidGeneratedRsaCopy;
use App\Services\GoogleAds\RsaCopyReviewRejected;
use App\Services\GoogleAds\RsaCopyReviewUnavailable;
use Google\Ads\GoogleAds\V26\Enums\QualityScoreBucketEnum\QualityScoreBucket;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Diagnoses low Quality Score keywords and takes targeted corrective action.
 *
 * Three root causes (from Google's three sub-scores):
 *   search_predicted_ctr: BELOW_AVERAGE  → generate ad copy variations that feature the keyword
 *   creative_quality_score: BELOW_AVERAGE → flag for SKAG review (keyword in wrong ad group)
 *   post_click_quality_score: BELOW_AVERAGE → log landing page improvement recommendation
 *
 * Pauses keywords stuck at QS < threshold for > pause_min_days consecutive days.
 * Thresholds are configured in config/optimization.php.
 */
class QualityScoreImprovementAgent
{
    public function __construct(private GenerateReviewedRsaCopy $copyGenerator) {}

    public function improve(Campaign $campaign): array
    {
        if (CampaignSpendGuardrails::automaticChangesSuspended($campaign) || $campaign->status === CampaignStatus::Paused) {
            return ['skipped' => true, 'reason' => 'Approved bounded trial or pause hold: keep the agreed keyword and creative test unchanged.',
                'actions' => [], 'flagged' => [], 'paused' => [], 'errors' => []];
        }
        $customer = $campaign->customer;

        if (! $customer?->google_ads_customer_id || ! $campaign->google_ads_campaign_id) {
            return ['skipped' => true];
        }

        $customerId = $customer->cleanGoogleCustomerId();
        $pauseDays = config('optimization.quality_score.pause_min_days', 21);
        $qsThreshold = config('optimization.quality_score.pause_qs_threshold', 5);

        $decliners = KeywordQualityScore::where('customer_id', $campaign->customer_id)
            ->where('campaign_google_id', $campaign->google_ads_campaign_id)
            ->declining($pauseDays)
            ->get()
            ->groupBy('keyword_text');

        $actions = [];
        $flagged = [];
        $paused = [];
        $errors = [];
        $pauseCandidates = [];

        foreach ($decliners as $keywordText => $records) {
            $latest = $records->sortByDesc('recorded_at')->first();

            try {
                $this->diagnoseAndAct(
                    $campaign,
                    $customer,
                    $customerId,
                    $latest,
                    $records,
                    $pauseDays,
                    $qsThreshold,
                    $actions,
                    $flagged,
                    $paused,
                    $errors,
                    $pauseCandidates
                );
            } catch (\Throwable $e) {
                report($e);
                $errors[] = "Error processing keyword '{$keywordText}': ".$e->getMessage();
                Log::error('QualityScoreImprovementAgent: '.$e->getMessage());
            }
        }

        $this->applyKeywordPauses($campaign, $customer, $pauseCandidates, $paused, $flagged, $errors);

        if (! empty($actions) || ! empty($paused)) {
            $total = count($actions) + count($paused);
            AgentActivity::record(
                'quality_score',
                'qs_improvements_applied',
                "Applied {$total} QS action(s) for \"{$campaign->name}\"",
                $campaign->customer_id,
                $campaign->id,
                ['actions' => $actions, 'paused' => $paused, 'flagged' => $flagged, 'errors' => $errors]
            );
        }

        // Send ONE aggregated alert if any keywords were paused this run
        if (! empty($paused)) {
            $this->notifyPaused($campaign, $paused, $qsThreshold, $pauseDays);
        }

        return [
            'actions' => $actions,
            'flagged' => $flagged,
            'paused' => $paused,
            'errors' => $errors,
        ];
    }

    private function diagnoseAndAct(
        Campaign $campaign,
        object $customer,
        string $customerId,
        KeywordQualityScore $latest,
        $allRecords,
        int $pauseDays,
        int $qsThreshold,
        array &$actions,
        array &$flagged,
        array &$paused,
        array &$errors,
        array &$pauseCandidates
    ): void {
        $keyword = $latest->keyword_text;
        $qs = $latest->quality_score;
        $ctr = $this->qualityBucket($latest->search_predicted_ctr);
        $creative = $this->qualityBucket($latest->creative_quality_score);
        $postClick = $this->qualityBucket($latest->post_click_quality_score);

        $isStuck = $allRecords->count() >= 2
            && $allRecords->every(fn ($r) => ($r->quality_score ?? 10) < $qsThreshold)
            && $allRecords->min('recorded_at') <= now()->subDays($pauseDays);

        // Root cause 1: Low expected CTR → generate tighter ad copy
        if ($ctr === 'BELOW_AVERAGE' && ! $isStuck) {
            $strategy = $this->strategyForKeyword($campaign, $latest);
            if (! $strategy) {
                $flagged[] = ['keyword' => $keyword, 'issue' => 'ad_copy_strategy_unconfirmed',
                    'recommendation' => 'The keyword must identify one approved Google strategy before new copy can be generated.'];
            } else {
                $this->generateReviewedKeywordCopy($campaign, $strategy, $latest, $actions, $flagged);
            }
        }

        // Root cause 2: Poor ad relevance → flag for SKAG review
        if ($creative === 'BELOW_AVERAGE') {
            $flagged[] = [
                'keyword' => $keyword,
                'issue' => 'creative_quality_score: BELOW_AVERAGE',
                'recommendation' => "Consider moving '{$keyword}' to a dedicated single-keyword ad group (SKAG) for tighter ad relevance.",
            ];

            AgentActivity::record(
                'quality_score',
                'skag_recommended',
                "Keyword '{$keyword}' flagged for SKAG in \"{$campaign->name}\" (creative QS below average)",
                $campaign->customer_id,
                $campaign->id
            );
        }

        // Root cause 3: Poor landing page → log improvement recommendation
        if ($postClick === 'BELOW_AVERAGE') {
            $flagged[] = [
                'keyword' => $keyword,
                'issue' => 'post_click_quality_score: BELOW_AVERAGE',
                'recommendation' => "Landing page for '{$keyword}' needs: keyword in H1/title, faster load time, and content that directly addresses search intent.",
            ];

            AgentActivity::record(
                'quality_score',
                'landing_page_flagged',
                "Landing page flagged for keyword '{$keyword}' in \"{$campaign->name}\" (post-click QS below average)",
                $campaign->customer_id,
                $campaign->id
            );
        }

        // Auto-pause if stuck at low QS for extended period
        if ($isStuck && $latest->criterion_resource_name) {
            $pauseCandidates[] = ['keyword' => $keyword, 'qs' => $qs, 'days' => $pauseDays,
                'resource_name' => $latest->criterion_resource_name];
        }
    }

    private function applyKeywordPauses(Campaign $campaign, Customer $customer, array $candidates, array &$paused, array &$flagged, array &$errors): void
    {
        if ($candidates === []) {
            return;
        }
        $changes = array_map(fn ($candidate) => ['action' => 'pause', 'resource_name' => $candidate['resource_name']], $candidates);
        try {
            $assessment = app(GoogleSearchMutationGuard::class)->assess($campaign, $changes);
        } catch (\Throwable $e) {
            report($e);
            $assessment = ['ready' => false, 'message' => 'The remaining keyword demand and affordable traffic could not be verified.'];
            $errors[] = 'Keyword pause assessment unavailable: '.$e->getMessage();
        }
        if (! ($assessment['ready'] ?? false)) {
            $reason = $assessment['message'] ?? 'The resulting keyword portfolio requires review before pausing low Quality Score terms.';
            $flagged[] = ['keywords' => array_column($candidates, 'keyword'), 'issue' => 'keyword_pause_reach_unverified', 'recommendation' => $reason];
            AgentActivity::record('quality_score', 'keyword_pauses_need_review', $reason,
                $campaign->customer_id, $campaign->id, ['candidates' => $candidates, 'assessment' => $assessment]);

            return;
        }
        foreach ($candidates as $candidate) {
            if (CampaignSpendGuardrails::automaticChangesSuspended($campaign)) {
                $flagged[] = ['issue' => 'keyword_pause_hold', 'recommendation' => 'A trial or spending hold started before the keyword change.'];
                AgentActivity::record('quality_score', 'keyword_pauses_need_review', 'A trial or spending hold started before the keyword change.',
                    $campaign->customer_id, $campaign->id, ['candidates' => $candidates]);

                break;
            }
            try {
                $success = app(UpdateKeywordStatus::class, ['customer' => $customer])->pause($customer->cleanGoogleCustomerId(), $candidate['resource_name']);
                if ($success) {
                    $paused[] = ['keyword' => $candidate['keyword'], 'qs' => $candidate['qs'], 'days' => $candidate['days']];
                } else {
                    $errors[] = "Failed to pause keyword '{$candidate['keyword']}'";
                }
            } catch (\Throwable $e) {
                report($e);
                $errors[] = "Failed to pause keyword '{$candidate['keyword']}': ".$e->getMessage();
            }
        }
    }

    private function notifyPaused(Campaign $campaign, array $paused, int $qsThreshold, int $pauseDays): void
    {
        $cacheKey = "notif:qs_paused:{$campaign->id}";
        if (Cache::has($cacheKey)) {
            return;
        }

        $keywordList = implode(', ', array_column($paused, 'keyword'));
        $count = count($paused);
        $message = "{$count} keyword(s) were paused in \"{$campaign->name}\" after remaining below QS {$qsThreshold} for {$pauseDays}+ days: {$keywordList}.";

        CriticalAgentAlert::deliver(
            'quality_score',
            'Low-QS Keywords Paused',
            $message,
            [
                'campaign_name' => $campaign->name,
                'campaign_id' => $campaign->id,
                'paused' => $paused,
            ],
            CriticalAgentAlert::RECIPIENTS_ADMINS,
            $campaign->customer
        );

        Cache::put($cacheKey, true, now()->addHours(24));
    }

    /** The SDK emits enum integers; older observations stored their names. */
    private function qualityBucket(mixed $value): string
    {
        if (is_int($value) || is_string($value) && ctype_digit($value)) {
            try {
                return QualityScoreBucket::name((int) $value);
            } catch (\UnexpectedValueException) {
                return 'UNKNOWN';
            }
        }

        return is_string($value) && in_array($value, ['BELOW_AVERAGE', 'AVERAGE', 'ABOVE_AVERAGE'], true)
            ? $value : 'UNKNOWN';
    }

    /** A keyword observation must identify one approved strategy in this exact Google campaign. */
    private function strategyForKeyword(Campaign $campaign, KeywordQualityScore $keyword): ?Strategy
    {
        $account = $campaign->customer?->cleanGoogleCustomerId();
        if (! $account) {
            return null;
        }
        $prefix = 'customers/'.preg_quote($account, '#');
        $group = (string) $keyword->ad_group_resource_name;
        $criterion = (string) $keyword->criterion_resource_name;
        $criterionGroup = preg_match('#^'.$prefix.'/adGroupCriteria/(\d+)~\d+$#', $criterion, $parts) === 1
            ? 'customers/'.$account.'/adGroups/'.$parts[1] : null;
        if (($criterion !== '' && $criterionGroup === null)
            || ($group !== '' && preg_match('#^'.$prefix.'/adGroups/\d+$#', $group) !== 1)
            || ($group !== '' && $criterionGroup !== null && $group !== $criterionGroup)) {
            return null;
        }
        $group = $group !== '' ? $group : $criterionGroup;
        if (! $group) {
            return null;
        }
        $strategies = $campaign->strategies()->whereNotNull('signed_off_at')
            ->where(fn ($query) => $query->where('platform', 'like', '%google%')->orWhere('platform', 'like', '%Google%'))
            ->whereIn('google_ads_ad_group_id', [$group, basename($group)])->get()
            ->filter(function (Strategy $strategy) use ($campaign, $account): bool {
                $target = (string) $strategy->reusableGoogleCampaignId();
                $target = ctype_digit($target) ? 'customers/'.$account.'/campaigns/'.$target : $target;

                return $target === $campaign->googleAdsResourceName();
            });

        return $strategies->count() === 1 ? $strategies->first() : null;
    }

    /** Reuse the same factual review/correction gate as live RSA repair before saving collateral. */
    private function generateReviewedKeywordCopy(Campaign $campaign, Strategy $strategy, KeywordQualityScore $keyword, array &$actions, array &$flagged): void
    {
        $state = app(\App\Services\GoogleAds\GoogleAdStrengthRepair::class);
        if ($reason = $state->mutationBlocked($campaign, $strategy)) {
            $flagged[] = ['keyword' => $keyword->keyword_text, 'issue' => 'ad_copy_generation_hold', 'recommendation' => $reason];

            return;
        }
        $existing = $strategy->adCopies()->latest('id')->first();
        $attempt = AgentActivity::record('quality_score', 'ad_copy_generation_attempt',
            'Preparing independently reviewed copy for a low expected-CTR keyword.', $campaign->customer_id, $campaign->id,
            ['strategy_id' => $strategy->id, 'keyword' => $keyword->keyword_text,
                'ad_group_resource_name' => $keyword->ad_group_resource_name,
                'criterion_resource_name' => $keyword->criterion_resource_name,
                'generation_revision' => GenerateReviewedRsaCopy::REVISION], 'running');
        try {
            $generated = $this->copyGenerator->generate($campaign, $strategy, [
                'keyword_text' => $keyword->keyword_text,
                'action_items' => ['Improve expected click-through rate using relevant intent from the observed keyword.'],
                'headlines' => $existing->headlines ?? [], 'descriptions' => $existing->descriptions ?? [],
            ], function (array $history, ?array $review, ?array $copy) use ($attempt): void {
                $details = array_merge($attempt->details ?? [], ['generation_attempts' => $history]);
                if ($review !== null) {
                    $details['review'] = $review;
                }
                if ($copy !== null) {
                    $details['last_candidate'] = $copy;
                }
                $attempt->update(['details' => $details]);
            });
            $saved = DB::transaction(function () use ($campaign, $strategy, $keyword, $state, $attempt, $generated): ?AdCopy {
                $fresh = Campaign::with('customer')->whereKey($campaign->id)->lockForUpdate()->firstOrFail();
                $currentStrategy = Strategy::whereKey($strategy->id)->lockForUpdate()->firstOrFail();
                $reason = $state->mutationBlocked($fresh, $currentStrategy);
                if ($reason === null && $this->strategyForKeyword($fresh, $keyword)?->id !== $strategy->id) {
                    $reason = 'keyword_strategy_changed_during_review';
                }
                if ($reason !== null) {
                    $attempt->update(['status' => 'skipped', 'description' => 'Reviewed copy was not saved because a current hold or target change applies.',
                        'details' => array_merge($attempt->details ?? [], ['reason' => $reason])]);

                    return null;
                }
                $copy = AdCopy::create(['strategy_id' => $strategy->id, 'platform' => 'Google Ads'] + $generated['copy']);
                $attempt->update(['action' => 'ad_copy_generated', 'status' => 'completed',
                    'description' => 'Saved independently reviewed copy for a low expected-CTR keyword.',
                    'details' => array_merge($attempt->details ?? [], ['ad_copy_id' => $copy->id,
                        'review' => $generated['review'], 'generation_attempts' => $generated['generation_attempts']])]);

                return $copy;
            });
            if ($saved) {
                $actions[] = ['keyword' => $keyword->keyword_text, 'action' => 'generated_ad_copy', 'variants' => 1,
                    'strategy_id' => $strategy->id, 'ad_copy_id' => $saved->id, 'reason' => 'search_predicted_ctr: BELOW_AVERAGE'];
            } else {
                $flagged[] = ['keyword' => $keyword->keyword_text, 'issue' => 'ad_copy_generation_hold',
                    'recommendation' => $attempt->details['reason']];
            }
        } catch (\Throwable $e) {
            $attempt->update(['status' => 'needs_review', 'description' => 'No new ad copy was saved: '.$e->getMessage(),
                'details' => array_merge($attempt->details ?? [], ['error' => $e->getMessage()])]);
            throw $e;
        }
    }

    /** Repair weak RSAs without waiting for impressions; track outcomes per ad. */
    public function checkAdStrength(Campaign $campaign): array
    {
        $result = ['actions' => [], 'errors' => [], 'unresolved' => [], 'mutation_holds' => [], 'observations' => [], 'verified' => false, 'checked' => false];
        $fresh = Campaign::with('customer')->find($campaign->id);
        $customer = $fresh?->customer;
        if ($customer) {
            $campaign->setRelation('customer', $customer);
        }
        if (! $customer?->google_ads_customer_id || ! $campaign->google_ads_campaign_id
            || $customer->service_type === 'setup_only' || $customer->is_sandbox
            || ! \App\Models\EnabledPlatform::isEnabled('google')
            || in_array($customer->google_ads_link_status, ['pending', 'refused', 'cancelled', 'failed', 'revoked'], true)
            || in_array($campaign->status, [\App\Enums\CampaignStatus::Paused, \App\Enums\CampaignStatus::Ended, \App\Enums\CampaignStatus::Completed], true)) {
            return array_merge($result, ['skipped' => 'not_managed_or_active']);
        }
        $lock = Cache::lock("ad_strength_repair:{$campaign->id}", 900);
        if (! $lock->get()) {
            return array_merge($result, ['skipped' => 'already_checking']);
        }
        $state = app(\App\Services\GoogleAds\GoogleAdStrengthRepair::class);
        try {
            $reader = app(\App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration::class, ['customer' => $customer]);
            $rows = $reader->ads($customer->cleanGoogleCustomerId(), $campaign->googleAdsResourceName());
            $allAds = array_map([\App\Services\GoogleAds\GoogleAdStrengthRepair::class, 'ad'], $rows);
            if ($allAds !== [] && $allAds[0]['campaign_status'] !== 'ENABLED') {
                return array_merge($result, ['checked' => true, 'skipped' => 'google_campaign_not_enabled',
                    'unresolved' => [['reason' => 'google_campaign_'.strtolower($allAds[0]['campaign_status'])]]]);
            }
            $ads = array_filter($allAds, fn ($ad) => $ad['is_rsa'] && $ad['status'] === 'ENABLED' && $ad['ad_group_status'] === 'ENABLED');
            $result['checked'] = true;
            $result['observations'] = array_values(array_map([\App\Services\GoogleAds\GoogleAdStrengthRepair::class, 'observation'], $ads));
            if ($ads === []) {
                $result['unresolved'][] = ['reason' => 'no_enabled_rsa'];
            }
            $verifications = [];
            $submittedCopies = [];
            foreach ($ads as $ad) {
                preg_match('#^(customers/\d+)/adGroupAds/(\d+)~#', $ad['resource_name'], $parts);
                $group = isset($parts[1], $parts[2]) ? $parts[1].'/adGroups/'.$parts[2] : '';
                $strategy = $campaign->strategies()->where('google_ads_ad_group_id', $group)->first();
                $writeHold = $strategy ? $state->mutationBlocked($campaign, $strategy) : null;
                if ($writeHold !== null) {
                    $result['mutation_holds'][] = ['ad_resource' => $ad['resource_name'], 'reason' => $writeHold];
                }
                $attempt = $state->latest($campaign, $ad['resource_name']);
                if ($attempt && isset($attempt->details['submitted_at'])) {
                    $verification = $state->verify($campaign, $attempt, $ad);
                    $verifications[$ad['resource_name']] = ['verification_status' => $verification['status'],
                        'submitted_at' => $attempt->details['submitted_at'],
                        'verification_reads' => $attempt->details['verification_reads'] ?? 0];
                    if (($verification['status'] ?? '') === 'pending') {
                        $result['unresolved'][] = ['ad_resource' => $ad['resource_name'], 'reason' => $verification['reason']];

                        continue;
                    }
                }
                if (\App\Services\GoogleAds\GoogleAdStrengthRepair::healthy($ad)) {
                    continue;
                }
                if ($reason = \App\Services\GoogleAds\GoogleAdStrengthRepair::statusReason($ad)) {
                    $result['unresolved'][] = ['ad_resource' => $ad['resource_name'], 'reason' => $reason];

                    continue;
                }
                $details = $attempt->details ?? [];
                if ($attempt && ($attempt->status === 'running' && $attempt->updated_at->gt(now()->subMinutes(30))
                    || isset($details['retry_after']) && \Illuminate\Support\Carbon::parse($details['retry_after'])->isFuture()
                    || isset($details['submitted_at']) && in_array($details['reason'] ?? '', ['copy_changed', 'ad_missing', 'review_pending', 'strength_pending', 'policy_disapproved', 'policy_targeting_restricted', 'ad_status_unknown', 'verification_failed'], true))) {
                    $result['unresolved'][] = ['ad_resource' => $ad['resource_name'], 'reason' => $details['reason'] ?? 'repair_in_progress']
                        + array_intersect_key($details, array_flip(['error_code', 'error_message']));

                    continue;
                }
                if ($state->attemptCount($campaign, $ad['resource_name']) >= \App\Services\GoogleAds\GoogleAdStrengthRepair::MAX_REPAIRS
                    && ! $state->hasAuthorizedGenerationRetry($campaign, $ad['resource_name'])) {
                    $state->escalate($campaign, $ad['resource_name'], $details['error_message'] ?? 'RSA strength remains weak after three bounded repair attempts.',
                        $details['error_code'] ?? 'ad_strength_repair_limit_reached');
                    $result['unresolved'][] = ['ad_resource' => $ad['resource_name'], 'reason' => 'repair_limit_reached']
                        + array_intersect_key($details, array_flip(['error_code', 'error_message']));

                    continue;
                }
                if ($writeHold !== null) {
                    $result['unresolved'][] = ['ad_resource' => $ad['resource_name'], 'reason' => self::observedStrengthReason($ad)];

                    continue;
                }
                $attempt = $state->start($campaign, $ad);
                try {
                    if (! $strategy) {
                        throw new \RuntimeException('Could not identify the strategy that owns this ad group.');
                    }
                    // A one-use copy recovery authorizes only the reviewed RSA replacement.
                    foreach (isset($attempt->details['generation_retry_authorization_id']) ? [] : $ad['action_items'] as $item) {
                        if (! is_string($item) || ! str_contains(strtolower($item), 'sitelink')) {
                            continue;
                        }
                        preg_match('/add(?:ing)?\s+(\d+)\s+(?:more\s+)?sitelinks?/i', $item, $matches);
                        $extensions = app(AdExtensionAgent::class)->repairSitelinks($campaign, (int) ($matches[1] ?? 2), $strategy);
                        $attempt->update(['details' => array_merge($attempt->details ?? [], ['sitelink_repair' => $extensions])]);
                        $state->syncSitelinks($strategy, $extensions['created']);
                        $result['errors'] = array_merge($result['errors'], AgentIssue::list($extensions['errors']));
                        if ($extensions['unresolved'] !== []) {
                            $result['unresolved'][] = ['ad_resource' => $ad['resource_name'], 'reason' => 'sitelink_coverage_unresolved', 'action_items' => [$item]];
                            $state->escalate($campaign, $ad['resource_name'], $extensions['unresolved'][0], 'ad_strength_sitelink_coverage_unresolved');
                        }
                        break;
                    }
                    $generated = $this->copyGenerator->generate($campaign, $strategy, $ad,
                        function (array $history, ?array $review, ?array $copy) use ($attempt, $strategy): void {
                            $details = array_merge($attempt->details ?? [], ['generation_revision' => GenerateReviewedRsaCopy::REVISION,
                                'generation_attempts' => $history, 'strategy_id' => $strategy->id]);
                            if ($review !== null) {
                                $details['review'] = $review;
                            }
                            if ($copy !== null) {
                                $details['last_candidate'] = $copy;
                            }
                            $attempt->update(['details' => $details]);
                        });
                    $copy = $generated['copy'];
                    if ($state->sameCopy($ad, $copy)) {
                        throw new \RuntimeException('Generated RSA copy is unchanged; no repair was submitted.');
                    }
                    if ($blocked = $state->mutationBlocked($campaign, $strategy)) {
                        $attempt->update(['status' => 'skipped', 'description' => 'RSA repair skipped because a current management hold applies.',
                            'details' => array_merge($attempt->details ?? [], ['reason' => $blocked])]);
                        $result['mutation_holds'][] = ['ad_resource' => $ad['resource_name'], 'reason' => $blocked];
                        $result['unresolved'][] = ['ad_resource' => $ad['resource_name'], 'reason' => self::observedStrengthReason($ad)];

                        continue;
                    }
                    // AI review may take minutes. Do not overwrite a later
                    // pause or copy edit made in Google while it was running.
                    $liveRows = $reader->ads($customer->cleanGoogleCustomerId(), $campaign->googleAdsResourceName());
                    $liveRow = collect($liveRows)->first(fn ($row) => ($row['adGroupAd']['resourceName'] ?? '') === $ad['resource_name']);
                    $live = $liveRow ? \App\Services\GoogleAds\GoogleAdStrengthRepair::ad($liveRow) : null;
                    if (! $live || $live['campaign_status'] !== 'ENABLED' || $live['status'] !== 'ENABLED'
                        || $live['ad_group_status'] !== 'ENABLED' || ! $state->sameCopy($live, $ad)) {
                        $attempt->update(['status' => 'skipped', 'description' => 'RSA changed or was paused during review; no copy write was sent.',
                            'details' => array_merge($attempt->details ?? [], ['reason' => 'google_ad_changed_during_review'])]);
                        $result['unresolved'][] = ['ad_resource' => $ad['resource_name'], 'reason' => 'google_ad_changed_during_review'];

                        continue;
                    }
                    $attempt->update(['details' => array_merge($attempt->details ?? [], ['google_write_started_at' => now()->toIso8601String()])]);
                    $updater = app(UpdateResponsiveSearchAd::class, ['customer' => $customer]);
                    if (! $updater->replace($customer->cleanGoogleCustomerId(), $ad['resource_name'], $copy['headlines'], $copy['descriptions'])) {
                        throw new \RuntimeException('Google did not accept the RSA update.');
                    }
                    $state->submitted($attempt, $copy);
                    $verifications[$ad['resource_name']] = ['verification_status' => 'pending',
                        'submitted_at' => $attempt->details['submitted_at'], 'verification_reads' => 0];
                    $submittedCopies[$ad['resource_name']] = $copy;
                    $result['actions'][] = ['ad_id' => $ad['ad_id'], 'ad_resource' => $ad['resource_name'],
                        'strength_before' => $ad['ad_strength'], 'verification' => 'pending', 'attempt_id' => $attempt->id] + $copy;
                    $result['unresolved'][] = ['ad_resource' => $ad['resource_name'], 'reason' => 'verification_pending'];
                    \App\Jobs\VerifyGoogleAdImprovement::dispatch($campaign, $ad['resource_name'],
                        \Google\Ads\GoogleAds\V26\Enums\AdStrengthEnum\AdStrength::value($ad['ad_strength']),
                        $copy['headlines'], $copy['descriptions'], $attempt->id)->delay(now()->addHour());
                } catch (\Throwable $e) {
                    report($e);
                    $code = match (true) {
                        $e instanceof InvalidGeneratedRsaCopy => 'ad_strength_copy_invalid',
                        $e instanceof RsaCopyReviewRejected => 'ad_strength_copy_rejected',
                        $e instanceof RsaCopyReviewUnavailable => 'ad_strength_review_unavailable',
                        default => 'ad_strength_repair_failed',
                    };
                    $issue = new AgentIssue($code, $e->getMessage());
                    $state->failedAttempt($campaign, $attempt, $e->getMessage(), $issue);
                    $result['errors'][] = $issue;
                    $result['unresolved'][] = ['ad_resource' => $ad['resource_name'], 'reason' => 'repair_failed',
                        'error_code' => $issue->code, 'error_message' => $issue->message];
                }
            }
            // Preserve the exact observation and any exhausted verification
            // state so readiness can wait only on confirmed, recent signals.
            $byResource = collect($ads)->keyBy('resource_name');
            $result['unresolved'] = array_map(function (array $issue) use ($byResource, $verifications, $submittedCopies): array {
                $ad = $byResource->get($issue['ad_resource'] ?? '');
                if (! $ad) {
                    return $issue;
                }
                $observation = \App\Services\GoogleAds\GoogleAdStrengthRepair::observation($ad);
                if (isset($submittedCopies[$ad['resource_name']])) {
                    // This snapshot read the old copy, then Google accepted a
                    // replacement. Start its waiting clock on the new identity.
                    $observation['live_copy_fingerprint'] = $observation['copy_fingerprint'];
                    $observation['copy_fingerprint'] = \App\Services\GoogleAds\GoogleAdStrengthRepair::copyFingerprint(
                        array_merge($ad, $submittedCopies[$ad['resource_name']]));
                    $observation['copy_identity_source'] = 'submitted';
                }

                return array_merge($observation, $verifications[$ad['resource_name']] ?? [], $issue);
            }, $result['unresolved']);
            $result['verified'] = $ads !== [] && $result['unresolved'] === [] && $result['errors'] === [];
        } catch (\Throwable $e) {
            report($e);
            $result['errors'][] = new AgentIssue('google_ad_strength_read_failed', $e->getMessage());
            $result['unresolved'][] = ['reason' => 'google_read_failed'];
            AgentActivity::record('quality_score', 'ad_strength_check_failed', 'Could not read Google RSA strength; retry remains available.',
                $campaign->customer_id, $campaign->id, ['error' => $e->getMessage()], 'needs_review');
            Log::warning('QualityScoreImprovementAgent: Ad strength check failed', ['campaign_id' => $campaign->id, 'error' => $e->getMessage()]);
        } finally {
            $lock->release();
        }

        return $result;
    }

    public static function isStrengthMutationHold(string $reason): bool
    {
        return in_array($reason, ['sandbox', 'google_disabled', 'missing_google_account', 'google_account_changed',
            'google_management_unavailable', 'customer_or_campaign_missing', 'campaign_not_active', 'google_campaign_paused',
            'campaign_ended', 'spend_safety_hold', 'approved_bounded_trial', 'setup_only', 'strategy_not_approved',
            'google_strategy_target_changed', 'auto_healing_disabled', 'ad_spend_unfunded', 'ad_rank_replacement_pending'], true);
    }

    /** Permission to write does not determine what Google actually reported. */
    public static function observedStrengthReason(array $ad): ?string
    {
        if ($reason = \App\Services\GoogleAds\GoogleAdStrengthRepair::statusReason($ad)) {
            return $reason;
        }

        return \App\Services\GoogleAds\GoogleAdStrengthRepair::healthy($ad) ? null : 'weak_ad_strength';
    }
}
