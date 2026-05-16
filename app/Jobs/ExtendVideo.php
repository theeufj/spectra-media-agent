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

class ExtendVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 900;

    public function __construct(
        protected VideoCollateral $sourceVideo,
        protected string $extensionPrompt
    ) {
    }

    public function handle(GeminiService $geminiService): void
    {
        Log::info("--- ExtendVideo Job Started ---");
        Log::info("Source VideoCollateral ID: {$this->sourceVideo->id}");
        Log::info("Extension Prompt: {$this->extensionPrompt}");

        try {
            // Validate source video
            if ($this->sourceVideo->status !== 'completed') {
                throw new \Exception("Source video must be completed before extension. Current status: {$this->sourceVideo->status}");
            }

            if (!$this->sourceVideo->gemini_video_uri) {
                throw new \Exception("Source video missing Gemini URI. Cannot extend.");
            }

            // Check extension count limit (max 20 extensions)
            $extensionCount = $this->sourceVideo->extension_count ?? 0;
            if ($extensionCount >= 20) {
                throw new \Exception("Maximum extension limit (20) reached for this video.");
            }

            // Use the stored generation URI directly. Veo extension expects the URI
            // from the original generation response (the "uri" key in the request body),
            // not a Files API URI. Files API URIs use a different field ("fileUri") and
            // re-uploading converts the format — causing Veo to reject or ignore the source.
            // Generation URIs are valid for 48 h, which is sufficient for freshly-extended videos.
            $videoUri = $this->sourceVideo->gemini_video_uri;
            Log::info("ExtendVideo: Using stored gemini_video_uri for extension", ['uri' => $videoUri]);

            // Build a context-rich prompt so Veo understands the brand, product and
            // original scene — without this, it generates a disconnected continuation.
            $enrichedPrompt = $this->buildEnrichedPrompt();

            // Start video extension
            $operationName = $geminiService->extendVideo(
                $videoUri,
                $enrichedPrompt
            );

            if (!$operationName) {
                throw new \Exception('Failed to start video extension.');
            }

            Log::info("Video extension started successfully. Operation: {$operationName}");

            // Create new VideoCollateral record for the extended video
            $extendedVideo = VideoCollateral::create([
                'campaign_id' => $this->sourceVideo->campaign_id,
                'strategy_id' => $this->sourceVideo->strategy_id,
                'platform' => $this->sourceVideo->platform,
                'script' => $this->sourceVideo->script . "\n\n[EXTENSION " . ($extensionCount + 1) . "]: " . $this->extensionPrompt,
                'status' => 'generating',
                'operation_name' => $operationName,
                'is_active' => true,
                'parent_video_id' => $this->sourceVideo->id,
                'extension_count' => $extensionCount + 1,
                'refinement_depth' => ($this->sourceVideo->refinement_depth ?? 0) + 1,
            ]);

            Log::info("Created extended video record with ID: {$extendedVideo->id}");

            // Dispatch job to check the extension status
            CheckVideoStatus::dispatch($extendedVideo)->delay(now()->addMinutes(1));

        } catch (\Exception $e) {
            Log::error("Error in ExtendVideo job for VideoCollateral ID {$this->sourceVideo->id}: " . $e->getMessage());
            $this->fail($e);
        }
    }

    /**
     * Build a context-rich extension prompt so Veo maintains visual and brand
     * consistency across 8-second clips.
     *
     * Without original context, Veo generates a disconnected continuation that
     * doesn't match the brand, product, or scene in the source video.
     */
    private function buildEnrichedPrompt(): string
    {
        $parts = [];

        // Original script — tells Veo what was depicted in the source clip
        $originalScript = trim($this->sourceVideo->script ?? '');
        if ($originalScript) {
            // Strip any previous [EXTENSION x]: prefixes to get the root script
            $rootScript = preg_split('/\[EXTENSION \d+\]:/i', $originalScript)[0];
            $rootScript = trim($rootScript);
            if ($rootScript) {
                $parts[] = "ORIGINAL VIDEO SCENE: {$rootScript}";
            }
        }

        // Brand / customer context
        $customer = $this->sourceVideo->campaign?->customer;
        if ($customer) {
            $parts[] = "BRAND: {$customer->name}";
            if ($customer->website) {
                $parts[] = "PRODUCT/SERVICE: {$customer->website}";
            }
        }

        // Strategy video context
        $strategy = $this->sourceVideo->strategy;
        if ($strategy?->video_strategy) {
            $videoStrategy = trim($strategy->video_strategy);
            if ($videoStrategy && !preg_match('/^n\/a/i', $videoStrategy)) {
                $parts[] = "CAMPAIGN VIDEO DIRECTION: " . mb_substr($videoStrategy, 0, 300);
            }
        }

        // User's requested continuation
        $parts[] = "CONTINUATION DIRECTION: {$this->extensionPrompt}";

        // Consistency instruction
        $parts[] = "IMPORTANT: Maintain exact visual continuity — same subjects, lighting, color grading, camera style, and environment as the preceding clip. This is a seamless 8-second continuation, not a new scene.";

        return implode("\n\n", $parts);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ExtendVideo failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
