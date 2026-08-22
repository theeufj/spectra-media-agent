<?php

namespace App\Services\EmailSequences;

use App\Models\EmailSequenceStep;

/**
 * Turn a step's stored body into the HTML that goes inside the email shell.
 *
 * Exists so the preview pane and the mailable cannot disagree. Both call this;
 * if the substitution or the escaping ever changes it changes in one place. A
 * preview that renders the body differently from the send is worse than no
 * preview, because it is trusted.
 */
class SequenceBodyRenderer
{
    public function __construct(private readonly EmailHtmlSanitizer $sanitizer) {}

    /**
     * @param  array<string, string>  $variables
     */
    public function body(EmailSequenceStep $step, array $variables): string
    {
        $text = $this->substitute($step->body, $variables);

        // Plain bodies are escaped and line-broken; HTML bodies are sanitised.
        // Substitution happens first in both cases so a value containing an
        // angle bracket is caught by the escaping rather than sitting outside
        // it.
        return $step->isHtml()
            ? $this->sanitizer->sanitize($text)
            : nl2br(e($text));
    }

    /**
     * Substitute {{ first_name }} and friends.
     *
     * A placeholder with no value collapses to nothing rather than printing
     * its own name — "Hi {{ first_name }}," reaching a real prospect is worse
     * than "Hi," and there is no value in the middle.
     *
     * @param  array<string, string>  $variables
     */
    public function substitute(string $text, array $variables): string
    {
        foreach ($variables as $key => $value) {
            $text = str_replace(['{{ '.$key.' }}', '{{'.$key.'}}'], (string) $value, $text);
        }

        // Anything still unresolved, and the space before it.
        return trim(preg_replace('/\s*\{\{\s*[a-z_]+\s*\}\}/i', '', $text) ?? $text);
    }
}
