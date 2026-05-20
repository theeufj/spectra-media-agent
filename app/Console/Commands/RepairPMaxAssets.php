<?php

namespace App\Console\Commands;

use App\Jobs\UploadPMaxVideoAssets;
use App\Models\Strategy;
use App\Models\VideoCollateral;
use App\Services\GoogleAds\VideoServices\UploadVideoAsset;
use App\Services\GoogleAds\VideoServices\UploadVideoToYouTube;
use App\Services\GoogleAds\PerformanceMaxServices\LinkAssetGroupAsset;
use Google\Ads\GoogleAds\V22\Enums\AssetFieldTypeEnum\AssetFieldType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Repair an existing PMax campaign's asset group by uploading missing video assets.
 *
 * Usage (auto-upload to YouTube):
 *   php artisan pmax:repair-assets --strategy=730
 *
 * Usage (manual YouTube IDs, no upload needed):
 *   php artisan pmax:repair-assets --strategy=730 --youtube-ids=dQw4w9WgXcQ,abc123xyz
 *
 * The --youtube-ids option assigns IDs to videos in order (first ID → oldest video, etc.)
 * and then links them to the asset group immediately without any upload step.
 */
class RepairPMaxAssets extends Command
{
    protected $signature = 'pmax:repair-assets
                            {--strategy= : Strategy ID to repair}
                            {--youtube-ids= : Comma-separated YouTube video IDs to assign (skips upload)}
                            {--async : Dispatch upload as a background job instead of running inline}';

    protected $description = 'Upload missing video assets to a deployed PMax asset group';

    public function handle(): int
    {
        $strategyId = $this->option('strategy');
        if (!$strategyId) {
            $this->error('--strategy is required');
            return self::FAILURE;
        }

        $strategy = Strategy::find($strategyId);
        if (!$strategy) {
            $this->error("Strategy {$strategyId} not found");
            return self::FAILURE;
        }

        $executionResult = $strategy->execution_result ?? [];
        $assetGroupResourceName = $executionResult['platform_ids']['asset_group'] ?? null;

        if (!$assetGroupResourceName) {
            $this->error("No asset_group found in strategy {$strategyId} execution_result. Has the campaign been deployed?");
            return self::FAILURE;
        }

        $customer   = $strategy->campaign?->customer;
        $customerId = preg_replace('/[^0-9]/', '', $customer?->google_ads_customer_id ?? '');

        if (!$customerId) {
            $this->error("No Google Ads customer ID found for strategy {$strategyId}");
            return self::FAILURE;
        }

        $this->info("Strategy: {$strategyId} | Customer: {$customerId}");
        $this->info("Asset group: {$assetGroupResourceName}");

        // Look up at campaign level — videos are shared across all strategies.
        $videos = VideoCollateral::where('campaign_id', $strategy->campaign_id)
            ->where('is_active', true)
            ->get();

        if ($videos->isEmpty()) {
            $this->warn("No active video collaterals found for campaign {$strategy->campaign_id}");
            return self::SUCCESS;
        }

        $this->info("Found {$videos->count()} video(s)");

        // If manual YouTube IDs provided, assign them and link directly
        $manualIds = $this->option('youtube-ids')
            ? array_filter(explode(',', $this->option('youtube-ids')))
            : [];

        if (!empty($manualIds)) {
            return $this->linkManualIds($videos, $manualIds, $customerId, $assetGroupResourceName, $customer);
        }

        // Otherwise upload to YouTube (async or inline)
        if ($this->option('async')) {
            UploadPMaxVideoAssets::dispatch($strategyId, $customerId, $assetGroupResourceName);
            $this->info("Dispatched UploadPMaxVideoAssets job for strategy {$strategyId}");
            return self::SUCCESS;
        }

        return $this->uploadAndLink($videos, $customerId, $assetGroupResourceName, $customer);
    }

    private function linkManualIds($videos, array $manualIds, string $customerId, string $assetGroupResourceName, $customer): int
    {
        $adAssetUploader = new UploadVideoAsset($customer);
        $assetLinker     = new LinkAssetGroupAsset($customer);
        $linked          = 0;

        foreach ($manualIds as $index => $youtubeId) {
            $youtubeId = trim($youtubeId);
            $video = $videos->get($index);

            if ($video && !$video->youtube_video_id) {
                $video->update(['youtube_video_id' => $youtubeId]);
                $this->line("  Saved youtube_video_id={$youtubeId} → video #{$video->id}");
            }

            $this->line("  Registering YouTube video {$youtubeId} as Google Ads asset...");
            $assetResourceName = ($adAssetUploader)($customerId, $youtubeId, "Video Asset #{$index}");

            if (!$assetResourceName) {
                $this->error("  Failed to register video {$youtubeId} as Google Ads asset");
                continue;
            }

            $linkResourceName = ($assetLinker)($customerId, $assetGroupResourceName, $assetResourceName, AssetFieldType::YOUTUBE_VIDEO);

            if ($linkResourceName) {
                $linked++;
                $this->info("  ✓ Linked {$youtubeId} to asset group");
            } else {
                $this->error("  ✗ Failed to link {$youtubeId} to asset group");
            }
        }

        $this->info("Done — linked {$linked}/" . count($manualIds) . " videos");
        return $linked > 0 ? self::SUCCESS : self::FAILURE;
    }

    private function uploadAndLink($videos, string $customerId, string $assetGroupResourceName, $customer): int
    {
        $youtubeUploader = new UploadVideoToYouTube();
        $adAssetUploader = new UploadVideoAsset($customer);
        $assetLinker     = new LinkAssetGroupAsset($customer);
        $linked          = 0;

        foreach ($videos as $video) {
            $this->line("Processing video #{$video->id} ({$video->s3_path})");

            if (!$video->youtube_video_id) {
                $this->line("  Uploading to YouTube...");
                $title = "Ad Video - " . ($strategy->campaign->name ?? 'Campaign') . " #{$video->id}";
                $youtubeId = ($youtubeUploader)($video->s3_path, $title);

                if (!$youtubeId) {
                    $this->error("  Failed to upload to YouTube. Is GOOGLE_YOUTUBE_REFRESH_TOKEN set?");
                    $this->line("  Hint: php artisan pmax:repair-assets --strategy={$this->option('strategy')} --youtube-ids=YOUR_ID");
                    continue;
                }

                $video->update(['youtube_video_id' => $youtubeId]);
                $this->line("  Uploaded → YouTube ID: {$youtubeId}");
            } else {
                $this->line("  Already has YouTube ID: {$video->youtube_video_id}");
            }

            $assetResourceName = ($adAssetUploader)($customerId, $video->youtube_video_id, "Video Asset #{$video->id}");

            if (!$assetResourceName) {
                $this->error("  Failed to register as Google Ads asset");
                continue;
            }

            $linkResourceName = ($assetLinker)($customerId, $assetGroupResourceName, $assetResourceName, AssetFieldType::YOUTUBE_VIDEO);

            if ($linkResourceName) {
                $linked++;
                $this->info("  ✓ Linked to asset group");
            } else {
                $this->error("  ✗ Failed to link to asset group");
            }
        }

        $this->info("Done — linked {$linked}/{$videos->count()} videos to asset group");
        return $linked > 0 ? self::SUCCESS : self::FAILURE;
    }
}
