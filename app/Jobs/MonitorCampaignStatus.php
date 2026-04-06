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
                      ->orWhereNotNull('facebook_ads_campaign_id');
            })
            ->whereNotNull('customer_id')
            ->get();

        foreach ($campaigns as $campaign) {
            try {
                if ($campaign->google_ads_campaign_id) {
                    $this->checkGoogleAdsStatus($campaign, $getCampaignStatus);
                }

                if ($campaign->facebook_ads_campaign_id) {
                    $this->checkFacebookAdsStatus($campaign);
                }
            } catch (\Exception $e) {
                Log::error("Failed to monitor campaign {$campaign->id}: " . $e->getMessage());
            }
        }
    }

    private function checkGoogleAdsStatus(Campaign $campaign, GetCampaignStatus $getCampaignStatus): void
    {
        $customerId = $campaign->customer?->google_ads_customer_id;
        if (!$customerId) {
            return;
        }
        $resourceName = "customers/{$customerId}/campaigns/{$campaign->google_ads_campaign_id}";
        $statusData = $getCampaignStatus($customerId, $resourceName);

        if ($statusData) {
            $oldStatus = $campaign->primary_status;
            
            $campaign->update([
                'platform_status' => $this->mapStatus($statusData['status']),
                'primary_status' => $this->mapPrimaryStatus($statusData['primary_status']),
                'primary_status_reasons' => $statusData['primary_status_reasons'],
                'last_checked_at' => now(),
            ]);

            $this->notifyIfBecameActive($campaign, $oldStatus, 'ELIGIBLE');
        }
    }

    private function notifyIfBecameActive(Campaign $campaign, ?string $oldStatus, string $activeStatus): void
    {
        if ($oldStatus !== $activeStatus && $campaign->primary_status === $activeStatus) {
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

    private function checkFacebookAdsStatus(Campaign $campaign): void
    {
        $customer = $campaign->customer;
        if (!$customer || !$customer->facebook_ads_account_id) {
            return;
        }

        $campaignService = new FacebookCampaignService($customer);
        $fbCampaign = $campaignService->getCampaign($campaign->facebook_ads_campaign_id);

        if ($fbCampaign) {
            $oldStatus = $campaign->primary_status;
            $effectiveStatus = $fbCampaign['effective_status'] ?? 'UNKNOWN';
            $primaryStatus = $this->mapFacebookEffectiveStatus($effectiveStatus);

            $updateData = [
                'platform_status' => $fbCampaign['status'] ?? 'UNKNOWN',
                'primary_status' => $primaryStatus,
                'last_checked_at' => now(),
            ];

            // Store issues info if present
            if (!empty($fbCampaign['issues_info'])) {
                $updateData['primary_status_reasons'] = json_encode($fbCampaign['issues_info']);
            }

            $campaign->update($updateData);

            $this->notifyIfBecameActive($campaign, $oldStatus, 'ELIGIBLE');

            // Also notify if campaign became disapproved or has issues
            if (in_array($effectiveStatus, ['DISAPPROVED', 'WITH_ISSUES', 'CAMPAIGN_PAUSED'])) {
                $this->notifyIfStatusChanged($campaign, $oldStatus, $primaryStatus);
            }
        }
    }

    private function notifyIfStatusChanged(Campaign $campaign, ?string $oldStatus, string $newStatus): void
    {
        if ($oldStatus !== $newStatus) {
            Log::warning("Campaign {$campaign->id} status changed: {$oldStatus} -> {$newStatus}");

            AgentActivity::record(
                'monitoring',
                'status_changed',
                "Campaign \"{$campaign->name}\" status changed from {$oldStatus} to {$newStatus}",
                $campaign->customer_id,
                $campaign->id,
                ['old_status' => $oldStatus, 'new_status' => $newStatus, 'platform' => 'facebook']
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
}
