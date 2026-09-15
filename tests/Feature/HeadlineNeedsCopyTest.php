<?php

namespace Tests\Feature;

use App\Jobs\GenerateImage;
use App\Models\AdCopy;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\ImageCollateral;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * A creative with no words on it is not an advertisement.
 *
 * GenerateStrategyCollateral dispatches GenerateAdCopy five seconds after
 * sign-off and the image jobs ten seconds after, and the image jobs can lose
 * that race: on campaign 44 the copy landed at 08:51:13 and the first image job
 * had already read for it at about 08:51:10. The headline is composited from
 * that copy, so losing the race does not produce a slightly worse ad — it
 * produces a stock photograph with nothing written on it, which is not what the
 * customer approved and not something anybody would pay to run.
 *
 * Releasing costs twenty seconds. Generating a creative nobody can run costs
 * the whole slot.
 */
class HeadlineNeedsCopyTest extends TestCase
{
    use DatabaseTransactions;

    private function strategy(): Strategy
    {
        $user = User::factory()->create();
        $customer = Customer::factory()->create();
        $user->customers()->attach($customer->id, ['role' => 'owner']);
        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);

        return Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'signed_off_at' => now(),
            'imagery_strategy' => 'A maker in a sunlit studio.',
        ]);
    }

    public function test_it_waits_rather_than_drawing_a_wordless_picture(): void
    {
        $strategy = $this->strategy();
        Http::fake();

        [$job, $queueJob] = $this->jobWithQueue($strategy);

        app()->call([$job, 'handle']);

        // Released, and nothing generated: no spend, no rows, no stock photo.
        $this->assertTrue($queueJob->isReleased(), 'the job should have gone back to the queue to wait for the copy');
        $this->assertSame(0, ImageCollateral::where('campaign_id', $strategy->campaign_id)->count());
    }

    public function test_it_gives_up_eventually_rather_than_waiting_for_ever(): void
    {
        $strategy = $this->strategy();
        Http::fake(['*' => Http::response(['error' => 'no'], 402)]);

        [$job, $queueJob] = $this->jobWithQueue($strategy, attempts: 99);

        app()->call([$job, 'handle']);

        /*
           A copy job that has genuinely failed must not hold the creative
           hostage. Past the limit it proceeds without a headline, which is
           worse than an ad and better than nothing.
        */
        $this->assertFalse($queueJob->isReleased());
    }

    public function test_a_strategy_that_already_has_copy_never_waits(): void
    {
        $strategy = $this->strategy();
        AdCopy::create([
            'strategy_id' => $strategy->id,
            'platform' => 'Google Ads (SEM)',
            'headlines' => ['Build a Store in 5 Minutes'],
            'descriptions' => ['Launch in five minutes.'],
        ]);

        Http::fake(['*' => Http::response(['error' => 'no'], 402)]);

        [$job, $queueJob] = $this->jobWithQueue($strategy);

        app()->call([$job, 'handle']);

        $this->assertFalse($queueJob->isReleased());
    }

    /**
     * A GenerateImage wired to a real queue job, so release() behaves.
     *
     * Mockery needed shouldIgnoreMissing() to survive the calls Laravel makes
     * on a job, and that is not on the interface — the sort of stub that
     * passes by absorbing everything, including the calls the test is meant to
     * be checking. Illuminate's own base Job tracks released state for us.
     *
     * @return array{GenerateImage, FakeQueueJob}
     */
    private function jobWithQueue(Strategy $strategy, int $attempts = 1): array
    {
        $queueJob = new FakeQueueJob($attempts);

        $job = new GenerateImage($strategy->campaign, $strategy, 0);
        $job->setJob($queueJob);

        return [$job, $queueJob];
    }
}

/**
 * The minimum real queue job: Illuminate's base class already implements
 * release() and isReleased(), so the test observes the actual mechanism rather
 * than a stubbed stand-in for it.
 */
final class FakeQueueJob extends \Illuminate\Queue\Jobs\Job implements \Illuminate\Contracts\Queue\Job
{
    public function __construct(private int $attemptCount = 1)
    {
        $this->connectionName = 'sync';
        $this->queue = 'default';
    }

    public function attempts(): int
    {
        return $this->attemptCount;
    }

    public function getJobId(): string
    {
        return 'fake-job';
    }

    public function getRawBody(): string
    {
        return '';
    }
}
