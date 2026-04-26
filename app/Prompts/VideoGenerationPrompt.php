<?php

namespace App\Prompts;

class VideoGenerationPrompt
{
    public static function create(string $topic): string
    {
        return "Create a short, engaging video about {$topic}. The video should be visually appealing and suitable for social media advertising. CRITICAL RULE: NEVER include any text, words, or typography overlaid in the video. The video must be purely visual/cinematic with absolutely NO text to prevent spelling mistakes.";
    }
}
