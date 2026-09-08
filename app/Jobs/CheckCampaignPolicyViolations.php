<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\Agents\SelfHealingAgent;
use App\Services\Customers\DeactivateCustomerService;
use App\Services\FacebookAds\AdService as FacebookAdService;
use App\Services\GoogleAds\CommonServices\GetAdStatus;
use Google\Ads\GoogleAds\V22\Enums\PolicyApprovalStatusEnum\PolicyApprovalStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckCampaignPolicyViolations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $campaignId;

    /**
     * Create a new job instance.
     */
    public function __construct(int $campaignId)
    {
        $this->campaignId = $campaignId;
    }

    /**
     * Execute the job.
     */
    public function handle(SelfHealingAgent $selfHealingAgent): void
    {
        try {
            $campaign = Campaign::findOrFail($this->campaignId);

            $hasViolation = false;

            if ($campaign->google_ads_campaign_id) {
                $hasViolation = $this->checkGoogleAdsPolicyViolations($campaign) || $hasViolation;
            }

            if ($campaign->facebook_ads_campaign_id) {
                $hasViolation = $this->checkFacebookAdsPolicyViolations($campaign) || $hasViolation;
            }

            if ($hasViolation) {
                $selfHealingAgent->heal($campaign);
            }
        } catch (\Throwable $e) {
            report($e);
            Log::error("Error checking for policy violations for campaign {$this->campaignId}: ".$e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }

    private function checkGoogleAdsPolicyViolations(Campaign $campaign): bool
    {
        $campaignResourceName = $campaign->google_ads_campaign_id;
        if (! str_starts_with($campaignResourceName, 'customers/')) {
            $campaignResourceName = "customers/{$campaign->customer->google_ads_customer_id}/campaigns/{$campaignResourceName}";
        }

        $getAdStatus = new GetAdStatus($campaign->customer);
        $ads = $getAdStatus($campaign->customer->google_ads_customer_id, $campaignResourceName);

        foreach ($ads as $ad) {
            if (($ad['approval_status'] ?? null) === PolicyApprovalStatus::DISAPPROVED) {
                Log::warning("Google Ads policy violation found for campaign {$this->campaignId}. Pausing campaign.", [
                    'ad' => $ad['resource_name'] ?? null,
                    'policy_topics' => $ad['policy_topics'] ?? [],
                ]);
                $this->pauseCampaign($campaign, 'google_disapproved_ad');

                return true;
            }
        }

        return false;
    }

    private function checkFacebookAdsPolicyViolations(Campaign $campaign): bool
    {
        $customer = $campaign->customer;
        if (! $customer || ! $customer->facebook_ads_account_id) {
            return false;
        }

        $adService = new FacebookAdService($customer);
        $accountId = 'act_'.$customer->facebook_ads_account_id;

        $ads = $adService->listAdsByAccount($accountId, [
            [
                'field' => 'campaign.id',
                'operator' => 'EQUAL',
                'value' => $campaign->facebook_ads_campaign_id,
            ],
        ]);

        $disapprovedAds = [];
        foreach ($ads as $ad) {
            $effectiveStatus = $ad['effective_status'] ?? '';
            if (in_array($effectiveStatus, ['DISAPPROVED', 'WITH_ISSUES'])) {
                $disapprovedAds[] = [
                    'ad_id' => $ad['id'],
                    'name' => $ad['name'] ?? 'Unknown',
                    'effective_status' => $effectiveStatus,
                ];
            }
        }

        if (! empty($disapprovedAds)) {
            Log::warning("Facebook Ads policy violations found for campaign {$this->campaignId}.", [
                'disapproved_count' => count($disapprovedAds),
                'ads' => $disapprovedAds,
            ]);

            // If all ads are disapproved, pause the campaign
            if (count($disapprovedAds) === count($ads)) {
                Log::warning("All ads disapproved for Facebook campaign {$this->campaignId}. Pausing campaign.");
                $this->pauseCampaign($campaign, 'facebook_all_ads_disapproved');
            }

            return true;
        }

        return false;
    }

    /**
     * Stop the campaign for real — on the platform, not only in our own row.
     *
     * Both branches above logged "Pausing campaign" and then wrote nothing but
     * `status`. No status mutation was ever sent to Google or Facebook, so the
     * disapproved ads kept serving; and because `platform_status` still said
     * ENABLED, the next hourly MonitorCampaignStatus reconciled `status`
     * straight back to Active and recorded a false status_reconciled
     * AgentActivity for it, every cycle, forever.
     *
     * DeactivateCustomerService::pauseCampaign() is the single implementation of
     * "pause this campaign wherever it is deployed", and it records the result
     * through applyPlatformStatus('PAUSED') — writing both columns is what makes
     * the pause survive the monitor.
     */
    private function pauseCampaign(Campaign $campaign, string $reason): void
    {
        $customer = $campaign->customer;
        if (! $customer) {
            return;
        }

        $result = app(DeactivateCustomerService::class)->pauseCampaign($customer, $campaign);

        if (is_string($result)) {
            // A platform refused, so the campaign is still serving an ad that
            // breaks policy. pauseCampaign() deliberately leaves both status
            // columns alone in that case — a campaign marked paused while it
            // spends is the worse of the two states — which makes this the only
            // signal anyone gets. Log::error alone never reaches the admin
            // exception dashboard.
            report(new \RuntimeException(
                "Policy pause refused for campaign {$campaign->id} ({$reason}): {$result}"
            ));
            Log::error('CheckCampaignPolicyViolations: failed to pause campaign', [
                'campaign_id' => $campaign->id,
                'reason' => $reason,
                'error' => $result,
            ]);
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('CheckCampaignPolicyViolations failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
