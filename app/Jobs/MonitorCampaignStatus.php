<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\GoogleAds\CommonServices\GetCampaignStatus;
use App\Services\FacebookAds\CampaignService;
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
                // Google Ads Monitoring
                if ($campaign->google_ads_campaign_id) {
                    $this->checkGoogleAdsStatus($campaign, $getCampaignStatus);
                }

                // Facebook Ads Monitoring
                if ($campaign->facebook_ads_campaign_id && $campaign->customer) {
                    $this->checkFacebookAdsStatus($campaign);
                }

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

    private function checkFacebookAdsStatus(Campaign $campaign): void
    {
        try {
            $campaignService = new CampaignService($campaign->customer);
            // We need a method to get a single campaign's status. 
            // The existing listCampaigns gets all. We can add getCampaign or just use list and filter (inefficient)
            // Or use the base service get() method directly if CampaignService doesn't expose it.
            // Let's assume we can fetch the campaign directly.
            // Looking at CampaignService, it extends BaseFacebookAdsService which has get().
            
            // We'll implement a quick fetch here or rely on a new method. 
            // For now, let's use the BaseService's get capability via the CampaignService instance.
            // But CampaignService doesn't expose get() publicly (it's protected in Base).
            // I'll assume I can add a method to CampaignService or use what's there.
            // Actually, I'll just use listCampaigns for the account and find the match for now to be safe, 
            // or better, I'll add `getCampaign` to CampaignService in a separate step if needed.
            // For this edit, I'll try to use a hypothetical `getCampaign` and if it fails I'll fix it.
            // Wait, I can't assume methods exist.
            // I'll use `listCampaigns` and filter, as it's safer with current context.
            
            $fbCampaigns = $campaignService->listCampaigns($campaign->customer->facebook_ads_account_id);
            
            if ($fbCampaigns) {
                foreach ($fbCampaigns as $fbCampaign) {
                    if ($fbCampaign['id'] === $campaign->facebook_ads_campaign_id) {
                        $oldStatus = $campaign->primary_status;
                        $status = $fbCampaign['status']; // e.g., 'ACTIVE', 'PAUSED'
                        
                        $campaign->update([
                            'platform_status' => $status,
                            'primary_status' => $status === 'ACTIVE' ? 'ELIGIBLE' : $status, // Map to our internal standard
                            'last_checked_at' => now(),
                        ]);

                        $this->notifyIfBecameActive($campaign, $oldStatus, 'ELIGIBLE');
                        break;
                    }
                }
            }
        } catch (\Exception $e) {
            Log::error("Facebook monitoring failed for campaign {$campaign->id}: " . $e->getMessage());
        }
    }

    private function notifyIfBecameActive(Campaign $campaign, ?string $oldStatus, string $activeStatus): void
    {
        if ($oldStatus !== $activeStatus && $campaign->primary_status === $activeStatus) {
            Log::info("Campaign {$campaign->id} is now {$activeStatus}.");
            
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
