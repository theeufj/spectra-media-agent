<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\Agents\CampaignOptimizationAgent;
use App\Services\Competition\CompetitiveRecommendationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

class ReviewCompetitiveCampaigns implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 1200;

    public function __construct(public int $customerId, public bool $autoApply = false) {}

    public function uniqueId(): string
    {
        return (string) $this->customerId;
    }

    public function handle(CampaignOptimizationAgent $agent, CompetitiveRecommendationService $actions): void
    {
        $key = 'competitive_review:'.$this->customerId;
        Cache::put($key, ['status' => 'running'], now()->addHour());
        $reviewed = 0;
        $proposed = 0;
        $errors = 0;
        foreach (Campaign::where('customer_id', $this->customerId)->serving()->withDeployedPlatforms()->get() as $campaign) {
            try {
                $analysis = $agent->analyze($campaign);
                $reviewed++;
                foreach ($analysis['recommendations'] ?? [] as $rec) {
                    if (($rec['source'] ?? null) === 'competitor') {
                        $record = $actions->record($campaign, $rec, $this->autoApply && (bool) ($rec['auto_apply_eligible'] ?? false));
                        $proposed += (int) $record->wasRecentlyCreated;
                    }
                }
            } catch (\Throwable $e) {
                report($e);
                $errors++;
            }
        }
        Cache::put($key, ['status' => $errors ? 'partial' : 'completed', 'reviewed' => $reviewed,
            'proposed' => $proposed, 'errors' => $errors, 'completed_at' => now()->toIso8601String()], now()->addDays(7));
    }

    public function failed(\Throwable $e): void
    {
        Cache::put('competitive_review:'.$this->customerId, ['status' => 'failed'], now()->addDays(7));
    }
}
