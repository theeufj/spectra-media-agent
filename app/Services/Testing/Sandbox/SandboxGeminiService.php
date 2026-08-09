<?php

namespace App\Services\Testing\Sandbox;

use App\Services\GeminiService;
use Illuminate\Support\Facades\Log;

/**
 * Deterministic stand-in for GeminiService in sandbox runs.
 *
 * The reason is reproducibility, not cost. A sandbox that makes real model calls
 * returns something different on every run, so you cannot tell a behaviour
 * change from a different sampling — which defeats the point of having a
 * sandbox. Not paying for inference on fabricated campaigns is a bonus.
 *
 * Extends GeminiService rather than implementing an interface because the
 * agents type-hint the concrete class. The parent constructor is deliberately
 * not called: it reads Google Cloud project config that a sandbox has no need
 * of, and would fail where that config is absent.
 *
 * Responses are shaped exactly as the real service returns them — an array with
 * a 'text' key — because agents check `isset($response['text'])` and then parse
 * JSON out of it. A stub that returned something simpler would let an agent's
 * parsing bug pass unnoticed, which is the failure mode this whole exercise
 * exists to remove.
 */
class SandboxGeminiService extends GeminiService
{
    /** @var list<array{model: string, prompt: string}> */
    private array $calls = [];

    public function __construct()
    {
        // Intentionally does not call parent::__construct().
    }

    public function generateContent(
        string $model,
        string $prompt,
        array $config = [],
        ?string $systemInstruction = null,
        bool $enableThinking = false,
        bool $enableGoogleSearch = false,
        ?int $maxRetries = null,
        ?string $imageBase64 = null,
        string $imageMimeType = 'image/jpeg',
        array $context = []
    ): ?array {
        $this->calls[] = ['model' => $model, 'prompt' => $prompt];

        Log::info('SandboxGeminiService: returned canned response (no model call, no cost)', [
            'model' => $model,
        ]);

        return [
            'text' => $this->cannedResponse($prompt),
            'model' => $model,
            'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
            'cost' => 0.0,
            'sandbox' => true,
        ];
    }

    /** @return list<array{model: string, prompt: string}> */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * A plausible, well-formed answer for the prompt being asked.
     *
     * Keyed off what the prompt is for, so each caller gets JSON matching the
     * schema it will try to parse. Deterministic — the same prompt always
     * produces the same answer.
     */
    private function cannedResponse(string $prompt): string
    {
        $p = strtolower($prompt);

        if (str_contains($p, 'recommend') || str_contains($p, 'optimi')) {
            return json_encode([
                'recommendations' => [
                    [
                        'type' => 'budget',
                        'action' => 'increase_budget',
                        'reasoning' => 'Sandbox: campaign is converting below target CPA with budget-limited delivery.',
                        'confidence' => 0.8,
                        'expected_impact' => 'Moderate increase in conversion volume.',
                        'priority' => 'high',
                    ],
                    [
                        'type' => 'targeting',
                        'action' => 'add_negative_keywords',
                        'reasoning' => 'Sandbox: a portion of spend is going to non-commercial queries.',
                        'confidence' => 0.6,
                        'expected_impact' => 'Reduced wasted spend.',
                        'priority' => 'medium',
                    ],
                ],
            ], JSON_THROW_ON_ERROR);
        }

        if (str_contains($p, 'health') || str_contains($p, 'summary')) {
            return json_encode([
                'summary' => 'Sandbox: account is operating within expected parameters.',
                'risk_level' => 'low',
                'recommendations' => ['Sandbox: no action required.'],
            ], JSON_THROW_ON_ERROR);
        }

        return json_encode([
            'summary' => 'Sandbox: deterministic placeholder response.',
        ], JSON_THROW_ON_ERROR);
    }
}
