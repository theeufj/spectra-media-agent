<?php

namespace App\Jobs;

use App\Models\Strategy;
use App\Services\Creative\RenderedSetReviewer;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/** One set review, at most one correction per concept, and one final review. */
class ReviewCreativeSet implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $maxExceptions = 1;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Strategy $strategy, public string $runId) {}

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addMinutes(30);
    }

    public function handle(RenderedSetReviewer $reviewer): void
    {
        $this->strategy->refresh();
        $state = $this->strategy->creative_review ?? [];
        if (($state['run_id'] ?? null) !== $this->runId || in_array($state['status'] ?? '', ['passed', 'needs_review'], true)) {
            return;
        }
        $images = $this->strategy->imageCollaterals()->where('generation_metadata->creative_run_id', $this->runId)->get();
        $slots = $images->pluck('generation_metadata')->pluck('slot')->unique();
        if ($slots->count() < ($state['expected_images'] ?? 3)) {
            if (now()->diffInMinutes(\Carbon\Carbon::parse($state['started_at']), true) >= 15) {
                $this->finish(['status' => 'needs_review', 'message' => 'Some images did not finish. Review the available assets or regenerate.']);
            } else {
                $this->release(30);
            }

            return;
        }
        if ($slots->isEmpty()) {
            $this->finish(['status' => 'needs_review', 'message' => 'No image allowance was available for this set. Review the ad copy and add images when available.']);

            return;
        }
        $lock = Cache::lock('creative-set-review:'.$this->strategy->id, 1800);
        if (! $lock->get()) {
            $this->release(30);

            return;
        }
        try {
            $this->strategy->refresh();
            $state = $this->strategy->creative_review ?? [];
            if (($state['run_id'] ?? null) !== $this->runId || in_array($state['status'] ?? '', ['passed', 'needs_review'], true)) {
                return;
            }
            $state['status'] = 'reviewing';
            if (! $this->saveState($state)) {
                return;
            }
            $results = $reviewer->review($this->strategy, $this->runId);
            $state['initial_review'] = $results;
            foreach ($results as $result) {
                $slot = (int) $result['slot'];
                if ($result['passed'] || ! empty($state['retried_slots'][$slot])) {
                    continue;
                }
                $old = $images->first(fn ($image) => ($image->generation_metadata['slot'] ?? null) === $slot);
                if (! $old) {
                    continue;
                }
                // Reserve the correction budget before making a billable call.
                $state['retried_slots'][$slot] = true;
                $state['status'] = 'revising';
                if (! $this->saveState($state)) {
                    return;
                }
                $reviewer->revise($this->strategy, $slot, $this->runId, $old->concept_key, $result['feedback']);
            }
            if (! empty($state['retried_slots'])) {
                $results = $reviewer->review($this->strategy->fresh(), $this->runId);
            }
            $passed = collect($results)->every(fn ($result) => (bool) $result['passed']);
            $this->finish(['status' => $passed ? 'passed' : 'needs_review', 'results' => $results,
                'message' => $passed ? 'The generated set passed its visual checks. Review the ads before using them.' : 'Some creative needs your review after one correction. No further automatic regeneration will run.']);
        } catch (\Throwable $e) {
            report($e);
            $this->finish(['status' => 'needs_review', 'message' => 'Automatic visual review could not finish. Your generated assets are saved for manual review.']);
        } finally {
            $lock->release();
        }
    }

    private function finish(array $result): void
    {
        $this->strategy->refresh();
        if (($this->strategy->creative_review['run_id'] ?? null) === $this->runId) {
            $this->saveState(array_merge($this->strategy->creative_review, $result, ['finished_at' => now()->toIso8601String()]));
        }
    }

    private function saveState(array $state): bool
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($state) {
            $strategy = Strategy::whereKey($this->strategy->id)->lockForUpdate()->first();
            if (($strategy?->creative_review['run_id'] ?? null) !== $this->runId) {
                return false;
            }
            $strategy->update(['creative_review' => $state]);

            return true;
        });
    }

    public function failed(?\Throwable $exception): void
    {
        $this->finish(['status' => 'needs_review', 'message' => 'Visual review stopped before completion. Review the saved assets manually.']);
    }
}
