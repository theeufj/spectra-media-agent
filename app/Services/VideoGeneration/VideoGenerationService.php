<?php

namespace App\Services\VideoGeneration;

use App\Prompts\VideoGenerationPrompt;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Log;

class VideoGenerationService
{
    private GeminiService $geminiService;

    public function __construct(GeminiService $geminiService)
    {
        $this->geminiService = $geminiService;
    }

    /**
     * Starts the video generation process.
     *
     * @param string $topic The topic for the video.
     * @param array $parameters Additional parameters for video generation.
     * @return string|null The operation name if generation started successfully, otherwise null.
     */
    public function startGeneration(string $topic, array $parameters = []): ?string
    {
        try {
            $prompt = VideoGenerationPrompt::create($topic);
            $operationName = $this->geminiService->startVideoGeneration($prompt, 'veo-3.1-generate-preview', $parameters);

            if (is_null($operationName)) {
                Log::error("Video generation failed to start: GeminiService returned null operation name.");
                return null;
            }

            Log::info("Video generation started successfully. Operation name: {$operationName}");
            return $operationName;

        } catch (\Exception $e) {
            Log::error("Error during video generation start: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Checks the status of a video generation operation.
     *
     * @param string $operationName The name of the operation to check.
     * @return array|null The operation result if complete, null if pending or failed.
     */
    public function checkGenerationStatus(string $operationName): ?array
    {
        try {
            $status = $this->geminiService->checkVideoGenerationStatus($operationName);

            if (is_null($status)) {
                Log::info("Video generation for operation {$operationName} is still in progress.");
                return null; // Still pending
            }

            if (isset($status['error'])) {
                Log::error("Video generation for operation {$operationName} failed.", ['error' => $status['error']]);
                return null;
            }
            
            Log::info("Video generation for operation {$operationName} completed successfully.");
            return $status;

        } catch (\Exception $e) {
            Log::error("Error checking video generation status for {$operationName}: " . $e->getMessage());
            return null;
        }
    }
}
