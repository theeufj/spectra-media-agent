<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Enums\AdvertisingChannelTypeEnum\AdvertisingChannelType;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Google\Ads\GoogleAds\V22\Resources\Campaign;
use Google\Ads\GoogleAds\V22\Resources\Campaign\NetworkSettings;
use Google\Ads\GoogleAds\V22\Services\CampaignOperation;
use Google\Ads\GoogleAds\V22\Services\MutateCampaignsRequest;
use Google\Protobuf\FieldMask;

/**
 * Toggle a Search campaign's network settings — primarily to disable Search Partners
 * and Display Network expansion, which commonly drain budget on out-of-network,
 * low-intent placements.
 *
 * Self-protecting: refuses to touch anything but a SEARCH channel campaign, since
 * disabling the content network on a Display/Demand-Gen campaign would break it.
 */
class UpdateCampaignNetworkSettings extends BaseGoogleAdsService
{
    /** Fields this service is allowed to set. target_google_search is never disabled here. */
    private const ALLOWED_FIELDS = [
        'target_google_search',
        'target_search_network',
        'target_content_network',
    ];

    /**
     * @param  array<string,bool>  $settings  e.g. ['target_search_network' => false, 'target_content_network' => false]
     */
    public function __invoke(string $customerId, string $campaignResourceName, array $settings): bool
    {
        $this->ensureClient();

        // Only Search campaigns — verify against the live channel type, not local state.
        $channelQuery = "SELECT campaign.advertising_channel_type FROM campaign WHERE campaign.resource_name = '$campaignResourceName'";

        try {
            $response = $this->searchQuery($customerId, $channelQuery);
            $channelType = null;
            foreach ($response->getIterator() as $row) {
                $channelType = $row->getCampaign()->getAdvertisingChannelType();
                break;
            }

            if ($channelType !== AdvertisingChannelType::SEARCH) {
                $this->logError("Refusing to change network settings on non-Search campaign ($campaignResourceName)");
                return false;
            }

            $networkSettings = new NetworkSettings();
            $paths = [];

            foreach (self::ALLOWED_FIELDS as $field) {
                if (!array_key_exists($field, $settings)) {
                    continue;
                }
                // Never disable Google Search itself — that would blind the campaign.
                if ($field === 'target_google_search' && $settings[$field] === false) {
                    continue;
                }
                $setter = 'set' . str_replace('_', '', ucwords($field, '_'));
                $networkSettings->{$setter}((bool) $settings[$field]);
                $paths[] = "network_settings.$field";
            }

            if (empty($paths)) {
                $this->logError('UpdateCampaignNetworkSettings: no valid network settings supplied');
                return false;
            }

            $campaign = new Campaign([
                'resource_name'    => $campaignResourceName,
                'network_settings' => $networkSettings,
            ]);

            $operation = new CampaignOperation();
            $operation->setUpdate($campaign);
            $operation->setUpdateMask(new FieldMask(['paths' => $paths]));

            $client = $this->client->getCampaignServiceClient();
            $result = $client->mutateCampaigns(new MutateCampaignsRequest([
                'customer_id' => $customerId,
                'operations'  => [$operation],
            ]));

            return count($result->getResults()) > 0;

        } catch (GoogleAdsException $e) {
            $this->logError('Failed to update campaign network settings: ' . $e->getMessage());
            return false;
        }
    }
}
