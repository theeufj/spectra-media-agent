<?php

namespace App\Prompts;

class AdCopyReviewPrompt
{
    private string $platform;
    private string $headlines;
    private string $descriptions;

    public function __construct(string $platform, string $headlines, string $descriptions)
    {
        $this->platform = $platform;
        $this->headlines = $headlines;
        $this->descriptions = $descriptions;
    }

    public function getPrompt(): string
    {
        return "Review the following ad copy for {$this->platform} based on typical platform requirements (e.g., character limits, clarity, call to action). " .
               "Provide an overall score (0-100) and specific feedback for headlines and descriptions. " .
               "Return ONLY a JSON object with two keys: 'overall_score' (integer) and 'feedback' (an object with 'headlines' and 'descriptions' keys, each containing an array of strings for feedback points). " .
               "Do NOT include any conversational text, explanations, or additional formatting outside the JSON object. " .
               "Example: {\"overall_score\": 85, \"feedback\": {\"headlines\": [\"Headline 1 is too long.\", \"Headline 3 is clear.\"], \"descriptions\": [\"Description 1 needs a stronger call to action.\"]}}\n\n" .
               "Platform: {$this->platform}\n" .
               "Headlines:\n{$this->headlines}\n\n" .
               "Descriptions:\n{$this->descriptions}";
    }
}