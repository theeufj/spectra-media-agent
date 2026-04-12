<?php

namespace App\Services\MicrosoftAds;

use Illuminate\Support\Facades\Log;

class AssetService extends BaseMicrosoftAdsService
{
    /**
     * Create a sitelink ad extension.
     */
    public function createSitelinkExtension(array $params): ?array
    {
        $extension = [
            'Type' => 'SitelinkAdExtension',
            'DisplayText' => $params['link_text'],
            'Description1' => $params['description1'] ?? '',
            'Description2' => $params['description2'] ?? '',
            'FinalUrls' => ['string' => [$params['final_url']]],
            'Status' => 'Active',
        ];

        if (isset($params['device_preference'])) {
            $extension['DevicePreference'] = $params['device_preference'];
        }

        if (isset($params['scheduling'])) {
            $extension['Scheduling'] = $params['scheduling'];
        }

        return $this->addAdExtension($extension, 'Sitelink');
    }

    /**
     * Create a callout ad extension.
     */
    public function createCalloutExtension(string $calloutText): ?array
    {
        return $this->addAdExtension([
            'Type' => 'CalloutAdExtension',
            'Text' => $calloutText,
            'Status' => 'Active',
        ], 'Callout');
    }

    /**
     * Create a call ad extension.
     */
    public function createCallExtension(string $phoneNumber, string $countryCode = 'AU'): ?array
    {
        return $this->addAdExtension([
            'Type' => 'CallAdExtension',
            'PhoneNumber' => $phoneNumber,
            'CountryCode' => $countryCode,
            'IsCallOnly' => false,
            'Status' => 'Active',
        ], 'Call');
    }

    /**
     * Create a structured snippet ad extension.
     */
    public function createStructuredSnippetExtension(string $header, array $values): ?array
    {
        return $this->addAdExtension([
            'Type' => 'StructuredSnippetAdExtension',
            'Header' => $header,
            'Values' => ['string' => $values],
            'Status' => 'Active',
        ], 'StructuredSnippet');
    }

    /**
     * Create a price ad extension.
     */
    public function createPriceExtension(string $priceExtensionType, string $language, array $tableRows): ?array
    {
        $rows = [];
        foreach ($tableRows as $row) {
            $rows[] = [
                'Header' => $row['header'],
                'Description' => $row['description'],
                'FinalUrls' => ['string' => [$row['final_url']]],
                'Price' => $row['price'],
                'PriceUnit' => $row['unit'] ?? 'Unknown',
                'PriceQualifier' => $row['qualifier'] ?? 'Unknown',
                'CurrencyCode' => $row['currency_code'] ?? 'AUD',
            ];
        }

        return $this->addAdExtension([
            'Type' => 'PriceAdExtension',
            'PriceExtensionType' => $priceExtensionType,
            'Language' => $language,
            'TableRows' => ['PriceTableRow' => $rows],
            'Status' => 'Active',
        ], 'Price');
    }

    /**
     * Create a promotion ad extension.
     */
    public function createPromotionExtension(array $params): ?array
    {
        $extension = [
            'Type' => 'PromotionAdExtension',
            'PromotionTarget' => $params['promotion_target'],
            'DiscountModifier' => $params['discount_modifier'] ?? 'None',
            'FinalUrls' => ['string' => [$params['final_url']]],
            'Language' => $params['language'] ?? 'English',
            'Status' => 'Active',
        ];

        if (isset($params['percent_off'])) {
            $extension['PercentOff'] = $params['percent_off'];
        } elseif (isset($params['money_amount_off'])) {
            $extension['MoneyAmountOff'] = $params['money_amount_off'];
            $extension['CurrencyCode'] = $params['currency_code'] ?? 'AUD';
        }

        if (isset($params['promotion_code'])) {
            $extension['PromotionCode'] = $params['promotion_code'];
        }
        if (isset($params['orders_over_amount'])) {
            $extension['OrdersOverAmount'] = $params['orders_over_amount'];
        }
        if (isset($params['promotion_start_date'])) {
            $extension['PromotionStartDate'] = $params['promotion_start_date'];
        }
        if (isset($params['promotion_end_date'])) {
            $extension['PromotionEndDate'] = $params['promotion_end_date'];
        }
        if (isset($params['occasion'])) {
            $extension['Occasion'] = $params['occasion'];
        }

        return $this->addAdExtension($extension, 'Promotion');
    }

    /**
     * Create an image ad extension.
     */
    public function createImageExtension(array $params): ?array
    {
        return $this->addAdExtension([
            'Type' => 'ImageAdExtension',
            'ImageMediaIds' => ['long' => [$params['media_id']]],
            'DisplayText' => $params['display_text'] ?? '',
            'FinalUrls' => isset($params['final_url']) ? ['string' => [$params['final_url']]] : null,
            'Status' => 'Active',
        ], 'Image');
    }

    /**
     * Associate ad extensions with a campaign.
     */
    public function linkExtensionsToCampaign(string $campaignId, array $extensionIds, string $extensionType): bool
    {
        $associations = [];
        foreach ($extensionIds as $extId) {
            $associations[] = [
                'AdExtensionId' => $extId,
                'EntityId' => $campaignId,
                'AssociationType' => 'Campaign',
            ];
        }

        $result = $this->apiCallWithRetry('SetAdExtensionsAssociations', [
            'AccountId' => $this->customer->microsoft_ads_account_id,
            'AdExtensionIdToEntityIdAssociations' => [
                'AdExtensionIdToEntityIdAssociation' => $associations,
            ],
            'AssociationType' => 'Campaign',
        ]);

        if ($result !== null) {
            Log::info('Microsoft Ads: Linked extensions to campaign', [
                'campaign_id' => $campaignId,
                'extension_ids' => $extensionIds,
                'type' => $extensionType,
            ]);
            return true;
        }

        return false;
    }

    /**
     * Associate ad extensions with an ad group.
     */
    public function linkExtensionsToAdGroup(string $adGroupId, array $extensionIds, string $extensionType): bool
    {
        $associations = [];
        foreach ($extensionIds as $extId) {
            $associations[] = [
                'AdExtensionId' => $extId,
                'EntityId' => $adGroupId,
                'AssociationType' => 'AdGroup',
            ];
        }

        $result = $this->apiCallWithRetry('SetAdExtensionsAssociations', [
            'AccountId' => $this->customer->microsoft_ads_account_id,
            'AdExtensionIdToEntityIdAssociations' => [
                'AdExtensionIdToEntityIdAssociation' => $associations,
            ],
            'AssociationType' => 'AdGroup',
        ]);

        if ($result !== null) {
            Log::info('Microsoft Ads: Linked extensions to ad group', [
                'ad_group_id' => $adGroupId,
                'extension_ids' => $extensionIds,
                'type' => $extensionType,
            ]);
            return true;
        }

        return false;
    }

    /**
     * Get all ad extensions for the account by type.
     */
    public function getAdExtensions(string $extensionType): array
    {
        $result = $this->apiCallWithRetry('GetAdExtensionsByIds', [
            'AccountId' => $this->customer->microsoft_ads_account_id,
            'AdExtensionIds' => null,
            'AdExtensionType' => $extensionType,
        ]);

        if ($result && isset($result['AdExtensions']['AdExtension'])) {
            $extensions = $result['AdExtensions']['AdExtension'];
            return isset($extensions['Id']) ? [$extensions] : $extensions;
        }

        return [];
    }

    /**
     * Delete ad extensions by IDs.
     */
    public function deleteAdExtensions(array $extensionIds): bool
    {
        $result = $this->apiCallWithRetry('DeleteAdExtensions', [
            'AccountId' => $this->customer->microsoft_ads_account_id,
            'AdExtensionIds' => ['long' => $extensionIds],
        ]);

        return $result !== null;
    }

    /**
     * Upload an image and get a media ID for use in image extensions.
     */
    public function uploadImage(string $imageData, string $mediaType = 'Image'): ?int
    {
        $result = $this->apiCallWithRetry('AddMedia', [
            'AccountId' => $this->customer->microsoft_ads_account_id,
            'Media' => ['Media' => [[
                'Type' => $mediaType,
                'MediaType' => 'Image',
                'Data' => base64_encode($imageData),
            ]]],
        ]);

        if ($result && isset($result['MediaIds'])) {
            $id = is_array($result['MediaIds']) ? $result['MediaIds'][0] : $result['MediaIds'];
            Log::info('Microsoft Ads: Uploaded image', ['media_id' => $id]);
            return (int) $id;
        }

        return null;
    }

    /**
     * Internal helper to add a single ad extension.
     */
    protected function addAdExtension(array $extension, string $typeName): ?array
    {
        $result = $this->apiCallWithRetry('AddAdExtensions', [
            'AccountId' => $this->customer->microsoft_ads_account_id,
            'AdExtensions' => ['AdExtension' => [$extension]],
        ]);

        if ($result && isset($result['AdExtensionIdentities'])) {
            Log::info("Microsoft Ads: Created {$typeName} extension", [
                'identities' => $result['AdExtensionIdentities'],
            ]);
            return $result;
        }

        return null;
    }
}
