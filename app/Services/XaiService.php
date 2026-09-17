<?php

namespace App\Services;

use App\Models\AiCost;
use App\Support\BillingAlert;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * xAI's own API, rather than Grok resold through OpenRouter.
 *
 * Same models, one less party. OpenRouter was a convenient way to reach Grok
 * without a second vendor relationship, and it cost us an outage: its prepaid
 * balance ran dry, every image call returned 402, and because the fallback
 * path treats Grok as merely unavailable the load landed on Gemini at three
 * times the intended rate until that rate-limited too. A direct account is one
 * fewer balance to run dry and one fewer hop to debug.
 *
 * The wire format is OpenAI-compatible, so this deliberately mirrors
 * OpenRouterService method for method — the two are interchangeable at every
 * call site, which is what makes falling back between them possible.
 *
 * MODEL NAMES DIFFER FROM OPENROUTER'S. OpenRouter namespaces them
 * ("x-ai/grok-4-fast"); xAI does not ("grok-4-fast"). Every one is read from
 * config and env-overridable, because a name that is wrong here fails as a 404
 * at request time rather than as anything obvious.
 */
class XaiService
{
    private const BASE = 'https://api.x.ai/v1';

    /**
     * Marks the account unusable for a while after a hard refusal.
     *
     * Same reasoning as the OpenRouter breaker: a 402 means the balance is
     * empty and no number of retries adds credit, and hammering a 429 is how a
     * rate limit stays hit. Without it one exhausted account drove ~135 calls
     * in two minutes into the fallback provider.
     */
    private const UNAVAILABLE_KEY = 'xai:unavailable';

    private const UNAVAILABLE_MINUTES = 15;

    public function isConfigured(): bool
    {
        return filled(config('services.xai.api_key'));
    }

    public static function isUnavailable(): bool
    {
        return (bool) Cache::get(self::UNAVAILABLE_KEY, false);
    }

    /**
     * Generate text. Returns ['text' => string] to match GeminiService and
     * OpenRouterService, so callers need not know which answered.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     * @return array{text: string}|null
     */
    public function generateText(
        string $prompt,
        array $config = [],
        ?string $systemInstruction = null,
        array $context = [],
    ): ?array {
        if (! $this->isConfigured() || self::isUnavailable()) {
            return null;
        }

        $model = (string) config('ai.models.text_xai');

        $messages = [];

        if ($systemInstruction) {
            $messages[] = ['role' => 'system', 'content' => $systemInstruction];
        }

        $messages[] = ['role' => 'user', 'content' => $prompt];

        $payload = ['model' => $model, 'messages' => $messages];

        // Gemini takes responseMimeType; the OpenAI-compatible APIs take
        // response_format. The caller asked for JSON either way.
        if (($config['responseMimeType'] ?? null) === 'application/json') {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        foreach (['temperature' => 'temperature', 'topP' => 'top_p', 'maxOutputTokens' => 'max_tokens'] as $from => $to) {
            if (isset($config[$from])) {
                $payload[$to] = $config[$from];
            }
        }

        try {
            $started = hrtime(true);

            $response = Http::withToken(config('services.xai.api_key'))
                ->timeout(120)
                ->post(self::BASE.'/chat/completions', $payload);

            if ($response->failed()) {
                $this->noteFailure('text generation', $model, $response->status(), $response->body());

                return null;
            }

            $text = $response->json('choices.0.message.content');

            if (! is_string($text) || trim($text) === '') {
                Log::error('XaiService: text response carried no content', [
                    'model' => $model,
                    'finish_reason' => $response->json('choices.0.finish_reason'),
                ]);

                return null;
            }

            $this->recordTokenCost(
                $model,
                'generateText',
                $response->json('usage') ?? [],
                (int) ((hrtime(true) - $started) / 1e6),
                $context,
            );

            return ['text' => $text];
        } catch (\Throwable $e) {
            report($e);
            Log::error('XaiService: text generation exception: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Generate one image. Returns ['data' => base64, 'mimeType' => ...] to
     * match GeminiService::generateImage(), or null.
     *
     * @param  array<string, mixed>  $context
     * @return array{data: string, mimeType: string}|null
     */
    public function generateImage(string $prompt, array $context = [], string $size = '1024x1024'): ?array
    {
        if (! $this->isConfigured() || self::isUnavailable()) {
            return null;
        }

        $model = (string) config('ai.models.image_xai');

        try {
            $response = Http::withToken(config('services.xai.api_key'))
                ->timeout(300)
                ->post(self::BASE.'/images/generations', [
                    'model' => $model,
                    'prompt' => $prompt,
                    // Requested at the target aspect rather than always square:
                    // filling a 1200x628 slot from a square is a 48% distortion
                    // and the format is dropped instead.
                    'size' => $size,
                    'response_format' => 'b64_json',
                    'n' => 1,
                ]);

            if ($response->failed()) {
                $this->noteFailure('image generation', $model, $response->status(), $response->body());

                return null;
            }

            $b64 = $response->json('data.0.b64_json');

            if (! is_string($b64) || $b64 === '') {
                Log::error('XaiService: image response missing b64_json', ['model' => $model]);

                return null;
            }

            // Images are billed flat rather than by token, so the token table
            // would record every one at zero.
            $this->recordFlatCost($model, 'generateImage', (float) config('ai.openrouter_image_cost', 0.04), $context);

            return ['data' => $b64, 'mimeType' => 'image/jpeg'];
        } catch (\Throwable $e) {
            report($e);
            Log::error('XaiService: image generation exception: '.$e->getMessage());

            return null;
        }
    }

    /**
     * A refusal that will not change on retry marks the provider down for a while.
     *
     * 402 is an empty balance and 429 is a rate limit already being hit;
     * asking again immediately makes both worse and, because the fallback
     * treats "unavailable" as a reason to use the other provider, multiplies
     * the load onto it.
     */
    private function noteFailure(string $what, string $model, int $status, string $body): void
    {
        Log::error("XaiService: {$what} failed", [
            'model' => $model,
            'status' => $status,
            'body' => substr($body, 0, 300),
        ]);

        BillingAlert::check('xAI', $body, $status);

        if (in_array($status, [402, 429], true)) {
            Cache::put(self::UNAVAILABLE_KEY, true, now()->addMinutes(self::UNAVAILABLE_MINUTES));

            Log::warning('XaiService: marking provider unavailable', [
                'status' => $status,
                'minutes' => self::UNAVAILABLE_MINUTES,
            ]);
        }
    }

    /**
     * A flat per-unit charge, for the calls that are not billed by token.
     *
     * An image costs the same whatever the prompt length, so running it
     * through the token table prices every one at zero — and a provider whose
     * spend reads as zero looks free in exactly the view a person checks
     * before deciding what to use.
     *
     * @param  array<string, mixed>  $context
     */
    private function recordFlatCost(string $model, string $operation, float $cost, array $context): void
    {
        try {
            AiCost::create([
                'campaign_id' => $context['campaign_id'] ?? null,
                'customer_id' => $context['customer_id'] ?? null,
                'service' => 'xAI',
                'operation' => $operation,
                'model' => $model,
                'input_tokens' => 0,
                'output_tokens' => 0,
                'cost' => $cost,
                'duration_ms' => 0,
                'task_type' => $context['task_type'] ?? null,
            ]);
        } catch (\Throwable $e) {
            report($e);
            Log::error('XaiService: failed to record image cost: '.$e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $usage
     * @param  array<string, mixed>  $context
     */
    private function recordTokenCost(string $model, string $operation, array $usage, int $durationMs, array $context): void
    {
        $rates = config('ai.pricing.'.$model);

        if (! is_array($rates)) {
            // A model named in code but absent from the pricing table has its
            // spend recorded as zero, which is how a provider's cost silently
            // disappears from every per-customer view.
            Log::warning('XaiService: no pricing for model, recording zero cost', ['model' => $model]);
        }

        $inputTokens = (int) ($usage['prompt_tokens'] ?? 0);
        $outputTokens = (int) ($usage['completion_tokens'] ?? 0);

        $cost = (($inputTokens / 1_000_000) * (float) ($rates['input'] ?? 0))
            + (($outputTokens / 1_000_000) * (float) ($rates['output'] ?? 0));

        try {
            AiCost::create([
                'campaign_id' => $context['campaign_id'] ?? null,
                'customer_id' => $context['customer_id'] ?? null,
                'service' => 'xAI',
                'operation' => $operation,
                'model' => $model,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'cost' => $cost,
                'duration_ms' => $durationMs,
                'task_type' => $context['task_type'] ?? null,
            ]);
        } catch (\Throwable $e) {
            report($e);
            Log::error('XaiService: failed to record cost: '.$e->getMessage());
        }
    }
}
