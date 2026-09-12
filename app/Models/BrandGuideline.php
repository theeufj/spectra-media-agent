<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandGuideline extends Model
{
    use BelongsToCustomer;

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
        'service_lines',
        'extraction_quality_score',
        'extraction_warning',
        'user_verified',
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
        'service_lines' => 'array',
        'user_verified' => 'boolean',
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
        $output .= '**Key Attributes:** '.implode(', ', $this->tone_attributes)."\n";

        if (! empty($voice['examples'])) {
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
    /**
     * The palette written for an image model rather than for a designer.
     *
     * getFormattedColorPalette() emits raw hex — "#16365C, #111827" — which is
     * the wrong currency twice over. An image model cannot honour a hex code
     * (it has no way to sample an exact value), and every character of it is a
     * glyph sitting in a prompt that the model may decide to draw. The stored
     * `description` compounds it: the one on file for a real estate customer
     * reads "reserve #C4A470 for refined accents and premium feature
     * callouts", which is design-system vocabulary an image model happily
     * renders as a fake UI full of labels.
     *
     * So this names the colours in plain English and says nothing about where
     * to use them. "Deep navy and near-black" is a direction a model can act
     * on; "#16365C for high-contrast statistic banners" is not.
     */
    public function getImageColorDirection(): string
    {
        $palette = $this->color_palette ?? [];

        $names = collect(array_merge(
            $palette['primary_colors'] ?? [],
            $palette['secondary_colors'] ?? [],
        ))
            ->map(fn ($hex) => self::nameColour((string) $hex))
            ->filter()
            ->unique()
            ->take(4)
            ->implode(', ');

        return $names === '' ? '' : "**Palette:** {$names}\n";
    }

    /**
     * A plain-English name for a hex colour, from its hue and lightness.
     *
     * Deliberately coarse. The point is a word an image model has strong
     * associations for — "deep navy", "warm gold" — not colorimetric accuracy.
     */
    public static function nameColour(string $hex): ?string
    {
        if (! preg_match('/^#?([0-9a-f]{6})$/i', trim($hex), $m)) {
            return null;
        }

        [$r, $g, $b] = array_map(fn ($pair) => hexdec($pair) / 255, str_split($m[1], 2));

        $max = max($r, $g, $b);
        $min = min($r, $g, $b);
        $lightness = ($max + $min) / 2;
        $delta = $max - $min;
        $saturation = $delta == 0.0 ? 0.0 : $delta / (1 - abs(2 * $lightness - 1));

        if ($lightness < 0.14) {
            return 'near-black';
        }
        if ($lightness > 0.94) {
            return 'white';
        }

        if ($saturation < 0.12) {
            return match (true) {
                $lightness < 0.3 => 'charcoal',
                $lightness < 0.55 => 'slate grey',
                $lightness < 0.8 => 'light grey',
                default => 'off-white',
            };
        }

        $hue = match (true) {
            $max === $r => fmod(((($g - $b) / $delta) + 6), 6),
            $max === $g => (($b - $r) / $delta) + 2,
            default => (($r - $g) / $delta) + 4,
        } * 60;

        $family = match (true) {
            $hue < 15 || $hue >= 345 => 'red',
            // The warm band splits on saturation, not hue alone: #C4A470 and
            // #FF4D00 are 19 degrees apart and nobody would call them the same
            // colour. A muted warm mid-tone is gold; a vivid one is orange.
            $hue < 50 => match (true) {
                $lightness < 0.35 => 'brown',
                $saturation < 0.6 => 'warm gold',
                default => 'orange',
            },
            $hue < 70 => 'yellow',
            $hue < 160 => 'green',
            $hue < 200 => 'teal',
            // Desaturated blues are the slate greys every dark UI palette is
            // built from — calling them "blue" loses what they actually read as.
            $hue < 255 => match (true) {
                $saturation < 0.25 => 'slate blue',
                $lightness < 0.35 => 'navy',
                default => 'blue',
            },
            $hue < 290 => 'purple',
            default => 'magenta',
        };

        // These already carry their own qualifier.
        if (in_array($family, ['warm gold', 'brown', 'orange', 'slate blue'], true)) {
            return $family;
        }

        return match (true) {
            $family === 'navy' => 'deep navy',
            $lightness < 0.32 => "deep {$family}",
            $lightness > 0.72 => "pale {$family}",
            default => $family,
        };
    }

    public function getFormattedColorPalette(): string
    {
        $palette = $this->color_palette;
        $output = '**Primary Colors:** '.implode(', ', $palette['primary_colors'])."\n";
        $output .= '**Secondary Colors:** '.implode(', ', $palette['secondary_colors'] ?? [])."\n";
        $output .= "**Usage:** {$palette['description']}\n";

        return $output;
    }

    /**
     * Get formatted USPs for prompts
     */
    public function getFormattedUSPs(): string
    {
        return implode("\n", array_map(
            fn ($usp, $index) => ($index + 1).". {$usp}",
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

        if (! empty($audience['pain_points'])) {
            $output .= '**Pain Points:** '.implode(', ', $audience['pain_points'])."\n";
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
            fn ($theme) => "- {$theme}",
            $this->messaging_themes
        ));
    }

    /**
     * Get formatted visual style
     */
    private function getFormattedVisualStyle(): string
    {
        $style = $this->visual_style;

        return "Aesthetic: {$style['overall_aesthetic']}\n".
               "Imagery: {$style['imagery_style']}\n".
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

        return "**DO NOT USE:**\n".implode("\n", array_map(
            fn ($item) => "- {$item}",
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
        return ! is_null($this->last_verified_at);
    }
}
