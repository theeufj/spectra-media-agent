<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class StorageHelper
{
    /**
     * Store a file and return [path, url].
     * Uses S3 + CloudFront when configured, otherwise falls back to local public disk.
     */
    public static function put(string $path, string $contents, string $contentType = 'application/octet-stream'): array
    {
        $s3Bucket = config('filesystems.disks.s3.bucket');
        $s3Key = config('filesystems.disks.s3.key');

        if ($s3Bucket && $s3Key) {
            $s3Client = Storage::disk('s3')->getClient();
            $result = $s3Client->putObject([
                'Bucket' => $s3Bucket,
                'Key' => $path,
                'Body' => $contents,
                'ContentType' => $contentType,
                'ACL' => 'public-read',
            ]);

            if (!isset($result['ETag'])) {
                throw new \RuntimeException('S3 upload failed - no ETag in response.');
            }

            Log::info("File uploaded to S3: {$path}");

            $cloudfrontDomain = config('filesystems.cloudfront_domain');
            $url = $cloudfrontDomain
                ? "https://{$cloudfrontDomain}/{$path}"
                : Storage::disk('s3')->url($path);

            return [$path, $url];
        }

        // Fallback: local public storage
        Storage::disk('public')->put($path, $contents);
        Log::info("File saved to local storage: {$path}");
        $url = asset("storage/{$path}");

        return [$path, $url];
    }

    /**
     * Read file contents from wherever it's stored.
     */
    public static function get(string $path): ?string
    {
        $s3Bucket = config('filesystems.disks.s3.bucket');
        $s3Key = config('filesystems.disks.s3.key');

        if ($s3Bucket && $s3Key) {
            return Storage::disk('s3')->get($path);
        }

        return Storage::disk('public')->get($path);
    }

    /**
     * Get the MIME type of a stored file.
     */
    public static function mimeType(string $path): ?string
    {
        $s3Bucket = config('filesystems.disks.s3.bucket');
        $s3Key = config('filesystems.disks.s3.key');

        if ($s3Bucket && $s3Key) {
            return Storage::disk('s3')->mimeType($path);
        }

        return Storage::disk('public')->mimeType($path);
    }

    /**
     * Delete a file from wherever it's stored.
     */
    public static function delete(string $path): void
    {
        $s3Bucket = config('filesystems.disks.s3.bucket');
        $s3Key = config('filesystems.disks.s3.key');

        if ($s3Bucket && $s3Key) {
            try {
                $s3Client = Storage::disk('s3')->getClient();
                $s3Client->deleteObject([
                    'Bucket' => $s3Bucket,
                    'Key' => $path,
                ]);
            } catch (\Throwable $e) {
                Log::warning("Failed to delete from S3: {$e->getMessage()}");
            }
            return;
        }

        Storage::disk('public')->delete($path);
    }

    /**
     * Check if a file exists.
     */
    public static function exists(string $path): bool
    {
        $s3Bucket = config('filesystems.disks.s3.bucket');
        $s3Key = config('filesystems.disks.s3.key');

        if ($s3Bucket && $s3Key) {
            return Storage::disk('s3')->exists($path);
        }

        return Storage::disk('public')->exists($path);
    }

    /**
     * Get the public URL for a stored file.
     */
    public static function url(string $path): string
    {
        $s3Bucket = config('filesystems.disks.s3.bucket');
        $s3Key = config('filesystems.disks.s3.key');

        if ($s3Bucket && $s3Key) {
            $cloudfrontDomain = config('filesystems.cloudfront_domain');
            return $cloudfrontDomain
                ? "https://{$cloudfrontDomain}/{$path}"
                : Storage::disk('s3')->url($path);
        }

        return asset("storage/{$path}");
    }

    /**
     * Check if S3 is configured.
     */
    public static function usesS3(): bool
    {
        return !empty(config('filesystems.disks.s3.bucket')) && !empty(config('filesystems.disks.s3.key'));
    }
}
