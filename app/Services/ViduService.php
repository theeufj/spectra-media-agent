<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ViduService
{
    private string $apiKey;
    private string $baseUrl = 'https://api.vidu.com/ent/v2';
    private string $model   = 'viduq3-pro';

    public function __construct()
    {
        $this->apiKey = config('services.vidu.api_key');
    }

    /**
     * Start a text-to-video generation task.
     * Returns the task ID on success, null on failure.
     *
     * @param array $parameters Accepts 'aspectRatio' => '16:9'|'9:16' (Veo convention, mapped internally)
     */
    public function generateVideo(string $prompt, array $parameters = []): ?string
    {
        // Map Veo aspect ratio convention to Vidu's format
        $veoRatio  = $parameters['aspectRatio'] ?? '16:9';
        $aspectRatio = match ($veoRatio) {
            '9:16'  => '9:16',
            default => '16:9',
        };

        $body = [
            'model'              => $this->model,
            'prompt'             => $prompt,
            'duration'           => 15,
            'aspect_ratio'       => $aspectRatio,
            'resolution'         => '720p',
            'movement_amplitude' => 'auto',
            'audio'              => true,  // q3 models only — generates narration/sound from prompt
        ];

        try {
            $response = Http::withHeaders([
                'Authorization' => "Token {$this->apiKey}",
                'Content-Type'  => 'application/json',
            ])->timeout(60)->post("{$this->baseUrl}/text2video", $body);

            if ($response->failed()) {
                Log::error("ViduService: Failed to start video generation", [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return null;
            }

            $taskId = $response->json('task_id');
            Log::info("ViduService: Video generation started. Task ID: {$taskId}");
            return $taskId;

        } catch (\Exception $e) {
            Log::error("ViduService: Exception during video generation: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Poll a task for completion.
     *
     * @return array|null null = still running, ['videoUrl' => string] = done
     * @throws \Exception on terminal failure
     */
    public function getTaskStatus(string $taskId): ?array
    {
        $response = Http::withHeaders([
            'Authorization' => "Token {$this->apiKey}",
            'Content-Type'  => 'application/json',
        ])->timeout(30)->get("{$this->baseUrl}/tasks/{$taskId}/creations");

        if ($response->failed()) {
            Log::error("ViduService: Failed to check task status for {$taskId}", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \Exception("ViduService: HTTP {$response->status()} checking task {$taskId}.");
        }

        $data   = $response->json();
        $state  = $data['state'] ?? 'unknown';

        Log::info("ViduService: Task {$taskId} state: {$state}");

        if (in_array($state, ['created', 'queueing', 'processing'])) {
            return null;
        }

        if ($state === 'success') {
            $videoUrl = $data['creations'][0]['url'] ?? null;
            if (!$videoUrl) {
                throw new \Exception("ViduService: Task {$taskId} succeeded but output URL is missing.");
            }
            return ['videoUrl' => $videoUrl];
        }

        $errCode = $data['err_code'] ?? 'unknown';
        throw new \Exception("ViduService: Task {$taskId} failed with state '{$state}', err_code: {$errCode}.");
    }

    /**
     * Download video bytes from a Vidu output URL.
     * Vidu output URLs are public for 24 hours — no auth header needed.
     */
    public function downloadVideo(string $url): string|false
    {
        try {
            $response = Http::timeout(120)->get($url);

            if ($response->failed()) {
                Log::error("ViduService: Failed to download video", [
                    'status' => $response->status(),
                    'url'    => $url,
                ]);
                return false;
            }

            return $response->body();

        } catch (\Exception $e) {
            Log::error("ViduService: Exception downloading video: " . $e->getMessage());
            return false;
        }
    }
}
