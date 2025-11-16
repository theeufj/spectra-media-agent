<?php

namespace App\Services\Validation;

use App\Services\GeminiService;
use Illuminate\Support\Facades\Log;

class BrandSafetyValidationService
{
    private $geminiService;

    public function __construct(GeminiService $geminiService)
    {
        $this->geminiService = $geminiService;
    }

    public function __invoke(string $adCopy): bool
    {
        try {
            $prompt = "You are a brand safety expert. Analyze the following ad copy to determine if it violates any common brand safety guidelines (e.g., hate speech, violence, adult content, etc.). Respond with 'true' if the ad copy is safe, and 'false' if it is not.\n\nAd Copy: \"{$adCopy}\"";

            $response = $this->geminiService->generateContent('gemini-2.5-pro', $prompt);

            if (is_null($response) || !isset($response['text'])) {
                Log::error("Brand safety validation failed: LLM response was null or missing.");
                return false; // Fail safe
            }

            return strtolower(trim($response['text'])) === 'true';
        } catch (\Exception $e) {
            Log::error("Error during brand safety validation: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return false; // Fail safe
        }
    }
}
