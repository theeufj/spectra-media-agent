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
                throw new \Exception("Source video missing Gemini video URI. Cannot extend non-Gemini videos.");
            }

            // Check extension count limit (max 20 extensions)
            $extensionCount = $this->sourceVideo->extension_count ?? 0;
            if ($extensionCount >= 20) {
                throw new \Exception("Maximum extension limit (20) reached for this video.");
            }

            // Start video extension
            $operationName = $geminiService->extendVideo(
                $this->sourceVideo->gemini_video_uri,
                $this->extensionPrompt
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
                'script' => $this->sourceVideo->script . "\n\n[EXTENSION]: " . $this->extensionPrompt,
                'status' => 'generating',
                'operation_name' => $operationName,
                'is_active' => true,
                'parent_video_id' => $this->sourceVideo->id,
                'extension_count' => $extensionCount + 1,
            ]);

            Log::info("Created extended video record with ID: {$extendedVideo->id}");

            // Dispatch job to check the extension status
            CheckVideoStatus::dispatch($extendedVideo)->delay(now()->addMinutes(1));

        } catch (\Exception $e) {
            Log::error("Error in ExtendVideo job for VideoCollateral ID {$this->sourceVideo->id}: " . $e->getMessage());
            $this->fail($e);
        }
    }
}
