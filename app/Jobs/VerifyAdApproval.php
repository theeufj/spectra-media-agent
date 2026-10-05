<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Customer;
use App\Notifications\CriticalAgentAlert;
use App\Services\GoogleAds\CommonServices\GetAdStatus;
use App\Support\GoogleAdPolicy;
use Google\Ads\GoogleAds\V22\Enums\AdGroupAdStatusEnum\AdGroupAdStatus;
use Google\Ads\GoogleAds\V22\Enums\PolicyApprovalStatusEnum\PolicyApprovalStatus;
use Google\Ads\GoogleAds\V22\Enums\PolicyReviewStatusEnum\PolicyReviewStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Checks the approval status of a healed ad ~24 hours after it was submitted.
 * Dispatched by SelfHealingAgent after a successful ad regeneration.
 */
class VerifyAdApproval implements ShouldQueue
{
    /**
     * A soft-deleted customer/campaign mid-queue means the work is moot —
     * discard quietly instead of filling failed_jobs with ModelNotFound.
     */
    public $deleteWhenMissingModels = true;

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;

    public $backoff = [300];

    public function __construct(
        public Customer $customer,
        public string $adResourceName,
        public int $campaignId
    ) {}

    public function handle(): void
    {
        $campaign = Campaign::find($this->campaignId);
        if (! $campaign || $campaign->customer_id !== $this->customer->id) {
            return;
        }

        try {
            $ad = app(GetAdStatus::class, ['customer' => $this->customer])
                ->forAd($this->customer->cleanGoogleCustomerId(), $this->adResourceName);
        } catch (\Throwable $e) {
            report($e);
            Log::warning('VerifyAdApproval: Could not check ad policy', ['ad' => $this->adResourceName, 'error' => $e->getMessage()]);
            $this->recordOutcome($campaign, 'unknown', null);
            throw $e;
        }

        $approval = $ad['approval_status'] ?? null;
        $review = $ad['review_status'] ?? null;
        $outcome = match (true) {
            ! $ad || ($ad['status'] ?? null) === AdGroupAdStatus::REMOVED => 'unknown',
            in_array($review, [PolicyReviewStatus::REVIEW_IN_PROGRESS, PolicyReviewStatus::UNDER_APPEAL], true) => 'pending',
            $approval === PolicyApprovalStatus::DISAPPROVED => 'disapproved',
            ! in_array($review, [PolicyReviewStatus::REVIEWED, PolicyReviewStatus::ELIGIBLE_MAY_SERVE], true) => 'unknown',
            $approval === PolicyApprovalStatus::APPROVED_LIMITED => 'limited',
            $approval === PolicyApprovalStatus::APPROVED => 'approved',
            default => 'unknown',
        };

        $this->recordOutcome($campaign, $outcome, $ad);
    }

    private function recordOutcome(Campaign $campaign, string $outcome, ?array $ad): void
    {
        $message = match ($outcome) {
            'approved' => 'Google approved the replacement ad. Campaign delivery still depends on its other settings.',
            'limited' => 'The replacement ad has limited Google approval: '.GoogleAdPolicy::summarize($ad ?? []).'.',
            'pending' => 'The replacement ad is still under Google review or appeal. Approval has not been confirmed.',
            'disapproved' => 'The replacement ad remains disapproved: '.GoogleAdPolicy::summarize($ad ?? []).'.',
            default => 'The replacement ad policy status could not be verified. No successful recovery has been confirmed.',
        };
        $details = GoogleAdPolicy::details($ad ?? ['resource_name' => $this->adResourceName]) + [
            'campaign_id' => $campaign->id,
            'campaign_name' => $campaign->name,
            'outcome' => $outcome,
            'checked_at' => now()->toIso8601String(),
        ];
        AgentActivity::record('self_healing', 'google_ad_approval_checked', $message, $this->customer->id,
            $campaign->id, $details, $outcome === 'approved' ? 'completed' : 'needs_review');

        if ($outcome !== 'approved') {
            CriticalAgentAlert::deliver('google_ad_approval_'.$outcome, 'Google ad approval needs attention', $message,
                $details + ['severity' => $outcome === 'disapproved' ? 'high' : 'medium',
                    'action_required' => $outcome === 'pending'
                        ? 'Wait for Google review and check the decision again.'
                        : 'Check the replacement ad in Google Ads and resolve the reported policy or destination issue.'],
                CriticalAgentAlert::RECIPIENTS_BOTH, $this->customer);
        }
    }
}
