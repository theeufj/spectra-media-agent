<?php

namespace App\Services\GoogleAds\VideoServices;

use App\Services\GoogleAds\BaseGoogleAdsService;
use App\Services\StorageHelper;
use Google\Ads\GoogleAds\V26\Enums\YouTubeVideoPrivacyEnum\YouTubeVideoPrivacy;
use Google\Ads\GoogleAds\V26\Enums\YouTubeVideoUploadGenerativeContentDisclosureEnum\YouTubeVideoUploadGenerativeContentDisclosure;
use Google\Ads\GoogleAds\V26\Enums\YouTubeVideoUploadStateEnum\YouTubeVideoUploadState;
use Google\Ads\GoogleAds\V26\Resources\VideoContentMetadata;
use Google\Ads\GoogleAds\V26\Resources\YouTubeVideoUpload;
use Google\Ads\GoogleAds\V26\Services\CreateYouTubeVideoUploadRequest;
use Google\Ads\GoogleAds\V26\Services\CreateYouTubeVideoUploadResponse;
use GuzzleHttp\Psr7\Utils;

/** Upload an unlisted video to the Google-managed channel for the target Ads account. */
class UploadPMaxVideoToGoogle extends BaseGoogleAdsService
{
    public function start(string $customerId, string $path, string $title, bool $generated): string
    {
        $this->assertAccount($customerId);
        if ($this->isDryRun()) {
            throw new \LogicException('Google video uploads cannot run in validation mode.');
        }

        $contents = StorageHelper::get($path);
        if (! $contents) {
            throw new \RuntimeException('The selected video file is unavailable.');
        }

        $video = new YouTubeVideoUpload([
            'video_title' => mb_substr(trim(strip_tags($title)), 0, 100),
            'video_privacy' => YouTubeVideoPrivacy::UNLISTED,
        ]);
        if ($generated) {
            $video->setVideoContentMetadata(new VideoContentMetadata([
                'generative_content_disclosure' => YouTubeVideoUploadGenerativeContentDisclosure::GENERATIVE_CONTENT,
            ]));
        }

        $this->ensureClient();
        $session = $this->client->getYouTubeVideoUploadServiceClient()->createYouTubeVideoUpload(
            CreateYouTubeVideoUploadRequest::build($customerId, $video),
        );
        $stream = Utils::streamFor($contents);
        try {
            $response = $session->startUpload($stream);
        } finally {
            $stream->close();
        }
        if (! $response instanceof CreateYouTubeVideoUploadResponse || ! $this->validUpload($customerId, $response->getResourceName())) {
            throw new \RuntimeException('Google did not return a video upload resource for this account.');
        }

        return $response->getResourceName();
    }

    /** Null means that Google has not finished processing the accepted upload. */
    public function processedVideoId(string $customerId, string $upload): ?string
    {
        $this->assertAccount($customerId);
        if (! $this->validUpload($customerId, $upload)) {
            throw new \InvalidArgumentException('The video upload does not belong to this Google Ads account.');
        }

        $this->ensureClient();
        $query = 'SELECT you_tube_video_upload.resource_name, you_tube_video_upload.video_id, you_tube_video_upload.state '
            ."FROM you_tube_video_upload WHERE you_tube_video_upload.resource_name = '{$upload}'";
        foreach ($this->searchQuery($customerId, $query)->getIterator() as $row) {
            $video = $row->getYouTubeVideoUpload();
            if ($video->getState() === YouTubeVideoUploadState::PROCESSED && preg_match('/^[A-Za-z0-9_-]{11}$/D', $video->getVideoId())) {
                return $video->getVideoId();
            }
            if (in_array($video->getState(), [YouTubeVideoUploadState::FAILED, YouTubeVideoUploadState::REJECTED, YouTubeVideoUploadState::UNAVAILABLE], true)) {
                throw new \RuntimeException('Google video upload state is '.YouTubeVideoUploadState::name($video->getState()).'.');
            }
        }

        return null;
    }

    private function assertAccount(string $customerId): void
    {
        if (! ctype_digit($customerId) || $this->customer?->cleanGoogleCustomerId() !== $customerId) {
            throw new \InvalidArgumentException('The Google Ads video account does not match this customer.');
        }
    }

    public function isLinked(string $customerId, string $group, string $youtubeId): bool
    {
        $this->assertAccount($customerId);
        if (! preg_match('#^customers/'.$customerId.'/assetGroups/\d+$#D', $group) || ! preg_match('/^[A-Za-z0-9_-]{11}$/D', $youtubeId)) {
            throw new \InvalidArgumentException('The selected Performance Max video link is invalid.');
        }

        $this->ensureClient();
        $query = 'SELECT asset_group_asset.resource_name FROM asset_group_asset '
            ."WHERE asset_group_asset.asset_group = '{$group}' AND asset_group_asset.field_type = 'YOUTUBE_VIDEO' "
            ."AND asset.youtube_video_asset.youtube_video_id = '{$youtubeId}' AND asset_group_asset.status = 'ENABLED'";

        return $this->searchQuery($customerId, $query)->getIterator()->valid();
    }

    private function validUpload(string $customerId, string $upload): bool
    {
        return preg_match('#^customers/'.$customerId.'/youTubeVideoUploads/[^/\s\x27]+$#D', $upload) === 1;
    }
}
