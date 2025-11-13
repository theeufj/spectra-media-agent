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
        Log::info("Checking video status for VideoCollateral ID: {$this->videoCollateral->id}");

        try {
            $operation = $geminiService->checkVideoGenerationStatus($this->videoCollateral->operation_name);

            if (!$operation) {
                // Not ready yet, re-dispatch with a delay
                Log::info("Video not ready. Re-dispatching check for VideoCollateral ID: {$this->videoCollateral->id}");
                $this->release(60); // Release back to the queue with a 60-second delay
                return;
            }

            if (isset($operation['error'])) {
                $this->videoCollateral->update(['status' => 'failed']);
                throw new \Exception('Video generation failed: ' . json_encode($operation['error']));
            }

            $videoUri = $operation['response']['videos'][0]['uri'];
            $videoData = file_get_contents($videoUri);

            if ($videoData === false) {
                $this->videoCollateral->update(['status' => 'failed']);
                throw new \Exception('Failed to download video from Gemini URI.');
            }

            $filename = uniqid('vid_', true) . '.mp4';
            $s3Path = "collateral/videos/{$this->videoCollateral->campaign_id}/{$filename}";
            Storage::disk('s3')->put($s3Path, $videoData, 'public');

            $cloudfrontDomain = config('filesystems.cloudfront_domain');
            $cloudFrontUrl = "https://{$cloudfrontDomain}/{$s3Path}";

            $this->videoCollateral->update([
                'status' => 'completed',
                's3_path' => $s3Path,
                'cloudfront_url' => $cloudFrontUrl,
            ]);

            Log::info("Successfully processed and stored video for VideoCollateral ID: {$this->videoCollateral->id}");

        } catch (\Exception $e) {
            $this->videoCollateral->update(['status' => 'failed']);
            Log::error("Error in CheckVideoStatus job for VideoCollateral ID {$this->videoCollateral->id}: " . $e->getMessage());
            $this->fail($e);
        }
    }
}
