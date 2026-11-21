<?php

namespace App\Jobs;

use App\Models\ABTest;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Services\Agents\ABTestingAgent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Runs daily. For every live strategy that has ad copy but no running A/B test,
 * automatically creates a headline split test by dividing the existing headlines
 * into two variant groups. Results are evaluated by EvaluateABTests.
 *
 * Requires at least 4 headlines to split meaningfully (2 per variant).
 */
class AutoStartABTests implements ShouldQueue
{
    use \App\Jobs\Concerns\RecordsAgentRun, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 300;

    public function handle(ABTestingAgent $agent): void
    {
        Log::info('AutoStartABTests: Starting daily auto-test pass');
        $runStart = $this->startRun();

        $campaigns = Campaign::with(['strategies.adCopies'])
            ->withServingChannel()
            ->where(fn ($q) => $q->whereNotNull('google_ads_campaign_id')
                ->orWhereNotNull('facebook_ads_campaign_id'))
            ->get();

        $started = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($campaigns as $campaign) {
            foreach ($campaign->strategies as $strategy) {
                // Only test deployed strategies. This previously looked for
                // ['deployed', 'live', 'active'] — 'live' and 'active' are not values
                // this column holds, and 'verified' (the terminal success state) was
                // missing, so the best-deployed strategies were skipped and no test
                // was ever started.
                if (! $strategy->isDeployed()) {
                    $skipped++;

                    continue;
                }

                // Skip if a test is already running for this strategy
                $hasRunningTest = ABTest::where('strategy_id', $strategy->id)
                    ->whereIn('status', [ABTest::STATUS_RUNNING, ABTest::STATUS_SIGNIFICANT])
                    ->exists();

                if ($hasRunningTest) {
                    $skipped++;

                    continue;
                }

                try {
                    $variants = app(\App\Services\Agents\CreativeTreatmentMetrics::class)->read(
                        $strategy, now()->subDays(7)->toDateString(), now()->toDateString());
                    // Stable selection avoids choosing variants based on their past outcome rate.
                    ksort($variants, SORT_STRING);
                    $variants = array_slice(array_values($variants), 0, 2);
                    if (count($variants) < 2) {
                        $skipped++;

                        continue;
                    }
                    $test = $agent->createTest($strategy, ABTest::TYPE_HEADLINE, $variants);
                    AgentActivity::record('creative', 'ab_test_started', 'Comparing the primary outcome for two published treatments.',
                        $campaign->customer_id, $campaign->id, ['strategy_id' => $strategy->id, 'test_id' => $test->id]);
                    $started++;
                } catch (\Throwable $e) {
                    report($e);
                    $errors++;
                    Log::error('AutoStartABTests: Failed to create test for strategy '.$strategy->id.': '.$e->getMessage());
                }
            }
        }

        Log::info('AutoStartABTests: Completed', ['started' => $started, 'skipped' => $skipped]);

        $this->finishRun($runStart, actions: $started, errors: $errors, scope: $campaigns->count().' campaigns');
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('AutoStartABTests failed: '.$exception->getMessage());
        $this->recordRunFailure($exception);
    }
}
