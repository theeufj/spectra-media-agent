<?php

namespace App\Services\Creative;

use App\Services\VideoGeneration\PerformanceMaxVideo;
use Illuminate\Support\Facades\Process;

class VideoReviewInput
{
    // The base64 envelope and the evidence prompt also count toward the inline request size.
    private const INLINE_BYTES = 700_000;

    /** Keep the whole clip and its audio. The review approval still pins the original bytes. */
    public static function forInlineReview(string $original): string
    {
        if (strlen($original) <= self::INLINE_BYTES) {
            return $original;
        }
        $seconds = PerformanceMaxVideo::duration($original);
        if (! $seconds || ! is_finite($seconds)) {
            return $original;
        }
        $videoKbps = (int) floor(self::INLINE_BYTES * 0.82 * 8 / (1000 * $seconds)) - 64;
        if ($videoKbps < 96) {
            // Preserve the original review path when compressing would make fine print illegible.
            return $original;
        }
        $path = tempnam(sys_get_temp_dir(), 'creative-review-');
        if ($path === false) {
            throw new \RuntimeException('Could not prepare the selected video for review.');
        }
        try {
            if (file_put_contents($path, $original) !== strlen($original)) {
                throw new \RuntimeException('Could not read the complete selected video for review.');
            }
            $encoded = Process::timeout(75)->run([
                config('services.ffmpeg.path') ?: 'ffmpeg', '-hide_banner', '-loglevel', 'error', '-nostdin',
                '-f', 'mov', '-protocol_whitelist', 'file,pipe', '-i', $path,
                '-map', '0:v:0', '-map', '0:a:0?', '-map_metadata', '-1',
                '-vf', "scale=w='min(768,iw)':h='min(768,ih)':force_original_aspect_ratio=decrease:force_divisible_by=2",
                '-c:v', 'libx264', '-threads', '2', '-preset', 'veryfast', '-pix_fmt', 'yuv420p',
                '-b:v', "{$videoKbps}k", '-maxrate', "{$videoKbps}k", '-bufsize', "{$videoKbps}k",
                '-c:a', 'aac', '-b:a', '64k', '-movflags', 'frag_keyframe+empty_moov', '-f', 'mp4', 'pipe:1',
            ]);
            $bytes = $encoded->output();
            if (! $encoded->successful() || $bytes === '' || strlen($bytes) > self::INLINE_BYTES) {
                throw new \RuntimeException('Could not encode the complete video within the inline review limit.');
            }

            return $bytes;
        } finally {
            unlink($path);
        }
    }
}
