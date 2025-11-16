<?php

namespace App\Services\Testing;

use App\Models\PromptVersion;
use App\Services\GeminiService;

class PromptTestingService
{
    private $geminiService;

    public function __construct(GeminiService $geminiService)
    {
        $this->geminiService = $geminiService;
    }

    public function __invoke(string $promptName, array $promptInputs): array
    {
        $promptVersions = PromptVersion::where('prompt_name', $promptName)->get();
        $results = [];

        foreach ($promptVersions as $promptVersion) {
            $prompt = $this->hydratePrompt($promptVersion->prompt_text, $promptInputs);
            $response = $this->geminiService->generateContent('gemini-2.5-pro', $prompt);
            $results[$promptVersion->version_number] = $response;
        }

        return $results;
    }

    private function hydratePrompt(string $promptText, array $promptInputs): string
    {
        foreach ($promptInputs as $key => $value) {
            $promptText = str_replace("{{{$key}}}", $value, $promptText);
        }
        return $promptText;
    }
}
