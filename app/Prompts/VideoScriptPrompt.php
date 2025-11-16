<?php

namespace App\Prompts;

class VideoScriptPrompt
{
    private string $strategy;

    public function __construct(string $strategy)
    {
        $this->strategy = $strategy;
    }

    public function getPrompt(): string
    {
        return <<<PROMPT
You are a creative and concise scriptwriter for short marketing videos.
Based on the following creative strategy, write a short, engaging voiceover script for a video that is approximately 8-15 seconds long.

The script should be a single paragraph. Do not include scene directions, camera angles, or any text other than the voiceover script itself.

--- CREATIVE STRATEGY ---
{$this->strategy}

--- SCRIPT ---
PROMPT;
    }
}
