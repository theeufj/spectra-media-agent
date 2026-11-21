<?php

namespace App\Jobs;

use App\Models\Strategy;
use App\Services\Creative\CreativePublication;
use App\Services\Creative\CreativeRelease;
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
        // Redis may still contain jobs serialized by the former single-mutation publisher.
        DB::transaction(function () {
            $current = Strategy::lockForUpdate()->find($this->strategyId);
            if (($current?->creative_approval['fingerprint'] ?? null) !== $this->fingerprint) {
                return;
            }
            CreativePublication::begin($current, $this->fingerprint, []);
            PublishApprovedCreative::dispatch($current->id, $this->fingerprint)->afterCommit();
        });
    }
}
