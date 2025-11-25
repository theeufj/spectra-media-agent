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

--- VOICEOVER SCRIPT ---
{$this->script}

--- VIDEO GENERATION REQUIREMENTS ---

1. VISUAL STORYTELLING:
   - Create compelling visual scenes that directly match and enhance the voiceover narrative
   - Use dynamic camera movements and transitions to maintain viewer engagement
   - Ensure scenes flow naturally and align with the pacing of the script
   - Include high-quality, professional imagery appropriate for advertising

2. NO TEXT RENDERING:
   - Do NOT generate, display, or render any text, words, letters, numbers, or written content
   - No text overlays, captions, subtitles, titles, labels, or on-screen text of any kind
   - Focus entirely on visual imagery, scenes, motion, and cinematography
   - Text rendering in AI-generated videos often contains errors - avoid completely

3. STYLE & TONE:
   - Match the emotional tone and energy level suggested by the script
   - Use color grading and lighting that supports the brand message
   - Maintain professional, polished production quality throughout
   - Create visually engaging content that captures attention immediately

4. TECHNICAL SPECIFICATIONS:
   - Optimize for the target platform and ad format specified in the creative strategy
   - Ensure smooth transitions between scenes
   - Maintain consistent visual quality throughout the entire video
PROMPT;
    }
}
