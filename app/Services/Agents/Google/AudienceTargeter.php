<?php

namespace App\Services\Agents\Google;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\ExecutionResult;
use App\Services\GoogleAds\CommonServices\AddAdGroupCriterion;
use App\Services\GoogleAds\CommonServices\SearchAudience;
use Illuminate\Support\Facades\Log;

/**
 * Attaches audience signals to a search ad group.
 */
class AudienceTargeter
{
    public function __construct(protected Customer $customer) {}

    public function addAudienceTargeting(string $customerId, string $adGroupResourceName, Strategy $strategy, ExecutionResult $result): void
    {
        $targetingConfig = $strategy->targetingConfig;
        if (! $targetingConfig) {
            return;
        }

        $searchAudienceService = new SearchAudience($this->customer);
        $addCriterionService = new AddAdGroupCriterion($this->customer);

        $audiences = [];
        // Merge interests and behaviors
        if (! empty($targetingConfig->interests)) {
            $audiences = array_merge($audiences, $targetingConfig->interests);
        }
        if (! empty($targetingConfig->behaviors)) {
            $audiences = array_merge($audiences, $targetingConfig->behaviors);
        }

        foreach ($audiences as $audienceKeyword) {
            try {
                // Search for the audience ID
                $foundAudiences = ($searchAudienceService)($customerId, $audienceKeyword);

                if (empty($foundAudiences)) {
                    Log::warning("GoogleAdsExecutionAgent: No audience found for keyword '{$audienceKeyword}'");

                    continue;
                }

                // Pick the first match
                $bestMatch = $foundAudiences[0];
                $audienceResourceName = $bestMatch['id'];

                Log::info("GoogleAdsExecutionAgent: Found audience for '{$audienceKeyword}'", [
                    'name' => $bestMatch['name'],
                    'id' => $audienceResourceName,
                ]);

                // Determine type based on resource name
                if (strpos($audienceResourceName, 'userInterests') !== false) {
                    $type = 'USER_INTEREST';
                    $key = 'userInterestId';
                } else {
                    $type = 'AUDIENCE';
                    $key = 'audienceId';
                }

                // Add to Ad Group
                $criterionResourceName = ($addCriterionService)($customerId, $adGroupResourceName, [
                    'type' => $type,
                    $key => $audienceResourceName,
                ]);

                if ($criterionResourceName) {
                    $result->addPlatformId('audience', $criterionResourceName);
                }

            } catch (\Exception $e) {
                $result->addWarning("Failed to add audience targeting for '{$audienceKeyword}': ".$e->getMessage());
            }
        }
    }

    /**
     * Get final URL from campaign, strategy, or plan
     */
}
