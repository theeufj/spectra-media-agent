<?php

namespace App\Jobs;

use App\Models\VideoCollateral;
use App\Services\GeminiService;
use App\Services\StorageHelper;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class CheckVideoStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 10; // Poll for a while
    public $timeout = 900;

    public function __construct(protected VideoCollateral $videoCollateral)
    {
    }

    public function handle(GeminiService $geminiService): void
    {
        Log::info("--- CheckVideoStatus Job Started ---");
        Log::info("Attempt #{$this->attempts()} for VideoCollateral ID: {$this->videoCollateral->id}");

        try {
            Log::info("Calling GeminiService to check status for operation: {$this->videoCollateral->operation_name}");
            $operation = $geminiService->checkVideoGenerationStatus($this->videoCollateral->operation_name);

            if (!$operation) {
                Log::info("Polling... Video is not ready yet. Re-dispatching job with a 60-second delay.");
                $this->release(60);
                return;
            }

            Log::info("Received a response from Gemini. Processing operation result.");
            Log::info("Full Gemini Response: " . json_encode($operation, JSON_PRETTY_PRINT));

            if (isset($operation['error'])) {
                $errorMessage = json_encode($operation['error']);
                Log::error("Video generation failed according to Gemini. Error: {$errorMessage}");
                $this->videoCollateral->update(['status' => 'failed']);
                throw new \Exception("Video generation failed: {$errorMessage}");
            }

            if (!isset($operation['response']['generateVideoResponse']['generatedSamples'][0]['video']['uri'])) {
                Log::error("Invalid response from Gemini: Video URI is missing.");
                $this->videoCollateral->update(['status' => 'failed']);
                throw new \Exception('Invalid response from Gemini: Video URI is missing.');
            }

            $videoUri = $operation['response']['generateVideoResponse']['generatedSamples'][0]['video']['uri'];
            Log::info("Video is ready. Downloading from Gemini URI: {$videoUri}");

            $videoData = $geminiService->downloadVideo($videoUri);

            if ($videoData === false) {
                Log::error("Failed to download video data from the provided Gemini URI.");
                $this->videoCollateral->update(['status' => 'failed']);
                throw new \Exception('Failed to download video from Gemini URI.');
            }

            Log::info("Video data downloaded successfully. Preparing to upload.");

            $filename = uniqid('vid_', true) . '.mp4';
            $storagePath = "collateral/videos/{$this->videoCollateral->campaign_id}/{$filename}";

            [$s3Path, $cloudFrontUrl] = StorageHelper::put($storagePath, $videoData, 'video/mp4');

            Log::info("Video uploaded at path: {$s3Path}, URL: {$cloudFrontUrl}");

            Log::info("Updating VideoCollateral record in the database with final status and URLs.");
            $this->videoCollateral->update([
                'status' => 'completed',
                's3_path' => $s3Path,
                'cloudfront_url' => $cloudFrontUrl,
                'gemini_video_uri' => $videoUri,
            ]);

            Log::info("--- CheckVideoStatus Job Completed Successfully for VideoCollateral ID: {$this->videoCollateral->id} ---");

        } catch (\Throwable $e) {
            Log::error("An error occurred in CheckVideoStatus for VideoCollateral ID {$this->videoCollateral->id}. Error: " . $e->getMessage());
            $this->videoCollateral->update(['status' => 'failed']);
            $this->fail($e);
            Log::info("--- CheckVideoStatus Job Failed for VideoCollateral ID: {$this->videoCollateral->id} ---");
        }
    }
}
