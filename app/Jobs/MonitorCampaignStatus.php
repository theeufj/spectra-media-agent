<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Notifications\CampaignStatusUpdated;
use App\Services\FacebookAds\CampaignService as FacebookCampaignService;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use Google\Ads\GoogleAds\V22\Enums\CampaignPrimaryStatusEnum\CampaignPrimaryStatus;
use Google\Ads\GoogleAds\V22\Enums\CampaignPrimaryStatusReasonEnum\CampaignPrimaryStatusReason;
use Google\Ads\GoogleAds\V22\Enums\CampaignStatusEnum\CampaignStatus as GoogleCampaignStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Microsoft\MsAds\Rest\Model\CampaignManagementService\CampaignStatus as MicrosoftCampaignStatus;

class MonitorCampaignStatus implements ShouldQueue
{
    use \App\Jobs\Concerns\RecordsAgentRun, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(GetCampaignStatus $getCampaignStatus): void
    {
        $runStart = $this->startRun();

        $campaigns = Campaign::with('customer.users')
            ->where(function ($query) {
                $query->whereNotNull('google_ads_campaign_id')
                    ->orWhereNotNull('facebook_ads_campaign_id')
                    ->orWhereNotNull('microsoft_ads_campaign_id')
                    ->orWhereNotNull('linkedin_campaign_id');
            })
            ->whereNotNull('customer_id')
            ->get();

        $processed = 0;
        $errors = 0;

        foreach ($campaigns as $campaign) {
            try {
                $platformResults = [];

                if ($campaign->google_ads_campaign_id) {
                    if ($res = $this->getGoogleAdsStatus($campaign, $getCampaignStatus)) {
                        $platformResults['google'] = $res;
                    }
                }

                if ($campaign->facebook_ads_campaign_id) {
                    if ($res = $this->getFacebookAdsStatus($campaign)) {
                        $platformResults['facebook'] = $res;
                    }
                }

                if ($campaign->microsoft_ads_campaign_id) {
                    if ($res = $this->getMicrosoftAdsStatus($campaign)) {
                        $platformResults['microsoft'] = $res;
                    }
                }

                if ($campaign->linkedin_campaign_id) {
                    if ($res = $this->getLinkedInAdsStatus($campaign)) {
                        $platformResults['linkedin'] = $res;
                    }
                }

                $this->updateOverallStatus($campaign, $platformResults);
                $processed++;

            } catch (\Throwable $e) {
                // Surface in the admin exception dashboard; the batch continues.
                report($e);
                $errors++;
                Log::error("Failed to monitor campaign {$campaign->id}: ".$e->getMessage());
            }
        }

        $this->finishRun($runStart, actions: $processed, errors: $errors, scope: $campaigns->count().' campaigns');
    }

    private function updateOverallStatus(Campaign $campaign, array $platformResults): void
    {
        if (empty($platformResults)) {
            return;
        }

        $oldStatus = $campaign->primary_status;

        $worstSeverity = -1;
        $worstStatus = 'UNKNOWN';
        $worstPlatformStatus = 'UNKNOWN';
        $worstReasons = [];

        foreach ($platformResults as $platform => $result) {
            $severity = $this->getStatusSeverity($result['primary_status']);
            // Prefer the more severe status. If they are equal, the first evaluated takes precedence.
            if ($severity > $worstSeverity) {
                $worstSeverity = $severity;
                $worstStatus = $result['primary_status'];
                $worstPlatformStatus = $result['platform_status'];
                $worstReasons = $result['primary_status_reasons'] ?? null;
            }
        }

        $campaign->update([
            'platform_status' => $worstPlatformStatus,
            'primary_status' => $worstStatus,
            'primary_status_reasons' => $worstReasons,
            'last_checked_at' => now(),
        ]);

        $this->syncLifecycleStatus($campaign, $worstPlatformStatus);

        $this->notifyIfBecameActive($campaign, $oldStatus, 'ELIGIBLE');

        // Only alert on unexpected problem states — not intentional pause/end.
        $silentStatuses = ['PAUSED', 'ENDED', 'REMOVED', 'ELIGIBLE', 'UNKNOWN'];
        if (! in_array($campaign->primary_status, $silentStatuses, true)) {
            $this->notifyIfStatusChanged($campaign, $oldStatus, $campaign->primary_status);
        }
    }

    /**
     * Reconcile Spectra's own `status` with what the platform reports.
     *
     * This job wrote platform_status and primary_status but never `status`, so
     * the two could only agree while every pause and resume went through our own
     * UI. A change made directly in the Google Ads UI never reached us, and
     * `status` drifted stale indefinitely — the drift behind BILL-8, and the
     * reason credit replenishment sized itself off a fallback rolling average
     * instead of the real budget sum (AdSpendBillingService::checkAndReplenish).
     *
     * Only `platform_status` is used here — it is the campaign's own ENABLED /
     * PAUSED / REMOVED state. `primary_status` is a serving state that includes
     * transient conditions like LIMITED or PENDING, which say nothing about
     * whether the campaign is meant to be running.
     *
     * Billing is deliberately unaffected: it bills on recorded spend, never on
     * this field (see AdSpendBillingService, BILL-8).
     */
    private function syncLifecycleStatus(Campaign $campaign, string $platformStatus): void
    {
        // Already a CampaignStatus — the model casts this column.
        $current = $campaign->status;

        // The mapping, the draft guard and the paired write all live on the
        // model, so every caller reconciles the two columns the same way.
        if (! $campaign->applyPlatformStatus($platformStatus)) {
            return;
        }

        $target = $campaign->status;

        Log::info("MonitorCampaignStatus: campaign {$campaign->id} status {$current?->value} → {$target->value} (platform says {$platformStatus})");

        AgentActivity::record(
            'monitoring',
            'status_reconciled',
            "Campaign \"{$campaign->name}\" changed on the platform — status updated to {$target->label()}",
            $campaign->customer_id,
            $campaign->id,
            ['from' => $current?->value, 'to' => $target->value, 'platform_status' => $platformStatus]
        );
    }

    private function getStatusSeverity(string $status): int
    {
        return match ($status) {
            'UNKNOWN' => 0,
            'ELIGIBLE' => 1,
            'PENDING' => 2,
            'PAUSED' => 3,
            'ENDED' => 4,
            'LIMITED' => 5,
            'REMOVED' => 6,
            'MISCONFIGURED' => 7,
            default => 0,
        };
    }

    private function getGoogleAdsStatus(Campaign $campaign, GetCampaignStatus $getCampaignStatus): ?array
    {
        if (! $campaign->customer?->google_ads_customer_id) {
            return null;
        }
        $customerId = $campaign->customer->cleanGoogleCustomerId();

        // google_ads_campaign_id stores the full resource name (customers/X/campaigns/Y)
        $resourceName = $campaign->google_ads_campaign_id;
        if (! str_starts_with($resourceName, 'customers/')) {
            $resourceName = "customers/{$customerId}/campaigns/{$resourceName}";
        }
        $statusData = $getCampaignStatus($customerId, $resourceName);

        if ($statusData) {
            $reasons = [];
            foreach ($statusData['primary_status_reasons'] ?? [] as $reasonInt) {
                $reasons[] = $this->mapPrimaryStatusReason((int) $reasonInt);
            }

            return [
                'platform_status' => $this->mapStatus($statusData['status']),
                'primary_status' => $this->mapPrimaryStatus($statusData['primary_status']),
                'primary_status_reasons' => $reasons ?: null,
            ];
        }

        return null;
    }

    private function notifyIfBecameActive(Campaign $campaign, ?string $oldStatus, string $activeStatus): void
    {
        if ($oldStatus !== $activeStatus && $campaign->primary_status === $activeStatus) {
            // Dedup: only notify once per 24h per campaign going active
            $cacheKey = "campaign_became_active:{$campaign->id}";
            if (Cache::has($cacheKey)) {
                return;
            }
            Cache::put($cacheKey, true, now()->addHours(24));

            Log::info("Campaign {$campaign->id} is now {$activeStatus}.");

            AgentActivity::record(
                'monitoring',
                'status_changed',
                "Campaign \"{$campaign->name}\" is now {$activeStatus}",
                $campaign->customer_id,
                $campaign->id,
                ['old_status' => $oldStatus, 'new_status' => $activeStatus]
            );

            if ($campaign->customer && $campaign->customer->users) {
                foreach ($campaign->customer->users as $user) {
                    $user->notify(new CampaignStatusUpdated($campaign));
                }
            }
        }
    }

    private function getFacebookAdsStatus(Campaign $campaign): ?array
    {
        $customer = $campaign->customer;
        if (! $customer || ! $customer->facebook_ads_account_id) {
            return null;
        }

        $campaignService = new FacebookCampaignService($customer);
        $fbCampaign = $campaignService->getCampaign($campaign->facebook_ads_campaign_id);

        if ($fbCampaign) {
            $effectiveStatus = $fbCampaign['effective_status'] ?? 'UNKNOWN';
            $primaryStatus = $this->mapFacebookEffectiveStatus($effectiveStatus);

            return [
                'platform_status' => $fbCampaign['status'] ?? 'UNKNOWN',
                'primary_status' => $primaryStatus,
                'primary_status_reasons' => ! empty($fbCampaign['issues_info']) ? json_encode($fbCampaign['issues_info']) : null,
            ];
        }

        return null;
    }

    private function notifyIfStatusChanged(Campaign $campaign, ?string $oldStatus, string $newStatus): void
    {
        if ($oldStatus !== $newStatus) {
            // Dedup: only notify once per 4h per campaign per status transition
            $cacheKey = "campaign_status_changed:{$campaign->id}:{$oldStatus}:{$newStatus}";
            if (Cache::has($cacheKey)) {
                return;
            }
            Cache::put($cacheKey, true, now()->addHours(4));

            Log::warning("Campaign {$campaign->id} status changed: {$oldStatus} -> {$newStatus}");

            AgentActivity::record(
                'monitoring',
                'status_changed',
                "Campaign \"{$campaign->name}\" status changed from {$oldStatus} to {$newStatus}",
                $campaign->customer_id,
                $campaign->id,
                ['old_status' => $oldStatus, 'new_status' => $newStatus]
            );

            if ($campaign->customer && $campaign->customer->users) {
                foreach ($campaign->customer->users as $user) {
                    $user->notify(new CampaignStatusUpdated($campaign));
                }
            }
        }
    }

    private function mapFacebookEffectiveStatus(string $effectiveStatus): string
    {
        return match ($effectiveStatus) {
            'ACTIVE' => 'ELIGIBLE',
            'PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED' => 'PAUSED',
            'DELETED', 'ARCHIVED' => 'REMOVED',
            'PENDING_REVIEW', 'PENDING_BILLING_INFO' => 'PENDING',
            'DISAPPROVED' => 'MISCONFIGURED',
            'WITH_ISSUES' => 'LIMITED',
            'IN_PROCESS', 'PREAPPROVED' => 'PENDING',
            default => $this->unmapped('facebook', $effectiveStatus),
        };
    }

    /**
     * Google Ads enums, translated by the SDK's own table rather than by hand.
     *
     * Every one of the eleven values mapPrimaryStatusReason() spelled out was
     * wrong — the list was shifted against the real enum, so a BUDGET_CONSTRAINED
     * campaign was reported as AD_GROUP_NOT_ELIGIBLE_SERVING and a paused one as
     * removed. Twenty-seven of the forty reasons were not mapped at all and came
     * back UNKNOWN, including the actionable ones: HAS_ADS_DISAPPROVED,
     * NO_KEYWORDS, MISSING_LOCATION_TARGETING. Those strings reach the customer's
     * dashboard as the explanation for why their campaign is not running.
     *
     * Reading the SDK's table means the mapping cannot drift from the API version
     * the client is built against.
     */
    private function mapStatus(int $status): string
    {
        return $this->enumName(GoogleCampaignStatus::class, $status);
    }

    private function mapPrimaryStatus(int $status): string
    {
        return $this->enumName(CampaignPrimaryStatus::class, $status);
    }

    private function mapPrimaryStatusReason(int $reason): string
    {
        return $this->enumName(CampaignPrimaryStatusReason::class, $reason);
    }

    /**
     * @param  class-string  $enum
     */
    private function enumName(string $enum, int $value): string
    {
        // 0 (UNSPECIFIED) and 1 (UNKNOWN) both mean Google did not tell us, and
        // callers already read 'UNKNOWN' as that case — notably the silent-status
        // list that decides whether a status change is worth a notification.
        if ($value <= 1) {
            return 'UNKNOWN';
        }

        try {
            return $enum::name($value);
        } catch (\UnexpectedValueException) {
            // A value newer than the SDK build. Better unknown than wrong.
            return 'UNKNOWN';
        }
    }

    private function getMicrosoftAdsStatus(Campaign $campaign): ?array
    {
        $customer = $campaign->customer;
        if (! $customer || ! $customer->microsoft_ads_account_id) {
            return null;
        }

        try {
            $service = new \App\Services\MicrosoftAds\CampaignService($customer);
            $msStatus = $service->getCampaignStatus($campaign->microsoft_ads_campaign_id);

            if ($msStatus) {
                return [
                    'platform_status' => $msStatus,
                    'primary_status' => $this->mapMicrosoftStatus($msStatus),
                    'primary_status_reasons' => null,
                ];
            }
        } catch (\Throwable $e) {
            // Surface in the admin exception dashboard; the batch continues.
            report($e);
            Log::warning("MonitorCampaignStatus: Microsoft Ads check failed for campaign {$campaign->id}: ".$e->getMessage());
        }

        return null;
    }

    private function getLinkedInAdsStatus(Campaign $campaign): ?array
    {
        $customer = $campaign->customer;
        if (! $customer || ! $customer->linkedin_ads_account_id) {
            return null;
        }

        try {
            $service = new \App\Services\LinkedInAds\CampaignService($customer);
            $liCampaign = $service->getCampaign($campaign->linkedin_campaign_id);

            if ($liCampaign) {
                $liStatus = $liCampaign['status'] ?? 'UNKNOWN';

                return [
                    'platform_status' => $liStatus,
                    'primary_status' => $this->mapLinkedInStatus($liStatus),
                    'primary_status_reasons' => null,
                ];
            }
        } catch (\Throwable $e) {
            // Surface in the admin exception dashboard; the batch continues.
            report($e);
            Log::warning("MonitorCampaignStatus: LinkedIn Ads check failed for campaign {$campaign->id}: ".$e->getMessage());
        }

        return null;
    }

    /**
     * Microsoft's campaign statuses, keyed off the vendored SDK's own constants.
     *
     * This was a list of lowercase string literals, and one of them —
     * 'budgetandmanuallypaused' — did not exist. The real value is
     * BudgetAndManualPaused, so a campaign paused by both its budget and by hand
     * fell through to UNKNOWN. Naming the constants means the API's spelling is
     * the only spelling this file can hold.
     */
    private const MICROSOFT_STATUS_MAP = [
        MicrosoftCampaignStatus::ACTIVE => 'ELIGIBLE',
        MicrosoftCampaignStatus::PAUSED => 'PAUSED',
        MicrosoftCampaignStatus::BUDGET_PAUSED => 'LIMITED',
        MicrosoftCampaignStatus::BUDGET_AND_MANUAL_PAUSED => 'PAUSED',
        MicrosoftCampaignStatus::DELETED => 'REMOVED',
        MicrosoftCampaignStatus::SUSPENDED => 'MISCONFIGURED',
    ];

    private function mapMicrosoftStatus(string $status): string
    {
        foreach (self::MICROSOFT_STATUS_MAP as $microsoft => $ours) {
            // Case-insensitive: the value arrives from JSON, not from the SDK's
            // own serialiser, and casing there is not guaranteed.
            if (strcasecmp((string) $microsoft, trim($status)) === 0) {
                return $ours;
            }
        }

        return $this->unmapped('microsoft', $status);
    }

    /**
     * A platform status this file has no mapping for.
     *
     * Facebook and LinkedIn are hand-rolled HTTP with no vendored SDK, so unlike
     * Google and Microsoft there is no enum to check the list against — the only
     * honest way to know a value is missing is for the platform to send one and
     * for that to be visible. Returning UNKNOWN silently is what let a
     * misspelled Microsoft status sit unnoticed.
     */
    private function unmapped(string $platform, string $value): string
    {
        if (trim($value) !== '') {
            Log::warning('MonitorCampaignStatus: unmapped platform status', [
                'platform' => $platform,
                'status' => $value,
            ]);
        }

        return 'UNKNOWN';
    }

    private function mapLinkedInStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'ACTIVE' => 'ELIGIBLE',
            'PAUSED' => 'PAUSED',
            'ARCHIVED' => 'REMOVED',
            'COMPLETED' => 'ENDED',
            'CANCELED' => 'REMOVED',
            'DRAFT' => 'PENDING',
            'PENDING_REVIEW' => 'PENDING',
            'PENDING_DELETION' => 'REMOVED',
            default => $this->unmapped('linkedin', $status),
        };
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('MonitorCampaignStatus failed: '.$exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
        $this->recordRunFailure($exception);
    }
}
