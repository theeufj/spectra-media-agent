<?php

namespace App\Jobs;

use App\Models\VideoCollateral;
use App\Services\GeminiService;
use App\Services\StorageHelper;
use App\Services\VideoGeneration\VideoPostCompletion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * Give a finished video one consistent narrator.
 *
 * Veo regenerates its narrator on every call, so a script-extension chain
 * changes voice mid-video. Once the chain is complete this job synthesizes
 * the FULL script as a single Gemini TTS pass (a named voice is
 * deterministic) and replaces the video's audio track with it via ffmpeg.
 *
 * Fails soft: if TTS or ffmpeg is unavailable, the video ships with its
 * original (inconsistent) audio rather than not shipping at all.
 */
class FinalizeVideoNarration implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public function __construct(protected VideoCollateral $video) {}

    public static function shouldRun(?VideoCollateral $video): bool
    {
        return $video
            && config('ai.video_narration_tts', true)
            && $video->status === 'completed'
            && filled($video->script)
            && is_null($video->narration_finalized_at)
            && $video->s3_path
            && self::ffmpegPath() !== null;
    }

    /**
     * Locate ffmpeg without requiring an env edit: explicit config first,
     * then the usual homes (Forge boxes get a static build in ~/bin).
     */
    public static function ffmpegPath(): ?string
    {
        $candidates = array_filter([
            config('services.ffmpeg.path'),
            trim((string) shell_exec('command -v ffmpeg 2>/dev/null')) ?: null,
            (getenv('HOME') ?: '/home/forge').'/bin/ffmpeg',
            '/usr/bin/ffmpeg',
            '/usr/local/bin/ffmpeg',
            '/opt/homebrew/bin/ffmpeg',
        ]);

        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    public function handle(GeminiService $gemini): void
    {
        $video = $this->video->fresh();
        if (! self::shouldRun($video)) {
            // Nothing to do (or no tooling) — deliver as-is.
            (new VideoPostCompletion)($video ?? $this->video);

            return;
        }

        try {
            $pcm = $gemini->synthesizeSpeech($video->script, null, [
                'campaign_id' => $video->campaign_id,
                'task_type' => 'video_narration',
            ]);

            if (! $pcm) {
                throw new \RuntimeException('TTS synthesis returned no audio');
            }

            $sourceBytes = Storage::disk('s3')->get($video->s3_path);
            if (! $sourceBytes) {
                throw new \RuntimeException('Could not read source video');
            }

            $dir = sys_get_temp_dir().'/narration_'.$video->id.'_'.uniqid();
            mkdir($dir);
            file_put_contents("{$dir}/in.mp4", $sourceBytes);
            file_put_contents("{$dir}/voice.pcm", $pcm);

            // Replace the audio track entirely (mixing would leak the old,
            // inconsistent voices). apad + -shortest: narration is padded
            // with silence to the video's length, output ends with the video.
            $ffmpeg = self::ffmpegPath();
            $result = Process::timeout(300)->run([
                $ffmpeg, '-y',
                '-i', "{$dir}/in.mp4",
                '-f', 's16le', '-ar', '24000', '-ac', '1', '-i', "{$dir}/voice.pcm",
                '-map', '0:v', '-map', '1:a',
                '-c:v', 'copy', '-c:a', 'aac', '-b:a', '128k',
                '-af', 'apad',
                '-shortest',
                "{$dir}/out.mp4",
            ]);

            if (! $result->successful() || ! is_file("{$dir}/out.mp4")) {
                throw new \RuntimeException('ffmpeg failed: '.substr($result->errorOutput(), -400));
            }

            $finalBytes = file_get_contents("{$dir}/out.mp4");
            $filename = uniqid('vid_narrated_', true).'.mp4';
            [$s3Path, $url] = StorageHelper::put(
                "collateral/videos/{$video->campaign_id}/{$filename}",
                $finalBytes,
                'video/mp4'
            );

            $video->update([
                's3_path' => $s3Path,
                'cloudfront_url' => $url,
                'narration_finalized_at' => now(),
            ]);

            Log::info("FinalizeVideoNarration: video {$video->id} re-voiced (".strlen($finalBytes).' bytes)');
        } catch (\Throwable $e) {
            report($e);
            Log::error("FinalizeVideoNarration: video {$video->id} failed — shipping original audio: ".$e->getMessage());
            // Mark attempted so the pipeline doesn't loop on a broken step.
            $video->update(['narration_finalized_at' => now()]);
        } finally {
            if (isset($dir)) {
                @unlink("{$dir}/in.mp4");
                @unlink("{$dir}/voice.pcm");
                @unlink("{$dir}/out.mp4");
                @rmdir($dir);
            }
        }

        (new VideoPostCompletion)($video->fresh());
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('FinalizeVideoNarration failed: '.$exception->getMessage());
        // Deliver with original audio rather than stranding the video.
        $this->video->update(['narration_finalized_at' => now()]);
        (new VideoPostCompletion)($this->video->fresh());
    }
}
