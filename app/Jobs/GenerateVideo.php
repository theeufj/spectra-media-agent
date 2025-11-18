<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Strategy;
use App\Models\VideoCollateral;
use App\Services\VideoGeneration\VideoGenerationService;
use App\Services\GeminiService;
use App\Prompts\VideoScriptPrompt;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Prompts\VideoFromScriptPrompt;

class GenerateVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 900;

    public function __construct(
        protected Campaign $campaign,
        protected Strategy $strategy,
        protected string $platform
    ) {
    }

    public function handle(VideoGenerationService $videoGenerationService, GeminiService $geminiService): void
    {
        Log::info("Starting video generation job for Strategy ID: {$this->strategy->id} on platform {$this->platform}");

        $videoCollateral = null;
        try {
            // Fetch brand guidelines if available
            $brandGuidelines = $this->campaign->user->customer->brandGuideline ?? null;
            if (!$brandGuidelines) {
                Log::warning("No brand guidelines found for customer ID: {$this->campaign->user->customer->id}");
            }

            // 1. Generate the video script
            $scriptPrompt = (new VideoScriptPrompt($this->strategy->video_strategy, $brandGuidelines))->getPrompt();
            $scriptResponse = $geminiService->generateContent('gemini-flash-latest', $scriptPrompt);
            $script = $scriptResponse['text'] ?? 'No script generated.';
            Log::info("Generated video script: {$script}");

            // 2. Create a placeholder VideoCollateral record
            $videoCollateral = VideoCollateral::create([
                'campaign_id' => $this->campaign->id,
                'strategy_id' => $this->strategy->id,
                'platform' => $this->platform,
                'status' => 'pending',
                'is_active' => true,
            ]);

            // 3. Generate the final video prompt using the dedicated prompt class
            $videoPrompt = (new VideoFromScriptPrompt($this->strategy->video_strategy, $script))->getPrompt();
            Log::info("Combined video prompt: {$videoPrompt}");

            // 4. Start the video generation and get the operation name
            $operationName = $videoGenerationService->startGeneration($videoPrompt);

            if (!$operationName) {
                $videoCollateral->update(['status' => 'failed']);
                throw new \Exception('Failed to start video generation.');
            }

            // 5. Update the record with the operation name and set status to 'generating'
            $videoCollateral->update([
                'operation_name' => $operationName,
                'status' => 'generating',
            ]);

            Log::info("Video generation initiated for Strategy ID: {$this->strategy->id}. Operation Name: {$operationName}");

            // 6. Dispatch the job to check the video status.
            CheckVideoStatus::dispatch($videoCollateral)->delay(now()->addMinutes(1));

        } catch (\Exception $e) {
            if ($videoCollateral) {
                $videoCollateral->update(['status' => 'failed']);
            }
            Log::error("Error in GenerateVideo job for Strategy ID {$this->strategy->id}: " . $e->getMessage());
            $this->fail($e);
        }
    }
}
