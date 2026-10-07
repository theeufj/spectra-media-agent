<?php

namespace App\Services\Agents\Google;

use App\Models\Campaign;
use App\Models\Customer;
use App\Models\Strategy;
use App\Services\Agents\AgentIssue;
use App\Services\Agents\ExecutionResult;
use App\Services\GoogleAds\CommonServices\AddAdGroupCriterion;
use App\Services\GoogleAds\CommonServices\SearchAudience;
use App\Services\GoogleAds\ReconcileSearchAudienceObservation;
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

        $audiences = [];
        // Merge interests and behaviors
        if (! empty($targetingConfig->interests)) {
            $audiences = array_merge($audiences, $targetingConfig->interests);
        }
        if (! empty($targetingConfig->behaviors)) {
            $audiences = array_merge($audiences, $targetingConfig->behaviors);
        }

        if ($audiences === []) {
            return;
        }
        $campaignResource = $strategy->reusableGoogleCampaignId();
        if ($customerId !== $this->customer->cleanGoogleCustomerId() || $strategy->campaign->customer_id !== $this->customer->id
            || ! $campaignResource || ! preg_match('#^customers/'.preg_quote($customerId, '#').'/adGroups/\d+$#D', $adGroupResourceName)) {
            $result->addWarning('search_audience_scope_invalid', 'Audience signals were skipped because the ad group account could not be verified.');

            return;
        }
        try {
            $observation = app(ReconcileSearchAudienceObservation::class, ['customer' => $this->customer])->ensureForAdGroup($adGroupResourceName, $campaignResource);
            $result->addMetadata('search_audience_observation', array_merge($observation, ['issues' => array_map(fn (AgentIssue $issue) => $issue->toArray(), AgentIssue::list($observation['issues'] ?? []))]));
            $intentionalTargeting = ($observation['status'] ?? null) === 'not_applicable'
                && ($observation['reason'] ?? null) === 'explicit_audience_targeting_intent';
            if (! ($observation['ready'] ?? false) || (($observation['status'] ?? null) !== 'ready' && ! $intentionalTargeting)) {
                $issues = AgentIssue::list($observation['issues'] ?? []);
                foreach ($issues ?: [new AgentIssue('search_audience_observation_unready', 'Audience signals were skipped because Google has not confirmed Observation mode.')] as $issue) {
                    $result->addWarning($issue->code, $issue->message);
                }

                return;
            }
        } catch (\Throwable $e) {
            report($e);
            $result->addWarning('search_audience_observation_unavailable', 'Audience signals were skipped because Observation could not be verified.');

            return;
        }
        $searchAudienceService = app(SearchAudience::class, ['customer' => $this->customer]);
        $addCriterionService = app(AddAdGroupCriterion::class, ['customer' => $this->customer]);

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

            } catch (\Throwable $e) {
                report($e);
                $result->addWarning('search_audience_signal_failed', 'A Search audience signal could not be added. Review the admin exception dashboard.');
            }
        }
    }

    /**
     * Get final URL from campaign, strategy, or plan
     */
}
