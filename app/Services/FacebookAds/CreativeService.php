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
     * @param string $imageUrl URL of the image (S3 URL or public URL)
     * @return ?string Image hash
     */
    protected function uploadImage(string $accountId, string $imageUrl): ?string
    {
        try {
            // Download the image from S3 or public URL
            $imageContent = @file_get_contents($imageUrl);
            if ($imageContent === false) {
                Log::error("Failed to download image from URL", ['url' => $imageUrl]);
                return null;
            }

            // Create temporary file
            $tempPath = sys_get_temp_dir() . '/' . uniqid('fb_image_') . '.jpg';
            if (file_put_contents($tempPath, $imageContent) === false) {
                Log::error("Failed to write image to temp file", ['temp_path' => $tempPath]);
                return null;
            }

            try {
                // Upload to Facebook using multipart form
                $response = \Http::asMultipart()
                    ->attach('source', fopen($tempPath, 'r'), basename($tempPath))
                    ->post($this->getBaseUrl() . "/act_{$accountId}/adimages", [
                        'access_token' => $this->accessToken,
                    ]);

                // Clean up temp file
                @unlink($tempPath);

                if ($response->successful()) {
                    $data = $response->json();
                    
                    if (isset($data['images'])) {
                        // Facebook returns images as associative array with filename as key
                        $firstImage = reset($data['images']);
                        $imageHash = $firstImage['hash'] ?? null;
                        
                        if ($imageHash) {
                            Log::info("Successfully uploaded image to Facebook", [
                                'account_id' => $accountId,
                                'image_hash' => $imageHash,
                            ]);
                            return $imageHash;
                        }
                    }
                }

                Log::error("Failed to upload image to Facebook", [
                    'account_id' => $accountId,
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);

                return null;

            } finally {
                // Ensure temp file is cleaned up even if exception occurs
                @unlink($tempPath);
            }

        } catch (\Exception $e) {
            Log::error("Error uploading image to Facebook: " . $e->getMessage(), [
                'exception' => $e,
                'account_id' => $accountId,
                'image_url' => $imageUrl,
            ]);
            return null;
        }
    }

    /**
     * Upload a video to the ad account.
     *
     * @param string $accountId Ad account ID (without 'act_' prefix)
     * @param string $videoUrl URL of the video (S3 URL or public URL)
     * @return ?string Video ID
     */
    protected function uploadVideo(string $accountId, string $videoUrl): ?string
    {
        try {
            // Download the video from S3 or public URL
            $videoContent = @file_get_contents($videoUrl);
            if ($videoContent === false) {
                Log::error("Failed to download video from URL", ['url' => $videoUrl]);
                return null;
            }

            // Create temporary file
            $tempPath = sys_get_temp_dir() . '/' . uniqid('fb_video_') . '.mp4';
            if (file_put_contents($tempPath, $videoContent) === false) {
                Log::error("Failed to write video to temp file", ['temp_path' => $tempPath]);
                return null;
            }

            try {
                // For videos, we need to use the resumable upload API for files > 10MB
                // For simplicity, using direct upload for smaller files
                $fileSize = filesize($tempPath);
                
                if ($fileSize > 10 * 1024 * 1024) { // 10MB
                    Log::info("Using resumable upload for large video", [
                        'account_id' => $accountId,
                        'file_size' => $fileSize,
                    ]);
                    return $this->uploadLargeVideo($accountId, $tempPath);
                }

                // Upload small video directly
                $response = \Http::asMultipart()
                    ->attach('source', fopen($tempPath, 'r'), basename($tempPath))
                    ->post($this->getBaseUrl() . "/act_{$accountId}/advideos", [
                        'access_token' => $this->accessToken,
                    ]);

                // Clean up temp file
                @unlink($tempPath);

                if ($response->successful()) {
                    $data = $response->json();
                    $videoId = $data['id'] ?? null;
                    
                    if ($videoId) {
                        Log::info("Successfully uploaded video to Facebook", [
                            'account_id' => $accountId,
                            'video_id' => $videoId,
                        ]);
                        return $videoId;
                    }
                }

                Log::error("Failed to upload video to Facebook", [
                    'account_id' => $accountId,
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);

                return null;

            } finally {
                // Ensure temp file is cleaned up
                @unlink($tempPath);
            }

        } catch (\Exception $e) {
            Log::error("Error uploading video to Facebook: " . $e->getMessage(), [
                'exception' => $e,
                'account_id' => $accountId,
                'video_url' => $videoUrl,
            ]);
            return null;
        }
    }

    /**
     * Upload large video using resumable upload.
     *
     * @param string $accountId Ad account ID
     * @param string $filePath Path to video file
     * @return ?string Video ID
     */
    private function uploadLargeVideo(string $accountId, string $filePath): ?string
    {
        try {
            $fileSize = filesize($filePath);
            
            // Step 1: Initialize upload session
            $initResponse = $this->post("/act_{$accountId}/advideos", [
                'upload_phase' => 'start',
                'file_size' => $fileSize,
            ]);

            if (!$initResponse || !isset($initResponse['upload_session_id'])) {
                Log::error("Failed to initialize video upload session");
                return null;
            }

            $uploadSessionId = $initResponse['upload_session_id'];
            $startOffset = $initResponse['start_offset'] ?? 0;
            $endOffset = $initResponse['end_offset'] ?? $fileSize;

            // Step 2: Upload video file
            $fileHandle = fopen($filePath, 'rb');
            fseek($fileHandle, $startOffset);
            $videoChunk = fread($fileHandle, $endOffset - $startOffset);
            fclose($fileHandle);

            $transferResponse = \Http::asMultipart()
                ->attach('video_file_chunk', $videoChunk, basename($filePath))
                ->post($this->getBaseUrl() . "/act_{$accountId}/advideos", [
                    'access_token' => $this->accessToken,
                    'upload_phase' => 'transfer',
                    'upload_session_id' => $uploadSessionId,
                    'start_offset' => $startOffset,
                ]);

            if (!$transferResponse->successful()) {
                Log::error("Failed to transfer video chunk", [
                    'status' => $transferResponse->status(),
                    'response' => $transferResponse->json(),
                ]);
                return null;
            }

            // Step 3: Finalize upload
            $finalizeResponse = $this->post("/act_{$accountId}/advideos", [
                'upload_phase' => 'finish',
                'upload_session_id' => $uploadSessionId,
            ]);

            if ($finalizeResponse && isset($finalizeResponse['id'])) {
                Log::info("Successfully uploaded large video", [
                    'account_id' => $accountId,
                    'video_id' => $finalizeResponse['id'],
                    'file_size' => $fileSize,
                ]);
                return $finalizeResponse['id'];
            }

            return null;

        } catch (\Exception $e) {
            Log::error("Error in resumable video upload: " . $e->getMessage());
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
