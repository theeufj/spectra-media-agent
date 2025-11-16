<?php

namespace App\Services;

use App\Models\AdCopy;
use App\Prompts\AdCopyReviewPrompt;
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
     * @param string $platform The platform name.
     * @return array|null The rules array or null if not found.
     */
    public static function getRulesForPlatform(string $platform): ?array
    {
        // Standardize the platform name to match the keys in the config file.
        $platformKey = strtolower($platform);
        $platformKey = str_replace(' ads (sem)', '.sem', $platformKey);
        $platformKey = str_replace(' advertising', '', $platformKey);
        $platformKey = str_replace(' ads', '', $platformKey);

        Log::info("Standardized platform key: {$platformKey}");

        $platformRules = Config::get('platform_rules');

        // Ensure platformRules is an array before proceeding.
        if (!is_array($platformRules)) {
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
     * @param AdCopy $adCopy The AdCopy model instance to review.
     * @return array An array containing programmatic validation and Gemini's qualitative feedback.
     */
    public function reviewAdCopy(AdCopy $adCopy): array
    {
        $platform = $adCopy->platform;
        $headlines = is_string($adCopy->headlines) ? json_decode($adCopy->headlines, true) : $adCopy->headlines;
        $descriptions = is_string($adCopy->descriptions) ? json_decode($adCopy->descriptions, true) : $adCopy->descriptions;

        Log::info("AdminMonitorService: Validating ad copy for platform {$platform}.", [
            'headlines' => $headlines,
            'descriptions' => $descriptions,
        ]);

        $validationResults = $this->validateAdCopy($platform, $headlines, $descriptions);

        // If programmatic validation fails critically, we might not even call Gemini.
        // For now, we'll always call Gemini but include programmatic results.
        $geminiFeedback = null;
        try {
            $headlinesString = implode("\n", $headlines);
            $descriptionsString = implode("\n", $descriptions);

            $reviewPrompt = (new AdCopyReviewPrompt($platform, $headlinesString, $descriptionsString))->getPrompt();

            $generatedResponse = $this->geminiService->generateContent('gemini-2.5-pro', $reviewPrompt);

            if (is_null($generatedResponse)) {
                Log::error("AdminMonitorService: Failed to get ad copy review from Gemini for AdCopy ID {$adCopy->id}.");
            } else {
                try {
                    $generatedReview = $generatedResponse['text'] ?? null;
                    if (is_null($generatedReview)) {
                        throw new \Exception("No text field in Gemini response.");
                    }
                    
                    // Clean the JSON string by removing markdown fences and trimming whitespace
                    $cleanedJson = preg_replace('/^```json\s*|\s*```$/', '', trim($generatedReview));
                    $geminiFeedback = json_decode($cleanedJson, true);

                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new \Exception("JSON decode error: " . json_last_error_msg());
                    }

                    if (!is_array($geminiFeedback) || !isset($geminiFeedback['overall_score']) || !isset($geminiFeedback['feedback'])) {
                        throw new \Exception("Gemini did not return a valid JSON object with overall_score and feedback.");
                    }
                } catch (\Exception $e) {
                    Log::error("AdminMonitorService: Failed to parse Gemini's ad copy review response for AdCopy ID {$adCopy->id}: " . $e->getMessage(), [
                        'generated_review' => $generatedReview,
                    ]);
                    $geminiFeedback = ['overall_score' => 0, 'feedback' => ['general' => ['Failed to parse Gemini\'s response.']]];
                }
            }
        } catch (\Exception $e) {
            Log::error("AdminMonitorService: Exception during Gemini ad copy review for AdCopy ID {$adCopy->id}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            $geminiFeedback = ['overall_score' => 0, 'feedback' => ['general' => ['An unexpected error occurred during Gemini review.']]];
        }

        $finalReview = [
            'programmatic_validation' => $validationResults,
            'gemini_feedback' => $geminiFeedback,
            'overall_status' => ($validationResults['is_valid'] && ($geminiFeedback['overall_score'] ?? 0) > 50) ? 'approved' : 'needs_revision',
        ];

        Log::info("AdminMonitorService: Final review for AdCopy ID {$adCopy->id}.", $finalReview);

        return $finalReview;
    }

    /**
     * Reviews an image generation prompt for quality and safety.
     *
     * @param string $prompt The image prompt to review.
     * @return array An array containing the validation results.
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
        $negativeKeywords = Config::get('platform_rules.negative_keywords', []);
        foreach ($negativeKeywords as $word) {
            if (stripos($trimmedPrompt, $word) !== false) {
                $isValid = false;
                $feedback[] = "The imagery strategy ('" . substr($trimmedPrompt, 0, 50) . "...') is not a valid image prompt as it contains instructional or negative keywords like '{$word}'. Please provide a descriptive prompt of the desired image.";
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

        // Add more sophisticated checks here in the future (e.g., using a safety-check API)

        Log::info('Image prompt review completed.', [
            'prompt' => $prompt,
            'is_valid' => $isValid,
            'feedback' => $feedback,
        ]);

        return [
            'is_valid' => $isValid,
            'feedback' => $feedback,
        ];
    }

    /**
     * Performs programmatic validation of ad copy against platform-specific rules.
     *
     * @param string $platform The platform for which the ad copy is intended.
     * @param array $headlines An array of headlines.
     * @param array $descriptions An array of descriptions.
     * @return array An array containing validation results.
     */
    private function validateAdCopy(string $platform, ?array $headlines, ?array $descriptions): array
    {
        // Ensure headlines and descriptions are arrays to prevent errors.
        $headlines = $headlines ?? [];
        $descriptions = $descriptions ?? [];

        $isValid = true;
        $feedback = [
            'headlines' => [],
            'descriptions' => [],
            'general' => [],
        ];

        $platformRules = self::getRulesForPlatform($platform);

        if (is_null($platformRules)) {
            $isValid = false;
            $feedback['general'][] = "No specific rules defined for platform: {$platform}.";
            Log::warning("AdminMonitorService: No programmatic rules found for platform {$platform}.");
            return ['is_valid' => $isValid, 'feedback' => $feedback];
        }

        // Validate Headlines
        if (count($headlines) !== $platformRules['headline_count']) {
            $isValid = false;
            $feedback['headlines'][] = "Expected {$platformRules['headline_count']} headlines, but got " . count($headlines) . ".";
        }
        foreach ($headlines as $index => $headline) {
            $length = mb_strlen($headline);
            if ($length < $platformRules['headline_min_length'] || $length > $platformRules['headline_max_length']) {
                $isValid = false;
                $feedback['headlines'][] = "Headline " . ($index + 1) . " (\"{$headline}\") is {$length} characters long. Expected between {$platformRules['headline_min_length']} and {$platformRules['headline_max_length']} characters.";
            }
            // Check for excessive exclamation marks
            if (isset($platformRules['max_exclamations_per_element'])) {
                $exclamationCount = substr_count($headline, '!');
                if ($exclamationCount > $platformRules['max_exclamations_per_element']) {
                    $isValid = false;
                    $feedback['headlines'][] = "Headline " . ($index + 1) . " (\"{$headline}\") has {$exclamationCount} exclamation marks. Max allowed: {$platformRules['max_exclamations_per_element']}.";
                }
            }
            if (isset($platformRules['allow_consecutive_exclamations']) && !$platformRules['allow_consecutive_exclamations']) {
                if (strpos($headline, '!!') !== false) {
                    $isValid = false;
                    $feedback['headlines'][] = "Headline " . ($index + 1) . " (\"{$headline}\") contains consecutive exclamation marks, which is not allowed.";
                }
            }
            // Add more formatting checks here (e.g., no special characters, capitalization rules)
        }

        // Validate Descriptions
        if (count($descriptions) !== $platformRules['description_count']) {
            $isValid = false;
            $feedback['descriptions'][] = "Expected {$platformRules['description_count']} descriptions, but got " . count($descriptions) . ".";
        }
        foreach ($descriptions as $index => $description) {
            $length = mb_strlen($description);
            if ($length < $platformRules['description_min_length'] || $length > $platformRules['description_max_length']) {
                $isValid = false;
                $feedback['descriptions'][] = "Description " . ($index + 1) . " (\"{$description}\") is {$length} characters long. Expected between {$platformRules['description_min_length']} and {$platformRules['description_max_length']} characters.";
            }
            // Check for excessive exclamation marks
            if (isset($platformRules['max_exclamations_per_element'])) {
                $exclamationCount = substr_count($description, '!');
                if ($exclamationCount > $platformRules['max_exclamations_per_element']) {
                    $isValid = false;
                    $feedback['descriptions'][] = "Description " . ($index + 1) . " (\"{$description}\") has {$exclamationCount} exclamation marks. Max allowed: {$platformRules['max_exclamations_per_element']}.";
                }
            }
            if (isset($platformRules['allow_consecutive_exclamations']) && !$platformRules['allow_consecutive_exclamations']) {
                if (strpos($description, '!!') !== false) {
                    $isValid = false;
                    $feedback['descriptions'][] = "Description " . ($index + 1) . " (\"{$description}\") contains consecutive exclamation marks, which is not allowed.";
                }
            }
        }

        return ['is_valid' => $isValid, 'feedback' => $feedback];
    }
}

