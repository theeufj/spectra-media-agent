<?php

namespace App\Services;

use App\Models\AiCost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenRouter provider for creative generation — currently xAI's Grok Imagine
 * models, selected as the default after a side-by-side shootout against
 * Gemini (2026-08-24): stronger image composition at ~half the price, and
 * video with native single-pass audio (one narrator by construction, where
 * Veo's per-call narrator forced a TTS re-voice step).
 */
class OpenRouterService
{
    private const BASE = 'https://openrouter.ai/api/v1';

    public function isConfigured(): bool
    {
        return filled(config('services.openrouter.api_key'));
    }

    /**
     * Generate one image. Returns ['data' => base64, 'mimeType' => ...] to
     * match GeminiService::generateImage(), or null.
     */
    public function generateImage(string $prompt, array $context = []): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $model = config('ai.models.image_grok', 'x-ai/grok-imagine-image-2.0');

        try {
            $response = Http::withToken(config('services.openrouter.api_key'))
                ->timeout(300)
                ->post(self::BASE.'/images/generations', [
                    'model' => $model,
                    'prompt' => $prompt,
                    'size' => '1024x1024',
                ]);

            if ($response->failed()) {
                Log::error('OpenRouterService: image generation failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 300),
                ]);

                return null;
            }

            $b64 = $response->json('data.0.b64_json');
            if (! $b64) {
                Log::error('OpenRouterService: image response missing b64_json');

                return null;
            }

            $this->recordCost($model, 'generateImage', (float) config('ai.openrouter_image_cost', 0.04), $context);

            // Grok returns JPEG bytes.
            return ['data' => $b64, 'mimeType' => 'image/jpeg'];
        } catch (\Throwable $e) {
            Log::error('OpenRouterService: image generation exception: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Start an async video generation. Returns the job id or null.
     */
    public function startVideoGeneration(string $prompt, int $durationSeconds, array $parameters = [], array $context = []): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $model = config('ai.models.video_grok', 'x-ai/grok-imagine-video-1.5');
        $duration = max(1, min(15, $durationSeconds));

        try {
            $response = Http::withToken(config('services.openrouter.api_key'))
                ->timeout(120)
                ->post(self::BASE.'/videos', array_merge([
                    'model' => $model,
                    'prompt' => $prompt,
                    'duration' => $duration,
                    'resolution' => '720p',
                    'aspect_ratio' => '16:9',
                    'generate_audio' => true,
                ], $parameters));

            if ($response->failed()) {
                Log::error('OpenRouterService: video start failed', [
                    'status' => $response->status(),
                    'body' => substr($response->body(), 0, 300),
                ]);

                return null;
            }

            $jobId = $response->json('id');
            if ($jobId) {
                $perSecond = (float) config("ai.video_cost_per_second.{$model}", 0.15);
                $this->recordCost($model, 'startVideoGeneration', round($duration * $perSecond, 6), array_merge($context, [
                    'duration_seconds' => $duration,
                ]));
            }

            return $jobId;
        } catch (\Throwable $e) {
            Log::error('OpenRouterService: video start exception: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Poll a video job. Returns:
     *  - null while still generating
     *  - ['error' => ...] on failure
     *  - ['bytes' => rawVideo] when complete
     */
    public function checkVideoStatus(string $jobId): ?array
    {
        try {
            $response = Http::withToken(config('services.openrouter.api_key'))
                ->timeout(120)
                ->get(self::BASE."/videos/{$jobId}");

            if ($response->failed()) {
                return ['error' => 'status check failed: '.$response->status()];
            }

            $status = $response->json('status');

            if (in_array($status, ['pending', 'processing', 'running', 'queued'], true)) {
                return null;
            }

            if ($status !== 'completed') {
                return ['error' => 'job status: '.($status ?? 'unknown')];
            }

            $content = Http::withToken(config('services.openrouter.api_key'))
                ->timeout(300)
                ->get(self::BASE."/videos/{$jobId}/content", ['index' => 0]);

            if ($content->failed() || $content->body() === '') {
                return ['error' => 'content download failed: '.$content->status()];
            }

            return ['bytes' => $content->body()];
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        }
    }

    /**
     * Current credit balance, cached briefly. Null when unavailable — never
     * let a billing-status call break anything.
     *
     * @return array{total: float, used: float, remaining: float}|null
     */
    public function creditBalance(): ?array
    {
        if (! $this->isConfigured()) {
            return null;
        }

        try {
            return \Illuminate\Support\Facades\Cache::remember('openrouter_credits', 300, function () {
                $response = Http::withToken(config('services.openrouter.api_key'))
                    ->timeout(15)
                    ->get(self::BASE.'/credits');

                if ($response->failed()) {
                    return null;
                }

                $total = (float) $response->json('data.total_credits');
                $used = (float) $response->json('data.total_usage');

                return [
                    'total' => $total,
                    'used' => round($used, 2),
                    'remaining' => round($total - $used, 2),
                ];
            });
        } catch (\Throwable $e) {
            Log::warning('OpenRouterService: credit check failed: '.$e->getMessage());

            return null;
        }
    }

    private function recordCost(string $model, string $operation, float $cost, array $context): void
    {
        try {
            AiCost::create([
                'campaign_id' => $context['campaign_id'] ?? null,
                'customer_id' => $context['customer_id'] ?? null,
                'service' => 'OpenRouter',
                'operation' => $operation,
                'model' => $model,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'cached_tokens' => 0,
                'cost' => $cost,
                'duration_ms' => 0,
                'task_type' => $context['task_type'] ?? null,
                'metadata' => array_filter([
                    'duration_seconds' => $context['duration_seconds'] ?? null,
                ]),
            ]);
        } catch (\Throwable $e) {
            Log::warning('OpenRouterService: failed to record cost: '.$e->getMessage());
        }
    }
}
