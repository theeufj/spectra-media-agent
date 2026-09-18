<?php

namespace App\Support;

/**
 * Turning many chunk vectors into the one vector a row actually stores.
 *
 * A knowledge base row holds a single vector for a whole page, but a page is
 * embedded chunk by chunk — the model has a token limit and a whole page does
 * not fit. Something has to reduce the list to one, and the choice is not
 * cosmetic: it decides what the row matches on.
 *
 * CrawlPage's comment said the chunks were averaged. They were not. The write
 * passed `$embedding`, the foreach variable, which after the loop holds
 * whichever chunk happened to be last — so every row was indexed on the tail
 * of its page, which for most sites is the footer, the terms link and the
 * contact block. `$allEmbeddings` was collected and used only to test that the
 * list was non-empty. Search still returned results, which is why it went
 * unnoticed: a wrong vector is not an empty one.
 */
class Embeddings
{
    /**
     * The element-wise mean of several equal-length vectors.
     *
     * Vectors of differing length are a mixed-model row rather than a page
     * with an odd chunk, and averaging across two embedding spaces produces a
     * point that means nothing in either. Those return null so the caller can
     * record the row as unembedded instead of storing a plausible fiction.
     *
     * @param  array<int, array<int, float>>  $vectors
     * @return array<int, float>|null
     */
    public static function average(array $vectors): ?array
    {
        $vectors = array_values(array_filter($vectors, static fn (array $v) => $v !== []));

        if ($vectors === []) {
            return null;
        }

        $dimensions = count($vectors[0]);

        foreach ($vectors as $vector) {
            if (count($vector) !== $dimensions) {
                return null;
            }
        }

        $sums = array_fill(0, $dimensions, 0.0);

        foreach ($vectors as $vector) {
            foreach ($vector as $i => $value) {
                $sums[$i] += (float) $value;
            }
        }

        $count = count($vectors);

        return array_map(static fn (float $sum): float => $sum / $count, $sums);
    }

    /**
     * Split text into embeddable pieces on paragraph then sentence boundaries.
     *
     * Ingestion asks a model to chunk the page semantically, but never stores
     * what it produced — only the averaged vector survives. So a repair has
     * nothing to restore and no reason to buy the same chunking again: for a
     * mean over the whole page, where the boundaries fall barely moves the
     * result, and a deterministic split costs nothing and cannot fail.
     *
     * @return array<int, string>
     */
    public static function split(string $text, int $maxChars = 2000): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $pieces = preg_split('/\n{2,}/', $text) ?: [$text];
        $chunks = [];
        $current = '';

        foreach ($pieces as $piece) {
            $piece = trim($piece);

            if ($piece === '') {
                continue;
            }

            // A single paragraph longer than the limit is broken on sentence
            // ends; a run-on with no sentence ends at all is cut on length,
            // because refusing to split it would drop the passage entirely.
            if (mb_strlen($piece) > $maxChars) {
                foreach (preg_split('/(?<=[.!?])\s+/', $piece) ?: [] as $sentence) {
                    foreach (mb_str_split($sentence, $maxChars) as $part) {
                        $chunks = self::append($chunks, $current, $part, $maxChars);
                        $current = array_pop($chunks) ?? '';
                    }
                }

                continue;
            }

            $chunks = self::append($chunks, $current, $piece, $maxChars);
            $current = array_pop($chunks) ?? '';
        }

        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return array_values(array_filter($chunks, static fn ($c) => trim($c) !== ''));
    }

    /**
     * Add a piece to the open chunk, closing it first if it would overflow.
     *
     * Returns the full list with the still-open chunk as its last element, so
     * the caller pops it back off — it keeps the accumulate-and-flush in one
     * place rather than repeated at each of the call sites above.
     *
     * @param  array<int, string>  $chunks
     * @return array<int, string>
     */
    private static function append(array $chunks, string $current, string $piece, int $maxChars): array
    {
        if ($current === '') {
            $chunks[] = $piece;

            return $chunks;
        }

        if (mb_strlen($current) + mb_strlen($piece) + 2 <= $maxChars) {
            $chunks[] = $current."\n\n".$piece;

            return $chunks;
        }

        $chunks[] = $current;
        $chunks[] = $piece;

        return $chunks;
    }
}
