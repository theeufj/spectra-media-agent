<?php

namespace App\Services\GoogleAds\VideoServices;

use App\Services\StorageHelper;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Uploads an MP4 video from S3 to YouTube using the YouTube Data API v3.
 *
 * Prerequisites:
 *   GOOGLE_YOUTUBE_CLIENT_ID     — OAuth client ID (from Google Cloud Console)
 *   GOOGLE_YOUTUBE_CLIENT_SECRET — OAuth client secret
 *   GOOGLE_YOUTUBE_REFRESH_TOKEN — Refresh token authorized with youtube.upload scope.
 *
 * To generate the refresh token:
 *   1. In Google Cloud Console, enable YouTube Data API v3 on your project.
 *   2. Create OAuth credentials with scope https://www.googleapis.com/auth/youtube.upload
 *   3. Run the OAuth flow once (e.g. via oauth2.googleapis.com/auth) and capture the refresh_token.
 *   4. Set it in .env as GOOGLE_YOUTUBE_REFRESH_TOKEN=...
 */
class UploadVideoToYouTube
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const UPLOAD_URL = 'https://www.googleapis.com/upload/youtube/v3/videos';

    public function __invoke(string $s3Path, string $title, string $description = ''): ?string
    {
        $clientId     = config('services.youtube.client_id');
        $clientSecret = config('services.youtube.client_secret');
        $refreshToken = config('services.youtube.refresh_token');

        if (!$clientId || !$clientSecret || !$refreshToken) {
            Log::warning('UploadVideoToYouTube: Missing YouTube credentials (GOOGLE_YOUTUBE_CLIENT_ID / SECRET / REFRESH_TOKEN)');
            return null;
        }

        $accessToken = $this->getAccessToken($clientId, $clientSecret, $refreshToken);
        if (!$accessToken) {
            return null;
        }

        $videoData = StorageHelper::get($s3Path);
        if (!$videoData) {
            Log::error('UploadVideoToYouTube: Could not read video from S3', ['s3_path' => $s3Path]);
            return null;
        }

        $videoSize = strlen($videoData);

        // Step 1: Initiate resumable upload — get upload URL
        $metadata = [
            'snippet' => [
                'title'       => mb_substr($title, 0, 100),
                'description' => mb_substr($description, 0, 5000),
                'categoryId'  => '22', // People & Blogs
            ],
            'status' => [
                'privacyStatus' => 'unlisted', // Unlisted so ads can use it
            ],
        ];

        $initiateResponse = Http::withHeaders([
            'Authorization'          => 'Bearer ' . $accessToken,
            'Content-Type'           => 'application/json',
            'X-Upload-Content-Type'  => 'video/mp4',
            'X-Upload-Content-Length' => $videoSize,
        ])->post(self::UPLOAD_URL . '?uploadType=resumable&part=snippet,status', $metadata);

        if (!$initiateResponse->successful()) {
            Log::error('UploadVideoToYouTube: Failed to initiate upload', [
                'status' => $initiateResponse->status(),
                'body'   => $initiateResponse->body(),
            ]);
            return null;
        }

        $uploadUri = $initiateResponse->header('Location');
        if (!$uploadUri) {
            Log::error('UploadVideoToYouTube: No Location header in initiate response');
            return null;
        }

        // Step 2: Upload the video bytes
        $uploadResponse = Http::withHeaders([
            'Authorization'  => 'Bearer ' . $accessToken,
            'Content-Type'   => 'video/mp4',
            'Content-Length' => $videoSize,
        ])->withBody($videoData, 'video/mp4')->put($uploadUri);

        if (!$uploadResponse->successful()) {
            Log::error('UploadVideoToYouTube: Upload failed', [
                'status' => $uploadResponse->status(),
                'body'   => $uploadResponse->body(),
            ]);
            return null;
        }

        $youtubeVideoId = $uploadResponse->json('id');

        if (!$youtubeVideoId) {
            Log::error('UploadVideoToYouTube: No video ID in upload response', [
                'body' => $uploadResponse->body(),
            ]);
            return null;
        }

        Log::info('UploadVideoToYouTube: Video uploaded successfully', [
            'youtube_video_id' => $youtubeVideoId,
            's3_path'          => $s3Path,
        ]);

        return $youtubeVideoId;
    }

    private function getAccessToken(string $clientId, string $clientSecret, string $refreshToken): ?string
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token',
        ]);

        if (!$response->successful()) {
            Log::error('UploadVideoToYouTube: Failed to get access token', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return null;
        }

        return $response->json('access_token');
    }
}
