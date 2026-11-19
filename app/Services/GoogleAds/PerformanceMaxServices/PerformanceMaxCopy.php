<?php

namespace App\Services\GoogleAds\PerformanceMaxServices;

final class PerformanceMaxCopy
{
    public const DESCRIPTION_INSTRUCTION = 'Write each description as a complete sentence ending with punctuation. Aim for 75 characters so the full meaning fits within 90.';

    public static function hasCompleteDescriptions(array $descriptions): bool
    {
        return count($descriptions) >= 2 && array_all($descriptions, static function ($asset): bool {
            if (! is_string($asset)) {
                return false;
            }
            $description = trim($asset);

            return self::characters($description) <= 90
                && preg_match('/[.!?][”")\]]*$/u', $description) === 1
                && preg_match('/(?:\.\.\.|…)[”")\]]*$/u', $description) !== 1;
        });
    }

    public static function characters(string $text): int
    {
        return mb_strwidth($text, 'UTF-8');
    }

    /** @return array<string, list<string>> */
    public static function coverageIssues(array $headlines, array $longHeadlines, array $descriptions): array
    {
        $issues = [];
        if (! array_any($headlines, fn ($asset) => is_string($asset) && self::characters(trim($asset)) <= 15)) {
            $issues['headlines'][] = 'Include at least one headline of 15 characters or fewer.';
        }
        if (! array_any($descriptions, fn ($asset) => is_string($asset) && self::characters(trim($asset)) <= 60)) {
            $issues['descriptions'][] = 'Include at least one description of 60 characters or fewer.';
        }
        if (! self::hasCompleteDescriptions($descriptions)) {
            $issues['descriptions'][] = self::DESCRIPTION_INSTRUCTION;
        }

        $seen = [];
        foreach (['headlines' => $headlines, 'long_headlines' => $longHeadlines, 'descriptions' => $descriptions] as $field => $assets) {
            foreach ($assets as $asset) {
                if (! is_string($asset)) {
                    continue;
                }
                // Google can assemble any of these assets together, so repeating
                // the same text across two formats is not meaningful coverage.
                $key = mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $asset) ?? $asset);
                if (isset($seen[$key])) {
                    $issues[$field][] = 'Write distinct assets. This text repeats an earlier asset: "'.$asset.'".';
                }
                $seen[$key] = true;
            }
        }

        return $issues;
    }
}
