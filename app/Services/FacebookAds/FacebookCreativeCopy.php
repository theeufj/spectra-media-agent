<?php

namespace App\Services\FacebookAds;

use App\Models\Strategy;
use RuntimeException;

class FacebookCreativeCopy
{
    /**
     * Cycle through each selected persona's own headline/body combinations. Images
     * and videos share the same sequence so the first video gets its own message.
     *
     * @return list<array{headline: string, body: string}>
     */
    public static function variations(Strategy $strategy): array
    {
        $sets = [];
        $copyRows = $strategy->deployableAdCopies()->whereRaw('LOWER(platform) LIKE ?', ['%facebook%'])->get();
        foreach ($copyRows as $copy) {
            $headlines = self::lines($copy->getAttribute('headlines'));
            $bodies = self::lines($copy->getAttribute('descriptions'));
            if ($headlines === [] || $bodies === []) {
                continue;
            }

            $pairs = [];
            // Pair corresponding variants first; rotate the bodies on the next pass.
            foreach ($bodies as $offset => $unused) {
                foreach ($headlines as $i => $headline) {
                    $pairs[] = ['headline' => $headline, 'body' => $bodies[($i + $offset) % count($bodies)]];
                }
            }
            $sets[] = $pairs;
        }
        if ($sets === []) {
            throw new RuntimeException('Select a Facebook headline and primary text for this campaign.');
        }

        $variants = [];
        for ($i = 0; $i < max(array_map('count', $sets)); $i++) {
            foreach ($sets as $pairs) {
                if (isset($pairs[$i]) && ! in_array($pairs[$i], $variants, true)) {
                    $variants[] = $pairs[$i];
                }
            }
        }

        return $variants;
    }

    private static function lines(?array $lines): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($line) => is_string($line) ? trim($line) : '',
            $lines ?? [],
        ), fn ($line) => $line !== '')));
    }
}
