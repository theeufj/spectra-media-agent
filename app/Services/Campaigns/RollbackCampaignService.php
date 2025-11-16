<?php

namespace App\Services\Campaigns;

use App\Models\Campaign;
use App\Models\Strategy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class RollbackCampaignService
{
    public function __invoke(Campaign $campaign): bool
    {
        try {
            DB::transaction(function () use ($campaign) {
                // Find the most recent versioning timestamp for this campaign's strategies
                $lastVersionTimestamp = DB::table('strategy_versions')
                    ->where('campaign_id', $campaign->id)
                    ->max('versioned_at');

                if (!$lastVersionTimestamp) {
                    Log::warning("No previous strategy versions found to roll back for campaign {$campaign->id}.");
                    return false;
                }

                // Get all strategy versions from that last timestamp
                $strategyVersionsToRestore = DB::table('strategy_versions')
                    ->where('campaign_id', $campaign->id)
                    ->where('versioned_at', $lastVersionTimestamp)
                    ->get();

                if ($strategyVersionsToRestore->isEmpty()) {
                    Log->warning("Could not find any strategy versions to restore for campaign {$campaign->id} at timestamp {$lastVersionTimestamp}.");
                    return false;
                }

                // Deactivate all current active strategies for the campaign
                $campaign->strategies()->update(['signed_off_at' => null]); // Assuming 'signed_off_at' being null means inactive

                // Restore the old strategies
                foreach ($strategyVersionsToRestore as $version) {
                    Strategy::create([
                        'campaign_id' => $version->campaign_id,
                        'platform' => $version->platform,
                        'ad_copy_strategy' => $version->ad_copy_strategy,
                        'imagery_strategy' => $version->imagery_strategy,
                        'video_strategy' => $version->video_strategy,
                        'bidding_strategy' => json_decode($version->bidding_strategy, true),
                        'cpa_target' => $version->cpa_target,
                        'revenue_cpa_multiple' => $version->revenue_cpa_multiple,
                        'signed_off_at' => Carbon::now(), // Mark as active
                    ]);
                }

                Log::info("Successfully rolled back strategies for campaign {$campaign->id} to version from {$lastVersionTimestamp}.");
            });

            return true;
        } catch (\Exception $e) {
            Log::error("Error rolling back campaign {$campaign->id}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return false;
        }
    }
}
