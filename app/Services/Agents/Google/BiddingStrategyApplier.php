<?php

namespace App\Services\Agents\Google;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionResult;
use App\Services\GoogleAds\CommonServices\UpdateCampaignBiddingStrategy;
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
        $strategyName = strtoupper(preg_replace('/(?<=[a-z])(?=[A-Z])/', '_', $biddingData['name'] ?? '') ?? '');

        if (empty($strategyName) || $strategyName === 'MANUAL_CPC') {
            return;
        }

        // Our launch policy requires conversion evidence before adding CPA or ROAS targets.
        // These thresholds are application policy, not Google eligibility requirements.
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
                    "Selected Maximize Conversions because our launch policy requires {$minRequired}+ recorded conversions in 30 days before adding this target. Agents will reassess bidding as campaign evidence becomes available."
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
        $result->addMetadata('expected_bidding', match ($mappedStrategy) {
            'TARGET_CPA' => 'MAXIMIZE_CONVERSIONS',
            'TARGET_ROAS' => 'MAXIMIZE_CONVERSION_VALUE',
            'MAXIMIZE_CLICKS' => 'TARGET_SPEND',
            default => $mappedStrategy,
        });

        $targetCpa = isset($biddingData['parameters']['targetCpaMicros'])
            ? $biddingData['parameters']['targetCpaMicros'] / 1_000_000
            : null;
        $targetRoas = $biddingData['parameters']['targetRoas'] ?? null;

        $ceiling = $biddingData['parameters']['cpcBidCeilingMicros'] ?? $biddingData['cpc_bid_ceiling_micros'] ?? null;
        $ceiling = is_numeric($ceiling) && (int) $ceiling > 0 ? (int) $ceiling : null;

        $updateService = $this->updater();
        $success = $updateService($customerId, $campaignResourceName, $mappedStrategy, $targetCpa, $targetRoas, $ceiling);

        if ($success) {
            Log::info("GoogleAdsExecutionAgent: Applied bidding strategy {$mappedStrategy} to campaign {$campaignResourceName}");
        } else {
            $result->addWarning('bidding_strategy_not_applied', "Could not confirm {$mappedStrategy} bidding in Google. Review the current campaign configuration before assuming the change was applied.");
        }
    }

    protected function updater(): UpdateCampaignBiddingStrategy
    {
        return new UpdateCampaignBiddingStrategy($this->customer);
    }

    /**
     * Get 30-day conversion count for a Google Ads customer account.
     */
    public function getConversionCountForCustomer(string $customerId): int
    {
        try {
            $conversionService = new \App\Services\GoogleAds\ConversionTrackingService($this->customer);

            return $conversionService->getConversionCountLast30Days($customerId);
        } catch (\Throwable $e) {
            report($e);
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
