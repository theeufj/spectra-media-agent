<?php

namespace App\Jobs;

use App\Models\Strategy;
use App\Models\VideoCollateral;
use App\Services\Creative\CreativeRelease;
use App\Services\GoogleAds\PerformanceMaxServices\RefreshPerformanceMaxAssets;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class RefreshApprovedPerformanceMaxCreative implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 12;

    public array $backoff = [45, 90, 180];

    public function __construct(private int $strategyId, private string $fingerprint) {}

    public function uniqueId(): string
    {
        return "{$this->strategyId}:{$this->fingerprint}";
    }

    public function handle(CreativeRelease $release): void
    {
        $strategy = Strategy::with('campaign.customer')->find($this->strategyId);
        if (! $strategy || ($strategy->creative_approval['fingerprint'] ?? null) !== $this->fingerprint || ! $release->isApproved($strategy)) {
            return;
        }
        $customer = $strategy->campaign->customer;
        $account = $customer->cleanGoogleCustomerId();
        if (! $account) {
            return;
        }
        $approval = $strategy->creative_approval;
        $missingVideoIds = VideoCollateral::forDeployment($strategy)->whereNull('youtube_video_id')->pluck('id')->all();
        if ($missingVideoIds !== []) {
            (new UploadPMaxVideoAssets($strategy->id, $account, videoIds: $missingVideoIds, prepareOnly: true))->handle();
            if (VideoCollateral::forDeployment($strategy)->whereNull('youtube_video_id')->exists()) {
                $this->release(45);

                return;
            }
        }

        $result = app(RefreshPerformanceMaxAssets::class, ['customer' => $customer])->refresh(
            $strategy, $approval['copy_ids'][0] ?? null, $approval['image_ids'], $approval['video_ids'], apply: true, replaceMedia: true,
        );
        if (! $result['links_confirmed']) {
            throw new \RuntimeException('Google has not yet confirmed the approved creative update.');
        }

        DB::transaction(function () {
            $current = Strategy::lockForUpdate()->find($this->strategyId);
            if (($current?->creative_approval['fingerprint'] ?? null) !== $this->fingerprint) {
                return;
            }
            $current->forceFill([
                'creative_approval' => [...$current->creative_approval, 'published_at' => now()->toIso8601String()],
                'launch_intent' => $current->launch_intent === null ? null : [
                    ...$current->launch_intent, 'creative_fingerprint' => $this->fingerprint,
                ],
            ])->save();
        });
    }
}
