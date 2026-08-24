<?php

namespace App\Jobs;

use App\Models\VideoCollateral;
use App\Services\GeminiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Keep extending a clip until its whole voiceover script has been spoken.
 *
 * Veo generates at most 8 seconds per call, but a typical generated script
 * runs 15–20 seconds of narration — so production videos ended mid-sentence.
 * The script is split into ~8-second segments; the initial generation
 * narrates segment one, and each completed clip re-enters here until every
 * segment is covered (or the extension cap stops runaway cost).
 */
class ExtendVideoForScript implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** ~2.4 spoken words per second × 8-second clips. */
    public const WORDS_PER_SEGMENT = 19;

    /** 1 generation + 3 extensions ≈ 32s and ~$12.80 — the ceiling. */
    public const MAX_EXTENSIONS = 3;

    public int $tries = 3;

    public int $timeout = 600;

    public function __construct(protected VideoCollateral $sourceVideo) {}

    /**
     * Split a script into narration segments that each fit an 8-second clip,
     * breaking on sentence boundaries where possible. Deterministic, so the
     * chain can recompute its position from (script, extension_count) alone.
     *
     * @return list<string>
     */
    public static function scriptSegments(string $script): array
    {
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($script), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $segments = [];
        $current = '';

        foreach ($sentences as $sentence) {
            $candidate = trim($current.' '.$sentence);
            if ($current !== '' && str_word_count($candidate) > self::WORDS_PER_SEGMENT) {
                $segments[] = $current;
                $current = $sentence;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $segments[] = $current;
        }

        return $segments;
    }

    /**
     * Does this clip still owe narration? (Each generation or extension
     * covers one segment.)
     */
    public static function needsExtension(VideoCollateral $video): bool
    {
        if ($video->status !== 'completed' || ! $video->script) {
            return false;
        }

        $covered = ($video->extension_count ?? 0) + 1;
        $needed = count(self::scriptSegments($video->script));

        if ($needed > $covered && ($video->extension_count ?? 0) >= self::MAX_EXTENSIONS) {
            Log::warning("ExtendVideoForScript: video {$video->id} script needs {$needed} segments but the extension cap is reached — narration will be incomplete.");

            return false;
        }

        return $needed > $covered;
    }

    public function handle(GeminiService $geminiService): void
    {
        $source = $this->sourceVideo->fresh();

        if (! $source || ! self::needsExtension($source) || ! $source->s3_path) {
            return;
        }

        $segments = self::scriptSegments($source->script);
        $nextSegment = $segments[($source->extension_count ?? 0) + 1] ?? null;
        if ($nextSegment === null) {
            return;
        }

        try {
            $bytes = Storage::disk('s3')->get($source->s3_path);
        } catch (\Throwable $e) {
            Log::error("ExtendVideoForScript: could not read source video {$source->id}: ".$e->getMessage());

            return;
        }

        $prompt = 'Seamlessly continue this advertising video — same subjects, lighting, colour grading, '
            ."camera style and environment, one continuous piece.\n\n"
            ."The narrator continues the voiceover, speaking exactly this and nothing else:\n"
            ."\"{$nextSegment}\"\n\n"
            .'No on-screen text of any kind — no captions, titles, labels or fine print; anything that '
            .'would naturally show text appears as abstract blurred shapes. No watermarks.';

        $operationName = $geminiService->extendVideoFromBytes(
            base64_encode($bytes),
            'video/mp4',
            $prompt,
            ['operation' => 'script_video_extension', 'campaign_id' => $source->campaign_id]
        );

        if (! $operationName) {
            Log::error("ExtendVideoForScript: failed to start extension for video {$source->id}");

            return;
        }

        // The extended clip supersedes this one.
        $source->update(['is_active' => false]);

        $extended = VideoCollateral::create([
            'campaign_id' => $source->campaign_id,
            'strategy_id' => $source->strategy_id,
            'platform' => $source->platform,
            'script' => $source->script,
            'status' => 'generating',
            'operation_name' => $operationName,
            'is_active' => true,
            'parent_video_id' => $source->id,
            'extension_count' => ($source->extension_count ?? 0) + 1,
        ]);

        Log::info('ExtendVideoForScript: segment '.(($source->extension_count ?? 0) + 2).'/'.count($segments)." started — source {$source->id} -> extended {$extended->id}");
        CheckVideoStatus::dispatch($extended)->delay(now()->addMinute());
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('ExtendVideoForScript failed: '.$exception->getMessage());
    }
}
