<?php

namespace App\Prompts;

use App\Models\BrandGuideline;
use Illuminate\Support\Facades\Log;

class VideoScriptPrompt
{
    private string $strategy;
    private ?BrandGuideline $brandGuidelines;
    private ?array $productContext;
    private int $variationIndex;

    public function __construct(string $strategy, ?BrandGuideline $brandGuidelines = null, ?array $productContext = null, int $variationIndex = 0)
    {
        $this->strategy = $strategy;
        $this->brandGuidelines = $brandGuidelines;
        $this->productContext = $productContext;
        $this->variationIndex = $variationIndex;
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

        $productContextString = '';
        if (!empty($this->productContext)) {
            $productContextString = "\n\n**PRODUCT DETAILS:**\n" .
                "The video script MUST feature or relate to the following product(s):\n" .
                json_encode($this->productContext, JSON_PRETTY_PRINT);
        }

        $variationInstruction = $this->getVariationInstruction();

        return <<<PROMPT
You are a creative and concise scriptwriter for short marketing videos.

{$brandContext}
{$productContextString}
Based on the following creative strategy, write a short, engaging voiceover script for a video that is approximately 8-15 seconds long.

{$variationInstruction}

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

    private function getVariationInstruction(): string
    {
        if ($this->variationIndex === 0) {
            return "**CREATIVE ANGLE — VARIATION A:**\nLead with the core problem the customer faces. Open with a pain point or frustration hook, then present the product as the direct solution. End with a benefit-driven call to action.";
        }

        return "**CREATIVE ANGLE — VARIATION B:**\nThis must be DISTINCTLY DIFFERENT from any other script written for this strategy. Lead with aspiration or a bold outcome — skip the problem framing entirely. Open with what life looks/feels like AFTER using the product. Use a different hook style, different sentence rhythm, and a different call to action from Variation A.";
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

