<?php

namespace App\Prompts;

class ImagePrompt
{
    private string $strategyContent;

    public function __construct(string $strategyContent)
    {
        $this->strategyContent = $strategyContent;
    }

    public function getPrompt(): string
    {
        return "Generate a high-quality, visually compelling image for a marketing campaign based on the following creative strategy. The image must directly reflect the concepts described in the strategy.\n\n" .
               "--- CREATIVE STRATEGY ---\n" .
               $this->strategyContent;
    }
}
