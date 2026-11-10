<?php

namespace App\Services\VideoGeneration;

use App\Services\GeminiService;
use App\Services\OpenRouterService;
use App\Services\XaiService;
use Illuminate\Support\Facades\Log;

class VideoGenerationService
{
    public function __construct(
        private GeminiService $geminiService,
        private OpenRouterService $openRouter,
        private XaiService $xai,
    ) {}

    /**
     * Start video generation, falling back to Veo if Grok is unavailable.
     *
     * @param  array  $parameters  Passed through to the provider (e.g. ['aspectRatio' => '9:16'])
     * @param  string|null  $voiceoverScript  The narration, used to size the clip duration.
     * @param  callable|null  $promptForProvider  Receives the provider about to run and returns the prompt for it. The
     *                                            provider is only known here, after a fallback has
     *                                            been taken — a caller that picks its prompt from
     *                                            config instead hands Veo a script sized for Grok.
     * @param  array  $context  Cost attribution (campaign_id, customer_id) for the provider's AiCost row.
     * @return array{provider: 'xai'|'openrouter'|'veo', operation_name: string}|null
     */
    public function startGeneration(
        string $topic,
        array $parameters = [],
        ?string $model = null,
        ?string $voiceoverScript = null,
        ?callable $promptForProvider = null,
        array $context = [],
        bool $forPerformanceMax = false,
    ): ?array {
        // The caller (GenerateVideo) already builds a complete prompt via
        // VideoFromScriptPrompt. This used to be re-wrapped in a generic
        // "Create a short, engaging video about {topic}" sentence — nesting a
        // multi-paragraph brief inside a one-liner and duplicating its rules.
        $prompt = $topic;

        // Each provider narrates a different amount of script in one pass, so
        // the prompt cannot be settled until we know which one actually ran.
        $promptFor = fn (string $provider): string => $promptForProvider
            ? $promptForProvider($provider)
            : $prompt;

        $provider = config('ai.video_provider');
        // Grok generates the full script with native audio in one pass, up to 15 seconds.
        $seconds = $voiceoverScript
            ? (int) min(15, max(6, ceil(str_word_count($voiceoverScript) / 2.4)))
            : 8;
        $aspectRatio = ($parameters['aspectRatio'] ?? null) === '9:16' ? '9:16' : '16:9';

        if ($provider === 'xai' && $this->xai->isConfigured() && ! XaiService::isUnavailable()) {
            $jobId = $this->xai->startVideoGeneration(
                $promptFor('xai'),
                $forPerformanceMax ? max(10, $seconds) : $seconds,
                $aspectRatio,
                $context,
            );

            if ($jobId) {
                Log::info("VideoGenerationService: Started via xAI. Job: {$jobId}");

                return ['provider' => 'xai', 'operation_name' => $jobId];
            }

            Log::warning('VideoGenerationService: Direct xAI video start failed. Trying configured fallbacks.');
        }

        if (in_array($provider, ['grok', 'xai'], true) && $this->openRouter->isConfigured()) {
            $jobId = $this->openRouter->startVideoGeneration(
                $promptFor('openrouter'),
                $seconds,
                ['aspect_ratio' => $aspectRatio],
                $context,
            );

            if ($jobId) {
                Log::info("VideoGenerationService: Started via OpenRouter/Grok. Job: {$jobId}");

                return ['provider' => 'openrouter', 'operation_name' => $jobId];
            }

            Log::warning('VideoGenerationService: Grok start failed — falling back to Veo.');
        }

        // ── Fallback: Veo ───────────────────────────────────────────────────
        $operationName = $this->geminiService->startVideoGeneration(
            $promptFor('veo'),
            $model ?? config('ai.models.video'),
            $parameters,
            $context,
        );

        if ($operationName) {
            Log::info("VideoGenerationService: Started via Veo. Operation: {$operationName}");

            return ['provider' => 'veo', 'operation_name' => $operationName];
        }

        Log::error('VideoGenerationService: no provider could start video generation.');

        return null;
    }

    /**
     * Check the status of a Veo long-running operation.
     * Grok jobs are polled directly in CheckVideoStatus.
     */
    public function checkGenerationStatus(string $operationName): ?array
    {
        try {
            $status = $this->geminiService->checkVideoGenerationStatus($operationName);

            if (is_null($status)) {
                Log::info("VideoGenerationService: Operation {$operationName} still in progress.");

                return null;
            }

            if (isset($status['error'])) {
                Log::error("VideoGenerationService: Operation {$operationName} failed.", ['error' => $status['error']]);

                return null;
            }

            Log::info("VideoGenerationService: Operation {$operationName} completed successfully.");

            return $status;

        } catch (\Throwable $e) {
            Log::error("VideoGenerationService: Error checking status for {$operationName}: ".$e->getMessage());

            return null;
        }
    }
}
