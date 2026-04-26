<?php

namespace App\Services\Agents;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Notifications\CampaignStatusUpdated;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBiddingStrategy;
use Illuminate\Support\Facades\Log;

/**
 * Automatically graduates campaigns up the bidding strategy ladder as
 * conversion data matures, unlocking smarter Google Smart Bidding.
 *
 * Ladder:
 *   MANUAL_CPC  →  ENHANCED_CPC  (≥30 conversions / 30 days)
 *   ENHANCED_CPC → TARGET_CPA    (≥50 conversions / 30 days)
 *   TARGET_CPA   → TARGET_ROAS   (≥100 conversions / 30 days + stable CPA σ<20%)
 *
 * Target CPA is set to 90% of the current average (stretch but achievable).
 * Target ROAS is set to 90% of the current average ROAS (conservative start).
 */
class BiddingStrategyProgressionAgent
{
    private const THRESHOLDS = [
        'MANUAL_CPC'   => ['min_conversions' => 30, 'next' => 'ENHANCED_CPC'],
        'ENHANCED_CPC' => ['min_conversions' => 50, 'next' => 'TARGET_CPA'],
        'TARGET_CPA'   => ['min_conversions' => 100, 'next' => 'TARGET_ROAS'],
    ];

    public function evaluate(Campaign $campaign): array
    {
        $customer = $campaign->customer;

        if (!$customer?->google_ads_customer_id || !$campaign->google_ads_campaign_id) {
            return ['skipped' => true];
        }

        $strategy = $campaign->strategies()->latest()->first();

        if (!$strategy) {
            return ['skipped' => true, 'reason' => 'no strategy'];
        }

        $biddingData    = is_string($strategy->bidding_strategy)
            ? json_decode($strategy->bidding_strategy, true)
            : (array) $strategy->bidding_strategy;

        $currentStrategy = strtoupper($biddingData['bid_strategy'] ?? $biddingData['bidding_strategy_type'] ?? 'MANUAL_CPC');

        if (!array_key_exists($currentStrategy, self::THRESHOLDS)) {
            return ['skipped' => true, 'reason' => "Strategy {$currentStrategy} not in progression ladder"];
        }

        $threshold   = self::THRESHOLDS[$currentStrategy];
        $customerId  = str_replace('-', '', $customer->google_ads_customer_id);
        $perfService = new GetCampaignPerformance($customer);
        $perf        = ($perfService)($customerId, $campaign->google_ads_campaign_id, 'LAST_30_DAYS');

        if (!$perf) {
            return ['skipped' => true, 'reason' => 'no performance data'];
        }

        $conversions = $perf['conversions'] ?? 0;

        if ($conversions < $threshold['min_conversions']) {
            Log::info("BiddingStrategyProgressionAgent: Not enough conversions for graduation", [
                'campaign_id'     => $campaign->id,
                'current'         => $currentStrategy,
                'conversions'     => $conversions,
                'needed'          => $threshold['min_conversions'],
            ]);
            return ['skipped' => true, 'reason' => "Need {$threshold['min_conversions']} conversions, have {$conversions}"];
        }

        // Extra stability check for TARGET_CPA → TARGET_ROAS
        if ($currentStrategy === 'TARGET_CPA') {
            if (!$this->hasStagleCpa($campaign)) {
                return ['skipped' => true, 'reason' => 'CPA not stable enough for TARGET_ROAS'];
            }
        }

        $nextStrategy = $threshold['next'];
        $targetCpa    = null;
        $targetRoas   = null;

        if ($nextStrategy === 'TARGET_CPA' && ($perf['cost_per_conversion'] ?? 0) > 0) {
            // Set target at 90% of current average CPA (stretch goal)
            $targetCpa = round($perf['cost_per_conversion'] * 0.9, 2);
        }

        if ($nextStrategy === 'TARGET_ROAS' && ($perf['cost_micros'] ?? 0) > 0 && ($perf['conversions'] ?? 0) > 0) {
            // Infer current ROAS from conversion value if available, or use a safe default
            $currentRoas = isset($perf['conversion_value']) && $perf['cost_micros'] > 0
                ? ($perf['conversion_value'] * 1_000_000) / $perf['cost_micros']
                : 2.0;
            $targetRoas = round($currentRoas * 0.9, 2);
        }

        $updateService = new UpdateCampaignBiddingStrategy($customer);
        $success = ($updateService)(
            $customerId,
            $campaign->google_ads_campaign_id,
            $nextStrategy,
            $targetCpa,
            $targetRoas
        );

        if (!$success) {
            return ['success' => false, 'error' => "API call to update strategy failed"];
        }

        // Update strategy model
        $biddingData['bid_strategy'] = $nextStrategy;
        if ($targetCpa)   $biddingData['target_cpa']  = $targetCpa;
        if ($targetRoas)  $biddingData['target_roas'] = $targetRoas;

        $strategy->update(['bidding_strategy' => $biddingData]);

        AgentActivity::record(
            'bidding',
            'strategy_graduated',
            "Graduated \"{$campaign->name}\" from {$currentStrategy} to {$nextStrategy}",
            $campaign->customer_id,
            $campaign->id,
            [
                'from'          => $currentStrategy,
                'to'            => $nextStrategy,
                'conversions'   => $conversions,
                'target_cpa'    => $targetCpa,
                'target_roas'   => $targetRoas,
            ]
        );

        // Notify the customer's primary user
        $user = $customer->users()->first();
        if ($user) {
            try {
                $user->notify(new CampaignStatusUpdated($campaign));
            } catch (\Exception $e) {
                Log::warning('BiddingStrategyProgressionAgent: Failed to notify user', ['error' => $e->getMessage()]);
            }
        }

        Log::info("BiddingStrategyProgressionAgent: Graduated campaign", [
            'campaign_id' => $campaign->id,
            'from'        => $currentStrategy,
            'to'          => $nextStrategy,
        ]);

        return [
            'success'     => true,
            'from'        => $currentStrategy,
            'to'          => $nextStrategy,
            'target_cpa'  => $targetCpa,
            'target_roas' => $targetRoas,
        ];
    }

    private function hasStagleCpa(Campaign $campaign): bool
    {
        // Compare last 14 days CPA vs prior 14 days CPA — stable means <20% variance
        $customer    = $campaign->customer;
        $customerId  = str_replace('-', '', $customer->google_ads_customer_id);
        $perfService = new GetCampaignPerformance($customer);

        $recent = ($perfService)($customerId, $campaign->google_ads_campaign_id, 'LAST_14_DAYS');
        $prior  = ($perfService)($customerId, $campaign->google_ads_campaign_id, 'LAST_30_DAYS');

        if (!$recent || !$prior) {
            return false;
        }

        $recentCpa = $recent['cost_per_conversion'] ?? 0;
        $priorCpa  = $prior['cost_per_conversion']  ?? 0;

        if ($priorCpa <= 0) {
            return false;
        }

        $variance = abs($recentCpa - $priorCpa) / $priorCpa;
        return $variance < 0.20;
    }
}
