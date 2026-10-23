<?php

namespace App\Services\GoogleAds\SearchServices;

use App\Models\Customer;
use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\Lib\V26\GoogleAdsException;
use Google\Ads\GoogleAds\V26\Enums\AdGroupStatusEnum\AdGroupStatus;
use Google\Ads\GoogleAds\V26\Enums\AdGroupTypeEnum\AdGroupType;
use Google\Ads\GoogleAds\V26\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V26\Enums\CampaignStatusEnum\CampaignStatus;
use Google\Ads\GoogleAds\V26\Resources\AdGroup;
use Google\Ads\GoogleAds\V26\Services\AdGroupOperation;

class CreateSearchAdGroup extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Creates an ad group within an existing Search campaign.
     *
     * @param  string  $customerId  The Google Ads customer ID.
     * @param  string  $campaignResourceName  The resource name of the parent Search campaign.
     * @param  string  $adGroupName  The name of the ad group to create.
     * @return string|null The resource name of the created ad group, or null on failure.
     */
    public function __invoke(string $customerId, string $campaignResourceName, string $adGroupName): ?string
    {
        $this->ensureClient();
        if (! $this->pausedSearchParent($customerId, $campaignResourceName)) {
            return null;
        }

        // Check if ad group already exists
        $existingAdGroup = $this->getAdGroupByName($customerId, $campaignResourceName, $adGroupName);
        // The lookup is a remote call: do not rely on an earlier PAUSED read
        // when creating a group with a temporary provider-default bid.
        if (! $this->pausedSearchParent($customerId, $campaignResourceName)) {
            return null;
        }
        if ($existingAdGroup) {
            if (! preg_match('#^customers/'.preg_quote($customerId, '#').'/adGroups/\d+$#', $existingAdGroup->getResourceName())) {
                $this->logError('The existing Search ad group does not belong to this customer account.');

                return null;
            }
            $this->logInfo("Ad Group '{$adGroupName}' already exists. Skipping creation.");

            return $existingAdGroup->getResourceName();
        }

        $adGroup = new AdGroup([
            'name' => $adGroupName,
            'campaign' => $campaignResourceName,
            'status' => AdGroupStatus::ENABLED,
            'type' => AdGroupType::SEARCH_STANDARD,
        ]);

        $adGroupOperation = new AdGroupOperation;
        $adGroupOperation->setCreate($adGroup);

        try {
            $adGroupServiceClient = $this->client->getAdGroupServiceClient();
            // Fix: Use MutateAdGroupsRequest object
            $request = new \Google\Ads\GoogleAds\V26\Services\MutateAdGroupsRequest([
                'validate_only' => $this->dryRun,
                'customer_id' => $customerId,
                'operations' => [$adGroupOperation],
            ]);
            $response = $adGroupServiceClient->mutateAdGroups($request);
            $newAdGroupResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo('Successfully created Search ad group: '.$newAdGroupResourceName);

            return $newAdGroupResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating Search ad group for customer $customerId: ".$e->getMessage(), $e);

            return null;
        }
    }

    /** @phpstan-impure Fresh Google status can change between calls. */
    private function pausedSearchParent(string $customerId, string $resource): bool
    {
        if (! $this->matchesCustomer($customerId, $resource)) {
            $this->logError('Search ad group staging requires an exact customer campaign resource.');

            return false;
        }
        $query = 'SELECT campaign.resource_name, campaign.status, campaign.advertising_channel_type '
            ."FROM campaign WHERE campaign.resource_name = '{$resource}'";
        try {
            foreach ($this->searchQuery($customerId, $query)->getIterator() as $row) {
                $campaign = $row->getCampaign();
                if ($campaign->getResourceName() === $resource && $campaign->getStatus() === CampaignStatus::PAUSED
                    && $campaign->getAdvertisingChannelType() === AdvertisingChannelType::SEARCH
                    && $this->matchesCustomer($customerId, $resource)) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            $this->logError('The Search campaign’s paused staging status could not be verified.', $e);

            return false;
        }
        $this->logError('Search ad group staging requires a freshly verified paused Search campaign.');

        return false;
    }

    /** @phpstan-impure The customer account pointer is reloaded from the database. */
    private function matchesCustomer(string $customerId, string $resource): bool
    {
        $customer = $this->customer?->exists ? $this->customer->fresh() : $this->customer;

        return ctype_digit($customerId) && $customer?->cleanGoogleCustomerId() === $customerId
            && preg_match('#^customers/'.preg_quote($customerId, '#').'/campaigns/\d+$#', $resource) === 1;
    }

    /**
     * Check if an ad group with the given name already exists in the campaign
     */
    private function getAdGroupByName(string $customerId, string $campaignResourceName, string $adGroupName): ?AdGroup
    {
        $query = "SELECT ad_group.resource_name, ad_group.name FROM ad_group WHERE ad_group.campaign = '{$campaignResourceName}' AND ad_group.name = '{$adGroupName}'";
        try {
            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();
            $request = new \Google\Ads\GoogleAds\V26\Services\SearchGoogleAdsRequest([
                'customer_id' => $customerId,
                'query' => $query,
            ]);
            $response = $googleAdsServiceClient->search($request);

            foreach ($response->getIterator() as $googleAdsRow) {
                return $googleAdsRow->getAdGroup();
            }
        } catch (GoogleAdsException $e) {
            $this->logError("Error fetching ad group by name for customer $customerId: ".$e->getMessage(), $e);
        }

        return null;
    }
}
