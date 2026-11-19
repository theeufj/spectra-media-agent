<?php

namespace App\Services\VideoGeneration;

use App\Jobs\UploadPMaxVideoAssets;
use App\Mail\VideosGenerated;
use App\Models\VideoCollateral;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Everything that happens once a video is genuinely finished — narration
 * complete, audio finalized: the "videos ready" email and the PMax
 * length/linking flow.
 *
 * Extracted from CheckVideoStatus so FinalizeVideoNarration can run the same
 * steps after it swaps the audio track.
 */
class VideoPostCompletion
{
    public function __invoke(VideoCollateral $video): void
    {
        $campaign = $video->campaign()->first();
        if (! $campaign instanceof \App\Models\Campaign || ! $campaign->customer) {
            return;
        }

        // This runs once per finished video. Emailing here unconditionally
        // sent every user a fresh "Your N Videos Are Ready!" for each clip —
        // three videos meant three emails. Only announce when the batch is
        // actually done: nothing still pending or generating.
        $stillRunning = $campaign->videoCollaterals()
            ->whereIn('status', ['pending', 'generating'])
            ->count();

        $videoCount = $campaign->videoCollaterals()->where('status', 'completed')->count();

        if ($stillRunning === 0 && $videoCount > 0) {
            foreach ($campaign->customer->users as $user) {
                Mail::to($user->email)->send(new VideosGenerated($user, $campaign, $videoCount));
            }
        }

        if ($video->is_active && $video->should_deploy && $video->status === 'completed'
            && (float) ($video->generation_metadata['duration_seconds'] ?? 0) >= PerformanceMaxVideo::MIN_SECONDS
            && str_contains(strtolower($video->platform ?? ''), 'performance max')) {
            $strategyId = $video->strategy_id ?? $video->generation_metadata['strategy_id'] ?? null;
            $account = $campaign->customer->cleanGoogleCustomerId();
            $strategy = $strategyId ? $campaign->strategies()->where('campaign_type', 'performance_max')->find($strategyId) : null;
            if ($account && $strategy?->signed_off_at && $strategy->deployment_status === 'active' && $strategy->reusableGoogleCampaignId()
                && in_array($video->id, $strategy->creative_approval['video_ids'] ?? [], true)) {
                UploadPMaxVideoAssets::dispatch($strategy->id, $account, videoIds: [$video->id])->delay(now()->addSeconds(30));
            }
        }

        Log::info("VideoPostCompletion: finished for VideoCollateral {$video->id}");
    }
}
