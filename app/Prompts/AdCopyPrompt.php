<?php

namespace App\Prompts;

use Illuminate\Support\Facades\Log;

class AdCopyPrompt
{
    private string $strategyContent;
    private string $platform;
    private ?array $rules;
    private ?array $feedback;

    public function __construct(string $strategyContent, string $platform, ?array $rules = null, ?array $feedback = null)
    {
        $this->strategyContent = $strategyContent;
        $this->platform = $platform;
        $this->rules = $rules ?? [];
        $this->feedback = $feedback ?? [];
    }

    public function getPrompt(): string
    {
        $rulesString = !empty($this->rules) ? json_encode($this->rules, JSON_PRETTY_PRINT) : 'No specific rules provided.';

        $basePrompt = "You are an expert copywriter. Based on the following marketing strategy and platform rules for {$this->platform}, generate dynamic ad copy.\n\n" .
                      "--- PLATFORM RULES ---\n" .
                      $rulesString . "\n\n" .
                      "--- RESPONSE FORMAT ---\n" .
                      "Return the output as a JSON object with two keys: 'headlines' (an array of strings) and 'descriptions' (an array of strings). " .
                      "Do NOT include any conversational text, explanations, or additional formatting outside the JSON object. " .
                      "Example: {\"headlines\": [\"Headline 1\", \"Headline 2\"], \"descriptions\": [\"Description 1.\", \"Description 2.\"]}\n\n" .
                      "--- MARKETING STRATEGY ---\n{$this->strategyContent}";

        if (!empty($this->feedback)) {
            $feedbackString = json_encode($this->feedback, JSON_PRETTY_PRINT);
            $basePrompt .= "\n\n--- CRITICAL CORRECTIONS REQUIRED ---\n" .
                           "The previous ad copy you generated was REJECTED because it violated the platform's rules. You MUST fix the following errors:\n" .
                           $feedbackString . "\n\n" .
                           "Generate a completely new and valid set of ad copy that strictly adheres to all rules and corrects these specific errors.";
        }

        Log::info("Generated AdCopyPrompt.", ['prompt' => $basePrompt]);
        return $basePrompt;
    }
}