<?php

namespace App\Jobs;

use App\Enums\CampaignStatus;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Notifications\CampaignStatusUpdated;
use App\Services\FacebookAds\CampaignService as FacebookCampaignService;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

            } catch (\Exception $e) {
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
        $target = match ($platformStatus) {
            'ENABLED' => CampaignStatus::Active,
            'PAUSED' => CampaignStatus::Paused,
            'REMOVED' => CampaignStatus::Ended,
            default => null, // UNKNOWN tells us nothing — leave the record alone.
        };

        if (! $target) {
            return;
        }

        // Already a CampaignStatus — the model casts this column.
        $current = $campaign->status;

        if ($current === $target) {
            return;
        }

        // Never resurrect a campaign the customer has not finished setting up:
        // a draft that somehow carries a platform id is a data problem to
        // investigate, not one to paper over by flipping it live.
        if (in_array($current, [CampaignStatus::Draft, CampaignStatus::PendingAdminDeployment], true)) {
            Log::warning("MonitorCampaignStatus: campaign {$campaign->id} is {$current->value} locally but {$platformStatus} on the platform — leaving status untouched", [
                'campaign_id' => $campaign->id,
            ]);

            return;
        }

        $campaign->update(['status' => $target]);

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
            'IN_PROCESS' => 'PENDING',
            default => 'UNKNOWN',
        };
    }

    private function mapStatus(int $status): string
    {
        // Map Google Ads Enum to string
        // 2 = ENABLED, 3 = PAUSED, 4 = REMOVED
        return match ($status) {
            2 => 'ENABLED',
            3 => 'PAUSED',
            4 => 'REMOVED',
            default => 'UNKNOWN',
        };
    }

    private function mapPrimaryStatus(int $status): string
    {
        // Google Ads API V22 CampaignPrimaryStatus enum
        return match ($status) {
            2 => 'ELIGIBLE',
            3 => 'PAUSED',
            4 => 'REMOVED',
            5 => 'ENDED',
            6 => 'PENDING',
            7 => 'MISCONFIGURED',
            8 => 'LIMITED',
            9 => 'LEARNING',
            10 => 'NOT_ELIGIBLE',
            default => 'UNKNOWN',
        };
    }

    private function mapPrimaryStatusReason(int $reason): string
    {
        // Google Ads API V22 CampaignPrimaryStatusReason enum
        return match ($reason) {
            2 => 'CAMPAIGN_SERVING_STATUS_RESTRICTED',
            3 => 'CAMPAIGN_STATUS_REMOVED',
            4 => 'CAMPAIGN_STATUS_PAUSED',
            5 => 'CAMPAIGN_BUDGET_UNDERFUNDED',
            6 => 'CAMPAIGN_BUDGET_MISCONFIGURED',
            7 => 'CAMPAIGN_PENDING_SCHEDULED_START',
            8 => 'CAMPAIGN_PENDING_BUDGET_APPROVAL',
            9 => 'CAMPAIGN_LEARNING_PERIOD',
            10 => 'AD_GROUP_AD_NOT_ELIGIBLE_SERVING',
            11 => 'AD_GROUP_NOT_ELIGIBLE_SERVING',
            12 => 'AD_GROUP_STATUS_PAUSED',
            default => 'UNKNOWN',
        };
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
        } catch (\Exception $e) {
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
        } catch (\Exception $e) {
            // Surface in the admin exception dashboard; the batch continues.
            report($e);
            Log::warning("MonitorCampaignStatus: LinkedIn Ads check failed for campaign {$campaign->id}: ".$e->getMessage());
        }

        return null;
    }

    private function mapMicrosoftStatus(string $status): string
    {
        return match (strtolower($status)) {
            'active' => 'ELIGIBLE',
            'paused' => 'PAUSED',
            'budgetpaused' => 'LIMITED',
            'budgetandmanuallypaused' => 'PAUSED',
            'deleted' => 'REMOVED',
            'suspended' => 'MISCONFIGURED',
            default => 'UNKNOWN',
        };
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
            default => 'UNKNOWN',
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
