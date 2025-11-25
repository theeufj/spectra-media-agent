<?php

namespace App\Services\GoogleAds;

use Google\Ads\GoogleAds\V22\Services\GoogleAdsServiceClient;
use Illuminate\Support\Facades\Log;
use App\Models\Customer;

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
            $response = $googleAdsServiceClient->search($customerId, $query);

            return count(iterator_to_array($response->getIterator())) > 0;
        } catch (\Exception $e) {
            Log::error("Error checking conversion tracking setup for customer {$customerId}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return false;
        }
    }
}
