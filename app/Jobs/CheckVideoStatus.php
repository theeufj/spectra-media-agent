<?php

namespace App\Jobs;

use App\Mail\VideosGenerated;
use App\Models\VideoCollateral;
use App\Services\GeminiService;
use App\Services\StorageHelper;
use App\Services\ViduService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class CheckVideoStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 60; // Poll for up to 60 minutes (60 × 60s releases)
    public $timeout = 900;

    public function __construct(protected VideoCollateral $videoCollateral)
    {
    }

    public function handle(GeminiService $geminiService, ViduService $viduService): void
    {
        Log::info("--- CheckVideoStatus Job Started ---");
        Log::info("Attempt #{$this->attempts()} for VideoCollateral ID: {$this->videoCollateral->id}", [
            'provider' => $this->videoCollateral->provider ?? 'veo',
        ]);

        try {
            $provider = $this->videoCollateral->provider ?? 'veo';

            if ($provider === 'vidu') {
                $this->handleVidu($viduService);
            } else {
                $this->handleVeo($geminiService);
            }
        } catch (\Throwable $e) {
            Log::error("CheckVideoStatus error for VideoCollateral ID {$this->videoCollateral->id}: " . $e->getMessage());
            $this->videoCollateral->update(['status' => 'failed']);
            $this->fail($e);
        }
    }

    // ─── Veo (Gemini) ────────────────────────────────────────────────────────

    private function handleVeo(GeminiService $geminiService): void
    {
        Log::info("CheckVideoStatus: Polling Veo operation: {$this->videoCollateral->operation_name}");

        $operation = $geminiService->checkVideoGenerationStatus($this->videoCollateral->operation_name);

        if (!$operation) {
            Log::info("CheckVideoStatus: Veo not ready yet — releasing with 60s delay.");
            $this->release(60);
            return;
        }

        Log::info("CheckVideoStatus: Veo response received.", ['operation' => $operation]);

        if (isset($operation['error'])) {
            $this->videoCollateral->update(['status' => 'failed']);
            throw new \Exception("Veo generation failed: " . json_encode($operation['error']));
        }

        $videoUri = $operation['response']['generateVideoResponse']['generatedSamples'][0]['video']['uri'] ?? null;
        if (!$videoUri) {
            $this->videoCollateral->update(['status' => 'failed']);
            throw new \Exception('Veo response is missing video URI.');
        }

        Log::info("CheckVideoStatus: Veo video ready. Downloading from: {$videoUri}");
        $videoData = $geminiService->downloadVideo($videoUri);

        if ($videoData === false) {
            $this->videoCollateral->update(['status' => 'failed']);
            throw new \Exception('Failed to download video from Veo URI.');
        }

        $this->storeAndComplete($videoData, ['gemini_video_uri' => $videoUri]);
    }

    // ─── Vidu ────────────────────────────────────────────────────────────────

    private function handleVidu(ViduService $viduService): void
    {
        Log::info("CheckVideoStatus: Polling Vidu task: {$this->videoCollateral->operation_name}");

        $result = $viduService->getTaskStatus($this->videoCollateral->operation_name);

        if ($result === null) {
            Log::info("CheckVideoStatus: Vidu not ready yet — releasing with 60s delay.");
            $this->release(60);
            return;
        }

        $videoUrl = $result['videoUrl'];
        Log::info("CheckVideoStatus: Vidu video ready. Downloading from: {$videoUrl}");

        $videoData = $viduService->downloadVideo($videoUrl);

        if ($videoData === false) {
            $this->videoCollateral->update(['status' => 'failed']);
            throw new \Exception('Failed to download video from Vidu URL.');
        }

        // gemini_video_uri intentionally not set — Vidu videos cannot be extended via Veo
        $this->storeAndComplete($videoData, []);
    }

    // ─── Shared: upload + notify ─────────────────────────────────────────────

    private function storeAndComplete(string $videoData, array $extraFields): void
    {
        $filename    = uniqid('vid_', true) . '.mp4';
        $storagePath = "collateral/videos/{$this->videoCollateral->campaign_id}/{$filename}";

        [$s3Path, $cloudFrontUrl] = StorageHelper::put($storagePath, $videoData, 'video/mp4');

        Log::info("CheckVideoStatus: Video uploaded.", ['s3_path' => $s3Path, 'url' => $cloudFrontUrl]);

        $this->videoCollateral->update(array_merge([
            'status'         => 'completed',
            's3_path'        => $s3Path,
            'cloudfront_url' => $cloudFrontUrl,
        ], $extraFields));

        $campaign = $this->videoCollateral->campaign;
        if ($campaign && $campaign->customer) {
            foreach ($campaign->customer->users as $user) {
                Mail::to($user->email)->send(new VideosGenerated($user, $campaign));
            }
        }

        Log::info("--- CheckVideoStatus Completed for VideoCollateral ID: {$this->videoCollateral->id} ---");
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('CheckVideoStatus failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
    }
}
