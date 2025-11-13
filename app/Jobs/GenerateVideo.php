<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\Strategy;
use App\Models\VideoCollateral;
use App\Services\GeminiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateVideo implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 900;

    public function __construct(
        protected Campaign $campaign,
        protected Strategy $strategy
    ) {
    }

    public function handle(GeminiService $geminiService): void
    {
        Log::info("Starting video generation job for Strategy ID: {$this->strategy->id}");

        try {
            $prompt = $this->strategy->video_strategy;

            // Create a placeholder record
            $videoCollateral = VideoCollateral::create([
                'campaign_id' => $this->campaign->id,
                'strategy_id' => $this->strategy->id,
                'platform' => $this->strategy->platform,
                'status' => 'processing',
            ]);

            $operationName = $geminiService->startVideoGeneration($prompt);

            if (!$operationName) {
                $videoCollateral->update(['status' => 'failed']);
                throw new \Exception('Failed to start video generation with Gemini.');
            }

            $videoCollateral->update(['operation_name' => $operationName]);

            // Dispatch the status checking job with a delay
            CheckVideoStatus::dispatch($videoCollateral)->delay(now()->addSeconds(60));

            Log::info("Successfully started video generation for Strategy ID: {$this->strategy->id}. Operation Name: {$operationName}");

        } catch (\Exception $e) {
            Log::error("Error in GenerateVideo job for Strategy ID {$this->strategy->id}: " . $e->getMessage());
            $this->fail($e);
        }
    }
}
