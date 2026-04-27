<?php

namespace App\Services\Agents;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Notifications\CampaignStatusUpdated;
use App\Services\GoogleAds\CommonServices\GetCampaignPerformance;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBiddingStrategy;
use App\Support\Json;
use Illuminate\Support\Facades\Log;

/**
 * Automatically graduates campaigns up the bidding strategy ladder as
 * conversion data matures, unlocking smarter Google Smart Bidding.
 *
 * Performance Max path (default for new campaigns with assets):
 *   MAXIMIZE_CONVERSIONS → TARGET_CPA    (≥30 conversions / 30 days)
 *   TARGET_CPA           → TARGET_ROAS   (≥100 conversions / 30 days + stable CPA σ<20%)
 *
 * Legacy Search path (campaigns without assets):
 *   MANUAL_CPC    → ENHANCED_CPC  (≥30 conversions / 30 days)
 *   ENHANCED_CPC  → TARGET_CPA    (≥50 conversions / 30 days + stable CPA σ<20%)
 *   TARGET_CPA    → TARGET_ROAS   (≥100 conversions / 30 days + stable CPA σ<20%)
 *
 * Thresholds are configured in config/optimization.php.
 * Target CPA is set to 90% of the current average (stretch but achievable).
 * Target ROAS is set to 90% of the current average ROAS (conservative start).
 */
class BiddingStrategyProgressionAgent
{
    private function thresholds(): array
    {
        $cfg = config('optimization.bidding_strategy.thresholds', []);

        return [
            'MAXIMIZE_CONVERSIONS' => ['min_conversions' => $cfg['MAXIMIZE_CONVERSIONS'] ?? 30,  'next' => 'TARGET_CPA',   'stability_check' => false],
            'MANUAL_CPC'           => ['min_conversions' => $cfg['MANUAL_CPC']           ?? 30,  'next' => 'ENHANCED_CPC', 'stability_check' => false],
            'ENHANCED_CPC'         => ['min_conversions' => $cfg['ENHANCED_CPC']         ?? 50,  'next' => 'TARGET_CPA',   'stability_check' => true],
            'TARGET_CPA'           => ['min_conversions' => $cfg['TARGET_CPA']           ?? 100, 'next' => 'TARGET_ROAS',  'stability_check' => true],
        ];
    }

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

        $biddingData = Json::safeDecode($strategy->bidding_strategy) ?? (array) $strategy->bidding_strategy;

        // Strategy name is stored under 'name' (from AI generation) or legacy keys.
        // Normalise to uppercase and strip camelCase variants (e.g. "MaximizeConversions" → "MAXIMIZE_CONVERSIONS").
        $rawName = $biddingData['name'] ?? $biddingData['bid_strategy'] ?? $biddingData['bidding_strategy_type'] ?? 'MANUAL_CPC';
        $currentStrategy = strtoupper(preg_replace('/([a-z])([A-Z])/', '$1_$2', $rawName));

        $thresholds = $this->thresholds();

        if (!array_key_exists($currentStrategy, $thresholds)) {
            return ['skipped' => true, 'reason' => "Strategy {$currentStrategy} not in progression ladder"];
        }

        $threshold   = $thresholds[$currentStrategy];
        $customerId  = $customer->cleanGoogleCustomerId();
        $perfService = new GetCampaignPerformance($customer);
        $perf        = ($perfService)($customerId, $campaign->google_ads_campaign_id, 'LAST_30_DAYS');

        if (!$perf) {
            return ['skipped' => true, 'reason' => 'no performance data'];
        }

        $conversions = $perf['conversions'] ?? 0;

        if ($conversions < $threshold['min_conversions']) {
            Log::info("BiddingStrategyProgressionAgent: Not enough conversions for graduation", [
                'campaign_id' => $campaign->id,
                'current'     => $currentStrategy,
                'conversions' => $conversions,
                'needed'      => $threshold['min_conversions'],
            ]);
            return ['skipped' => true, 'reason' => "Need {$threshold['min_conversions']} conversions, have {$conversions}"];
        }

        // Stability check gates any graduation that involves setting a CPA target.
        // Graduating with a volatile CPA means Google picks an unrealistic target.
        if ($threshold['stability_check'] && !$this->hasStableCpa($campaign)) {
            return ['skipped' => true, 'reason' => 'CPA not stable enough for next strategy'];
        }

        $nextStrategy = $threshold['next'];
        $targetCpa    = null;
        $targetRoas   = null;
        $cpaMult      = config('optimization.bidding_strategy.cpa_target_multiplier', 0.9);
        $roasMult     = config('optimization.bidding_strategy.roas_target_multiplier', 0.9);

        if ($nextStrategy === 'TARGET_CPA' && ($perf['cost_per_conversion'] ?? 0) > 0) {
            $targetCpa = round($perf['cost_per_conversion'] * $cpaMult, 2);
        }

        if ($nextStrategy === 'TARGET_ROAS' && ($perf['cost_micros'] ?? 0) > 0 && ($perf['conversions'] ?? 0) > 0) {
            $currentRoas = isset($perf['conversion_value']) && $perf['cost_micros'] > 0
                ? ($perf['conversion_value'] * 1_000_000) / $perf['cost_micros']
                : 2.0;
            $targetRoas = round($currentRoas * $roasMult, 2);
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
        if ($targetCpa)  $biddingData['target_cpa']  = $targetCpa;
        if ($targetRoas) $biddingData['target_roas'] = $targetRoas;

        $strategy->update(['bidding_strategy' => $biddingData]);

        AgentActivity::record(
            'bidding',
            'strategy_graduated',
            "Graduated \"{$campaign->name}\" from {$currentStrategy} to {$nextStrategy}",
            $campaign->customer_id,
            $campaign->id,
            [
                'from'        => $currentStrategy,
                'to'          => $nextStrategy,
                'conversions' => $conversions,
                'target_cpa'  => $targetCpa,
                'target_roas' => $targetRoas,
            ]
        );

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

    private function hasStableCpa(Campaign $campaign): bool
    {
        // Compare last 14 days CPA vs prior 14 days CPA — stable means <20% variance
        $customer    = $campaign->customer;
        $customerId  = $customer->cleanGoogleCustomerId();
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
