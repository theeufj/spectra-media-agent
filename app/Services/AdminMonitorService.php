<?php

namespace App\Services;

use App\Models\AdCopy;
use App\Prompts\AdCopyReviewPrompt;
use App\Services\Agents\AgentIssue;
use App\Services\GoogleAds\PerformanceMaxServices\PerformanceMaxCopy;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class AdminMonitorService
{
    private GeminiService $geminiService;

    public function __construct(GeminiService $geminiService)
    {
        $this->geminiService = $geminiService;
    }

    /**
     * Returns the validation rules for a specific platform.
     *
     * @param  string  $platform  The platform name.
     * @return array|null The rules array or null if not found.
     */
    public static function getRulesForPlatform(string $platform, ?string $campaignType = null): ?array
    {
        // Standardize the platform name to match the keys in the config file.
        $platformKey = strtolower($platform);
        $platformKey = str_replace(' ads (sem)', '.sem', $platformKey);
        $platformKey = str_replace(' advertising', '', $platformKey);
        $platformKey = str_replace(' ads', '', $platformKey);
        if (str_starts_with($platformKey, 'google') && ($campaignType === 'performance_max'
            || in_array($platformKey, ['google (performance max)', 'google performance max', 'google.performance_max'], true))) {
            $platformKey = 'google.performance_max';
        }

        Log::info("Standardized platform key: {$platformKey}");

        $platformRules = Config::get('platform_rules');

        // Ensure platformRules is an array before proceeding.
        if (! is_array($platformRules)) {
            return null;
        }

        // Check for an exact match first
        if (isset($platformRules[$platformKey])) {
            return $platformRules[$platformKey];
        }

        // If no exact match, check if the platform name starts with a configured key
        foreach ($platformRules as $key => $rules) {
            if (str_starts_with($platformKey, $key)) {
                return $rules;
            }
        }

        return null;
    }

    /**
     * Reviews generated ad copy against platform-specific requirements.
     *
     * @param  AdCopy  $adCopy  The AdCopy model instance to review.
     * @return array An array containing programmatic validation and Gemini's qualitative feedback.
     */
    public function reviewAdCopy(AdCopy $adCopy, bool $existingSearchAd = false, ?int $timeoutSeconds = null): array
    {
        $platform = $adCopy->platform;
        $headlines = $this->copyAssets($adCopy->headlines);
        $descriptions = $this->copyAssets($adCopy->descriptions);
        $strategy = $adCopy->strategy;
        $rules = self::getRulesForPlatform($platform, $existingSearchAd ? null : $strategy?->campaign_type);
        $performanceMax = ! $existingSearchAd && isset($rules['long_headline_count']);
        $longHeadlines = $performanceMax ? $this->copyAssets($adCopy->long_headlines) : [];

        Log::info("AdminMonitorService: Validating ad copy for platform {$platform}.", [
            'headlines' => $headlines,
            'descriptions' => $descriptions,
        ]);

        $validationResults = $this->validateAdCopy($platform, $rules, $headlines, $descriptions, $longHeadlines, $existingSearchAd);
        if ($performanceMax && ! $validationResults['is_valid']) {
            return ['programmatic_validation' => $validationResults, 'qualitative_review_status' => 'skipped',
                'gemini_feedback' => null, 'warnings' => [], 'overall_status' => 'needs_revision'];
        }

        $evidence = $strategy?->campaign
            ? app(\App\Services\Campaigns\AdvertisingEvidence::class)->context($strategy->campaign, $strategy) : [];
        $feeScope = app(AdCopyFeeScopeValidator::class)->validate($headlines, $descriptions, $evidence, $longHeadlines);
        $validationResults['fee_scope'] = $feeScope;
        if (! $feeScope['is_valid']) {
            $validationResults['is_valid'] = false;
            foreach ($feeScope['issues'] as $issue) {
                $validationResults['feedback']['general'][] = $issue['message'];
            }
        }

        // A release with a known placement or fee error already needs a customer edit.
        // The generation path still requests additional feedback for the next draft.
        if ($timeoutSeconds !== null && ! $validationResults['is_valid']) {
            return ['programmatic_validation' => $validationResults, 'qualitative_review_status' => 'skipped',
                'gemini_feedback' => null, 'warnings' => [], 'overall_status' => 'needs_revision'];
        }

        $geminiFeedback = null;
        $qualitativeStatus = 'unavailable';
        try {
            $headlinesString = implode("\n", $headlines);
            $descriptionsString = implode("\n", $descriptions);

            $reviewPrompt = (new AdCopyReviewPrompt($platform, $headlinesString, $descriptionsString, $evidence, $validationResults, implode("\n", $longHeadlines)))->getPrompt();

            $generatedResponse = $timeoutSeconds === null
                ? $this->geminiService->generateContent(config('ai.models.default'), $reviewPrompt)
                : $this->geminiService->generateContent(config('ai.models.default'), $reviewPrompt, maxRetries: 1,
                    context: ['text_timeout_seconds' => $timeoutSeconds, 'task_type' => 'creative_release_review']);

            if (is_null($generatedResponse)) {
                Log::error("AdminMonitorService: Failed to get ad copy review from Gemini for AdCopy ID {$adCopy->id}.");
            } else {
                try {
                    $generatedReview = $generatedResponse['text'] ?? null;
                    if (is_null($generatedReview)) {
                        throw new \Exception('No text field in Gemini response.');
                    }

                    // Clean the JSON string by removing markdown fences and trimming whitespace
                    $cleanedJson = preg_replace('/^```json\s*|\s*```$/', '', trim($generatedReview));
                    $geminiFeedback = json_decode($cleanedJson, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new \Exception('JSON decode error: '.json_last_error_msg());
                    }

                    if (! is_array($geminiFeedback) || ! isset($geminiFeedback['overall_score']) || ! isset($geminiFeedback['feedback'])) {
                        throw new \Exception('Gemini did not return a valid JSON object with overall_score and feedback.');
                    }
                    $qualitativeStatus = 'completed';
                } catch (\Throwable $e) {
                    report($e);
                    Log::error("AdminMonitorService: Failed to parse Gemini's ad copy review response for AdCopy ID {$adCopy->id}: ".$e->getMessage(), [
                        'generated_review' => $generatedReview,
                    ]);
                    $geminiFeedback = ['overall_score' => 0, 'feedback' => ['general' => ['Failed to parse Gemini\'s response.']]];
                }
            }
        } catch (\Throwable $e) {
            report($e);
            Log::error("AdminMonitorService: Exception during Gemini ad copy review for AdCopy ID {$adCopy->id}: ".$e->getMessage(), [
                'exception' => $e,
            ]);
            $geminiFeedback = ['overall_score' => 0, 'feedback' => ['general' => ['An unexpected error occurred during Gemini review.']]];
        }

        $warnings = [];
        if (is_array($geminiFeedback) && is_array($geminiFeedback['blocking_issues'] ?? null)) {
            $blockers = [];
            $falsePositives = [];
            foreach ($geminiFeedback['blocking_issues'] as $issue) {
                $verified = is_string($issue) ? $this->verifiedLengthFalsePositive($issue, $validationResults) : null;
                if ($verified === null) {
                    $blockers[] = $issue;

                    continue;
                }
                $falsePositives[] = $verified;
                $warnings[] = AgentIssue::make('model_false_positive',
                    "The model reported a length violation for \"{$verified['text']}\"; code counted {$verified['characters']} characters within the configured {$verified['max_characters']}-character limit.")->toArray();
            }
            if ($falsePositives !== []) {
                $geminiFeedback['reported_blocking_issues'] = $geminiFeedback['blocking_issues'];
                $geminiFeedback['verified_length_false_positives'] = $falsePositives;
                $geminiFeedback['blocking_issues'] = $blockers;
            }
        }

        // The generation loop may accept a lower style score, but must never bypass factual/intent failures.
        if (($evidence !== [] && (($geminiFeedback['factual_accuracy'] ?? false) !== true
            || ($geminiFeedback['intent_relevance'] ?? false) !== true)) || ! empty($geminiFeedback['blocking_issues'])) {
            $validationResults['is_valid'] = false;
            $validationResults['feedback']['general'][] = 'Campaign evidence/intent review did not pass.';
            foreach ((array) ($geminiFeedback['blocking_issues'] ?? []) as $issue) {
                if (is_string($issue)) {
                    $validationResults['feedback']['general'][] = $issue;
                }
            }
        }

        $finalReview = [
            'programmatic_validation' => $validationResults,
            'qualitative_review_status' => $qualitativeStatus,
            'gemini_feedback' => $geminiFeedback,
            'warnings' => $warnings,
            'overall_status' => ($validationResults['is_valid'] && ($geminiFeedback['overall_score'] ?? 0) > 75) ? 'approved' : 'needs_revision',
        ];

        Log::info("AdminMonitorService: Final review for AdCopy ID {$adCopy->id}.", $finalReview);

        return $finalReview;
    }

    /** Read current array casts and legacy copy stored as JSON text. */
    private function copyAssets(mixed $stored): array
    {
        $decoded = is_string($stored) ? json_decode($stored, true) : $stored;

        return is_array($decoded) ? $decoded : [];
    }

    /** Only a complete, length-only objection quoting an exact candidate can be disproved numerically. */
    private function verifiedLengthFalsePositive(string $issue, array $validation): ?array
    {
        foreach (['headlines' => 'Headline', 'long_headlines' => 'Long headline', 'descriptions' => 'Description'] as $field => $label) {
            $policy = $validation['numeric_policy'][$field] ?? null;
            if (! is_array($policy) || ! is_int($policy['min_characters'] ?? null) || ! is_int($policy['max_characters'] ?? null)) {
                continue;
            }
            foreach ($policy['assets'] ?? [] as $asset) {
                if (! is_array($asset) || ! is_string($asset['text'] ?? null) || ! mb_check_encoding($asset['text'], 'UTF-8')) {
                    continue;
                }
                $text = preg_quote($asset['text'], '~');
                $quoted = "(?:'{$text}'|\"{$text}\"|‘{$text}’|“{$text}”)";
                $pattern = '~\A(?i:'.$label.')(?:\s+\d+)?\s+'.$quoted.'\s+(?i:exceeds\s+(?:the\s+)?)(\d+)(?i:(?:-|\s+)character\s+limit)[.!]?\z~u';
                $length = $asset['characters'];
                if (preg_match($pattern, $issue, $match) === 1 && (int) $match[1] === $policy['max_characters']
                    && $length >= $policy['min_characters'] && $length <= $policy['max_characters']) {
                    return ['code' => 'model_false_positive', 'reason' => 'verified_character_count_within_platform_limits',
                        'reported_issue' => $issue, 'field' => $field, 'text' => $asset['text'], 'characters' => $length,
                        'min_characters' => $policy['min_characters'], 'max_characters' => $policy['max_characters']];
                }
            }
        }

        return null;
    }

    /**
     * Vocabulary belonging to a media plan or a design system rather than to a
     * picture. Every one of these has been observed rendered into artwork as
     * words, or as the fake interface it describes.
     */
    private const DESIGNER_BRIEF_WORDS = [
        'responsive display', 'performance max', 'mrec', 'image extension',
        'dashboard', 'infographic', 'screenshot', 'ui element', 'callout',
    ];

    /**
     * Reviews an image generation prompt for quality and safety.
     *
     * @param  string  $prompt  The image prompt to review.
     * @return array{is_valid: bool, feedback: list<string>, warnings: list<string>}
     */
    public function reviewImagePrompt(string $prompt): array
    {
        $isValid = true;
        $feedback = [];
        $trimmedPrompt = trim($prompt);

        // Rule 1: Check for empty or very short prompts
        if (strlen($trimmedPrompt) < 20) {
            $isValid = false;
            $feedback[] = 'The image prompt is too short. It must be at least 20 characters long to generate a meaningful image.';
        }

        // Rule 2: Check for non-descriptive or negative keywords
        //
        // The length escape hatch makes this reachable only for short prompts,
        // and GenerateImage already returns early on a short "N/A" strategy
        // before it ever calls this — so in practice Rule 2 has never rejected
        // a live prompt (real strategies run 290-470 characters). It is kept
        // for the short-prompt case rather than widened: every strategy
        // written before the imagery contract was fixed would trip a broader
        // rule, and is_valid false makes the job throw.
        $negativeKeywords = Config::get('platform_rules.negative_keywords', []);
        foreach ($negativeKeywords as $word) {
            if (stripos($trimmedPrompt, $word) !== false) {
                // Allow mixed content if the prompt is sufficiently long (e.g., "N/A for Search. Use X for Display...")
                if (strlen($trimmedPrompt) > 50) {
                    continue;
                }

                $isValid = false;
                $feedback[] = "The imagery strategy ('".substr($trimmedPrompt, 0, 50)."...') is not a valid image prompt as it contains instructional or negative keywords like '{$word}'. Please provide a descriptive prompt of the desired image.";
            }
        }

        // Rule 3: Check for forbidden keywords
        $forbiddenWords = Config::get('platform_rules.forbidden_keywords', []);
        foreach ($forbiddenWords as $word) {
            if (stripos($trimmedPrompt, $word) !== false) {
                $isValid = false;
                $feedback[] = "The prompt contains a forbidden keyword: '{$word}'. Please revise the imagery strategy.";
            }
        }

        /*
         * Rule 4: drift detection, reported rather than enforced.
         *
         * `imagery_strategy` is written by the strategy agent and handed
         * almost verbatim to an image model. When it arrives as a designer's
         * brief — hex codes, ad-format names, instructions to draw an
         * interface — what comes back has garbled labels and invented facts
         * set in type on the artwork.
         *
         * These are warnings and not validation failures deliberately.
         * is_valid false makes GenerateImage throw, and every strategy written
         * before the imagery contract was fixed contains all three of these;
         * enforcing here would convert a quality problem into a generation
         * outage for existing campaigns. The point is to see the drift on the
         * day it comes back, which nothing previously did.
         */
        $warnings = [];

        if (preg_match('/#[0-9A-Fa-f]{6}\b/', $trimmedPrompt, $hex)) {
            $warnings[] = "Contains the hex colour {$hex[0]}. An image model cannot sample a hex value, and may render the characters instead.";
        }

        foreach (self::DESIGNER_BRIEF_WORDS as $word) {
            if (stripos($trimmedPrompt, $word) !== false) {
                $warnings[] = "Reads as a designer's brief rather than a scene: '{$word}'.";
            }
        }

        if ($warnings !== []) {
            Log::warning('AdminMonitorService: imagery strategy reads as a design brief rather than a scene.', [
                'prompt' => $trimmedPrompt,
                'warnings' => $warnings,
            ]);
        }

        Log::info('Image prompt review completed.', [
            'prompt' => $prompt,
            'is_valid' => $isValid,
            'feedback' => $feedback,
            'warnings' => $warnings,
        ]);

        return [
            'is_valid' => $isValid,
            'feedback' => $feedback,
            'warnings' => $warnings,
        ];
    }

    /** Performs programmatic validation against platform-specific rules. */
    private function validateAdCopy(string $platform, ?array $rules, array $headlines, array $descriptions, array $longHeadlines, bool $existingSearchAd): array
    {
        $feedback = ['headlines' => [], 'descriptions' => [], 'general' => []];
        if ($rules === null) {
            $feedback['general'][] = "No specific rules defined for platform: {$platform}.";
            Log::warning("AdminMonitorService: No programmatic rules found for platform {$platform}.");

            return ['is_valid' => false, 'feedback' => $feedback];
        }

        $performanceMax = ! $existingSearchAd && isset($rules['long_headline_count']);
        $fields = [
            'headlines' => ['headline', 'Headline', $headlines, 3, 15],
            'descriptions' => ['description', 'Description', $descriptions, 2, 4],
        ];
        if ($performanceMax) {
            $fields['long_headlines'] = ['long_headline', 'Long headline', $longHeadlines, 1, 5];
            $feedback['long_headlines'] = [];
        }

        $numericPolicy = [];
        foreach ($fields as $field => [$rule, $label, $assets, $searchMin, $searchMax]) {
            $min = $rules[$rule.'_min_length'];
            $max = $rules[$rule.'_max_length'];
            $expectedCount = $rules[$rule.'_count'];
            $numericPolicy[$field] = ['min_characters' => $min, 'max_characters' => $max, 'assets' => []];
            $count = count($assets);
            if ($existingSearchAd ? $count < $searchMin || $count > $searchMax : $count !== $expectedCount) {
                $expected = $existingSearchAd ? "{$searchMin} to {$searchMax}" : $expectedCount;
                $feedback[$field][] = "Expected {$expected} ".str_replace('_', ' ', $field).", but got {$count}.";
            }
            if ($performanceMax && ! array_is_list($assets)) {
                $feedback[$field][] = "Return {$field} as a list of strings.";
            }

            foreach (array_values($assets) as $index => $asset) {
                $name = $label.' '.($index + 1);
                if (! is_string($asset)) {
                    $feedback[$field][] = "{$name} is not text.";

                    continue;
                }
                $length = $performanceMax ? PerformanceMaxCopy::characters($asset) : mb_strlen($asset);
                $numericPolicy[$field]['assets'][] = ['text' => $asset, 'characters' => $length];
                if ($length < $min || $length > $max) {
                    $feedback[$field][] = "{$name} (\"{$asset}\") is {$length} characters long. Expected between {$min} and {$max} characters.";
                }
                $maxExclamations = $rules['max_exclamations_per_element'] ?? null;
                $exclamations = substr_count($asset, '!');
                if ($maxExclamations !== null && $exclamations > $maxExclamations) {
                    $feedback[$field][] = "{$name} (\"{$asset}\") has {$exclamations} exclamation marks. Max allowed: {$maxExclamations}.";
                }
                if (! ($rules['allow_consecutive_exclamations'] ?? true) && str_contains($asset, '!!')) {
                    $feedback[$field][] = "{$name} (\"{$asset}\") contains consecutive exclamation marks, which is not allowed.";
                }
            }
        }

        if ($performanceMax) {
            foreach (PerformanceMaxCopy::coverageIssues($headlines, $longHeadlines, $descriptions) as $field => $issues) {
                $feedback[$field] = array_merge($feedback[$field], $issues);
            }
        }

        return ['is_valid' => array_filter($feedback) === [], 'feedback' => $feedback, 'numeric_policy' => $numericPolicy];
    }
}
