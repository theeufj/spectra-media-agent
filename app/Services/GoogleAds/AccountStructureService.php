<?php

namespace App\Services\GoogleAds;

use Google\Ads\GoogleAds\V22\Services\GoogleAdsServiceClient;
use Illuminate\Support\Facades\Log;
use App\Models\Customer;

class AccountStructureService extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    public function getAccountStructureLimits(string $customerId): array
    {
        try {
            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();

            $campaignQuery = "SELECT campaign.id FROM campaign";
            $campaignResponse = $googleAdsServiceClient->search($customerId, $campaignQuery);
            $campaignCount = count(iterator_to_array($campaignResponse->getIterator()));

            $adGroupQuery = "SELECT ad_group.id FROM ad_group";
            $adGroupResponse = $googleAdsServiceClient->search($customerId, $adGroupQuery);
            $adGroupCount = count(iterator_to_array($adGroupResponse->getIterator()));

            return [
                'campaigns' => $campaignCount,
                'ad_groups' => $adGroupCount,
            ];
        } catch (\Exception $e) {
            Log::error("Error fetching account structure limits for customer {$customerId}: " . $e->getMessage(), [
                'exception' => $e,
            ]);
            return [
                'campaigns' => 0,
                'ad_groups' => 0,
            ];
        }
    }
}
