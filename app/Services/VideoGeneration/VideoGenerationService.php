<?php

namespace App\Services\VideoGeneration;

use App\Services\GeminiService;
use Illuminate\Support\Facades\Log;

class VideoGenerationService
{
    public function __construct(
        private GeminiService $geminiService,
        private \App\Services\OpenRouterService $openRouter,
    ) {}

    /**
     * Start video generation, falling back to Veo if Grok is unavailable.
     *
     * Returns ['provider' => 'openrouter'|'veo', 'operation_name' => string]
     * or null if both providers fail.
     *
     * @param  array  $parameters  Passed through to the provider (e.g. ['aspectRatio' => '9:16'])
     * @param  string|null  $voiceoverScript  The narration, used to size the clip duration.
     * @param  callable|null  $promptForProvider  Receives the provider about to run ('openrouter'
     *                                            or 'veo') and returns the prompt for it. The
     *                                            provider is only known here, after a fallback has
     *                                            been taken — a caller that picks its prompt from
     *                                            config instead hands Veo a script sized for Grok.
     * @param  array  $context  Cost attribution (campaign_id, customer_id) for the provider's AiCost row.
     */
    public function startGeneration(
        string $topic,
        array $parameters = [],
        ?string $model = null,
        ?string $voiceoverScript = null,
        ?callable $promptForProvider = null,
        array $context = [],
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

        // ── Primary: Grok via OpenRouter (default after the 2026-08-24
        //    shootout: single-pass native audio = one narrator throughout,
        //    where Veo needed an extension chain plus a TTS re-voice) ───────
        if (config('ai.video_provider') === 'grok' && $this->openRouter->isConfigured()) {
            // One pass, capped at Grok's 15s — duration sized to the script.
            $seconds = $voiceoverScript
                ? (int) min(15, max(6, ceil(str_word_count($voiceoverScript) / 2.4)))
                : 8;

            $grokParams = [];
            if (($parameters['aspectRatio'] ?? null) === '9:16') {
                $grokParams['aspect_ratio'] = '9:16';
            }

            $jobId = $this->openRouter->startVideoGeneration(
                $promptFor('openrouter'),
                $seconds,
                $grokParams,
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

        Log::error('VideoGenerationService: both Grok and Veo failed to start video generation.');

        return null;
    }

    /**
     * Check the status of a Veo long-running operation.
     * OpenRouter jobs are polled directly in CheckVideoStatus.
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
