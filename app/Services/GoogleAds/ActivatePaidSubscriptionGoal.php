<?php

namespace App\Services\GoogleAds;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Setting;
use Google\Ads\GoogleAds\V22\Resources\CampaignConversionGoal;
use Google\Ads\GoogleAds\V22\Resources\ConversionAction;
use Google\Ads\GoogleAds\V22\Services\CampaignConversionGoalOperation;
use Google\Ads\GoogleAds\V22\Services\ConversionActionOperation;
use Google\Ads\GoogleAds\V22\Services\MutateGoogleAdsRequest;
use Google\Ads\GoogleAds\V22\Services\MutateOperation;
use Google\Protobuf\FieldMask;

/** Explicitly dispatched for an owner-approved campaign, never a scheduled sweep. */
class ActivatePaidSubscriptionGoal extends BaseGoogleAdsService
{
    public function activate(Campaign $campaign, DataManagerService $dataManager): void
    {
        $customerId = $this->customer->cleanGoogleCustomerId();
        if ($customerId !== (string) config('conversions.google_ads_customer_id') || $campaign->customer_id !== $this->customer->id) {
            throw new \LogicException('Paid subscription goals belong only to the own-site advertising account.');
        }
        $resource = Setting::get('conversion_resource_name.paid_subscription');
        if (! is_string($resource) || ! preg_match('~^customers/(\d+)/conversionActions/(\d+)$~', $resource, $parts) || $parts[1] !== $customerId) {
            throw new \RuntimeException('Paid subscription action is not provisioned for this account.');
        }

        // Credentials can work for old actions while Google's ingestion service
        // has not learned about a newly created action yet. A validation request
        // creates no event and must succeed before we change bidding goals.
        $validation = $dataManager->ingestConversion(
            operatingAccountId: $customerId,
            conversionActionId: $parts[2],
            adIdentifiers: ['gclid' => 'DIAGNOSTIC_FAKE_GCLID_0000'],
            value: 1,
            currency: 'USD',
            occurredAt: now(),
            validateOnly: true,
            transactionId: 'validate-paid-subscription-goal',
        );
        if (! $validation['success']) {
            throw new \RuntimeException('Paid subscription action is not ready: '.($validation['error'] ?? 'validation failed'));
        }

        $campaignId = $campaign->googleCampaignNumericId();
        if (! $campaignId || ! ctype_digit($campaignId)) {
            throw new \LogicException('Campaign is missing its Google ID.');
        }
        $state = $this->readRows($customerId, "SELECT campaign.status FROM campaign WHERE campaign.id = {$campaignId}");
        if (($state[0]['campaign']['status'] ?? null) !== 'ENABLED') {
            throw new \RuntimeException('Campaign is no longer enabled; its goals were not changed.');
        }
        $config = $this->readRows($customerId, "SELECT conversion_goal_campaign_config.custom_conversion_goal FROM conversion_goal_campaign_config WHERE campaign.id = {$campaignId}");
        if (! empty($config[0]['conversionGoalCampaignConfig']['customConversionGoal'])) {
            throw new \RuntimeException('Campaign now uses a custom goal; refusing to override it.');
        }

        $actionQuery = "SELECT conversion_action.resource_name, conversion_action.name, conversion_action.type, conversion_action.category, conversion_action.origin, conversion_action.primary_for_goal FROM conversion_action WHERE conversion_action.status = 'ENABLED'";
        $goalQuery = "SELECT campaign_conversion_goal.resource_name, campaign_conversion_goal.category, campaign_conversion_goal.origin, campaign_conversion_goal.biddable FROM campaign_conversion_goal WHERE campaign.id = {$campaignId}";
        $actions = $this->readRows($customerId, $actionQuery);
        $goals = $this->readRows($customerId, $goalQuery);
        $paid = collect($actions)->pluck('conversionAction')->firstWhere('resourceName', $resource);
        if (! $paid || $paid['type'] !== 'UPLOAD_CLICKS' || $paid['category'] !== 'PURCHASE') {
            throw new \RuntimeException('Paid subscription action has an unexpected type or category.');
        }
        if (! collect($goals)->contains(fn ($row) => $row['campaignConversionGoal']['category'] === $paid['category'] && $row['campaignConversionGoal']['origin'] === $paid['origin'])) {
            throw new \RuntimeException('Google has not created the paid subscription campaign goal yet.');
        }

        $actionOps = [];
        foreach ($actions as $row) {
            $action = $row['conversionAction'];
            $primary = $action['resourceName'] === $resource;
            if (($action['primaryForGoal'] ?? false) === $primary) {
                continue;
            }
            $actionOps[] = new ConversionActionOperation([
                'update' => new ConversionAction(['resource_name' => $action['resourceName'], 'primary_for_goal' => $primary]),
                'update_mask' => new FieldMask(['paths' => ['primary_for_goal']]),
            ]);
        }
        $goalOps = [];
        foreach ($goals as $row) {
            $goal = $row['campaignConversionGoal'];
            $biddable = $goal['category'] === $paid['category'] && $goal['origin'] === $paid['origin'];
            if (($goal['biddable'] ?? false) === $biddable) {
                continue;
            }
            $goalOps[] = new CampaignConversionGoalOperation([
                'update' => new CampaignConversionGoal(['resource_name' => $goal['resourceName'], 'biddable' => $biddable]),
                'update_mask' => new FieldMask(['paths' => ['biddable']]),
            ]);
        }
        // Change primaries and campaign goals atomically so a failed request
        // cannot leave the campaign optimising for an empty set of actions.
        $operations = array_merge(
            array_map(fn ($op) => new MutateOperation(['conversion_action_operation' => $op]), $actionOps),
            array_map(fn ($op) => new MutateOperation(['campaign_conversion_goal_operation' => $op]), $goalOps),
        );
        $this->applyOperations($customerId, $operations);
        if ($this->dryRun) {
            return;
        }

        $afterActions = $this->readRows($customerId, $actionQuery);
        $afterGoals = $this->readRows($customerId, $goalQuery);
        $primaries = collect($afterActions)->pluck('conversionAction')->filter(fn ($action) => $action['primaryForGoal'] ?? false)->pluck('resourceName')->all();
        $biddableGoals = collect($afterGoals)->pluck('campaignConversionGoal')->filter(fn ($goal) => $goal['biddable'] ?? false)->values();
        if ($primaries !== [$resource] || $biddableGoals->count() !== 1 || $biddableGoals[0]['category'] !== $paid['category'] || $biddableGoals[0]['origin'] !== $paid['origin']) {
            throw new \RuntimeException('Google has not confirmed the requested paid subscription goal settings.');
        }
        AgentActivity::record('optimization', 'paid_subscription_goal_activated',
            'Google validated the paid subscription action. Campaign now optimises for new paying subscribers.',
            $campaign->customer_id, $campaign->id,
            ['conversion_action' => $resource, 'validation_request_id' => $validation['requestId'] ?? null,
                'before' => ['actions' => $actions, 'goals' => $goals], 'after' => ['actions' => $afterActions, 'goals' => $afterGoals]]);
    }

    protected function readRows(string $customerId, string $query): array
    {
        $this->ensureClient();
        $rows = [];
        foreach ($this->searchQuery($customerId, $query)->iterateAllElements() as $row) {
            $rows[] = json_decode($row->serializeToJsonString(), true);
        }

        return $rows;
    }

    protected function applyOperations(string $customerId, array $operations): void
    {
        if ($operations) {
            $this->client->getGoogleAdsServiceClient()->mutate(new MutateGoogleAdsRequest([
                'validate_only' => $this->dryRun,
                'customer_id' => $customerId,
                'mutate_operations' => $operations,
                'partial_failure' => false,
            ]));
        }
    }
}
