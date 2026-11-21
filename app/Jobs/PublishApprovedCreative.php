<?php

namespace App\Jobs;

use App\Models\Strategy;
use App\Models\VideoCollateral;
use App\Services\Creative\CreativePublication;
use App\Services\Creative\CreativeRelease;
use App\Services\FacebookAds\PublishFacebookRevision;
use App\Services\GoogleAds\PerformanceMaxServices\PublishPerformanceMaxRevision;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class PublishApprovedCreative implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $maxExceptions = 4;

    public array $backoff = [60, 180, 600];

    public function __construct(protected int $strategyId, protected string $fingerprint) {}

    public function uniqueId(): string
    {
        return "{$this->strategyId}:{$this->fingerprint}";
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addDays(7);
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('creative-publication:'.$this->strategyId))->shared()->releaseAfter(60)->expireAfter(700)];
    }

    public function handle(CreativeRelease $release): void
    {
        $strategy = Strategy::with('campaign.customer')->find($this->strategyId);
        if (! $strategy || ($strategy->creative_approval['fingerprint'] ?? null) !== $this->fingerprint
            || ($strategy->creative_publication['fingerprint'] ?? null) !== $this->fingerprint
            || ($strategy->creative_publication['phase'] ?? null) === 'published') {
            return;
        }
        if (! $release->isApproved($strategy)) {
            CreativePublication::write($strategy->id, $this->fingerprint, ['phase' => 'superseded', 'detail' => 'The selection or supporting evidence changed. Review the current release.']);

            return;
        }
        $customer = $strategy->campaign->customer;
        $google = CreativePublication::channel($strategy) === 'google';
        if ($google) {
            $account = CreativePublication::guard($strategy, $this->fingerprint)['account_id'];
            $missing = VideoCollateral::forDeployment($strategy)->whereNull('youtube_video_id')->pluck('id')->all();
            if ($missing !== []) {
                (new UploadPMaxVideoAssets($strategy->id, $account, videoIds: $missing, prepareOnly: true))->handle();
                if (VideoCollateral::forDeployment($strategy)->whereNull('youtube_video_id')->exists()) {
                    $this->release(120);

                    return;
                }
            }
        }
        $done = $google
            ? app(PublishPerformanceMaxRevision::class, ['customer' => $customer])->advance($strategy, $this->fingerprint)
            : app(PublishFacebookRevision::class)->advance($strategy, $this->fingerprint);
        if (! $done) {
            $this->release(300);
        }
    }

    public function failed(?\Throwable $failure): void
    {
        CreativePublication::write($this->strategyId, $this->fingerprint, ['phase' => 'failed',
            'detail' => 'The platform could not verify the full replacement. Check the release before retrying.']);
    }
}
