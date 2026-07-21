<?php

namespace App\Jobs;

use App\Models\VideoCollateral;
use App\Services\GeminiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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

    public function handle(GeminiService $geminiService): void
    {
        $source = $this->sourceVideo;

        if ($source->status !== 'completed' || !$source->s3_path) {
            Log::warning("ExtendPMaxVideo: source video {$source->id} not completed / has no file — skipping");
            return;
        }

        // Don't extend an already-extended clip (avoids runaway length + cost).
        if (($source->extension_count ?? 0) >= 1) {
            Log::info("ExtendPMaxVideo: video {$source->id} already extended — skipping");
            return;
        }

        try {
            $bytes = Storage::disk('s3')->get($source->s3_path);
        } catch (\Throwable $e) {
            Log::error("ExtendPMaxVideo: could not read source video {$source->id}: " . $e->getMessage());
            return;
        }
        if (!$bytes) {
            Log::error("ExtendPMaxVideo: empty source video {$source->id}");
            return;
        }

        $brand = $source->campaign?->customer?->name ?? 'the brand';
        $prompt = "Seamlessly continue this product-showcase video for {$brand}. Maintain the exact "
            . "same subjects, lighting, colour grading, camera style and environment — one continuous take. "
            . "No on-screen text, no watermarks.";

        $operationName = $geminiService->extendVideoFromBytes(
            base64_encode($bytes),
            'video/mp4',
            $prompt,
            ['operation' => 'pmax_video_extension', 'campaign_id' => $source->campaign_id]
        );

        if (!$operationName) {
            Log::error("ExtendPMaxVideo: failed to start extension for video {$source->id}");
            return;
        }

        // Retire the too-short source; the extended clip supersedes it.
        $source->update(['is_active' => false]);

        $extended = VideoCollateral::create([
            'campaign_id'     => $source->campaign_id,
            'strategy_id'     => $source->strategy_id,
            'platform'        => $source->platform,
            'script'          => $source->script,
            'status'          => 'generating',
            'operation_name'  => $operationName,
            'is_active'       => true,
            'parent_video_id' => $source->id,
            'extension_count' => ($source->extension_count ?? 0) + 1,
        ]);

        Log::info("ExtendPMaxVideo: extension started for source {$source->id} -> extended {$extended->id}");
        CheckVideoStatus::dispatch($extended)->delay(now()->addMinute());
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ExtendPMaxVideo failed: ' . $exception->getMessage());
    }
}
