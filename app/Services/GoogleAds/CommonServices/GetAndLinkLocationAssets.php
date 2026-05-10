<?php

namespace App\Services\GoogleAds\CommonServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\CampaignAsset;
use Google\Ads\GoogleAds\V22\Services\CampaignAssetOperation;
use Google\Ads\GoogleAds\V22\Services\MutateCampaignAssetsRequest;
use Google\Ads\GoogleAds\V22\Services\SearchGoogleAdsRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use Illuminate\Support\Facades\Log;

class GetAndLinkLocationAssets extends BaseGoogleAdsService
{
    /**
     * Find Business Profile location assets already synced to the Google Ads account
     * and link them to the specified campaign.
     *
     * Google Ads auto-syncs LOCATION assets when a Business Profile is linked to the
     * account (done in Google Ads UI → Tools → Linked accounts → Business Profile).
     * This service detects those assets and wires them to the campaign.
     *
     * AssetFieldType::LOCATION = 29 (protobuf value; not exposed in the V22 PHP enum).
     *
     * @return int Number of location assets linked
     */
    public function __invoke(string $customerId, string $campaignResourceName): int
    {
        $this->ensureClient();

        $query = "SELECT asset.resource_name, asset.name FROM asset WHERE asset.type = 'LOCATION'";

        try {
            $googleAdsServiceClient = $this->client->getGoogleAdsServiceClient();
            $response = $googleAdsServiceClient->search(new SearchGoogleAdsRequest([
                'customer_id' => $customerId,
                'query'       => $query,
            ]));

            $linked = 0;

            foreach ($response->getIterator() as $row) {
                $assetResourceName = $row->getAsset()->getResourceName();

                try {
                    $campaignAsset = new CampaignAsset([
                        'campaign'   => $campaignResourceName,
                        'asset'      => $assetResourceName,
                        'field_type' => 29, // AssetFieldType::LOCATION (proto value, not in V22 PHP enum)
                    ]);

                    $operation = new CampaignAssetOperation();
                    $operation->setCreate($campaignAsset);

                    $this->client->getCampaignAssetServiceClient()->mutateCampaignAssets(
                        new MutateCampaignAssetsRequest([
                            'customer_id' => $customerId,
                            'operations'  => [$operation],
                        ])
                    );

                    $linked++;
                } catch (GoogleAdsException $e) {
                    // Skip duplicate links (asset already linked to this campaign)
                    if (!str_contains($e->getMessage(), 'already exists')) {
                        Log::warning('GetAndLinkLocationAssets: Failed to link location asset', [
                            'asset'    => $assetResourceName,
                            'campaign' => $campaignResourceName,
                            'error'    => $e->getMessage(),
                        ]);
                    }
                }
            }

            return $linked;
        } catch (\Exception $e) {
            Log::warning('GetAndLinkLocationAssets: Query failed', [
                'customer_id' => $customerId,
                'error'       => $e->getMessage(),
            ]);
            return 0;
        }
    }
}
