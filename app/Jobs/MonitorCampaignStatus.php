<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use App\Services\FacebookAds\CampaignService as FacebookCampaignService;
use App\Notifications\CampaignStatusUpdated;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MonitorCampaignStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(GetCampaignStatus $getCampaignStatus): void
    {
        $campaigns = Campaign::with('customer.users')
            ->where(function ($query) {
                $query->whereNotNull('google_ads_campaign_id')
                      ->orWhereNotNull('facebook_ads_campaign_id')
                      ->orWhereNotNull('microsoft_ads_campaign_id')
                      ->orWhereNotNull('linkedin_campaign_id');
            })
            ->whereNotNull('customer_id')
            ->get();

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

            } catch (\Exception $e) {
                Log::error("Failed to monitor campaign {$campaign->id}: " . $e->getMessage());
            }
        }
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

        $this->notifyIfBecameActive($campaign, $oldStatus, 'ELIGIBLE');

        if ($campaign->primary_status !== 'ELIGIBLE') {
            $this->notifyIfStatusChanged($campaign, $oldStatus, $campaign->primary_status);
        }
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
        if (!$campaign->customer?->google_ads_customer_id) {
            return null;
        }
        $customerId = $campaign->customer->cleanGoogleCustomerId();

        // google_ads_campaign_id stores the full resource name (customers/X/campaigns/Y)
        $resourceName = $campaign->google_ads_campaign_id;
        if (!str_starts_with($resourceName, 'customers/')) {
            $resourceName = "customers/{$customerId}/campaigns/{$resourceName}";
        }
        $statusData = $getCampaignStatus($customerId, $resourceName);

        if ($statusData) {
            return [
                'platform_status' => $this->mapStatus($statusData['status']),
                'primary_status' => $this->mapPrimaryStatus($statusData['primary_status']),
                'primary_status_reasons' => $statusData['primary_status_reasons'] ?? null,
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
        if (!$customer || !$customer->facebook_ads_account_id) {
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
                'primary_status_reasons' => !empty($fbCampaign['issues_info']) ? json_encode($fbCampaign['issues_info']) : null,
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
        // Map Google Ads Enum to string
        // 2 = ELIGIBLE, 3 = PAUSED, 4 = REMOVED, 5 = ENDED, 6 = PENDING, 7 = MISCONFIGURED, 8 = LIMITED
        return match ($status) {
            2 => 'ELIGIBLE',
            3 => 'PAUSED',
            4 => 'REMOVED',
            5 => 'ENDED',
            6 => 'PENDING',
            7 => 'MISCONFIGURED',
            8 => 'LIMITED',
            default => 'UNKNOWN',
        };
    }

    private function getMicrosoftAdsStatus(Campaign $campaign): ?array
    {
        $customer = $campaign->customer;
        if (!$customer || !$customer->microsoft_ads_account_id) {
            return null;
        }

        try {
            $service = new \App\Services\MicrosoftAds\CampaignManagementService($customer);
            $msStatus = $service->getCampaignStatus($campaign->microsoft_ads_campaign_id);

            if ($msStatus) {
                return [
                    'platform_status' => $msStatus,
                    'primary_status' => $this->mapMicrosoftStatus($msStatus),
                    'primary_status_reasons' => null,
                ];
            }
        } catch (\Exception $e) {
            Log::warning("MonitorCampaignStatus: Microsoft Ads check failed for campaign {$campaign->id}: " . $e->getMessage());
        }

        return null;
    }

    private function getLinkedInAdsStatus(Campaign $campaign): ?array
    {
        $customer = $campaign->customer;
        if (!$customer || !$customer->linkedin_ads_account_id) {
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
            Log::warning("MonitorCampaignStatus: LinkedIn Ads check failed for campaign {$campaign->id}: " . $e->getMessage());
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
        Log::error('MonitorCampaignStatus failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
