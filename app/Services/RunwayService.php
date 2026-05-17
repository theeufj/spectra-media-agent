<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RunwayService
{
    private string $apiKey;
    private string $baseUrl = 'https://api.runwayml.com/v1';
    private string $apiVersion = '2024-11-06';
    private string $model = 'gen4_turbo';

    public function __construct()
    {
        $this->apiKey = config('services.runway.api_key');
    }

    /**
     * Start a text-to-video generation task.
     * Returns the task ID on success, null on failure.
     *
     * @param array $parameters Accepts 'aspectRatio' => '16:9'|'9:16' (Veo convention, mapped internally)
     */
    public function generateVideo(string $prompt, array $parameters = []): ?string
    {
        // Map Veo's aspectRatio convention to Runway's ratio format
        $aspectRatio = $parameters['aspectRatio'] ?? '16:9';
        $ratio = $aspectRatio === '9:16' ? '720:1280' : '1280:720';

        $body = [
            'model'      => $this->model,
            'promptText' => $prompt,
            'duration'   => 5,
            'ratio'      => $ratio,
        ];

        try {
            $response = Http::withHeaders([
                'Authorization'    => "Bearer {$this->apiKey}",
                'X-Runway-Version' => $this->apiVersion,
                'Content-Type'     => 'application/json',
            ])->timeout(60)->post("{$this->baseUrl}/text_to_video", $body);

            if ($response->failed()) {
                Log::error("RunwayService: Failed to start video generation", [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return null;
            }

            $taskId = $response->json('id');
            Log::info("RunwayService: Video generation started. Task ID: {$taskId}");
            return $taskId;

        } catch (\Exception $e) {
            Log::error("RunwayService: Exception during video generation: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Poll a task for completion.
     *
     * @return array|null null = still running, ['videoUrl' => string] = done
     * @throws \Exception on terminal failure (FAILED, CANCELLED)
     */
    public function getTaskStatus(string $taskId): ?array
    {
        $response = Http::withHeaders([
            'Authorization'    => "Bearer {$this->apiKey}",
            'X-Runway-Version' => $this->apiVersion,
        ])->timeout(30)->get("{$this->baseUrl}/tasks/{$taskId}");

        if ($response->failed()) {
            Log::error("RunwayService: Failed to check task status for {$taskId}", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            throw new \Exception("RunwayService: HTTP {$response->status()} checking task {$taskId}.");
        }

        $data   = $response->json();
        $status = $data['status'] ?? 'UNKNOWN';

        Log::info("RunwayService: Task {$taskId} status: {$status}");

        if (in_array($status, ['PENDING', 'THROTTLED', 'RUNNING'])) {
            return null;
        }

        if ($status === 'SUCCEEDED') {
            $videoUrl = $data['output'][0] ?? null;
            if (!$videoUrl) {
                throw new \Exception("RunwayService: Task {$taskId} SUCCEEDED but output URL is missing.");
            }
            return ['videoUrl' => $videoUrl];
        }

        $failure = json_encode($data['failure'] ?? $data['failureCode'] ?? 'unknown');
        throw new \Exception("RunwayService: Task {$taskId} ended with status {$status}: {$failure}");
    }

    /**
     * Download video bytes from a Runway output URL.
     * Runway output URLs are public — no auth header needed.
     */
    public function downloadVideo(string $url): string|false
    {
        try {
            $response = Http::timeout(120)->get($url);

            if ($response->failed()) {
                Log::error("RunwayService: Failed to download video from URL", [
                    'status' => $response->status(),
                    'url'    => $url,
                ]);
                return false;
            }

            return $response->body();

        } catch (\Exception $e) {
            Log::error("RunwayService: Exception downloading video: " . $e->getMessage());
            return false;
        }
    }
}
