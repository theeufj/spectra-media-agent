<?php

namespace App\Services\GoogleAds;

use App\Models\Customer;
use Google\Ads\GoogleAds\V22\Services\GoogleAdsServiceClient;
use Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest;
use Illuminate\Support\Facades\Log;

class ConversionTrackingService extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    public function isConversionTrackingSetUp(string $customerId): bool
    {
        try {
            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();

            $query = "SELECT conversion_action.id FROM conversion_action";
            $response = $googleAdsServiceClient->search(new SearchGoogleAdsRequest([
                'customer_id' => $customerId,
                'query' => $query,
            ]));

            return count(iterator_to_array($response->getIterator())) > 0;
        } catch (\Exception $e) {
            Log::error("Error checking conversion tracking setup for customer {$customerId}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return false;
        }
    }

    public function getConversionCountLast30Days(string $customerId): int
    {
        try {
            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();

            $query = "SELECT metrics.conversions FROM customer WHERE segments.date DURING LAST_30_DAYS";
            $response = $googleAdsServiceClient->search(new SearchGoogleAdsRequest([
                'customer_id' => $customerId,
                'query' => $query,
            ]));

            $total = 0;
            foreach ($response->getIterator() as $row) {
                $total += (int) $row->getMetrics()->getConversions();
            }

            return $total;
        } catch (\Exception $e) {
            Log::error("Error getting conversion count for customer {$customerId}: " . $e->getMessage());
            return 0;
        }
    }
}
