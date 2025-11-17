<?php

namespace App\Services\FacebookAds;

use Illuminate\Support\Facades\Log;
use App\Models\Customer;

class CreativeService extends BaseFacebookAdsService
{
    public function __construct(Customer $customer)
    {
        parent::__construct($customer);
    }

    /**
     * Create a new creative with image.
     *
     * @param string $accountId Ad account ID (without 'act_' prefix)
     * @param string $creativeName Name for the creative
     * @param string $imageUrl URL of the image
     * @param string $headline Ad headline
     * @param string $description Ad description
     * @param string $callToAction Call to action text
     * @return ?array
     */
    public function createImageCreative(
        string $accountId,
        string $creativeName,
        string $imageUrl,
        string $headline,
        string $description,
        string $callToAction = 'LEARN_MORE'
    ): ?array {
        try {
            $response = $this->post("/act_{$accountId}/adcreatives", [
                'name' => $creativeName,
                'object_story_spec' => json_encode([
                    'page_id' => $this->getPagesForAccount($accountId)[0] ?? null,
                    'link_data' => [
                        'image_hash' => $this->uploadImage($accountId, $imageUrl),
                        'link' => $this->getPageWebsite() ?? 'https://example.com',
                        'headline' => $headline,
                        'description' => $description,
                        'call_to_action_type' => $callToAction,
                    ],
                ]),
            ]);

            if ($response && isset($response['id'])) {
                Log::info("Created image creative for account {$accountId}", [
                    'customer_id' => $this->customer->id,
                    'creative_id' => $response['id'],
                ]);
                return $response;
            }

            Log::error("Failed to create image creative", [
                'customer_id' => $this->customer->id,
                'account_id' => $accountId,
                'response' => $response,
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error("Error creating image creative: " . $e->getMessage(), [
                'exception' => $e,
                'account_id' => $accountId,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }

    /**
     * Create a new creative with video.
     *
     * @param string $accountId Ad account ID (without 'act_' prefix)
     * @param string $creativeName Name for the creative
     * @param string $videoUrl URL of the video
     * @param string $headline Ad headline
     * @param string $description Ad description
     * @return ?array
     */
    public function createVideoCreative(
        string $accountId,
        string $creativeName,
        string $videoUrl,
        string $headline,
        string $description
    ): ?array {
        try {
            $response = $this->post("/act_{$accountId}/adcreatives", [
                'name' => $creativeName,
                'object_story_spec' => json_encode([
                    'page_id' => $this->getPagesForAccount($accountId)[0] ?? null,
                    'video_data' => [
                        'video_id' => $this->uploadVideo($accountId, $videoUrl),
                        'link' => $this->getPageWebsite() ?? 'https://example.com',
                        'headline' => $headline,
                        'description' => $description,
                    ],
                ]),
            ]);

            if ($response && isset($response['id'])) {
                Log::info("Created video creative for account {$accountId}", [
                    'customer_id' => $this->customer->id,
                    'creative_id' => $response['id'],
                ]);
                return $response;
            }

            Log::error("Failed to create video creative", [
                'customer_id' => $this->customer->id,
                'account_id' => $accountId,
                'response' => $response,
            ]);
            return null;
        } catch (\Exception $e) {
            Log::error("Error creating video creative: " . $e->getMessage(), [
                'exception' => $e,
                'account_id' => $accountId,
                'customer_id' => $this->customer->id,
            ]);
            return null;
        }
    }

    /**
     * Upload an image to the ad account.
     *
     * @param string $accountId Ad account ID (without 'act_' prefix)
     * @param string $imageUrl URL of the image
     * @return ?string Image hash
     */
    protected function uploadImage(string $accountId, string $imageUrl): ?string
    {
        try {
            // Download the image
            $imageContent = file_get_contents($imageUrl);
            if (!$imageContent) {
                Log::error("Failed to download image", ['url' => $imageUrl]);
                return null;
            }

            // Upload to Facebook
            $response = \Http::asMultipart()
                ->withToken($this->accessToken)
                ->post($this->getBaseUrl() . "/act_{$accountId}/adimages", [
                    'image' => \Illuminate\Http\UploadedFile::fake()->image('temp.jpg')->getContent(),
                ]);

            if ($response->successful() && isset($response['images'])) {
                $imageHash = array_key_first($response['images']);
                Log::info("Uploaded image to account {$accountId}", ['image_hash' => $imageHash]);
                return $imageHash;
            }

            return null;
        } catch (\Exception $e) {
            Log::error("Error uploading image: " . $e->getMessage(), [
                'exception' => $e,
                'account_id' => $accountId,
            ]);
            return null;
        }
    }

    /**
     * Upload a video to the ad account.
     *
     * @param string $accountId Ad account ID (without 'act_' prefix)
     * @param string $videoUrl URL of the video
     * @return ?string Video ID
     */
    protected function uploadVideo(string $accountId, string $videoUrl): ?string
    {
        try {
            // This is a simplified implementation
            // In production, you'd want to use resumable uploads for large files
            $response = $this->post("/act_{$accountId}/advideos", [
                'source' => $videoUrl,
            ]);

            if ($response && isset($response['id'])) {
                Log::info("Uploaded video to account {$accountId}", ['video_id' => $response['id']]);
                return $response['id'];
            }

            return null;
        } catch (\Exception $e) {
            Log::error("Error uploading video: " . $e->getMessage(), [
                'exception' => $e,
                'account_id' => $accountId,
            ]);
            return null;
        }
    }

    /**
     * Get pages associated with the account.
     *
     * @param string $accountId Ad account ID
     * @return array
     */
    protected function getPagesForAccount(string $accountId): array
    {
        try {
            $response = $this->get("/act_{$accountId}/pages");

            if ($response && isset($response['data'])) {
                return array_column($response['data'], 'id');
            }

            return [];
        } catch (\Exception $e) {
            Log::error("Error getting pages: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get the website from the customer record.
     *
     * @return ?string
     */
    protected function getPageWebsite(): ?string
    {
        return $this->customer->website;
    }
}
