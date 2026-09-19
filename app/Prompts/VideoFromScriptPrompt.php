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
     * {{ad_text}} (approved ad copy for message context, never rendered).
     *
     * Strictly no on-screen text: validated 2026-08-24 — Veo 3.1 garbled
     * even a three-word end-card ("Agency Resullts"). The message belongs in the
     * voiceover; admins can experiment with text via the editable template.
     */
    public static function defaultTemplate(): string
    {
        return <<<'PROMPT'
Create a distinctive short advertisement with spoken English voiceover. Bring the supplied selling idea to life; every shot must help the viewer understand or want this offer.

--- VOICEOVER SCRIPT (the narrator speaks exactly this) ---
{{voiceover_script}}

--- CREATIVE STRATEGY ---
{{creative_strategy}}

--- APPROVED AD TEXT (context for the message — never rendered on screen) ---
{{ad_text}}

--- REQUIREMENTS ---

1. VISUAL STORYTELLING:
   - Open immediately on the product, a compelling action or the benefit in progress.
     The first frame should work as an ad image on its own. No fade-in, logo-only intro,
     slow establishing shot or automatic stressed-person setup.
   - Follow ONE concept from the creative strategy. If a variation selects an Alternative
     angle, use that angle only; do not combine both concepts into a montage.
   - Build a short, connected sequence: hook, demonstration, resolved benefit. For a full
     15-second ad, two or three purposeful shots are enough. Match the supplied narration;
     if it is only an opening segment, show just those opening beats and do not rush to a
     final reveal or call to action that belongs to a later segment.
   - Show a specific product detail, use or service action as the reason to believe the offer.
     No interchangeable laptop footage, unrelated lifestyle filler or generic AI imagery.
   - Honour the chosen visual medium, palette, materials and lighting. A tactile product film,
     candid service moment or simple animation can be more appropriate than a cinematic film.
     Use camera movement, cuts and sound cues only when they clarify the action.
   - Keep the product and action prominent within the requested aspect ratio and away from
     edges; reframe for the requested orientation rather than cropping away the selling idea.
   - Make the visual sequence understandable without sound. Narration adds the brand and
     message; the images should still communicate the use or benefit on their own.

2. ON-SCREEN TEXT (NONE):
   - No text anywhere in the video: no captions, titles, labels, end-cards, UI text or fine print
   - Any scene that would naturally contain text (screens, signs, documents) must show it as
     plain unlettered surfaces or soft colour, never legible words or fake UI placeholder bars
   - Video text rendering garbles even short phrases — the message belongs in the voiceover

3. AUDIO:
   - The voiceover script above must be spoken clearly as English narration
   - Speak only the supplied script, with natural pauses. Never add a slogan, testimonial
     or extra claim. When it includes a brand name, keep that early identification clear.
   - Use a subtle music bed and purposeful product/action sounds appropriate to the concept;
     narration always intelligible. Do not fill every moment with dramatic music.

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
