<?php

namespace App\Console\Commands;

use App\Jobs\UploadPMaxVideoAssets;
use App\Models\Strategy;
use App\Models\VideoCollateral;
use App\Services\Creative\CreativeRelease;
use Illuminate\Console\Command;

class RepairPMaxAssets extends Command
{
    protected $signature = 'pmax:repair-assets
                            {--strategy= : Strategy ID to repair}
                            {--youtube-ids= : Only restore the approved videos with these processed YouTube IDs}
                            {--async : Dispatch upload as a background job instead of running inline}';

    protected $description = 'Restore customer-approved videos in a deployed Performance Max asset group';

    public function handle(): int
    {
        $strategyId = $this->option('strategy');
        if (! $strategyId) {
            $this->error('--strategy is required');

            return self::FAILURE;
        }

        $strategy = Strategy::find($strategyId);
        if (! $strategy) {
            $this->error("Strategy {$strategyId} not found");

            return self::FAILURE;
        }

        $executionResult = $strategy->execution_result ?? [];
        $assetGroupResourceName = $executionResult['platform_ids']['asset_group'] ?? null;

        if (! $assetGroupResourceName) {
            $this->error("No asset_group found in strategy {$strategyId} execution_result. Has the campaign been deployed?");

            return self::FAILURE;
        }

        $customer = $strategy->campaign?->customer;
        $customerId = preg_replace('/[^0-9]/', '', $customer?->google_ads_customer_id ?? '');

        if (! $customerId) {
            $this->error("No Google Ads customer ID found for strategy {$strategyId}");

            return self::FAILURE;
        }

        if (! app(CreativeRelease::class)->isApproved($strategy)) {
            $this->error('Approve the exact creative selection in Site to Spend before restoring videos.');

            return self::FAILURE;
        }
        $videos = VideoCollateral::forDeployment($strategy)->where('status', 'completed');
        $youtubeIds = array_values(array_filter(array_map('trim', explode(',', $this->option('youtube-ids') ?? ''))));
        if ($youtubeIds !== []) {
            $videos->whereIn('youtube_video_id', $youtubeIds);
        }
        $ids = $videos->pluck('id')->all();
        if ($ids === [] || ($youtubeIds !== [] && count($ids) !== count(array_unique($youtubeIds)))) {
            $this->error('No approved videos matched the request.');

            return self::FAILURE;
        }

        if ($this->option('async') || $videos->whereNull('youtube_video_id')->exists()) {
            UploadPMaxVideoAssets::dispatch($strategy->id, $customerId, $assetGroupResourceName, videoIds: $ids);
            $this->info('Queued the approved videos for restoration.');
        } else {
            UploadPMaxVideoAssets::dispatchSync($strategy->id, $customerId, $assetGroupResourceName, videoIds: $ids);
        }

        return self::SUCCESS;
    }
}
