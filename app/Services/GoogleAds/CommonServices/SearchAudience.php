<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsRow;
use Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest;

class SearchAudience extends BaseGoogleAdsService
{
    /**
     * Search for audiences (User Interests) by name.
     *
     * @param string $customerId
     * @param string $keyword
     * @return array Array of ['id' => resource_name, 'name' => name, 'type' => taxonomy_type]
     */
    public function __invoke(string $customerId, string $keyword): array
    {
        $this->ensureClient();
        $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();

        // Search for User Interests (Affinity, In-Market)
        // Note: user_interest resource is available for querying.
        $query = "SELECT user_interest.resource_name, user_interest.name, user_interest.taxonomy_type " .
                 "FROM user_interest " .
                 "WHERE user_interest.name LIKE '%$keyword%' " .
                 "AND user_interest.taxonomy_type IN ('AFFINITY', 'IN_MARKET') " .
                 "LIMIT 10";

        $request = new SearchGoogleAdsRequest([
            'customer_id' => $customerId,
            'query' => $query,
        ]);

        try {
            $stream = $googleAdsServiceClient->search($request);

            foreach ($stream->iterateAllElements() as $googleAdsRow) {
                /** @var GoogleAdsRow $googleAdsRow */
                $audiences[] = [
                    'id' => $googleAdsRow->getUserInterest()->getResourceName(),
                    'name' => $googleAdsRow->getUserInterest()->getName(),
                    'type' => $googleAdsRow->getUserInterest()->getTaxonomyType()
                ];
            }
        } catch (\Exception $e) {
            // Log error or just return empty
            $this->logError("Failed to search audiences: " . $e->getMessage());
        }

        return $audiences;
    }
}
