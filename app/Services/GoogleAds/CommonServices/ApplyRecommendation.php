<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\Campaigns\CampaignSpendGuardrails;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V26\Enums\RecommendationTypeEnum\RecommendationType;
use Google\Ads\GoogleAds\V26\Services\ApplyRecommendationOperation;
use Google\Ads\GoogleAds\V26\Services\ApplyRecommendationRequest;

/** Google suggestions never bypass the campaign's current approval policy. */
class ApplyRecommendation extends BaseGoogleAdsService
{
    /** @param string[] $resourceNames */
    public function __invoke(string $customerId, array $resourceNames): bool
    {
        if ($resourceNames === []) {
            return true;
        }
        if ($this->customer->cleanGoogleCustomerId() !== $customerId || count($resourceNames) > 100
            || count(array_unique($resourceNames)) !== count($resourceNames)) {
            return false;
        }
        foreach ($resourceNames as $resource) {
            if (! preg_match('#^customers/'.preg_quote($customerId, '#').'/recommendations/[A-Za-z0-9_~.\-]+$#D', $resource)) {
                return false;
            }
        }
        try {
            $recommendations = $this->readRecommendations($customerId, $resourceNames);
            if (count($recommendations) !== count($resourceNames)
                || array_diff($resourceNames, array_column($recommendations, 'resource_name')) !== []) {
                return false;
            }
            foreach ($recommendations as $recommendation) {
                $campaign = CampaignSpendGuardrails::forGoogleResource($customerId, $recommendation['campaign_resource'] ?? '');
                // These automatic settings are not individually priced proposals.
                // A bounded owner policy therefore holds them for review rather
                // than blindly using Google's recommended CPA or budget.
                if (! $campaign || CampaignSpendGuardrails::automaticChangesSuspended($campaign)
                    || CampaignSpendGuardrails::automationLimits($campaign) !== null
                    || ! in_array($recommendation['type'] ?? null, [RecommendationType::RAISE_TARGET_CPA,
                        RecommendationType::RAISE_TARGET_CPA_BID_TOO_LOW, RecommendationType::OPTIMIZE_AD_ROTATION], true)) {
                    return false;
                }
            }
            if ($this->dryRun) {
                return true; // Google does not expose validate_only for this endpoint.
            }
            $operations = array_map(fn ($name) => new ApplyRecommendationOperation(['resource_name' => $name]), $resourceNames);
            $this->apply($customerId, $operations);

            return true;
        } catch (\Throwable $e) {
            report($e);
            $this->logError('ApplyRecommendation: automatic application could not be verified.', $e);

            return false;
        }
    }

    protected function readRecommendations(string $customerId, array $resources): array
    {
        $this->ensureClient();
        $values = implode("','", $resources); // identifiers are validated before interpolation
        $query = "SELECT recommendation.resource_name, recommendation.type, recommendation.campaign FROM recommendation WHERE recommendation.resource_name IN ('{$values}') AND recommendation.dismissed = FALSE";
        $rows = [];
        foreach ($this->searchQuery($customerId, $query)->iterateAllElements() as $row) {
            $rec = $row->getRecommendation();
            $rows[] = ['resource_name' => $rec->getResourceName(), 'campaign_resource' => $rec->getCampaign(), 'type' => $rec->getType()];
        }

        return $rows;
    }

    protected function apply(string $customerId, array $operations): void
    {
        $this->client->getRecommendationServiceClient()->applyRecommendation(new ApplyRecommendationRequest([
            'customer_id' => $customerId, 'operations' => $operations,
        ]));
    }
}
