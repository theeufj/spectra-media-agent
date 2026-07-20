<?php

namespace App\Services\Agents\Concerns;

/**
 * Parse a JSON object out of an LLM text response, tolerating the ```json / ```
 * code-fence wrappers models commonly emit. Returns [] on any parse failure.
 *
 * This is the canonical version of a helper that was copy-pasted across many agents;
 * new agents (and existing ones as they are touched) should use this trait rather
 * than re-implementing it.
 */
trait ParsesLlmJson
{
    protected function parseJson(string $text): array
    {
        $cleaned = trim($text);

        if (str_starts_with($cleaned, '```json')) {
            $cleaned = substr($cleaned, 7);
        }
        if (str_starts_with($cleaned, '```')) {
            $cleaned = substr($cleaned, 3);
        }
        if (str_ends_with($cleaned, '```')) {
            $cleaned = substr($cleaned, 0, -3);
        }

        return json_decode(trim($cleaned), true) ?? [];
    }
}
