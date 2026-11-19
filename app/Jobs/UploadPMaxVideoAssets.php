<?php

namespace App\Jobs;

use App\Models\Strategy;
use App\Models\VideoCollateral;
use App\Services\Creative\CreativeRelease;
use App\Services\GoogleAds\CommonServices\ReadCampaignConfiguration;
use App\Services\GoogleAds\PerformanceMaxServices\LinkAssetGroupAsset;
use App\Services\GoogleAds\VideoServices\UploadPMaxVideoToGoogle;
use App\Services\GoogleAds\VideoServices\UploadVideoAsset;
use App\Services\GoogleAds\VideoServices\UploadVideoToYouTube;
use App\Services\StorageHelper;
use App\Services\VideoGeneration\PerformanceMaxVideo;
use Google\Ads\GoogleAds\V26\Enums\AssetFieldTypeEnum\AssetFieldType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/** Prepare selected video uploads and optionally link the processed videos to their asset group. */
class UploadPMaxVideoAssets implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 16;

    public int $timeout = 600;

    public array $backoff = [60, 120, 300];

    private ?array $videoIds = null;

    private bool $prepareOnly = false;

    public function __construct(
        protected int $strategyId,
        protected string $customerId,
        protected ?string $assetGroupResourceName = null,
        ?array $videoIds = null,
        bool $prepareOnly = false,
    ) {
        $this->videoIds = $videoIds;
        $this->prepareOnly = $prepareOnly;
    }

    public function handle(): void
    {
        $strategy = Strategy::with('campaign.customer')->find($this->strategyId);
        $campaign = $strategy?->campaign;
        $customer = $campaign?->customer;
        if (! $customer || $customer->cleanGoogleCustomerId() !== $this->customerId
            || $strategy->campaign_type !== 'performance_max' || ! $strategy->signed_off_at || $campaign->hasPassedEndDate()) {
            return;
        }

        $resource = $strategy->reusableGoogleCampaignId();
        if ($resource && ctype_digit($resource)) {
            $resource = "customers/{$this->customerId}/campaigns/{$resource}";
        }
        if (! $resource || ! preg_match('#^customers/'.$this->customerId.'/campaigns/\d+$#D', $resource)) {
            return;
        }

        $release = app(CreativeRelease::class);
        if (! $release->isApproved($strategy)) {
            return;
        }
        $videos = VideoCollateral::forDeployment($strategy)->where('campaign_id', $campaign->id)
            ->where('status', 'completed')->whereNotNull('s3_path')
            ->when($this->videoIds !== null, fn ($query) => $query->whereKey($this->videoIds))->get();
        if ($videos->isEmpty()) {
            return;
        }

        $group = null;
        if (! $this->prepareOnly) {
            $reader = app(ReadCampaignConfiguration::class, ['customer' => $customer]);
            $groups = collect(array_column($reader->performanceMaxGroups($this->customerId, $resource), 'assetGroup'))->where('status', 'ENABLED');
            $requestedGroup = $this->assetGroupResourceName ?? $strategy->execution_result['metadata']['google_pmax_staging']['asset_group'] ?? null;
            $group = $requestedGroup ? $groups->firstWhere('resourceName', $requestedGroup) : ($groups->count() === 1 ? $groups->first() : null);
            if (! $group) {
                throw new \RuntimeException('The selected Performance Max asset group is not enabled in this campaign.');
            }
        }

        $googleUpload = app(UploadPMaxVideoToGoogle::class, ['customer' => $customer]);
        $pending = false;
        $error = null;
        foreach ($videos as $video) {
            $lease = Cache::lock('pmax-video-upload:'.$video->id, $this->timeout + 30);
            if (! $lease->get()) {
                $pending = true;

                continue;
            }
            try {
                $video->refresh();
                if (! $video->is_active || ! $video->should_deploy || $video->status !== 'completed' || ! $video->s3_path) {
                    continue;
                }
                $bytes = StorageHelper::get($video->s3_path);
                if (! $bytes || ! $release->matchesMedia($strategy, 'video', $video->id, $bytes)) {
                    throw new \RuntimeException('A selected video no longer matches the customer-approved render.');
                }
                $youtubeId = $this->prepareVideo($video, $googleUpload, $campaign->name);
                if (! $youtubeId) {
                    $pending = true;

                    continue;
                }
                $video->refresh();
                $current = $strategy->fresh();
                if ($group && $video->is_active && $video->should_deploy
                    && $current && $release->isApproved($current)
                    && $strategy->creative_approval['fingerprint'] === $current->creative_approval['fingerprint']
                    && ! $googleUpload->isLinked($this->customerId, $group['resourceName'], $youtubeId)) {
                    $uploadAsset = app(UploadVideoAsset::class, ['customer' => $customer]);
                    $linkAsset = app(LinkAssetGroupAsset::class, ['customer' => $customer]);
                    $asset = $uploadAsset($this->customerId, $youtubeId, 'Ad video '.$video->id);
                    if (! $asset || ! $linkAsset($this->customerId, $group['resourceName'], $asset, AssetFieldType::YOUTUBE_VIDEO)) {
                        throw new \RuntimeException('Google has not confirmed the video asset group link.');
                    }
                }
            } catch (\Throwable $exception) {
                report($exception);
                $error ??= $exception;
            } finally {
                $lease->release();
            }
        }

        if ($error) {
            throw $error;
        }
        if ($pending) {
            $this->release(45);
        }
    }

    private function prepareVideo(VideoCollateral $video, UploadPMaxVideoToGoogle $googleUpload, string $campaignName): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]{11}$/D', $video->youtube_video_id ?? '')) {
            return $video->youtube_video_id;
        }

        $metadata = $video->generation_metadata ?? [];
        $upload = $metadata['google_ads_video_upload'] ?? null;
        if ($upload) {
            $youtubeId = $googleUpload->processedVideoId($this->customerId, $upload);
        } else {
            $duration = $metadata['duration_seconds'] ?? PerformanceMaxVideo::duration(StorageHelper::get($video->s3_path) ?? '');
            if ($duration === null || $duration < PerformanceMaxVideo::MIN_SECONDS) {
                throw new \RuntimeException('The selected Performance Max video must run for at least 10 seconds.');
            }

            $title = "{$campaignName} - ad video {$video->id}";
            if (filled(config('services.youtube.client_id')) && filled(config('services.youtube.client_secret')) && filled(config('services.youtube.refresh_token'))) {
                $youtubeId = app(UploadVideoToYouTube::class)($video->s3_path, $title);
                if (! $youtubeId) {
                    throw new \RuntimeException('The YouTube upload could not be started.');
                }
            } else {
                $upload = $googleUpload->start($this->customerId, $video->s3_path, $title, $video->provider !== null && $video->source !== 'uploaded');
                $video->update(['generation_metadata' => $metadata + ['google_ads_video_upload' => $upload]]);

                return null;
            }
        }

        if ($youtubeId) {
            $video->update(['youtube_video_id' => $youtubeId]);
        }

        return $youtubeId;
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Performance Max video upload failed', ['strategy_id' => $this->strategyId, 'error' => $exception->getMessage()]);
    }
}
