<?php

namespace App\Services\GoogleAds\ShoppingServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\Ad;
use Google\Ads\GoogleAds\V22\Resources\AdGroupAd;
use Google\Ads\GoogleAds\V22\Common\ShoppingProductAdInfo;
use Google\Ads\GoogleAds\V22\Services\AdGroupAdOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAdGroupAdsRequest;
use Google\Ads\GoogleAds\V22\Enums\AdGroupAdStatusEnum\AdGroupAdStatus;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use App\Models\Customer;

class CreateShoppingProductAd extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Creates a Shopping Product Ad within a Shopping Ad Group.
     *
     * Shopping Product Ads are auto-generated from the Merchant Center feed —
     * no headlines, descriptions, or images are needed. The ShoppingProductAdInfo
     * constructor is empty; Google pulls all product data from the linked feed.
     *
     * @param string $customerId The Google Ads customer ID.
     * @param string $adGroupResourceName The resource name of the parent Shopping Ad Group.
     * @return string|null The resource name of the created ad, or null on failure.
     */
    public function __invoke(string $customerId, string $adGroupResourceName): ?string
    {
        $this->ensureClient();

        // Shopping Product Ads have no configurable fields — product data comes from Merchant Center
        $shoppingProductAdInfo = new ShoppingProductAdInfo();

        $ad = new Ad([
            'shopping_product_ad' => $shoppingProductAdInfo,
        ]);

        $adGroupAd = new AdGroupAd([
            'ad_group' => $adGroupResourceName,
            'status' => AdGroupAdStatus::ENABLED,
            'ad' => $ad,
        ]);

        $adGroupAdOperation = new AdGroupAdOperation();
        $adGroupAdOperation->setCreate($adGroupAd);

        try {
            $adGroupAdServiceClient = $this->client->getAdGroupAdServiceClient();
            $request = new MutateAdGroupAdsRequest([
                'customer_id' => $customerId,
                'operations' => [$adGroupAdOperation],
            ]);
            $response = $adGroupAdServiceClient->mutateAdGroupAds($request);
            $newAdGroupAdResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Successfully created Shopping Product Ad: " . $newAdGroupAdResourceName);
            return $newAdGroupAdResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating Shopping Product Ad for customer $customerId: " . $e->getMessage(), $e);
            return null;
        }
    }
}
