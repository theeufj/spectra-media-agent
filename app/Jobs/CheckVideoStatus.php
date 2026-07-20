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

        $response = $operation['response'] ?? [];

        // Veo 3.1 returns the video as inline base64 (response.videos[].bytesBase64Encoded);
        // older/other configs return a download URI. Handle both.
        $inlineBytes = $response['videos'][0]['bytesBase64Encoded']
            ?? $response['generateVideoResponse']['generatedSamples'][0]['video']['bytesBase64Encoded']
            ?? null;

        if ($inlineBytes) {
            $videoData = base64_decode($inlineBytes, true);
            if ($videoData === false || $videoData === '') {
                $this->videoCollateral->update(['status' => 'failed']);
                throw new \Exception('Veo inline video bytes could not be decoded.');
            }
            Log::info('CheckVideoStatus: Veo video ready (inline bytes).');
            $this->storeAndComplete($videoData, ['gemini_video_inline' => true]);
            return;
        }

        $videoUri = $response['generateVideoResponse']['generatedSamples'][0]['video']['uri']
            ?? $response['videos'][0]['uri']
            ?? null;
        if (!$videoUri) {
            $this->videoCollateral->update(['status' => 'failed']);
            throw new \Exception('Veo response is missing both inline bytes and a video URI.');
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
            $videoCount = $campaign->videoCollaterals()->where('status', 'completed')->count();
            foreach ($campaign->customer->users as $user) {
                Mail::to($user->email)->send(new VideosGenerated($user, $campaign, $videoCount));
            }

            // For a live PMax campaign, link the finished video to its asset group so it
            // lifts ad strength. Previously only the creation flow did this, so healed /
            // regenerated videos never attached. UploadPMaxVideoAssets self-resolves the
            // asset group when none is passed.
            if ($campaign->google_ads_campaign_id) {
                $strategyId = $this->videoCollateral->strategy_id ?? $campaign->strategies()->latest()->value('id');
                if ($strategyId) {
                    \App\Jobs\UploadPMaxVideoAssets::dispatch($strategyId, $campaign->customer->cleanGoogleCustomerId())
                        ->delay(now()->addSeconds(30));
                }
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
