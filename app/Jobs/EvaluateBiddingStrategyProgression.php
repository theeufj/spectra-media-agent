<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Services\Agents\BiddingStrategyProgressionAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Weekly job that evaluates all active Google Ads campaigns and graduates
 * their bidding strategy when conversion data thresholds are met.
 *
 * Schedule: Tuesdays at 05:00.
 */
class EvaluateBiddingStrategyProgression implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries   = 2;
    public $timeout = 600;

    public function handle(BiddingStrategyProgressionAgent $agent): void
    {
        $lock = Cache::lock('job:bidding_progression', 3600);
        if (!$lock->get()) {
            Log::info('EvaluateBiddingStrategyProgression: Already running, skipping');
            return;
        }

        try {
            $this->run($agent);
        } finally {
            $lock->release();
        }
    }

    private function run(BiddingStrategyProgressionAgent $agent): void
    {
        $campaigns = Campaign::with(['customer', 'strategies'])
            ->where('status', 'active')
            ->whereNotNull('google_ads_campaign_id')
            ->get();

        $summary = ['evaluated' => 0, 'graduated' => 0, 'reverted' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($campaigns as $campaign) {
            try {
                $result = $agent->evaluate($campaign);

                $summary['evaluated']++;

                if ($result['skipped'] ?? false) {
                    $summary['skipped']++;
                } elseif ($result['success'] ?? false) {
                    $summary['graduated']++;
                    Log::info("EvaluateBiddingStrategyProgression: Graduated {$campaign->id}", $result);
                }
            } catch (\Exception $e) {
                $summary['errors']++;
                Log::error("EvaluateBiddingStrategyProgression: Error for campaign {$campaign->id}: " . $e->getMessage());
            }

            // Check for regression independently — a campaign that was just skipped for graduation
            // may still be eligible to revert if it is underperforming its current target.
            try {
                $regressResult = $agent->checkForRegression($campaign);

                if ($regressResult['success'] ?? false) {
                    $summary['reverted']++;
                    Log::info("EvaluateBiddingStrategyProgression: Reverted {$campaign->id}", $regressResult);
                }
            } catch (\Exception $e) {
                Log::error("EvaluateBiddingStrategyProgression: Regression check error for campaign {$campaign->id}: " . $e->getMessage());
            }
        }

        Log::info('EvaluateBiddingStrategyProgression: Complete', $summary);
    }


    public function failed(\Throwable $exception): void
    {
        Log::error('EvaluateBiddingStrategyProgression failed: ' . $exception->getMessage());
    }
}
