<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\VideoCollateral;
use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\GoogleAds\VideoServices\UploadVideoAsset;
use App\Services\GoogleAds\VideoServices\UploadVideoToYouTube;
use App\Services\GoogleAds\PerformanceMaxServices\LinkAssetGroupAsset;
use Google\Ads\GoogleAds\V22\Enums\AssetFieldTypeEnum\AssetFieldType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Uploads video collaterals to YouTube (if not already uploaded) and links them
 * as YOUTUBE_VIDEO assets to an existing PMax asset group.
 *
 * Dispatched automatically after PMax deployment when videos have no YouTube IDs.
 * Also used by the pmax:repair-assets command for already-deployed campaigns.
 */
class UploadPMaxVideoAssets implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 600;

    public function __construct(
        protected int $strategyId,
        protected string $customerId,
        protected ?string $assetGroupResourceName = null
    ) {}

    public function handle(): void
    {
        $strategy = Strategy::find($this->strategyId);
        if (!$strategy) {
            Log::error("UploadPMaxVideoAssets: Strategy {$this->strategyId} not found");
            return;
        }

        $customer = $strategy->campaign?->customer;
        if (!$customer) {
            Log::error("UploadPMaxVideoAssets: No customer found for strategy {$this->strategyId}");
            return;
        }

        // Resolve the asset group if a caller (e.g. video-completion) didn't pass one.
        $assetGroupResourceName = $this->assetGroupResourceName
            ?: $this->resolveAssetGroupResource($customer, $strategy->campaign);
        if (!$assetGroupResourceName) {
            Log::warning("UploadPMaxVideoAssets: No asset group resolved for strategy {$this->strategyId}");
            return;
        }

        // Look up at campaign level — videos are shared across all strategies.
        $videos = VideoCollateral::where('campaign_id', $strategy->campaign_id)
            ->where('is_active', true)
            ->get();

        if ($videos->isEmpty()) {
            Log::info("UploadPMaxVideoAssets: No active videos for campaign {$strategy->campaign_id}");
            return;
        }

        $youtubeUploader  = new UploadVideoToYouTube();
        $adAssetUploader  = new UploadVideoAsset($customer);
        $assetLinker      = new LinkAssetGroupAsset($customer);
        $linked           = 0;

        foreach ($videos as $video) {
            try {
                // Step 1: Upload to YouTube if no ID yet
                if (!$video->youtube_video_id) {
                    $title = "Ad Video - " . ($strategy->campaign->name ?? 'Campaign') . " #{$video->id}";
                    $youtubeId = ($youtubeUploader)($video->s3_path, $title);

                    if (!$youtubeId) {
                        Log::warning("UploadPMaxVideoAssets: Could not upload video {$video->id} to YouTube — skipping", [
                            's3_path' => $video->s3_path,
                        ]);
                        continue;
                    }

                    $video->update(['youtube_video_id' => $youtubeId]);
                    Log::info("UploadPMaxVideoAssets: Uploaded video to YouTube", [
                        'video_id'         => $video->id,
                        'youtube_video_id' => $youtubeId,
                    ]);
                }

                // Step 2: Register as Google Ads video asset
                $assetResourceName = ($adAssetUploader)(
                    $this->customerId,
                    $video->youtube_video_id,
                    "Video Asset #{$video->id}"
                );

                if (!$assetResourceName) {
                    Log::warning("UploadPMaxVideoAssets: Could not register video {$video->id} as Google Ads asset");
                    continue;
                }

                // Step 3: Link to asset group
                $linkResourceName = ($assetLinker)(
                    $this->customerId,
                    $assetGroupResourceName,
                    $assetResourceName,
                    AssetFieldType::YOUTUBE_VIDEO
                );

                if ($linkResourceName) {
                    $linked++;
                    Log::info("UploadPMaxVideoAssets: Linked video asset to asset group", [
                        'video_id'             => $video->id,
                        'youtube_video_id'     => $video->youtube_video_id,
                        'asset_resource_name'  => $assetResourceName,
                        'link_resource_name'   => $linkResourceName,
                    ]);
                }
            } catch (\Exception $e) {
                Log::error("UploadPMaxVideoAssets: Error processing video {$video->id}: " . $e->getMessage());
            }
        }

        Log::info("UploadPMaxVideoAssets: Done — linked {$linked}/{$videos->count()} videos to asset group", [
            'strategy_id'          => $this->strategyId,
            'asset_group'          => $assetGroupResourceName,
        ]);
    }

    /** Resolve the first enabled asset group resource name for a PMax campaign. */
    private function resolveAssetGroupResource(Customer $customer, ?Campaign $campaign): ?string
    {
        $campId = $campaign?->googleCampaignNumericId();
        if (!$campId) {
            return null;
        }

        try {
            $svc = new class($customer) extends BaseGoogleAdsService {
                public function get(string $cid, string $campId): ?string
                {
                    $this->ensureClient();
                    $q = "SELECT asset_group.resource_name FROM asset_group "
                        . "WHERE campaign.id = {$campId} AND asset_group.status = 'ENABLED'";
                    foreach ($this->searchQuery($cid, $q)->getIterator() as $row) {
                        return $row->getAssetGroup()->getResourceName();
                    }
                    return null;
                }
            };
            return $svc->get($customer->cleanGoogleCustomerId(), $campId);
        } catch (\Throwable $e) {
            Log::warning('UploadPMaxVideoAssets: asset group resolve failed: ' . $e->getMessage());
            return null;
        }
    }

    public function failed(\Throwable $e): void
    {
        Log::error("UploadPMaxVideoAssets: Job failed for strategy {$this->strategyId}: " . $e->getMessage());
    }
}
