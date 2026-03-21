<?php

namespace App\Jobs;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
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
            ->whereNotNull('google_ads_campaign_id')
            ->whereNotNull('customer_id')
            ->get();

        foreach ($campaigns as $campaign) {
            try {
                $this->checkGoogleAdsStatus($campaign, $getCampaignStatus);
            } catch (\Exception $e) {
                Log::error("Failed to monitor campaign {$campaign->id}: " . $e->getMessage());
            }
        }
    }

    private function checkGoogleAdsStatus(Campaign $campaign, GetCampaignStatus $getCampaignStatus): void
    {
        $resourceName = "customers/{$campaign->customer_id}/campaigns/{$campaign->google_ads_campaign_id}";
        $statusData = $getCampaignStatus($campaign->customer_id, $resourceName);

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
