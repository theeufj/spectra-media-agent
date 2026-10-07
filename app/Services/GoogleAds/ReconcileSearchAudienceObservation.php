<?php

namespace App\Services\GoogleAds;

use App\Models\Strategy;
use App\Services\Agents\AgentIssue;
use Google\Ads\GoogleAds\V22\Common\TargetingSetting;
use Google\Ads\GoogleAds\V22\Common\TargetRestriction;
use Google\Ads\GoogleAds\V22\Common\TargetRestrictionOperation;
use Google\Ads\GoogleAds\V22\Common\TargetRestrictionOperation\Operator;
use Google\Ads\GoogleAds\V22\Enums\TargetingDimensionEnum\TargetingDimension;
use Google\Ads\GoogleAds\V22\Resources\AdGroup;
use Google\Ads\GoogleAds\V22\Resources\Campaign;
use Google\Ads\GoogleAds\V22\Services\AdGroupOperation;
use Google\Ads\GoogleAds\V22\Services\CampaignOperation;
use Google\Ads\GoogleAds\V22\Services\MutateGoogleAdsRequest;
use Google\Ads\GoogleAds\V22\Services\MutateOperation;
use Google\Protobuf\FieldMask;
use Illuminate\Support\Facades\Cache;

/** Search audience signals observe performance without narrowing keyword reach. */
class ReconcileSearchAudienceObservation extends BaseGoogleAdsService
{
    public function inspect(?string $campaignResource = null): array
    {
        return $this->check($campaignResource, false);
    }

    public function reconcile(?string $campaignResource = null): array
    {
        return $this->check($campaignResource, true);
    }

    /** Explicit deployment may configure a staged PAUSED group without enabling it. */
    public function ensureForAdGroup(string $adGroupResource, string $campaignResource): array
    {
        if (ctype_digit($campaignResource) && $this->customer?->cleanGoogleCustomerId()) {
            $campaignResource = 'customers/'.$this->customer->cleanGoogleCustomerId().'/campaigns/'.$campaignResource;
        }
        if (! $this->validResource($adGroupResource, 'adGroups') || ! $this->validResource($campaignResource, 'campaigns')) {
            return $this->state('unknown', false, false, issues: [new AgentIssue('search_audience_scope_invalid', 'The Search ad group does not belong to this Google account.')]);
        }
        try {
            $customerId = $this->customer->cleanGoogleCustomerId();
            $rows = $this->readRows($customerId, "SELECT campaign.resource_name, ad_group.resource_name FROM ad_group WHERE ad_group.resource_name = '{$adGroupResource}'");
            $row = $rows[0] ?? [];
            if (($row['adGroup']['resourceName'] ?? null) !== $adGroupResource) {
                return $this->state('unknown', false, false, issues: [new AgentIssue('search_audience_group_unavailable', 'Google has not confirmed the Search ad group.')]);
            }
            if (($row['campaign']['resourceName'] ?? null) !== $campaignResource) {
                return $this->state('unknown', false, false, issues: [new AgentIssue('search_audience_campaign_mismatch', 'Audience signals were skipped because the ad group is not in the intended Google campaign.')]);
            }

            return $this->check($campaignResource, true, $adGroupResource);
        } catch (\Throwable $e) {
            report($e);

            return $this->unavailable();
        }
    }

    /** Only declared modes count as intent; selected interests alone are signals. */
    public static function requestsAudienceOnly(Strategy $strategy): bool
    {
        $options = $strategy->targetingConfig->google_options ?? [];
        foreach (['audience_mode', 'audience_targeting_mode'] as $key) {
            $mode = strtolower(str_replace(['-', ' '], '_', (string) ($options[$key] ?? '')));
            if (in_array($mode, ['targeting', 'audience_only', 'remarketing', 'retargeting'], true)) {
                return true;
            }
        }

        return ($options['audience_only'] ?? false) === true || ($options['remarketing'] ?? false) === true
            || ($options['retargeting'] ?? false) === true;
    }

    private function check(?string $resource, bool $mutate, ?string $creationGroup = null): array
    {
        $actions = [];
        $groups = [];
        if (is_string($resource) && ctype_digit($resource) && $this->customer?->cleanGoogleCustomerId()) {
            $resource = 'customers/'.$this->customer->cleanGoogleCustomerId().'/campaigns/'.$resource;
        }
        if (! $this->validResource($resource, 'campaigns')) {
            return $this->state('unknown', false, false, issues: [new AgentIssue('search_audience_scope_invalid', 'A campaign in this Google account is required to verify audience Observation.')]);
        }
        try {
            $snapshot = $this->snapshot($resource, $creationGroup);
            $campaign = $snapshot['campaign'];
            if (($campaign['advertisingChannelType'] ?? '') !== 'SEARCH') {
                return in_array($campaign['advertisingChannelType'] ?? '', ['', 'UNKNOWN', 'UNSPECIFIED'], true)
                    ? $this->unavailable()
                    : $this->state('not_applicable', true, false, reason: 'non_search_campaign');
            }
            $groups = $this->describe($snapshot, $creationGroup);
            if ($this->hasExplicitIntent($resource)) {
                return $this->explicitIntentState($groups, $creationGroup);
            }
            if ($mutate && ! $this->allowedStatus($campaign['status'] ?? '', $creationGroup !== null)) {
                return $this->held('google_campaign_inactive', $groups);
            }
            foreach ($groups as $group) {
                if (! $group['requires_observation']) {
                    continue;
                }
                if (! $mutate) {
                    continue;
                }
                // API reads can outlive an approval, pause, billing or feature change.
                $fresh = $this->snapshot($resource, $creationGroup);
                if (($fresh['campaign']['advertisingChannelType'] ?? '') !== 'SEARCH'
                    || ! $this->allowedStatus($fresh['campaign']['status'] ?? '', $creationGroup !== null)) {
                    return $this->held('google_campaign_inactive', $this->describe($fresh, $creationGroup), $actions);
                }
                if ($this->hasExplicitIntent($resource)) {
                    return $this->explicitIntentState($this->describe($fresh, $creationGroup), $creationGroup, $actions);
                }
                $current = collect($this->describe($fresh, $creationGroup))->firstWhere('resource', $group['resource']);
                if (! $current || ! $this->allowedStatus($current['status'], $creationGroup !== null)) {
                    return $this->held('google_ad_group_inactive', $groups, $actions);
                }
                if (! $current['requires_observation']) {
                    continue;
                }
                $parentRestrictions = $fresh['campaign']['targetingSetting']['targetRestrictions'] ?? [];
                $atCampaign = $parentRestrictions !== [];
                $restrictions = $atCampaign ? $parentRestrictions : $fresh['groups'][$current['resource']]['targetingSetting']['targetRestrictions'] ?? [];
                $target = $atCampaign ? $resource : $current['resource'];
                // Incremental ADD replaces this dimension only. Sending the full
                // list would overwrite unrelated restrictions changed during reads.
                $setting = new TargetingSetting(['target_restriction_operations' => [new TargetRestrictionOperation([
                    'operator' => Operator::ADD,
                    'value' => new TargetRestriction(['targeting_dimension' => TargetingDimension::AUDIENCE, 'bid_only' => true]),
                ])]]);
                $mask = new FieldMask(['paths' => ['targeting_setting.target_restriction_operations']]);
                $operation = $atCampaign
                    ? new MutateOperation(['campaign_operation' => new CampaignOperation(['update' => new Campaign(['resource_name' => $target, 'targeting_setting' => $setting]), 'update_mask' => $mask])])
                    : new MutateOperation(['ad_group_operation' => new AdGroupOperation(['update' => new AdGroup(['resource_name' => $target, 'targeting_setting' => $setting]), 'update_mask' => $mask])]);
                if ($creationGroup === null && ($hold = $this->localMutationHold($resource, $current['resource']))) {
                    return $this->held($hold, $groups, $actions);
                }
                $this->applyOperations($this->customer->cleanGoogleCustomerId(), [$operation]);
                $actions[] = ['type' => 'search_audience_observation_updated', 'resource' => $target];
                $post = $this->snapshot($resource, $creationGroup);
                $postRestrictions = $atCampaign ? $post['campaign']['targetingSetting']['targetRestrictions'] ?? []
                    : $post['groups'][$current['resource']]['targetingSetting']['targetRestrictions'] ?? [];
                if (! $this->observes($postRestrictions) || $this->otherRestrictions($postRestrictions) !== $this->otherRestrictions($restrictions)) {
                    return $this->state('needs_review', false, true, $actions, [new AgentIssue('search_audience_observation_unverified', 'Google has not confirmed audience Observation with the other targeting restrictions preserved.')], $this->describe($post, $creationGroup));
                }
            }
            $groups = $this->describe($this->snapshot($resource, $creationGroup), $creationGroup);
            $unresolved = array_filter($groups, fn ($group) => $group['requires_observation']);
            if ($unresolved !== []) {
                return $this->state('needs_review', false, true, $actions, [new AgentIssue('search_audience_restricts_reach', 'Search audiences are restricting keyword reach. Audience signals should use Observation unless audience-only targeting was explicitly approved.')], $groups);
            }
            $applicable = $creationGroup !== null || array_sum(array_column($groups, 'audience_criteria_count')) > 0;

            return $this->state($applicable ? 'ready' : 'not_applicable', true, $applicable, $actions, adGroups: $groups, reason: $applicable ? null : 'no_audience_criteria');
        } catch (\Throwable $e) {
            report($e);

            return $this->unavailable($actions, $groups);
        }
    }

    private function snapshot(string $resource, ?string $onlyGroup): array
    {
        $customerId = $this->customer->cleanGoogleCustomerId();
        $id = basename($resource);
        $row = $this->readRows($customerId, "SELECT campaign.resource_name, campaign.status, campaign.advertising_channel_type, campaign.targeting_setting.target_restrictions FROM campaign WHERE campaign.id = {$id}")[0] ?? [];
        if (($row['campaign']['resourceName'] ?? null) !== $resource) {
            throw new \RuntimeException('The exact Google campaign could not be confirmed.');
        }
        if (($row['campaign']['advertisingChannelType'] ?? '') !== 'SEARCH') {
            return ['campaign' => $row['campaign'], 'groups' => [], 'counts' => []];
        }
        $groups = [];
        foreach ($this->readRows($customerId, "SELECT campaign.resource_name, ad_group.resource_name, ad_group.status, ad_group.targeting_setting.target_restrictions FROM ad_group WHERE campaign.id = {$id} AND ad_group.status != 'REMOVED'") as $groupRow) {
            $group = $groupRow['adGroup'] ?? [];
            if (($groupRow['campaign']['resourceName'] ?? null) !== $resource || ! $this->validResource($group['resourceName'] ?? null, 'adGroups')) {
                throw new \RuntimeException('The Google ad group scope could not be confirmed.');
            }
            if ($onlyGroup === null || $group['resourceName'] === $onlyGroup) {
                $groups[$group['resourceName']] = $group;
            }
        }
        if ($groups === []) {
            throw new \RuntimeException('No current Search ad group could be verified.');
        }
        $counts = [];
        foreach ($this->readRows($customerId, "SELECT campaign.resource_name, ad_group.resource_name, ad_group_criterion.type, ad_group_criterion.status, ad_group_criterion.negative FROM ad_group_criterion WHERE campaign.id = {$id} AND ad_group_criterion.type IN ('USER_INTEREST', 'USER_LIST', 'CUSTOM_AUDIENCE', 'COMBINED_AUDIENCE', 'AUDIENCE') AND ad_group_criterion.status = 'ENABLED'") as $criterion) {
            if (($criterion['campaign']['resourceName'] ?? null) !== $resource || ! $this->validResource($criterion['adGroup']['resourceName'] ?? null, 'adGroups')) {
                throw new \RuntimeException('The Google audience criterion scope could not be confirmed.');
            }
            $group = $criterion['adGroup']['resourceName'];
            if (isset($groups[$group]) && empty($criterion['adGroupCriterion']['negative'])
                && ($criterion['adGroupCriterion']['status'] ?? null) === 'ENABLED') {
                $counts[$group] = ($counts[$group] ?? 0) + 1;
            }
        }

        return ['campaign' => $row['campaign'], 'groups' => $groups, 'counts' => $counts];
    }

    private function describe(array $snapshot, ?string $creationGroup): array
    {
        $parent = $snapshot['campaign']['targetingSetting']['targetRestrictions'] ?? [];

        return array_values(array_map(function ($group) use ($snapshot, $parent, $creationGroup) {
            $count = $snapshot['counts'][$group['resourceName']] ?? 0;
            $observes = $this->observes($parent !== [] ? $parent : $group['targetingSetting']['targetRestrictions'] ?? []);

            return ['resource' => $group['resourceName'], 'status' => $group['status'] ?? 'UNKNOWN', 'audience_criteria_count' => $count,
                'observation' => $observes, 'restriction_source' => $parent !== [] ? 'campaign' : 'ad_group',
                'requires_observation' => ($count > 0 || $creationGroup !== null) && ! $observes];
        }, $snapshot['groups']));
    }

    private function observes(array $restrictions): bool
    {
        foreach ($restrictions as $restriction) {
            if (($restriction['targetingDimension'] ?? null) === 'AUDIENCE') {
                return ($restriction['bidOnly'] ?? false) === true;
            }
        }

        return false;
    }

    private function otherRestrictions(array $restrictions): array
    {
        $values = [];
        foreach ($restrictions as $restriction) {
            if (($restriction['targetingDimension'] ?? null) !== 'AUDIENCE') {
                $values[] = [($restriction['targetingDimension'] ?? null), ($restriction['bidOnly'] ?? false) === true];
            }
        }
        sort($values);

        return $values;
    }

    private function allowedStatus(string $status, bool $staged): bool
    {
        return $status === 'ENABLED' || $staged && $status === 'PAUSED';
    }

    private function validResource(?string $resource, string $collection): bool
    {
        $id = $this->customer?->cleanGoogleCustomerId();

        return $id && is_string($resource) && preg_match('#^customers/'.preg_quote($id, '#').'/'.$collection.'/\d+$#D', $resource) === 1;
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int, Strategy> */
    private function strategies(string $resource): \Illuminate\Database\Eloquent\Collection
    {
        return Strategy::whereHas('campaign', fn ($query) => $query->where('customer_id', $this->customer->id))
            ->with(['campaign.customer', 'targetingConfig'])->orderBy('id')->get()
            ->filter(fn ($strategy) => in_array($strategy->reusableGoogleCampaignId(), [$resource, basename($resource)], true));
    }

    private function hasExplicitIntent(string $resource): bool
    {
        return $this->strategies($resource)->contains(fn ($strategy) => self::requestsAudienceOnly($strategy));
    }

    private function explicitIntentState(array $groups, ?string $creationGroup, array $actions = []): array
    {
        foreach ($groups as $group) {
            if (($group['audience_criteria_count'] > 0 || $creationGroup !== null) && $group['observation']) {
                return $this->state('needs_review', false, true, $actions,
                    [new AgentIssue('explicit_audience_targeting_mismatch', 'This strategy explicitly requires audience-only targeting, but Google has audience Observation. Review the intended reach before changing it.')],
                    $groups, 'explicit_audience_targeting_mismatch');
            }
        }

        return $this->state('not_applicable', true, false, $actions, adGroups: $groups, reason: 'explicit_audience_targeting_intent');
    }

    private function localMutationHold(string $resource, string $group): ?string
    {
        $strategies = $this->strategies($resource);
        $strategy = $strategies->first(fn ($candidate) => in_array($candidate->google_ads_ad_group_id, [$group, basename($group)], true));
        if (! $strategy) {
            return 'search_strategy_owner_unverified';
        }
        Cache::forget('enabled_platform_slugs');
        Cache::forget('setting_managed_billing_enabled');
        $campaign = clone $strategy->campaign;
        $campaign->setAttribute('google_ads_campaign_id', $resource);

        return app(GoogleAdStrengthRepair::class)->mutationBlocked($campaign, $strategy);
    }

    private function held(string $reason, array $groups, array $actions = []): array
    {
        return $this->state('needs_review', false, true, $actions, [new AgentIssue('search_audience_mutation_held', 'Audience Observation changes are on hold: '.str_replace('_', ' ', $reason).'.')], $groups, $reason);
    }

    private function unavailable(array $actions = [], array $groups = []): array
    {
        return $this->state('unknown', false, false, $actions, [new AgentIssue('search_audience_check_unavailable', 'The current Search audience targeting could not be verified. Previous evidence has not been cleared.')], $groups);
    }

    private function state(string $status, bool $ready, bool $applicable, array $actions = [], array $issues = [], array $adGroups = [], ?string $reason = null): array
    {
        return ['status' => $status, 'ready' => $ready, 'applicable' => $applicable, 'checked_at' => now()->toIso8601String(),
            'actions' => $actions, 'issues' => $issues, 'ad_groups' => $adGroups, 'reason' => $reason];
    }

    protected function readRows(string $customerId, string $query): array
    {
        $this->ensureClient();
        $rows = [];
        foreach ($this->searchQuery($customerId, $query)->iterateAllElements() as $row) {
            $rows[] = json_decode($row->serializeToJsonString(), true, 512, JSON_THROW_ON_ERROR);
        }

        return $rows;
    }

    protected function applyOperations(string $customerId, array $operations): void
    {
        $this->ensureClient();
        $this->client->getGoogleAdsServiceClient()->mutate(new MutateGoogleAdsRequest([
            'customer_id' => $customerId, 'mutate_operations' => $operations, 'partial_failure' => false, 'validate_only' => $this->dryRun,
        ]));
    }
}
