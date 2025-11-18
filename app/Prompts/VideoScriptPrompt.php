<?php

namespace App\Prompts;

use App\Models\BrandGuideline;
use Illuminate\Support\Facades\Log;

class VideoScriptPrompt
{
    private string $strategy;
    private ?BrandGuideline $brandGuidelines;

    public function __construct(string $strategy, ?BrandGuideline $brandGuidelines = null)
    {
        $this->strategy = $strategy;
        $this->brandGuidelines = $brandGuidelines;
    }

    private function formatBrandContext(): string
    {
        if (!$this->brandGuidelines) {
            return '';
        }

        $brandVoice = $this->brandGuidelines->getFormattedBrandVoice();
        $personality = $this->brandGuidelines->brand_personality;
        $usps = $this->brandGuidelines->getFormattedUSPs();
        $themes = $this->brandGuidelines->messaging_themes;
        
        return "**BRAND VOICE & PERSONALITY:**\n" .
               $brandVoice . "\n" .
               "**Brand Personality:** " . implode(', ', $personality['traits'] ?? []) . "\n" .
               "**Communication Style:** {$personality['communication_style']}\n\n" .
               $usps . "\n\n" .
               "**Key Messaging Themes:** " . implode(', ', $themes['primary_themes'] ?? []) . "\n" .
               "**Emotional Appeal:** {$themes['emotional_appeal']}\n\n" .
               "**DO NOT USE:** " . implode(', ', $this->brandGuidelines->do_not_use ?? []) . "\n\n";
    }

    public function getPrompt(): string
    {
        $brandContext = $this->formatBrandContext();
        
        if ($brandContext) {
            Log::info("VideoScriptPrompt: Using brand guidelines for customer ID: {$this->brandGuidelines->customer_id}");
        } else {
            Log::info("VideoScriptPrompt: No brand guidelines available - using generic approach");
        }

        return <<<PROMPT
You are a creative and concise scriptwriter for short marketing videos.

{$brandContext}Based on the following creative strategy, write a short, engaging voiceover script for a video that is approximately 8-15 seconds long.

**SCRIPT REQUIREMENTS:**
- **Length:** 8-15 seconds of spoken content (approximately 20-40 words)
- **Format:** Single paragraph of voiceover narration only
- **Tone:** {$this->getBrandTone()}
- **Structure:** Hook (1-2 seconds) → Key Benefit (4-6 seconds) → Call to Action (2-3 seconds)
- **Style:** Conversational, engaging, and impactful
- **No:** Scene directions, camera angles, timestamps, or any non-voiceover text

**VOICEOVER BEST PRACTICES:**
- Start with an attention-grabbing hook or question
- Focus on ONE primary benefit or value proposition
- Use active, energetic language
- End with a clear, compelling call to action
- Ensure natural pacing and rhythm for spoken delivery
- Avoid jargon unless it's part of the brand voice

--- CREATIVE STRATEGY ---
{$this->strategy}

--- VOICEOVER SCRIPT ---
PROMPT;
    }

    private function getBrandTone(): string
    {
        if (!$this->brandGuidelines || !isset($this->brandGuidelines->tone_attributes['primary_tones'])) {
            return "Engaging and professional";
        }

        $tones = $this->brandGuidelines->tone_attributes['primary_tones'];
        return implode(', ', array_slice($tones, 0, 3));
    }
}

