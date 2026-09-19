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
        if (! $this->brandGuidelines) {
            return '';
        }

        $brandVoice = $this->brandGuidelines->getFormattedBrandVoice();
        $personality = $this->brandGuidelines->brand_personality;
        $usps = $this->brandGuidelines->getFormattedUSPs();
        $themes = $this->brandGuidelines->messaging_themes ?? [];

        $context = "**BRAND VOICE & PERSONALITY:**\n".$brandVoice."\n";

        // Add personality archetype and characteristics
        if (isset($personality['archetype'])) {
            $context .= "**Brand Archetype:** {$personality['archetype']}\n";
        }
        if (isset($personality['characteristics'])) {
            $context .= '**Personality Traits:** '.implode(', ', $personality['characteristics'])."\n";
        }
        if (isset($personality['if_brand_were_person'])) {
            $context .= "**Brand Essence:** {$personality['if_brand_were_person']}\n";
        }

        $context .= "\n".$usps."\n\n";

        // Add messaging themes if available (simple array)
        if (! empty($themes)) {
            $context .= '**Key Messaging Themes:** '.implode(', ', $themes)."\n\n";
        }

        // Add do-not-use list
        if (! empty($this->brandGuidelines->do_not_use)) {
            $context .= '**DO NOT USE:** '.implode(', ', $this->brandGuidelines->do_not_use)."\n\n";
        }

        return $context;
    }

    public function getPrompt(): string
    {
        $brandContext = $this->formatBrandContext();

        if ($brandContext) {
            Log::info("VideoScriptPrompt: Using brand guidelines for customer ID: {$this->brandGuidelines->customer_id}");
        } else {
            Log::info('VideoScriptPrompt: No brand guidelines available - using generic approach');
        }

        $productContextString = '';
        if (! empty($this->productContext)) {
            $productContextString = "\n\n**PRODUCT DETAILS:**\n".
                "The video script MUST feature or relate to the following product(s):\n".
                json_encode($this->productContext, JSON_PRETTY_PRINT);
        }

        $variationInstruction = $this->getVariationInstruction();

        return <<<PROMPT
You are a direct-response advertising writer. Write a specific, natural spoken ad for this offer, not generic marketing narration.

{$brandContext}
{$productContextString}
Based on the following creative strategy, write a short, engaging voiceover script for a video no longer than 14 seconds.

{$variationInstruction}

**SCRIPT REQUIREMENTS:**
- **HARD WORD BUDGET: 35 words maximum.** The video canvas is 15 seconds and narration runs ~2.4 words per second — a 36th word gets cut off or rushed. Count your words before answering; when in doubt, cut. 25 punchy words beat 35 crowded ones.
- **Format:** Single paragraph of voiceover narration only
- **Tone:** {$this->getBrandTone()}
- **Structure:** Immediate hook and brand/offer identification → ONE concrete benefit or demonstration → ONE next action
- **Style:** Conversational, engaging, and impactful
- **No:** Scene directions, camera angles, timestamps, or any non-voiceover text

**VOICEOVER BEST PRACTICES:**
- Start with a specific observation, product action, useful contrast or desirable outcome.
  Do not default to a rhetorical question, "Struggling with...?" or "In today's world".
- Identify the supplied brand naturally in the opening sentence when its name is available;
  otherwise identify the actual offer. Never invent a brand name. Branding counts in the word budget.
- Focus on ONE supported reason to choose this offer. Use a concrete feature or mechanism
  from the strategy, brand or product details instead of "transform", "unlock" or "game-changing".
- Complement the visual action instead of describing every shot. No fabricated statistics,
  savings, reviews, testimonials, guarantees or urgency; use prices only when supplied.
- End with ONE specific, achievable next action. Do not invent a free trial, discount or demo.
- Keep the close in the same conversational voice as the opening. Read it aloud mentally:
  natural pauses must fit the 14-second narration window; fewer words are better than rushing.
- Before answering, remove interchangeable marketing filler and count the spoken words.

--- CREATIVE STRATEGY ---
{$this->strategy}

--- VOICEOVER SCRIPT ---
PROMPT;
    }

    private function getVariationInstruction(): string
    {
        if ($this->variationIndex === 0) {
            return "**CREATIVE ANGLE — VARIATION A:**\nFollow the lead concept in the strategy. Open on its product action, distinctive feature or concrete benefit, then explain why it matters. A frustration hook is appropriate only when the brief specifically depends on it; it is not the default. Close with one supported next step.";
        }

        return "**CREATIVE ANGLE — VARIATION B:**\nUse the Alternative angle from the strategy when supplied; otherwise lead with a concrete benefit in use. Change the selling emphasis and opening construction from the lead concept, not just the wording. Keep the benefit supported and the next action appropriate to the same offer; do not inflate an aspiration into a promised result.";
    }

    private function getBrandTone(): string
    {
        if (! $this->brandGuidelines) {
            return 'Engaging and professional';
        }

        // tone_attributes is a simple array of strings, not nested
        $tones = $this->brandGuidelines->tone_attributes ?? [];

        if (empty($tones)) {
            return 'Engaging and professional';
        }

        // Every attribute the extraction found. Brand voice is the thing the
        // script is supposed to sound like; keeping three of it and discarding
        // the rest narrows the voice for no reason — the list is a handful of
        // words, not a payload concern.
        return implode(', ', array_filter($tones));
    }
}
