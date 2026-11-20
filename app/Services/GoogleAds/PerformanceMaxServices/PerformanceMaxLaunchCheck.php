<?php

namespace App\Services\GoogleAds\PerformanceMaxServices;

use App\Models\Campaign;
use App\Models\Strategy;
use App\Services\Agents\ExecutionBudgetGuard;
use App\Services\Agents\ExecutionContext;
use App\Services\Agents\ExecutionPlan;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\CampaignStatusHelper;
use App\Services\Creative\GoogleCreativeCta;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use App\Services\GoogleAds\ReconcileCampaignConversionGoals;
use Google\Ads\GoogleAds\V26\Enums\CallToActionTypeEnum\CallToActionType;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;
use Illuminate\Support\Facades\DB;

class PerformanceMaxLaunchCheck
{
    public function ready(Strategy $strategy, string $resource): bool
    {
        $strategy->refresh()->load('campaign.customer');
        $campaign = $strategy->campaign;
        $customer = $campaign->customer;
        $stage = $strategy->execution_result['metadata']['google_pmax_staging'] ?? [];
        if (! $customer || ! $strategy->signed_off_at || $strategy->deployment_status !== 'verified'
            || $strategy->campaign_type !== 'performance_max' || $resource !== $strategy->reusableGoogleCampaignId()
            || ($stage['campaign_resource'] ?? null) !== $resource
            || CampaignStatusHelper::getGoogleAdsStatus(customer: $customer) !== CampaignStatus::ENABLED
            || ! app(ExecutionBudgetGuard::class)->validate(new ExecutionPlan([]), new ExecutionContext($strategy, $campaign, $customer), 'google')->passed) {
            return false;
        }
        $account = $customer->cleanGoogleCustomerId();
        $reader = app(ReadCampaignConfiguration::class, ['customer' => $customer]);
        $snapshot = $reader->performanceMax($account, $resource);
        if (! $this->matches($stage, $snapshot)) {
            return false;
        }
        $goals = app(ReconcileCampaignConversionGoals::class, ['customer' => $customer])->inspect($strategy, $resource);
        $fresh = $strategy->fresh();

        $current = $campaign->fresh(['customer']);

        return ($goals['configuration_verified'] ?? false) === true
            && ($goals['intent']['action_resource'] ?? null) === ($stage['goal_action'] ?? '')
            && $fresh?->deployment_status === 'verified' && $fresh->signed_off_at !== null
            && $fresh->bidding_strategy === $strategy->bidding_strategy
            && $fresh->daily_budget === $strategy->daily_budget
            && ($fresh->execution_result['metadata']['google_pmax_staging'] ?? []) === $stage
            && $current && $current->customer?->cleanGoogleCustomerId() === $account
            && CampaignSpendGuardrails::canEnable($current) && CampaignSpendGuardrails::hasLaunchFunding($current->customer)
            && CampaignSpendGuardrails::moneyToMicros($current->approved_daily_budget) >= $stage['budget_micros']
            && CampaignSpendGuardrails::permitsDailyBudget($current, $stage['budget_micros'])
            && $reader->campaignStatus($account, $resource) === 'PAUSED'
            && $this->recordVerifiedConfiguration($strategy, $snapshot, 'PAUSED');
    }

    /** Persist a successful read without changing the campaign or strategy lifecycle. */
    public function recordVerifiedConfiguration(Strategy $strategy, array $snapshot, string $status): bool
    {
        return DB::transaction(function () use ($strategy, $snapshot, $status) {
            $campaign = Campaign::whereKey($strategy->campaign_id)->lockForUpdate()->firstOrFail();
            $locked = Strategy::whereKey($strategy->id)->lockForUpdate()->firstOrFail();
            $result = $locked->execution_result ?? [];
            $stage = $result['metadata']['google_pmax_staging'] ?? [];
            $goal = $result['metadata']['conversion_goal_readiness'] ?? [];
            $expectedDeploymentStatus = $status === 'PAUSED' ? 'verified' : 'active';
            $account = $campaign->customer?->cleanGoogleCustomerId();
            $resource = $stage['campaign_resource'] ?? '';
            $goalCheckedAt = is_string($goal['checked_at'] ?? null) ? strtotime($goal['checked_at']) : false;

            if ($locked->campaign_type !== 'performance_max' || $locked->deployment_status !== $expectedDeploymentStatus
                || ! $locked->signed_off_at || ! $locked->deployed_at || ! $account
                || $locked->deployed_at->ne($strategy->deployed_at) || $stage !== ($strategy->execution_result['metadata']['google_pmax_staging'] ?? [])
                || $locked->conversion_goals !== $strategy->conversion_goals
                || ! preg_match('#^customers/'.preg_quote($account, '#').'/campaigns/\d+$#D', $resource)
                || $locked->reusableGoogleCampaignId() !== $resource
                || CampaignSpendGuardrails::moneyToMicros($locked->daily_budget) !== ($stage['budget_micros'] ?? null)
                || ($goal['configuration_verified'] ?? false) !== true || ($goal['campaign_resource'] ?? null) !== $resource
                || ($goal['intent']['action_resource'] ?? null) !== ($stage['goal_action'] ?? '')
                || $goalCheckedAt === false || $goalCheckedAt < $locked->deployed_at->timestamp || $goalCheckedAt > now()->timestamp
                || $goalCheckedAt < now()->subHours((int) config('optimization.search_delivery.stale_after_hours', 3))->timestamp
                || ! $this->matches($stage, $snapshot, $status)) {
                return false;
            }

            $result['metadata']['configuration_verification'] = [
                'passed' => true, 'issues' => [], 'scope' => 'configuration_and_goals',
                'checked_at' => now()->toIso8601String(),
            ];
            $locked->update(['execution_result' => $result]);

            return true;
        });
    }

    public function matches(array $stage, array $snapshot, string $status = 'PAUSED'): bool
    {
        $campaign = $snapshot['campaign']['campaign'] ?? [];
        $groups = array_column($snapshot['groups'] ?? [], 'assetGroup');
        $automations = array_column($campaign['assetAutomationSettings'] ?? [], 'assetAutomationStatus', 'assetAutomationType');

        return ($campaign['resourceName'] ?? null) === ($stage['campaign_resource'] ?? '')
            && ($campaign['status'] ?? null) === $status
            && ($campaign['advertisingChannelType'] ?? null) === 'PERFORMANCE_MAX'
            && ($campaign['biddingStrategyType'] ?? null) === 'MAXIMIZE_CONVERSIONS'
            && (int) ($campaign['maximizeConversions']['targetCpaMicros'] ?? 0) === 0
            && ($automations['FINAL_URL_EXPANSION_TEXT_ASSET_AUTOMATION'] ?? null) === 'OPTED_OUT'
            && ($automations['TEXT_ASSET_AUTOMATION'] ?? null) === 'OPTED_OUT'
            && (int) ($snapshot['campaign']['campaignBudget']['amountMicros'] ?? 0) === ($stage['budget_micros'] ?? -1)
            && count($groups) === 1
            && ($groups[0]['resourceName'] ?? null) === ($stage['asset_group'] ?? '')
            && ($groups[0]['status'] ?? null) === 'ENABLED'
            && ($groups[0]['finalUrls'] ?? []) === ($stage['final_urls'] ?? null)
            && (! isset($stage['native_cta']) || self::linkedCtas($snapshot, $stage['asset_group']) === [CallToActionType::name(GoogleCreativeCta::type($stage['native_cta']))]);
    }

    public static function linkedCtas(array $snapshot, string $group): array
    {
        return collect($snapshot['ctas'] ?? [])->where('assetGroup.resourceName', $group)
            ->pluck('asset.callToActionAsset.callToAction')->values()->all();
    }
}
