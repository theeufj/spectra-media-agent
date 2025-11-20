<?php

namespace App\Services\Validators;

class AssetValidator
{
    /**
     * Image validation rules.
     */
    private const IMAGE_RULES = [
        'min_width' => 1200,
        'min_height' => 628,
        'max_file_size' => 5 * 1024 * 1024, // 5MB in bytes
        'allowed_formats' => ['image/jpeg', 'image/jpg', 'image/png'],
        'allowed_extensions' => ['jpg', 'jpeg', 'png'],
        'recommended_ratios' => [
            '1.91:1' => [1200, 628], // Landscape (Facebook Link Ads)
            '1:1' => [1080, 1080],   // Square
            '4:5' => [1080, 1350],   // Portrait
            '16:9' => [1920, 1080],  // Widescreen
        ],
    ];

    /**
     * Video validation rules.
     */
    private const VIDEO_RULES = [
        'max_file_size' => 4 * 1024 * 1024 * 1024, // 4GB in bytes
        'allowed_formats' => ['video/mp4'],
        'allowed_extensions' => ['mp4'],
        'min_duration' => 1,     // 1 second
        'max_duration' => 241,   // 4 minutes 1 second (Facebook limit)
        'recommended_ratios' => [
            '16:9' => [1920, 1080],  // Landscape
            '1:1' => [1080, 1080],   // Square
            '4:5' => [1080, 1350],   // Vertical
            '9:16' => [1080, 1920],  // Stories
        ],
    ];

    /**
     * Validate an image file.
     *
     * @param string $filePath Path to the image file (local or S3 URL)
     * @param bool $downloadIfRemote Whether to download remote files for validation
     * @return array Validation errors (empty if valid)
     */
    public function validateImage(string $filePath, bool $downloadIfRemote = false): array
    {
        $errors = [];
        $tempFile = null;

        try {
            // Handle remote files
            if ($this->isRemoteFile($filePath)) {
                if (!$downloadIfRemote) {
                    $errors[] = "Cannot validate remote image without downloading. Set downloadIfRemote=true.";
                    return $errors;
                }
                
                $tempFile = $this->downloadTemporaryFile($filePath);
                if (!$tempFile) {
                    $errors[] = "Failed to download image from: {$filePath}";
                    return $errors;
                }
                $filePath = $tempFile;
            }

            // Check if file exists
            if (!file_exists($filePath)) {
                $errors[] = "Image file does not exist: {$filePath}";
                return $errors;
            }

            // Check file size
            $fileSize = filesize($filePath);
            if ($fileSize > self::IMAGE_RULES['max_file_size']) {
                $maxSizeMB = self::IMAGE_RULES['max_file_size'] / (1024 * 1024);
                $actualSizeMB = round($fileSize / (1024 * 1024), 2);
                $errors[] = "Image file size exceeds {$maxSizeMB}MB (actual: {$actualSizeMB}MB)";
            }

            // Check MIME type
            $mimeType = mime_content_type($filePath);
            if (!in_array($mimeType, self::IMAGE_RULES['allowed_formats'])) {
                $errors[] = "Unsupported image format: {$mimeType}. Allowed: " . implode(', ', self::IMAGE_RULES['allowed_formats']);
            }

            // Check file extension
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if (!in_array($extension, self::IMAGE_RULES['allowed_extensions'])) {
                $errors[] = "Unsupported image extension: {$extension}. Allowed: " . implode(', ', self::IMAGE_RULES['allowed_extensions']);
            }

            // Check dimensions
            $imageInfo = @getimagesize($filePath);
            if ($imageInfo === false) {
                $errors[] = "Unable to read image dimensions. File may be corrupted.";
            } else {
                [$width, $height] = $imageInfo;

                if ($width < self::IMAGE_RULES['min_width']) {
                    $errors[] = "Image width {$width}px is below minimum " . self::IMAGE_RULES['min_width'] . "px";
                }

                if ($height < self::IMAGE_RULES['min_height']) {
                    $errors[] = "Image height {$height}px is below minimum " . self::IMAGE_RULES['min_height'] . "px";
                }

                // Add aspect ratio info (not an error, just a warning)
                $ratio = $this->calculateAspectRatio($width, $height);
                $isRecommended = $this->isRecommendedRatio($ratio, 'image');
                
                if (!$isRecommended) {
                    $errors[] = "Image aspect ratio {$ratio} is not recommended. Consider: " . 
                                implode(', ', array_keys(self::IMAGE_RULES['recommended_ratios']));
                }
            }

        } finally {
            // Clean up temp file if created
            if ($tempFile && file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }

        return $errors;
    }

    /**
     * Validate a video file.
     *
     * @param string $filePath Path to the video file (local or S3 URL)
     * @param bool $downloadIfRemote Whether to download remote files for validation
     * @return array Validation errors (empty if valid)
     */
    public function validateVideo(string $filePath, bool $downloadIfRemote = false): array
    {
        $errors = [];
        $tempFile = null;

        try {
            // Handle remote files
            if ($this->isRemoteFile($filePath)) {
                if (!$downloadIfRemote) {
                    $errors[] = "Cannot validate remote video without downloading. Set downloadIfRemote=true.";
                    return $errors;
                }
                
                $tempFile = $this->downloadTemporaryFile($filePath);
                if (!$tempFile) {
                    $errors[] = "Failed to download video from: {$filePath}";
                    return $errors;
                }
                $filePath = $tempFile;
            }

            // Check if file exists
            if (!file_exists($filePath)) {
                $errors[] = "Video file does not exist: {$filePath}";
                return $errors;
            }

            // Check file size
            $fileSize = filesize($filePath);
            if ($fileSize > self::VIDEO_RULES['max_file_size']) {
                $maxSizeGB = self::VIDEO_RULES['max_file_size'] / (1024 * 1024 * 1024);
                $actualSizeGB = round($fileSize / (1024 * 1024 * 1024), 2);
                $errors[] = "Video file size exceeds {$maxSizeGB}GB (actual: {$actualSizeGB}GB)";
            }

            // Check MIME type
            $mimeType = mime_content_type($filePath);
            if (!in_array($mimeType, self::VIDEO_RULES['allowed_formats'])) {
                $errors[] = "Unsupported video format: {$mimeType}. Allowed: " . implode(', ', self::VIDEO_RULES['allowed_formats']);
            }

            // Check file extension
            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if (!in_array($extension, self::VIDEO_RULES['allowed_extensions'])) {
                $errors[] = "Unsupported video extension: {$extension}. Allowed: " . implode(', ', self::VIDEO_RULES['allowed_extensions']);
            }

            // For video duration and dimensions, we would need FFmpeg or similar
            // This is a simplified check - in production, integrate with FFmpeg
            $errors[] = "Note: Full video validation (duration, codec, resolution) requires FFmpeg integration";

        } finally {
            // Clean up temp file if created
            if ($tempFile && file_exists($tempFile)) {
                @unlink($tempFile);
            }
        }

        return $errors;
    }

    /**
     * Validate video with FFmpeg (if available).
     *
     * @param string $filePath Path to video file
     * @return array Video metadata and validation errors
     */
    public function validateVideoWithFFmpeg(string $filePath): array
    {
        $errors = [];
        
        // Check if FFmpeg is available
        $ffmpegPath = $this->findFFmpeg();
        if (!$ffmpegPath) {
            return ['errors' => ['FFmpeg not found. Install FFmpeg for full video validation.']];
        }

        // Use FFprobe to get video metadata
        $command = "{$ffmpegPath}probe -v quiet -print_format json -show_format -show_streams " . escapeshellarg($filePath);
        $output = shell_exec($command);
        
        if (!$output) {
            return ['errors' => ['Failed to read video metadata with FFmpeg']];
        }

        $metadata = json_decode($output, true);
        
        if (!$metadata) {
            return ['errors' => ['Failed to parse FFmpeg output']];
        }

        // Extract video stream info
        $videoStream = null;
        foreach ($metadata['streams'] ?? [] as $stream) {
            if ($stream['codec_type'] === 'video') {
                $videoStream = $stream;
                break;
            }
        }

        if (!$videoStream) {
            return ['errors' => ['No video stream found in file']];
        }

        // Validate duration
        $duration = (float)($metadata['format']['duration'] ?? 0);
        if ($duration < self::VIDEO_RULES['min_duration']) {
            $errors[] = "Video duration {$duration}s is below minimum " . self::VIDEO_RULES['min_duration'] . "s";
        }
        if ($duration > self::VIDEO_RULES['max_duration']) {
            $errors[] = "Video duration {$duration}s exceeds maximum " . self::VIDEO_RULES['max_duration'] . "s";
        }

        // Check resolution and aspect ratio
        $width = $videoStream['width'] ?? 0;
        $height = $videoStream['height'] ?? 0;
        
        if ($width && $height) {
            $ratio = $this->calculateAspectRatio($width, $height);
            $isRecommended = $this->isRecommendedRatio($ratio, 'video');
            
            if (!$isRecommended) {
                $errors[] = "Video aspect ratio {$ratio} is not recommended. Consider: " . 
                            implode(', ', array_keys(self::VIDEO_RULES['recommended_ratios']));
            }
        }

        return [
            'errors' => $errors,
            'metadata' => [
                'duration' => $duration,
                'width' => $width,
                'height' => $height,
                'codec' => $videoStream['codec_name'] ?? 'unknown',
                'bitrate' => (int)($metadata['format']['bit_rate'] ?? 0),
            ],
        ];
    }

    /**
     * Calculate aspect ratio as a string (e.g., "16:9").
     */
    private function calculateAspectRatio(int $width, int $height): string
    {
        $gcd = $this->gcd($width, $height);
        $ratioWidth = $width / $gcd;
        $ratioHeight = $height / $gcd;
        
        return "{$ratioWidth}:{$ratioHeight}";
    }

    /**
     * Calculate greatest common divisor.
     */
    private function gcd(int $a, int $b): int
    {
        while ($b != 0) {
            $temp = $b;
            $b = $a % $b;
            $a = $temp;
        }
        return $a;
    }

    /**
     * Check if aspect ratio is in recommended list.
     */
    private function isRecommendedRatio(string $ratio, string $type): bool
    {
        $rules = $type === 'image' ? self::IMAGE_RULES : self::VIDEO_RULES;
        return isset($rules['recommended_ratios'][$ratio]);
    }

    /**
     * Check if file path is remote (URL).
     */
    private function isRemoteFile(string $filePath): bool
    {
        return str_starts_with($filePath, 'http://') || str_starts_with($filePath, 'https://');
    }

    /**
     * Download remote file to temporary location.
     */
    private function downloadTemporaryFile(string $url): ?string
    {
        $tempFile = sys_get_temp_dir() . '/' . uniqid('asset_') . '_' . basename(parse_url($url, PHP_URL_PATH));
        
        $content = @file_get_contents($url);
        if ($content === false) {
            return null;
        }

        if (file_put_contents($tempFile, $content) === false) {
            return null;
        }

        return $tempFile;
    }

    /**
     * Find FFmpeg installation.
     */
    private function findFFmpeg(): ?string
    {
        // Check common locations
        $paths = [
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            '/opt/homebrew/bin/ffmpeg',
        ];

        foreach ($paths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        // Try which command
        $which = shell_exec('which ffmpeg 2>/dev/null');
        if ($which && file_exists(trim($which))) {
            return trim($which);
        }

        return null;
    }

    /**
     * Get image validation rules.
     */
    public function getImageRules(): array
    {
        return self::IMAGE_RULES;
    }

    /**
     * Get video validation rules.
     */
    public function getVideoRules(): array
    {
        return self::VIDEO_RULES;
    }
}
