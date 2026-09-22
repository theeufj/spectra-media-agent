<?php

namespace App\Prompts;

use App\Models\BrandGuideline;
use App\Models\Persona;
use Illuminate\Support\Facades\Log;

class AdCopyPrompt
{
    /**
     * Admin-editable house-style directives injected into every ad copy
     * prompt (Admin → Settings). Unlike the image/video prompts this is an
     * additive block, not a full template: the rest of this prompt carries
     * machine contracts (the JSON response format, platform rules, the
     * rejection-feedback loop) that a free-form edit could silently break.
     */
    public const DIRECTIVES_SETTING = 'ad_copy_directives';

    private string $strategyContent;

    private string $platform;

    private ?array $rules;

    private ?array $feedback;

    private ?BrandGuideline $brandGuidelines;

    private ?array $productContext;

    private ?Persona $persona;

    private $competitors;

    public function __construct(
        string $strategyContent,
        string $platform,
        ?array $rules = null,
        ?array $feedback = null,
        ?BrandGuideline $brandGuidelines = null,
        ?array $productContext = null,
        ?Persona $persona = null,
        $competitors = null
    ) {
        $this->strategyContent = $strategyContent;
        $this->platform = $platform;
        $this->rules = $rules ?? [];
        $this->feedback = $feedback ?? [];
        $this->brandGuidelines = $brandGuidelines;
        $this->productContext = $productContext;
        $this->persona = $persona;
        $this->competitors = $competitors;
    }

    /**
     * The platform's limits as instructions, not as a config dump.
     *
     * These used to reach the model as json_encode() of the rules array — a
     * block of keys and numbers under a "PLATFORM RULES" heading, with no
     * sentence anywhere telling it to obey them. Counting characters is
     * already the thing a language model is worst at, and it was being asked
     * to infer the requirement from "description_max_length": 90.
     *
     * It did not. A live run produced descriptions of 98, 102 and 99
     * characters against a 90 limit, and the validator rejected all ten
     * attempts. The numbers were in the prompt the whole time.
     */
    private function formatRules(): string
    {
        if (empty($this->rules)) {
            return 'No specific rules provided.';
        }

        $r = $this->rules;
        $lines = ['Copy that breaks any of these is rejected automatically, so check each line before returning it:'];

        if (isset($r['headline_count'], $r['headline_max_length'])) {
            $min = $r['headline_min_length'] ?? 1;
            $lines[] = "- Write exactly {$r['headline_count']} headlines. Each must be between {$min} and {$r['headline_max_length']} characters, counting spaces and punctuation. Aim for a few characters under the maximum — one character over and the headline is thrown out.";
        }

        if (isset($r['description_count'], $r['description_max_length'])) {
            $min = $r['description_min_length'] ?? 1;
            $lines[] = "- Write exactly {$r['description_count']} descriptions. Each must be between {$min} and {$r['description_max_length']} characters, counting spaces and punctuation. A description of {$r['description_max_length']}+1 characters is rejected whole, not trimmed.";
        }

        if (isset($r['max_exclamations_per_element'])) {
            $lines[] = "- At most {$r['max_exclamations_per_element']} exclamation mark per headline or description.";
        }

        if (array_key_exists('allow_consecutive_exclamations', $r) && ! $r['allow_consecutive_exclamations']) {
            $lines[] = '- Never two exclamation marks in a row.';
        }

        // Anything the cases above do not name still has to reach the model,
        // or a rule added to config would be silently dropped from the prompt.
        $named = [
            'headline_count', 'headline_min_length', 'headline_max_length',
            'description_count', 'description_min_length', 'description_max_length',
            'max_exclamations_per_element', 'allow_consecutive_exclamations',
        ];

        $rest = array_diff_key($r, array_flip($named));

        if ($rest !== []) {
            $lines[] = '- Also: '.json_encode($rest);
        }

        return implode("\n", $lines);
    }

    public function getPrompt(): string
    {
        $rulesString = $this->formatRules();

        // Include brand guidelines if available
        $brandContext = $this->brandGuidelines
            ? $this->formatBrandContext()
            : "**BRAND GUIDELINES:** Not available. Use professional, engaging tone suitable for {$this->platform}.";

        // Include product context if available
        $productContextString = '';
        if (! empty($this->productContext)) {
            $productContextString = "\n\n--- SELECTED PRODUCTS ---\n".
                "The user has selected specific products to advertise. You MUST incorporate their details (Price, Title, Features) into the ad copy where appropriate.\n".
                json_encode($this->productContext, JSON_PRETTY_PRINT);
        }

        // Include competitor intelligence if available
        $competitorContext = $this->formatCompetitorContext();

        // Include persona context if available
        $personaContext = '';
        if ($this->persona) {
            $personaContext = "\n\n--- TARGET PERSONA ---\n".
                "Write this ad copy specifically for the following audience persona. Tailor the messaging angle, tone, and pain points addressed.\n".
                "Persona: {$this->persona->name}\n".
                "Description: {$this->persona->description}\n";

            if ($this->persona->pain_points) {
                $personaContext .= 'Pain Points: '.implode(', ', $this->persona->pain_points)."\n";
            }
            if ($this->persona->messaging_angle) {
                $personaContext .= "Messaging Angle: {$this->persona->messaging_angle}\n";
            }
            if ($this->persona->tone_adjustments) {
                $tone = $this->persona->tone_adjustments;
                $personaContext .= 'Tone: '.($tone['formality'] ?? 'balanced').' formality, '.($tone['urgency'] ?? 'medium').' urgency, '.($tone['emotion'] ?? 'balanced')." emotion\n";
            }
            if ($this->persona->demographics) {
                $demo = $this->persona->demographics;
                $personaContext .= 'Demographics: '.($demo['age_range'] ?? '').', '.($demo['income_level'] ?? '')."\n";
            }
            $personaContext .= '--- END PERSONA ---';
        }

        // Admin-authored style rules apply to every customer's copy.
        $directives = trim((string) \App\Models\Setting::get(self::DIRECTIVES_SETTING, ''));
        $directivesBlock = $directives !== ''
            ? "--- HOUSE STYLE DIRECTIVES (always follow) ---\n{$directives}\n--- END HOUSE STYLE ---\n\n"
            : '';

        $basePrompt = "You are an expert copywriter specializing in {$this->platform} advertising.\n\n".
                      $directivesBlock.
                      $brandContext."\n\n".
                      $competitorContext.
                      "--- PLATFORM RULES ---\n".
                      $rulesString.
                      $productContextString.
                      $personaContext."\n\n".
                      "--- RESPONSE FORMAT ---\n".
                      "Return the output as a JSON object with two keys: 'headlines' (an array of strings) and 'descriptions' (an array of strings). ".
                      'Do NOT include any conversational text, explanations, or additional formatting outside the JSON object. '.
                      "Example: {\"headlines\": [\"Headline 1\", \"Headline 2\"], \"descriptions\": [\"Description 1.\", \"Description 2.\"]}\n\n".
                      "--- MARKETING STRATEGY ---\n{$this->strategyContent}";

        if (! empty($this->feedback)) {
            // Unescaped: the model reads this, and "62\/100 \u2014 76 or above"
            // is harder to act on than the sentence it was written as.
            $feedbackString = json_encode(
                $this->feedback,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
            $basePrompt .= "\n\n--- CRITICAL CORRECTIONS REQUIRED ---\n".
                           "The previous ad copy you generated was REJECTED because it violated the platform's rules. You MUST fix the following errors:\n".
                           $feedbackString."\n\n".
                           'Generate a completely new and valid set of ad copy that strictly adheres to all rules and corrects these specific errors.';
        }

        Log::info('Generated AdCopyPrompt with brand guidelines.', [
            'has_brand_guidelines' => ! is_null($this->brandGuidelines),
            'platform' => $this->platform,
        ]);

        return $basePrompt;
    }

    private function formatCompetitorContext(): string
    {
        if (! $this->competitors || $this->competitors->isEmpty()) {
            return '';
        }

        $lines = [];
        foreach ($this->competitors as $c) {
            $name = $c->name ?? $c->domain ?? 'Competitor';
            $messaging = $c->messaging_analysis ?? [];
            $counterStrategy = $messaging['counter_strategy'] ?? null;
            $keyMessages = $messaging['key_messages'] ?? $messaging['messaging_themes'] ?? [];

            $entry = "**{$name}**";
            if (! empty($keyMessages)) {
                $entry .= "\n  Their messaging: ".implode('; ', array_slice((array) $keyMessages, 0, 3));
            }
            if ($counterStrategy) {
                $strategy = is_array($counterStrategy)
                    ? ($counterStrategy['strategy'] ?? implode('; ', array_merge($counterStrategy['differentiation_angles'] ?? [], $counterStrategy['ad_copy_recommendations'] ?? [])))
                    : $counterStrategy;
                if ($strategy) {
                    $entry .= "\n  Counter-angle: {$strategy}";
                }
            }
            $lines[] = $entry;
        }

        if (empty($lines)) {
            return '';
        }

        return "--- COMPETITOR INTELLIGENCE ---\n".
               "Write copy that differentiates from these competitors. Use their weaknesses and messaging gaps as angles.\n\n".
               implode("\n\n", $lines)."\n\n--- END COMPETITOR INTELLIGENCE ---\n\n";
    }

    private function formatBrandContext(): string
    {
        return <<<BRAND
--- BRAND GUIDELINES ---

{$this->brandGuidelines->getFormattedBrandVoice()}

{$this->brandGuidelines->getFormattedTargetAudience()}

**UNIQUE SELLING PROPOSITIONS:**
{$this->brandGuidelines->getFormattedUSPs()}

**MESSAGING THEMES:**
{$this->formatMessagingThemes()}

{$this->formatConstraints()}

--- END BRAND GUIDELINES ---
BRAND;
    }

    private function formatMessagingThemes(): string
    {
        return implode("\n", array_map(
            fn ($theme) => "- {$theme}",
            $this->brandGuidelines->messaging_themes
        ));
    }

    private function formatConstraints(): string
    {
        if (empty($this->brandGuidelines->do_not_use)) {
            return '';
        }

        return "**DO NOT USE:**\n".implode("\n", array_map(
            fn ($item) => "- {$item}",
            $this->brandGuidelines->do_not_use
        ));
    }
}
