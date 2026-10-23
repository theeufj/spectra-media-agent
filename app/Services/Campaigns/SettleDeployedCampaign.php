<?php

namespace App\Services\Campaigns;

use App\Enums\CampaignStatus;
use App\Jobs\RecordSiteConversion;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionResult;
use App\Services\Agents\Google\SearchKeywordBuilder;
use App\Services\CampaignStatusHelper;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use App\Services\GoogleAds\CommonServices\UpdateCampaignStatus;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus as GoogleCampaignStatus;
use Illuminate\Support\Facades\Log;

/**
 * Bring a campaign's lifecycle status into line once its objects are live.
 *
 * This used to live at the bottom of DeployCampaign::handle(), which meant it
 * only ran when that job reached its final lines. It frequently does not: the
 * job talks to four ad platforms in sequence, and a worker restart part-way
 * through leaves the strategies mid-flight and the campaign untouched.
 *
 * ReconcileStuckDeployments then picks those strategies up an hour later,
 * asks the platform whether the objects actually exist, and marks the ones that
 * do as `deployed`. It had no way to finish the other half of the job, so the
 * campaign stayed at `draft` while its ads served.
 *
 * That is not a cosmetic mismatch. `campaigns.status` is what the optimisation
 * side of the product filters on — CampaignFetchPerformanceData, HealthCheck,
 * SelfHealing, AudienceIntelligence, PortfolioOptimization, BudgetAllocation
 * and DetectKeywordCannibalization all select `status = active` — while
 * ProcessDailyAdSpendBilling deliberately also matches `platform_status =
 * ENABLED` so a pause can never outrun the billing. The two together mean a
 * campaign in this state is charged for every day, and looked at by nothing:
 * no performance data is fetched for it, so it is invisible in reports, and no
 * agent will notice it failing.
 *
 * Campaign::applyPlatformStatus() will not fix it either, and should not — it
 * refuses to promote a draft on purpose, because a draft carrying a platform id
 * is ambiguous when all you have is a status read. This class is the other
 * case: the deployment is known to have succeeded, because we are the ones who
 * ran it.
 */
class SettleDeployedCampaign
{
    /**
     * @return bool whether the lifecycle status moved
     */
    public function settle(Campaign $campaign): bool
    {
        $customer = $campaign->customer;

        if (! $customer) {
            return false;
        }

        if ($customer->service_type === 'setup_only') {
            return $this->settleSetupOnly($campaign, $customer);
        }

        $previousStatus = $campaign->status;
        $staged = $campaign->strategies()->where('campaign_type', 'search')->orderBy('id')->get()
            ->filter(fn ($strategy) => ! empty($strategy->execution_result['metadata']['google_search_staging']));
        if ($staged->isNotEmpty()) {
            if ($campaign->status === CampaignStatus::Paused || $campaign->spend_safety_hold || CampaignSpendGuardrails::activeTrial($campaign)) {
                return false;
            }
            if (CampaignStatusHelper::getGoogleAdsStatus(null, $customer) !== GoogleCampaignStatus::ENABLED
                || $staged->contains(fn ($strategy) => ($strategy->execution_result['metadata']['google_search_staging']['enable_after_success'] ?? false) !== true)) {
                $campaign->update(['status' => CampaignStatus::Paused]);

                return true;
            }
            $pending = $staged->filter(fn ($strategy) => ($strategy->execution_result['metadata']['search_reach_activation']['enabled_verified'] ?? false) !== true);
            foreach ($pending as $strategy) {
                $this->verifyStagedReach($campaign, $customer, $strategy);
            }
            if ($pending->isNotEmpty()) {
                // The existing enabling guard requires a settled local lifecycle.
                // Restore it if Google rejects activation; a failed request is
                // never recorded as a successful live campaign.
                $campaign->refresh();
                if ($campaign->status === CampaignStatus::Paused || $campaign->spend_safety_hold || CampaignSpendGuardrails::activeTrial($campaign)) {
                    return false;
                }
                $campaign->update(['status' => CampaignStatus::Active]);
                $attempted = [];
                try {
                    foreach ($pending as $strategy) {
                        $resource = $strategy->execution_result['metadata']['google_search_staging']['campaign_resource'];
                        $proof = $strategy->fresh()->execution_result['metadata']['search_reach_activation']['preflight'] ?? [];
                        $activationCheck = ExecutionResult::success();
                        $current = app(SearchKeywordBuilder::class, ['customer' => $customer])->confirmActivationProof($campaign,
                            $customer->cleanGoogleCustomerId(), $resource, $proof, $activationCheck);
                        $this->saveActivation($strategy, ['activation_check' => $activationCheck->metadata['search_reach_activation_check'] ?? null,
                            'issues' => array_map(fn ($issue) => $issue->jsonSerialize(), $activationCheck->errors),
                            'status' => $current ? 'activation_ready' : 'needs_review']);
                        if (! $current || ! $activationCheck->success) {
                            throw new \RuntimeException('The immediate Search activation check did not pass: '.$activationCheck->errorMessage());
                        }
                        $attempted[] = ['strategy' => $strategy, 'resource' => $resource];
                        $this->saveActivation($strategy, ['enable_attempted_at' => now()->toIso8601String(), 'enabled_verified' => false]);
                        $enabled = $this->campaignStatusService($customer)->enable($customer->cleanGoogleCustomerId(), $resource);
                        $verified = app(GetCampaignStatus::class, ['customer' => $customer])($customer->cleanGoogleCustomerId(), $resource);
                        if (! ($enabled['success'] ?? false) || ($verified['status'] ?? null) !== GoogleCampaignStatus::ENABLED) {
                            throw new \RuntimeException('Google has not verified the staged Search campaign as enabled after its approved reach check.');
                        }
                        $this->saveActivation($strategy, ['enabled_verified' => true, 'status' => 'enabled_verified',
                            'compensated_pause_verified' => null, 'issues' => [], 'checked_at' => now()->toIso8601String(), 'campaign_resource' => $resource]);
                        AgentActivity::record('deployment', 'search_stage_activation_verified',
                            'Enabled the staged Search campaign after a fresh demand, reach and approved-spending check. Google confirmed it is enabled.',
                            $campaign->customer_id, $campaign->id, ['campaign_resource' => $resource]);
                    }
                } catch (\Throwable $e) {
                    report($e);
                    $allPaused = true;
                    foreach ($attempted as $attempt) {
                        $paused = false;
                        try {
                            $this->campaignStatusService($customer)->pause($customer->cleanGoogleCustomerId(), $attempt['resource']);
                            $readback = app(GetCampaignStatus::class, ['customer' => $customer])($customer->cleanGoogleCustomerId(), $attempt['resource']);
                            $paused = ($readback['status'] ?? null) === GoogleCampaignStatus::PAUSED;
                        } catch (\Throwable $pauseError) {
                            report($pauseError);
                            Log::error('Staged Search activation compensation could not be verified', ['campaign_id' => $campaign->id,
                                'campaign_resource' => $attempt['resource'], 'error' => $pauseError->getMessage()]);
                        }
                        $allPaused = $allPaused && $paused;
                        $this->saveActivation($attempt['strategy'], ['enabled_verified' => false, 'status' => 'needs_review',
                            'compensated_pause_verified' => $paused, 'checked_at' => now()->toIso8601String(),
                            'issues' => [['code' => 'search_stage_activation_unverified',
                                'message' => $paused ? 'Activation did not complete. Google confirmed this stage is paused.' : 'Activation and its safety pause could not be verified. Review live Google Ads status immediately.']]]);
                    }
                    if ($allPaused) {
                        // A concurrent owner pause wins over this rollback.
                        Campaign::whereKey($campaign->id)->where('status', CampaignStatus::Active->value)->update(['status' => $previousStatus->value]);
                    } else {
                        $campaign->refresh();
                        $campaign->update(['status' => CampaignStatus::Active, 'spend_safety_hold' => [
                            'reason' => 'deployment_activation_pause_unverified', 'requested_at' => now()->toIso8601String(),
                            'campaign_resources' => array_column($attempted, 'resource')],
                            'search_delivery_state' => [...($campaign->search_delivery_state ?? []), 'status' => 'needs_review',
                                'blocked_reason' => 'deployment_activation_pause_unverified', 'mutation_allowed' => false]]);
                    }
                    AgentActivity::record('deployment', 'search_stage_activation_needs_review',
                        $allPaused ? 'Search activation failed. Google confirmed all attempted stages are paused.' : 'Search activation failed and some safety pauses remain unverified. The campaign stays visible as active until live status is resolved.',
                        $campaign->customer_id, $campaign->id, ['all_attempted_stages_paused' => $allPaused,
                            'attempted_resources' => array_column($attempted, 'resource')], 'needs_review');
                    $campaign->refresh();
                    throw $e;
                }
            }
        }

        if ($campaign->status === CampaignStatus::Active) {
            if ($previousStatus !== CampaignStatus::Active) {
                RecordSiteConversion::dispatch($customer, 'campaign_live');

                return true;
            }

            return false;
        }

        $campaign->update(['status' => CampaignStatus::Active]);
        RecordSiteConversion::dispatch($customer, 'campaign_live');

        return true;
    }

    private function verifyStagedReach(Campaign $campaign, Customer $customer, Strategy $strategy): void
    {
        if (! in_array($strategy->deployment_status, ['deployed', 'verified', 'active'], true)) {
            throw new \RuntimeException('The staged Search build has not completed successfully. It remains paused.');
        }
        $resource = $strategy->execution_result['metadata']['google_search_staging']['campaign_resource'] ?? null;
        if (! is_string($resource) || $resource !== $strategy->reusableGoogleCampaignId()) {
            throw new \RuntimeException('The staged Search resource does not match this strategy. Activation requires review.');
        }
        $status = app(GetCampaignStatus::class, ['customer' => $customer])($customer->cleanGoogleCustomerId(), $resource);
        if (($status['status'] ?? null) !== GoogleCampaignStatus::PAUSED) {
            throw new \RuntimeException('The staged Search campaign is no longer verified paused. Review its live status before activation.');
        }
        $result = ExecutionResult::success();
        $allowed = app(SearchKeywordBuilder::class, ['customer' => $customer])->preflightReach($campaign, $strategy,
            $customer->cleanGoogleCustomerId(), $resource, $result);
        $this->saveActivation($strategy, ['enabled_verified' => false, 'checked_at' => now()->toIso8601String(),
            'campaign_resource' => $resource, 'reach_gate' => $result->metadata['search_reach_preflight']['gate'] ?? null,
            'preflight' => $result->metadata['search_reach_preflight'] ?? [],
            'issues' => array_map(fn ($issue) => $issue->jsonSerialize(), $result->errors)]);
        if (! $allowed || ! $result->success) {
            throw new \RuntimeException('The fresh Search reach check did not pass. The staged campaign remains paused: '.$result->errorMessage());
        }
    }

    private function saveActivation(Strategy $strategy, array $evidence): void
    {
        $stored = $strategy->fresh()->execution_result ?? [];
        $stored['metadata']['search_reach_activation'] = [...($stored['metadata']['search_reach_activation'] ?? []), ...$evidence];
        $strategy->update(['execution_result' => $stored]);
    }

    /**
     * One-time setup: "everything arrives paused" is a promise in the receipt
     * email, so it is enforced mechanically rather than trusted to the deploy
     * path. The customer flips the switch themselves, on their own billing.
     *
     * Ordering matters. The platform is paused first and the column second, so
     * a failure part-way leaves a campaign that is paused-but-marked-live
     * rather than live-but-marked-paused. The first is a reporting problem; the
     * second spends a customer's money while every dashboard says it is not.
     */
    private function settleSetupOnly(Campaign $campaign, Customer $customer): bool
    {
        $this->pauseOnPlatform($campaign, $customer);

        if ($campaign->status === CampaignStatus::Paused) {
            return false;
        }

        $campaign->update(['status' => CampaignStatus::Paused]);

        return true;
    }

    private function pauseOnPlatform(Campaign $campaign, Customer $customer): void
    {
        $customerId = preg_replace('/[^0-9]/', '', (string) $customer->google_ads_customer_id);

        /*
           google_ads_campaign_id holds a resource name, not a bare id.

           Stripping non-digits from "customers/9654834654/campaigns/24246329924"
           glues the customer id onto the front of the campaign id and produces
           965483465424246329924 — a campaign that does not exist. So every
           setup-only pause asked Google to pause nothing, the call failed, the
           column was set to Paused regardless, and MonitorCampaignStatus read
           ENABLED an hour later and correctly flipped us back to Active.

           Every one-time setup campaign has therefore gone live, which is the
           exact opposite of what the receipt email, the Create my ads dialog
           and the deployment screen all promise. Found by noticing a campaign
           this code had "paused" sitting at active a day later.

           The resource name is what the API wants anyway, so it is used as
           stored and only built when the column holds a bare id.
        */
        $resourceName = (string) $campaign->google_ads_campaign_id;

        if (! str_contains($resourceName, '/')) {
            $bare = preg_replace('/[^0-9]/', '', $resourceName);
            $resourceName = $bare === '' ? '' : "customers/{$customerId}/campaigns/{$bare}";
        }

        if ($customerId === '' || $resourceName === '') {
            return;
        }

        try {
            $result = $this->campaignStatusService($customer)->execute(
                $customerId,
                $resourceName,
                'PAUSED'
            );

            AgentActivity::record(
                'deployment',
                'setup_only_paused',
                "Paused \"{$campaign->name}\" after build — one-time setup customers launch it themselves.",
                $campaign->customer_id,
                $campaign->id,
                $result
            );
        } catch (\Throwable $e) {
            // A setup-only campaign left running spends the customer's money on
            // a plan that never agreed to it, so this has to reach the admin
            // dashboard rather than only the log.
            report($e);
            Log::error('SettleDeployedCampaign: could not pause setup-only campaign', [
                'campaign_id' => $campaign->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Overridden in tests; the constructor talks to Google. */
    protected function campaignStatusService(Customer $customer): UpdateCampaignStatus
    {
        return new UpdateCampaignStatus($customer);
    }
}
