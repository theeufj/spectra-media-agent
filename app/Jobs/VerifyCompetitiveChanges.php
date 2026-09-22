<?php

namespace App\Jobs;

use App\Models\Recommendation;
use App\Services\Competition\CompetitiveRecommendationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class VerifyCompetitiveChanges implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function handle(CompetitiveRecommendationService $service): void
    {
        Recommendation::where('source', 'competitor')->whereIn('status', ['applied', 'needs_verification', 'verified'])
            ->where('updated_at', '>=', now()->subDays(30))->each(function (Recommendation $rec) use ($service) {
                try {
                    if ($rec->status !== 'verified') {
                        $service->verify($rec);
                    }
                    if ($rec->fresh()->status === 'verified') {
                        $service->measure($rec->fresh());
                    }
                } catch (\Throwable $e) {
                    report($e);
                }
            });
    }
}
