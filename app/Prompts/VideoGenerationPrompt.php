<?php

namespace App\Prompts;

class VideoGenerationPrompt
{
    public static function create(string $topic): string
    {
        return "Create a short, engaging video about {$topic}. The video should be visually appealing and suitable for social media advertising.";
    }
}
