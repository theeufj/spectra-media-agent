<?php

namespace App\Services\VideoGeneration;

use App\Models\Campaign;
use App\Models\ImageCollateral;
use App\Services\Creative\MetaOfferArt;
use App\Services\GeminiService;
use App\Services\StorageHelper;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

/** A mobile motion ad with the same actual words on screen and in its narration. */
class MetaOfferVideo
{
    public function __construct(private MetaOfferArt $art, private GeminiService $gemini) {}

    /** @return list<string> */
    public static function scenes(string $brand, string $script): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/u', trim($script), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $canonical = static fn (string $value) => mb_strtolower(preg_replace('/[^\pL\pN]+/u', '', $value) ?? '');
        $brand = $canonical($brand);
        if (count($sentences) < 2 || count($sentences) > 4 || $brand === '' || ! str_contains($canonical($sentences[0]), $brand) || count(preg_split('/\s+/u', trim($script))) > 35) {
            return [];
        }

        return $sentences;
    }

    public function render(Campaign $campaign, string $script, int $variant, string $aspectRatio = '9:16', ?int $referenceImageId = null): array
    {
        [$width, $height] = match ($aspectRatio) {
            '9:16' => [720, 1280],
            '1:1' => [720, 720],
            '16:9' => [1280, 720],
            default => throw new \InvalidArgumentException('Choose a supported video aspect ratio.'),
        };
        $sentences = self::scenes((string) $campaign->customer?->name, $script);
        if (! $sentences) {
            throw new \RuntimeException('The Meta voiceover must open with the brand and fit in two to four short scenes.');
        }
        $source = $this->art->campaignSource($campaign, $variant);
        if ($referenceImageId !== null) {
            $image = ImageCollateral::where('campaign_id', $campaign->id)->where('is_active', true)->findOrFail($referenceImageId);
            $bytes = StorageHelper::get($image->s3_path, 12 * 1024 * 1024);
            if (! $bytes || ! @getimagesizefromstring($bytes)) {
                throw new \RuntimeException('The selected video reference image could not be loaded.');
            }
            $source = ['id' => $image->id, 'bytes' => $bytes];
        }
        $pcm = $this->gemini->synthesizeSpeech($script, context: ['campaign_id' => $campaign->id, 'customer_id' => $campaign->customer_id, 'task_type' => 'video_narration']);
        if (! $pcm || strlen($pcm) < 48_000 || strlen($pcm) > 48_000 * 35) {
            throw new \RuntimeException('The branded Meta voice track could not be produced at the requested length.');
        }
        $duration = strlen($pcm) / 48_000;
        $weights = array_map(fn ($line) => max(1, count(preg_split('/\s+/u', $line))), $sentences);
        $directory = sys_get_temp_dir().'/meta-offer-'.bin2hex(random_bytes(12));
        if (! mkdir($directory, 0700)) {
            throw new \RuntimeException('The Meta video could not allocate a render directory.');
        }
        try {
            File::put($directory.'/voice.pcm', $pcm);
            $frames = '';
            foreach ($sentences as $i => $sentence) {
                File::put($directory."/slide{$i}.png", $this->art->render($campaign, $sentence, null, $width, $height, $variant + $i, $source['bytes'] ?? null));
                $seconds = $duration * $weights[$i] / array_sum($weights) + ($i === count($sentences) - 1 ? .5 : 0);
                $frames .= "file 'slide{$i}.png'\nduration ".number_format($seconds, 4, '.', '')."\n";
            }
            $last = count($sentences) - 1;
            File::put($directory.'/frames.txt', $frames."file 'slide{$last}.png'\n");
            $result = Process::path($directory)->timeout(120)->run([
                \App\Jobs\FinalizeVideoNarration::ffmpegPath() ?? 'ffmpeg', '-nostdin', '-hide_banner', '-loglevel', 'error',
                '-f', 'concat', '-safe', '1', '-i', 'frames.txt', '-f', 's16le', '-ar', '24000', '-ac', '1', '-i', 'voice.pcm',
                '-map', '0:v', '-map', '1:a', '-vf', "fps=24,zoompan=z='1.008+0.008*sin(on/36)':x='iw/2-iw/zoom/2':y='ih/2-ih/zoom/2':d=1:s={$width}x{$height}:fps=24",
                '-af', 'apad', '-t', number_format($duration + .5, 3, '.', ''), '-c:v', 'libx264', '-pix_fmt', 'yuv420p', '-threads', '2',
                '-preset', 'veryfast', '-c:a', 'aac', '-movflags', '+faststart', 'finished.mp4',
            ]);
            if (! $result->successful() || ! is_file($directory.'/finished.mp4')) {
                throw new \RuntimeException('The branded Meta video could not be encoded. '.mb_substr($result->errorOutput(), -300));
            }

            return ['bytes' => File::get($directory.'/finished.mp4'), 'duration_seconds' => $duration + .5,
                'reference_ids' => $source ? [$source['id']] : [], 'aspect_ratio' => $aspectRatio];
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
