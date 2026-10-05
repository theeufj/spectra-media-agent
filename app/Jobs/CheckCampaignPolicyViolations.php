<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Services\Agents\CampaignAlertService;
use App\Services\Agents\SelfHealingAgent;
use App\Services\Customers\DeactivateCustomerService;
use App\Services\FacebookAds\AdService as FacebookAdService;
use App\Services\GoogleAds\CommonServices\GetAdStatus;
use App\Support\GoogleAdPolicy;
use Google\Ads\GoogleAds\V22\Enums\AdGroupAdStatusEnum\AdGroupAdStatus;
use Google\Ads\GoogleAds\V22\Enums\AdGroupStatusEnum\AdGroupStatus;
use Google\Ads\GoogleAds\V22\Enums\PolicyApprovalStatusEnum\PolicyApprovalStatus;
use Google\Ads\GoogleAds\V22\Enums\PolicyReviewStatusEnum\PolicyReviewStatus;
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

    private bool $destinationIssueDetected = false;

    public int $tries = 3;

    public array $backoff = [60, 300];

    public int $timeout = 300;

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

            // Older deployments used a one-year platform end date regardless
            // of the date the customer approved. Stop those at the local date.
            if ($campaign->hasPassedEndDate()) {
                if ($campaign->platform_status !== 'PAUSED' || $campaign->status->value !== 'paused') {
                    $this->pauseExpiredCampaign($campaign);
                }

                return;
            }

            $hasViolation = false;
            $checkFailed = false;
            $canHeal = $campaign->status === CampaignStatus::Active && $campaign->customer?->service_type !== 'setup_only';
            foreach (['google_ads', 'facebook_ads'] as $platform) {
                if (! $campaign->getAttribute($platform.'_campaign_id')) {
                    continue;
                }
                try {
                    $hasViolation = ($platform === 'google_ads'
                        ? $this->checkGoogleAdsPolicyViolations($campaign)
                        : $this->checkFacebookAdsPolicyViolations($campaign)) || $hasViolation;
                } catch (\Throwable $e) {
                    report($e);
                    $checkFailed = true;
                    app(CampaignAlertService::class)->recordPolicyCheck($campaign, $platform, [], 'The ad platform approval check failed. The last known evidence has not been cleared.', false);
                    Log::error('Campaign policy check unavailable', ['campaign_id' => $campaign->id, 'platform' => $platform, 'error' => $e->getMessage()]);
                }
            }

            // Observing approval never grants permission to resume a paused or
            // setup-only campaign. Website outages cannot be fixed by ad-copy changes.
            if ($hasViolation && $canHeal && ! $checkFailed && ! $this->destinationIssueDetected
                && (CampaignAlertService::policyStatus($campaign)['status'] ?? 'unknown') !== 'unknown') {
                $selfHealingAgent->heal($campaign);
            }
        } catch (\Throwable $e) {
            report($e);
            Log::error("Error checking for policy violations for campaign {$this->campaignId}: ".$e->getMessage(), [
                'exception' => $e,
            ]);
            throw $e;
        }
    }

    private function checkGoogleAdsPolicyViolations(Campaign $campaign): bool
    {
        $campaignResourceName = $campaign->google_ads_campaign_id;
        if (! str_starts_with($campaignResourceName, 'customers/')) {
            $campaignResourceName = "customers/{$campaign->customer->cleanGoogleCustomerId()}/campaigns/{$campaignResourceName}";
        }

        $ads = $this->googleAds($campaign, $campaignResourceName);

        $disapprovedAds = [];
        $issues = [];
        foreach ($ads as $ad) {
            if (($ad['approval_status'] ?? null) === PolicyApprovalStatus::DISAPPROVED && ($ad['status'] ?? null) !== AdGroupAdStatus::REMOVED) {
                $destination = GoogleAdPolicy::isDestinationIssue($ad);
                $this->destinationIssueDetected = $this->destinationIssueDetected || $destination;
                $issues[] = array_merge(GoogleAdPolicy::details($ad), [
                    'platform' => 'google_ads', 'message' => GoogleAdPolicy::summarize($ad),
                    'action_required' => $destination ? 'Fix the website destination, then request Google Ads review.' : 'Review the policy issue and request Google Ads review.',
                ]);
            }
            if (($ad['status'] ?? null) !== AdGroupAdStatus::ENABLED
                || ($ad['ad_group_status'] ?? null) !== AdGroupStatus::ENABLED) {
                continue;
            }

            if (($ad['approval_status'] ?? null) === PolicyApprovalStatus::DISAPPROVED) {
                $disapprovedAds[] = $ad;
            }
        }

        $confirmedApproval = collect($ads)->contains(fn ($ad) => in_array($ad['approval_status'] ?? null, [PolicyApprovalStatus::APPROVED, PolicyApprovalStatus::APPROVED_LIMITED], true)
            && in_array($ad['review_status'] ?? null, [PolicyReviewStatus::REVIEWED, PolicyReviewStatus::ELIGIBLE_MAY_SERVE], true));
        $uncertain = $ads === [] ? 'No current ads were returned, so approval could not be verified.'
            : ($issues === [] && ! $confirmedApproval ? 'The current ads are still awaiting review. Approval has not been confirmed.' : null);
        $previousIssues = CampaignAlertService::policyStatus($campaign)['platforms']['google_ads']['issues'] ?? [];
        if ($issues === [] && $previousIssues !== []) {
            // An absent or pending ad is not evidence that the rejection was fixed.
            foreach ($previousIssues as $previous) {
                $current = collect($ads)->firstWhere('resource_name', $previous['ad_resource_name']);
                if (! $current || ! in_array($current['approval_status'] ?? null, [PolicyApprovalStatus::APPROVED, PolicyApprovalStatus::APPROVED_LIMITED], true)
                    || ! in_array($current['review_status'] ?? null, [PolicyReviewStatus::REVIEWED, PolicyReviewStatus::ELIGIBLE_MAY_SERVE], true)) {
                    $uncertain = 'The previously rejected ad is missing or still awaiting review. Approval has not been confirmed.';
                    break;
                }
            }
        }
        app(CampaignAlertService::class)->recordPolicyCheck($campaign, 'google_ads', $issues, $uncertain);

        if ($disapprovedAds === []) {
            return false;
        }

        // Policy checks report rejected ads without changing campaign controls.
        // The caller may still repair eligible copy issues on managed campaigns.
        return true;
    }

    /** @return array<int, array<string, mixed>> */
    protected function googleAds(Campaign $campaign, string $campaignResourceName): array
    {
        $getAdStatus = new GetAdStatus($campaign->customer);

        return $getAdStatus($campaign->customer->cleanGoogleCustomerId(), $campaignResourceName);
    }

    private function checkFacebookAdsPolicyViolations(Campaign $campaign): bool
    {
        $customer = $campaign->customer;
        if (! $customer || ! $customer->facebook_ads_account_id) {
            return false;
        }

        $accountId = 'act_'.$customer->facebook_ads_account_id;

        $ads = $this->facebookAds($customer, $accountId, [
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
                    'platform' => 'facebook_ads',
                    'ad_resource_name' => (string) $ad['id'],
                    'ad_id' => $ad['id'],
                    'name' => $ad['name'] ?? 'Unknown',
                    'effective_status' => $effectiveStatus,
                    'final_urls' => [], 'policy_topics' => [], 'destination_issue' => false,
                    'message' => 'Facebook Ads rejected "'.($ad['name'] ?? $ad['id']).'" ('.$effectiveStatus.').',
                    'action_required' => 'Review the policy issue in Facebook Ads Manager.',
                ];
            }
        }
        $uncertain = $ads === [] ? 'No current Facebook ads were returned, so approval could not be verified.' : null;
        if ($disapprovedAds === []) {
            $previousIssues = CampaignAlertService::policyStatus($campaign)['platforms']['facebook_ads']['issues'] ?? [];
            foreach ($previousIssues as $previous) {
                $current = collect($ads)->first(fn ($ad) => (string) ($ad['id'] ?? '') === (string) $previous['ad_resource_name']);
                if (! $current || ! in_array($current['effective_status'] ?? '', ['ACTIVE', 'PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED'], true)) {
                    $uncertain = 'The previously rejected Facebook ad is missing or still awaiting review. Approval has not been confirmed.';
                    break;
                }
            }
            if ($previousIssues === [] && collect($ads)->contains(fn ($ad) => in_array($ad['effective_status'] ?? '', ['PENDING_REVIEW', 'IN_PROCESS', 'PREAPPROVED'], true))) {
                $uncertain = 'Facebook Ads is still reviewing the current ads. Approval has not been confirmed.';
            }
        }
        app(CampaignAlertService::class)->recordPolicyCheck($campaign, 'facebook_ads', $disapprovedAds, $uncertain);

        if (! empty($disapprovedAds)) {
            Log::warning("Facebook Ads policy violations found for campaign {$this->campaignId}.", [
                'disapproved_count' => count($disapprovedAds),
                'ads' => $disapprovedAds,
            ]);

            return true;
        }

        return false;
    }

    protected function facebookAds(\App\Models\Customer $customer, string $accountId, array $filters): array
    {
        return (new FacebookAdService($customer))->listAdsByAccount($accountId, $filters);
    }

    /**
     * Stop spend after the customer-approved end date using the shared platform
     * sweep. Policy verdicts never trigger this safeguard.
     */
    private function pauseExpiredCampaign(Campaign $campaign): void
    {
        if ($campaign->status === CampaignStatus::Paused || $campaign->platform_status === 'PAUSED') {
            return;
        }
        $customer = $campaign->customer;
        if (! $customer) {
            return;
        }

        $result = app(DeactivateCustomerService::class)->pauseCampaign($customer, $campaign);

        if ($result === true) {
            AgentActivity::record('self_healing', 'campaign_end_date_paused',
                'Paused "'.$campaign->name.'" because its approved end date has passed.',
                $customer->id, $campaign->id, ['reason' => 'campaign_end_date_passed']);
        }

        if (is_string($result)) {
            // A failed end-date stop must reach the exception dashboard. Keep
            // both status columns truthful until the platform accepts the pause.
            report(new \RuntimeException(
                "End-date pause refused for campaign {$campaign->id}: {$result}"
            ));
            Log::error('CheckCampaignPolicyViolations: failed to stop expired campaign', [
                'campaign_id' => $campaign->id,
                'reason' => 'campaign_end_date_passed',
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
