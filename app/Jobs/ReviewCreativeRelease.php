<?php

namespace App\Jobs;

use App\Models\Strategy;
use App\Services\Creative\CreativeRelease;
use App\Services\Creative\CreativeReleaseReviewer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class ReviewCreativeRelease implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public bool $failOnTimeout = true;

    public int $tries = 2;

    // Keep the selection locked through both attempts, Redis visibility and
    // backoff, even if the first worker exits before releasing its reservation.
    public int $uniqueFor = 7200;

    public array $backoff = [90];

    public function __construct(private int $strategyId, private string $fingerprint) {}

    public function uniqueId(): string
    {
        return "{$this->strategyId}:{$this->fingerprint}";
    }

    public function handle(CreativeRelease $release, CreativeReleaseReviewer $reviewer): void
    {
        $strategy = Strategy::find($this->strategyId);
        if (! $strategy) {
            return;
        }
        $review = $strategy->creative_release_review;
        if ($release->reviewIsCurrent($review ?? [], $this->fingerprint)) {
            return;
        }
        $selection = $release->selection($strategy);
        if ($selection['fingerprint'] !== $this->fingerprint) {
            DB::transaction(function () {
                $current = Strategy::lockForUpdate()->find($this->strategyId);
                if (! $current) {
                    return;
                }
                $rotation = $current->creative_revision ?? [];
                if (($rotation['status'] ?? null) === 'checking_rotation' && ($rotation['fingerprint'] ?? null) === $this->fingerprint) {
                    $current->forceFill(['creative_revision' => [...$rotation, 'status' => 'needs_approval']])->save();
                }
            });

            return;
        }
        $this->store($release, $reviewer->review($strategy, $selection));
    }

    public function failed(\Throwable $exception): void
    {
        report($exception);
        $this->store(app(CreativeRelease::class), [
            'status' => 'unavailable',
            'issues' => ['The creative review is temporarily unavailable. Reload this page in a few minutes to retry.'],
            'retry_after' => now()->addMinutes(5)->timestamp,
        ]);
    }

    private function store(CreativeRelease $release, array $result): void
    {
        DB::transaction(function () use ($release, $result) {
            $strategy = Strategy::lockForUpdate()->find($this->strategyId);
            if ($strategy && $release->selection($strategy)['fingerprint'] === $this->fingerprint) {
                $strategy->update(['creative_release_review' => ['fingerprint' => $this->fingerprint, ...$result, 'copy_review_version' => 2]]);
                app(\App\Services\Creative\ApprovedVariantRotation::class)->complete($strategy, $result);
            }
        });
    }
}
