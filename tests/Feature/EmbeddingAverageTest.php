<?php

namespace Tests\Feature;

use App\Support\Embeddings;
use Tests\TestCase;

/**
 * The averaging itself, which decides what a row matches on.
 */
class EmbeddingAverageTest extends TestCase
{
    public function test_it_takes_the_mean_rather_than_the_last_chunk(): void
    {
        // The bug in CrawlPage: the write used the foreach variable, so a page
        // was represented by whichever chunk came last — its footer.
        $this->assertSame(
            [2.0, 20.0],
            Embeddings::average([[1.0, 10.0], [2.0, 20.0], [3.0, 30.0]]),
        );
    }

    public function test_vectors_from_two_spaces_are_refused_rather_than_blended(): void
    {
        // A mean across two embedding spaces is a point that means nothing in
        // either, and it would be stored looking exactly like a good one.
        $this->assertNull(Embeddings::average([[1.0, 2.0], [1.0, 2.0, 3.0]]));
    }

    public function test_nothing_to_average_is_not_a_zero_vector(): void
    {
        $this->assertNull(Embeddings::average([]));
    }

    public function test_splitting_keeps_every_passage(): void
    {
        $text = "First para.\n\nSecond para.\n\nThird para.";
        $chunks = Embeddings::split($text, 20);

        $this->assertNotEmpty($chunks);
        $this->assertSame(
            preg_replace('/\s+/', '', $text),
            preg_replace('/\s+/', '', implode('', $chunks)),
        );
    }
}
