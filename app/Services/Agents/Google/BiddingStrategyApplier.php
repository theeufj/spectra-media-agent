<?php

namespace App\Services\Agents\Google;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionResult;
use Illuminate\Support\Facades\Log;

/**
 * Chooses and applies a Google Ads bidding strategy based on available
 * conversion volume.
 */
class BiddingStrategyApplier
{
    public function __construct(protected Customer $customer) {}

    public function applyBiddingStrategy(
        string $customerId,
        string $campaignResourceName,
        Strategy $strategy,
        ExecutionResult $result
    ): void {
        $biddingData = $strategy->bidding_strategy ?? [];
        $strategyName = strtoupper($biddingData['name'] ?? '');

        if (empty($strategyName) || $strategyName === 'MANUAL_CPC') {
            return;
        }

        // Smart Bidding strategies that need conversion data to work effectively.
        // Google minimums: Target CPA needs 30+/month, Target ROAS needs 50+/month.
        $targetRoasStrategies = ['TARGET_ROAS', 'TARGETROAS'];
        $targetCpaStrategies = ['TARGET_CPA', 'TARGETCPA'];
        $needsConversionData = in_array($strategyName, array_merge($targetRoasStrategies, $targetCpaStrategies), true);

        if ($needsConversionData) {
            $conversionCount = $this->getConversionCountForCustomer($customerId);
            $minRequired = in_array($strategyName, $targetRoasStrategies, true) ? 50 : 30;

            if ($conversionCount < $minRequired) {
                Log::info("GoogleAdsExecutionAgent: Downgrading {$strategyName} to MaximizeConversions — only {$conversionCount} conversions recorded (need {$minRequired})", [
                    'campaign' => $campaignResourceName,
                ]);
                $result->addWarning(
                    'bidding_strategy_downgraded',
                    "Bidding strategy changed from {$strategyName} to Maximize Conversions — account needs {$minRequired}+ conversions/month before this strategy is effective. Will auto-upgrade via BiddingStrategyProgressionAgent."
                );
                $strategyName = 'MAXIMIZE_CONVERSIONS';
            }
        }

        $mappedStrategy = match ($strategyName) {
            'TARGET_CPA', 'TARGETCPA' => 'TARGET_CPA',
            'TARGET_ROAS', 'TARGETROAS' => 'TARGET_ROAS',
            'MAXIMIZE_CONVERSIONS' => 'MAXIMIZE_CONVERSIONS',
            'MAXIMIZE_CLICKS' => 'MAXIMIZE_CLICKS',
            default => null,
        };

        if (! $mappedStrategy) {
            return;
        }

        $targetCpa = isset($biddingData['parameters']['targetCpaMicros'])
            ? $biddingData['parameters']['targetCpaMicros'] / 1_000_000
            : null;
        $targetRoas = $biddingData['parameters']['targetRoas'] ?? null;

        $updateService = new \App\Services\GoogleAds\CommonServices\UpdateCampaignBiddingStrategy($this->customer);
        $success = $updateService($customerId, $campaignResourceName, $mappedStrategy, $targetCpa, $targetRoas);

        if ($success) {
            Log::info("GoogleAdsExecutionAgent: Applied bidding strategy {$mappedStrategy} to campaign {$campaignResourceName}");
        } else {
            $result->addWarning('bidding_strategy_not_applied', "Could not apply {$mappedStrategy} bidding strategy — campaign will run on Manual CPC until next optimisation cycle.");
        }
    }

    /**
     * Get 30-day conversion count for a Google Ads customer account.
     */
    public function getConversionCountForCustomer(string $customerId): int
    {
        try {
            $conversionService = new \App\Services\GoogleAds\ConversionTrackingService($this->customer);

            return $conversionService->getConversionCountLast30Days($customerId);
        } catch (\Exception $e) {
            Log::warning('GoogleAdsExecutionAgent: Could not fetch conversion count, assuming 0', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Execute Display campaign deployment
     */
}
