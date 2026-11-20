<?php

namespace App\Jobs;

use App\Models\VideoCollateral;
use App\Services\GeminiService;
use App\Services\StorageHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Extends a completed Veo clip by ~7s (8s → ~15s) so it satisfies Google Ads PMax's
 * 10-second minimum. Unlike ExtendVideo (which needs a Gemini Files URI), this reads the
 * stored video bytes and extends via the Vertex inline-bytes path — the only path
 * available for our Vertex-generated PMax videos.
 */
class ExtendPMaxVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(protected VideoCollateral $sourceVideo) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping("video-extension:{$this->sourceVideo->id}"))->shared()->releaseAfter(30)->expireAfter(660)];
    }

    public function handle(GeminiService $geminiService): void
    {
        $source = $this->sourceVideo->fresh();

        if (! $source || ! $source->is_active || $source->status !== 'completed' || ! $source->s3_path
            || ($source->provider ?? 'veo') !== 'veo' || ($source->extension_count ?? 0) >= 1
            || VideoCollateral::where('parent_video_id', $source->id)->exists()) {
            Log::info("ExtendPMaxVideo: video {$this->sourceVideo->id} has no pending extension.");

            return;
        }

        $bytes = StorageHelper::get($source->s3_path);
        if (! $bytes) {
            throw new \RuntimeException("Could not read Performance Max video {$source->id} for extension.");
        }

        $brand = $source->campaign?->customer?->name ?? 'the brand';
        $prompt = "Seamlessly continue this product-showcase video for {$brand}. Maintain the exact "
            .'same subjects, lighting, colour grading, camera style and environment — one continuous take. '
            .'No on-screen text, no watermarks.';

        $operationName = $geminiService->extendVideoFromBytes(
            base64_encode($bytes),
            'video/mp4',
            $prompt,
            ['operation' => 'pmax_video_extension', 'campaign_id' => $source->campaign_id]
        );

        if (! $operationName) {
            throw new \RuntimeException("Could not start extension for Performance Max video {$source->id}.");
        }

        // Retire the too-short source; the extended clip supersedes it.
        $source->update(['is_active' => false]);

        $extended = VideoCollateral::create([
            'campaign_id' => $source->campaign_id,
            'strategy_id' => $source->strategy_id,
            'platform' => $source->platform,
            'script' => $source->script,
            'status' => 'generating',
            'operation_name' => $operationName,
            'is_active' => true,
            'provider' => 'veo',
            'should_deploy' => (bool) $source->should_deploy,
            'variation_index' => $source->variation_index,
            'generation_metadata' => \Illuminate\Support\Arr::except($source->generation_metadata ?? [], ['duration_seconds']),
            'parent_video_id' => $source->id,
            'extension_count' => ($source->extension_count ?? 0) + 1,
        ]);

        Log::info("ExtendPMaxVideo: extension started for source {$source->id} -> extended {$extended->id}");
        CheckVideoStatus::dispatch($extended)->delay(now()->addMinute());
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ExtendPMaxVideo failed: '.$exception->getMessage());
    }
}
