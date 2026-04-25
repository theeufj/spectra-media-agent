<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\Agents\SelfHealingAgent;
use App\Services\GoogleAds\CommonServices\GetAdStatus;
use App\Services\FacebookAds\AdService as FacebookAdService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Google\Ads\GoogleAds\V22\Enums\PolicyApprovalStatusEnum\PolicyApprovalStatus;

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
        } catch (\Exception $e) {
            Log::error("Error checking for policy violations for campaign {$this->campaignId}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
        }
    }

    private function checkGoogleAdsPolicyViolations(Campaign $campaign): bool
    {
        $campaignResourceName = $campaign->google_ads_campaign_id;
        if (!str_starts_with($campaignResourceName, 'customers/')) {
            $campaignResourceName = "customers/{$campaign->customer->google_ads_customer_id}/campaigns/{$campaignResourceName}";
        }

        $getAdStatus = new GetAdStatus($campaign->customer, true);
        $ads = $getAdStatus($campaign->customer->google_ads_customer_id, $campaignResourceName);

        foreach ($ads as $ad) {
            if (($ad['approval_status'] ?? null) === PolicyApprovalStatus::DISAPPROVED) {
                Log::warning("Google Ads policy violation found for campaign {$this->campaignId}. Pausing campaign.", [
                    'ad' => $ad['resource_name'] ?? null,
                    'policy_topics' => $ad['policy_topics'] ?? [],
                ]);
                $campaign->update(['status' => 'PAUSED']);
                return true;
            }
        }

        return false;
    }

    private function checkFacebookAdsPolicyViolations(Campaign $campaign): bool
    {
        $customer = $campaign->customer;
        if (!$customer || !$customer->facebook_ads_account_id) {
            return false;
        }

        $adService = new FacebookAdService($customer);
        $accountId = 'act_' . $customer->facebook_ads_account_id;

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

        if (!empty($disapprovedAds)) {
            Log::warning("Facebook Ads policy violations found for campaign {$this->campaignId}.", [
                'disapproved_count' => count($disapprovedAds),
                'ads' => $disapprovedAds,
            ]);

            // If all ads are disapproved, pause the campaign
            if (count($disapprovedAds) === count($ads)) {
                Log::warning("All ads disapproved for Facebook campaign {$this->campaignId}. Pausing campaign.");
                $campaign->update(['status' => 'PAUSED']);
            }

            return true;
        }

        return false;
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('CheckCampaignPolicyViolations failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
