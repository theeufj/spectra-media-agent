<?php

namespace App\Prompts;

use App\Models\Setting;

class VideoFromScriptPrompt
{
    /**
     * Admin-editable override for the video generation prompt. Blank or
     * unset means the built-in default below. Edited from Admin → Settings.
     */
    public const TEMPLATE_SETTING = 'video_prompt_template';

    private string $strategy;

    private string $script;

    private string $adText;

    public function __construct(string $strategy, string $script, string $adText = '')
    {
        $this->strategy = $strategy;
        $this->script = $script;
        $this->adText = $adText;
    }

    /**
     * The built-in prompt template. Placeholders substituted per generation:
     * {{creative_strategy}} (the strategy's video brief),
     * {{voiceover_script}} (the narration the visuals must follow),
     * {{ad_text}} (approved ad copy — the only words allowed on screen).
     *
     * Strictly no on-screen text: validated 2026-08-24 — Veo 3.1 garbled
     * even a three-word end-card ("Agency Resullts"), unlike the image
     * model, which sets type accurately. The message belongs in the
     * voiceover; admins can experiment with text via the editable template.
     */
    public static function defaultTemplate(): string
    {
        return <<<'PROMPT'
Create a polished advertising video with spoken English voiceover.

--- VOICEOVER SCRIPT (the narrator speaks exactly this) ---
{{voiceover_script}}

--- CREATIVE STRATEGY ---
{{creative_strategy}}

--- APPROVED AD TEXT (context for the message — never rendered on screen) ---
{{ad_text}}

--- REQUIREMENTS ---

1. VISUAL STORYTELLING:
   - Scenes must directly match and enhance the voiceover narrative, cut to its pacing
   - Dynamic camera movement and clean transitions; professional, polished production quality
   - Colour grading and lighting that fit the brand tone in the creative strategy

2. ON-SCREEN TEXT (NONE):
   - No text anywhere in the video: no captions, titles, labels, end-cards, UI text or fine print
   - Any scene that would naturally contain text (screens, signs, documents) must show it as
     abstract blurred shapes or placeholder bars, never legible words
   - Video text rendering garbles even short phrases — the message belongs in the voiceover

3. AUDIO:
   - The voiceover script above must be spoken clearly as English narration
   - Subtle music bed appropriate to the brand tone; narration always intelligible

4. HARD RULES:
   - Never invent statistics, prices, ratings or claims
   - No watermarks, no third-party logos
   - Cultural sensitivity and inclusivity throughout
PROMPT;
    }

    /**
     * The template actually in force: the admin override when one is set,
     * otherwise the default.
     */
    public static function activeTemplate(): string
    {
        $custom = trim((string) Setting::get(self::TEMPLATE_SETTING, ''));

        return $custom !== '' ? $custom : self::defaultTemplate();
    }

    public function getPrompt(): string
    {
        return strtr(self::activeTemplate(), [
            '{{creative_strategy}}' => $this->strategy,
            '{{voiceover_script}}' => $this->script,
            '{{ad_text}}' => $this->adText !== '' ? $this->adText : '(none provided — end with no text)',
        ]);
    }
}
