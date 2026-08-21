<?php

namespace App\Services\Support;

/**
 * Audits one assistant turn against the tool output it was given.
 *
 * TWO SIGNALS, WITH DIFFERENT EPISTEMIC WEIGHT — and conflating them would make
 * the monitoring worse than none:
 *
 *  - "Did it consult the account?" is a FACT. The tool calls were recorded as
 *    they happened. A reply about performance that used no tools was answered
 *    from the prompt alone, and that is provable.
 *
 *  - "Do the figures match?" is a HEURISTIC. The reply is prose; the model may
 *    legitimately round ($1,234.56 -> "about $1,200"), convert, sum two figures,
 *    or restate a number the customer typed. So an unsourced figure means
 *    "a human should read this one", never "this is wrong". Anything that
 *    reported these as errors would be crying wolf within a week and would be
 *    ignored — which is the failure mode that matters for a monitor.
 */
class SupportChatAudit
{
    /**
     * Numbers below this are ignored entirely.
     *
     * Small integers are overwhelmingly ordinals, list positions, day counts and
     * prose ("3 things to try", "the last 7 days"). Including them would bury
     * the real signal — a quoted spend or conversion figure — in noise.
     */
    private const MIN_INTERESTING = 100.0;

    /** Relative tolerance, so sensible rounding of a real figure still matches. */
    private const TOLERANCE = 0.05;

    /**
     * Figures in the reply that cannot be traced to any tool output.
     *
     * @param  list<float>  $toolNumbers  every value the tools returned this turn
     * @param  string  $customerMessage  excluded, since quoting the customer back is not fabrication
     * @return list<float>
     */
    public function unsourcedFigures(string $reply, array $toolNumbers, string $customerMessage = ''): array
    {
        $quoted = $this->extractNumbers($reply);
        $theirs = $this->extractNumbers($customerMessage);

        $unsourced = [];

        foreach ($quoted as $number) {
            if ($number < self::MIN_INTERESTING) {
                continue;
            }

            if ($this->isNear($number, $toolNumbers) || $this->isNear($number, $theirs)) {
                continue;
            }

            $unsourced[] = $number;
        }

        return array_values(array_unique($unsourced));
    }

    /**
     * Should this turn have used a tool?
     *
     * Keyword-based on the CUSTOMER's message, not the reply: the question is
     * whether the assistant should have looked, and that is decided by what was
     * asked. Judging from the reply would let a deflecting answer excuse itself.
     */
    public function shouldHaveUsedTools(string $customerMessage): bool
    {
        $needles = [
            'my ad', 'my campaign', 'my account', 'my spend', 'my budget', 'my result',
            'how am i', 'how are my', 'performance', 'better', 'improve', 'optimi',
            'roas', 'ctr', 'cpa', 'conversion', 'clicks', 'impressions',
            'how much', 'spending', 'spent', 'balance', 'working',
        ];

        return \Illuminate\Support\Str::contains(\Illuminate\Support\Str::lower($customerMessage), $needles);
    }

    /**
     * Pull candidate figures out of prose.
     *
     * Strips thousands separators and currency symbols so "$1,234.56" and
     * "1234.56" compare equal. Percentages are included — a fabricated CTR is
     * exactly the kind of claim worth catching.
     *
     * @return list<float>
     */
    private function extractNumbers(string $text): array
    {
        // Drop separators first so 1,234.56 survives as one token.
        $normalised = preg_replace('/(?<=\d),(?=\d{3}\b)/', '', $text) ?? $text;

        preg_match_all('/-?\d+(?:\.\d+)?/', $normalised, $matches);

        return array_map('floatval', $matches[0]);
    }

    /**
     * @param  list<float>  $haystack
     */
    private function isNear(float $needle, array $haystack): bool
    {
        foreach ($haystack as $candidate) {
            if ($candidate == 0.0) {
                if ($needle == 0.0) {
                    return true;
                }

                continue;
            }

            if (abs($needle - $candidate) / abs($candidate) <= self::TOLERANCE) {
                return true;
            }
        }

        return false;
    }
}
