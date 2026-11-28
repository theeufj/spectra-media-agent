<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Enums\CampaignStatus as LocalCampaignStatus;
use App\Models\Campaign as LocalCampaign;
use App\Models\Strategy;
use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\Campaigns\GoogleCampaignSpendSafety;
use App\Services\Campaigns\GoogleSearchTotalBudget;
use App\Services\CampaignStatusHelper;
use App\Services\Deployment\CampaignLaunchIntent;
use App\Services\GoogleAds\ApprovedAiMaxPilotActivation;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\InspectAiMaxPilot;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsException;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V26\Resources\Campaign;
use Google\Ads\GoogleAds\V26\Services\CampaignOperation;
use Google\Ads\GoogleAds\V26\Services\MutateCampaignsRequest;
use Google\ApiCore\ApiException;
use Google\Protobuf\FieldMask;

class UpdateCampaignStatus extends BaseGoogleAdsService
{
    private bool $ownerApprovedRestart = false;

    /**
     * Update the status of a Google Ads campaign.
     *
     * @param  string  $customerId  Google Ads customer ID (no dashes)
     * @param  string  $campaignResourceName  e.g. "customers/1234567890/campaigns/9876543210"
     * @param  string  $status  'ENABLED', 'PAUSED', or 'REMOVED'
     * @return array{success: bool, resource_name?: string, new_status?: string, error?: string, rejected?: bool}
     */
    public function execute(string $customerId, string $campaignResourceName, string $status): array
    {
        if (strtoupper($status) === 'ENABLED') {
            $local = CampaignSpendGuardrails::forGoogleResource($customerId, $campaignResourceName);
            if ($local && ! CampaignSpendGuardrails::canEnable($local, $this->ownerApprovedRestart)) {
                return ['success' => false, 'error' => 'Campaign is deliberately paused or held by spend safety. Review its limits and approve the restart first.'];
            }
            if ($local && CampaignSpendGuardrails::hasGoogleTotalBudget($local)) {
                try {
                    $ready = $this->fixedSearchReady($local, $customerId, $campaignResourceName);
                } catch (\Throwable $e) {
                    report($e);
                    $ready = false;
                }
                if (! $ready) {
                    return ['success' => false, 'error' => 'Google Search activation requires the approved total budget, schedule and verified launch.'];
                }
            }
            if ($local && ($pilot = CampaignSpendGuardrails::aiMaxPilot($local)) !== null) {
                try {
                    if (! $this->pilotRestartReady($local, $pilot)) {
                        return ['success' => false, 'error' => 'AI Max pilot spending and configuration must be freshly verified before activation.'];
                    }
                } catch (\Throwable $e) {
                    report($e);

                    return ['success' => false, 'error' => 'AI Max pilot activation could not be verified; the campaign was not enabled.'];
                }
            }
            if ($local) {
                try {
                    $issues = app(VerifySingleSearchLandingPage::class, ['customer' => $this->customer])
                        ->forEnable($local, $customerId, $campaignResourceName);
                } catch (\Throwable $e) {
                    report($e);
                    $issues = ['The reviewed Search landing page could not be verified.'];
                }
                if ($issues !== []) {
                    return ['success' => false, 'error' => implode(' ', $issues)];
                }
            }
        }
        $this->ensureClient();

        try {
            $operation = new CampaignOperation;

            if (strtoupper($status) === 'REMOVED') {
                // Google deletes a campaign with a remove operation. Sending an
                // update that sets status = REMOVED is rejected outright with
                // INVALID_ARGUMENT, so this branch never worked — and because
                // the method reports failure by return value rather than by
                // throwing, callers that only guarded against exceptions
                // reported success while the campaign stayed put.
                $operation->setRemove($campaignResourceName);
            } else {
                $statusEnum = match (strtoupper($status)) {
                    'ENABLED' => CampaignStatus::ENABLED,
                    'PAUSED' => CampaignStatus::PAUSED,
                    default => throw new \InvalidArgumentException("Invalid status: {$status}. Must be ENABLED, PAUSED, or REMOVED."),
                };

                $campaign = new Campaign([
                    'resource_name' => $campaignResourceName,
                    'status' => $statusEnum,
                ]);

                $operation->setUpdate($campaign);
                $operation->setUpdateMask(new FieldMask(['paths' => ['status']]));
            }

            $this->client->getCampaignServiceClient()->mutateCampaigns(
                new MutateCampaignsRequest([
                    'validate_only' => $this->dryRun,
                    'customer_id' => $customerId,
                    'operations' => [$operation],
                ])
            );

            $this->logInfo("Campaign status updated: {$campaignResourceName} -> {$status}");

            return [
                'success' => true,
                'resource_name' => $campaignResourceName,
                'new_status' => $status,
            ];
        } catch (GoogleAdsException|ApiException $e) {
            $this->logError('Failed to update campaign status: '.$e->getMessage());

            return [
                'success' => false,
                'error' => $e->getMessage(),
                'rejected' => in_array($e->getStatus(), ['INVALID_ARGUMENT', 'PERMISSION_DENIED', 'FAILED_PRECONDITION'], true),
            ];
        }
    }

    private function fixedSearchReady(LocalCampaign $campaign, string $account, string $resource): bool
    {
        $strategies = $campaign->strategies()->limit(2)->get();
        if ($strategies->count() !== 1 || $this->customer?->cleanGoogleCustomerId() !== $account) {
            return false;
        }
        $strategy = $strategies->first()->setRelation('campaign', $campaign);
        $expected = GoogleSearchTotalBudget::forStrategy($strategy);
        if ($expected === null || ! $this->hasFixedSearchActivation($strategy, $resource, $expected)
            || ! app(ReadCampaignConfiguration::class, ['customer' => $this->customer])
                ->hasVerifiedSearchTotalBudget($account, $resource, $expected)) {
            return false;
        }

        $fresh = $strategy->fresh(['campaign.customer']);
        $current = $fresh?->campaign;
        $owner = $current?->customer;

        return $fresh && $current && $owner && $owner->cleanGoogleCustomerId() === $account
            && CampaignSpendGuardrails::canEnable($current, $this->ownerApprovedRestart)
            && CampaignSpendGuardrails::hasLaunchFunding($owner)
            && CampaignStatusHelper::getGoogleAdsStatus(null, $owner) === CampaignStatus::ENABLED
            && GoogleSearchTotalBudget::forStrategy($fresh) === $expected
            && $this->hasFixedSearchActivation($fresh, $resource, $expected)
            && ($fresh->launch_intent === null || app(CampaignLaunchIntent::class)->canPublish($fresh, $this->ownerApprovedRestart));
    }

    private function hasFixedSearchActivation(Strategy $strategy, string $resource, array $expected): bool
    {
        $metadata = $strategy->execution_result['metadata'] ?? [];
        $activation = $metadata['search_reach_activation'] ?? [];
        if ($strategy->google_ads_campaign_id !== $resource
            || ! in_array($strategy->deployment_status, ['deployed', 'verified', 'active'], true)
            || ($metadata['google_search_staging']['campaign_resource'] ?? null) !== $resource
            || ($metadata['google_search_staging']['enable_after_success'] ?? null) !== true
            || ($metadata['google_search_baseline']['google_total_budget'] ?? null) != $expected
            || ($activation['campaign_resource'] ?? null) !== $resource
            || ! array_key_exists('launch_id', $activation)
            || $activation['launch_id'] !== ($strategy->launch_intent['id'] ?? null)) {
            return false;
        }
        if (($activation['enabled_verified'] ?? null) === true && ($activation['status'] ?? null) === 'enabled_verified') {
            return true;
        }

        $timestamp = $activation['enable_attempted_at'] ?? null;
        $attempted = is_string($timestamp) ? strtotime($timestamp) : false;

        return ! $this->ownerApprovedRestart && $strategy->campaign?->status === LocalCampaignStatus::Active
            && ($activation['status'] ?? null) === 'activation_ready' && ($activation['enabled_verified'] ?? null) === false
            && ($activation['preflight']['validated_campaign_resource'] ?? null) === $resource
            && ($activation['preflight']['gate']['ready'] ?? null) === true
            && ($activation['activation_check']['ready'] ?? null) === true
            && ($activation['activation_check']['metadata']['google_total_budget'] ?? null) == $expected
            && $attempted !== false && $attempted >= now()->subMinutes(5)->timestamp && $attempted <= now()->timestamp;
    }

    protected function pilotRestartReady(\App\Models\Campaign $campaign, array $pilot): bool
    {
        if (! app(ApprovedAiMaxPilotActivation::class)->ownerRequestAllowsActivation($campaign, $pilot)) {
            return false;
        }
        $this->checkPilotSpend($campaign);
        $readiness = $this->inspectPilot($campaign, $pilot);
        $fresh = $campaign->fresh(['customer']);

        return ($readiness['ready'] ?? null) === true && $fresh !== null
            && CampaignSpendGuardrails::canEnable($fresh, $this->ownerApprovedRestart)
            && CampaignSpendGuardrails::aiMaxPilot($fresh) === $pilot
            && app(ApprovedAiMaxPilotActivation::class)->ownerRequestAllowsActivation($fresh, $pilot);
    }

    protected function checkPilotSpend(\App\Models\Campaign $campaign): void
    {
        app(GoogleCampaignSpendSafety::class)->check($campaign);
    }

    protected function inspectPilot(\App\Models\Campaign $campaign, array $pilot): array
    {
        return (new InspectAiMaxPilot($campaign->customer))->inspect($campaign, $pilot);
    }

    /**
     * Pause a campaign.
     */
    public function pause(string $customerId, string $campaignResourceName): array
    {
        return $this->execute($customerId, $campaignResourceName, 'PAUSED');
    }

    /**
     * Enable/start a campaign.
     */
    public function enable(string $customerId, string $campaignResourceName, bool $ownerApprovedRestart = false): array
    {
        $previous = $this->ownerApprovedRestart;
        $this->ownerApprovedRestart = $ownerApprovedRestart;
        try {
            return $this->execute($customerId, $campaignResourceName, 'ENABLED');
        } finally {
            $this->ownerApprovedRestart = $previous;
        }
    }

    public function confirmedStatus(string $account, string $resource, string $status): bool
    {
        if (! ctype_digit($account) || ! preg_match("~^customers/{$account}/campaigns/\d+$~D", $resource)) {
            return false;
        }
        $this->ensureClient();
        $rows = $this->searchQuery($account, "SELECT campaign.resource_name, campaign.status FROM campaign WHERE campaign.resource_name = '{$resource}'");
        foreach ($rows->iterateAllElements() as $row) {
            $remote = $row->getCampaign();

            return $remote->getResourceName() === $resource && \Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus::name($remote->getStatus()) === $status;
        }

        return false;
    }
}
