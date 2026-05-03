<?php

namespace App\Services\Agents;

use App\Models\AgentActivity;
use App\Models\Campaign;
use App\Notifications\CampaignStatusUpdated;
use App\Services\Agents\AdaptiveThresholds;
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

    /**
     * Check whether a graduated campaign has regressed below its target thresholds.
     * If so, revert it one step down the ladder.
     *
     * Regression thresholds:
     *   TARGET_CPA  → actual CPA > target × (1 + cpa_tolerance) → revert to MAXIMIZE_CONVERSIONS
     *   TARGET_ROAS → actual ROAS < target × (1 - roas_tolerance) → revert to TARGET_CPA
     *   Tolerances are computed per-campaign from historical CPA variance via AdaptiveThresholds.
     */
    public function checkForRegression(Campaign $campaign): array
    {
        $customer = $campaign->customer;

        if (!$customer?->google_ads_customer_id || !$campaign->google_ads_campaign_id) {
            return ['skipped' => true];
        }

        $strategy = $campaign->strategies()->latest()->first();
        if (!$strategy) {
            return ['skipped' => true, 'reason' => 'no strategy'];
        }

        $biddingData     = Json::safeDecode($strategy->bidding_strategy) ?? (array) $strategy->bidding_strategy;
        $rawName         = $biddingData['name'] ?? $biddingData['bid_strategy'] ?? $biddingData['bidding_strategy_type'] ?? '';
        $currentStrategy = strtoupper(preg_replace('/([a-z])([A-Z])/', '$1_$2', $rawName));

        // Only revert strategies that have a numeric target they can fail against
        if (!in_array($currentStrategy, ['TARGET_CPA', 'TARGET_ROAS'], true)) {
            return ['skipped' => true, 'reason' => 'strategy not regression-eligible'];
        }

        // Only run regression check if at least 14 days have passed since graduation
        $lastGraduation = AgentActivity::where('campaign_id', $campaign->id)
            ->where('action', 'strategy_graduated')
            ->latest()
            ->first();

        if (!$lastGraduation || $lastGraduation->created_at->gt(now()->subDays(14))) {
            return ['skipped' => true, 'reason' => 'too soon after graduation'];
        }

        $customerId  = $customer->cleanGoogleCustomerId();
        $perfService = new GetCampaignPerformance($customer);
        $perf        = ($perfService)($customerId, $campaign->google_ads_campaign_id, 'LAST_14_DAYS');

        if (!$perf) {
            return ['skipped' => true, 'reason' => 'no performance data'];
        }

        // Use per-campaign tolerance derived from historical CPA/ROAS variance.
        // Stable campaigns revert sooner; volatile campaigns get a wider band.
        $thresholds    = AdaptiveThresholds::forCampaign($campaign);
        $cpaTolerance  = $thresholds['cpa_regression_tolerance'];   // e.g. 0.18 for stable, 0.35 for volatile
        $roasTolerance = $thresholds['roas_regression_tolerance'];

        $revertTo        = null;
        $reason          = null;
        $revertTargetCpa = null;

        if ($currentStrategy === 'TARGET_CPA') {
            $targetCpa = (float) ($biddingData['target_cpa'] ?? 0);
            $actualCpa = (float) ($perf['cost_per_conversion'] ?? 0);

            if ($targetCpa > 0 && $actualCpa > $targetCpa * (1 + $cpaTolerance)) {
                $revertTo = 'MAXIMIZE_CONVERSIONS';
                $reason   = sprintf(
                    'CPA $%.2f exceeds target $%.2f by >%.0f%% (campaign tolerance)',
                    $actualCpa, $targetCpa, $cpaTolerance * 100
                );
            }
        }

        if ($currentStrategy === 'TARGET_ROAS') {
            $targetRoas = (float) ($biddingData['target_roas'] ?? 0);
            $actualRoas = isset($perf['conversion_value'], $perf['cost_micros']) && $perf['cost_micros'] > 0
                ? ($perf['conversion_value'] * 1_000_000) / $perf['cost_micros']
                : 0;

            if ($targetRoas > 0 && $actualRoas > 0 && $actualRoas < $targetRoas * (1 - $roasTolerance)) {
                $revertTo = 'TARGET_CPA';
                $revertTargetCpa = isset($perf['cost_per_conversion']) && $perf['cost_per_conversion'] > 0
                    ? round($perf['cost_per_conversion'] * 1.10, 2)
                    : null;
                $reason = sprintf(
                    'ROAS %.2f below target %.2f by >%.0f%% (campaign tolerance)',
                    $actualRoas, $targetRoas, $roasTolerance * 100
                );
            }
        }

        if (!$revertTo) {
            return ['skipped' => true, 'reason' => 'no regression detected'];
        }

        $updateService = new UpdateCampaignBiddingStrategy($customer);
        $targetCpaForRevert = $revertTargetCpa ?? null;

        $success = ($updateService)(
            $customerId,
            $campaign->google_ads_campaign_id,
            $revertTo,
            $targetCpaForRevert,
            null
        );

        if (!$success) {
            return ['success' => false, 'error' => 'API call to revert strategy failed'];
        }

        $biddingData['bid_strategy'] = $revertTo;
        unset($biddingData['target_roas']);
        if ($targetCpaForRevert) {
            $biddingData['target_cpa'] = $targetCpaForRevert;
        }

        $strategy->update(['bidding_strategy' => $biddingData]);

        AgentActivity::record(
            'bidding',
            'strategy_reverted',
            "Reverted \"{$campaign->name}\" from {$currentStrategy} to {$revertTo}: {$reason}",
            $campaign->customer_id,
            $campaign->id,
            [
                'from'            => $currentStrategy,
                'to'              => $revertTo,
                'reason'          => $reason,
                'target_cpa'      => $targetCpaForRevert,
            ]
        );

        Log::info("BiddingStrategyProgressionAgent: Reverted campaign due to regression", [
            'campaign_id' => $campaign->id,
            'from'        => $currentStrategy,
            'to'          => $revertTo,
            'reason'      => $reason,
        ]);

        return [
            'success'    => true,
            'from'       => $currentStrategy,
            'to'         => $revertTo,
            'reason'     => $reason,
            'target_cpa' => $targetCpaForRevert ?? null,
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
