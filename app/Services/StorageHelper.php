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

            $params = self::putParams($path, $contents, $contentType);

            $result = $s3Client->putObject($params);

            if (! isset($result['ETag'])) {
                throw new \RuntimeException('S3 upload failed - no ETag in response.');
            }

            Log::info("File uploaded to S3: {$path}");

            $url = Storage::disk('s3')->url($path);

            return [$path, $url];
        }

        // Fallback: local public storage
        Storage::disk('public')->put($path, $contents);
        Log::info("File saved to local storage: {$path}");
        $url = asset("storage/{$path}");

        return [$path, $url];
    }

    /**
     * The PutObject parameters for one upload.
     *
     * Extracted so the ACL decision can be asserted without a live bucket —
     * the thing it decides is whether anyone can read the file afterwards, and
     * that went wrong silently for months.
     *
     * @return array<string, mixed>
     */
    private static function putParams(string $path, string $contents, string $contentType): array
    {
        $params = [
            'Bucket' => config('filesystems.disks.s3.bucket'),
            'Key' => $path,
            'Body' => $contents,
            'ContentType' => $contentType,
        ];

        /*
         * Only send an ACL where the provider has them.
         *
         * This was hardcoded to 'public-read', which quietly assumes every
         * S3-compatible store works the way S3 does. Cloudflare R2 — what a
         * Forge bucket is — has no object-level ACLs at all: whether an object
         * can be read is a property of the bucket and the domain it is served
         * on. DigitalOcean Spaces does have them and needs this.
         */
        $acl = config('filesystems.disks.s3.acl');
        if (is_string($acl) && $acl !== '') {
            $params['ACL'] = $acl;
        }

        return $params;
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
            return Storage::disk('s3')->url($path);
        }

        return asset("storage/{$path}");
    }

    /**
     * Check if S3 is configured.
     */
    public static function usesS3(): bool
    {
        return ! empty(config('filesystems.disks.s3.bucket')) && ! empty(config('filesystems.disks.s3.key'));
    }
}
