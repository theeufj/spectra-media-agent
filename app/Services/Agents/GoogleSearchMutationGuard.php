<?php

namespace App\Services\Agents;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\CommonServices\GetCampaignSpendSafety;
use App\Services\GoogleAds\Diagnostics\InspectSearchDelivery;
use Carbon\CarbonImmutable;
use Google\Ads\GoogleAds\V22\Enums\BiddingStrategyTypeEnum\BiddingStrategyType;

/** Demand and affordable reach for the complete proposed Search portfolio, never authorization by itself. */
class GoogleSearchMutationGuard
{
    public function __construct(private GoogleSearchReachPlanner $planner, private GoogleSearchReachGate $gate) {}

    public function assess(Campaign $campaign, array $changes, bool $approvedByUser = false): array
    {
        try {
            $campaign = $campaign->fresh(['customer']) ?? $campaign;
            if (! $campaign->customer || ! CampaignSpendGuardrails::canEnable($campaign, $approvedByUser) || $campaign->hasPassedEndDate() || $campaign->spend_safety_hold
                || ! $approvedByUser && ($campaign->customer->service_type !== 'managed' || $campaign->status !== CampaignStatus::Active || CampaignSpendGuardrails::automaticChangesSuspended($campaign))) {
                return $this->blocked('Campaign changes require review: the campaign is inactive, ended, or under an approved spending hold or trial.');
            }
            $snapshot = $this->inspect($campaign);
            if (! $snapshot || ($snapshot['channel'] ?? null) !== 2 || ($snapshot['google_search_enabled'] ?? null) !== true
                || ($snapshot['approved_ads'] ?? 0) < 1 || ! $approvedByUser && ($snapshot['status'] ?? null) !== 2) {
                return $this->blocked('Current Google Search settings could not be verified.', 'unavailable');
            }
            if (CampaignSpendGuardrails::activeTrial($campaign)) {
                $spend = $this->spend($campaign);
                $snapshot['reported_lifetime_cost_micros'] = $spend['cost_micros'];
                $snapshot['reported_spend_checked_at'] = now()->toIso8601String();
            }
            $proposed = $this->project($campaign, $snapshot, $changes);
            $keywords = array_values(array_filter($proposed['keywords'], fn ($keyword) => ($keyword['status'] ?? null) === 'ENABLED'
                && ($keyword['ad_group_status'] ?? null) === 'ENABLED'));
            if ($keywords === []) {
                return $this->blocked('The proposed changes leave no active Search keywords. Review a replacement portfolio before applying them.');
            }
            $assessment = $this->planner->assessMutation($campaign, $proposed, $keywords);
            $result = $this->gate->evaluate($campaign, $proposed, $assessment);

            if ($result['ready']) {
                $fresh = $campaign->fresh(['customer']);
                $after = $fresh ? $this->inspect($fresh) : null;
                $fresh = $fresh?->fresh(['customer']); // Local approval may have changed during the final API read.
                if (! $fresh || ! CampaignSpendGuardrails::canEnable($fresh, $approvedByUser) || $fresh->spend_safety_hold || $fresh->hasPassedEndDate()
                    || ! $approvedByUser && ($fresh->customer?->service_type !== 'managed' || $fresh->status !== CampaignStatus::Active || CampaignSpendGuardrails::automaticChangesSuspended($fresh))
                    || ! $after || $this->fingerprint($snapshot) !== $this->fingerprint($after)) {
                    return $this->blocked('Campaign settings or authorization changed during reach research. Review the current settings before applying the proposal.');
                }
                if (CampaignSpendGuardrails::activeTrial($fresh)) {
                    $spend = $this->spend($fresh);
                    $after['reported_lifetime_cost_micros'] = $spend['cost_micros'];
                    $after['reported_spend_checked_at'] = now()->toIso8601String();
                }
                $fresh->refresh(); // Refresh throws if the campaign was removed, which cannot authorize a write.
                if (! CampaignSpendGuardrails::canEnable($fresh, $approvedByUser)
                    || ! $approvedByUser && ($fresh->customer?->service_type !== 'managed' || CampaignSpendGuardrails::automaticChangesSuspended($fresh))) {
                    return $this->blocked('Campaign authorization changed during the final spend verification. Review the current limits before applying the proposal.');
                }
                $proposed = $this->project($fresh, $after, $changes);
                $snapshot = $after;
                $result = $this->gate->evaluate($fresh, $proposed, $assessment);
            }

            return ['ready' => $result['ready'], 'status' => $result['status'], 'issues' => $result['issues'],
                'message' => $result['ready'] ? 'The proposed Search changes have current demand and affordable forecast evidence.'
                    : AgentIssue::toSentence($result['issues']),
                'snapshot' => $proposed, 'observed_snapshot' => $snapshot, 'assessment' => $assessment, 'reach_gate' => $result];
        } catch (\Throwable $e) {
            report($e);

            return $this->blocked('Fresh Search demand or forecast evidence is unavailable; no campaign change was authorized.', 'unavailable');
        }
    }

    private function project(Campaign $campaign, array $snapshot, array $changes): array
    {
        $customerId = $campaign->customer->cleanGoogleCustomerId();
        $keywords = $snapshot['keywords'] ?? [];
        $activeGroups = array_column(array_filter($keywords, fn ($keyword) => ($keyword['ad_group_status'] ?? null) === 'ENABLED'), 'ad_group_resource');
        // Exclusions must be staged before additions, regardless of producer ordering.
        usort($changes, fn ($a, $b) => (($a['action'] ?? null) === 'negative' ? 0 : 1) <=> (($b['action'] ?? null) === 'negative' ? 0 : 1));
        foreach ($changes as $change) {
            $action = $change['action'] ?? null;
            if ($action === 'bidding') {
                $ceiling = $change['cpc_bid_ceiling_micros'] ?? null;
                if (($change['strategy'] ?? null) !== 'MAXIMIZE_CLICKS' || ! is_int($ceiling) || $ceiling <= 0
                    || ! CampaignSpendGuardrails::permitsBiddingStrategy($campaign, 'MAXIMIZE_CLICKS', $ceiling)
                    || isset($change['expected_bidding_strategy']) && $change['expected_bidding_strategy'] !== ($snapshot['bidding_strategy'] ?? null)) {
                    throw new \InvalidArgumentException('A capped bidding repair requires its exact approved strategy, ceiling and current strategy.');
                }
                $snapshot['bidding_strategy'] = BiddingStrategyType::TARGET_SPEND;
                $snapshot['cpc_bid_ceiling_micros'] = $snapshot['actual_cpc_ceiling_micros'] = $ceiling;

                continue;
            }
            if (in_array($action, ['add', 'negative'], true)) {
                $text = trim($change['text'] ?? '');
                $match = strtoupper($change['match_type'] ?? '');
                if ($text === '' || ! in_array($match, ['EXACT', 'PHRASE', 'BROAD'], true)) {
                    throw new \InvalidArgumentException('A keyword change requires text and a known match type.');
                }
                $group = $change['ad_group_resource'] ?? null;
                if ($action === 'add' || ($change['scope'] ?? 'campaign') === 'ad_group') {
                    if (! is_string($group) || ! preg_match('~^customers/'.preg_quote($customerId, '~').'/adGroups/\d+$~', $group)
                        || ! in_array($group, $activeGroups, true)) {
                        throw new \InvalidArgumentException('A keyword addition must use this campaign’s verified active ad group.');
                    }
                }
                if ($action === 'negative') {
                    $snapshot['negative_keywords'][] = ['text' => $text, 'match_type' => $match,
                        'scope' => $change['scope'] ?? 'campaign', 'ad_group_resource' => $group];

                    continue;
                }
                if (! is_string($change['selection_reason'] ?? null) || trim($change['selection_reason']) === '') {
                    throw new \InvalidArgumentException('A new keyword requires a recorded commercial selection reason.');
                }
                if (GoogleSearchReachPlanner::negativeConflict($text, $snapshot['negative_keywords'] ?? [], $group)) {
                    throw new \InvalidArgumentException('The proposed keyword conflicts with a current negative keyword.');
                }
                foreach ($keywords as $existing) {
                    if (($existing['ad_group_resource'] ?? null) === $group && ($existing['match_type'] ?? null) === $match
                        && mb_strtolower(trim($existing['text'] ?? '')) === mb_strtolower($text)
                        && ($existing['status'] ?? null) !== 'REMOVED') {
                        throw new \InvalidArgumentException('The proposed keyword already exists; duplicate additions cannot be forecast as extra reach.');
                    }
                }
                $added = ['text' => $text, 'match_type' => $match, 'ad_group_resource' => $group,
                    'status' => 'ENABLED', 'ad_group_status' => 'ENABLED', 'selection_reason' => $change['selection_reason']];
                if (($snapshot['bidding_strategy'] ?? null) === BiddingStrategyType::MANUAL_CPC) {
                    $groupKeywords = array_filter($keywords, fn ($keyword) => ($keyword['ad_group_resource'] ?? null) === $group);
                    $bids = array_column($groupKeywords, 'ad_group_cpc_bid_micros');
                    $bid = $change['bid_micros'] ?? ($bids === [] ? 0 : max($bids));
                    if (! is_int($bid) || $bid <= 0 || ! CampaignSpendGuardrails::permitsKeywordBid($campaign, $bid)) {
                        throw new \InvalidArgumentException('The new keyword’s inherited manual CPC could not be verified.');
                    }
                    $added['cpc_bid_micros'] = $bid;
                }
                $keywords[] = $added;

                continue;
            }
            if (! in_array($action, ['enable', 'pause', 'remove', 'bid'], true)) {
                throw new \InvalidArgumentException('Unsupported Search mutation cannot bypass reach verification.');
            }
            $resource = $change['resource_name'] ?? null;
            $found = false;
            foreach ($keywords as &$keyword) {
                if (($keyword['resource_name'] ?? $keyword['resource'] ?? null) !== $resource || ! is_string($resource)) {
                    continue;
                }
                $found = true;
                if ($action === 'bid') {
                    $bid = $change['bid_micros'] ?? null;
                    if (($snapshot['bidding_strategy'] ?? null) !== BiddingStrategyType::MANUAL_CPC || ! is_int($bid) || $bid <= 0
                        || ! CampaignSpendGuardrails::permitsKeywordBid($campaign, $bid)) {
                        throw new \InvalidArgumentException('Only a verified, permitted manual CPC keyword bid can be forecast.');
                    }
                    $keyword['cpc_bid_micros'] = $bid;
                } else {
                    $keyword['status'] = $action === 'enable' ? 'ENABLED' : ($action === 'pause' ? 'PAUSED' : 'REMOVED');
                }
            }
            unset($keyword);
            if (! $found) {
                throw new \InvalidArgumentException('The changed keyword does not belong to this campaign’s current snapshot.');
            }
        }
        $snapshot['keywords'] = $keywords;
        if (($snapshot['bidding_strategy'] ?? null) === BiddingStrategyType::MANUAL_CPC) {
            $active = array_filter($keywords, fn ($keyword) => ($keyword['status'] ?? null) === 'ENABLED' && ($keyword['ad_group_status'] ?? null) === 'ENABLED');
            $bids = array_column($active, 'cpc_bid_micros');
            if (count($bids) !== count($active) || array_filter($bids, fn ($bid) => ! is_int($bid) || $bid <= 0)) {
                throw new \InvalidArgumentException('Every enabled manual CPC keyword requires a verified effective bid.');
            }
            $snapshot['cpc_bid_ceiling_micros'] = $snapshot['actual_cpc_ceiling_micros'] = $bids === [] ? 0 : max($bids);
        }

        return $snapshot;
    }

    private function fingerprint(array $snapshot): string
    {
        return GoogleSearchReachGate::settingsFingerprint($snapshot);
    }

    private function blocked(string $message, string $status = 'blocked'): array
    {
        return ['ready' => false, 'status' => $status, 'message' => $message,
            'issues' => [(new AgentIssue('search_mutation_reach_unverified', $message))->toArray()]];
    }

    protected function inspect(Campaign $campaign): ?array
    {
        return app(InspectSearchDelivery::class, ['customer' => $campaign->customer])->inspect($campaign, CarbonImmutable::now()->subDays(2));
    }

    protected function spend(Campaign $campaign): array
    {
        return app(GetCampaignSpendSafety::class, ['customer' => $campaign->customer])->forCampaign($campaign);
    }
}
