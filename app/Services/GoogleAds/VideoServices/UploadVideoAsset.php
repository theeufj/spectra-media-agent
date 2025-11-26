<?php

namespace App\Services\GoogleAds\VideoServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use Google\Ads\GoogleAds\V22\Resources\Asset;
use Google\Ads\GoogleAds\V22\Common\VideoAsset;
use Google\Ads\GoogleAds\V22\Enums\AssetTypeEnum\AssetType;
use Google\Ads\GoogleAds\V22\Services\AssetService;
use Google\Ads\GoogleAds\V22\Services\AssetOperation;
use Google\Ads\GoogleAds\V22\Services\MutateAssetsRequest;
use Google\Ads\GoogleAds\V22\Errors\GoogleAdsException;
use App\Models\Customer;

class UploadVideoAsset extends BaseGoogleAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Uploads a video to YouTube and registers it as a Video Asset in Google Ads.
     * Note: This service assumes the video is already uploaded to YouTube and provides its ID.
     *
     * @param string $customerId The Google Ads customer ID.
     * @param string $youtubeVideoId The YouTube video ID (e.g., 'dQw4w9WgXcQ').
     * @param string $videoName A name for the video asset.
     * @return string|null The resource name of the uploaded video asset, or null on failure.
     */
    public function __invoke(string $customerId, string $youtubeVideoId, string $videoName): ?string
    {
        // Create VideoAsset
        $videoAsset = new VideoAsset(['youtube_video_id' => $youtubeVideoId]);

        // Create Asset
        $asset = new Asset([
            'name' => $videoName,
            'type' => AssetType::VIDEO,
            'video_asset' => $videoAsset,
        ]);

        // Create AssetOperation
        $assetOperation = new AssetOperation();
        $assetOperation->setCreate($asset);

        try {
            $assetServiceClient = $this->client->getAssetServiceClient();
            $request = new MutateAssetsRequest([
                'customer_id' => $customerId,
                'operations' => [$assetOperation],
            ]);
            $response = $assetServiceClient->mutateAssets($request);
            $newAssetResourceName = $response->getResults()[0]->getResourceName();
            $this->logInfo("Successfully created video asset: " . $newAssetResourceName);
            return $newAssetResourceName;
        } catch (GoogleAdsException $e) {
            $this->logError("Error creating video asset for customer $customerId: " . $e->getMessage(), $e);
            return null;
        }
    }
}
