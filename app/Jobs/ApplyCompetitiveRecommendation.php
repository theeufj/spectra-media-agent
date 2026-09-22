<?php

namespace App\Jobs;

use App\Models\Recommendation;
use App\Services\Competition\CompetitiveRecommendationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class ApplyCompetitiveRecommendation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 20;

    public int $timeout = 300;

    public function __construct(public int $recommendationId) {}

    public function handle(CompetitiveRecommendationService $service): void
    {
        $recommendation = Recommendation::find($this->recommendationId);
        if ($recommendation?->source === 'competitor') {
            if (! $service->apply($recommendation)) {
                $this->release(30);
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        $recommendation = Recommendation::find($this->recommendationId);
        if ($recommendation && in_array($recommendation->status, ['approved', 'applying'], true)) {
            $recommendation->update(['status' => 'needs_verification', 'execution' => array_merge($recommendation->execution ?? [], [
                'error' => 'Execution was interrupted. The platform will be checked before any further action.',
            ])]);
        }
    }
}
