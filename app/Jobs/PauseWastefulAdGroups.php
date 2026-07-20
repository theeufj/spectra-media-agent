<?php

namespace App\Jobs;

use App\Features\AutoOptimization;
use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Models\Recommendation;
use App\Services\GoogleAds\CommonServices\PauseZeroConversionAdGroups;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

/**
 * Pauses ad groups that spent money over a trailing window without converting.
 * Deterministic (no LLM) and self-reporting: each pause is recorded as an applied
 * Recommendation + AgentActivity so it surfaces in the daily "what we optimised" email.
 */
class PauseWastefulAdGroups implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, \App\Jobs\Concerns\RecordsAgentRun;

    public int $timeout = 600;

    public function handle(): void
    {
        $runStart = $this->startRun();

        $minSpend       = (float) config('optimization.ad_group_pause.min_spend', 50);
        $windowDays     = (int) config('optimization.ad_group_pause.window_days', 30);
        $maxPerCampaign = (int) config('optimization.ad_group_pause.max_per_campaign', 5);

        $paused = 0;
        $errors = 0;
        $scanned = 0;

        $campaigns = Campaign::whereNotNull('google_ads_campaign_id')
            ->whereIn('primary_status', ['ELIGIBLE', 'LEARNING'])
            ->with('customer')
            ->get();

        foreach ($campaigns as $campaign) {
            $customer = $campaign->customer;
            if (!$customer || !$customer->google_ads_customer_id) {
                continue;
            }
            // Same gate as every other auto-applied optimisation.
            if (!Feature::for($customer)->active(AutoOptimization::class)) {
                continue;
            }

            $scanned++;

            try {
                $result = (new PauseZeroConversionAdGroups($customer))
                    ->forCampaign($campaign, $minSpend, $windowDays, $maxPerCampaign);

                foreach ($result['paused'] as $adGroup) {
                    $paused++;
                    $rationale = "No conversions in {$windowDays} days on \${$adGroup['cost']} spend";

                    Recommendation::create([
                        'campaign_id'       => $campaign->id,
                        'type'              => 'AD_GROUP_PAUSE',
                        'target_entity'     => ['ad_group' => $adGroup['name'], 'resource' => $adGroup['resource']],
                        'rationale'         => "Paused ad group \"{$adGroup['name']}\" — {$rationale}",
                        'status'            => 'applied',
                        'requires_approval' => false,
                    ]);

                    AgentActivity::record(
                        'optimization',
                        'ad_group_paused',
                        "Paused ad group \"{$adGroup['name']}\" on \"{$campaign->name}\" — {$rationale}",
                        $campaign->customer_id,
                        $campaign->id,
                        $adGroup
                    );
                }
            } catch (\Exception $e) {
                $errors++;
                Log::error("PauseWastefulAdGroups: failed for campaign {$campaign->id}: " . $e->getMessage());
            }
        }

        Log::info("PauseWastefulAdGroups: paused {$paused} ad group(s) across {$scanned} campaigns", [
            'errors' => $errors,
        ]);

        $this->finishRun($runStart, actions: $paused, errors: $errors, scope: "{$scanned} campaigns");
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('PauseWastefulAdGroups failed: ' . $exception->getMessage(), [
            'exception' => $exception->getTraceAsString(),
        ]);
        $this->recordRunFailure($exception);
    }
}
