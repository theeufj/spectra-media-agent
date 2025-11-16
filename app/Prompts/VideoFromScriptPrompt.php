<?php

namespace App\Prompts;

class VideoFromScriptPrompt
{
    private string $strategy;
    private string $script;

    public function __construct(string $strategy, string $script)
    {
        $this->strategy = $strategy;
        $this->script = $script;
    }

    public function getPrompt(): string
    {
        return <<<PROMPT
Creative Strategy: {$this->strategy}

--- VOICEVOVER SCRIPT ---
{$this->script}

--- INSTRUCTIONS ---
Generate a video where the scenes and pacing are directly inspired by and aligned with the provided voiceover script. The script should dictate the narrative flow of the video.
PROMPT;
    }
}
