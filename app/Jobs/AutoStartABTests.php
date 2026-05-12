<?php

namespace App\Jobs;

use App\Models\ABTest;
use App\Models\AdCopy;
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
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;
    public $timeout = 300;

    public function handle(ABTestingAgent $agent): void
    {
        Log::info('AutoStartABTests: Starting daily auto-test pass');

        $campaigns = Campaign::with(['strategies.adCopies'])
            ->where('primary_status', 'ELIGIBLE')
            ->where(fn($q) => $q->whereNotNull('google_ads_campaign_id')
                                ->orWhereNotNull('facebook_ads_campaign_id'))
            ->get();

        $started = 0;
        $skipped = 0;

        foreach ($campaigns as $campaign) {
            foreach ($campaign->strategies as $strategy) {
                // Only test deployed strategies
                if (!in_array($strategy->deployment_status, ['deployed', 'live', 'active'])) {
                    $skipped++;
                    continue;
                }

                // Skip if a test is already running for this strategy
                $hasRunningTest = ABTest::where('strategy_id', $strategy->id)
                    ->where('status', ABTest::STATUS_RUNNING)
                    ->exists();

                if ($hasRunningTest) {
                    $skipped++;
                    continue;
                }

                // Find ad copy for this strategy
                $adCopy = $strategy->adCopies
                    ->where('platform', $strategy->platform)
                    ->first()
                    ?? $strategy->adCopies->first();

                if (!$adCopy) {
                    $skipped++;
                    continue;
                }

                $headlines = array_values(array_filter($adCopy->headlines ?? []));
                $descriptions = array_values(array_filter($adCopy->descriptions ?? []));

                // Need at least 4 headlines to create a meaningful split
                if (count($headlines) < 4) {
                    $skipped++;
                    continue;
                }

                try {
                    // Split headlines evenly: first half → A, second half → B
                    $mid = (int) ceil(count($headlines) / 2);
                    $variantA = array_slice($headlines, 0, $mid);
                    $variantB = array_slice($headlines, $mid);

                    $agent->createTest($strategy, ABTest::TYPE_HEADLINE, [
                        [
                            'label'   => 'Variant A',
                            'content' => implode(' | ', $variantA),
                            'meta'    => ['headlines' => $variantA, 'descriptions' => $descriptions],
                        ],
                        [
                            'label'   => 'Variant B',
                            'content' => implode(' | ', $variantB),
                            'meta'    => ['headlines' => $variantB, 'descriptions' => $descriptions],
                        ],
                    ]);

                    AgentActivity::record(
                        'creative',
                        'ab_test_started',
                        'Started headline A/B test for "' . $campaign->name . '" (' . $strategy->platform . ')',
                        $campaign->customer_id,
                        $campaign->id,
                        ['strategy_id' => $strategy->id, 'headlines_a' => count($variantA), 'headlines_b' => count($variantB)]
                    );

                    Log::info('AutoStartABTests: Created headline test', [
                        'campaign_id'  => $campaign->id,
                        'strategy_id'  => $strategy->id,
                        'platform'     => $strategy->platform,
                        'variant_a'    => count($variantA),
                        'variant_b'    => count($variantB),
                    ]);

                    $started++;
                } catch (\Exception $e) {
                    Log::error('AutoStartABTests: Failed to create test for strategy ' . $strategy->id . ': ' . $e->getMessage());
                }
            }
        }

        Log::info('AutoStartABTests: Completed', ['started' => $started, 'skipped' => $skipped]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('AutoStartABTests failed: ' . $exception->getMessage());
    }
}
