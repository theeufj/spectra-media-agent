<?php

namespace Tests\Feature;

use App\Jobs\GenerateImage;
use App\Models\AdCopy;
use App\Models\Campaign;
use App\Models\Strategy;
use Tests\TestCase;

/**
 * Four creatives, four messages.
 *
 * The approved copy carried five headlines and the model was handed all of
 * them with "set exactly one of those lines". It set the same one every time:
 * four photographs of "Build a Store in 5 Minutes". Five headlines are written,
 * approved and paid for so the account can discover which of them works, and
 * four copies of one message cannot tell anybody that.
 *
 * Keyed on the lens — the thing that already makes each creative visually
 * distinct now makes it say something distinct too.
 */
class HeadlineRotationTest extends TestCase
{
    private const HEADLINES = [
        'Build a Store in 5 Minutes',
        'All-In-One Store for $35/Mo',
        'Have a Chat, Get a Store',
        'No Hidden Fees or Extra Apps',
        'Launch Your Store with AI',
    ];

    private function headlineForLens(?AdCopy $adCopy, int $lens): ?string
    {
        $job = new GenerateImage(Campaign::factory()->make(), new Strategy);

        $m = new \ReflectionMethod($job, 'headlineForLens');

        return $m->invoke($job, $adCopy, $lens);
    }

    public function test_consecutive_creatives_lead_with_different_headlines(): void
    {
        $adCopy = new AdCopy(['headlines' => self::HEADLINES]);

        $chosen = array_map(fn ($lens) => $this->headlineForLens($adCopy, $lens), [0, 1, 2, 3]);

        $this->assertSame(4, count(array_unique($chosen)), 'two creatives in one set carried the same message');
        $this->assertSame('Build a Store in 5 Minutes', $chosen[0]);
    }

    public function test_it_wraps_rather_than_running_out(): void
    {
        // Six creatives against five headlines is fine; a repeat at that point
        // is better than an empty headline.
        $adCopy = new AdCopy(['headlines' => self::HEADLINES]);

        $this->assertSame($this->headlineForLens($adCopy, 0), $this->headlineForLens($adCopy, 5));
    }

    public function test_blank_headlines_are_never_nominated(): void
    {
        // An empty string would be nominated as the exact line to set, and the
        // model would be asked to render nothing word for word.
        $adCopy = new AdCopy(['headlines' => ['Launch in 5 Minutes', '', '   ']]);

        foreach (range(0, 5) as $lens) {
            $this->assertSame('Launch in 5 Minutes', $this->headlineForLens($adCopy, $lens));
        }
    }

    public function test_no_copy_means_no_nomination(): void
    {
        $this->assertNull($this->headlineForLens(null, 0));
        $this->assertNull($this->headlineForLens(new AdCopy(['headlines' => []]), 0));
    }
}
