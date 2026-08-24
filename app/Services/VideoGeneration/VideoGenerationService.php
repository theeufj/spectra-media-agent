<?php

namespace App\Services\VideoGeneration;

use App\Services\GeminiService;
use App\Services\ViduService;
use Illuminate\Support\Facades\Log;

class VideoGenerationService
{
    public function __construct(
        private GeminiService $geminiService,
        private ViduService $viduService,
        private \App\Services\OpenRouterService $openRouter,
    ) {}

    /**
     * Start video generation, falling back to Vidu if Veo is unavailable.
     *
     * Returns ['provider' => 'veo'|'vidu', 'operation_name' => string]
     * or null if both providers fail.
     *
     * @param  array  $parameters  Passed through to the provider (e.g. ['aspectRatio' => '9:16'])
     * @param  string|null  $voiceoverScript  When provided, Vidu will structure its prompt around
     *                                        narrating this script rather than using the generic wrapper.
     */
    public function startGeneration(string $topic, array $parameters = [], ?string $model = null, ?string $voiceoverScript = null): ?array
    {
        // The caller (GenerateVideo) already builds a complete prompt via
        // VideoFromScriptPrompt. This used to be re-wrapped in a generic
        // "Create a short, engaging video about {topic}" sentence — nesting a
        // multi-paragraph brief inside a one-liner and duplicating its rules.
        $prompt = $topic;

        // ── Primary: Grok via OpenRouter (default after the 2026-08-24
        //    shootout: single-pass native audio = one narrator throughout,
        //    where Veo needed an extension chain plus a TTS re-voice) ───────
        if (config('ai.video_provider', 'grok') === 'grok' && $this->openRouter->isConfigured()) {
            // One pass, capped at Grok's 15s — duration sized to the script.
            $seconds = $voiceoverScript
                ? (int) min(15, max(6, ceil(str_word_count($voiceoverScript) / 2.4)))
                : 8;

            $grokParams = [];
            if (($parameters['aspectRatio'] ?? null) === '9:16') {
                $grokParams['aspect_ratio'] = '9:16';
            }

            $jobId = $this->openRouter->startVideoGeneration($prompt, $seconds, $grokParams);

            if ($jobId) {
                Log::info("VideoGenerationService: Started via OpenRouter/Grok. Job: {$jobId}");

                return ['provider' => 'openrouter', 'operation_name' => $jobId];
            }

            Log::warning('VideoGenerationService: Grok start failed — falling back to Veo.');
        }

        // ── Fallback: Veo ───────────────────────────────────────────────────
        $operationName = $this->geminiService->startVideoGeneration(
            $prompt,
            $model ?? config('ai.models.video'),
            $parameters
        );

        if ($operationName) {
            Log::info("VideoGenerationService: Started via Veo. Operation: {$operationName}");

            return ['provider' => 'veo', 'operation_name' => $operationName];
        }

        // ── Fallback: Vidu ──────────────────────────────────────────────────
        if (! config('services.vidu.api_key')) {
            Log::warning('VideoGenerationService: Veo failed and VIDU_API_KEY is not set — no fallback available.');

            return null;
        }

        Log::warning('VideoGenerationService: Veo failed, falling back to Vidu.');

        // Vidu's audio AI works best with a concise, narration-focused prompt rather than the
        // generic Veo wrapper. When a voiceover script is available, build a Vidu-specific prompt
        // that explicitly instructs the model to narrate it — avoiding the conflicting "NO TEXT"
        // instruction from VideoFromScriptPrompt that can suppress speech generation.
        $viduPrompt = $voiceoverScript
            ? $this->buildViduNarrationPrompt($voiceoverScript, $topic)
            : $prompt;

        $taskId = $this->viduService->generateVideo($viduPrompt, $parameters);

        if ($taskId) {
            Log::info("VideoGenerationService: Started via Vidu. Task ID: {$taskId}");

            return ['provider' => 'vidu', 'operation_name' => $taskId];
        }

        Log::error('VideoGenerationService: Both Veo and Vidu failed to start video generation.');

        return null;
    }

    /**
     * Build a Vidu-optimised prompt where audio narration of the script is the primary directive.
     * Vidu's viduq3-pro audio layer responds to explicit voiceover instructions better than
     * the generic Veo-style visual prompt.
     */
    private function buildViduNarrationPrompt(string $script, string $visualContext): string
    {
        // Extract a brief visual summary from the visual context (first 200 chars of strategy)
        $briefVisual = mb_substr(strip_tags(trim($visualContext)), 0, 200);
        if (strlen($visualContext) > 200) {
            $briefVisual = rtrim($briefVisual, ' .,').'.';
        }

        return <<<PROMPT
Professional advertising video. The narrator speaks this voiceover script in English:

"{$script}"

Visual setting: {$briefVisual}

Requirements: No on-screen text or captions. The narration above must be spoken clearly as English voiceover audio accompanying the visuals.
PROMPT;
    }

    /**
     * Check the status of a Veo long-running operation.
     * Only used for Veo — Vidu polling is handled directly in CheckVideoStatus.
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

        } catch (\Exception $e) {
            Log::error("VideoGenerationService: Error checking status for {$operationName}: ".$e->getMessage());

            return null;
        }
    }
}
