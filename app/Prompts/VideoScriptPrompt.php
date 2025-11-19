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
        $themes = $this->brandGuidelines->messaging_themes ?? [];
        
        $context = "**BRAND VOICE & PERSONALITY:**\n" . $brandVoice . "\n";
        
        // Add personality archetype and characteristics
        if (isset($personality['archetype'])) {
            $context .= "**Brand Archetype:** {$personality['archetype']}\n";
        }
        if (isset($personality['characteristics'])) {
            $context .= "**Personality Traits:** " . implode(', ', $personality['characteristics']) . "\n";
        }
        if (isset($personality['if_brand_were_person'])) {
            $context .= "**Brand Essence:** {$personality['if_brand_were_person']}\n";
        }
        
        $context .= "\n" . $usps . "\n\n";
        
        // Add messaging themes if available (simple array)
        if (!empty($themes)) {
            $context .= "**Key Messaging Themes:** " . implode(', ', $themes) . "\n\n";
        }
        
        // Add do-not-use list
        if (!empty($this->brandGuidelines->do_not_use)) {
            $context .= "**DO NOT USE:** " . implode(', ', $this->brandGuidelines->do_not_use) . "\n\n";
        }
        
        return $context;
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
        if (!$this->brandGuidelines) {
            return "Engaging and professional";
        }

        // tone_attributes is a simple array of strings, not nested
        $tones = $this->brandGuidelines->tone_attributes ?? [];
        
        if (empty($tones)) {
            return "Engaging and professional";
        }
        
        return implode(', ', array_slice($tones, 0, 3));
    }
}

