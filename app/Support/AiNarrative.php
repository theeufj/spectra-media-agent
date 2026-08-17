<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Render AI-written prose for email.
 *
 * Models emit Markdown whether or not the prompt asks for it, and the executive
 * report templates ran it through nl2br(e(...)) — which escapes the HTML and
 * converts newlines, but leaves the syntax untouched. Customers received
 * "**Executive Summary: Weekly Performance**" with the asterisks showing, in the
 * one email that is meant to look considered.
 *
 * Converting is not enough on its own: this is model output, so the HTML has to
 * be constrained to a handful of safe tags, and it has to survive Outlook, which
 * ignores most stylesheet rules. Inline styles are applied per element for that
 * reason.
 */
class AiNarrative
{
    /**
     * Tags an AI narrative has any business producing.
     *
     * @var array<string, string>
     */
    private const INLINE_STYLES = [
        'p' => 'margin: 0 0 12px; font-size: 14px; line-height: 1.7; color: #3d4852;',
        'h1' => 'margin: 16px 0 8px; font-size: 16px; font-weight: 700; color: #2d3748;',
        'h2' => 'margin: 16px 0 8px; font-size: 15px; font-weight: 700; color: #2d3748;',
        'h3' => 'margin: 14px 0 6px; font-size: 14px; font-weight: 700; color: #2d3748;',
        'ul' => 'margin: 0 0 12px; padding-left: 20px;',
        'ol' => 'margin: 0 0 12px; padding-left: 20px;',
        'li' => 'font-size: 14px; line-height: 1.6; color: #3d4852; margin-bottom: 4px;',
        'strong' => 'font-weight: 700; color: #2d3748;',
        'em' => 'font-style: italic;',
    ];

    /**
     * Convert a model's Markdown into email-safe HTML.
     *
     * Returns an empty string for empty input so callers can test the result
     * directly rather than guarding twice.
     */
    public static function toEmailHtml(?string $markdown): string
    {
        $markdown = trim((string) $markdown);

        if ($markdown === '') {
            return '';
        }

        // html_input: strip — the narrative is model output, and nothing it
        // writes should be able to inject markup into an email we send on a
        // customer's behalf.
        $html = Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        foreach (self::INLINE_STYLES as $tag => $style) {
            $html = str_replace("<{$tag}>", "<{$tag} style=\"{$style}\">", $html);
        }

        // A trailing empty paragraph is common when the model ends on a newline,
        // and shows up as a stray gap above the call to action.
        return trim(preg_replace('/<p[^>]*>\s*<\/p>/', '', $html));
    }

    /**
     * Did the model stop mid-thought?
     *
     * A summary cut off at the token ceiling still reads as prose until the last
     * few words, so it reaches the customer looking like a considered report
     * that simply stops. Worth knowing before sending.
     */
    public static function looksTruncated(?string $text): bool
    {
        $text = rtrim((string) $text);

        if ($text === '') {
            return false;
        }

        return ! in_array(substr($text, -1), ['.', '!', '?', ':', ')', '"', ']'], true);
    }
}
