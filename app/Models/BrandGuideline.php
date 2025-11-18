<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandGuideline extends Model
{
    protected $fillable = [
        'customer_id',
        'brand_voice',
        'tone_attributes',
        'writing_patterns',
        'color_palette',
        'typography',
        'visual_style',
        'messaging_themes',
        'unique_selling_propositions',
        'target_audience',
        'competitor_differentiation',
        'brand_personality',
        'do_not_use',
        'extraction_quality_score',
        'extracted_at',
        'last_verified_at',
    ];

    protected $casts = [
        'brand_voice' => 'array',
        'tone_attributes' => 'array',
        'writing_patterns' => 'array',
        'color_palette' => 'array',
        'typography' => 'array',
        'visual_style' => 'array',
        'messaging_themes' => 'array',
        'unique_selling_propositions' => 'array',
        'target_audience' => 'array',
        'competitor_differentiation' => 'array',
        'brand_personality' => 'array',
        'do_not_use' => 'array',
        'extracted_at' => 'datetime',
        'last_verified_at' => 'datetime',
    ];

    /**
     * Get the customer that owns the brand guidelines
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * Get formatted brand voice for use in prompts
     */
    public function getFormattedBrandVoice(): string
    {
        $voice = $this->brand_voice;
        $output = "**Primary Tone:** {$voice['primary_tone']}\n";
        $output .= "**Description:** {$voice['description']}\n";
        $output .= "**Key Attributes:** " . implode(', ', $this->tone_attributes) . "\n";
        
        if (!empty($voice['examples'])) {
            $output .= "**Examples from their content:**\n";
            foreach ($voice['examples'] as $example) {
                $output .= "- \"{$example}\"\n";
            }
        }
        
        return $output;
    }

    /**
     * Get formatted color palette for prompts
     */
    public function getFormattedColorPalette(): string
    {
        $palette = $this->color_palette;
        $output = "**Primary Colors:** " . implode(', ', $palette['primary_colors']) . "\n";
        $output .= "**Secondary Colors:** " . implode(', ', $palette['secondary_colors'] ?? []) . "\n";
        $output .= "**Usage:** {$palette['description']}\n";
        
        return $output;
    }

    /**
     * Get formatted USPs for prompts
     */
    public function getFormattedUSPs(): string
    {
        return implode("\n", array_map(
            fn($usp, $index) => ($index + 1) . ". {$usp}",
            $this->unique_selling_propositions,
            array_keys($this->unique_selling_propositions)
        ));
    }

    /**
     * Get formatted target audience for prompts
     */
    public function getFormattedTargetAudience(): string
    {
        $audience = $this->target_audience;
        $output = "**Primary Audience:** {$audience['primary']}\n";
        $output .= "**Demographics:** {$audience['demographics']}\n";
        $output .= "**Psychographics:** {$audience['psychographics']}\n";
        $output .= "**Language Level:** {$audience['language_level']}\n";
        
        if (!empty($audience['pain_points'])) {
            $output .= "**Pain Points:** " . implode(', ', $audience['pain_points']) . "\n";
        }
        
        return $output;
    }

    /**
     * Get complete formatted guidelines for inclusion in prompts
     */
    public function getFormattedGuidelines(): string
    {
        return <<<GUIDELINES
=== BRAND GUIDELINES ===

{$this->getFormattedBrandVoice()}

{$this->getFormattedTargetAudience()}

**UNIQUE SELLING PROPOSITIONS:**
{$this->getFormattedUSPs()}

**MESSAGING THEMES:**
{$this->getFormattedMessagingThemes()}

**VISUAL STYLE:**
{$this->getFormattedVisualStyle()}

{$this->getFormattedColorPalette()}

**BRAND PERSONALITY:**
Archetype: {$this->brand_personality['archetype']}
Characteristics: {$this->getFormattedCharacteristics()}

{$this->getFormattedConstraints()}

=== END BRAND GUIDELINES ===
GUIDELINES;
    }

    /**
     * Get formatted messaging themes
     */
    private function getFormattedMessagingThemes(): string
    {
        return implode("\n", array_map(
            fn($theme) => "- {$theme}",
            $this->messaging_themes
        ));
    }

    /**
     * Get formatted visual style
     */
    private function getFormattedVisualStyle(): string
    {
        $style = $this->visual_style;
        return "Aesthetic: {$style['overall_aesthetic']}\n" .
               "Imagery: {$style['imagery_style']}\n" .
               "Description: {$style['description']}";
    }

    /**
     * Get formatted brand characteristics
     */
    private function getFormattedCharacteristics(): string
    {
        return implode(', ', $this->brand_personality['characteristics']);
    }

    /**
     * Get formatted constraints
     */
    private function getFormattedConstraints(): string
    {
        if (empty($this->do_not_use)) {
            return '';
        }
        
        return "**DO NOT USE:**\n" . implode("\n", array_map(
            fn($item) => "- {$item}",
            $this->do_not_use
        ));
    }

    /**
     * Check if guidelines are fresh (extracted within last 30 days)
     */
    public function isFresh(): bool
    {
        return $this->extracted_at->isAfter(now()->subDays(30));
    }

    /**
     * Check if guidelines have been verified by user
     */
    public function isVerified(): bool
    {
        return !is_null($this->last_verified_at);
    }
}
