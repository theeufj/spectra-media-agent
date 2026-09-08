<?php

namespace Tests\Feature;

use App\Jobs\GenerateCampaignCollateral;
use App\Mail\AdsReadyToDeploy;
use App\Mail\CollateralGenerated;
use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\PendingBatch;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One failed asset used to cost the customer every email about the batch.
 *
 * The batch is dispatched with allowFailures(), and Batch::recordSuccessfulJob()
 * invokes `then` only when pending_jobs reaches zero — but
 * DatabaseBatchRepository::incrementFailedJobs() writes pending_jobs back
 * unchanged, so a permanently failed member pins it above zero forever. The
 * members do fail permanently: GenerateAdCopy, GenerateImage and GenerateVideo
 * all end handle() with fail(), which bypasses $tries. A single Gemini or Veo
 * error therefore meant no CollateralGenerated mail and no AdsReadyToDeploy
 * upsell, though the other eleven assets had generated fine.
 *
 * `finally` is the callback an allowFailures() batch actually reaches.
 */
class CollateralBatchCompletionTest extends TestCase
{
    use DatabaseTransactions;

    private ?int $campaignId = null;

    public function test_the_completion_callback_is_finally_rather_than_then(): void
    {
        $batch = $this->dispatchedBatch();

        $this->assertTrue($batch->allowsFailures());
        $this->assertCount(1, $batch->finallyCallbacks());
        $this->assertSame(
            [],
            $batch->thenCallbacks(),
            'then() never fires once a member has failed permanently'
        );
    }

    public function test_a_partly_failed_batch_still_emails_what_did_generate(): void
    {
        $callback = $this->dispatchedBatch()->finallyCallbacks()[0];

        Mail::fake();

        // Eleven of twelve assets generated — the exact case that sent nothing.
        $callback($this->batch(totalJobs: 12, failedJobs: 1));

        Mail::assertSent(CollateralGenerated::class);
        Mail::assertSent(AdsReadyToDeploy::class);
    }

    public function test_a_batch_where_everything_failed_promises_nothing(): void
    {
        $callback = $this->dispatchedBatch()->finallyCallbacks()[0];

        Mail::fake();

        $callback($this->batch(totalJobs: 12, failedJobs: 12));

        // catch() has already stamped the error onto the strategies for the
        // polling endpoint; "your collateral is ready" would be a lie.
        Mail::assertNothingSent();
    }

    /**
     * Run the job and return the batch it dispatched.
     */
    private function dispatchedBatch(): PendingBatch
    {
        $customer = Customer::factory()->create();

        $campaign = Campaign::factory()->create(['customer_id' => $customer->id]);
        $this->campaignId = $campaign->id;

        Strategy::factory()->create([
            'campaign_id' => $campaign->id,
            'platform' => 'Google Ads',
            'signed_off_at' => now(),
            // Keeps the batch to ad copy plus three images; the video path is
            // not what is under test here.
            'generate_video' => false,
        ]);

        $user = User::factory()->create();
        $customer->users()->attach($user->id);

        Bus::fake();

        (new GenerateCampaignCollateral($campaign, $user->id))->handle();

        $captured = null;
        Bus::assertBatched(function (PendingBatch $batch) use (&$captured) {
            $captured = $batch;

            return true;
        });

        $this->assertInstanceOf(PendingBatch::class, $captured);

        return $captured;
    }

    /**
     * A finished batch as the repository would report it: a failed member never
     * decrements pending_jobs, which is the whole reason `then` never fired.
     */
    private function batch(int $totalJobs, int $failedJobs): Batch
    {
        return new Batch(
            app(QueueFactory::class),
            app(BatchRepository::class),
            (string) Str::uuid(),
            "Campaign {$this->campaignId} Collateral",
            $totalJobs,
            $failedJobs,
            $failedJobs,
            [],
            [],
            CarbonImmutable::now(),
        );
    }
}
